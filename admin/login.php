<?php
/**
 * 登录
**/
$verifycode = 1;//验证码开关
$login_limit_count = 5;//登录失败次数
$login_limit_file = '@login.lock';

if(!function_exists("imagecreate") || !file_exists('code.php'))$verifycode=0;
include("../includes/common.php");

// Serialize attempts across sessions; persist counters only, never passwords or OTPs.
function admin_totp_verify_once($code, $secret, $ip){
  $file = sys_get_temp_dir().'/epay-totp-'.hash_hmac('sha256', 'admin-totp', SYS_KEY).'.json';
  $fp = @fopen($file, 'c+');
  if(!$fp || !flock($fp, LOCK_EX)) throw new RuntimeException('验证暂不可用');
  @chmod($file, 0600);
  try {
    $data = json_decode(stream_get_contents($fp), true) ?: [];
    $now = time();
    $key = hash('sha256', $ip);
    foreach(($data['attempts'] ?? []) as $k=>$v) if($v['until'] <= $now) unset($data['attempts'][$k]);
    $attempt = $data['attempts'][$key] ?? ['until'=>$now+300, 'count'=>0];
    $valid = false;
    if($attempt['count'] < 5){
      $attempt['count']++;
      $step = (int)floor($now / 30);
      $secretId = hash('sha256', $secret);
      $valid = preg_match('/^[0-9]{6}$/D', $code) && (($data['used'][$secretId] ?? -1) < $step) && \lib\TOTP::create($secret)->verify($code, $now);
      if($valid) $data['used'][$secretId] = $step;
      $data['attempts'][$key] = $attempt;
    }
    rewind($fp);
    if(!ftruncate($fp, 0) || fwrite($fp, json_encode($data)) === false || !fflush($fp)) throw new RuntimeException('验证暂不可用');
    return (bool)$valid;
  } finally { flock($fp, LOCK_UN); fclose($fp); }
}

