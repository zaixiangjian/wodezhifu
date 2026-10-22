<?php
namespace lib;

class Order
{
    private static function account($row){
        if (in_array((int)$row['tid'], [2,5], true)) {
            $param = json_decode($row['param'], true);
            if (!is_array($param) || !isset($param['uid']) || !is_scalar($param['uid']) || !preg_match('/^[1-9][0-9]*$/D', (string)$param['uid'])) throw new \RuntimeException('充值订单资金账户无效');
            return (int)$param['uid'];
        }
        return $row['uid'];
    }

    /** Reject legacy credits/refunds that cannot establish a deposit liability. */
    private static function depositPrincipal($row, $account){
        if (Finance::cents($row['money']) !== Finance::cents($row['realmoney'])) throw new \RuntimeException('保证金本金与实付金额不同，退款本金规则需人工核对');
        $credit = Finance::row("SELECT money FROM pre_record WHERE uid=:uid AND trade_no=:trade AND type='保证金充值' LIMIT 1 FOR UPDATE", [':uid'=>$account, ':trade'=>$row['trade_no']]);
        if (!$credit || Finance::cents($credit['money']) !== Finance::cents($row['money'])) throw new \RuntimeException('保证金入账流水缺失或金额不符，需人工核对');
        $reserved = Finance::row("SELECT COALESCE(SUM(r.money),0) AS money FROM pre_record r INNER JOIN pre_refundorder f ON f.refund_no=r.trade_no WHERE r.uid=:uid AND r.type='保证金退款预留' AND f.trade_no=:trade AND f.status=1", [':uid'=>$account, ':trade'=>$row['trade_no']]);
        if (Finance::cents($reserved['money']) !== Finance::cents($row['refundmoney'] ?? '0')) throw new \RuntimeException('历史保证金退款账本不一致，需人工核对');
    }

    private static function depositFrozen($row, $account, $remaining){
        $held = Finance::row("SELECT COALESCE(SUM(CASE WHEN action=2 THEN money ELSE -money END),0) AS money FROM pre_record WHERE uid=:uid AND trade_no=:trade AND type IN ('保证金订单冻结','保证金订单解冻','保证金退款释放冻结')", [':uid'=>$account, ':trade'=>$row['trade_no']]);
        if (Finance::cents($held['money'], true) !== $remaining) throw new \RuntimeException('历史冻结未建立保证金账本，需人工核对');
    }

    private static function lockedOrder($trade_no, $uid = 0){
        $row = Finance::row('SELECT * FROM pre_order WHERE trade_no=:trade FOR UPDATE', [':trade'=>$trade_no]);
        if (!$row || ($uid > 0 && $row['uid'] != $uid)) throw new \RuntimeException('当前订单不存在');
        return $row;
    }

    private static function pending($trade_no){
        if (Finance::row('SELECT refund_no FROM pre_refundorder WHERE trade_no=:trade AND status<>1 LIMIT 1 FOR UPDATE', [':trade'=>$trade_no])) {
            throw new \RuntimeException('订单存在待核对退款，禁止继续资金操作');
        }
    }

    public static function freeze($trade_no){ return self::setFrozen($trade_no, true); }
    public static function unfreeze($trade_no){ return self::setFrozen($trade_no, false); }

