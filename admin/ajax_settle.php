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
	if($value === '' || !preg_match('/^[a-zA-Z0-9_.:-]{1,128}$/', $value)) exit(json_encode(['code'=>-1, 'msg'=>''.$name.'不合法']));
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
case 'settleList':
    $sql=" 1=1"; $bindings=[];
    foreach(['batch','uid','type','dstatus','value','transfer_status','offset','limit'] as $key) sql_read_scalar($_POST, $key);
    if(!empty($_POST['batch'])) { $sql.=" AND `batch`=:batch"; $bindings[':batch']=$_POST['batch']; }
    foreach(['uid','type'] as $key){
        if(!empty($_POST[$key])) { $sql.=" AND `{$key}`=:{$key}"; $bindings[':'.$key]=(int)$_POST[$key]; }
    }
    if(isset($_POST['dstatus']) && $_POST['dstatus']>-1) { $sql.=" AND `status`=:status"; $bindings[':status']=(int)$_POST['dstatus']; }
    if(!empty($_POST['value'])) {
        $sql.=" AND (`account` LIKE :account_value OR `username` LIKE :username_value)";
        $bindings[':account_value']='%'.$_POST['value'].'%'; $bindings[':username_value']='%'.$_POST['value'].'%';
    }
    if(isset($_POST['transfer_status']) && in_array((string)$_POST['transfer_status'], ['3','4'], true)) { $sql.=' AND transfer_status=:transfer_status'; $bindings[':transfer_status']=(int)$_POST['transfer_status']; }
    $offset=sql_read_page($_POST,'offset',0,0,10000000);
    $limit=sql_read_page($_POST,'limit',20,1,500);
    $total=$DB->getColumn("SELECT count(*) FROM pre_settle WHERE{$sql}", $bindings);
    $list=$DB->getAll("SELECT * FROM pre_settle WHERE{$sql} ORDER BY id DESC LIMIT $offset,$limit", $bindings);
	$list2 = [];
	foreach($list as $row){
		if($row['type'] == 2 && $row['status'] == 1 && !empty($row['transfer_ext']) && time() - strtotime($row['transfer_date']) <= 86400){
			if(substr($row['transfer_ext'], 0, 4) == 'http'){
				$row['jumpurl'] = $row['transfer_ext'];
			}else{
				$row['jumpurl'] = $siteurl.'paypage/wxtrans.php?id='.$row['id'].'&type=settle';
			}
		}
		$list2[] = $row;
	}

	exit(json_encode(['total'=>$total, 'rows'=>$list2]));
break;

case 'create_batch':
	if($_SERVER['REQUEST_METHOD'] !== 'POST') exit('{"code":405,"msg":"Method Not Allowed"}');
	// Do not accept a CSRF token from the query string for this state-changing action.
	if(!isset($_POST['csrf_token']) || !is_string($_POST['csrf_token']) || !hash_equals(csrf_token('admin'), $_POST['csrf_token'])) exit('{"code":403,"msg":"CSRF TOKEN ERROR"}');
    try {
        $r=\lib\Finance::transaction(function() use($DB){
            $rows=\lib\Finance::checked($DB->query('SELECT * FROM pre_settle WHERE status=0 AND transfer_status=0 ORDER BY id FOR UPDATE'))->fetchAll(\PDO::FETCH_ASSOC);
            if(!$rows) throw new \RuntimeException('没有待结算记录');
            $batch=date('Ymd').bin2hex(random_bytes(5));$sum=0;
            foreach($rows as $row){
                $n=\lib\Finance::checked($DB->update('settle',['batch'=>$batch,'status'=>2],['id'=>$row['id'],'status'=>0]));
                if($n!==1) throw new \RuntimeException('结算认领失败');
                $sum+=\lib\Finance::cents($row['realmoney']);
            }
            \lib\Finance::checked($DB->insert('batch',['batch'=>$batch,'allmoney'=>\lib\Finance::amount($sum),'count'=>count($rows),'time'=>'NOW()','status'=>0]));
            return ['code'=>0,'msg'=>'succ','batch'=>$batch,'count'=>count($rows),'allmoney'=>\lib\Finance::amount($sum)];
        });exit(json_encode($r));
    }catch(\Throwable $e){exit(json_encode(['code'=>-1,'msg'=>'批次创建未完成']));}
