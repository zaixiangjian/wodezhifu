<?php
$nosession = true;
define('API_INIT', true);
require './includes/common.php';

if(isset($_GET['s'])){
	\lib\ApiHelper::load_api($_GET['s']);
	exit;
}

$act=isset($_GET['act'])?daddslashes($_GET['act']):null;
@header('Content-Type: application/json; charset=UTF-8');

function public_api_safe_token($value, $name='参数'){
	$value = trim((string)$value);
	if($value === '' || !preg_match('/^[a-zA-Z0-9_.:-]{1,128}$/', $value)) exit(json_encode(['code'=>-4, 'msg'=>$name.'不合法']));
	return $value;
}
function public_api_safe_money($value){
	$value = trim((string)$value);
	if($value !== '' && (!is_numeric($value) || !preg_match('/^[0-9]+(\.[0-9]{1,2})?$/', $value))) exit(json_encode(['code'=>-1, 'msg'=>'金额输入错误']));
	return $value;
}
function public_api_safe_limit($value, $default=10, $max=50){
	$value = isset($value) ? intval($value) : $default;
	if($value < 1) $value = $default;
	if($value > $max) $value = $max;
	return $value;
}
function public_api_safe_offset($value){
	$value = isset($value) ? intval($value) : 0;
	return $value < 0 ? 0 : $value;
}

