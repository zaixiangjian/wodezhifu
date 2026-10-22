<?php

namespace lib\ProfitSharing;

use Exception;

class CommUtil
{
    public static $plugins = ['alipay','alipaysl','alipayd','wxpayn','wxpaynp','yeepay','yseqt','chinaums','dinpay','adapay','duolabao','allinpay','huifu','haipay','heepay','kunpeng','kuaiqian','kuaiqianbank','yinyingtong','entpay','shengpay','helipay'];
    public static $no_order_plugins = ['chinaums','dinpay','duolabao','allinpay'];
    public static $mode_plugins = ['haipay','adapay','huifu','helipay'];

    /**
     * @param array $channel
     * @return IProfitSharing|false
     */
    public static function getModel($channel){
        if($channel['plugin'] == 'alipay' || $channel['plugin'] == 'alipaysl' || $channel['plugin'] == 'alipayd'){
            return new Alipay($channel);
        }elseif($channel['plugin'] == 'wxpayn' || $channel['plugin'] == 'wxpaynp'){
            return new Wxpay($channel);
        }elseif($channel['plugin'] == 'kuaiqian' || $channel['plugin'] == 'kuaiqianbank'){
            return new Kuaiqian($channel);
        }elseif(in_array($channel['plugin'], self::$plugins)){
            $classname = '\\lib\\ProfitSharing\\'.ucfirst($channel['plugin']);
            if(class_exists($classname)){
                return new $classname($channel);
            }else{
                return new Common($channel);
            }
        }
        return false;
    }

    public static function getReceiver($id){
        global $DB;
        $result = $DB->find('psreceiver', '*', ['id'=>$id]);
        if(!$result) return $result;
        $result['info'] = !empty($result['info']) ? json_decode($result['info'], true) : [['account'=>$result['account'], 'name'=>$result['name'], 'rate'=>$result['rate']]];
        return $result;
    }

    public static function getOrder($trade_no){
        global $DB;
        $result = $DB->getRow("SELECT A.*,B.channel,B.account,B.name,B.rate FROM pre_psorder A LEFT JOIN pre_psreceiver B ON A.rid=B.id WHERE A.trade_no=:trade_no", [':trade_no'=>$trade_no]);
        if(!$result) return $result;
        $result['rdata'] = json_decode($result['rdata'], true);
        return $result;
    }

    //订单分账定时任务
    public static function task(){
        global $DB, $conf;
        // Persist selection before provider I/O. Unresolved old intents must not starve later queries.
        foreach(self::queryBatch() as $id){
            $srow=self::loadItem($id);
            if($srow && in_array((int)$srow['status'],[1,5,6],true))self::process_item($srow);
        }
    
        $limit = 10; //每次提交分账的订单数量
        $list = $DB->getAll("SELECT A.*,B.channel,B.account,B.name,B.rate,B.info,B.uid psuid,C.uid,C.subchannel,C.realmoney ordermoney FROM pre_psorder A INNER JOIN pre_psreceiver B ON B.id=A.rid LEFT JOIN pre_order C ON C.trade_no=A.trade_no WHERE A.status=0 AND (A.addtime<=DATE_SUB(NOW(), INTERVAL 60 SECOND) AND A.delay=0 OR A.addtime<=DATE_SUB(NOW(), INTERVAL 24 HOUR) AND A.delay=1) ORDER BY A.id ASC LIMIT {$limit}");
        foreach($list as $srow){
            self::process_item($srow);
        }

        if($conf['profits_failretry'] == 1){
            $limit = 10; //每次提交分账的订单数量
            $list = $DB->getAll("SELECT A.*,B.channel,B.account,B.name,B.rate,B.info,B.uid psuid,C.uid,C.subchannel,C.realmoney ordermoney FROM pre_psorder A INNER JOIN pre_psreceiver B ON B.id=A.rid LEFT JOIN pre_order C ON C.trade_no=A.trade_no WHERE A.status=3 AND (A.addtime<=DATE_SUB(NOW(), INTERVAL 24 HOUR) AND A.delay=0 OR A.addtime<=DATE_SUB(NOW(), INTERVAL 48 HOUR) AND A.delay=1) AND A.retry=0 AND A.addtime>DATE_SUB(NOW(), INTERVAL 3 DAY) ORDER BY A.id ASC LIMIT {$limit}");
            foreach($list as $srow){
                self::process_item($srow);
            }
        }
    }

