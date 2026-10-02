<?php
include("../includes/common.php");
if($islogin==1){}else exit("<script language='javascript'>window.location.href='./login.php';</script>");

// Reauthorize each sensitive operation against the factor currently stored on the server.
function verify_current_totp($conf, $submitted){
	if(!epay_totp_enabled($conf)) return true;
	if(!epay_totp_secret_valid($conf['totp_secret'] ?? null)){
		echojsonmsg('当前动态口令配置异常，请联系管理员');
		return false;
	}
	$now = time();
	$attempts = $_SESSION['totp_settings_attempts'] ?? ['until'=>$now+300, 'count'=>0];
	if($attempts['until'] <= $now) $attempts = ['until'=>$now+300, 'count'=>0];
	if($attempts['count'] >= 5){
		echojsonmsg('验证次数过多，请稍后重试');
		return false;
	}
	$attempts['count']++;
	$_SESSION['totp_settings_attempts'] = $attempts;
	if(!is_string($submitted) || !preg_match('/^[0-9]{6}$/D', $submitted)){
		echojsonmsg('请输入当前动态口令');
		return false;
	}
	try {
		if(!\lib\TOTP::create($conf['totp_secret'])->verify($submitted)){
			echojsonmsg('当前动态口令错误');
			return false;
		}
	} catch (Exception $e) {
		echojsonmsg('当前动态口令验证失败');
		return false;
	}
	unset($_SESSION['totp_settings_attempts']);
	return true;
}

function save_totp_factor($secret, $enabled){
	global $DB, $CACHE;
	try {
		if($DB->beginConfigurationTransaction() === false) return false;
		// Store both fields in one statement, not a partially applied two-write flow.
		if($DB->exec('REPLACE INTO pre_config (k,v) VALUES (:secret_key,:secret),(:open_key,:enabled)', [':secret_key'=>'totp_secret', ':secret'=>$secret, ':open_key'=>'totp_open', ':enabled'=>(string)$enabled]) === false) throw new RuntimeException('write');
		if($DB->commit() === false) throw new RuntimeException('commit');
		return $CACHE->clear() !== false;
	} catch(Throwable $e){
		try { $DB->rollBack(); } catch(Throwable $ignored) {}
		return false;
	}
}

if(isset($_POST['action'])){
	header('Content-Type: application/json; charset=UTF-8');
	header('Cache-Control: no-store');
	if(!$islogin) exit(json_encode(['code'=>-1, 'msg'=>'未登录']));
	if(!checkRefererHost()) exit(json_encode(['code'=>403, 'msg'=>'Forbidden']));
	csrf_check_json('admin');
	if($_POST['action'] == 'generate'){
		unset($_SESSION['totp_reset_ticket']);
		if(!verify_current_totp($conf, $_POST['current_code'] ?? null)) exit;
		try {
			$totp = \lib\TOTP::create();
			$totp->setLabel($conf['admin_user']);
			$totp->setIssuer($conf['sitename']);
			$secret = $totp->getSecret();
			$ticket = bin2hex(random_bytes(32));
			$_SESSION['totp_reset_ticket'] = [
				'token' => $ticket, 'secret_hash' => hash('sha256', $secret),
				'old_hash' => epay_admin_session_digest($conf), 'attempts'=>0,
				'expires' => time() + 180
			];
			echojson(['code' => 0, 'data' => ['secret' => $secret, 'qrcode' => $totp->getProvisioningUri(), 'ticket' => $ticket]]);
		} catch (Exception $e) {
			echojsonmsg('生成动态口令失败');
		}
	}elseif($_POST['action'] == 'bind'){
		$secret = is_string($_POST['secret'] ?? null) ? trim($_POST['secret']) : '';
		$code = is_string($_POST['code'] ?? null) ? trim($_POST['code']) : '';
		$pending = $_SESSION['totp_reset_ticket'] ?? null;
		$submitted = $_POST['reset_ticket'] ?? null;
		if(!is_array($pending) || !is_string($submitted) || !is_string($secret) ||
			($pending['expires'] ?? 0) < time() ||
			!hash_equals((string)($pending['token'] ?? ''), $submitted) ||
			!hash_equals((string)($pending['secret_hash'] ?? ''), hash('sha256', $secret)) ||
			!hash_equals((string)($pending['old_hash'] ?? ''), epay_admin_session_digest($conf)) ||
			($pending['attempts'] ?? 0) >= 5 || !preg_match('/^[A-Z2-7]{32}$/D', $secret)){
			echojsonmsg('重置验证已失效，请重新验证当前动态口令');
		}
		$_SESSION['totp_reset_ticket']['attempts'] = ($pending['attempts'] ?? 0) + 1;
		if(!preg_match('/^[0-9]{6}$/D', $code)){
			echojsonmsg('参数不完整');
		}
		try {
			$totp = \lib\TOTP::create($secret);
			if (!$totp->verify($code)) {
				echojsonmsg('动态口令错误');
			}
		} catch (Exception $e) {
			echojsonmsg('动态口令验证失败');
		}
		if(!save_totp_factor($secret, 1)) echojsonmsg('保存动态口令失败，请重新登录检查配置');
		unset($_SESSION['totp_reset_ticket'], $_SESSION['admin_totp_challenge']);
		epay_delete_cookie('admin_token', '/admin');
		echojson(['code' => 0, 'msg' => 'TOTP绑定成功']);
	}elseif($_POST['action'] == 'close'){
		if(!verify_current_totp($conf, $_POST['current_code'] ?? null)) exit;
		if(!save_totp_factor('', 0)) echojsonmsg('保存动态口令失败，请重新登录检查配置');
		unset($_SESSION['totp_reset_ticket'], $_SESSION['admin_totp_challenge']);
		epay_delete_cookie('admin_token', '/admin');
		echojson(['code' => 0, 'msg' => 'TOTP已关闭']);
	}else{
		echojsonmsg('参数错误');
	}
}