if($act=='query')
{
	$pid=intval($_GET['pid']);
	$key=daddslashes($_GET['key']);
	$userrow=$DB->getRow("SELECT * FROM pre_user WHERE uid='{$pid}' limit 1");
	if(!$userrow) exit(json_encode(['code'=>-3, 'msg'=>'商户ID不存在']));
	if($key!==$userrow['key']) exit(json_encode(['code'=>-3, 'msg'=>'商户密钥错误']));
	if($userrow['keytype'] == 1) exit(json_encode(['code'=>-3, 'msg'=>'该商户只能使用RSA签名类型']));

	$orders=$DB->getColumn("SELECT count(*) from pre_order WHERE uid={$pid}");
	$lastday=date("Y-m-d",strtotime("-1 day"));
	$today=date("Y-m-d");
	$order_today=$DB->getColumn("SELECT count(*) from pre_order where uid={$pid} and status=1 and date='$today'");
	$order_lastday=$DB->getColumn("SELECT count(*) from pre_order where uid={$pid} and status=1 and date='$lastday'");

	$result=array("code"=>1,"pid"=>$pid,"key"=>$key,"active"=>$userrow['status'],"money"=>$userrow['money'],"type"=>$userrow['settle_id'],"account"=>$userrow['account'],"username"=>$userrow['username'],"orders"=>$orders,"orders_today"=>$order_today,"orders_lastday"=>$order_lastday);
	exit(json_encode($result));
}
elseif($act=='settle')
{
	$pid=intval($_GET['pid']);
	$key=daddslashes($_GET['key']);
	$limit=public_api_safe_limit($_GET['limit'] ?? null);
	$offset=public_api_safe_offset($_GET['offset'] ?? null);
	$userrow=$DB->getRow("SELECT * FROM pre_user WHERE uid='{$pid}' limit 1");
	if(!$userrow) exit(json_encode(['code'=>-3, 'msg'=>'商户ID不存在']));
	if($key!==$userrow['key']) exit(json_encode(['code'=>-3, 'msg'=>'商户密钥错误']));
	if($userrow['keytype'] == 1) exit(json_encode(['code'=>-3, 'msg'=>'该商户只能使用RSA签名类型']));

	$rs=$DB->query("SELECT * FROM pre_settle WHERE uid='{$pid}' order by id desc limit {$offset},{$limit}");
	while($row=$rs->fetch(PDO::FETCH_ASSOC)){
		$data[]=$row;
	}
	if($rs){
		$result=array("code"=>1,"msg"=>"查询结算记录成功！","data"=>$data);
	}else{
		$result=array("code"=>-1,"msg"=>"查询结算记录失败！");
	}
	exit(json_encode($result));
}
elseif($act=='order')
{
	if(isset($_GET['sign']) && isset($_GET['trade_no'])){
		$trade_no=public_api_safe_token($_GET['trade_no'], '订单号');
		if(empty($_GET['sign']) || md5(SYS_KEY.$trade_no.SYS_KEY) !== $_GET['sign']) exit(json_encode(['code'=>-3, 'msg'=>'verify sign failed']));
		$row=$DB->getRow("SELECT * FROM pre_order WHERE trade_no='{$trade_no}' limit 1");
	}else{
		$pid=intval($_GET['pid']);
		$key=daddslashes($_GET['key']);
		$userrow=$DB->getRow("SELECT * FROM pre_user WHERE uid='{$pid}' limit 1");
		if(!$userrow) exit(json_encode(['code'=>-3, 'msg'=>'商户ID不存在']));
		if($key!==$userrow['key']) exit(json_encode(['code'=>-3, 'msg'=>'商户密钥错误']));
		if($userrow['keytype'] == 1) exit(json_encode(['code'=>-3, 'msg'=>'该商户只能使用RSA签名类型']));

		if(!empty($_GET['trade_no'])){
			$trade_no=public_api_safe_token($_GET['trade_no'], '订单号');
			$row=$DB->getRow("SELECT * FROM pre_order WHERE uid='{$pid}' and trade_no='{$trade_no}' limit 1");
		}elseif(!empty($_GET['out_trade_no'])){
			$out_trade_no=public_api_safe_token($_GET['out_trade_no'], '商户订单号');
			$row=$DB->getRow("SELECT * FROM pre_order WHERE uid='{$pid}' and out_trade_no='{$out_trade_no}' limit 1");
		}else{
			exit(json_encode(['code'=>-4, 'msg'=>'订单号不能为空']));
		}
	}
	if($row){
		if(!\lib\PaymentEligibility::allows($row)) $row['payurl'] = '';
		$type=$DB->getColumn("SELECT name FROM pre_type WHERE id='{$row['type']}' LIMIT 1");
		$result=array("code"=>1,"msg"=>"succ","trade_no"=>$row['trade_no'],"out_trade_no"=>$row['out_trade_no'],"api_trade_no"=>$row['api_trade_no'],"bill_trade_no"=>$row['bill_trade_no'],"type"=>$type,"pid"=>$row['uid'],"addtime"=>$row['addtime'],"endtime"=>$row['endtime'],"name"=>$row['name'],"money"=>$row['money'],"param"=>$row['param'],"buyer"=>$row['buyer'],"status"=>$row['status'],"payurl"=>$row['payurl']);
	}else{
		$result=array("code"=>-1,"msg"=>"订单号不存在");
	}
	exit(json_encode($result));
}
elseif($act=='orders')
{
	$pid=intval($_GET['pid']);
	$key=daddslashes($_GET['key']);
	$limit=public_api_safe_limit($_GET['limit'] ?? null);
	$offset=public_api_safe_offset($_GET['offset'] ?? null);
	$status=isset($_GET['status'])?intval($_GET['status']):null;
	$userrow=$DB->getRow("SELECT * FROM pre_user WHERE uid='{$pid}' limit 1");
	if(!$userrow) exit(json_encode(['code'=>-3, 'msg'=>'商户ID不存在']));
	if($key!==$userrow['key']) exit(json_encode(['code'=>-3, 'msg'=>'商户密钥错误']));
	if($userrow['keytype'] == 1) exit(json_encode(['code'=>-3, 'msg'=>'该商户只能使用RSA签名类型']));

	$sql = " uid='{$pid}'";
	if(isset($_GET['status'])){
		$status = intval($_GET['status']);
		$sql .= " AND A.status='{$status}'";
	}

	$rs=$DB->query("SELECT A.*,B.name typename FROM pre_order A LEFT JOIN pre_type B ON A.type=B.id WHERE{$sql} ORDER BY trade_no DESC LIMIT {$offset},{$limit}");
	while($row=$rs->fetch(PDO::FETCH_ASSOC)){
		$data[]=["trade_no"=>$row['trade_no'],"out_trade_no"=>$row['out_trade_no'],"type"=>$row['typename'],"pid"=>$row['uid'],"addtime"=>$row['addtime'],"endtime"=>$row['endtime'],"name"=>$row['name'],"money"=>$row['money'],"param"=>$row['param'],"buyer"=>$row['buyer'],"status"=>$row['status']];
	}
	if($rs){
		$result=array("code"=>1,"msg"=>"查询订单记录成功！","count"=>count($data),"data"=>$data);
	}else{
		$result=array("code"=>-1,"msg"=>"查询订单记录失败！");
	}
	exit(json_encode($result));
}
elseif($act=='refund')
{
	if(!$conf['user_refund']) exit(json_encode(['code'=>-4, 'msg'=>'未开启商户后台自助退款']));
	$pid=intval($_POST['pid']);
	$key=daddslashes($_POST['key']);
	$userrow=$DB->getRow("SELECT * FROM pre_user WHERE uid='{$pid}' limit 1");
	if(!$userrow) exit(json_encode(['code'=>-3, 'msg'=>'商户ID不存在']));
	if($key!==$userrow['key']) exit(json_encode(['code'=>-3, 'msg'=>'商户密钥错误']));
	if($userrow['keytype'] == 1) exit(json_encode(['code'=>-3, 'msg'=>'该商户只能使用RSA签名类型']));
	if($userrow['refund'] == 0) exit(json_encode(['code'=>-2, 'msg'=>'商户未开启订单退款API接口']));

	$money = public_api_safe_money($_POST['money']);

	if(!empty($_POST['trade_no'])){
		$trade_no=public_api_safe_token($_POST['trade_no'], '订单号');
	}elseif(!empty($_POST['out_trade_no'])){
		$out_trade_no=public_api_safe_token($_POST['out_trade_no'], '商户订单号');
		$trade_no = $DB->findColumn('order', 'trade_no', ['out_trade_no'=>$out_trade_no, 'uid'=>$pid]);
		if(!$trade_no) exit(json_encode(['code'=>-1, 'msg'=>'当前订单不存在！']));
	}else{
		exit(json_encode(['code'=>-4, 'msg'=>'订单号不能为空']));
	}

	$refund_no = date("YmdHis").rand(11111,99999);
	$result = \lib\Order::refund($refund_no, $trade_no, $money, 1, $pid);
	if($result['code'] == 0){
		$result['msg'] = '退款成功！退款金额¥'.$result['money'];
	}
	exit(json_encode($result));
}
elseif($act=='refundapi')
{
	$money = public_api_safe_money($_POST['money']);
	$trade_no=public_api_safe_token($_POST['trade_no'], '订单号');
	if(!isset($_POST['key']) || $_POST['key']!==md5($trade_no.SYS_KEY.$trade_no))exit(json_encode(['code'=>-1, 'msg'=>'密钥错误']));

	$refund_no = date("YmdHis").rand(11111,99999);
	$result = \lib\Order::refund($refund_no, $trade_no, $money, 1);
	if($result['code'] == 0){
		$result['msg'] = '退款成功！退款金额￥'.$result['money'];
	}
	exit(json_encode($result));
}
else
{
	exit(json_encode(['code'=>-5, 'msg'=>'No Act!']));
}
