<?php

class bepusdt_plugin
{
    public static $info = [
        'name'     => 'bepusdt',
        'showname' => 'BEpusdt 个人加密货币收款',
        'author'   => 'V03413',
        'link'     => 'https://github.com/v03413/BEpusdt',
        'types'    => [
            // 此列表可能存在变动，以此为准 https://github.com/v03413/BEpusdt/blob/main/docs/trade-type.md
            'tron.trx',
            'bsc.bnb',
            'ethereum.eth',
            'usdt.trc20',
            'usdc.trc20',
            'usdt.polygon',
            'usdc.polygon',
            'usdt.arbitrum',
            'usdc.arbitrum',
            'usdt.erc20',
            'usdc.erc20',
            'usdt.bep20',
            'usdc.bep20',
            'usdt.xlayer',
            'usdc.xlayer',
            'usdc.base',
            'usdt.solana',
            'usdc.solana',
            'usdt.aptos',
            'usdc.aptos',
            'usdt.plasma',
        ],
        'inputs'   => [
            'appurl'  => [
                'name' => '接口地址',
                'type' => 'input',
                'note' => '必须以http://或https://开头，以/结尾',
            ],
            'appkey'  => [
                'name' => '认证Token',
                'type' => 'input',
                'note' => '搭建BEpusdt时填写的 auth_token 参数',
            ],
            'address' => [
                'name' => '收款地址',
                'type' => 'input',
                'note' => '可以留空 留空则由BEpusdt自动分配，切勿乱填 注意空格',
            ],
            'timeout' => [
                'name' => '订单超时',
                'type' => 'input',
                'note' => '可以留空 填写整数(单位秒)、推荐 1200',
            ],
            'rate'    => [
                'name' => '订单汇率',
                'type' => 'input',
                'note' => '可以留空 例如：7.4 ~1.02 ~0.98（不明白切勿乱填）',
            ],
            'fiat'    => [
                'name'    => '交易法币',
                'type'    => 'select',
                'options' => [
                    'CNY' => '人民币 (CNY)',
                    'USD' => '美元 (USD)',
                    'EUR' => '欧元 (EUR)',
                    'GBP' => '英镑 (GBP)',
                    'JPY' => '日元 (JPY)',
                ],
            ],
        ],
        'select'   => null,
        'note'     => '', //支付密钥填写说明
    ];

    public static function submit(): array
    {
        require_once __DIR__.'/Checkout.php';
        return bepusdtCheckout::run(static function () { return self::createPayment(); }, true);
    }

    private static function createPayment(): array
    {
        global $siteurl, $channel, $order, $conf;
        // Loader metadata (notably apptype) may be an array; validate only consumed config.
        foreach (['appurl', 'appkey'] as $key) {
            if (!isset($channel[$key]) || !is_scalar($channel[$key])) return ['type'=>'error','msg'=>'Invalid channel configuration'];
        }
        foreach (['fiat', 'address', 'timeout', 'rate'] as $key) {
            if (isset($channel[$key]) && !is_scalar($channel[$key])) return ['type'=>'error','msg'=>'Invalid channel configuration'];
        }
        if (!is_string($order['realmoney']) && !is_int($order['realmoney'])) return ['type'=>'error','msg'=>'Invalid fiat amount'];
        if (!preg_match('/\A[0-9]+(?:\.[0-9]{1,2})?\z/', (string)$order['realmoney'])) return ['type'=>'error','msg'=>'Invalid fiat amount'];
        // Deployed Go float64 API requires JSON numbers. Bound to non-exponent range
        // and verify round-trip equality before signing the same numeric value.
        $amount = (float)$order['realmoney'];
        if ($amount >= 1000000 || !self::moneyMatch($amount, $order['realmoney'])) return ['type'=>'error','msg'=>'Unsupported fiat amount'];

        $parameter              = [
            'fiat'         => trim((string)($channel['fiat'] ?? '')),
            'address'      => trim((string)($channel['address'] ?? '')),
            'trade_type'   => $order['typename'],
            'order_id'     => TRADE_NO,
            'name'         => $order['name'],
            'timeout'      => intval($channel['timeout'] ?? 0),
            'rate'         => strval($channel['rate'] ?? ''),
            'amount'       => (float)$order['realmoney'],
            'notify_url'   => $conf['localurl'] . 'pay/notify/' . TRADE_NO . '/',
            'redirect_url' => $siteurl . 'pay/return/' . TRADE_NO . '/',
        ];
        $parameter['signature'] = self::_toSign($parameter, $channel['appkey']);

        $url  = trim($channel['appurl']) . 'api/v1/order/create-transaction';
        $data = self::_post($url, $parameter);
        if (!is_array($data)) {

            return ['type' => 'error', 'msg' => '请求失败，请检查服务器是否能正常请求 BEpusdt 网关！'];
        }

        if (!self::validScalars(array_diff_key($data, ['data'=>true])) || (string)($data['status_code'] ?? '') !== '200') {

            return ['type' => 'error', 'msg' => '请求失败，错误信息：' . (string)($data['message'] ?? '')];
        }

        return ['type' => 'jump', 'url' => ($data['data']['payment_url'] ?? null)];
    }

