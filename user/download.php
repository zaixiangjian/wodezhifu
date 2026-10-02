<?php
include("../includes/common.php");

if($islogin2==1){}else exit("<script language='javascript'>window.location.href='./login.php';</script>");
// Only the explicitly disabled status is blocked; pending/unknown retain legacy policy.
if(isset($userrow['status']) && (string)$userrow['status'] === '0'){ http_response_code(403); exit('Forbidden'); }
if(!checkRefererHost()) exit('Forbidden');
csrf_check_page('user');

$act=isset($_GET['act'])?daddslashes($_GET['act']):null;

function display_status($status){
	if($status==1){
		return '已支付';
	}elseif($status==2){
		return '已退款';
	}elseif($status==3){
		return '已冻结';
	}else{
		return '未支付';
	}
}

function csv_text($value){
	$value = str_replace(["\r", "\n"], ' ', (string)$value);
	if(preg_match('/^[\x00-\x20]*[=+\-@]/', $value)) $value = "'".$value;
	$value = str_replace('"', '""', $value);
	return '"'.$value.'"';
}
function user_export_date($key){
	$value = isset($_GET[$key]) ? trim($_GET[$key]) : '';
	if($value !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) exit('param error');
	return $value;
}
function user_export_token($value, $max=128){
	$value = trim((string)$value);
	if($value === '') return '';
	if(strlen($value) > intval($max) || !preg_match('/^[a-zA-Z0-9_.:\-@]+$/', $value)) exit('param error');
	return $value;
}
function user_export_money($value){
	$value = trim((string)$value);
	if($value === '' || !preg_match('/^\d+(\.\d{1,2})?$/', $value)) exit('param error');
	return $value;
}
function user_export_text($value, $max=128){
	$value = trim(str_replace(["\r", "\n"], ' ', (string)$value));
	if($value === '') return '';
	if(mb_strlen($value) > intval($max)) exit('param error');
	return $value;
}
function display_psstatus($status){
	if($status==1){
		return '已提交';
	}elseif($status==2){
		return '分账成功';
	}elseif($status==3){
		return '分账失败';
	}elseif($status==4){
		return '已取消';
	}else{
		return '待分账';
	}
}

function user_export_scalar($value){
    if(!is_string($value) && !is_int($value)) exit('param error');
    return $value;
}