break;
case 'complete_batch':
    if($_SERVER['REQUEST_METHOD'] !== 'POST') exit('{"code":405,"msg":"Method Not Allowed"}');
	$batch=admin_safe_token($_POST['batch'] ?? '', '批次号');
    try {\lib\Finance::transaction(function() use($DB,$batch){
        // Same row locks as settlePay: completion wins and prevents a new intent,
        // or the durable payment intent wins and completion must refuse it.
        $rows=\lib\Finance::checked($DB->query('SELECT * FROM pre_settle WHERE batch=:b ORDER BY id FOR UPDATE',[':b'=>$batch]))->fetchAll(\PDO::FETCH_ASSOC);
        $header=\lib\Finance::row('SELECT * FROM pre_batch WHERE batch=:b FOR UPDATE',[':b'=>$batch]);
        if(!$header || !$rows || (int)$header['count']!==count($rows))throw new \RuntimeException('批次不存在或成员不一致');
        foreach($rows as $row){
            $confirmed=(int)$row['status']===1 && (int)$row['transfer_status']===1;
            $manual=(int)$row['transfer_status']===0 && empty($row['transfer_no']) && in_array((int)$row['status'],[1,2],true);
            if(!$confirmed && !$manual)throw new \RuntimeException('批次有在途付款或待核对记录');
        }
        \lib\Finance::checked($DB->exec('UPDATE pre_settle SET status=1,endtime=NOW() WHERE batch=:b AND status=2 AND transfer_status=0 AND (transfer_no IS NULL OR transfer_no=\'\')',[':b'=>$batch]));
        \lib\Finance::checked($DB->update('batch',['status'=>1],['batch'=>$batch]));
    });exit(json_encode(['code'=>0,'msg'=>'succ']));}catch(\Throwable $e){exit(json_encode(['code'=>-2,'msg'=>'批次未完成：有在途付款、待核对记录或成员状态冲突，请查询原交易']));}
break;
case 'setSettleStatus':
	if($_SERVER['REQUEST_METHOD'] !== 'POST') exit('{"code":405,"msg":"Method Not Allowed"}');
	$id=intval($_POST['id']);
	$status=intval($_POST['status']);
    try {\lib\Transfer::settleStatus($id,$status);exit(json_encode(['code'=>200]));}
    catch(\Throwable $e){exit(json_encode(['code'=>400,'msg'=>'状态冲突或余额确认失败，请核对']));}
break;
case 'opslist':
    $status=intval($_POST['status']);$i=0;
    foreach((array)($_POST['checkbox']??[]) as $id){
        try {\lib\Transfer::settleStatus((int)$id,$status);$i++;}
        catch(\Throwable $e){exit(json_encode(['code'=>-2,'msg'=>'批量处理未完成，请核对','completed'=>$i]));}
    }
    exit(json_encode(['code'=>0,'msg'=>'成功改变'.$i.'条记录状态']));
break;
case 'settle_result':
	$id=intval($_POST['id']);
	$row=$DB->getRow("select result from pre_settle where id='$id' limit 1");
	if(!$row)
		exit('{"code":-1,"msg":"当前结算记录不存在！"}');
	$result = ['code'=>0,'msg'=>'succ','result'=>$row['result']];
	exit(json_encode($result));
break;
case 'settle_setresult':
	$id=intval($_POST['id']);
	$result=admin_safe_text($_POST['result'], 2000);
	$row=$DB->getRow("select * from pre_settle where id='$id' limit 1");
	if(!$row)
		exit('{"code":-1,"msg":"当前结算记录不存在！"}');
	$sds = $DB->exec("UPDATE pre_settle SET result='$result' WHERE id='$id'");
	if($sds!==false)
		exit('{"code":0,"msg":"修改成功！"}');
	else
		exit(json_encode(['code'=>-1, 'msg'=>'修改失败！'.$DB->error().'']));