    private static function setFrozen($trade_no, $freeze){
        global $DB;
        try {
            return Finance::transaction(function() use ($DB, $trade_no, $freeze){
                $row = self::lockedOrder($trade_no);
                self::pending($trade_no);
                if (!($freeze ? ((int)$row['status'] === 1 || ((int)$row['tid'] === 5 && (int)$row['status'] === 2)) : (int)$row['status'] === 3)) throw new \RuntimeException('当前订单状态不支持此操作');
                if ((int)$row['tid'] === 5) {
                    $account = self::account($row);
                    self::depositPrincipal($row, $account);
                    $remaining = Finance::cents($row['money']) - Finance::cents($row['refundmoney'] ?? '0');
                    if ($remaining <= 0) throw new \RuntimeException('保证金订单无剩余本金');
                    if (!$freeze) self::depositFrozen($row, $account, $remaining);
                    Finance::changeDeposit($account, Finance::amount($remaining), !$freeze, $freeze ? '保证金订单冻结' : '保证金订单解冻', $trade_no);
                    if (Finance::checked($DB->update('order', ['status'=>$freeze ? 3 : (Finance::cents($row['refundmoney'] ?? '0') > 0 ? 2 : 1)], ['trade_no'=>$trade_no])) !== 1) throw new \RuntimeException('订单状态更新失败');
                    return ['code'=>0, 'msg'=>$freeze ? '保证金订单冻结成功' : '保证金订单解冻成功'];
                }
                if (Finance::cents($row['refundmoney'] ?? '0') > 0) throw new \RuntimeException('部分退款订单暂不支持冻结/解冻，需核对');
                $channel = Finance::row('SELECT mode FROM pre_channel WHERE id=:id', [':id'=>$row['channel']]);
                if (!$channel || $channel['mode'] == 1) throw new \RuntimeException('通道不存在或商户直清不支持冻结');
                Finance::change(self::account($row), $row['getmoney'], !$freeze, $freeze ? '订单冻结' : '订单解冻', $trade_no);
                if (Finance::checked($DB->update('order', ['status'=>$freeze ? 3 : 1], ['trade_no'=>$trade_no])) !== 1) throw new \RuntimeException('订单状态更新失败');
                return ['code'=>0, 'msg'=>$freeze ? '订单冻结成功' : '订单解冻成功'];
            });
        } catch (\Throwable $e) { return ['code'=>-1, 'msg'=>$e->getMessage()]; }
    }

    private static function refundResult($record, $row){
        return ['code'=>0, 'account_uid'=>self::account($row), 'account_type'=>(int)$row['tid'] === 5 ? 'deposit' : 'money', 'refund_no'=>$record['refund_no'], 'out_refund_no'=>$record['out_refund_no'], 'trade_no'=>$record['trade_no'], 'out_trade_no'=>$row['out_trade_no'], 'uid'=>$record['uid'], 'money'=>$record['money'], 'reducemoney'=>$record['reducemoney']];
    }

