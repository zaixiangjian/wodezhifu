<?php
include("../includes/common.php");
if($islogin==1){}else exit("<script language='javascript'>window.location.href='./login.php';</script>");
$act=isset($_GET['act'])?daddslashes($_GET['act']):null;

if(!checkRefererHost())exit('{"code":403}');
csrf_check_json('admin');

@header('Content-Type: application/json; charset=UTF-8');

function epay_ajax_uint($value, $min=1, $max=2147483647){
    if((!is_string($value) && !is_int($value)) || !preg_match('/^(0|[1-9][0-9]*)$/D', (string)$value) || strlen((string)$value)>10 || $value<$min || $value>$max)
        exit('{"code":-1,"msg":"整数参数不合法"}');
    return (int)$value;
}
function epay_ajax_batch($values, $tokens=false){
    if(!is_array($values) || count($values)<1 || count($values)>500 || array_keys($values)!==range(0,count($values)-1))
        exit('{"code":-1,"msg":"批量参数必须是1至500项的列表"}');
    $result=[];
    foreach($values as $value){
        if($tokens){
            if((!is_string($value) && !is_int($value)) || !preg_match('/^[a-zA-Z0-9_.:-]{1,64}$/D',(string)$value))
                exit('{"code":-1,"msg":"订单号不合法"}');
            $result[]=(string)$value;
        }else $result[]=epay_ajax_uint($value);
    }
    return array_values(array_unique($result, SORT_STRING));
}

function admin_safe_column($column, $allowed){
	if(!is_string($column)) exit('{"code":-1,"msg":"筛选字段不合法"}');
	$column = trim((string)$column);
	if(!in_array($column, $allowed, true)) exit('{"code":-1,"msg":"筛选字段不合法"}');
	return $column;
}
function admin_safe_date($value){
	if(!is_string($value) && !is_int($value)) exit('{"code":-1,"msg":"参数类型不合法"}');
	$value = trim((string)$value);
	if($value !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) exit('{"code":-1,"msg":"日期格式不合法"}');
	return $value;
}