break;
case 'settle_info':
	$id=intval($_GET['id']);
	$rows=$DB->getRow("select * from pre_settle where id='$id' limit 1");
	if(!$rows)
		exit('{"code":-1,"msg":"当前结算记录不存在！"}');
	$data = '<div class="form-group"><div class="input-group"><div class="input-group-addon">结算方式</div><select class="form-control" id="pay_type" default="'.intval($rows['type']).'">'.($conf['settle_alipay']?'<option value="1">支付宝</option>':null).''.($conf['settle_wxpay']?'<option value="2">微信</option>':null).''.($conf['settle_qqpay']?'<option value="3">QQ钱包</option>':null).''.($conf['settle_bank']?'<option value="4">银行卡</option>':null).'</select></div></div>';
	$data .= '<div class="form-group"><div class="input-group"><div class="input-group-addon">结算账号</div><input type="text" id="pay_account" value="'.htmlspecialchars($rows['account'], ENT_QUOTES, 'UTF-8').'" class="form-control" required/></div></div>';
	$data .= '<div class="form-group"><div class="input-group"><div class="input-group-addon">真实姓名</div><input type="text" id="pay_name" value="'.htmlspecialchars($rows['username'], ENT_QUOTES, 'UTF-8').'" class="form-control" required/></div></div>';
	$data .= '<input type="submit" id="save" onclick="saveInfo('.$id.')" class="btn btn-primary btn-block" value="保存">';
	$result=array("code"=>0,"msg"=>"succ","data"=>$data,"pay_type"=>$rows['type']);
	exit(json_encode($result));
break;
case 'settle_save':
	$id=intval($_POST['id']);
	$pay_type=intval($_POST['pay_type']);
	$pay_account=trim($_POST['pay_account']);
	$pay_name=trim($_POST['pay_name']);
	$data = ['type'=>$pay_type, 'account'=>$pay_account, 'username'=>$pay_name];
	if($DB->update('settle', $data, ['id'=>$id,'transfer_status'=>0,'status'=>0])===1)
		exit('{"code":0,"msg":"修改记录成功！"}');
	else
		exit(json_encode(['code'=>-1, 'msg'=>'修改记录失败！'.$DB->error().'']));
break;
case 'paypwd_check':
	if(isset($_SESSION['paypwd']) && $_SESSION['paypwd']==$conf['admin_paypwd'])
		exit('{"code":0,"msg":"ok"}');
	else
		exit('{"code":-1,"msg":"error"}');
break;
case 'paypwd_input':
	$paypwd=trim($_POST['paypwd']);
	if(!$conf['admin_paypwd'])exit('{"code":-1,"msg":"你还未设置支付密码"}');
	if($paypwd == $conf['admin_paypwd']){
		$_SESSION['paypwd'] = $paypwd;
		exit('{"code":0,"msg":"ok"}');
	}else{
		exit('{"code":-1,"msg":"支付密码错误！"}');
	}
break;
case 'paypwd_reset':
	unset($_SESSION['paypwd']);
	exit('{"code":0,"msg":"ok"}');
break;

case 'transfer':
	$id = isset($_POST['id'])?intval($_POST['id']):exit('{"code":-1,"msg":"ID不能为空"}');
	$type = isset($_POST['type'])?intval($_POST['type']):exit('{"code":-1,"msg":"type不能为空"}');
	$channelid = isset($_POST['channel'])?intval($_POST['channel']):0;

	if(!isset($_SESSION['paypwd']) || $_SESSION['paypwd']!==$conf['admin_paypwd'])exit('{"code":-1,"msg":"支付密码错误，请返回重新进入该页面"}');

	$row=$DB->getRow("SELECT * FROM pre_settle WHERE id='{$id}' limit 1");
	if(!$row)exit('{"code":-1,"msg":"记录不存在"}');
	if($row['type']!=$type)exit('{"code":-1,"msg":"转账类型不正确"}');

	if($row['transfer_status']==1 && $row['status']==1)exit(json_encode(['code'=>0, 'ret'=>2, 'result'=>'转账订单号:'.$row['transfer_result'].' 支付时间:'.$row['transfer_date'].'']));

	if($type == 1){
		$app = 'alipay';
	}elseif($type == 2){
		$app = 'wxpay';
	}elseif($type == 3){
		$app = 'qqpay';
	}elseif($type == 4){
		$app = 'bank';
	}
	$channel = \lib\Channel::get($channelid);
	if(!$channel)exit('{"code":-1,"msg":"当前支付通道信息不存在"}');

    $result=\lib\Transfer::settlePay($id,$app,$channel);
    if($result['code']==0) exit(json_encode(['code'=>0,'ret'=>$result['status']==2?0:1,'result'=>$result['status']==2?'付款失败':'已提交原付款，请查询结果']));
    exit(json_encode($result));
break;

default:
	exit('{"code":-4,"msg":"No Act"}');
break;
}