$title='TOTP二次验证配置';
include './head.php';

?>
  <div class="container" style="padding-top:70px;">
    <div class="col-xs-12 col-sm-10 col-lg-8 center-block" style="float: none;">
<div class="panel panel-primary">
<div class="panel-heading"><h3 class="panel-title">TOTP二次验证</h3></div>
<div class="panel-body">
  <form onsubmit="return false" method="post" class="form" role="form">
	<div class="form-group">
		<div class="input-group">
			<?php if($conf['totp_open'] == 1){ ?>
			<input type="text" name="totp_status" value="已开启" style="color:green" class="form-control" readonly/>
			<div class="input-group-btn"><button type="button" class="btn btn-info" onclick="open_totp()">重置</button></div>
			<div class="input-group-btn"><button type="button" class="btn btn-danger" onclick="close_totp()">关闭</button></div>
			<?php }else{ ?>
			<input type="text" name="totp_status" value="未开启" style="color:blue" class="form-control" readonly/>
			<div class="input-group-btn"><button type="button" class="btn btn-info" onclick="open_totp()">开启</button></div>
			<?php } ?>
		</div>
	</div>
  </form>
</div>
<div class="panel-footer">
<p><span class="glyphicon glyphicon-info-sign"></span> 开启后，登录时需使用支持TOTP的认证软件进行二次验证，提高账号安全性。开启前需确保服务器时间正确。</p>
<p>支持TOTP的认证软件：<a href="https://sj.qq.com/appdetail/com.tencent.authenticator" target="_blank" rel="noreferrer">腾讯身份验证器</a>、<a href="https://play.google.com/store/apps/details?id=com.google.android.apps.authenticator2" target="_blank" rel="noreferrer">谷歌身份验证器</a>、<a href="https://www.microsoft.com/zh-cn/security/mobile-authenticator-app" target="_blank" rel="noreferrer">微软身份验证器</a>、<a href="https://github.com/freeotp" target="_blank" rel="noreferrer">FreeOTP</a></p>
</div>
</div>
<div class="modal" id="modal-totp" data-backdrop="static" data-keyboard="false" aria-hidden="true">
	<div class="modal-dialog" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<button type="button" class="close" data-dismiss="modal" aria-label="Close">
					<span aria-hidden="true">&times;</span>
				</button>
				<h4 class="modal-title">TOTP绑定</h4>
			</div>
			<div class="modal-body text-center">
				<p>使用支持TOTP的认证软件扫描以下二维码</p>
				<div class="qr-image mt-4" id="qrcode"></div>
				<p><a href="javascript:;" data-clipboard-text="" id="copy-btn">复制密钥</a></p>
				<form id="form-totp" style="text-align: left;" onsubmit="return bind_totp()">
					<?php if($conf['totp_open'] == 1){ ?>
					<p>当前动态口令已在上一步验证。请填写新验证器显示的口令。</p>
					<?php } ?>
					<div class="form-group mt-4">
						<div class="input-group"><input type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" class="form-control input-lg" name="code" id="code" value="" placeholder="填写动态口令" autocomplete="off" required><div class="input-group-btn"><input type="submit" name="submit" value="完成绑定" class="btn btn-success btn-lg btn-block"/></div></div>
					</div>
				</form>
			</div>
		</div>
	</div>
</div>
    </div>
  </div>
