<?php
/**
 * 微信登录
**/
$is_defend=true;
$nosession=true;
include("../includes/common.php");
if($conf['login_wx']==0)sysmsg("未开启微信快捷登录");

if(isset($_GET['sid'])){
	$sid = trim(daddslashes($_GET['sid']));
	if(!preg_match('/^[a-zA-Z0-9,-]{22,128}$/D',$sid))exit("Access Denied");
	session_id($sid);
}
session_start();
if(isset($_GET['sid']) && (!is_string($_GET['bridge'] ?? null) || empty($_SESSION['oauth_bridge']) || !hash_equals($_SESSION['oauth_bridge'], $_GET['bridge']))) exit('Access Denied');
if(empty($_SESSION['oauth_bridge'])) $_SESSION['oauth_bridge'] = bin2hex(random_bytes(32));
if(isset($_GET['unbind'])){ http_response_code(405); exit('Method Not Allowed'); }
if($islogin2==1 && !isset($_GET['code']) && !isset($_GET['act']) && isset($_GET['bind'])){
    $bindStart = $_SESSION['oauth_bind_start'] ?? null;
    if(!is_array($bindStart) || $bindStart['actor'] !== (string)$uid || $bindStart['provider'] !== 'wx' || $bindStart['expires'] < time()) exit('请从账户设置发起绑定');
}
if(!isset($_GET['sid']) && !isset($_GET['code']) && !isset($_GET['act'])) $_SESSION['wxlogin_intent'] = ['actor'=>epay_oauth_actor(), 'expires'=>time()+300];

if(isset($_GET['act']) && $_GET['act']=='login'){
	$verified = $_SESSION['wxlogin_verified'] ?? null;
	unset($_SESSION['wxlogin_verified']);
	if(is_array($verified) && $verified['expires'] >= time() && $verified['actor'] === epay_oauth_actor()){
		$openId = daddslashes($verified['id']);
		$userrow=$DB->getRow("SELECT * FROM pre_user WHERE wx_uid='{$openId}' LIMIT 1");
		if($userrow){
			$uid=$userrow['uid'];
			$key=$userrow['key'];
			if($islogin2==1){
				exit('{"code":-1,"msg":"当前微信已绑定商户ID:'.$uid.'，请勿重复绑定！"}');
			}
			$session=epay_user_session_digest($userrow);
			$expiretime=time()+2592000;
			$token=authcode("{$uid}\t{$session}\t{$expiretime}", 'ENCODE', SYS_KEY);
			epay_set_cookie("user_token", $token, time() + 2592000, "/user");
			$DB->exec("update `pre_user` set `lasttime`=NOW() where `uid`='$uid'");
			$result=array("code"=>0,"msg"=>"登录成功！正在跳转到用户中心","url"=>"./");
		}elseif($islogin2==1){
			$sds=$DB->exec("update `pre_user` set `wx_uid`='$openId' where `uid`='$uid'");
			$result=array("code"=>0,"msg"=>"已成功绑定微信账号！","url"=>"./editinfo.php");
		}else{
			$_SESSION['Oauth_wx_uid']=$openId;
			$result=array("code"=>0,"msg"=>"请输入商户ID和密钥完成绑定和登录","url"=>"./login.php?connect=true");
		}
		unset($_SESSION['openid']);
	}else{
		$result=array("code"=>1);
	}
	exit(json_encode($result));
}

if(!empty($conf['localurl_wxpay']) && !strpos($conf['localurl_wxpay'],$_SERVER['HTTP_HOST'])){
	$code_url = $conf['localurl_wxpay'].'user/wxlogin.php?sid='.session_id();
}else{
	$code_url = $siteurl.'user/wxlogin.php?sid='.session_id();
}
if(isset($_GET['bind'])){
	$code_url .= '&bind=1';
}
$code_url .= '&bridge='.rawurlencode($_SESSION['oauth_bridge']);

