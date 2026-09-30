<?php
include("../includes/common.php");
if($islogin==1){}else exit("<script language='javascript'>window.location.href='./login.php';</script>");
$act=isset($_GET['act'])?daddslashes($_GET['act']):null;

if(!checkRefererHost())exit('{"code":403}');
csrf_check_json('admin');

@header('Content-Type: application/json; charset=UTF-8');

function admin_safe_column($column, $allowed){
	$column = trim((string)$column);
	if(!in_array($column, $allowed, true)) exit('{"code":-1,"msg":"筛选字段不合法"}');
	return $column;
}
function admin_safe_date($value){
	$value = trim((string)$value);
	if($value !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) exit('{"code":-1,"msg":"日期格式不合法"}');
	return $value;
}
function admin_safe_token($value, $name='参数'){
	$value = trim((string)$value);
	if($value === '' || !preg_match('/^[a-zA-Z0-9_.:-]{1,128}$/', $value)) exit('{"code":-1,"msg":"'.$name.'不合法"}');
	return $value;
}
function admin_safe_text($value, $max=128){
	$value = trim((string)$value);
	if(mb_strlen($value) > $max) exit('{"code":-1,"msg":"文本过长"}');
	return daddslashes($value);
}


switch($act){
case 'transferList':
	$sql=" 1=1";
	$params = [];
	if(isset($_POST['uid']) && !empty($_POST['uid'])) {
		$uid = intval($_POST['uid']);
		$sql.=" AND `uid`=:uid";
		$params[':uid'] = $uid;
	}
	if(isset($_POST['type']) && !empty($_POST['type'])) {
		$type = trim((string)$_POST['type']);
        if(!in_array($type,['alipay','wxpay','qqpay','bank'],true)) exit(json_encode(['code'=>-1,'msg'=>'转账类型不合法']));
		$sql.=" AND `type`=:type";
		$params[':type'] = $type;
	}
	if(isset($_POST['channel']) && !empty($_POST['channel'])) {
		$channel = intval($_POST['channel']);
		$sql.=" AND `channel`=:channel";
		$params[':channel'] = $channel;
	}
	if(isset($_POST['dstatus']) && $_POST['dstatus']>-1) {
		$dstatus = intval($_POST['dstatus']);
		$sql.=" AND `status`=:dstatus";
		$params[':dstatus'] = $dstatus;
	}
	if(!empty($_POST['starttime']) || !empty($_POST['endtime'])){
		if(!empty($_POST['starttime'])){
			$starttime = admin_safe_date($_POST['starttime']);
			$sql.=" AND addtime>=:starttime";
			$params[':starttime'] = $starttime.' 00:00:00';
		}
		if(!empty($_POST['endtime'])){
			$endtime = admin_safe_date($_POST['endtime']);
			$sql.=" AND addtime<=:endtime";
			$params[':endtime'] = $endtime.' 23:59:59';
		}
	}
	if(isset($_POST['value']) && !empty($_POST['value'])) {
		$column = admin_safe_column($_POST['column'], ['biz_no','out_biz_no','uid','type','channel','account','username','money','costmoney','status','desc']);
		$value = (string)$_POST['value'];
		if($column=='username'||$column=='desc'){
			$sql.=" AND `{$column}` LIKE :value";
			$params[':value'] = '%'.$value.'%';
		}else{
			$sql.=" AND `{$column}`=:value";
			$params[':value'] = $value;
		}
	}
	$offset = intval($_POST['offset']);
	$limit = intval($_POST['limit']);
	$total = $DB->getColumn("SELECT count(*) from pre_transfer WHERE{$sql}", $params);
	$list = $DB->getAll("SELECT * FROM pre_transfer WHERE{$sql} order by biz_no desc limit $offset,$limit", $params);
	$list2 = [];
	foreach($list as $row){
		if($row['type'] == 'wxpay' && $row['status'] == 0 && !empty($row['ext'])){
			if(substr($row['ext'], 0, 4) == 'http'){
				$row['jumpurl'] = $row['ext'];
			}else{
				$row['jumpurl'] = $siteurl.'paypage/wxtrans.php?id='.$row['biz_no'].'&type=transfer';
			}
		}
		if($row['status'] == 4){
			$row['jumpurl'] = \lib\Transfer::red_url($row['biz_no']);
		}
		$list2[] = $row;
	}

	exit(json_encode(['total'=>$total, 'rows'=>$list2]));
break;

case 'statistics':
	$sql=" 1=1";
	$params = [];
	if(isset($_POST['uid']) && !empty($_POST['uid'])) {
		$uid = intval($_POST['uid']);
		$sql.=" AND `uid`=:uid";
		$params[':uid'] = $uid;
	}
	if(isset($_POST['type']) && !empty($_POST['type'])) {
		$type = trim((string)$_POST['type']);
        if(!in_array($type,['alipay','wxpay','qqpay','bank'],true)) exit(json_encode(['code'=>-1,'msg'=>'转账类型不合法']));
		$sql.=" AND `type`=:type";
		$params[':type'] = $type;
	}
	if(isset($_POST['dstatus']) && $_POST['dstatus']>-1) {
		$dstatus = intval($_POST['dstatus']);
		$sql.=" AND `status`=:dstatus";
		$params[':dstatus'] = $dstatus;
	}
	if(!empty($_POST['starttime']) || !empty($_POST['endtime'])){
		if(!empty($_POST['starttime'])){
			$starttime = admin_safe_date($_POST['starttime']);
			$sql.=" AND addtime>=:starttime";
			$params[':starttime'] = $starttime.' 00:00:00';
		}
		if(!empty($_POST['endtime'])){
			$endtime = admin_safe_date($_POST['endtime']);
			$sql.=" AND addtime<=:endtime";
			$params[':endtime'] = $endtime.' 23:59:59';
		}
	}
	if(isset($_POST['value']) && !empty($_POST['value'])) {
		$column = admin_safe_column($_POST['column'], ['biz_no','out_biz_no','uid','type','channel','account','username','money','costmoney','status','desc']);
		$value = (string)$_POST['value'];
		if($column=='username'||$column=='desc'){
			$sql.=" AND `{$column}` LIKE :value";
			$params[':value'] = '%'.$value.'%';
		}else{
			$sql.=" AND `{$column}`=:value";
			$params[':value'] = $value;
		}
	}
	$totalMoney = $DB->getColumn("SELECT SUM(money) FROM pre_transfer WHERE{$sql} AND status<>2", $params);
	$resultCount = $DB->getRow("SELECT 
    COUNT(*) AS totalCount,
    COUNT(status = 0 OR NULL) AS status0count,
    COUNT(status = 1 OR NULL) AS status1count,
    COUNT(status = 2 OR NULL) AS status2count,
    COUNT(status = 3 OR NULL) AS status3count
    FROM pre_transfer WHERE{$sql}", $params);
	exit(json_encode(['code'=>0, 'data'=>['totalMoney'=>number_format($totalMoney ?? 0, 2, '.', ''), 'totalCount'=>$resultCount['totalCount'], 'status0count'=>$resultCount['status0count'], 'status1count'=>$resultCount['status1count'], 'status2count'=>$resultCount['status2count'], 'status3count'=>$resultCount['status3count']]]));
break;

case 'transfer_query':
	if($_SERVER['REQUEST_METHOD'] !== 'POST') exit('{"code":405,"msg":"Method Not Allowed"}');
	if(!isset($_POST['csrf_token']) || !is_string($_POST['csrf_token']) || !hash_equals(csrf_token('admin'), $_POST['csrf_token'])) exit('{"code":403,"msg":"CSRF TOKEN ERROR"}');
	$biz_no=admin_safe_token($_POST['biz_no'] ?? '', '付款单号');
	$result = \lib\Transfer::status($biz_no);
	exit(json_encode($result));
break;
case 'transfer_result':
	$biz_no=admin_safe_token($_GET['biz_no'], '付款单号');
    $row = $DB->find('transfer', 'biz_no,result', ['biz_no' => $biz_no]);
	if(!$row) exit('{"code":-1,"msg":"付款记录不存在！"}');
	$result = ['code'=>0,'msg'=>$row['result']?$row['result']:'未知'];
	exit(json_encode($result));
break;
case 'transfer_cancel':
	$biz_no=admin_safe_token($_POST['biz_no'], '付款单号');
	$result = \lib\Transfer::cancel($biz_no);
	exit(json_encode($result));
break;
case 'balance_query':
	$type = $_POST['type'];
	$channel = isset($_POST['channel'])?intval($_POST['channel']):$conf['transfer_'.$type];
	$channel = \lib\Channel::get($channel);
	if(!$channel)exit('{"code":-1,"msg":"当前支付通道信息不存在"}');
	$user_id = isset($_POST['user_id'])?$_POST['user_id']:null;
	$result = \lib\Transfer::balance($type, $channel, $user_id);
	exit(json_encode($result));
break;
case 'setTransferStatus':
	$biz_no=admin_safe_token($_POST['biz_no'], '付款单号');
    try {\lib\Transfer::finish($biz_no,intval($_POST['status']),trim($_POST['reason']??'')); exit(json_encode(['code'=>0,'msg'=>'succ']));}
    catch(\Throwable $e){exit(json_encode(['code'=>-2,'msg'=>'状态冲突或确认失败，请核对']));}
break;
case 'delTransfer':
	$biz_no=admin_safe_token($_POST['biz_no'], '付款单号');
	if($DB->update('transfer', ['result'=>'已归档（保留资金凭证）'], ['biz_no'=>$biz_no,'status'=>2])!==false)exit('{"code":0,"msg":"已归档，保留资金凭证"}');
	else exit('{"code":-1,"msg":"删除失败['.$DB->error().']"}');
break;
case 'refundTransfer':
	$biz_no=admin_safe_token($_POST['biz_no'], '付款单号');
    try {\lib\Transfer::finish($biz_no,2,'管理员确认退款');exit(json_encode(['code'=>0,'msg'=>'已确认失败并退回余额']));}
    catch(\Throwable $e){exit(json_encode(['code'=>-2,'msg'=>'状态冲突或退款未完成，请核对']));}
break;
case 'transfer_proof':
	$biz_no=admin_safe_token($_POST['biz_no'], '付款单号');
	$result = \lib\Transfer::proof($biz_no);
	exit(json_encode($result));
break;
case 'operation': //批量操作订单
	$status=is_numeric($_POST['status'])?intval($_POST['status']):exit('{"code":-1,"msg":"请选择操作"}');
	$checkbox=$_POST['checkbox'];
    $i=0;
    foreach($checkbox as $biz_no){
        try {
            if($status==3){
                // Retain the financial tombstone and external intent instead of deleting it.
                $r=$DB->find('transfer','status',['biz_no'=>$biz_no]);
                if(!$r || !in_array((int)$r['status'],[1,2],true)) throw new \RuntimeException('未终结记录不可删除');
                continue;
            }
            \lib\Transfer::finish($biz_no,$status,'管理员确认');$i++;
        }catch(\Throwable $e){exit(json_encode(['code'=>-2,'msg'=>'批量确认未完成，请核对','completed'=>$i]));}
    }
    exit(json_encode(['code'=>0,'msg'=>'成功改变'.$i.'条订单状态']));
break;

case 'batch_submit':
	$type = isset($_POST['type'])?trim($_POST['type']):'alipay';
	if(!in_array($type, ['alipay','wxpay','qqpay','bank'], true)) exit('{"code":-2,"msg":"转账类型不合法"}');
	$out_biz_no = date("YmdHis").rand(11111,99999);
	if(!isset($_POST['paypwd']) || $_POST['paypwd']!==$conf['admin_paypwd'])exit('{"code":-2,"msg":"支付密码错误"}');
	$payee_account = trim($_POST['account']);
	$payee_real_name = trim($_POST['name']);
	$money = trim($_POST['money']);
	$desc = trim($_POST['desc']);
	if(empty($payee_account) || empty($money))exit('{"code":-2,"msg":"必填项不能为空"}');
	if($desc && mb_strlen($desc)>32)exit('{"code":-2,"msg":"转账备注最多32个字"}');
	if(!is_numeric($money) || !preg_match('/^[0-9.]+$/', $money) || $money<=0)exit('{"code":-2,"msg":"转账金额输入不规范"}');

	$channelid = isset($_POST['channel'])?intval($_POST['channel']):null;

	$result = \lib\Transfer::add(0, $type, $out_biz_no, $payee_account, $payee_real_name, $money, null, $desc, null, $channelid);

	if($result['code']==0){
		if($result['status'] == 1){
			$msg='转账成功！转账单据号:'.$result['orderid'];
		}elseif($result['status'] == 3){
			$msg='提交成功！请等待管理员审核转账。';
		}elseif(isset($result['wxpackage'])){
			$msg='提交成功！请在付款记录页面扫码确认收款。转账单据号:'.$result['orderid'];
		}else{
			$msg='提交成功！转账处理中，请稍后查询结果。转账单据号:'.$result['orderid'];
		}
		exit(json_encode(['code'=>0, 'status'=>$result['status'], 'msg'=>$msg]));
	}else{
		if(!isset($result['errcode']) || in_array($result['errcode'], \lib\Transfer::$payee_err_code)){
            //$data = ['biz_no'=>$out_biz_no, 'out_biz_no'=>$out_biz_no, 'uid'=>0, 'type'=>$type, 'channel'=>$channelid, 'account'=>$payee_account, 'username'=>$payee_real_name, 'money'=>$money, 'costmoney'=>$money, 'addtime'=>'NOW()', 'pay_order_no'=>$result['orderid'], 'status'=>2, 'desc'=>$desc, 'result'=>$result['msg']];
            //$DB->insert('transfer', $data);
			exit(json_encode(['code'=>-1, 'msg'=>$result['msg']]));
		}else{
			exit(json_encode(['code'=>-2, 'msg'=>$result['msg']]));
		}
	}
break;

case 'stat':
	$startday = trim($_POST['startday']);
	$endday = trim($_POST['endday']);
	if(!$startday || !$endday)exit(json_encode(['code'=>0, 'msg'=>'no day']));
	$sql="`addtime`>='{$startday} 00:00:00' AND `addtime`<='{$endday} 23:59:59' AND status=1";
	if(isset($_POST['type']) && !empty($_POST['type'])) {
		$type = trim($_POST['type']);
		if(!in_array($type, ['alipay','wxpay','qqpay','bank'], true)) exit(json_encode(['code'=>-1,'msg'=>'转账类型不合法']));
		$sql.=" AND `type`='$type'";
	}
	$list = $DB->getAll("SELECT account,username,COUNT(*) AS order_count,SUM(money) AS money
		FROM pre_transfer
		WHERE {$sql}
		GROUP BY account,username
		ORDER BY money DESC");
	exit(json_encode($list));
break;
default:
	exit('{"code":-4,"msg":"No Act"}');
break;
}