<?php

namespace lib\ProfitSharing;

use Exception;

class Wxpay implements IProfitSharing
{
    
    static $paytype = 'wxpay';

    private $channel;
    private $service;
    private $ecommerce;

    function __construct($channel){
		$this->channel = $channel;
        $wechatpay_config = require(PLUGIN_ROOT.$channel['plugin'].'/inc/config.php');
        $this->ecommerce = $wechatpay_config['ecommerce'];
        $this->service = new \WeChatPay\V3\ProfitsharingService($wechatpay_config);
	}

    //请求分账
    public function submit($trade_no, $api_trade_no, $order_money, $info){
        global $conf;
        $receivers = [];
        $allmoney = 0;
        $rdata = [];
        foreach($info as $receiver){
            $money = round(floor($order_money * $receiver['rate']) / 100, 2);
            $type = self::get_wxpay_account_type($receiver['account']);
            if($this->ecommerce){
                $receivers[] = [
                    'type' => $type,
                    'receiver_account' => $receiver['account'],
                    'amount' => intval(round($money*100)),
                    'description' => $conf['profits_desc']?$conf['profits_desc']:'订单分账'
                ];
            }else{
                $receivers[] = [
                    'type' => $type,
                    'account' => $receiver['account'],
                    'amount' => intval(round($money*100)),
                    'description' => $conf['profits_desc']?$conf['profits_desc']:'订单分账'
                ];
            }
            $allmoney += $money;
            $rdata[] = ['account'=>$receiver['account'], 'money'=>$money];
        }
        $out_order_no = date('YmdHis').rand(1000,9999);
        if($this->ecommerce){
            $param = [
                'transaction_id' => $api_trade_no,
                'out_order_no' => $out_order_no,
                'receivers' => $receivers,
                'finish' => true,
            ];
        }else{
            $param = [
                'transaction_id' => $api_trade_no,
                'out_order_no' => $out_order_no,
                'receivers' => $receivers,
                'unfreeze_unsplit' => true,
            ];
        }
        try{
            $result = $this->service->submit($param);
            return ['code'=>0, 'msg'=>'请求分账成功', 'settle_no'=>$out_order_no, 'money'=>round($allmoney, 2), 'rdata'=>$rdata];
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }

    //查询分账结果
    public function query($trade_no, $api_trade_no, $settle_no, $expected = []){
        $reason_desc = ['ACCOUNT_ABNORMAL'=>'分账接收账户异常', 'NO_RELATION'=>'分账关系已解除', 'RECEIVER_HIGH_RISK'=>'高风险接收方', 'RECEIVER_REAL_NAME_NOT_VERIFIED'=>'接收方未实名', 'NO_AUTH'=>'分账权限已解除', 'RECEIVER_RECEIPT_LIMIT'=>'接收方已达收款限额', 'PAYER_ACCOUNT_ABNORMAL'=>'分出方账户异常', 'INVALID_REQUEST'=>'描述参数设置失败'];

        try{
            $result = $this->service->query($settle_no, $api_trade_no);
            if(isset($result['state']) && $result['state'] == 'FINISHED' || isset($result['status']) && $result['status'] == 'FINISHED'){
                if(($result['out_order_no']??null)!==$settle_no || ($result['transaction_id']??null)!==$api_trade_no)return ['code'=>0,'status'=>0,'reason'=>'分账订单身份不符'];
                return ReceiverState::aggregate($expected,$result['receivers']??null,$this->ecommerce?'receiver_account':'account',true,'SUCCESS','CLOSED','result') + ['receivers'=>$result['receivers']??null];
            }else{
                return ['code'=>0, 'status'=>0];
            }
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }

    //解冻剩余资金
    public function unfreeeze($trade_no, $api_trade_no){
        $out_order_no = date('YmdHis').rand(1000,9999);
        try{
            $this->service->unfreeze($out_order_no, $api_trade_no);
            return ['code'=>0, 'msg'=>'解冻剩余资金成功'];
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }

    //分账回退
    public function return($trade_no, $api_trade_no, $settle_no, $rdata, $queryOnly = false){
        global $DB;
        if(!$settle_no || !$rdata || $DB->db->inTransaction())return ['code'=>-2,'msg'=>'回退合同缺失或事务未提交'];
        $seen=[];$complete=true;
        foreach($rdata as $receiver){
            $account=(string)$receiver['account'];
            $cents=\lib\Finance::cents($receiver['money']);
            if(isset($seen[$account]) || $cents<=0)return ['code'=>-2,'msg'=>'回退接收方无效'];
            $seen[$account]=true;
        }
        foreach($rdata as $receiver){
            $account=(string)$receiver['account'];
            $cents=\lib\Finance::cents($receiver['money']);
            $key='psreturn:'.hash('sha256',json_encode([$trade_no,$api_trade_no,$settle_no,$account,$cents]));
            $params=['out_order_no'=>$settle_no,'out_return_no'=>'R'.substr(hash('sha256',$key),0,60),'return_mchid'=>$account,'amount'=>$cents,'description'=>'分账回退'];
            if($this->ecommerce)$params['transaction_id']=$api_trade_no;
            try{
                // Recovery observes only persisted intents: absence is not a claim or
                // permission to send. The original owner may not have reached this
                // receiver yet; after its death the order stays pending for review.
                if($queryOnly){
                    $old=\lib\Finance::row('SELECT payload FROM pre_funds_snapshot WHERE event_key=:k',[':k'=>$key]);
                    if(!$old){$complete=false;continue;}
                    $v=json_decode($old['payload'],true);
                    if(($v['params']??null)!==$params)throw new \RuntimeException('回退合同冲突');
                    $claim=[false,$v];
                }else{
                    // Only the original owner may commit a new per-receiver intent.
                    $claim=\lib\Finance::transaction(function()use($DB,$key,$params){
                        $old=\lib\Finance::row('SELECT payload FROM pre_funds_snapshot WHERE event_key=:k FOR UPDATE',[':k'=>$key]);
                        if($old){$v=json_decode($old['payload'],true);if(($v['params']??null)!==$params)throw new \RuntimeException('回退合同冲突');return [false,$v];}
                        $v=['v'=>1,'params'=>$params,'state'=>'UNCERTAIN'];
                        \lib\Finance::checked($DB->insert('funds_snapshot',['event_key'=>$key,'payload'=>json_encode($v,JSON_THROW_ON_ERROR),'addtime'=>'NOW()']));
                        return [true,$v];
                    });
                }
                $v=$claim[1];
                if(($v['state']??'')==='SUCCESS')continue;
                if(self::get_wxpay_account_type($account)!=='MERCHANT_ID'){
                    $v['state']='UNSUPPORTED';
                }else{
                    $result=($claim[0] && !$queryOnly)?$this->service->return($params):$this->service->returnQuery($params['out_return_no'],$settle_no);
                    $v['response']=$result;
                    $matches=($result['out_order_no']??null)===$settle_no && ($result['out_return_no']??null)===$params['out_return_no'] && (string)($result['return_mchid']??'')===$account && ($result['amount']??null)===$cents;
                    $v['state']=$matches && in_array($result['result']??'', ['SUCCESS','PROCESSING','FAILED'],true)?$result['result']:'UNCERTAIN';
                }
                ReceiverState::save($key,$v);
                if($v['state']!=='SUCCESS')$complete=false;
            }catch(\Throwable $e){
                // Provider acceptance + local write failure remains durable UNCERTAIN; query only.
                $complete=false;
            }
        }
        return $complete?['code'=>0,'msg'=>'全部接收方回退已确认']:['code'=>-2,'msg'=>'部分回退/待核对；保留原回退单号，仅查询，不重复付款'];
    }

    //查询分账结果
    public function amount($trade_no, $api_trade_no){
        try{
            $result = $this->service->orderAmountQuery($api_trade_no);
            return ['code'=>0, 'amount'=>round($result['unsplit_amount']/100,2)];
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }

    //添加分账接收方
    public function addReceiver($account, $name = null){
        $type = self::get_wxpay_account_type($account);
        try{
            $this->service->addReceiver($type, $account, $name);
            return ['code'=>0, 'msg'=>'添加分账接收方成功'];
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }

    //删除分账接收方
    public function deleteReceiver($account){
        $type = self::get_wxpay_account_type($account);
        try{
            $this->service->deleteReceiver($type, $account);
            return ['code'=>0, 'msg'=>'删除分账接收方成功'];
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }

    private static function get_wxpay_account_type($account){
        if(is_numeric($account))$type = 'MERCHANT_ID';
	    else $type = 'PERSONAL_OPENID';
        return $type;
    }
}