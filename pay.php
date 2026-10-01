<?php
$nosession = true;
$s = isset($_GET['s'])?$_GET['s']:exit('404 Not Found');
if(!is_string($s) || !preg_match('/^[a-zA-Z0-9]{1,32}\/[0-9]{8,32}\/\z/', $s)) exit('404 Not Found');
unset($_GET['s']);
include("./includes/common.php");

if (function_exists("set_time_limit"))
{
	@set_time_limit(0);
}
if (function_exists("ignore_user_abort"))
{
	@ignore_user_abort(true);
}

$sitename=is_string($_GET['sitename'] ?? null)?base64_decode($_GET['sitename'], true):'';
$submit2=true;

try{
	$result = \lib\Plugin::loadForPay($s);
	\lib\Payment::echoDefault($result);
}catch(Exception $e){
	sysmsg($e->getMessage());
}