    public static function refund($refund_no, $trade_no, $money, $api = 0, $uid = 0, $out_refund_no = null){
        global $DB, $order, $conf;
        $gatewayStarted = false;
        try {
            if (!is_string($refund_no) || !preg_match('/^[A-Za-z0-9_-]{1,19}$/D', $refund_no)) throw new \RuntimeException('退款单号格式错误');
            if ($out_refund_no === null) $out_refund_no = $refund_no;
            if (!is_string($out_refund_no) || !preg_match('/^[A-Za-z0-9_-]{1,150}$/D', $out_refund_no)) throw new \RuntimeException('商户退款单号格式错误');
            $cents = Finance::cents($money);
            if ($cents <= 0) throw new \RuntimeException('退款金额必须大于零');
            // Gateway intent must be durable, not nested in an uncommitted caller transaction.
            if ($DB->db->inTransaction()) throw new \RuntimeException('退款必须在独立事务中调用');
            $prepared = Finance::transaction(function() use ($DB, $refund_no, $out_refund_no, $trade_no, $cents, $uid, $api, &$order, $conf){
                $order = self::lockedOrder($trade_no, $uid);
                $account = self::account($order);
                // Also serializes out_refund_no across different orders of the same merchant.
                if (!Finance::row('SELECT uid FROM pre_user WHERE uid=:uid FOR UPDATE', [':uid'=>$order['uid']])) throw new \RuntimeException('商户不存在');
                $existing = Finance::row('SELECT * FROM pre_refundorder WHERE refund_no=:refund OR (uid=:uid AND out_refund_no=:outno) LIMIT 1 FOR UPDATE', [':refund'=>$refund_no, ':uid'=>$order['uid'], ':outno'=>$out_refund_no]);
                if ($existing) {
                    if ($existing['trade_no'] !== $trade_no || Finance::cents($existing['money']) !== $cents || $existing['out_refund_no'] !== $out_refund_no) throw new \RuntimeException('退款单号已用于不同请求');
                    if ((int)$existing['status'] !== 1) throw new \RuntimeException('退款处理中或结果待核对，禁止重复请求');
                    return ['done'=>self::refundResult($existing, $order)];
                }
                self::pending($trade_no);
                if (!in_array((int)$order['status'], [1,2,3], true)) throw new \RuntimeException('该订单状态不支持退款');
                $total = Finance::cents($order['realmoney']);
                $refunded = Finance::cents($order['refundmoney'] ?? '0');
                if ($order['status'] == 2 && $refunded === 0) throw new \RuntimeException('历史退款缺少累计金额，需人工核对');
                if ($cents > $total - $refunded) throw new \RuntimeException('退款金额超过剩余可退金额');
                $channel = Finance::row('SELECT mode FROM pre_channel WHERE id=:id', [':id'=>$order['channel']]);
                if (!$channel) throw new \RuntimeException('支付通道不存在');
                if ($api == 1 && empty($order['api_trade_no'])) throw new \RuntimeException('接口订单号不存在');
                $deposit = (int)$order['tid'] === 5;
                if ($deposit) self::depositPrincipal($order, $account);
                $get = Finance::cents($order['getmoney']);
                // Fee-free refund liability is capped cumulatively, not per request.
                $fee = !empty($conf['refund_fee_type']);
                $reduce = $channel['mode'] == 1 ? 0 : ($fee ? $cents : min($refunded + $cents, $get) - min($refunded, $get));
                if ($deposit) $reduce = $cents;
                $release = !$deposit && $order['status'] == 3 ? $get : 0;
                if ($release && $refunded) throw new \RuntimeException('历史冻结退款订单需人工核对');
                $user = Finance::row('SELECT money FROM pre_user WHERE uid=:uid FOR UPDATE', [':uid'=>$account]);
                if (!$user) throw new \RuntimeException('资金账户不存在');
                if (!$deposit && $uid > 0 && Finance::cents($user['money'], true) + $release < $reduce) throw new \RuntimeException('商户余额不足，请先充值');
                if ($deposit && (int)$order['status'] === 3) self::depositFrozen($order, $account, $total - $refunded);
                $record = ['refund_no'=>$refund_no, 'out_refund_no'=>$out_refund_no, 'trade_no'=>$trade_no, 'uid'=>$order['uid'], 'money'=>Finance::amount($cents), 'reducemoney'=>Finance::amount($reduce), 'addtime'=>date('Y-m-d H:i:s'), 'status'=>0];
                Finance::checked($DB->insert('refundorder', $record));
                // Reserve the exact deposit liability atomically with the durable intent.
                if ($deposit) {
                    if ((int)$order['status'] === 3) Finance::changeDeposit($account, Finance::amount($cents), true, '保证金退款释放冻结', $trade_no);
                    Finance::changeDeposit($account, Finance::amount($cents), false, '保证金退款预留', $refund_no);
                }
                $plan = ['deposit'=>$deposit, 'depositRelease'=>$deposit && (int)$order['status'] === 3 ? $total - $refunded - $cents : 0, 'record'=>$record, 'account'=>$account, 'release'=>$release, 'refundmoney'=>$refunded + $cents, 'mode'=>$channel['mode'], 'fee'=>$fee, 'total'=>$total];
                if ($api != 1) return ['done'=>self::finishRefund($plan, $order, $uid)];
                return $plan;
            });
            if (isset($prepared['done'])) return $prepared['done'];
            // A persisted status=0 intent blocks retries even after process/connection death.
            $gatewayStarted = true;
            $message = null;
            if (!Plugin::refund($refund_no, $trade_no, Finance::amount($cents), $message)) {
                return ['code'=>-2, 'msg'=>'网关退款未确认，已保留待核对记录；禁止自动重试', 'refund_no'=>$refund_no];
            }
            return Finance::transaction(function() use ($prepared, $trade_no, $uid, &$order){
                $order = self::lockedOrder($trade_no, $uid);
                return self::finishRefund($prepared, $order, $uid);
            });
        } catch (\Throwable $e) {
            return ['code'=>$gatewayStarted ? -2 : -1, 'msg'=>$gatewayStarted ? '网关已调用但本地未确认，必须人工核对，禁止重试' : $e->getMessage(), 'refund_no'=>$refund_no];
        }
    }