if($islogin2==1 && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['unbind'])){
	if(!checkRefererHost())exit();
	if(!isset($_POST['csrf_token'])) exit('CSRF TOKEN ERROR');
	csrf_check_page('user');
	$DB->exec("update `pre_user` set `wx_uid`=NULL where `uid`='$uid'");
	@header('Content-Type: text/html; charset=UTF-8');
	exit("<script language='javascript'>alert('您已成功解绑微信账号！');window.location.href='./editinfo.php';</script>");
}
elseif(strpos($_SERVER['HTTP_USER_AGENT'], 'MicroMessenger')!==false){

$redirect_url = $_GET['url'] ?? '';
if(!is_string($redirect_url) || !preg_match('/^[A-Za-z0-9_-]+\.php(?:\?[A-Za-z0-9_=&%.-]*)?$/D', $redirect_url)) $redirect_url = '';
$redirect_js = json_encode('./'.$redirect_url, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
if($islogin2==1 && !isset($_GET['bind']) && !isset($_GET['code'])){
	exit("<script>window.location.href={$redirect_js};</script>");
}

if($conf['login_wx']==0)sysmsg("未开启微信快捷登录");
$wxinfo = \lib\Channel::getWeixin($conf['login_wx']);
if(!$wxinfo)sysmsg("当前微信公众号不存在");

try{
	$openId = wechat_oauth($wxinfo);
}catch(Exception $e){
	sysmsg($e->getMessage());
}
$intent = $_SESSION['wxlogin_intent'] ?? ['actor'=>epay_oauth_actor(), 'expires'=>time()+300];
if($intent['expires'] < time()) exit('授权已过期');
$_SESSION['wxlogin_verified'] = ['id'=>$openId, 'actor'=>$intent['actor'], 'expires'=>time()+120];
if(isset($_GET['sid'])) exit('授权成功，请返回原页面');
unset($_SESSION['wxlogin_verified']);

	$userrow=$DB->getRow("SELECT * FROM pre_user WHERE wx_uid='{$openId}' limit 1");
	if($userrow){
		$uid=$userrow['uid'];
		$key=$userrow['key'];
		if($islogin2==1) exit('该微信已绑定商户，请勿重复绑定');
		$DB->insert('log', ['uid'=>$uid, 'type'=>'微信快捷登录', 'date'=>'NOW()', 'ip'=>$clientip, 'city'=>$city]);
		$session=epay_user_session_digest($userrow);
		$expiretime=time()+604800;
		$token=authcode("{$uid}\t{$session}\t{$expiretime}", 'ENCODE', SYS_KEY);
		epay_set_cookie("user_token", $token, time() + 604800, "/user");
		@header('Content-Type: text/html; charset=UTF-8');
		exit("<script>window.location.href={$redirect_js};</script>");
	}elseif($islogin2==1){
		$sds=$DB->exec("update `pre_user` set `wx_uid`='$openId' where `uid`='$uid'");
		@header('Content-Type: text/html; charset=UTF-8');
		exit("<script language='javascript'>alert('已成功绑定微信账号！');window.location.href='./editinfo.php';</script>");
	}else{
		if(!isset($_GET['bind'])){
			$_SESSION['Oauth_wx_uid']=$openId;
			exit("<script language='javascript'>alert('请输入商户ID和密钥完成绑定和登录');window.location.href='./login.php?connect=true';</script>");
		}else{
			exit("<script language='javascript'>alert('微信账号绑定成功');</script>");
		}
	}
}elseif($islogin2==1 && !isset($_GET['bind'])){
	exit("<script language='javascript'>window.location.href='./';</script>");
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8" />
<title>微信登录 | <?php echo $conf['sitename']?></title>
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
<link rel="stylesheet" href="<?php echo $cdnpublic?>twitter-bootstrap/3.4.1/css/bootstrap.min.css" type="text/css" />
<link rel="stylesheet" href="<?php echo $cdnpublic?>animate.css/3.7.2/animate.min.css" type="text/css" />
<link rel="stylesheet" href="<?php echo $cdnpublic?>font-awesome/4.7.0/css/font-awesome.min.css" type="text/css" />
<link rel="stylesheet" href="./assets/css/font.css" type="text/css" />
<link rel="stylesheet" href="./assets/css/app.css" type="text/css" />
<style>input:-webkit-autofill{-webkit-box-shadow:0 0 0px 1000px white inset;-webkit-text-fill-color:#333;}img.logo{width:14px;height:14px;margin:0 5px 0 3px;}</style>
</head>
<body>
<div class="app app-header-fixed  ">
<div class="container w-xxl w-auto-xs" ng-controller="SigninFormController" ng-init="app.settings.container = false;">
<span class="navbar-brand block m-t" id="sitename"><?php echo $conf['sitename']?></span>
<div class="m-b-lg">
<div class="wrapper text-center">
<strong>微信扫码登录</strong>
</div>
<form name="form" class="form-validation">
<div class="text-danger wrapper text-center" ng-show="authError">
</div>
	<div class="form-group" style="text-align: center;">
		<div class="list-group-item list-group-item-success" style="font-weight: bold;" id="login">
			<span id="loginmsg">请使用微信扫描二维码登录</span>
		</div>
		<div id="qrcode" class="qr-image list-group-item">
		</div>
		<div class="list-group-item">
		<div class="btn-group">
		<a href="login.php" class="btn btn-primary btn-rounded"><i class="fa fa-user"></i>&nbsp;返回登录</a>
		<a href="reg.php" class="btn btn-info btn-rounded"><i class="fa fa-user-plus"></i>&nbsp;注册账号</a>
		</div>
		</div>
		</div>
	</div>
</form>
</div>
<div class="text-center">
<p>
<small class="text-muted"><a href="/"><?php echo $conf['sitename']?></a><br>&copy; 2016~<?php echo date("Y")?></small>
</p>
</div>
</div>
</div>
<script src="/assets/vendor/jquery/3.7.1/jquery.min.js"></script>
<script src="/assets/vendor/twitter-bootstrap/3.4.1-epay.1/js/bootstrap.js"></script>
<script src="<?php echo $cdnpublic?>layer/3.1.1/layer.js"></script>
<script src="<?php echo $cdnpublic?>jquery.qrcode/1.0/jquery.qrcode.min.js"></script>
<script>
$(document).ready(function(){
	$('#qrcode').qrcode({
        text: <?php echo json_encode($code_url, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>,
        width: 230,
        height: 230,
        foreground: "#000000",
        background: "#ffffff",
        typeNumber: -1
    });
	setTimeout('checkopenid()', 2000);
});
function checkopenid(){
	$.ajax({
		type: "GET",
		dataType: "json",
		url: "wxlogin.php?act=login",
		success: function (data, textStatus) {
			if (data.code == 0) {
				layer.msg(data.msg, {icon: 16,time: 10000,shade:[0.3, "#000"]});
				setTimeout(function(){ window.location.href=data.url }, 1000);
			}else if (data.code == 1) {
				setTimeout('checkopenid()', 2000);
			}else{
				layer.alert(data.msg);
			}
		},
		error: function (data) {
			layer.msg('服务器错误', {icon: 2});
			return false;
		}
	});
}
</script>
</body>
</html>