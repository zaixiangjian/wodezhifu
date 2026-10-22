<?php
// Normal functional regressions only: no exploit patterns or application secrets.
function check($ok, $label) { if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); } echo "PASS: $label\n"; }
check(PHP_VERSION === '8.3.36', 'PHP security release retained');
check(PCRE_JIT_SUPPORT && ini_get('pcre.jit') === '1', 'JIT retained, not a disable-only mitigation');
$required = ['pdo_mysql','mysqli','gd','zip','mbstring','bcmath','Zend OPcache','sodium','curl','openssl'];
foreach ($required as $extension) check(extension_loaded($extension), "extension $extension");
check(preg_match('/^[a-z0-9_]{3,24}$/i', 'normal_user_42') === 1, 'ASCII identifier');
check(preg_match('/^(?<amount>\d+\.\d{2})$/', '123.45', $m) === 1 && $m['amount'] === '123.45', 'named money capture');
check(preg_match('/^\p{L}+$/u', '正常文本') === 1, 'UTF Unicode property');
check(preg_replace('/\s+/', '-', 'one two  three') === 'one-two-three', 'replacement');
check(preg_split('/[,;]/', 'a,b;c') === ['a','b','c'], 'split');
check(preg_match_all('/\b\d+\b/', 'a 12 b 34', $m) === 2 && $m[0] === ['12','34'], 'match_all');
check(preg_match('/foo(?=bar)/', 'foobar') === 1, 'lookahead');
check(preg_match('/(?<=foo)bar/', 'foobar') === 1, 'fixed lookbehind');
check(preg_match('/^(a|b)+$/', 'abba') === 1, 'ordinary alternation');
check(preg_match('/^\d{2}$/', 'abc') === 0, 'ordinary negative match');
check(preg_last_error() === PREG_NO_ERROR, 'no regex engine errors');
check(ini_get('expose_php') === '' && ini_get('display_errors') === '' && ini_get('log_errors') === '1', 'security INI retained');
echo json_encode(['php'=>PHP_VERSION,'pcre'=>PCRE_VERSION,'jit'=>ini_get('pcre.jit'),'extensions'=>get_loaded_extensions()]), "\n";