    /** Reserve at most 10 query intents while inspecting at most 100 primary-key rows.
     * The cycle ceiling prevents a stream of new orders delaying wraparound forever.
     * Only scheduler metadata is mutable; never hold its lock over provider I/O.
     */
    public static function queryBatch(){
        global $DB;
        if($DB->db->inTransaction())throw new \RuntimeException('分账调度必须独立提交游标');
        return \lib\Finance::transaction(function() use($DB){
            $key='cron:profit-query:v1';
            \lib\Finance::checked($DB->query("INSERT INTO pre_funds_snapshot (event_key,payload,addtime) VALUES (:k,'{\"v\":1,\"after\":0,\"ceiling\":0}',NOW()) ON DUPLICATE KEY UPDATE event_key=event_key",[':k'=>$key]));
            $saved=\lib\Finance::row('SELECT payload FROM pre_funds_snapshot WHERE event_key=:k FOR UPDATE',[':k'=>$key]);
            $cursor=json_decode($saved['payload'],true);
            if(!is_array($cursor) || ($cursor['v']??null)!==1 || !isset($cursor['after'],$cursor['ceiling']) || !is_int($cursor['after']) || !is_int($cursor['ceiling']) || $cursor['after']<0 || $cursor['ceiling']<$cursor['after'])throw new \RuntimeException('分账调度游标损坏');
            if($cursor['after']===$cursor['ceiling']){
                $last=\lib\Finance::row('SELECT id FROM pre_psorder ORDER BY id DESC LIMIT 1');
                $cursor['after']=0;
                $cursor['ceiling']=$last?(int)$last['id']:0;
            }
            // No status predicate or JOIN here: LIMIT bounds actual business rows visited,
            // including completed/orphaned orders. Missing receivers cannot pin the cursor.
            $rows=\lib\Finance::checked($DB->query('SELECT id,status FROM pre_psorder FORCE INDEX (PRIMARY) WHERE id>:after AND id<=:ceiling ORDER BY id ASC LIMIT 100',[':after'=>$cursor['after'],':ceiling'=>$cursor['ceiling']]))->fetchAll(\PDO::FETCH_ASSOC);
            $ids=[];
            foreach($rows as $row){
                $cursor['after']=(int)$row['id'];
                if(in_array((int)$row['status'],[1,5,6],true))$ids[]=(int)$row['id'];
                if(count($ids)===10)break;
            }
            if(count($ids)<10 && count($rows)<100)$cursor['after']=$cursor['ceiling'];
            \lib\Finance::checked($DB->update('funds_snapshot',['payload'=>json_encode($cursor,JSON_THROW_ON_ERROR)],['event_key'=>$key]));
            return $ids;
        });
    }

    //处理一个订单分账任务
    public static function loadItem($id){
        global $DB;
        return \lib\Finance::row('SELECT A.*,B.channel,B.account,B.name,B.rate,B.info,B.uid psuid,C.uid,C.subchannel,C.realmoney ordermoney FROM pre_psorder A LEFT JOIN pre_psreceiver B ON A.rid=B.id LEFT JOIN pre_order C ON C.trade_no=A.trade_no WHERE A.id=:id',[':id'=>$id]);
    }

    // First-submission immutable contract; legacy in-flight rows require evidence, not current config.
    public static function snapshot($id, $create=false){
        global $DB;
        $key='ps:'.$id;
        $saved=\lib\Finance::row('SELECT payload FROM pre_funds_snapshot WHERE event_key=:k',[':k'=>$key]);
        if($saved){$v=json_decode($saved['payload'],true);if(!is_array($v) || ($v['v']??0)!==1)throw new \RuntimeException('分账快照损坏');return $v;}
        if(!$create)throw new \RuntimeException('历史分账无快照，须供应商核对');
        $row=self::loadItem($id);
        $channel=$row['subchannel']>0?\lib\Channel::getSub($row['subchannel']):\lib\Channel::get($row['channel'],$row['uid']?$DB->findColumn('user','channelinfo',['uid'=>$row['uid']]):null);
        if(!$channel)throw new \RuntimeException('分账通道不存在');
        $info=!empty($row['info'])?json_decode($row['info'],true):[['account'=>$row['account'],'name'=>$row['name'],'rate'=>$row['rate']]];
        if(!is_array($info) || !$info)throw new \RuntimeException('分账接收方无效');
        $amount=!empty($row['sub_trade_no'])?$DB->findColumn('suborder','money',['sub_trade_no'=>$row['sub_trade_no']]):$row['ordermoney'];
        \lib\Finance::cents($amount);
        $v=['v'=>1,'psuid'=>$row['psuid'],'uid'=>$row['uid'],'mode'=>(int)$channel['mode'],'channel'=>$row['channel'],'subchannel'=>$row['subchannel'],'channel_hash'=>hash('sha256',json_encode($channel)),'info'=>$info,'ordermoney'=>$amount,'money'=>$row['money'],'trade_no'=>$row['sub_trade_no']?:$row['trade_no'],'api_trade_no'=>$row['api_trade_no']];
        \lib\Finance::checked($DB->insert('funds_snapshot',['event_key'=>$key,'payload'=>json_encode($v,JSON_THROW_ON_ERROR),'addtime'=>'NOW()']));
        return $v;
    }

