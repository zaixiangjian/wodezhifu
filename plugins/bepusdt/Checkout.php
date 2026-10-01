<?php
final class bepusdtCheckout
{
    public static function safeUrl($url, $limit=500): bool {
        if (!is_string($url) || strlen($url)>$limit || preg_match('/[\x00-\x20\x7f\\\\<>"]/', $url)) return false;
        $p = parse_url($url);
        return $p && isset($p['scheme'],$p['host']) && in_array(strtolower($p['scheme']), ['http','https'], true)
            && !isset($p['user']) && !isset($p['pass']) && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
    public static function run(callable $create, bool $remote=true): array {
        global $DB, $order;
        $error = ['type'=>'error','msg'=>'支付初始化未完成，请稍后重试；不确定订单须核对上游后恢复'];
        $lock = null;
        try {
            // MySQL connection-scoped lock: survives local writes, released on disconnect.
            $database = $DB->getColumn('SELECT DATABASE()');
            if (!is_string($database) || $database === '') return $error;
            $lock = 'checkout:'.hash('sha256', $database.'|bepusdt|'.TRADE_NO);
            $lock = substr($lock,0,64);
            if ((string)$DB->getColumn('SELECT GET_LOCK(:name, 10)', [':name'=>$lock]) !== '1') { $lock=null; return $error; }
            $fresh = $DB->getRow('SELECT A.*, B.name AS typename, B.showname AS typeshowname FROM pre_order A LEFT JOIN pre_type B ON A.type=B.id WHERE A.trade_no=:trade_no', [':trade_no'=>TRADE_NO]);
            if (!is_array($fresh) || !is_string($fresh['typename'] ?? null) || $fresh['typename'] === '') return $error;
            require_once __DIR__.'/../../includes/lib/PaymentEligibility.php';
            if (!\lib\PaymentEligibility::allows($fresh, 'bepusdt')) return ['type'=>'error','msg'=>\lib\PaymentEligibility::MESSAGE];
            $order = $fresh;
            if (!empty($fresh['payurl'])) return self::safeUrl($fresh['payurl']) ? ['type'=>'jump','url'=>$fresh['payurl']] : $error;
            $raw = $fresh['ext'] ?? null;
            $ext = ($raw === '' || $raw === null) ? [] : @unserialize($raw, ['allowed_classes'=>false]);
            if (!is_array($ext)) return $error;
            $key = '_bepusdt_checkout';
            if (isset($ext[$key])) {
                $state = $ext[$key];
                if (!is_array($state)) return $error;
                if (isset($state['url']) && self::safeUrl($state['url'])) return self::saveUrl($state['url'], $error);
                // No undocumented query/recreate after timeout, crash or lost gateway response.
                if ($remote) return $error;
            }
            $ext[$key] = ['state'=>'pending'];
            $intent = serialize($ext);
            if (($intent !== $raw && $DB->exec('UPDATE pre_order SET ext=:new WHERE trade_no=:trade_no AND ext <=> :old', [':new'=>$intent, ':trade_no'=>TRADE_NO, ':old'=>$raw]) !== 1)
                || $DB->getColumn('SELECT ext FROM pre_order WHERE trade_no=:trade_no', [':trade_no'=>TRADE_NO]) !== $intent) return $error;
            $result = $create();
            if (!is_array($result) || ($result['type'] ?? '') !== 'jump' || !self::safeUrl($result['url'] ?? null)) return $error;
            // Durable recovery checkpoint before publishing the URL. Never claim a failed save succeeded.
            // Merge concurrent metadata using compare-and-swap, never overwrite a newer ext snapshot.
            $saved = false;
            for ($attempt=0; $attempt<3; $attempt++) {
                $latest = $DB->getColumn('SELECT ext FROM pre_order WHERE trade_no=:trade_no', [':trade_no'=>TRADE_NO]);
                if (!is_string($latest)) return $error;
                $merged = @unserialize($latest, ['allowed_classes'=>false]);
                if (!is_array($merged) || ($merged[$key]['state'] ?? null) !== 'pending') return $error;
                $merged[$key] = ['state'=>'ready','url'=>$result['url']];
                $checkpoint = serialize($merged);
                $written = $DB->exec('UPDATE pre_order SET ext=:new WHERE trade_no=:trade_no AND ext <=> :old', [':new'=>$checkpoint, ':trade_no'=>TRADE_NO, ':old'=>$latest]);
                if ($written === false) return $error;
                if ($written === 1) {
                    $saved = $DB->getColumn('SELECT ext FROM pre_order WHERE trade_no=:trade_no', [':trade_no'=>TRADE_NO]) === $checkpoint;
                    break;
                }
            }
            if (!$saved) return $error;
            return self::saveUrl($result['url'], $error);
        } catch (\Throwable $e) { return $error; }
        finally { if ($lock !== null) { try { $DB->getColumn('SELECT RELEASE_LOCK(:name)', [':name'=>$lock]); } catch (\Throwable $e) {} } }
    }
    private static function saveUrl(string $url, array $error): array {
        global $DB, $order;
        if ($DB->update('order', ['payurl'=>$url], ['trade_no'=>TRADE_NO]) === false
            || $DB->getColumn('SELECT payurl FROM pre_order WHERE trade_no=:trade_no', [':trade_no'=>TRADE_NO]) !== $url) return $error;
        $order['payurl']=$url;
        return ['type'=>'jump','url'=>$url];
    }
}