switch($act){

case 'order':
foreach(['paytype','channel','subchannel','dstatus','starttime','endtime','kw','type','rid'] as $key){
    if(isset($_GET[$key])) user_export_scalar($_GET[$key]);
}
$paytype = [];
$rs = $DB->getAll("SELECT * FROM pre_type");
foreach($rs as $row){
	$paytype[$row['id']] = $row['showname'];
}
unset($rs);

$bindings = [':owner_uid'=>(int)$uid];
$sql=" A.`uid`=:owner_uid";
if(isset($_GET['paytype']) && !empty($_GET['paytype'])) {
	$type = intval($_GET['paytype']);
	$sql.=" AND A.`type`=:filter_type"; $bindings[':filter_type']=$type;
}elseif(isset($_GET['channel']) && !empty($_GET['channel'])) {
	$channel = intval($_GET['channel']);
	$sql.=" AND A.`channel`=:filter_channel"; $bindings[':filter_channel']=$channel;
}elseif(isset($_GET['subchannel']) && !empty($_GET['subchannel'])) {
	$subchannel = trim($_GET['subchannel']);
	$subchannel = explode('|', $subchannel);
	$subchannel = array_map('intval', $subchannel);
    $marks=[];
    foreach($subchannel as $index=>$id){ $mark=':subchannel_'.$index; $marks[]=$mark; $bindings[$mark]=$id; }
    $sql.=" AND A.`subchannel` IN (".implode(',', $marks).")";
}
if(isset($_GET['dstatus']) && $_GET['dstatus']>-1) {
	$dstatus = intval($_GET['dstatus']);
	$sql.=" AND A.`status`=:filter_status"; $bindings[':filter_status']=$dstatus;
}
$starttime = user_export_date('starttime');
$endtime = user_export_date('endtime');
if($starttime !== ''){
	$sql.=" AND A.addtime>=:filter_starttime"; $bindings[':filter_starttime']=$starttime.' 00:00:00';
}
if($endtime !== ''){
	$sql.=" AND A.addtime<=:filter_endtime"; $bindings[':filter_endtime']=$endtime.' 23:59:59';
}
if(isset($_GET['kw']) && $_GET['kw'] !== '') {
	$search_type = isset($_GET['type']) ? intval($_GET['type']) : 0;
	if($search_type==1){ $kw=user_export_token($_GET['kw'],64); $sql.=" AND A.`trade_no`=:kw";
	}elseif($search_type==2){ $kw=user_export_token($_GET['kw'],128); $sql.=" AND A.`out_trade_no`=:kw";
	}elseif($search_type==3){ $kw=user_export_text($_GET['kw'],80); $sql.=" AND A.`name` like :kw";
	}elseif($search_type==4){ $kw=user_export_money($_GET['kw']); $sql.=" AND A.`money`=:kw";
	}elseif($search_type==5){ $kw=user_export_money($_GET['kw']); $sql.=" AND A.`realmoney`=:kw";
	}elseif($search_type==6){ $kw=user_export_text($_GET['kw'],128); $sql.=" AND A.`domain`=:kw";
	}elseif($search_type==7){ $kw=user_export_token($_GET['kw'],45); $sql.=" AND A.`ip`=:kw";
	}elseif($search_type==8){ $kw=user_export_text($_GET['kw'],128); $sql.=" AND A.`buyer`=:kw";
	}elseif($search_type==9){ $kw=user_export_token($_GET['kw'],128); $sql.=" AND A.`api_trade_no`=:kw";
	}elseif($search_type==10){ $kw=user_export_token($_GET['kw'],128); $sql.=" AND A.`bill_trade_no`=:kw";
	}elseif($search_type==11){ $kw=user_export_token($_GET['kw'],128); $sql.=" AND A.`bill_mch_trade_no`=:kw";
	}else{ exit('param error'); }
    $bindings[':kw'] = strpos($sql, 'like :kw') !== false ? '%'.$kw.'%' : $kw;
}

$file="系统订单号,商户订单号,接口订单号,商户ID,网站域名,商品名称,订单金额,实际支付,商户分成,支付方式,支付账号,支付IP,创建时间,完成时间,支付状态,已退款金额,退款时间\r\n";

$rs = $DB->query("SELECT A.*,B.plugin,C.apply_id submchid FROM pre_order A LEFT JOIN pre_channel B ON A.channel=B.id LEFT JOIN pre_subchannel C ON A.subchannel=C.id WHERE{$sql} order by trade_no desc limit 100000", $bindings);
while($row = $rs->fetch()){
	if($row['status']==2){
		$row['refundtime'] = $DB->findColumn('refundorder', 'addtime', ['trade_no'=>$row['trade_no']], 'refund_no DESC');
	}
	$file.=csv_text($row['trade_no']).','.csv_text($row['out_trade_no']).','.csv_text($row['api_trade_no']).','.csv_text($row['submchid']).','.csv_text($row['domain']).','.csv_text($row['name']).','.csv_text($row['money']).','.csv_text($row['realmoney']).','.csv_text($row['getmoney']).','.csv_text($paytype[$row['type']]).','.csv_text($row['buyer']).','.csv_text($row['ip']).','.csv_text($row['addtime']).','.csv_text($row['endtime']).','.csv_text(display_status($row['status'])).','.csv_text($row['status']==2?$row['refundmoney']:'').','.csv_text($row['refundtime'] ?? '')."\r\n";
}

$file = hex2bin('efbbbf').$file;
$file_name='order_'.($starttime?:'all').'_'.($endtime?:'all').'.csv';
$file_size=strlen($file);
header("Content-Description: File Transfer");
header("Content-Type: application/force-download");
header("Content-Length: {$file_size}");
header("Content-Disposition:attachment; filename={$file_name}");
echo $file;
break;

case 'complain':
foreach(['paytype','channel','subchannel','dstatus','starttime','endtime','kw','type','rid'] as $key){
    if(isset($_GET[$key])) user_export_scalar($_GET[$key]);
}
$paytype = [];
$rs = $DB->getAll("SELECT * FROM pre_type");
foreach($rs as $row){
	$paytype[$row['id']] = $row['showname'];
}
unset($rs);

$bindings = [':owner_uid'=>(int)$uid];
$sql=" A.uid=:owner_uid";
if(isset($_GET['paytype']) && !empty($_GET['paytype'])) {
	$paytypen = intval($_GET['paytype']);
	$sql.=" AND A.`paytype`=:filter_paytypen"; $bindings[':filter_paytypen']=$paytypen;
}elseif(isset($_GET['channel']) && !empty($_GET['channel'])) {
	$channel = intval($_GET['channel']);
	$sql.=" AND A.`channel`=:filter_channel"; $bindings[':filter_channel']=$channel;
}
if(isset($_GET['dstatus']) && $_GET['dstatus']>-1) {
	$dstatus = intval($_GET['dstatus']);
	$sql.=" AND A.`status`=:filter_status"; $bindings[':filter_status']=$dstatus;
}
$starttime = user_export_date('starttime');
$endtime = user_export_date('endtime');
if($starttime !== ''){
	$sql.=" AND A.addtime>=:filter_starttime"; $bindings[':filter_starttime']=$starttime.' 00:00:00';
}
if($endtime !== ''){
	$sql.=" AND A.addtime<=:filter_endtime"; $bindings[':filter_endtime']=$endtime.' 23:59:59';
}
if(isset($_GET['kw']) && $_GET['kw'] !== '') {
	$search_type = isset($_GET['type']) ? intval($_GET['type']) : 0;
	if($search_type==1){ $kw=user_export_token($_GET['kw'],64); $sql.=" AND A.`trade_no`=:kw";
	}elseif($search_type==2){ $kw=user_export_token($_GET['kw'],128); $sql.=" AND A.`thirdid`=:kw";
	}elseif($search_type==3){ $kw=user_export_text($_GET['kw'],32); $sql.=" AND A.`type`=:kw";
	}elseif($search_type==4){ $kw=user_export_text($_GET['kw'],128); $sql.=" AND A.`title` like :kw";
	}elseif($search_type==5){ $kw=user_export_text($_GET['kw'],128); $sql.=" AND A.`content` like :kw";
	}elseif($search_type==6){ $kw=user_export_token($_GET['kw'],32); $sql.=" AND A.`phone`=:kw";
	}else{ exit('param error'); }
    $bindings[':kw'] = strpos($sql, 'like :kw') !== false ? '%'.$kw.'%' : $kw;
}

$file="ID,支付方式,商户ID,关联订单号,商品名称,订单金额,问题类型,投诉原因,投诉详情,创建时间,最后更新时间,状态\r\n";

$rs = $DB->query("SELECT A.*,B.money,B.name ordername,C.apply_id submchid FROM pre_complain A LEFT JOIN pre_order B ON A.trade_no=B.trade_no LEFT JOIN pre_subchannel C ON A.subchannel=C.id WHERE{$sql} order by A.addtime desc limit 100000", $bindings);
while($row = $rs->fetch()){
	$file.=csv_text($row['id']).','.csv_text($paytype[$row['paytype']]).','.csv_text($row['submchid']).','.csv_text($row['trade_no']).','.csv_text($row['ordername']).','.csv_text($row['money']).','.csv_text($row['type']).','.csv_text($row['title']).','.csv_text($row['content']).','.csv_text($row['addtime']).','.csv_text($row['edittime']).','.csv_text(['0'=>'待处理','1'=>'处理中','2'=>'处理完成'][$row['status']])."\r\n";
}

$file = hex2bin('efbbbf').$file;
$file_name='complain_'.time().'.csv';
$file_size=strlen($file);
header("Content-Description: File Transfer");
header("Content-Type: application/force-download");
header("Content-Length: {$file_size}");
header("Content-Disposition:attachment; filename={$file_name}");
echo $file;
break;

case 'psorder':
foreach(['paytype','channel','subchannel','dstatus','starttime','endtime','kw','type','rid'] as $key){
    if(isset($_GET[$key])) user_export_scalar($_GET[$key]);
}
$paytype = [];
$rs = $DB->getAll("SELECT * FROM pre_type");
foreach($rs as $row){
	$paytype[$row['id']] = $row['showname'];
}
unset($rs);

$bindings = [':owner_uid'=>(int)$uid];
$sql=" rid in (select id from pre_psreceiver where uid=:owner_uid and subchannel>0)";
if(isset($_GET['rid']) && !empty($_GET['rid'])) {
	$rid = intval($_GET['rid']);
	$sql.=" AND A.`rid`=:filter_rid"; $bindings[':filter_rid']=$rid;
}
if(isset($_GET['dstatus']) && $_GET['dstatus']>-1) {
	$dstatus = intval($_GET['dstatus']);
	$sql.=" AND A.`status`=:filter_status"; $bindings[':filter_status']=$dstatus;
}
$starttime = user_export_date('starttime');
$endtime = user_export_date('endtime');
if($starttime !== ''){
	$sql.=" AND A.addtime>=:filter_starttime"; $bindings[':filter_starttime']=$starttime.' 00:00:00';
}
if($endtime !== ''){
	$sql.=" AND A.addtime<=:filter_endtime"; $bindings[':filter_endtime']=$endtime.' 23:59:59';
}
if(isset($_GET['kw']) && $_GET['kw'] !== '') {
	$search_type = isset($_GET['type']) ? intval($_GET['type']) : 0;
	if($search_type==1){ $kw=user_export_token($_GET['kw'],64); $sql.=" AND A.`trade_no`=:kw";
	}elseif($search_type==2){ $kw=user_export_token($_GET['kw'],128); $sql.=" AND A.`api_trade_no`=:kw";
	}elseif($search_type==3){ $kw=user_export_money($_GET['kw']); $sql.=" AND A.`money`=:kw";
	}else{ exit('param error'); }
    $bindings[':kw'] = strpos($sql, 'like :kw') !== false ? '%'.$kw.'%' : $kw;
}

$file="系统订单号,分账规则,支付方式,订单金额,分账金额,时间,分账状态\r\n";

$rs = $DB->query("SELECT A.*,C.id channelid,C.name channelname,C.type,D.realmoney ordermoney FROM pre_psorder A LEFT JOIN pre_psreceiver B ON A.rid=B.id LEFT JOIN pre_channel C ON B.channel=C.id LEFT JOIN pre_order D ON D.trade_no=A.trade_no WHERE{$sql} order by A.id desc limit 100000", $bindings);
while($row = $rs->fetch()){
	$file.=csv_text($row['trade_no']).','.csv_text($row['rid']).','.csv_text($paytype[$row['type']]).','.csv_text($row['ordermoney']).','.csv_text($row['money']).','.csv_text($row['addtime']).','.csv_text(display_psstatus($row['status']))."\r\n";
}

$file = hex2bin('efbbbf').$file;
$file_name='psorder_'.($starttime?:'all').'_'.($endtime?:'all').'.csv';
$file_size=strlen($file);
header("Content-Description: File Transfer");
header("Content-Type: application/force-download");
header("Content-Length: {$file_size}");
header("Content-Disposition:attachment; filename={$file_name}");
echo $file;
break;

case 'wximg':
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: private, no-store');
    exit('投诉图片下载暂不可用：缺少已审核的图片模型及媒体归属校验，请联系管理员');


default:
	exit('No Act');
break;
}