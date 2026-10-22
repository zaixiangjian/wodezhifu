<?php
namespace lib;

/** Checked, composable balance transactions; amounts are integer cents internally. */
class Finance
{
    public static function cents($value, $signed = false){
        if (!is_scalar($value) || !preg_match($signed ? '/^-?\d{1,8}(?:\.\d{1,2})?$/D' : '/^\d{1,8}(?:\.\d{1,2})?$/D', (string)$value)) {
            throw new \RuntimeException('金额格式错误（最多两位小数）');
        }
        $text = (string)$value;
        $negative = substr($text, 0, 1) === '-';
        $parts = explode('.', ltrim($text, '-'));
        $n = (int)$parts[0] * 100 + (int)str_pad($parts[1] ?? '', 2, '0');
        return $negative ? -$n : $n;
    }

    public static function amount($cents){
        if (abs($cents) > 9999999999) throw new \RuntimeException('余额超出数据库金额范围');
        return ($cents < 0 ? '-' : '').intdiv(abs($cents), 100).'.'.str_pad((string)(abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function checked($result){
        if ($result === false) throw new \RuntimeException('资金数据库操作失败');
        return $result;
    }

    public static function row($sql, $params = []){
        global $DB;
        return self::checked($DB->query($sql, $params))->fetch(\PDO::FETCH_ASSOC);
    }

    public static function transaction($callback){
        global $DB;
        $own = !$DB->db->inTransaction();
        $savepoint = 'finance_'.bin2hex(random_bytes(6));
        if ($own) self::checked($DB->beginTransaction());
        else self::checked($DB->exec('SAVEPOINT '.$savepoint));
        try {
            $result = $callback();
            if ($own) self::checked($DB->commit());
            else self::checked($DB->exec('RELEASE SAVEPOINT '.$savepoint));
            return $result;
        } catch (\Throwable $e) {
            if ($DB->db->inTransaction()) {
                if ($own) $DB->rollBack();
                else $DB->exec('ROLLBACK TO SAVEPOINT '.$savepoint);
            }
            throw $e;
        }
    }

    /** Deposit ledger uses oldmoney/newmoney for deposit, never the spendable balance. */
    public static function deposit($uid, $money, $trade){
        global $DB;
        $cents = self::cents($money);
        if ($cents <= 0 || !is_scalar($uid) || !preg_match('/^[1-9][0-9]*$/D', (string)$uid) || !$trade) throw new \RuntimeException('保证金充值参数错误');
        return self::transaction(function() use ($DB, $uid, $cents, $trade){
            $user = self::row('SELECT deposit FROM pre_user WHERE uid=:uid FOR UPDATE', [':uid'=>$uid]);
            if (!$user) throw new \RuntimeException('保证金账户不存在');
            $existing = self::row("SELECT money FROM pre_record WHERE uid=:uid AND type='保证金充值' AND trade_no=:trade LIMIT 1 FOR UPDATE", [':uid'=>$uid, ':trade'=>$trade]);
            if ($existing) {
                if (self::cents($existing['money']) !== $cents) throw new \RuntimeException('保证金充值重复金额不一致');
                return true;
            }
            $old = self::cents($user['deposit'] ?? '0.00', true);
            $new = $old + $cents;
            if (self::checked($DB->update('user', ['deposit'=>self::amount($new)], ['uid'=>$uid])) !== 1) throw new \RuntimeException('保证金账户更新失败');
            self::checked($DB->insert('record', ['uid'=>$uid, 'action'=>1, 'money'=>self::amount($cents), 'oldmoney'=>self::amount($old), 'newmoney'=>self::amount($new), 'type'=>'保证金充值', 'trade_no'=>$trade, 'date'=>'NOW()']));
            return true;
        });
    }

    /** Mutate available deposit, never spendable money; callers own business idempotency. */
    public static function changeDeposit($uid, $money, $add, $type, $reference){
        global $DB;
        $cents = self::cents($money);
        if ($cents === 0) return true;
        return self::transaction(function() use ($DB, $uid, $cents, $add, $type, $reference){
            $user = self::row('SELECT deposit FROM pre_user WHERE uid=:uid FOR UPDATE', [':uid'=>$uid]);
            if (!$user) throw new \RuntimeException('保证金账户不存在');
            $old = self::cents($user['deposit'] ?? '0', true);
            $new = $add ? $old + $cents : $old - $cents;
            if ($new < 0) throw new \RuntimeException('可用保证金不足，需核对已提取或冻结资金');
            if (self::checked($DB->update('user', ['deposit'=>self::amount($new)], ['uid'=>$uid])) !== 1) throw new \RuntimeException('保证金账户更新失败');
            self::checked($DB->insert('record', ['uid'=>$uid, 'action'=>$add ? 1 : 2, 'money'=>self::amount($cents), 'oldmoney'=>self::amount($old), 'newmoney'=>self::amount($new), 'type'=>$type, 'trade_no'=>$reference, 'date'=>'NOW()']));
            return true;
        });
    }

    public static function change($uid, $money, $add = true, $type = null, $orderid = null, $requireFunds = false){
        global $DB;
        $cents = self::cents($money);
        if ($cents === 0) return true;
        return self::transaction(function() use ($DB, $uid, $cents, $add, $type, $orderid, $requireFunds){
            $user = self::row('SELECT money FROM pre_user WHERE uid=:uid FOR UPDATE', [':uid'=>$uid]);
            if (!$user) throw new \RuntimeException('资金账户不存在');
            // Serialize the duplicate check with every other mutation of this account.
            if ($type === '代付退回' && $orderid !== null && $orderid !== '') {
                if (self::row("SELECT id FROM pre_record WHERE uid=:uid AND type='代付退回' AND trade_no=:trade LIMIT 1 FOR UPDATE", [':uid'=>$uid, ':trade'=>$orderid])) return true;
            }
            $old = self::cents($user['money'], true);
            $new = $add ? $old + $cents : $old - $cents;
            if (!$add && $requireFunds && $new < 0) throw new \RuntimeException('商户余额不足，请先充值');
            $updated = self::checked($DB->update('user', ['money'=>self::amount($new)], ['uid'=>$uid]));
            if ($updated !== 1) throw new \RuntimeException('资金账户更新失败');
            self::checked($DB->insert('record', ['uid'=>$uid, 'action'=>$add ? 1 : 2, 'money'=>self::amount($cents), 'oldmoney'=>self::amount($old), 'newmoney'=>self::amount($new), 'type'=>$type, 'trade_no'=>$orderid, 'date'=>'NOW()']));
            return true;
        });
    }
}
