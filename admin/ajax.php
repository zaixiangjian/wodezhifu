<?php
include("../includes/common.php");
if($islogin==1){}else exit("<script language='javascript'>window.location.href='./login.php';</script>");
$act=isset($_GET['act'])?daddslashes($_GET['act']):null;

if(!checkRefererHost()){ http_response_code(403); exit(json_encode(['code'=>403,'msg'=>'请求来源验证失败'])); }
csrf_check_json('admin');

@header('Content-Type: application/json; charset=UTF-8');

function article_image_png($tmp, $destination){
    $size = @filesize($tmp);
    if($size === false || $size < 1 || $size > 2*1024*1024) throw new RuntimeException('图片不能超过2MB');
    $info = @getimagesize($tmp);
    if(!$info || !in_array($info['mime'], ['image/png','image/jpeg','image/gif','image/webp'], true)) throw new RuntimeException('只允许上传 PNG/JPG/GIF/WEBP 图片');
    if($info[0] < 1 || $info[1] < 1 || $info[0] > 4096 || $info[1] > 4096 || $info[0]*$info[1] > 8000000) throw new RuntimeException('图片尺寸过大');
    if(!function_exists('imagecreatefromstring') || !function_exists('imagepng')) throw new RuntimeException('服务器缺少 GD 图片处理扩展，无法安全上传');
    $siteRoot = realpath(ROOT);
    $assetRoot = realpath(ROOT.'assets');
    $parent = realpath(dirname($destination));
    if($siteRoot === false || $assetRoot === false || strpos($assetRoot, $siteRoot.DIRECTORY_SEPARATOR) !== 0 || $parent === false || strpos($parent, $assetRoot.DIRECTORY_SEPARATOR) !== 0 || is_link($destination)) throw new RuntimeException('图片保存路径不安全');
    $image = @imagecreatefromstring(file_get_contents($tmp));
    if(!$image) throw new RuntimeException('图片解码失败');
    imagealphablending($image, false);
    imagesavealpha($image, true);
    $stage = tempnam($parent, '.article-');
    if($stage === false){ imagedestroy($image); throw new RuntimeException('图片保存失败'); }
    try {
        if(!imagepng($image, $stage) || !rename($stage, $destination)) throw new RuntimeException('图片保存失败');
        @chmod($destination, 0644);
    } finally { imagedestroy($image); if(is_file($stage)) unlink($stage); }
}