if(isset($_GET['act']) && $_GET['act']=='login'){
  header('Content-Type: application/json; charset=UTF-8');
  header('Cache-Control: no-store');
  if($_SERVER['REQUEST_METHOD'] !== 'POST'){
    header('Allow: POST');
    http_response_code(405);
    exit(json_encode(['code'=>405, 'msg'=>'登录接口仅接受 POST 请求']));
  }
  if(!checkRefererHost()){
    http_response_code(403);
    exit(json_encode(['code'=>403, 'msg'=>'登录请求来源校验失败，请确认浏览器未禁用同站 Referer，并检查域名反代的 Host 设置']));
  }
  unset($_SESSION['admin_totp_challenge']);
  if(!is_string($_POST['username'] ?? null) || !is_string($_POST['password'] ?? null) || !is_string($_POST['code'] ?? '') || !is_string($_POST['enc'] ?? '0')) exit(json_encode(['code'=>-1,'msg'=>'登录参数不合法']));
  $username = trim($_POST['username']);
  $password = trim($_POST['password']);
  $code = trim($_POST['code'] ?? '');
  $enc_type = isset($_POST['enc']) ? $_POST['enc'] : '0';
  if(empty($username) || empty($password)){
    exit(json_encode(['code'=>-1,'msg'=>'用户名或密码不能为空']));
  }
  if($verifycode==1 && (!$code || strtolower($code) != $_SESSION['vc_code'])){
    exit(json_encode(['code'=>-1,'msg'=>'验证码错误']));
  }
  $errcount = $DB->getColumn("SELECT count(*) FROM `pre_log` WHERE `ip`=:ip AND `date`>DATE_SUB(NOW(),INTERVAL 1 DAY) AND `uid`=0 AND `type`='登录失败'", [':ip'=>$clientip]);
  if($errcount >= $login_limit_count && file_exists($login_limit_file)){
    exit(json_encode(['code'=>-1,'msg'=>'多次登录失败，暂时禁止登录。可删除@login.lock文件解除限制']));
  }
  if($enc_type == '1'){
    $plain = '';
    $private_key = base64ToPem($conf['private_key'], 'PRIVATE KEY');
    $pkey = openssl_pkey_get_private($private_key);
    if(!openssl_private_decrypt(base64_decode($password), $plain, $pkey, OPENSSL_PKCS1_PADDING)){
      exit(json_encode(['code'=>-1,'msg'=>'密码解密失败']));
    }
    $password = $plain;
  }
  if(is_string($conf['admin_user'] ?? null) && $conf['admin_user'] !== '' && is_string($conf['admin_pwd'] ?? null) && $conf['admin_pwd'] !== '' && $password !== '' && hash_equals($conf['admin_user'], $username) && hash_equals($conf['admin_pwd'], $password)){
    if (epay_totp_enabled($conf)) {
      if(!epay_totp_secret_valid($conf['totp_secret'] ?? null)) exit(json_encode(['code'=>-1, 'msg'=>'动态口令配置异常，请联系管理员']));
      session_regenerate_id(true);
      $challenge = bin2hex(random_bytes(32));
      $_SESSION['admin_totp_challenge'] = ['token'=>$challenge, 'expires'=>time()+120, 'ip'=>$clientip, 'credential'=>epay_admin_session_digest($conf), 'attempts'=>0];
      unset($_SESSION['vc_code']);
      exit(json_encode(['code'=>-1, 'msg'=>'需要验证动态口令', 'vcode'=>2, 'challenge'=>$challenge]));
    }
    $DB->insert('log', ['uid'=>0, 'type'=>'登录后台', 'date'=>'NOW()', 'ip'=>$clientip]);
    if (file_exists($login_limit_file)) {
      unlink($login_limit_file);
    }
		$session=epay_admin_session_digest($conf);
		$expiretime=time() + 2592000;
		$token=authcode("{$username}\t{$session}\t{$expiretime}", 'ENCODE', SYS_KEY);
		epay_set_cookie("admin_token", $token, $expiretime, "/admin");
    unset($_SESSION['vc_code']);
    exit(json_encode(['code'=>0]));
  }else{
    $DB->insert('log', ['uid'=>0, 'type'=>'登录失败', 'date'=>'NOW()', 'ip'=>$clientip]);
    unset($_SESSION['vc_code']);
    $errcount++;
    $retry_times = $login_limit_count - $errcount;
    if($retry_times < 0) $retry_times = 0;
    if($retry_times <= 0){
      file_put_contents($login_limit_file, '1');
      exit(json_encode(['code'=>-1,'msg'=>'多次登录失败，暂时禁止登录。可删除@login.lock文件解除限制','vcode'=>1]));
    }else{
      exit(json_encode(['code'=>-1,'msg'=>'用户名或密码错误，你还可以尝试'.$retry_times.'次','vcode'=>1]));
    }
  }
}elseif(isset($_GET['act']) && $_GET['act']=='totp'){
  header('Content-Type: application/json; charset=UTF-8');
  header('Cache-Control: no-store');
  if($_SERVER['REQUEST_METHOD'] !== 'POST'){
    header('Allow: POST');
    http_response_code(405);
    exit(json_encode(['code'=>405, 'msg'=>'动态口令接口仅接受 POST 请求']));
  }
  if(!checkRefererHost()){
    http_response_code(403);
    exit(json_encode(['code'=>403, 'msg'=>'登录请求来源校验失败，请确认浏览器未禁用同站 Referer，并检查域名反代的 Host 设置']));
  }
  $pending = $_SESSION['admin_totp_challenge'] ?? null;
  $submitted = $_POST['challenge'] ?? null;
  if(!is_array($pending) || $pending['expires'] < time() || $pending['ip'] !== $clientip || $pending['attempts'] >= 5 || !is_string($submitted) || !hash_equals($pending['token'], $submitted) || !hash_equals($pending['credential'], epay_admin_session_digest($conf))){
    unset($_SESSION['admin_totp_challenge']);
    exit(json_encode(['code'=>-1,'msg'=>'请重新验证用户名和密码']));
  }
  $_SESSION['admin_totp_challenge']['attempts']++;
  $code = is_string($_POST['code'] ?? null) ? trim($_POST['code']) : '';
  if (empty($code)) exit(json_encode(['code'=>-1,'msg'=>'请输入动态口令']));
  if (!epay_totp_enabled($conf) || !epay_totp_secret_valid($conf['totp_secret'] ?? null)) {
    exit(json_encode(['code'=>-1,'msg'=>'未启用TOTP二次验证']));
  }
  try {
    if (!admin_totp_verify_once($code, $conf['totp_secret'], $clientip)) {
      exit(json_encode(['code'=>-1,'msg'=>'动态口令错误']));
    }
  } catch (Exception $e) {
    exit(json_encode(['code'=>-1,'msg'=>$e->getMessage()]));
  }
  unset($_SESSION['admin_totp_challenge']);
  session_regenerate_id(true);
  $DB->insert('log', ['uid'=>0, 'type'=>'登录后台', 'date'=>'NOW()', 'ip'=>$clientip]);
  $session=epay_admin_session_digest($conf);
  $expiretime=time() + 2592000;
  $token=authcode("{$conf['admin_user']}\t{$session}\t{$expiretime}", 'ENCODE', SYS_KEY);
  epay_set_cookie("admin_token", $token, $expiretime, "/admin");
  exit(json_encode(['code'=>0]));
}elseif(isset($_GET['logout'])){
	if($_SERVER['REQUEST_METHOD'] !== 'POST'){
		header('Allow: POST');
		http_response_code(405);
		exit('Method Not Allowed');
	}
	csrf_check_page('admin');
	if(!checkRefererHost())exit();
	epay_delete_cookie("admin_token", "/admin");
	exit("<script language='javascript'>window.location.href='./login.php';</script>");
}elseif($islogin==1){
	exit("<script language='javascript'>alert('您已登录！');window.location.href='./';</script>");
}
$title='用户登录';
include './head.php';
?>
  <nav class="navbar navbar-fixed-top navbar-default">
    <div class="container">
      <div class="navbar-header">
        <button type="button" class="navbar-toggle collapsed" data-toggle="collapse" data-target="#navbar" aria-expanded="false" aria-controls="navbar">
          <span class="sr-only">导航按钮</span>
          <span class="icon-bar"></span>
          <span class="icon-bar"></span>
          <span class="icon-bar"></span>
        </button>
        <a class="navbar-brand" href="./">支付管理中心</a>
      </div><!-- /.navbar-header -->
      <div id="navbar" class="collapse navbar-collapse">
        <ul class="nav navbar-nav navbar-right">
          <li class="active">
            <a href="./login.php"><span class="glyphicon glyphicon-user"></span> 登录</a>
          </li>
        </ul>
      </div><!-- /.navbar-collapse -->
    </div><!-- /.container -->
  </nav><!-- /.navbar -->
  <div class="container" style="padding-top:70px;">
    <div class="col-xs-12 col-sm-10 col-md-8 col-lg-6 center-block" style="float: none;">
      <div class="panel panel-primary">
        <div class="panel-heading"><h3 class="panel-title">管理员登录</h3></div>
        <div class="panel-body">
          <form class="form-horizontal" id="login-form" role="form" onsubmit="return submitlogin()">
            <div class="input-group">
              <span class="input-group-addon"><span class="glyphicon glyphicon-user"></span></span>
              <input type="text" name="user" value="" class="form-control input-lg" placeholder="用户名" required="required"/>
            </div><br/>
            <div class="input-group">
              <span class="input-group-addon"><span class="glyphicon glyphicon-lock"></span></span>
              <input type="password" name="pass" class="form-control input-lg" placeholder="密码" required="required"/>
            </div><br/>
            <?php if($verifycode==1){?>
            <div class="input-group">
              <span class="input-group-addon"><span class="glyphicon glyphicon-adjust"></span></span>
              <input type="text" class="form-control input-lg" name="code" placeholder="输入验证码" autocomplete="off" required>
              <span class="input-group-addon" style="padding: 0">
                <img id="verifycode" src="./code.php?r=<?php echo time();?>"height="45"onclick="this.src='./code.php?r='+Math.random();" title="点击更换验证码">
              </span>
            </div><br/>
            <?php }?>
            <div class="form-group">
              <div class="col-xs-12"><button type="submit" class="btn btn-primary btn-lg btn-block" id="submit">立即登录</button></div>
            </div>
            <div class="form-group">
              <div class="col-xs-12 text-center"><a href="javascript:void(0);" onclick="findpwd()">忘记密码</a></div>
            </div>
          </form>
          <form class="form-horizontal" id="totp-form" onsubmit="return doTotp()" style="display:none;">
            <div class="alert alert-info" role="alert">TOTP二次验证</div>
            <div class="input-group">
              <div class="input-group-addon"><span class="glyphicon glyphicon-lock" aria-hidden="true"></span></div>
              <input type="number" class="form-control input-lg" placeholder="输入动态口令" name="totp_code" id="totp_code" autocomplete="off" required="required"/>
            </div><br/>
            <div class="form-group">
              <div class="col-xs-12"><button type="submit" class="btn btn-primary btn-lg btn-block" id="submit">立即登录</button></div>
            </div>
            <div class="form-group">
              <div class="col-xs-12 text-center"><a href="javascript:void(0);" onclick="findpwd()">忘记密码</a></div>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
