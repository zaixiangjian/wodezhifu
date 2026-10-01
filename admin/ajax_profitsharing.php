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


function sql_read_scalar($source, $key, $default=''){
    $value = $source[$key] ?? $default;
    if(!is_string($value) && !is_int($value)) exit('{"code":-1,"msg":"参数类型不合法"}');
    return $value;
}
function sql_read_page($source, $key, $default, $min, $max){
    $value = sql_read_scalar($source, $key, $default);
    if(!preg_match('/^(0|[1-9][0-9]*)$/D', (string)$value) || $value < $min || $value > $max) exit('{"code":-1,"msg":"分页参数不合法"}');
    return (int)$value;
}

switch($act){

case 'receiverList':
    $sql=" 1=1"; $bindings=[];
    foreach(['value','column','offset','limit'] as $key) sql_read_scalar($_POST, $key);
    if(!empty($_POST['value'])) {
        $column=admin_safe_column($_POST['column'] ?? '', ['id','uid','channel','subchannel','account','name','info','mode','status']);
        if($column=='info') {
            $sql.=" AND (A.`info` LIKE :info_value OR A.`account` LIKE :account_value)";
            $bindings[':info_value']='%'.$_POST['value'].'%'; $bindings[':account_value']='%'.$_POST['value'].'%';
        }else { $sql.=" AND A.`{$column}`=:value"; $bindings[':value']=$_POST['value']; }
    }
    $offset=sql_read_page($_POST,'offset',0,0,10000000);
    $limit=sql_read_page($_POST,'limit',20,1,500);
    $total=$DB->getColumn("SELECT count(*) FROM pre_psreceiver A WHERE{$sql}", $bindings);
    $list=$DB->getAll("SELECT A.*,B.name channelname,C.name subchannelname,C.apply_id FROM pre_psreceiver A LEFT JOIN pre_channel B ON A.channel=B.id LEFT JOIN pre_subchannel C ON A.subchannel=C.id WHERE{$sql} ORDER BY A.id DESC LIMIT $offset,$limit", $bindings);
	exit(json_encode(['total'=>$total, 'rows'=>$list]));
break;
case 'orderList':
	$paytype = [];
	$paytypes = [];
	$rs = $DB->getAll("SELECT * FROM pre_type");
	foreach($rs as $row){
		$paytype[$row['id']] = $row['showname'];
		$paytypes[$row['id']] = $row['name'];
	}
	unset($rs);

    $sql=" 1=1"; $bindings=[];
    foreach(['rid','dstatus','starttime','endtime','value','column','offset','limit'] as $key) sql_read_scalar($_POST, $key);
    if(!empty($_POST['rid'])) { $sql.=" AND A.`rid`=:rid"; $bindings[':rid']=(int)$_POST['rid']; }
    if(isset($_POST['dstatus']) && $_POST['dstatus']>-1) { $sql.=" AND A.`status`=:status"; $bindings[':status']=(int)$_POST['dstatus']; }
    if(!empty($_POST['starttime'])) { $sql.=" AND A.addtime>=:starttime"; $bindings[':starttime']=admin_safe_date($_POST['starttime']).' 00:00:00'; }
    if(!empty($_POST['endtime'])) { $sql.=" AND A.addtime<=:endtime"; $bindings[':endtime']=admin_safe_date($_POST['endtime']).' 23:59:59'; }
    if(!empty($_POST['value'])) {
        $column=admin_safe_column($_POST['column'] ?? '', ['id','rid','trade_no','api_trade_no','type','money','status','settle_no']);
        $sql.=" AND A.`{$column}`=:value"; $bindings[':value']=$_POST['value'];
    }
    $offset=sql_read_page($_POST,'offset',0,0,10000000);
    $limit=sql_read_page($_POST,'limit',20,1,500);
    $total=$DB->getColumn("SELECT count(*) FROM pre_psorder A LEFT JOIN pre_psreceiver B ON A.rid=B.id LEFT JOIN pre_channel C ON B.channel=C.id WHERE{$sql}", $bindings);
    $list=$DB->getAll("SELECT A.*,C.id channelid,C.name channelname,C.type,D.realmoney ordermoney FROM pre_psorder A LEFT JOIN pre_psreceiver B ON A.rid=B.id LEFT JOIN pre_channel C ON B.channel=C.id LEFT JOIN pre_order D ON D.trade_no=A.trade_no WHERE{$sql} ORDER BY A.id DESC LIMIT $offset,$limit", $bindings);
	$list2 = [];
	foreach($list as $row){
		$row['typename'] = $paytypes[$row['type']];
		$row['typeshowname'] = $paytype[$row['type']];
		$list2[] = $row;
	}

	exit(json_encode(['total'=>$total, 'rows'=>$list2]));
break;

case 'get_receiver':
	$id=intval($_GET['id']);
	$row=$DB->find('psreceiver', '*', ['id'=>$id]);
	if(!$row) exit('{"code":-1,"msg":"当前分账规则不存在！"}');
	$row['info'] = !empty($row['info']) ? json_decode($row['info'], true) : [['account'=>$row['account'], 'name'=>$row['name'], 'rate'=>$row['rate']]];
	exit(json_encode(['code'=>0, 'data'=>$row]));
break;

case 'add_receiver':
	$data = [
		'channel' => intval($_POST['channel']),
		'uid' => !empty($_POST['uid'])?intval($_POST['uid']):null,
		'subchannel' => !empty($_POST['subchannel']) ? intval($_POST['subchannel']) : null,
		'info' => trim($_POST['info']),
		'minmoney' => trim($_POST['minmoney']),
		'mode' => intval($_POST['mode']),
		'status' => 0,
		'addtime' => 'NOW()'
	];
	if(!$data['channel'] || !$data['info'])exit('{"code":-1,"msg":"必填项不能为空"}');
	if(!empty($data['uid']) && !$DB->find('user', 'uid', ['uid'=>$data['uid']]))exit('{"code":-1,"msg":"商户ID不存在"}');
	if(!\lib\Channel::get($data['channel']))exit('{"code":-1,"msg":"支付通道不存在"}');
	if(!strpos($data['rate'], '|') && $data['rate'] > 100) exit('{"code":-1,"msg":"分账比例不能大于100"}');
	if($data['uid'] > 0 && $data['subchannel'] > 0){
		$sql = "`uid`='{$data['uid']}' AND `subchannel`='{$data['subchannel']}'";
	}elseif($data['uid'] > 0){
		$sql = "`uid`='{$data['uid']}'";
	}else{
		$sql = "`uid` IS NULL";
	}
	$rows = $DB->getRow("SELECT * FROM `pre_psreceiver` WHERE `channel`='{$data['channel']}' AND {$sql}");
	if($rows)exit('{"code":-1,"msg":"该支付通道&UID已存在分账规则"}');
	if($DB->insert('psreceiver', $data)){
		exit('{"code":0,"msg":"新增分账规则成功！"}');
	}else{
		exit('{"code":-1,"msg":"新增分账规则失败['.$DB->error().']"}');
	}
break;

case 'edit_receiver':
	$id=intval($_POST['id']);
	$row=$DB->find('psreceiver', '*', ['id'=>$id]);
	if(!$row) exit('{"code":-1,"msg":"当前分账规则不存在！"}');
	$data = [
		'channel' => intval($_POST['channel']),
		'uid' => !empty($_POST['uid'])?intval($_POST['uid']):null,
		'subchannel' => !empty($_POST['subchannel']) ? intval($_POST['subchannel']) : null,
		'info' => trim($_POST['info']),
		'minmoney' => trim($_POST['minmoney']),
		'mode' => intval($_POST['mode']),
	];
	if(!$data['channel'] || !$data['info'])exit('{"code":-1,"msg":"必填项不能为空"}');
	if(!empty($data['uid']) && !$DB->find('user', 'uid', ['uid'=>$data['uid']]))exit('{"code":-1,"msg":"商户ID不存在"}');
	if(!\lib\Channel::get($data['channel']))exit('{"code":-1,"msg":"支付通道不存在"}');
	if(!strpos($data['rate'], '|') && $data['rate'] > 100) exit('{"code":-1,"msg":"分账比例不能大于100"}');
	if($data['uid'] > 0 && $data['subchannel'] > 0){
		$sql = "`uid`='{$data['uid']}' AND `subchannel`='{$data['subchannel']}'";
	}elseif($data['uid'] > 0){
		$sql = "`uid`='{$data['uid']}'";
	}else{
		$sql = "`uid` IS NULL";
	}
	$rows = $DB->getRow("SELECT * FROM `pre_psreceiver` WHERE `channel`='{$data['channel']}' AND {$sql} AND id!='$id'");
	if($rows)exit('{"code":-1,"msg":"该支付通道&UID已存在分账规则"}');
	if($row['status']==1 && $data['channel'] != $row['channel']){
		exit('{"code":-1,"msg":"请先将状态改为已关闭再切换通道"}');
	}
	if($row['status']==1 && $data['info']!=$row['info']){
		$channel = $row['subchannel'] > 0 ? \lib\Channel::getSub($row['subchannel']) : \lib\Channel::get($row['channel'], $row['uid']?$DB->findColumn('user', 'channelinfo', ['uid'=>$row['uid']]):null);
		if($channel){
			$new_info = json_decode($data['info'], true);
			$old_info = !empty($row['info']) ? json_decode($row['info'], true) : [['account'=>$row['account'], 'name'=>$row['name'], 'rate'=>$row['rate']]];
			
			$model = \lib\ProfitSharing\CommUtil::getModel($channel);
			foreach($new_info as $item){
				if(!array_filter($old_info, function($v) use ($item) {
					return $v['account'] == $item['account'];
				})){
					$result = $model->addReceiver($item['account'], $item['name']);
					if($result['code'] != 0) exit(json_encode($result));
				}
			}
		}
	}
	if($DB->update('psreceiver', $data, ['id'=>$id])!==false){
		exit('{"code":0,"msg":"修改分账规则成功！"}');
	}else{
		exit('{"code":-1,"msg":"修改分账规则失败['.$DB->error().']"}');
	}
break;

case 'set_receiver':
	$id=intval($_POST['id']);
	$status=intval($_POST['status']);
	$row=$DB->find('psreceiver', '*', ['id'=>$id]);
	if(!$row) exit('{"code":-1,"msg":"当前分账规则不存在！"}');
	$channel = $row['subchannel'] > 0 ? \lib\Channel::getSub($row['subchannel']) : \lib\Channel::get($row['channel'], $row['uid']?$DB->findColumn('user', 'channelinfo', ['uid'=>$row['uid']]):null);
	if(!$channel) exit('{"code":-1,"msg":"当前支付通道不存在！"}');
	$model = \lib\ProfitSharing\CommUtil::getModel($channel);
	$row['info'] = !empty($row['info']) ? json_decode($row['info'], true) : [['account'=>$row['account'], 'name'=>$row['name'], 'rate'=>$row['rate']]];
	foreach($row['info'] as $item){
		if($status == 1){
			$result = $model->addReceiver($item['account'], $item['name']);
			if($result['code'] != 0) exit(json_encode($result));
		}elseif($status == 0){
			$result = $model->deleteReceiver($item['account'], $item['name']);
		}
	}
	$DB->update('psreceiver', ['status'=>$status], ['id'=>$id]);
	exit('{"code":0,"msg":"状态修改成功！"}');
break;

case 'del_receiver':
	$id=intval($_POST['id']);
	$row=$DB->find('psreceiver', '*', ['id'=>$id]);
	if(!$row) exit('{"code":-1,"msg":"当前分账规则不存在！"}');
	if($DB->delete('psreceiver', ['id'=>$id])){
		exit('{"code":0,"msg":"删除分账规则成功！"}');
	}else{
		exit('{"code":-1,"msg":"删除分账规则失败['.$DB->error().']"}');
	}
break;


case 'submit':
    exit(json_encode(\lib\ProfitSharing\CommUtil::operate(intval($_POST['id']),'submit')));
break;
case 'query':
    exit(json_encode(\lib\ProfitSharing\CommUtil::operate(intval($_POST['id']),'query')));
break;
case 'unfreeeze':
    exit(json_encode(\lib\ProfitSharing\CommUtil::reversal(intval($_POST['id']),'unfreeeze')));
break;
case 'return':
    exit(json_encode(\lib\ProfitSharing\CommUtil::reversal(intval($_POST['id']),'return')));
break;
case 'amount':
	$id=intval($_POST['id']);
	$row = $DB->getRow("SELECT A.*,B.channel,B.uid psuid,C.uid,C.subchannel FROM pre_psorder A LEFT JOIN pre_psreceiver B ON A.rid=B.id LEFT JOIN pre_order C ON C.trade_no=A.trade_no WHERE A.id=:id", [':id'=>$id]);
	if(!$row)exit('{"code":-1,"msg":"订单不存在"}');
	$channel = $row['subchannel'] > 0 ? \lib\Channel::getSub($row['subchannel']) : \lib\Channel::get($row['channel'], $row['uid']?$DB->findColumn('user', 'channelinfo', ['uid'=>$row['uid']]):null);
	if(!$channel) exit('{"code":-1,"msg":"通道信息不存在"}');
	$model = \lib\ProfitSharing\CommUtil::getModel($channel);
	if(!empty($row['sub_trade_no'])) $row['trade_no'] = $row['sub_trade_no'];
	$result = $model->amount($row['trade_no'], $row['api_trade_no']);
	exit(json_encode($result));
break;

case 'editmoney':
	$id=intval($_POST['id']);
	$money=trim($_POST['money']);
	if(!preg_match('/^\\d{1,8}(?:\\.\\d{1,2})?$/D',$money) || \lib\Finance::cents($money)<=0)exit('{"code":-1,"msg":"金额输入错误"}');
    try {
        \lib\Finance::transaction(function() use($DB,$id,$money){
            $row=\lib\Finance::row('SELECT * FROM pre_psorder WHERE id=:id FOR UPDATE',[':id'=>$id]);
            if(!$row || (int)$row['status']!==0)throw new \RuntimeException('分账已认领，金额不可更改');
            if($DB->find('funds_snapshot','event_key',['event_key'=>'ps:'.$id]))throw new \RuntimeException('分账已有不可变快照');
            \lib\Finance::checked($DB->update('psorder',['money'=>$money],['id'=>$id,'status'=>0]));
        });exit(json_encode(['code'=>0,'msg'=>'succ']));
    }catch(\Throwable $e){exit(json_encode(['code'=>-2,'msg'=>'金额未修改，原分账已认领或需核对']));}
break;

case 'operation': //批量操作订单
    $status=intval($_POST['status']);$i=0;
    foreach((array)($_POST['checkbox']??[]) as $id){
        $id=(int)$id;
        if($status==5){
            // Archive, never erase the external intent or enable a second submission.
            $n=\lib\Finance::checked($DB->exec("UPDATE pre_psorder SET result='已归档（保留资金凭证）' WHERE id=:id AND status IN (2,3,4)",[':id'=>$id]));
            $i+=$n;continue;
        }
        if($status==4) $r=\lib\ProfitSharing\CommUtil::reversal($id,'unfreeeze');
        elseif($status==1 || $status==2) $r=\lib\ProfitSharing\CommUtil::operate($id,$status==1?'submit':'query');
        else $r=['code'=>-2,'msg'=>'原资金交易不能直接重置，请先核对供应商终态'];
        if(($r['code']??-1)<0)exit(json_encode(['code'=>-2,'msg'=>$r['msg']??'批量处理未完成','completed'=>$i]));
        $i++;
    }
    exit(json_encode(['code'=>0,'msg'=>'已处理'.$i.'条资金记录']));
break;

case 'statistics':
    $sql=" 1=1"; $bindings=[];
    foreach(['rid','dstatus','starttime','endtime','value','column','offset','limit'] as $key) sql_read_scalar($_POST, $key);
    if(!empty($_POST['rid'])) { $sql.=" AND A.`rid`=:rid"; $bindings[':rid']=(int)$_POST['rid']; }
    if(isset($_POST['dstatus']) && $_POST['dstatus']>-1) { $sql.=" AND A.`status`=:status"; $bindings[':status']=(int)$_POST['dstatus']; }
    if(!empty($_POST['starttime'])) { $sql.=" AND A.addtime>=:starttime"; $bindings[':starttime']=admin_safe_date($_POST['starttime']).' 00:00:00'; }
    if(!empty($_POST['endtime'])) { $sql.=" AND A.addtime<=:endtime"; $bindings[':endtime']=admin_safe_date($_POST['endtime']).' 23:59:59'; }
    if(!empty($_POST['value'])) {
        $column=admin_safe_column($_POST['column'] ?? '', ['id','rid','trade_no','api_trade_no','type','money','status','settle_no']);
        $sql.=" AND A.`{$column}`=:value"; $bindings[':value']=$column=='money' ? (float)$_POST['value'] : $_POST['value'];
    }

    $result = $DB->getRow("SELECT 
        SUM(money) AS totalMoney,
        SUM(CASE WHEN status = 2 THEN money ELSE 0 END) AS successMoney,
        SUM(CASE WHEN status = 3 THEN money ELSE 0 END) AS failMoney,
        COUNT(*) AS totalCount,
        SUM(CASE WHEN status = 2 THEN 1 ELSE 0 END) AS successCount,
        SUM(CASE WHEN status = 3 THEN 1 ELSE 0 END) AS failCount
        FROM pre_psorder A WHERE {$sql}", $bindings);

    $successRate = $result['totalCount'] > 0 ? round(($result['successCount'] / $result['totalCount']) * 100, 2) : 0;

    $data = [
        'totalMoney' => number_format($result['totalMoney'] ?? 0, 2, '.', ''),
        'successMoney' => number_format($result['successMoney'] ?? 0, 2, '.', ''),
        'failMoney' => number_format($result['failMoney'] ?? 0, 2, '.', ''),
        'totalCount' => $result['totalCount'] ?? 0,
        'successCount' => $result['successCount'] ?? 0,
        'failCount' => $result['failCount'] ?? 0,
        'successRate' => $successRate
    ];
    
    exit(json_encode(['code' => 0, 'data' => $data]));
break;

default:
	exit('{"code":-4,"msg":"No Act"}');
break;
}