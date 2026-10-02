<?php
require __DIR__.'/../includes/common.php';
if (($islogin2 ?? 0) != 1 || (int)($uid ?? 0) <= 0) { http_response_code(403); header('Cache-Control: no-store'); exit('Forbidden'); }
if (($userrow['status'] ?? null) === 0 || ($userrow['status'] ?? null) === '0') { http_response_code(403); header('Cache-Control: no-store'); exit('Forbidden'); }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { http_response_code(405); header('Allow: GET'); exit; }
\lib\PrivateReceipt::respond($_GET['id'] ?? null, 'user', $uid);