    public static function snapshotChannel($v){
        global $DB;
        $channel=$v['subchannel']>0?\lib\Channel::getSub($v['subchannel']):\lib\Channel::get($v['channel'],$v['uid']?$DB->findColumn('user','channelinfo',['uid'=>$v['uid']]):null);
        if(!$channel || !hash_equals($v['channel_hash'],hash('sha256',json_encode($channel))))throw new \RuntimeException('通道身份/配置已变化，必须核对原供应商');
        return $channel;
    }

    // Complete financial state and ledger in the same row-locked transaction.
    public static function confirm($id,$status,$extra=[]){
        global $DB;
        return \lib\Finance::transaction(function() use($DB,$id,$status,$extra){
            $locked=\lib\Finance::row('SELECT * FROM pre_psorder WHERE id=:id FOR UPDATE',[':id'=>$id]);
            if(!$locked) throw new \RuntimeException('分账不存在');
            if((int)$locked['status']==2){if($status!=2)throw new \RuntimeException('分账终态冲突');return true;}
            if(!in_array((int)$locked['status'],[1,5],true)) throw new \RuntimeException('分账状态未认领');
            $v=self::snapshot($id);
            if(!in_array((int)$status,[1,2,3],true))throw new \RuntimeException('分账终态无效');
            if(isset($extra['money']) && \lib\Finance::cents($extra['money'])!==\lib\Finance::cents($v['money']))throw new \RuntimeException('供应商分账金额与快照不一致');
            if($status==2 && !empty($v['psuid']) && $v['mode']===0){
                \lib\Finance::change($v['psuid'],$v['money'],false,'订单分账','ps:'.$id);
            }
            $extra['status']=$status;
            \lib\Finance::checked($DB->update('psorder',$extra,['id'=>$id]));
            return true;
        });
    }

    public static function process_item($row){
        $result=self::operate($row['id']);
        echo htmlspecialchars($row['trade_no'].' '.($result['msg']??'待查询'),ENT_QUOTES,'UTF-8').'<br/>';
        return $result;
    }

    // 5=durable uncertain intent. Never auto-resubmit a claimed/failing intent.
    public static function operate($id,$action=null){
        global $DB;
        try {
            $current=$DB->find('psorder','*',['id'=>$id]);
            if($current && (int)$current['status']===6)return self::reversal($id,'return');
            if($DB->db->inTransaction()) throw new \RuntimeException('外部资金调用须独立提交intent');
            $claim=\lib\Finance::transaction(function() use($DB,$id,$action){
                $o=\lib\Finance::row('SELECT * FROM pre_psorder WHERE id=:id FOR UPDATE',[':id'=>$id]);
                if(!$o) throw new \RuntimeException('分账不存在');
                if($o['status']==2) return ['done'=>true];
                if($action=='submit' && $o['status']!=0) throw new \RuntimeException('原交易已提交或待核对，仅允许查询');
                if($action==='query' && $o['status']==0) throw new \RuntimeException('未提交分账不能以查询发起付款');
                if($o['status']==0){
                    if((float)$o['money']<=0) throw new \RuntimeException('分账金额必须大于0');
                    self::snapshot($id,true);
                    \lib\Finance::checked($DB->update('psorder',['status'=>5,'result'=>'已持久化分账intent，待核对'],['id'=>$id,'status'=>0]));
                    return ['submit'=>true];
                }
                if(!in_array((int)$o['status'],[1,5],true)) throw new \RuntimeException('失败分账请先按供应商合同核对，不能自动重试');
                return ['submit'=>false];
            });
            if(isset($claim['done'])) return ['code'=>0,'status'=>1,'msg'=>'分账已成功'];
            $row=self::loadItem($id);
            $v=self::snapshot($id);
            $channel=self::snapshotChannel($v);
            $model=self::getModel($channel);
            if(!$model) throw new \RuntimeException('通道不存在');
            $trade=$v['trade_no'];
            if($claim['submit']){
                $r=$model->submit($trade,$v['api_trade_no'],$v['ordermoney'],$v['info']);
                $extra=[];
                if(isset($r['settle_no']))$extra['settle_no']=$r['settle_no'];
                if(isset($r['rdata'])){$extra['rdata']=json_encode($r['rdata']);$extra['money']=number_format((float)$r['money'],2,'.','');}
                if(($r['code']??-9)==1) self::confirm($id,2,$extra);
                elseif(($r['code']??-9)==0) self::confirm($id,1,$extra);
                else return ['code'=>-2,'msg'=>'分账结果未确认，仅可查询原交易'];
            }else{
                if($model instanceof Wxpay || $model instanceof Alipay){
                    $expected=ReceiverState::expected($v['ordermoney'],$v['info']);
                    if(array_sum($expected)!==\lib\Finance::cents($v['money']))throw new \RuntimeException('分账总金额不符快照');
                    $r=$model->query($trade,$v['api_trade_no'],$row['settle_no'],$expected);
                    ReceiverState::save('psquery:'.$id,['v'=>1,'expected'=>$expected,'result'=>$r]);
                }else $r=$model->query($trade,$v['api_trade_no'],$row['settle_no']);
                if(($r['code']??-1)==0 && ($r['status']??0)==1) self::confirm($id,2);
                elseif(($r['code']??-1)==0 && ($r['status']??0)==2) self::confirm($id,3,['result'=>$r['reason']??'分账失败，禁止盲目重试']);
            }
            return $r;
        } catch(\Throwable $e){return ['code'=>-2,'msg'=>'分账未完成或待核对，请查询原交易，禁止重复提交'];}
    }