    private static function finishRefund($plan, $row, $uid){
        global $DB;
        $record = $plan['record'];
        $trade = $record['trade_no'];
        if ($plan['depositRelease'] > 0) Finance::changeDeposit($plan['account'], Finance::amount($plan['depositRelease']), true, '保证金退款释放冻结', $trade);
        if (!$plan['deposit'] && $plan['release'] > 0) Finance::change($plan['account'], Finance::amount($plan['release']), true, '退款释放冻结', $trade);
        if (!$plan['deposit']) Finance::change($plan['account'], $record['reducemoney'], false, '订单退款', $trade, $uid > 0);
        if (!$plan['deposit'] && $plan['mode'] == 1 && !$plan['fee'] && $plan['refundmoney'] >= $plan['total']) {
            $feeRecord = Finance::row("SELECT money FROM pre_record WHERE uid=:uid AND trade_no=:trade AND type IN ('订单服务费','在线收款服务费') LIMIT 1 FOR UPDATE", [':uid'=>$plan['account'], ':trade'=>$trade]);
            if ($feeRecord) Finance::change($plan['account'], $feeRecord['money'], true, '服务费退款', $trade);
        }
        if (Finance::checked($DB->update('order', ['status'=>2, 'refundmoney'=>Finance::amount($plan['refundmoney'])], ['trade_no'=>$trade])) !== 1) throw new \RuntimeException('订单退款累计更新失败');
        if (Finance::checked($DB->update('refundorder', ['status'=>1, 'endtime'=>date('Y-m-d H:i:s')], ['refund_no'=>$record['refund_no'], 'status'=>0])) !== 1) throw new \RuntimeException('退款记录确认失败');
        return self::refundResult($record, $row);
    }


    public static function refund_info($trade_no, $api = 0, $uid = 0){
        global $DB;
        $where = ['trade_no'=>$trade_no];
        if($uid > 0) $where['uid'] = $uid;
        $order = $DB->find('order', '*', $where);
        if(!$order)
            return ['code'=>-1, 'msg'=>'当前订单不存在！'];
        if(!in_array($order['status'], [1,2,3]))
            return ['code'=>-1, 'msg'=>'该订单状态不支持退款！'];
        if($order['status'] == 2 && empty($order['refundmoney'])) return ['code'=>-1, 'msg'=>'该订单已退款！'];
        if($order['status'] == 2 && $order['refundmoney'] > 0 && $order['refundmoney'] >= $order['realmoney']) return ['code'=>-1, 'msg'=>'该订单已全额退款！'];
        $money = !empty($order['refundmoney']) ? round($order['realmoney'] - $order['refundmoney'], 2) : $order['realmoney'];

        if($api==1){
            if(!$order['api_trade_no']) return ['code'=>-1, 'msg'=>'接口订单号不存在'];
            $channel = \lib\Channel::get($order['channel']);
            if(!$channel) return ['code'=>-1, 'msg'=>'当前支付通道信息不存在'];
            if(\lib\Plugin::isrefund($channel['plugin'])==false){
                return ['code'=>-1, 'msg'=>'当前支付通道不支持API退款'];
            }
        }

        return ['code'=>0, 'money'=>$money];
    }

    public static function close($trade_no, $uid = 0){
        global $DB, $order, $conf;

        $where = ['trade_no'=>$trade_no];
        if($uid > 0) $where['uid'] = $uid;
        $order = $DB->find('order', '*', $where);
        if(!$order)
            return ['code'=>-1, 'msg'=>'当前订单不存在！'];
        if($order['status'] != 0)
            return ['code'=>-1, 'msg'=>'该订单状态不支持关闭！'];

        if(!\lib\Plugin::close($trade_no, $message)){
            return ['code'=>-1, 'msg'=>$message];
        }
        return ['code'=>0];
    }
}