foreach(['value','column','dstatus','order','uid','type','channel','subchannel','applyid','trade_no','money','paypwd','isget','isreturn'] as $key){
	foreach([$_POST,$_GET] as $input) if(isset($input[$key]) && !is_string($input[$key]) && !is_int($input[$key])) exit('{"code":-1,"msg":"参数类型不合法"}');
}
switch($act){
case 'orderList':
	$paytype = [];
	$paytypes = [];
	$rs = $DB->getAll("SELECT * FROM pre_type");
	foreach($rs as $row){
		$paytype[$row['id']] = $row['showname'];
		$paytypes[$row['id']] = $row['name'];
	}
	unset($rs);

	[$sql, $params] = [" 1=1", []];
	if(isset($_POST['uid']) && !empty($_POST['uid'])) {
		$uid = intval($_POST['uid']);
		[$sql, $params] = [$sql." AND A.`uid`=:b1", $params + [':b1'=>"$uid"]];
	}
	if(isset($_POST['type']) && !empty($_POST['type'])) {
		$type = intval($_POST['type']);
		[$sql, $params] = [$sql." AND A.`type`=:b2", $params + [':b2'=>"$type"]];
	}elseif(isset($_POST['channel']) && !empty($_POST['channel'])) {
		$channel = intval($_POST['channel']);
		[$sql, $params] = [$sql." AND A.`channel`=:b3", $params + [':b3'=>"$channel"]];
	}elseif(isset($_POST['subchannel']) && !empty($_POST['subchannel'])) {
		$subchannel = intval($_POST['subchannel']);
		[$sql, $params] = [$sql." AND A.`subchannel`=:b4", $params + [':b4'=>"$subchannel"]];
	}elseif(isset($_POST['applyid']) && !empty($_POST['applyid'])) {
		$applyid = intval($_POST['applyid']);
		[$sql, $params] = [$sql." AND A.`subchannel` IN (SELECT id FROM pre_subchannel WHERE apply_id=:b5)", $params + [':b5'=>"{$applyid}"]];
	}
	if(isset($_POST['dstatus']) && !isNullOrEmpty($_POST['dstatus'])) {
		if(substr($_POST['dstatus'], 0, 6) == 'settle'){
			$dstatus = intval(substr($_POST['dstatus'], 7));
			[$sql, $params] = [$sql." AND A.settle=:b6", $params + [':b6'=>$dstatus]];
		}else{
			$dstatus = intval($_POST['dstatus']);
			[$sql, $params] = [$sql." AND A.status=:b7", $params + [':b7'=>$dstatus]];
		}
	}
	if(!empty($_POST['starttime']) || !empty($_POST['endtime'])){
		if(!empty($_POST['starttime'])){
			$starttime = admin_safe_date($_POST['starttime']);
			[$sql, $params] = [$sql." AND A.addtime>=:b8", $params + [':b8'=>"{$starttime} 00:00:00"]];
		}
		if(!empty($_POST['endtime'])){
			$endtime = admin_safe_date($_POST['endtime']);
			[$sql, $params] = [$sql." AND A.addtime<=:b9", $params + [':b9'=>"{$endtime} 23:59:59"]];
		}
	}
	if(isset($_POST['value']) && !empty($_POST['value'])) {
		$column = admin_safe_column($_POST['column'], ['trade_no','out_trade_no','api_trade_no','uid','domain','name','money','realmoney','getmoney','buyer','ip']);
		$value = $_POST['value'];
		if($column=='name'){
			[$sql, $params] = [$sql." AND A.`{$column}` like :b10", $params + [':b10'=>"%{$value}%"]];
		}else{
			if(($column == 'money' || $column == 'realmoney' || $column == 'getmoney') && strpos($value,'-')){
				$money = explode('-', $value, 2);
				$min = is_numeric($money[0]) ? $money[0] : '0';
				$max = is_numeric($money[1]) ? $money[1] : '0';
				[$sql, $params] = [$sql." AND A.`{$column}`>=:b11 AND A.`{$column}`<=:b12", $params + [':b11'=>"{$min}", ':b12'=>"{$max}"]];
			}else{
				[$sql, $params] = [$sql." AND A.`{$column}`=:b13", $params + [':b13'=>"{$value}"]];
			}
		}
	}
	$offset = epay_ajax_uint($_POST['offset'] ?? 0, 0, 10000000);
	$limit = epay_ajax_uint($_POST['limit'] ?? 20, 1, 500);
	$total = $DB->getColumn("SELECT count(*) from pre_order A WHERE{$sql}", $params);
	$list = $DB->getAll("SELECT A.*,B.plugin,B.name channelname FROM pre_order A LEFT JOIN pre_channel B ON A.channel=B.id WHERE{$sql} order by trade_no desc limit $offset,$limit", $params);
	$list2 = [];
	foreach($list as $row){
		$row['typename'] = $paytypes[$row['type']];
		$row['typeshowname'] = $paytype[$row['type']];
		$list2[] = $row;
	}

	exit(json_encode(['total'=>$total, 'rows'=>$list2]));
break;

case 'statistics':
    [$sql, $params] = [" 1=1", []];
	if(isset($_POST['uid']) && !empty($_POST['uid'])) {
		$uid = intval($_POST['uid']);
		[$sql, $params] = [$sql." AND A.`uid`=:b14", $params + [':b14'=>"$uid"]];
	}
	if(isset($_POST['type']) && !empty($_POST['type'])) {
		$type = intval($_POST['type']);
		[$sql, $params] = [$sql." AND A.`type`=:b15", $params + [':b15'=>"$type"]];
	}elseif(isset($_POST['channel']) && !empty($_POST['channel'])) {
		$channel = intval($_POST['channel']);
		[$sql, $params] = [$sql." AND A.`channel`=:b16", $params + [':b16'=>"$channel"]];
	}elseif(isset($_POST['subchannel']) && !empty($_POST['subchannel'])) {
		$subchannel = intval($_POST['subchannel']);
		[$sql, $params] = [$sql." AND A.`subchannel`=:b17", $params + [':b17'=>"$subchannel"]];
	}
	if(isset($_POST['dstatus']) && $_POST['dstatus']>-1) {
		$dstatus = intval($_POST['dstatus']);
		[$sql, $params] = [$sql." AND A.status=:b18", $params + [':b18'=>$dstatus]];
	}
	if(!empty($_POST['starttime']) || !empty($_POST['endtime'])){
		if(!empty($_POST['starttime'])){
			$starttime = admin_safe_date($_POST['starttime']);
			[$sql, $params] = [$sql." AND A.addtime>=:b19", $params + [':b19'=>"{$starttime} 00:00:00"]];
		}
		if(!empty($_POST['endtime'])){
			$endtime = admin_safe_date($_POST['endtime']);
			[$sql, $params] = [$sql." AND A.addtime<=:b20", $params + [':b20'=>"{$endtime} 23:59:59"]];
		}
	}
	if(isset($_POST['value']) && !empty($_POST['value'])) {
		$column = admin_safe_column($_POST['column'], ['trade_no','out_trade_no','api_trade_no','uid','domain','name','money','realmoney','getmoney','buyer','ip']);
		$value = $_POST['value'];
		if($column=='name'){
			[$sql, $params] = [$sql." AND A.`{$column}` like :b21", $params + [':b21'=>"%{$value}%"]];
		}else{
			if(($column == 'money' || $column == 'realmoney' || $column == 'getmoney') && strpos($value,'-')){
				$money = explode('-', $value, 2);
				$min = is_numeric($money[0]) ? $money[0] : '0';
				$max = is_numeric($money[1]) ? $money[1] : '0';
				[$sql, $params] = [$sql." AND A.`{$column}`>=:b22 AND A.`{$column}`<=:b23", $params + [':b22'=>"{$min}", ':b23'=>"{$max}"]];
			}else{
				[$sql, $params] = [$sql." AND A.`{$column}`=:b24", $params + [':b24'=>"{$value}"]];
			}
		}
	}
    // 统计数据
    $resultMoneyData = $DB->getRow("SELECT 
    SUM(money) AS totalMoney,
    SUM(CASE WHEN A.status = 1 THEN money ELSE 0 END) AS successMoney,
    SUM(CASE WHEN A.status = 0 THEN money ELSE 0 END) AS unpaidMoney,
    SUM(CASE WHEN A.status = 2 THEN refundmoney ELSE 0 END) AS refundMoney
    FROM pre_order A LEFT JOIN pre_channel B ON A.channel=B.id WHERE {$sql} order by trade_no desc", $params);

    $resultCount = $DB->getRow("SELECT 
    COUNT(*) AS totalCount,
    SUM(CASE WHEN A.status = 1 THEN 1 ELSE 0 END) AS successCount,
    SUM(CASE WHEN A.status = 0 THEN 1 ELSE 0 END) AS unpaidCount,
    SUM(CASE WHEN A.status = 2 THEN 1 ELSE 0 END) AS refundCount
    FROM pre_order A LEFT JOIN pre_channel B ON A.channel=B.id WHERE {$sql} order by trade_no desc", $params);

    // 获取平台总收入利润
    $platformProfit = $DB->getColumn("SELECT SUM(A.profitmoney) FROM pre_order A LEFT JOIN pre_channel B ON A.channel=B.id WHERE {$sql} AND A.status = 1 order by trade_no desc", $params);

	$result = [
        'totalMoney' => number_format($resultMoneyData['totalMoney'] ?? 0, 2, '.', ''),
        'successMoney' => number_format($resultMoneyData['successMoney'] ?? 0, 2, '.', ''),
        'unpaidMoney' => number_format($resultMoneyData['unpaidMoney'] ?? 0, 2, '.', ''),
        'refundMoney' => number_format($resultMoneyData['refundMoney'] ?? 0, 2, '.', ''),
        'totalCount' => $resultCount['totalCount'] ?? '0',
        'successCount' => $resultCount['successCount'] ?? '0',
        'unpaidCount' => $resultCount['unpaidCount'] ?? '0',
        'refundCount' => $resultCount['refundCount'] ?? '0',
        'platformProfit' => number_format($platformProfit ?? 0, 2, '.', '')
    ];
	$result['successRate'] = $result['totalCount'] > 0 ? round(($result['totalCount']-$result['unpaidCount']) / $result['totalCount'] * 100, 2) : 0;
	exit(json_encode(['code'=>0, 'data'=>$result]));
break;

case 'riskList':
	[$sql, $params] = [" 1=1", []];
	if(isset($_POST['value']) && !empty($_POST['value'])) {
		$column = admin_safe_column($_POST['column'], ['trade_no','out_trade_no','uid','type','channel','status','risk','content']);
		$value = $_POST['value'];
		[$sql, $params] = [$sql." AND `{$column}`=:b25", $params + [':b25'=>"{$value}"]];
	}
	if(isset($_POST['type']) && $_POST['type']>-1) {
		$type = intval($_POST['type']);
		[$sql, $params] = [$sql." AND `type`=:b26", $params + [':b26'=>$type]];
	}
	$offset = epay_ajax_uint($_POST['offset'] ?? 0, 0, 10000000);
	$limit = epay_ajax_uint($_POST['limit'] ?? 20, 1, 500);
	$total = $DB->getColumn("SELECT count(*) from pre_risk WHERE{$sql}", $params);
	$list = $DB->getAll("SELECT * FROM pre_risk WHERE{$sql} order by id desc limit $offset,$limit", $params);

	exit(json_encode(['total'=>$total, 'rows'=>$list]));
break;

case 'setStatus': // 财务状态必须通过记账流程变更。
	if($_SERVER['REQUEST_METHOD'] !== 'POST') exit('{"code":405,"msg":"Method Not Allowed"}');
	exit('{"code":400,"msg":"禁止直接改资金状态或删除订单；请使用退款、冻结、解冻或人工核对流程"}');
break;
case 'order': //订单详情
	$trade_no=trim($_GET['trade_no']);
	$row=$DB->getRow("select A.*,B.showname typename,C.name channelname from pre_order A,pre_type B,pre_channel C where trade_no=:b30 and A.type=B.id and A.channel=C.id limit 1", [':b30'=>"$trade_no"]);
	if(!$row)
		exit('{"code":-1,"msg":"当前订单不存在或未成功选择支付通道！"}');
	$row['subchannelname'] = $row['subchannel'] > 0 ? $DB->findColumn('subchannel', 'name', ['id'=>$row['subchannel']]) : '';
	if($row['status']==2){
		$row['refundtime'] = $DB->findColumn('refundorder', 'addtime', ['trade_no'=>$trade_no], 'refund_no DESC');
	}
	$result=array("code"=>0,"msg"=>"succ","data"=>$row);
	exit(json_encode($result));
break;
case 'subOrders':
	$trade_no=trim($_GET['trade_no']);
	$list = \lib\Payment::getSubOrders($trade_no);
	exit(json_encode(['code'=>0, 'data'=>$list, 'settle'=>$DB->findColumn('order', 'settle', ['trade_no'=>$trade_no])]));
break;
case 'operation': //批量仅允许走带行锁的财务服务
	if($_SERVER['REQUEST_METHOD'] !== 'POST') exit('{"code":405,"msg":"Method Not Allowed"}');
	$status=$_POST['status'] ?? null;
	if(!in_array($status, ['2','3',2,3], true)) exit('{"code":-1,"msg":"批量仅支持冻结或解冻；禁止直接改状态和删除"}');
	$checkbox=epay_ajax_batch($_POST['checkbox'] ?? null, true);
	$ok=0; $failed=[];
	foreach($checkbox as $trade_no){
		try {
			$r=(int)$status===2 ? \lib\Order::freeze($trade_no) : \lib\Order::unfreeze($trade_no);
			if(isset($r['code']) && $r['code']===0) $ok++;
			else $failed[]=['trade_no'=>$trade_no,'msg'=>$r['msg'] ?? '操作失败'];
		} catch (\Throwable $e) { $failed[]=['trade_no'=>$trade_no,'msg'=>'资金操作失败，请核对后处理']; }
	}
	exit(json_encode(['code'=>$failed ? -1 : 0,'msg'=>'成功处理'.$ok.'条订单','changed'=>$ok,'failed'=>$failed,'total'=>count($checkbox)]));
break;
case 'getmoney': //退款查询
	if(!$conf['admin_paypwd'])exit('{"code":-1,"msg":"你还未设置支付密码"}');
	$trade_no=trim($_POST['trade_no']);
	if(!preg_match('/^[a-zA-Z0-9_.:-]{1,64}$/', $trade_no)) exit('{"code":-1,"msg":"订单号不合法"}');
	$api=isset($_POST['api'])?intval($_POST['api']):0;
	$result = \lib\Order::refund_info($trade_no, $api);
	exit(json_encode($result));
break;
case 'refund': //退款操作
	$trade_no=trim($_POST['trade_no']);
	if(!preg_match('/^[a-zA-Z0-9_.:-]{1,64}$/', $trade_no)) exit('{"code":-1,"msg":"订单号不合法"}');
	$money = trim($_POST['money']);
	if(!is_numeric($money) || !preg_match('/^[0-9.]+$/', $money))exit('{"code":-1,"msg":"金额输入错误"}');

	$refund_no = date("YmdHis").rand(11111,99999);
	$result = \lib\Order::refund($refund_no, $trade_no, $money);
	if($result['code'] == 0){
		$account_uid = $result['account_uid'] ?? $result['uid'];
		$account_label = ($result['account_type'] ?? 'money') === 'deposit' ? '保证金' : '余额';
		$result['msg'] = '已成功从UID:'.$account_uid.'扣除'.$result['reducemoney'].'元'.$account_label;
	}
	exit(json_encode($result));
break;
case 'apirefund': //API退款操作
	$trade_no=trim($_POST['trade_no']);
	if(!preg_match('/^[a-zA-Z0-9_.:-]{1,64}$/', $trade_no)) exit('{"code":-1,"msg":"订单号不合法"}');
	$paypwd=trim($_POST['paypwd']);
	$money = trim($_POST['money']);
	if(!is_numeric($money) || !preg_match('/^[0-9.]+$/', $money))exit('{"code":-1,"msg":"金额输入错误"}');
	if($paypwd!=$conf['admin_paypwd'])
		exit('{"code":-1,"msg":"支付密码输入错误！"}');
	
	$refund_no = date("YmdHis").rand(11111,99999);
	$result = \lib\Order::refund($refund_no, $trade_no, $money, 1);
	if($result['code'] == 0){
		$result['msg'] = '退款成功！退款金额¥'.$result['money'];
		if($result['reducemoney']>0){
			$account_uid = $result['account_uid'] ?? $result['uid'];
			$account_label = ($result['account_type'] ?? 'money') === 'deposit' ? '保证金' : '余额';
			$result['msg'] .= '，并成功从UID:'.$account_uid.'扣除'.$result['reducemoney'].'元'.$account_label;
		}
	}
	exit(json_encode($result));
break;
case 'freeze': //冻结订单
	$trade_no=trim($_POST['trade_no']);
	if(!preg_match('/^[a-zA-Z0-9_.:-]{1,64}$/', $trade_no)) exit('{"code":-1,"msg":"订单号不合法"}');
	$result = \lib\Order::freeze($trade_no);
	exit(json_encode($result));
break;
case 'unfreeze': //解冻订单
	$trade_no=trim($_POST['trade_no']);
	if(!preg_match('/^[a-zA-Z0-9_.:-]{1,64}$/', $trade_no)) exit('{"code":-1,"msg":"订单号不合法"}');
	$result = \lib\Order::unfreeze($trade_no);
	exit(json_encode($result));
break;
case 'notify': //获取回调地址
	$trade_no=trim($_POST['trade_no']);
	if(!preg_match('/^[a-zA-Z0-9_.:-]{1,64}$/', $trade_no)) exit('{"code":-1,"msg":"订单号不合法"}');
	$row=$DB->getRow("select * from pre_order where trade_no=:b34 limit 1", [':b34'=>"$trade_no"]);
	if(!$row)
		exit('{"code":-1,"msg":"当前订单不存在！"}');
	$url=creat_callback($row);
	if($_POST['isget'] == 1){
		if(do_notify($url['notify'])){
			$DB->exec("UPDATE pre_order SET notify=0 WHERE trade_no=:b35", [':b35'=>"$trade_no"]);
			exit('{"code":0}');
		}
		exit('{"code":-1}');
	}
	if($row['notify']>0)
		$DB->exec("update pre_order set notify=0,notifytime=NULL where trade_no=:b36", [':b36'=>"$trade_no"]);
	exit('{"code":0,"url":"'.($_POST['isreturn']==1?$url['return']:$url['notify']).'"}');
break;
case 'fillorder': //手动补单
	$trade_no=trim($_POST['trade_no']);
	if(!preg_match('/^[a-zA-Z0-9_.:-]{1,64}$/', $trade_no)) exit('{"code":-1,"msg":"订单号不合法"}');
	$row=$DB->getRow("SELECT A.*,B.name typename,B.showname typeshowname FROM pre_order A left join pre_type B on A.type=B.id WHERE trade_no=:trade_no limit 1", [':trade_no'=>$trade_no]);
	if(!$row)
		exit('{"code":-1,"msg":"当前订单不存在！"}');
	if($row['status']>0)exit('{"code":-1,"msg":"当前订单不是未完成状态！"}');
	try {
        \lib\Payment::processOrder(true, $row, $row['api_trade_no'] ?: null, $row['buyer'] ?? null, null, null, $date);
        $fresh = \lib\Finance::row('SELECT status FROM pre_order WHERE trade_no=:n', [':n'=>$trade_no]);
        if (!$fresh || (int)$fresh['status']!==1) throw new \RuntimeException('本地入账未确认');
        exit(json_encode(['code'=>0,'msg'=>'补单成功']));
    } catch (\Throwable $e) { exit(json_encode(['code'=>-2,'msg'=>'补单未完成，请核对本地订单；未确认成功'])); }

break;
case 'alipaydSettle': //支付宝直付通确认结算
	$trade_no=trim($_POST['trade_no']);
	if(!preg_match('/^[a-zA-Z0-9_.:-]{1,64}$/', $trade_no)) exit('{"code":-1,"msg":"订单号不合法"}');
	$row=$DB->getRow("select * from pre_order where trade_no=:b40 limit 1", [':b40'=>"$trade_no"]);
	if(!$row)
		exit('{"code":-1,"msg":"当前订单不存在！"}');
	if($row['status']==0)exit('{"code":-1,"msg":"当前订单状态是未支付"}');
	$channel = $row['subchannel'] > 0 ? \lib\Channel::getSub($row['subchannel']) : \lib\Channel::get($row['channel'], $DB->findColumn('user', 'channelinfo', ['uid'=>$row['uid']]));
	if(!$channel){
		exit('{"code":-1,"msg":"当前支付通道信息不存在"}');
	}
    $lock=substr('epay_settle_'.hash('sha256',$trade_no),0,64);
    $reply=['code'=>-2,'msg'=>'结算未确认，请核对原交易，禁止重复提交'];
    $got=false;
    try {
        if($DB->db->inTransaction()) throw new \RuntimeException('结算不得嵌套事务');
        $got=(int)\lib\Finance::checked($DB->query('SELECT GET_LOCK(:name,0)',[':name'=>$lock]))->fetchColumn()===1;
        if(!$got) throw new \RuntimeException('结算处理中');
        $row=\lib\Finance::row('SELECT * FROM pre_order WHERE trade_no=:n',[':n'=>$trade_no]);
        if((int)$row['status']!==1) throw new \RuntimeException('订单未支付');
        if((int)$row['settle']===2) $reply=['code'=>0,'msg'=>'已结算'];
        else {
            if((int)$row['settle']!==1) throw new \RuntimeException('非待结算状态，仅可核对');
            $channel=$row['subchannel']>0 ? \lib\Channel::getSub($row['subchannel']) : \lib\Channel::get($row['channel'],$DB->findColumn('user','channelinfo',['uid'=>$row['uid']]));
            if(!$channel || !in_array($channel['plugin'],['alipayd','wxpaynp'],true)) throw new \RuntimeException('通道不支持');
            if(\lib\Finance::checked($DB->update('order',['settle'=>4],['trade_no'=>$trade_no,'status'=>1,'settle'=>1]))!==1) throw new \RuntimeException('认领失败');
            $row['_settlement_claim']=true;
            $r=$channel['plugin']==='alipayd' ? \lib\Payment::alipaydSettle($channel,$row) : \lib\Payment::wxpaynpSettle($channel,$row);
            if($r===false || $r===null) throw new \RuntimeException('上游未确认');
            if(\lib\Finance::checked($DB->update('order',['settle'=>2],['trade_no'=>$trade_no,'status'=>1,'settle'=>4]))!==1) throw new \RuntimeException('终态未保存');
            $reply=['code'=>0,'msg'=>'结算成功'];
        }
    } catch(\Throwable $e) {} finally {
        if($got) { try { \lib\Finance::checked($DB->query('SELECT RELEASE_LOCK(:name)',[':name'=>$lock])); } catch(\Throwable $e) { $reply=['code'=>-2,'msg'=>'结算锁释放未确认，请核对原交易']; } }
    }
    exit(json_encode($reply));

break;
case 'alipayPreAuthPay': //支付宝授权资金支付
	$trade_no=trim($_POST['trade_no']);
	if(!preg_match('/^[a-zA-Z0-9_.:-]{1,64}$/', $trade_no)) exit('{"code":-1,"msg":"订单号不合法"}');
	$order=$DB->getRow("select * from pre_order where trade_no=:b43 limit 1", [':b43'=>"$trade_no"]);
	if(!$order)
		exit('{"code":-1,"msg":"当前订单不存在！"}');
	$channel = $order['subchannel'] > 0 ? \lib\Channel::getSub($order['subchannel']) : \lib\Channel::get($order['channel'], $DB->findColumn('user', 'channelinfo', ['uid'=>$row['uid']]));
	if(!$channel){
		exit('{"code":-1,"msg":"当前支付通道信息不存在"}');
	}
	try{
		$result = \lib\Payment::alipayPreAuthPay($channel, $order);

		$api_trade_no = $result['trade_no'];
		$buyer_id = $result['buyer_user_id'];
		$total_amount = $result['total_amount'];
		if(!epay_callback_money_match($total_amount, $order['realmoney'])) exit('{"code":-1,"msg":"授权资金支付金额校验失败"}');
		processNotify($order, $api_trade_no, $buyer_id);

		exit('{"code":0,"msg":"授权资金支付成功！"}');
	}catch(Exception $e){
		$errmsg = $e->getMessage();
		exit('{"code":-1,"msg":"授权资金支付失败,'.$errmsg.'"}');
	}
break;
case 'alipayUnfreeze': //支付宝授权资金解冻
	$trade_no=trim($_POST['trade_no']);
	if(!preg_match('/^[a-zA-Z0-9_.:-]{1,64}$/', $trade_no)) exit('{"code":-1,"msg":"订单号不合法"}');
	$order=$DB->getRow("select * from pre_order where trade_no=:b44 limit 1", [':b44'=>"$trade_no"]);
	if(!$order)
		exit('{"code":-1,"msg":"当前订单不存在！"}');
	$channel = $order['subchannel'] > 0 ? \lib\Channel::getSub($order['subchannel']) : \lib\Channel::get($order['channel'], $DB->findColumn('user', 'channelinfo', ['uid'=>$row['uid']]));
	if(!$channel){
		exit('{"code":-1,"msg":"当前支付通道信息不存在"}');
	}
	try{
		\lib\Payment::alipayUnfreeze($channel, $order);
		$DB->exec("update `pre_order` set `status`=0 where `trade_no`=:b45", [':b45'=>"$trade_no"]);
		exit('{"code":0,"msg":"授权资金解冻成功！"}');
	}catch(Exception $e){
		$errmsg = $e->getMessage();
		exit('{"code":-1,"msg":"授权资金解冻失败,'.$errmsg.'"}');
	}
break;
case 'alipayRedPacketTansfer': //支付宝红包转账重试
	$trade_no=trim($_POST['trade_no']);
	if(!preg_match('/^[a-zA-Z0-9_.:-]{1,64}$/', $trade_no)) exit('{"code":-1,"msg":"订单号不合法"}');
	$order=$DB->getRow("select * from pre_order where trade_no=:b46 limit 1", [':b46'=>"$trade_no"]);
	if(!$order)
		exit('{"code":-1,"msg":"当前订单不存在！"}');
	$channel = $order['subchannel'] > 0 ? \lib\Channel::getSub($order['subchannel']) : \lib\Channel::get($order['channel'], $DB->findColumn('user', 'channelinfo', ['uid'=>$row['uid']]));
	if(!$channel){
		exit('{"code":-1,"msg":"当前支付通道信息不存在"}');
	}
	if(!empty($channel['appmchid'])) $payee_user_id = $channel['appmchid'];
	else $payee_user_id = $DB->findColumn('user', 'alipay_uid', ['uid'=>$order['uid']]);
	if(!$payee_user_id) exit('{"code":-1,"msg":"当前商户未绑定支付宝账号"}');
	try{
		\lib\Payment::alipayRedPacketTransfer($channel, $payee_user_id, $order['money'], $order['api_trade_no']);
		$DB->exec("update `pre_order` set `settle`=2 where `trade_no`=:b47", [':b47'=>"$trade_no"]);
		exit('{"code":0,"msg":"红包打款成功！"}');
	}catch(Exception $e){
		$errmsg = $e->getMessage();
		exit('{"code":-1,"msg":"红包打款失败,'.$errmsg.'"}');
	}
break;
default:
	exit('{"code":-4,"msg":"No Act"}');
break;
}