<script src="<?php echo $cdnpublic?>layer/3.1.1/layer.js"></script>
<script src="<?php echo $cdnpublic?>jquery.qrcode/1.0/jquery.qrcode.min.js"></script>
<script src="<?php echo $cdnpublic?>clipboard.js/1.7.1/clipboard.min.js"></script>
<script>
var commonData = {secret:null,qrcode:null,resetTicket:null};
var totpInFlight = false;
var totpEnabled = <?php echo $conf['totp_open'] == 1 ? 'true' : 'false'; ?>;
function open_totp(){
	if(totpInFlight) return false;
	if(!commonData.qrcode || !commonData.secret){
		if(totpEnabled){
			layer.prompt({title:'请输入当前验证器的动态口令以重置', formType:0}, function(currentCode, promptIndex){
				if(!/^[0-9]{6}$/.test(currentCode)) return layer.msg('当前动态口令格式错误', {icon:2});
				layer.close(promptIndex);
				generate_totp(currentCode);
			});
			return;
		}
		generate_totp('');
	}else{
		$('#modal-totp').modal('show');
		$("#code").focus();
	}
}
function generate_totp(currentCode){
	if(totpInFlight) return false;
	totpInFlight = true;
	var ii = layer.load(2, {shade:[0.1,'#fff']});
	$.post('?', {action:'generate', current_code:currentCode}, function(res){
		layer.close(ii);
		totpInFlight = false;
		if(res.code == 0){
			commonData.secret = res.data.secret;
			commonData.qrcode = res.data.qrcode;
			commonData.resetTicket = res.data.ticket || null;
			$('#qrcode').empty().qrcode({
					text: commonData.qrcode,
					width: 150,
					height: 150,
					foreground: "#000000",
					background: "#ffffff",
					typeNumber: -1
				});
				$("#copy-btn").attr('data-clipboard-text', commonData.secret);
				$('#modal-totp').modal('show');
				$("#code").focus();
			}else{
				layer.alert(res.msg || '操作失败，请重试', {icon: 2});
			}
		}, 'json').fail(function(){ totpInFlight=false; layer.close(ii); layer.alert('请求失败，请重试', {icon:2}); });
}
function bind_totp(){
	if(totpInFlight) return false;
	var code = $("#code").val();
	if(!commonData.secret || !commonData.resetTicket){
		layer.msg('请先验证当前动态口令', {icon: 2});
		return false;
	}
	if(!/^[0-9]{6}$/.test(code)){
		layer.msg('动态口令格式错误', {icon: 2});
		return false;
	}
	var ii = layer.load(2, {shade:[0.1,'#fff']});
	totpInFlight = true;
	$('#form-totp :submit').prop('disabled', true);
	$.post('?', {action:'bind', secret:commonData.secret, code:code, reset_ticket:commonData.resetTicket}, function(res){
		layer.close(ii);
		if(res.code == 0){
			layer.alert('TOTP绑定成功', {icon: 1}, function(){
				window.location.reload();
			});
		}else{
			totpInFlight = false;
			$('#form-totp :submit').prop('disabled', false);
			commonData.resetTicket = null;
			commonData.secret = null;
			commonData.qrcode = null;
			$('#modal-totp').modal('hide');
			layer.alert(res.msg || '绑定失败，请重新验证', {icon: 2});
		}
	}, 'json').fail(function(){ totpInFlight=false; $('#form-totp :submit').prop('disabled', false); layer.close(ii); layer.alert('请求失败，请重试', {icon:2}); });
	return false;
}
function close_totp(){
	if(totpInFlight) return false;
	layer.prompt({title:'请输入当前验证器的动态口令以关闭', formType:0}, function(currentCode, promptIndex){
		if(!/^[0-9]{6}$/.test(currentCode)) return layer.msg('当前动态口令格式错误', {icon:2});
		layer.close(promptIndex);
		var ii = layer.load(2, {shade:[0.1,'#fff']});
		if(totpInFlight) return;
		totpInFlight = true;
		$.post('?', {action: 'close', current_code:currentCode}, function(res){
			layer.close(ii);
			if(res.code == 0){
				layer.alert('TOTP已关闭', {icon: 1}, function(){
					window.location.reload();
				});
			}else{
				totpInFlight = false;
				layer.alert(res.msg || '操作失败，请重试', {icon: 2});
			}
		}, 'json').fail(function(){totpInFlight=false;layer.close(ii);layer.alert('请求失败，请重试', {icon:2});});
	});
}
$(document).ready(function(){
	var clipboard = new Clipboard('#copy-btn');
	clipboard.on('success', function (e) {
		layer.msg('复制成功！', {icon: 1, time: 600});
	});
	clipboard.on('error', function (e) {
		layer.msg('复制失败', {icon: 2});
	});
	// 不在第六位输入时自动提交：用户随后点击“完成绑定”会重复发起请求。
	// 重置成功后的第二个请求会使用已失效的旧凭证，造成“已保存却提示错误”。
});
</script>