<?php
/**
 * QQ互联
**/
include("../includes/common.php");
if($islogin2==1 && !isset($_GET['code']) && !isset($_GET['act']) && isset($_GET['bind'])){
    $bindStart = $_SESSION['oauth_bind_start'] ?? null;
    if(!is_array($bindStart) || $bindStart['actor'] !== (string)$uid || $bindStart['provider'] !== 'qq' || $bindStart['expires'] < time()) exit('请从账户设置发起绑定');
}

if(isset($_GET['unbind'])){ http_response_code(405); exit('Method Not Allowed'); }
// Legacy qrlogin.php accepts a client-supplied qrsig and writes a shared,
// timeless recovery slot, not a session/actor-bound authentication proof.
// Do not consume it for login or binding; use official QQ OAuth (1/3).
if((isset($_GET['act']) && $_GET['act']==='qrlogin') ||
    ($conf['login_qq']==2 && !isset($_GET['code']) && !($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['unbind'])))){
    unset($_SESSION['findpwd_qq']);
    header('Content-Type: application/json; charset=UTF-8');
    exit(json_encode(['code'=>-1, 'msg'=>'旧版QQ扫码登录已停用，请使用账号密码或联系管理员配置QQ官方/聚合OAuth登录']));
}

$QC_config['appid']=$conf['login_qq_appid'];
$QC_config['appkey']=$conf['login_qq_appkey'];
$QC_config['callback']=$siteurl.'user/connect.php';

$Oauth_config['apiurl']=$conf['login_apiurl'];
$Oauth_config['appid']=$conf['login_appid'];
$Oauth_config['appkey']=$conf['login_appkey'];
$Oauth_config['callback']=$siteurl.'user/connect.php';

if($_GET['code'] && ($conf['login_qq']==1 || $conf['login_qq']==3 || $conf['login_wx']==-1 || $conf['login_alipay']==-1)){
	if($conf['login_qq']==1 && !isset($_GET['type'])){
		$QC=new \lib\QC($QC_config);
		[$access_token,$openid]=$QC->qq_callback();
		$typename = 'QQ';
		$typecolumn = 'qq_uid';
	}else{
		$type = isset($_GET['type'])?$_GET['type']:exit('{"code":-1,"msg":"no type"}');
		if(!is_string($type) || !in_array($type, ['qq','wx','alipay'], true)) exit('Invalid OAuth type');
		if($type == 'qq'){
			$typename = 'QQ';
			$typecolumn = 'qq_uid';
		}elseif($type == 'wx'){
			$typename = '微信';
			$typecolumn = 'wx_uid';
		}elseif($type == 'alipay'){
			$typename = '支付宝';
			$typecolumn = 'alipay_uid';
		}
		$Oauth=new \lib\Oauth($Oauth_config);
		$arr = $Oauth->callback();
		if(isset($arr['code']) && $arr['code']==0){
			$openid=$arr['social_uid'];
			$access_token=$arr['access_token'];
		}elseif(isset($arr['code'])){
			sysmsg($arr['msg']);
		}else{
			sysmsg('获取登录数据失败');
		}
	}

	$userrow=$DB->getRow("SELECT * FROM pre_user WHERE {$typecolumn}=:openid limit 1", [':openid'=>$openid]);
	if($userrow){
		$uid=$userrow['uid'];
		$key=$userrow['key'];
		if($islogin2==1){
			@header('Content-Type: text/html; charset=UTF-8');
			exit("<script language='javascript'>alert('当前{$typename}已绑定商户ID:{$uid}，请勿重复绑定！');window.location.href='./editinfo.php';</script>");
		}
		$DB->insert('log', ['uid'=>$uid, 'type'=>$typename.'快捷登录', 'date'=>'NOW()', 'ip'=>$clientip, 'city'=>$city]);
		$session=epay_user_session_digest($userrow);
		$expiretime=time()+2592000;
		$token=authcode("{$uid}\t{$session}\t{$expiretime}", 'ENCODE', SYS_KEY);
		epay_set_cookie("user_token", $token, time() + 2592000, "/user");
		$DB->exec("update `pre_user` set `lasttime`=NOW() where `uid`='$uid'");
		exit("<script language='javascript'>window.location.href='./';</script>");
	}elseif($islogin2==1){
		$sds=$DB->exec("update `pre_user` set `{$typecolumn}`=:openid where `uid`='$uid'", [':openid'=>$openid]);
		@header('Content-Type: text/html; charset=UTF-8');
		exit("<script language='javascript'>alert('已成功绑定{$typename}！');window.location.href='./editinfo.php';</script>");
	}else{
		$_SESSION['Oauth_'.$typecolumn]=$openid;
		@header('Content-Type: text/html; charset=UTF-8');
		exit("<script language='javascript'>alert('请输入商户ID和密钥完成绑定和登录');window.location.href='./login.php?connect=true';</script>");
	}
}elseif($islogin2==1 && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['unbind'])){
	if(!checkRefererHost())exit();
	if(!isset($_POST['csrf_token'])) exit('CSRF TOKEN ERROR');
	csrf_check_page('user');
	$DB->exec("update `pre_user` set `qq_uid`=NULL where `uid`='$uid'");
	@header('Content-Type: text/html; charset=UTF-8');
	exit("<script language='javascript'>alert('您已成功解绑QQ！');window.location.href='./editinfo.php';</script>");
}elseif($islogin2==1 && !isset($_GET['bind'])){
	@header('Content-Type: text/html; charset=UTF-8');
	exit("<script language='javascript'>alert('您已登陆！');window.location.href='./';</script>");
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8" />
<title>QQ扫码登录 | <?php echo $conf['sitename']?></title>
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
<strong>QQ扫码登录</strong>
</div>
<form name="form" class="form-validation">
<div class="text-danger wrapper text-center" ng-show="authError">
</div>
	<div class="form-group" style="text-align: center;">
		<div class="list-group-item list-group-item-info" style="font-weight: bold;" id="login">
			<span id="loginmsg">请使用QQ手机版扫描二维码</span><span id="loginload" style="padding-left: 10px;color: #790909;">.</span>
		</div>
		<div id="qrimg" class="list-group-item">
		</div>
		<div class="list-group-item" id="mobile" style="display:none;"><button type="button" onclick="qrlogin()" class="btn btn-success btn-block">我已完成登录</button></div>
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
<script src="./assets/js/qrlogin.js"></script>
</body>
</html>