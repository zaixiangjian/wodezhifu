<?php
/** Optional presentation policy. Never authentication; follow modes explicitly forward visitor path/query. */
function epay_site_redirect_host($url) {
    if (!is_string($url) || $url === '' || strlen($url)>2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url) || preg_match('/%(?:0[ad]|00)/i', $url)) return null;
    $p = parse_url($url);
    if (!$p || !isset($p['scheme'], $p['host']) || !in_array(strtolower($p['scheme']), ['http','https'], true) || isset($p['user']) || isset($p['pass']) || !filter_var($p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '').'/', FILTER_VALIDATE_URL)) return null;
    return strtolower(rtrim($p['host'], '.'));
}
function epay_site_redirect_sources($conf, $server) {
    $hosts = [];
    foreach (['localurl','apiurl','localurl_alipay','localurl_wxpay'] as $key) {
        $host = epay_site_redirect_host($conf[$key] ?? '');
        if ($host !== null) $hosts[] = $host;
    }
    // Captured at an authenticated same-origin settings save, not request Host on public pages.
    if (is_string($conf['site_redirect_source_host'] ?? null) && $conf['site_redirect_source_host'] !== '') $hosts[] = $conf['site_redirect_source_host'];
    if (is_string($server['SERVER_NAME'] ?? null) && $server['SERVER_NAME'] !== '_' && $server['SERVER_NAME'] !== '') $hosts[] = strtolower(rtrim($server['SERVER_NAME'], '.'));
    return array_unique($hosts);
}
/** Strict ASCII names, case-sensitive; never PHP parse_str normalization. */
function epay_site_redirect_query_names($input) {
    if (!is_string($input) || strlen($input)>4096) throw new InvalidArgumentException('查询参数白名单类型错误或超过4KB');
    $names = preg_split('/[,\x09-\x0d\x20]+/', trim($input, " \t\n\r\x0b\x0c"), -1, PREG_SPLIT_NO_EMPTY);
    if (count($names)>64) throw new InvalidArgumentException('查询参数白名单最多64项');
    foreach ($names as $name) {
        if (!preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $name)) throw new InvalidArgumentException('白名单参数名仅允许ASCII字母、数字、下划线、点和横线，每项最多64字符，不接受数组键');
    }
    return array_values(array_unique($names));
}
function epay_site_redirect_filter_query($query, $policy, $allowlist) {
    if ($policy === 'all') return $query; // Missing setting retains exact legacy bytes.
    if ($policy !== 'allowlist') return null; // Invalid stored policy fails closed for visitor query only.
    try { $names = epay_site_redirect_query_names($allowlist); }
    catch (InvalidArgumentException $e) { return null; }
    if ($query === null || !$names) return null;
    $kept = [];
    foreach (explode('&', $query) as $part) {
        $key = urldecode(explode('=', $part, 2)[0]); // Exactly one decode, '+' is a space.
        if (in_array($key, $names, true)) $kept[] = $part;
    }
    return $kept ? implode('&', $kept) : null;
}
function epay_site_redirect_validate_settings($settings, $conf, $server) {
    $touch = false;
    foreach (['site_redirect_mode','site_redirect_302_url','site_redirect_301_url','site_redirect_query_policy','site_redirect_query_allowlist'] as $key) {
        if (array_key_exists($key, $settings)) {
            $touch = true;
            if (!is_string($settings[$key])) throw new InvalidArgumentException('全站跳转设置类型错误');
        }
    }
    if (!$touch) return [];
    $effective = array_merge($conf, $settings);
    if (!in_array($effective['site_redirect_mode'] ?? '0', ['0','302','301','302_follow','301_follow'], true)) throw new InvalidArgumentException('全站跳转模式错误');
    if (!in_array($effective['site_redirect_query_policy'] ?? 'all', ['all','allowlist'], true)) throw new InvalidArgumentException('查询参数策略错误');
    epay_site_redirect_query_names($effective['site_redirect_query_allowlist'] ?? '');
    // The caller has already authenticated the admin, Referer and CSRF.
    $source = epay_site_redirect_host('http://'.($server['HTTP_HOST'] ?? '').'/');
    if ($source === null) throw new InvalidArgumentException('本站域名无效');
    $sources = epay_site_redirect_sources($effective, $server);
    $sources[] = $source;
    foreach (['302','301'] as $mode) {
        $url = $effective['site_redirect_'.$mode.'_url'] ?? '';
        if (!is_string($url)) throw new InvalidArgumentException('跳转网址类型错误');
        if ($url === '' && !in_array($effective['site_redirect_mode'] ?? '0', [$mode, $mode.'_follow'], true)) continue;
        $host = epay_site_redirect_host($url);
        if ($host === null) throw new InvalidArgumentException('跳转网址须为完整HTTP/HTTPS网址，不能包含账号、空白或换行');
        if (in_array($host, $sources, true)) throw new InvalidArgumentException('跳转网址不能指向本站（含不同端口或路径），以免循环');
    }
    $out = ['site_redirect_source_host'=>$source];
    if (array_key_exists('site_redirect_query_allowlist', $settings)) $out['site_redirect_query_allowlist'] = implode(',', epay_site_redirect_query_names($settings['site_redirect_query_allowlist']));
    return $out;
}
function epay_site_redirect_preserved($script, $method, $get) {
    // SCRIPT_NAME is supplied by the web server; REQUEST_URI and cookies confer no exception.
    if (preg_match('#^/admin/[A-Za-z0-9_]+\.php$#D', $script)) return true;
    $exact = ['/submit.php','/submit2.php','/mapi.php','/api.php','/api_receipt.php','/pay.php','/cashier.php','/getshop.php','/gold.php','/gateway.php','/wework.php','/cron.php','/plugins/paypal/webhook.php',
        '/paypage/index.php','/paypage/ajax.php','/paypage/inc.php','/paypage/success.php','/paypage/red.php','/paypage/red_ajax.php','/paypage/wxtrans.php','/paypage/wxapplet.php',
        '/user/receipt.php','/user/certificate.php','/user/login.php','/user/oauth.php','/user/wxlogin.php','/user/connect.php','/user/test.php','/user/recharge.php','/user/deposit.php','/user/groupbuy.php','/user/openid.php','/user/douyinoauth.php','/user/alipaycert.php','/user/alipaycertok.php'];
    if (in_array($script, $exact, true)) return true;
    $act = $get['act'] ?? null;
    if ($script === '/user/ajax.php' && $method === 'POST' && is_string($act) && in_array($act, ['login','connect','testpay','captcha','qrcode','getopenid'], true)) return true;
    if ($script === '/user/ajax2.php' && $method === 'POST' && is_string($act) && in_array($act, ['recharge','groupbuy','groupinfo','deposit_recharge','certificate','cert_geturl','cert_query','cert_bind'], true)) return true;
    return false;
}
/** Keep the configured authority fixed; never resolve a visitor URI as a URL. */
function epay_site_redirect_follow_url($url, $uri, $policy = 'all', $allowlist = '') {
    if (epay_site_redirect_host($url) === null || !is_string($uri) || $uri === '' || strlen($uri)>8192 || $uri[0] !== '/' || preg_match('/[\x00-\x20\x7f\\\\#]/', $uri) || preg_match('/%(?:0[ad]|00)/i', $uri) || preg_match('/%(?![0-9a-f]{2})/i', $uri)) return null;
    $p = parse_url($url);
    $qpos = strpos($uri, '?');
    $path = $qpos === false ? $uri : substr($uri, 0, $qpos);
    $query = $qpos === false ? null : substr($uri, $qpos+1);
    $query = epay_site_redirect_filter_query($query, $policy, $allowlist);
    // Dot segments would let browser normalization discard a configured prefix.
    if (preg_match('#(?:^|/)(?:\.|%2e){1,2}(?:/|$)#i', $path)) return null;
    $authority = $p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '');
    $out = $authority.rtrim($p['path'] ?? '', '/').$path;
    if (array_key_exists('query', $p)) {
        $out .= '?'.$p['query'];
        if ($query !== null && $query !== '') $out .= ($p['query'] !== '' ? '&' : '').$query;
    } elseif ($query !== null) $out .= '?'.$query;
    if (array_key_exists('fragment', $p)) $out .= '#'.$p['fragment'];
    return $out;
}
function epay_site_redirect_apply($conf) {
    if (PHP_SAPI === 'cli') return;
    $mode = $conf['site_redirect_mode'] ?? '0';
    if (!in_array($mode, ['302','301','302_follow','301_follow'], true)) return;
    $follow = substr($mode, -7) === '_follow';
    $status = $follow ? substr($mode, 0, 3) : $mode;
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    if (epay_site_redirect_preserved($script, $method, $_GET)) return;
    $url = $conf['site_redirect_'.$status.'_url'] ?? '';
    $host = epay_site_redirect_host($url);
    if ($host === null || in_array($host, epay_site_redirect_sources($conf, $_SERVER), true)) {
        http_response_code(503); header('Cache-Control: no-store'); exit('Site redirect configuration invalid; contact administrator.');
    }
    header('Referrer-Policy: no-referrer');
    if (!in_array($method, ['GET','HEAD'], true) || preg_match('#/ajax[^/]*\\.php$#', $script) || strcasecmp($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '', 'XMLHttpRequest') === 0) {
        http_response_code(403); header('Cache-Control: no-store'); header('Content-Type: application/json; charset=UTF-8');
        exit(json_encode(['code'=>403,'msg'=>'全站跳转模式下不接受此请求'], JSON_UNESCAPED_UNICODE));
    }
    if ($follow) {
        $url = epay_site_redirect_follow_url($url, $_SERVER['REQUEST_URI'] ?? '', $conf['site_redirect_query_policy'] ?? 'all', $conf['site_redirect_query_allowlist'] ?? '');
        if ($url === null) {
            http_response_code(400); header('Cache-Control: no-store');
            if ($method !== 'HEAD') echo 'Invalid visitor URI.';
            exit;
        }
    }
    if ($status === '302') header('Cache-Control: no-store');
    header('Location: '.$url, true, (int)$status);
    exit;
}

// Private nginx fallback entry: SCRIPT_NAME remains /index.php, never a payment/admin identity.
if (PHP_SAPI !== 'cli' && ($_SERVER['EPAY_SITE_FALLBACK'] ?? '') === '1' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $route = $_SERVER['DOCUMENT_URI'] ?? '';
    if (preg_match('#^/(?:admin|pay|api|paypage|plugins|install|includes|vendor)(?:/|$)#i', $route)) {
        http_response_code(404); exit;
    }
    $nosession = true;
    require __DIR__.'/common.php';
    // The redirect hook exits when enabled. Disabled policy retains local missing-path 404.
    http_response_code(404);
    exit;
}