<div class="modal fade" id="modal-findpwd" tabindex="-1" role="dialog">
	<div class="modal-dialog" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<button type="button" class="close" data-dismiss="modal" aria-label="Close">
					<span aria-hidden="true">&times;</span>
				</button>
				<h4 class="modal-title">找回管理员密码方法</h4>
			</div>
			<div class="modal-body">
				<p>进入数据库管理器（phpMyAdmin），点击进入当前网站所在数据库，然后查看pay_config表即可找回管理员密码。</p>
        <?php if($conf['totp_open'] == 1){?>如需关闭TOTP二次验证，请执行以下SQL：UPDATE pay_config SET v='0' WHERE k='totp_open';UPDATE pay_cache SET v='' WHERE k='config';<?php }?>
			</div>
		</div>
	</div>
</div>
<script src="<?php echo $cdnpublic?>layer/3.1.1/layer.js"></script>
<script src="<?php echo $cdnpublic?>jsencrypt/3.5.4/jsencrypt.min.js"></script>
<script>
const PUBLIC_KEY_PEM = `<?php echo base64ToPem($conf['public_key'], 'PUBLIC KEY')?>`;
function submitlogin(){
  var enc_type = '0';
  var user = $("input[name='user']").val();
  var pass = $("input[name='pass']").val();
  var code = $("input[name='code']").val();
  if(user=='' || pass==''){layer.alert('用户名或密码不能为空！');return false;}
  if(PUBLIC_KEY_PEM != ''){
    const enc = new JSEncrypt();
    enc.setPublicKey(PUBLIC_KEY_PEM);
    pass = enc.encrypt(pass);
    if(pass) enc_type = '1';
  }
  var ii = layer.load(2);
  $.ajax({
    type : 'POST',
    url : '?act=login',
    data: {username:user, password:pass, code:code, enc:enc_type},
    dataType : 'json',
    success : function(data) {
      layer.close(ii);
      if(data.code == 0){
        layer.msg('登录成功，正在跳转', {icon: 1,shade: 0.01,time: 15000});
        window.location.href='./';
      }else{
        if(data.vcode==1){
          $("#verifycode").attr('src', './code.php?r='+Math.random())
        }else if(data.vcode==2){
          window.totpChallenge = data.challenge;
          $("#totp-form").show();
          $("#login-form").hide();
          $("#totp_code").focus();
          return false;
        }
        layer.alert(typeof data.msg === 'string' && data.msg.trim() ? data.msg : '登录失败：服务器未返回错误详情，请检查网络请求响应和 PHP 日志', {icon: 2});
      }
    },
    error:function(xhr){
      layer.close(ii);
      layer.msg('登录请求失败（HTTP ' + (xhr.status || '网络错误') + '），请检查 PHP 日志');
    }
  });
  return false;
}
var loginTotpInFlight = false;
function doTotp(){
  if(loginTotpInFlight) return false;
  var code = $("#totp_code").val();
  if(code.length != 6){
		layer.msg('动态口令格式错误', {icon: 2});
		return false;
	}
	var ii = layer.load(2, {shade:[0.1,'#fff']});
	loginTotpInFlight = true;
	$.post('?act=totp', {code:code, challenge:window.totpChallenge}, function(res){
		layer.close(ii);
		if(res.code == 0){
			layer.msg('登录成功，正在跳转', {icon: 1,shade: 0.01,time: 15000});
      window.location.href = './';
		}else{
			loginTotpInFlight = false;
			layer.alert(typeof res.msg === 'string' && res.msg.trim() ? res.msg : '动态口令验证失败：服务器未返回错误详情，请重新验证用户名和密码', {icon: 2});
		}
	}, 'json').fail(function(xhr){
		loginTotpInFlight = false;
		layer.close(ii);
		layer.alert('动态口令请求失败（HTTP ' + (xhr.status || '网络错误') + '），请检查浏览器 Cookie 与 PHP 日志', {icon: 2});
	});
	return false;
}
function findpwd(){
  $('#modal-findpwd').modal('show');
}
</script>
</body>
</html>