    public static function notify()
    {
        global $channel, $order;

        if (ob_get_level() > 0) ob_clean();
        header('Content-Type: text/plain; charset=utf-8');

        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data) || !self::validScalars($data)) exit('fail - data error');
        $sign = $data['signature'] ?? '';
        if (!is_string($sign) || !preg_match('/\A[0-9a-f]{32}\z/', $sign)
            || !hash_equals(self::_toSign($data, $channel['appkey']), $sign)) {
            // 签名验证失败

            exit('fail - sign error');
        }

        $out_trade_no = $data['order_id'] ?? '';
        $trade_no = $data['trade_id'] ?? '';
        // Upstream EpNotify does not include buyer; retain it only when supplied.
        $buyer = mb_substr((string)($data['buyer'] ?? ''), -28);
        if (($data['status'] ?? null) === 2 && $out_trade_no === TRADE_NO
            && is_string($trade_no) && $trade_no !== ''
            && self::moneyMatch($data['amount'] ?? null, $order['realmoney'])
            && (!array_key_exists('fiat', $data) || $data['fiat'] === ($channel['fiat'] ?: 'CNY'))
            && (!array_key_exists('trade_type', $data) || $data['trade_type'] === $order['typename'])) {
            processNotify($order, $trade_no, $buyer);

            exit('ok');
        }

        exit('fail - status error');
    }

    // Fiat order amounts: compare exact decimal values, never round underpayments up.
    private static function moneyMatch($paid, $expected): bool
    {
        $normalize = static function ($value) {
            if (!is_string($value) && !is_int($value) && !is_float($value)) return null;
            $value = (string)$value;
            if (!preg_match('/\A[0-9]+(?:\.[0-9]+)?\z/', $value)) return null;
            $parts = explode('.', $value, 2);
            return (ltrim($parts[0], '0') ?: '0') . '.' . rtrim($parts[1] ?? '', '0');
        };
        $left = $normalize($paid);
        return $left !== null && $left !== '0.' && $left === $normalize($expected);
    }

    private static function validScalars(array $data): bool
    {
        foreach ($data as $value) {
            if ($value !== null && !is_string($value) && !is_int($value) && !is_float($value)) return false;
            if (is_float($value) && !is_finite($value)) return false;
        }
        return true;
    }

    public static function return(): array
    {
        return ['type' => 'page', 'page' => 'return'];
    }

    private static function _toSign(array $parameter, string $token): string
    {
        ksort($parameter);

        $sign = '';

        foreach ($parameter as $key => $val) {
            if ($val == '') continue;
            if ($key != 'signature') {
                if ($sign != '') {
                    $sign .= "&";

                }

                $sign .= "$key=$val";
            }
        }

        return md5($sign . $token);
    }

    private static function _post(string $url, array $json)
    {

        $header[] = 'Accept: */*';
        $header[] = 'Accept-Language: zh-CN,zh;q=0.8';
        $header[] = 'Connection: close';
        $header[] = 'Content-Type: application/json';

        $ch = curl_init();
        if (!function_exists('epay_prepare_outbound_curl') || !epay_prepare_outbound_curl($ch, $url)) { curl_close($ch); return null; }
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HEADER, false);
        $resp = curl_exec($ch);
        curl_close($ch);

        return json_decode($resp, true);
    }
}