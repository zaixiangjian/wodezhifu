<?php
namespace lib;

/** Current checkout switches only; never gates notification, return, refund or provider queries. */
final class PaymentEligibility {
    const MESSAGE = '当前商户、支付方式或支付通道已关闭，无法继续支付';
    private const PLUGINS = ['bepusdt', 'epusdt', 'epusdtapi'];

    private static $adminTestOrder = null;

    private static function adminRoute(string $file): bool {
        global $islogin;
        return $islogin == 1 && session_status() === PHP_SESSION_ACTIVE
            && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(dirname(__DIR__, 2).'/admin/'.$file);
    }

    private static function binding(array $order): array {
        return [(string)($order['trade_no'] ?? ''), (int)($order['uid'] ?? 0),
            (int)($order['tid'] ?? 0), (int)($order['type'] ?? 0),
            (int)($order['channel'] ?? 0), (int)($order['subchannel'] ?? 0)];
    }

    public static function issueAdminTest(array $order): void {
        global $conf;
        if (!self::adminRoute('ajax_pay.php') || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
            || !csrf_verify('admin') || !checkRefererHost() || (int)($order['tid'] ?? 0) !== 3
            || (int)($order['uid'] ?? 0) !== (int)($conf['test_pay_uid'] ?? 0)) {
            throw new \RuntimeException('管理员测试授权失败');
        }
        // Only one outstanding test navigation per browser; no ambient session exemption.
        $_SESSION['epay_admin_test_intent'] = ['binding'=>self::binding($order),
            'expires'=>time()+300, 'actor'=>epay_admin_session_digest($conf)];
    }

    public static function consumeAdminTest(array $order): bool {
        global $conf;
        if (!self::adminRoute('testsubmit.php') || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'
            || !checkRefererHost()) return false;
        $intent = $_SESSION['epay_admin_test_intent'] ?? null;
        unset($_SESSION['epay_admin_test_intent']);
        if (!is_array($intent) || !is_int($intent['expires'] ?? null) || $intent['expires'] < time()
            || ($intent['binding'] ?? null) !== self::binding($order)
            || (int)($order['tid'] ?? 0) !== 3 || (int)($order['uid'] ?? 0) !== (int)($conf['test_pay_uid'] ?? 0)
            || !is_string($intent['actor'] ?? null)
            || !hash_equals(epay_admin_session_digest($conf), $intent['actor'])) return false;
        self::$adminTestOrder = self::binding($order);
        return true;
    }

    private static function isAdminTest(array $order): bool {
        return self::adminRoute('testsubmit.php') && self::$adminTestOrder !== null
            && self::$adminTestOrder === self::binding($order);
    }

    public static function allows($order, $plugin = null): bool {
        global $DB, $conf;
        // Do not change other plugins' existing payment policy.
        if ($plugin !== null && !in_array($plugin, self::PLUGINS, true)) return true;
        try {
            if (!is_array($order)) return false;
            $main = $DB->getRow('SELECT id,type,plugin,status FROM pre_channel WHERE id=:id LIMIT 1', [':id'=>(int)($order['channel'] ?? 0)]);
            if (!$main) return $plugin === null;
            if (!in_array($main['plugin'], self::PLUGINS, true)) return $plugin === null;
            if ($plugin !== null && $plugin !== $main['plugin']) return false;
            $adminTest = self::isAdminTest($order);
            if ((!$adminTest && (int)$main['status'] !== 1) || (int)$main['type'] !== (int)($order['type'] ?? 0)) return false;
            $user = $DB->getRow('SELECT status,pay FROM pre_user WHERE uid=:uid LIMIT 1', [':uid'=>(int)($order['uid'] ?? 0)]);
            // Preserve existing explicit-ban and configured pending-review semantics.
            if (!$user || (int)$user['status'] === 0 || (int)$user['pay'] === 0
                || ((int)$user['pay'] === 2 && (int)($conf['user_review'] ?? 0) === 1)) return false;
            $type = $DB->getRow('SELECT status FROM pre_type WHERE id=:id LIMIT 1', [':id'=>(int)$order['type']]);
            if (!$type || (!$adminTest && (int)$type['status'] !== 1)) return false;
            if ((int)($order['subchannel'] ?? 0) > 0) {
                $sub = $DB->getRow('SELECT channel,uid,status FROM pre_subchannel WHERE id=:id LIMIT 1', [':id'=>(int)$order['subchannel']]);
                if (!$sub || (!$adminTest && (int)$sub['status'] !== 1) || (int)$sub['channel'] !== (int)$main['id'] || (int)$sub['uid'] !== (int)$order['uid']) return false;
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