switch($act){
case 'getcount':
	if(($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'){
		http_response_code(405);
		header('Allow: POST');
		exit(json_encode(['code'=>405, 'msg'=>'统计刷新仅接受 POST 请求']));
	}
	$thtime=date("Y-m-d").' 00:00:00';
	$count1=$DB->getColumn("SELECT count(*) from pre_order");
	$count2=$DB->getColumn("SELECT count(*) from pre_user");
	$plugincount=$DB->getColumn("SELECT count(*) from pre_plugin");
	if($plugincount<1){
		\lib\Plugin::updateAll();
	}
	$isConvert = $DB->getRow("SELECT * FROM pre_channel WHERE status=1 AND config IS NULL LIMIT 1");
	if($isConvert){
		convert_channel_data();
		\lib\Plugin::updateAll();
	}

	$orderrow=$DB->getRow("SELECT COUNT(*) allnum,COUNT(IF(status>0, 1, NULL)) sucnum FROM pre_order WHERE addtime>='$thtime'");
	$success_rate = 100;
	if($orderrow){
		if($orderrow['allnum'] > 0){
			$success_rate = round($orderrow['sucnum']/$orderrow['allnum']*100,2);
		}
	}

	$paytype = [];
	$rs = $DB->getAll("SELECT id,name,showname FROM pre_type WHERE status=1");
	foreach($rs as $row){
		$paytype[$row['id']] = $row['showname'];
	}
	unset($rs);

	$channel = [];
	$rs = $DB->getAll("SELECT id,name FROM pre_channel WHERE status=1");
	foreach($rs as $row){
		$channel[$row['id']] = $row['name'];
	}
	unset($rs);

	$tongji_cachetime=getSetting('tongji_cachetime', true);
	$tongji_cache = $CACHE->read('tongji');
	if($tongji_cachetime+3600>=time() && $tongji_cache && !isset($_GET['getnew'])){
		$array = @unserialize($tongji_cache, ['allowed_classes'=>false]) ?: [];
		$result=["code"=>0,"type"=>"cache","paytype"=>$paytype,"channel"=>$channel,"count1"=>$count1,"count2"=>$count2,"usermoney"=>round($array['usermoney'],2),"settlemoney"=>round($array['settlemoney'],2),"success_rate"=>$success_rate,"order_today"=>$array['order_today'],"order"=>[]];
	}else{
		$usermoney=$DB->getColumn("SELECT SUM(money) FROM pre_user WHERE money!='0.00'");
		$settlemoney=$DB->getColumn("SELECT SUM(money) FROM pre_settle");

		$today=date("Y-m-d");
		$rs=$DB->query("SELECT type,channel,realmoney,profitmoney from pre_order where (status=1 OR status=3) and date>='$today'");
		foreach($paytype as $id=>$type){
			$order_paytype[$id]=0;
			$profit_paytype[$id]=0;
		}
		foreach($channel as $id=>$type){
			$order_channel[$id]=0;
		}
		while($row = $rs->fetch())
		{
			$order_paytype[$row['type']]+=$row['realmoney'];
			$order_channel[$row['channel']]+=$row['realmoney'];
			if(!empty($row['profitmoney'])){
				$profit_paytype[$row['type']]+=$row['profitmoney'];
			}
		}
		foreach($order_paytype as $k=>$v){
			$order_paytype[$k] = round($v,2);
		}
		foreach($order_channel as $k=>$v){
			$order_channel[$k] = round($v,2);
		}
		foreach($profit_paytype as $k=>$v){
			$profit_paytype[$k] = round($v,2);
		}
		$allmoney=0;
		foreach($order_paytype as $order){
			$allmoney+=$order;
		}
		$allprofit=0;
		foreach($profit_paytype as $money){
			$allprofit+=$money;
		}
	
		$order_today['all']=round($allmoney,2);
		$order_today['profit_all']=round($allprofit,2);
		$order_today['paytype']=$order_paytype;
		$order_today['channel']=$order_channel;
		$order_today['profit_paytype']=$profit_paytype;

		saveSetting('tongji_cachetime',time());
		$CACHE->save('tongji',serialize(["usermoney"=>$usermoney,"settlemoney"=>$settlemoney,"order_today"=>$order_today]));

		$result=["code"=>0,"type"=>"online","paytype"=>$paytype,"channel"=>$channel,"count1"=>$count1,"count2"=>$count2,"usermoney"=>round($usermoney,2),"settlemoney"=>round($settlemoney,2),"success_rate"=>$success_rate,"order_today"=>$order_today,"order"=>[]];
	}
	for($i=1;$i<7;$i++){
		$day = date("Ymd", strtotime("-{$i} day"));
		if($order_tongji = $CACHE->read('order_'.$day)){
			$result["order"][$day] = @unserialize($order_tongji, ['allowed_classes'=>false]) ?: [];
		}else{
			break;
		}
	}
	exit(json_encode($result));
break;

case 'set':
	if(($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'){
		header('Allow: POST'); http_response_code(405);
		exit(json_encode(['code'=>405, 'msg'=>'设置仅接受 POST 请求']));
	}
	// Explicit inventory of generic settings forms, template switch and group-buy toggle.
	// Authentication credentials/factors are changed only through dedicated proof flows.
	$allowed = explode(' ', 'alicombine_open alipay_aes_key alipay_getmobile alipay_mini_login alipay_paymode alipay_qrcode_url alipay_qrpaylogin alipay_satf alipay_satf_fee_account alipay_satf_fee_type alipay_settle_check alipay_settle_notice alipay_wappaylogin alipay_web_login alipay_web_login_all apiurl applyments_open appurl auto_check_channel auto_check_complain auto_check_notify auto_check_payip auto_check_payspeed auto_check_sucrate auto_settle_money black_payact blockalert blockname captcha_id captcha_key captcha_open_login captcha_open_test captcha_version cdnpublic cert_aliyunid cert_aliyunkey cert_aliyunsceneid cert_antiid cert_antikey cert_antisceneid cert_appcode cert_appcode2 cert_channel cert_corpopen cert_force cert_money cert_open cert_qcloudid cert_qcloudkey check_channel_failcount check_channel_ids check_channel_notice check_channel_second check_complain_notice check_complain_rate check_notify_count check_notify_notice check_pay_regoin check_payip_count check_payip_second check_paymsg check_paymsg_notice check_paymsg_retry check_payspeed_count check_payspeed_days check_payspeed_second check_sucrate_count check_sucrate_notice check_sucrate_second check_sucrate_value close_keylogin complain_auto_black complain_auto_ipblack complain_auto_refund complain_auto_refund_money complain_auto_reply complain_auto_reply_con complain_auto_reply_repeat complain_freeze_order complain_open complain_range cronkey description direct_settle_time email footer forceqq group_buy site_redirect_mode site_redirect_302_url site_redirect_301_url site_redirect_query_policy site_redirect_query_allowlist homepage homepage_301_url homepage_302_url homepage_url invite_apply_rate invite_groupbuy_rate invite_mode invite_open invite_order_fee invite_order_type invite_rate ip_type keywords kfqq localurl localurl_alipay localurl_wxpay login_alipay login_apiurl login_appid login_appkey login_qq login_qq_appid login_qq_appkey login_wx login_wxa mail_apikey mail_apiuser mail_cloud mail_name mail_name2 mail_port mail_pwd mail_recv mail_smtp mailnotice mchrisk_open modal msgconfig_apply msgconfig_balance msgconfig_complain msgconfig_complain_all msgconfig_domain msgconfig_group msgconfig_mchrisk msgconfig_mchrisk_all msgconfig_order msgconfig_regaudit msgconfig_risk msgconfig_settle msgrobot_phone msgrobot_url notifyordername ocr_aliyunid ocr_aliyunkey ocr_baiduid ocr_baidukey ocr_type onecode ordername orderprint orgname pageordername pay_daymoney pay_domain_forbid pay_domain_open pay_iplimit pay_iplimit_white pay_maxmoney pay_minmoney pay_payaddmax pay_payaddmin pay_payaddstart pay_region_block pay_userlimit pay_verify pay_verify_check_count pay_verify_check_ip pay_verify_check_rate pay_verify_check_second pay_verify_check_uid pay_verify_type payfee_lessthan payfee_mincost print_appid print_appsecret profits_desc profits_failretry proxy proxy_apikey proxy_apiurl proxy_port proxy_pwd proxy_server proxy_type proxy_user qqqun recharge refund_fee_type reg_input_settle reg_open reg_pay reg_pay_price reg_pay_uid robotnotice settle_alipay settle_bank settle_fee_max settle_fee_min settle_maxlimit settle_money settle_open settle_qqpay settle_rate settle_transfer settle_transfermax settle_type settle_wxpay sitename sms_api sms_appid sms_appkey sms_sign sms_tpl_balance sms_tpl_complain sms_tpl_edit sms_tpl_find sms_tpl_group sms_tpl_reg template test_open test_pay_uid title transfer_alipay transfer_alipay_info_content transfer_alipay_info_type transfer_alipay_scene_name transfer_bank transfer_desc transfer_maxlimit transfer_maxmoney transfer_minmoney transfer_name transfer_qqpay transfer_rate transfer_wxpay transfer_wxpay_info_content transfer_wxpay_info_type transfer_wxpay_scene_id transfer_wxpay_type user_deposit user_deposit_day user_deposit_min user_profitsharing user_refund user_review user_settings_edit user_style user_transfer user_transfer_red verifytype voice_apikey voice_username voicenotice wework_aeskey wework_contact wework_paykfid wework_paymsgmode wework_payopen wework_remark wework_token wx_open_url wxappkf_aeskey wxappkf_applet wxappkf_msg_transfer wxappkf_payopen wxappkf_token wxcombine_minmoney wxcombine_open wxcombine_submoney wxminipay_path wxnotice wxnotice_tpl_balance wxnotice_tpl_balance_money wxnotice_tpl_balance_msg wxnotice_tpl_balance_time wxnotice_tpl_balance_user wxnotice_tpl_complain wxnotice_tpl_complain_name wxnotice_tpl_complain_order_no wxnotice_tpl_complain_reason wxnotice_tpl_complain_time wxnotice_tpl_complain_type wxnotice_tpl_login wxnotice_tpl_login_ip wxnotice_tpl_login_iploc wxnotice_tpl_login_name wxnotice_tpl_login_time wxnotice_tpl_login_user wxnotice_tpl_order wxnotice_tpl_order_money wxnotice_tpl_order_name wxnotice_tpl_order_no wxnotice_tpl_order_outno wxnotice_tpl_order_time wxnotice_tpl_settle wxnotice_tpl_settle_account wxnotice_tpl_settle_money wxnotice_tpl_settle_realmoney wxnotice_tpl_settle_time wxnotice_tpl_settle_type wxpay_qrcode_url wxpay_qrpaylogin wxpay_web_login zhuce');
	$settings = $_POST;
	unset($settings['csrf_token']); // Transport metadata, never persist as configuration.
	if(!$settings) exit(json_encode(['code'=>-1, 'msg'=>'设置不能为空']));
	foreach($settings as $key=>$value){
		if(!in_array($key, $allowed, true) || !is_string($value))
			exit(json_encode(['code'=>-1, 'msg'=>'未知或非法设置项']));
	}

	if(isset($_POST['localurl'])){
		if(!empty($_POST['localurl']) && (substr($_POST['localurl'],0,4)!='http' || substr($_POST['localurl'],-1)!='/'))exit('{"code":-1,"msg":"回调专用网址格式错误"}');
	}
	if(isset($_POST['apiurl'])){
		if(!empty($_POST['apiurl']) && (substr($_POST['apiurl'],0,4)!='http' || substr($_POST['apiurl'],-1)!='/'))exit('{"code":-1,"msg":"用户对接网址格式错误"}');
	}
	if(isset($_POST['login_apiurl'])){
		if(!empty($_POST['login_apiurl']) && (substr($_POST['login_apiurl'],0,4)!='http' || substr($_POST['login_apiurl'],-1)!='/'))exit('{"code":-1,"msg":"聚合登录API接口地址格式错误"}');
	}

	try {
        $redirectSettings = epay_site_redirect_validate_settings($settings, $conf, $_SERVER);
        if (isset($redirectSettings['site_redirect_query_allowlist'])) $_POST['site_redirect_query_allowlist'] = $redirectSettings['site_redirect_query_allowlist'];
    } catch (InvalidArgumentException $e) {
        exit(json_encode(['code'=>-1, 'msg'=>$e->getMessage()], JSON_UNESCAPED_UNICODE));
    }

	if(isset($_POST['homepage'])){
		$homepage = intval($_POST['homepage']);
		if(!in_array($homepage, [0,1,2,3,4], true))exit('{"code":-1,"msg":"首页显示模式错误"}');
	}
	foreach(['homepage_302_url'=>'302跳转网址URL','homepage_301_url'=>'301跳转网址URL'] as $urlkey=>$urlname){
		if(isset($_POST[$urlkey]) && $_POST[$urlkey] !== ''){
			$url = trim($_POST[$urlkey]);
			if(!preg_match('/^https?:\/\//i', $url))exit('{"code":-1,"msg":"'.$urlname.'必须以 http:// 或 https:// 开头"}');
			if(function_exists('epay_is_safe_outbound_url') && !epay_is_safe_outbound_url($url))exit('{"code":-1,"msg":"'.$urlname.'不安全，禁止填写内网/localhost/保留地址"}');
			$_POST[$urlkey] = $url;
		}
	}
	// Own the transaction: do not commit/roll back an ambient caller transaction.
	$ownsSettingsTransaction = false;
	try {
		if($DB->db->inTransaction()) throw new RuntimeException('已有设置事务');
		if($DB->beginConfigurationTransaction() !== true) throw new RuntimeException('开启设置事务失败');
		$ownsSettingsTransaction = true;
		foreach($settings as $k=>$v){
			if(saveSetting($k, $_POST[$k]) === false) throw new RuntimeException('保存设置失败');
		}
		if(isset($redirectSettings['site_redirect_source_host']) && saveSetting('site_redirect_source_host', $redirectSettings['site_redirect_source_host']) === false) throw new RuntimeException('保存本站域名失败');
		// Cache is a DB table on the same connection: invalidate atomically, not before/after commit.
		if($CACHE->clear() === false) throw new RuntimeException('清理设置缓存失败');
		if($DB->commit() !== true) throw new RuntimeException('提交设置事务失败');
		$ownsSettingsTransaction = false;
	} catch (Throwable $e) {
		if($ownsSettingsTransaction && $DB->db->inTransaction()) $DB->rollBack();
		exit(json_encode(['code'=>-1, 'msg'=>'保存设置失败']));
	}
	exit('{"code":0,"msg":"succ"}');
break;
case 'setGonggao':
	if($_SERVER['REQUEST_METHOD'] !== 'POST') exit('{"code":405,"msg":"Method Not Allowed"}');
	$id=intval($_POST['id']);
	$status=intval($_POST['status']);
	$sql = "UPDATE pre_anounce SET status='$status' WHERE id='$id'";
	if($DB->exec($sql))exit('{"code":0,"msg":"修改状态成功！"}');
	else exit('{"code":-1,"msg":"修改状态失败['.$DB->error().']"}');
break;
case 'delGonggao':
	if($_SERVER['REQUEST_METHOD'] !== 'POST') exit('{"code":405,"msg":"Method Not Allowed"}');
	$id=intval($_POST['id']);
	$sql = "DELETE FROM pre_anounce WHERE id='$id'";
	if($DB->exec($sql))exit('{"code":0,"msg":"删除公告成功！"}');
	else exit('{"code":-1,"msg":"删除公告失败['.$DB->error().']"}');
break;
case 'iptype':
	$result = [
	['name'=>'0_X_FORWARDED_FOR', 'ip'=>real_ip(0), 'city'=>get_ip_city(real_ip(0))],
	['name'=>'1_X_REAL_IP', 'ip'=>real_ip(1), 'city'=>get_ip_city(real_ip(1))],
	['name'=>'2_REMOTE_ADDR', 'ip'=>real_ip(2), 'city'=>get_ip_city(real_ip(2))]
	];
	exit(json_encode($result));
break;

case 'setArticle': //文章状态
	if($_SERVER['REQUEST_METHOD'] !== 'POST') exit('{"code":405,"msg":"Method Not Allowed"}');
	$id=intval($_POST['id']);
	$active=intval($_POST['active']);
	if(!in_array($active, [0,1], true)) exit('{"code":-1,"msg":"状态不合法"}');
	$DB->exec("update pre_article set active='$active' where id='{$id}'");
	exit('{"code":0,"msg":"succ"}');
break;
case 'article_upload':
	if(($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'){ http_response_code(405); exit(json_encode(['error'=>1,'message'=>'请使用POST上传'])); }
	$file=$_FILES['imgFile'] ?? null;
	if(!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name']))exit(json_encode(['error'=>1,'message'=>'上传文件不存在或上传失败']));
	$ext=strtolower(pathinfo(is_string($file['name'] ?? null)?$file['name']:'', PATHINFO_EXTENSION));
	$allow=['gif'=>'image/gif','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'];
	$info=@getimagesize($file['tmp_name']);
	if(!isset($allow[$ext]) || !$info || $allow[$ext]!==$info['mime'])exit(json_encode(['error'=>1,'message'=>'图片扩展名与允许的内容类型不匹配']));
	try {
		$filename=bin2hex(random_bytes(16)).'.png';
		article_image_png($file['tmp_name'],ROOT.'assets/img/article/'.$filename);
		exit(json_encode(['error'=>0,'url'=>'/assets/img/article/'.$filename]));
	}catch(RuntimeException $e){ exit(json_encode(['error'=>1,'message'=>$e->getMessage()])); }

break;

case 'testproxy':
	$conf['proxy_server'] = trim($_POST['proxy_server']);
	$conf['proxy_port'] = $_POST['proxy_port'];
	$conf['proxy_user'] = trim($_POST['proxy_user']);
	$conf['proxy_pwd'] = trim($_POST['proxy_pwd']);
	$conf['proxy_type'] = $_POST['proxy_type'];
	try{
		if(check_proxy('https://dl.amh.sh/ip.htm') !== true){ throw new Exception('代理检测未确认成功；当前安全策略禁止配置代理。'); }
	}catch(Exception $e){
		try{
			if(check_proxy('https://myip.ipip.net/') !== true){ throw new Exception('代理检测未确认成功；当前安全策略禁止配置代理。'); }
		}catch(Exception $e){
			exit(json_encode(['code'=>-1,'msg'=>$e->getMessage()], JSON_UNESCAPED_UNICODE));
		}
	}
	exit('{"code":0}');
break;

case 'generate_wxa_link':
	$wechatid = intval($_POST['channel']);
	$wxinfo = \lib\Channel::getWeixin($wechatid);
	if(!$wxinfo)exit('{"code":-1,"msg":"该微信小程序不存在"}');
	$path = 'pages/getopenid/getopenid';
	$query = 'url='.$siteurl.'&wechatid='.$wechatid;
	$wechat = new \lib\wechat\WechatAPI($wechatid);
	try{
		$url_link = $wechat->generate_link($path, $query, 3600);
	}catch(Exception $e){
		exit('{"code":-1,"msg":"'.$e->getMessage().'"}');
	}
	exit('{"code":0,"url":"'.$url_link.'"}');
break;
default:
	exit('{"code":-4,"msg":"No Act"}');
break;
}