    public static function reversal($id,$action){
        global $DB;
        try {
            if($DB->db->inTransaction())throw new \RuntimeException('回退必须独立事务');
            $claim=\lib\Finance::transaction(function() use($DB,$id,$action){
                $o=\lib\Finance::row('SELECT * FROM pre_psorder WHERE id=:id FOR UPDATE',[':id'=>$id]);
                if(!$o)throw new \RuntimeException('分账不存在');
                if(($action=='return' && !in_array((int)$o['status'],[2,6],true)) || ($action=='unfreeeze' && $o['status']!=0))throw new \RuntimeException('当前状态不能回退/取消');
                self::snapshot($id,$action==='unfreeeze');
                \lib\Finance::checked($DB->update('psorder',['status'=>$action=='return'?6:7,'result'=>'回退/取消intent已提交，结果待核对'],['id'=>$id]));
                return $o;
            });
            $row=self::loadItem($id);
            $v=self::snapshot($id);
            $channel=self::snapshotChannel($v);
            $model=self::getModel($channel);
            if(!$model)throw new \RuntimeException('分账通道不存在');
            $trade=$v['trade_no'];
            if($action==='return' && (int)$claim['status']===6 && !($model instanceof Wxpay))throw new \RuntimeException('该通道未实现回退查询，禁止重放');
            if($action==='return' && $model instanceof Wxpay){
                $expected=ReceiverState::expected($v['ordermoney'],$v['info']);
                    if(array_sum($expected)!==\lib\Finance::cents($v['money']))throw new \RuntimeException('分账总金额不符快照');
                $rdata=json_decode($row['rdata']??'[]',true);
                $check=[];foreach($rdata?:[] as $r)$check[]=['account'=>(string)$r['account'],'amount'=>\lib\Finance::cents($r['money']),'state'=>'SUCCESS'];
                if(ReceiverState::aggregate($expected,$check,'account',true,'SUCCESS','FAIL','state')['status']!==1)throw new \RuntimeException('回退接收方不符快照');
            }
            $r=$action=='return'?$model->return($trade,$v['api_trade_no'],$row['settle_no'],json_decode($row['rdata']??'[]',true)?:[], (int)$claim['status']===6):$model->unfreeeze($trade,$v['api_trade_no']);
            if(($r['code']??-1)!=0)return ['code'=>-2,'msg'=>'回退/取消结果待核对，禁止重复调用'];
            \lib\Finance::transaction(function() use($DB,$id,$action,$row,$channel){
                $o=\lib\Finance::row('SELECT * FROM pre_psorder WHERE id=:id FOR UPDATE',[':id'=>$id]);
                if($o['status']!=($action=='return'?6:7))throw new \RuntimeException('回退状态冲突');
                // Existing implementation does not establish whether upstream return restores balance.
                // Preserve its no-credit semantics until per-provider contract is verified.
                \lib\Finance::checked($DB->update('psorder',['status'=>4,'result'=>'回退/取消已确认'],['id'=>$id]));
            });return $r;
        }catch(\Throwable $e){return ['code'=>-2,'msg'=>'回退/取消未确认，请按供应商原交易核对，禁止重复调用'];}
    }

    public static function processNotify($trade_no,$status,$errmsg=null,$settle_no=null){
        global $DB;
        $o=$DB->find('psorder','*',['trade_no'=>$trade_no]);
        if(!$o)return;
        if(!in_array((int)$status,[2,3],true))return;
        $extra=['result'=>$errmsg??''];
        if($settle_no)$extra['settle_no']=$settle_no;
        self::confirm($o['id'],(int)$status,$extra);
    }
}
