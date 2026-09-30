<?php
namespace lib;

use Exception;

class Payment {

    private static function safeResultUrl($url, $paymentScheme = false){
        if (!is_string($url) || $url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) throw new Exception('支付跳转地址无效');
        if (substr($url, 0, 2) === '//') throw new Exception('支付跳转地址无效');
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme !== null && $scheme !== false) {
            $allowed = $paymentScheme ? ['http','https','weixin','alipays','alipay','mqqapi','qqwallet','unionpay','upwrp'] : ['http','https'];
            if (!in_array(strtolower($scheme), $allowed, true)) throw new Exception('支付跳转协议无效');
            if (in_array(strtolower($scheme), ['http','https'], true) && (!parse_url($url, PHP_URL_HOST) || parse_url($url, PHP_URL_USER) !== null)) throw new Exception('支付跳转地址无效');
        }
        return $url;
    }

    private static function resultPage($page){
        if (!is_string($page) || !preg_match('/^[a-zA-Z0-9_-]+$/D', $page)) throw new Exception('支付页面无效');
        $root = realpath(PAYPAGE_ROOT);
        $file = realpath(PAYPAGE_ROOT.$page.'.php');
        if (!$root || !$file || !is_file($file) || strpos($file, $root.DIRECTORY_SEPARATOR) !== 0) throw new Exception('支付页面无效');
        return $file;
    }

    //生成待签名字符串
    static private function getSignContent($data){
        ksort($data);
        $signStr = '';
        foreach ($data as $k => $v) {
            if(is_array($v) || isEmpty($v) || $k == 'sign' || $k == 'sign_type') continue;
            $signStr .= $k . '=' . $v . '&';
        }
        $signStr = substr($signStr, 0, -1);
        return $signStr;
    }

    //生成签名
    static public function makeSign($data, $md5key) {
        $sign_type = !empty($data['sign_type']) ? $data['sign_type'] : 'MD5';
        $signStr = self::getSignContent($data);
        if($sign_type == 'RSA'){
            global $conf;
            $private_key = base64ToPem($conf['private_key'], 'PRIVATE KEY');
            $pkey = openssl_pkey_get_private($private_key);
            if(!$pkey) return false;
            openssl_sign($signStr, $sign, $pkey, OPENSSL_ALGO_SHA256);
            return base64_encode($sign);
        }else{
            $sign = md5($signStr . $md5key);
            return $sign;	
        }
    }

    //验证签名
    static public function verifySign($data, $md5key, $publicKey) {
        if(!isset($data['sign'])) throw new Exception('缺少签名参数');
        $sign_type = !empty($data['sign_type']) ? $data['sign_type'] : 'MD5';
        if($sign_type == 'RSA'){
            $public_key = base64ToPem($publicKey, 'PUBLIC KEY');
            $pkey = openssl_pkey_get_public($public_key);
            if(!$pkey) throw new Exception('签名校验失败，商户公钥错误');
            $signStr = self::getSignContent($data);
            $result = openssl_verify($signStr, base64_decode($data['sign']), $pkey, OPENSSL_ALGO_SHA256);
            return $result === 1;
        }else{
            $sign = self::makeSign($data, $md5key);
            return $sign === $data['sign'];
        }
    }

    // 页面支付返回信息
    static public function echoDefault($result){
        global $cdnpublic,$order,$conf,$sitename,$ordername,$siteurl;
        $type = $result['type'];
        if (in_array($type, ['jump','qrcode','scheme'], true)) $result['url'] = self::safeResultUrl($result['url'], $type !== 'jump');
        if ($type === 'return') $result['url'] = self::safeResultUrl($result['url']);
        if(!$type) return false;
        switch($type){
            case 'jump': //跳转
                $selfurl = is_self_url($result['url']);
                $html_text = '<script>window.location.replace('.json_encode(self::safeResultUrl($result['url']), JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).');</script>';
                if(!isset($result['submit']) && is_url($result['url']) && !$selfurl && ($conf['wxpay_qrpaylogin'] == 1 && checkwechat()) || ($conf['alipay_qrpaylogin'] == 1 && checkalipay())){
                    self::updateOrderPayUrl(TRADE_NO, $result['url']);
                    $result['url'] = $siteurl.'pay/checkpay/'.TRADE_NO.'/';
                }
                if(isset($result['submit']) && $result['submit']){
                    submitTemplate($html_text);
                }else{
                    echo $html_text;
                }
                break;
            case 'html': //显示html
                $html_text = $result['data'];
                if(isset($result['submit']) && $result['submit'] && substr($html_text, 0, 6) == '<form '){
                    submitTemplate($html_text);
                }else{
                    echo $html_text;
                }
                break;
            case 'json': //显示JSON
                echo json_encode($result['data']);
                break;
            case 'page': //显示指定页面
                include_once SYSTEM_ROOT.'txprotect.php';
                if(isset($result['data'])) extract($result['data']);
                if($conf['pageordername']==1)$order['name']=$ordername?$ordername:'onlinepay';
                include self::resultPage($result['page']);
                break;
            case 'qrcode': //扫码页面
                $selfurl = is_self_url($result['url']);
                if($result['page'] == 'alipay_qrcode' && !empty($conf['alipay_qrcode_url'])){
                    $siteurl = $conf['alipay_qrcode_url'];
                    if(strpos($result['url'], $siteurl)===0){
                        $result['url'] = $siteurl.substr($result['url'], strlen($siteurl));
                    }elseif(!empty($conf['localurl_alipay']) && strpos($result['url'], $conf['localurl_alipay'])===0){
                        $result['url'] = $siteurl.substr($result['url'], strlen($conf['localurl_alipay']));
                    }
                }
                if(in_array($result['page'], ['wxpay_qrcode', 'wxpay_wap']) && !empty($conf['wxpay_qrcode_url'])){
                    $siteurl = $conf['wxpay_qrcode_url'];
                    if(strpos($result['url'], $siteurl)===0){
                        $result['url'] = $siteurl.substr($result['url'], strlen($siteurl));
                    }elseif(!empty($conf['localurl_wxpay']) && strpos($result['url'], $conf['localurl_wxpay'])===0){
                        $result['url'] = $siteurl.substr($result['url'], strlen($conf['localurl_wxpay']));
                    }
                }
                if($conf['check_pay_regoin'] > 0 && is_url($result['url']) && in_array($result['page'], ['alipay_qrcode', 'wxpay_qrcode', 'wxpay_wap'])
                    || is_url($result['url']) && !$selfurl && $conf['wxpay_qrpaylogin'] == 1 && in_array($result['page'], ['wxpay_qrcode', 'wxpay_wap'])
                    || is_url($result['url']) && !$selfurl && $conf['alipay_qrpaylogin'] == 1 && $result['page'] == 'alipay_qrcode'){
                    self::updateOrderPayUrl(TRADE_NO, $result['url']);
                    $result['url'] = $siteurl.'pay/checkpay/'.TRADE_NO.'/';
                }
            case 'scheme': //跳转urlscheme页面
                if($result['page'] == 'wxpay_mini') $result['page'] = 'wxpay_h5';
                include_once SYSTEM_ROOT.'txprotect.php';
                $code_url = $result['url'];
                if($conf['pageordername']==1)$order['name']=$ordername?$ordername:'onlinepay';
                if($conf['wework_payopen'] == 1 && ($result['page'] == 'wxpay_wap' && checkwechat()===false || $result['page'] == 'wxpay_qrcode' && checkmobile())){
                    $code_url_wxkf = self::getWxkfPayUrl($code_url);
                    if($code_url_wxkf){
                        $code_url = $code_url_wxkf;
                        $code_url2 = $result['url'];
                        include PAYPAGE_ROOT.'wxpay_h5.php';
                        break;
                    }
                }
                if($conf['wxappkf_payopen'] == 1 && $conf['wxappkf_applet'] > 0 && ($result['page'] == 'wxpay_wap' && checkwechat()===false || $result['page'] == 'wxpay_qrcode' && checkmobile())){
                    $code_url_wxkf = self::getWxappkfPayUrl($code_url);
                    if($code_url_wxkf){
                        $code_url = $code_url_wxkf;
                        $code_url2 = $result['url'];
                        include PAYPAGE_ROOT.'wxpay_h5.php';
                        break;
                    }
                }
                include self::resultPage($result['page']);
                break;
            case 'return': //同步回调
                returnTemplate($result['url']);
                break;
            case 'error': //错误提示
                $checkerr = self::check_error_msg($result['msg']);
                if($checkerr && $conf['check_paymsg_retry'] == 1 && $order['tid']!=3){
                    $returnurl = '/submit2.php?typeid='.$order['type'].'&trade_no='.$order['trade_no'].'&retry=1';
                    exit('<script>alert("当前支付通道异常，点击确定后重新下单");window.location.href="'.$returnurl.'";</script>');
                }
                sysmsg($result['msg']);
                break;
            default:break;
        }
    }

    // API支付返回信息
    static public function echoJson($result){
        global $order,$siteurl,$conf;
        if(!$result) return false;
        $type = $result['type'];
        if (in_array($type, ['jump','qrcode','scheme'], true)) $result['url'] = self::safeResultUrl($result['url'], $type !== 'jump');
        if ($type === 'return') $result['url'] = self::safeResultUrl($result['url']);
        if(!$type) return false;
        if(defined('API_INIT')){
            $json = ['code'=>0, 'trade_no'=>TRADE_NO];
            switch($type){
                case 'jump': //跳转URL
                    $json['pay_type'] = 'jump';
                    $json['pay_info'] = $result['url'];
                    break;
                case 'html': //显示html跳转
                    $json['pay_type'] = 'html';
                    $json['pay_info'] = $result['data'];
                    break;
                case 'qrcode': //扫码支付
                    $selfurl = is_self_url($result['url']);
                    if($result['page'] == 'alipay_qrcode' && !empty($conf['alipay_qrcode_url'])){
                        $siteurl = $conf['alipay_qrcode_url'];
                        if(strpos($result['url'], $siteurl)===0){
                            $result['url'] = $siteurl.substr($result['url'], strlen($siteurl));
                        }elseif(!empty($conf['localurl_alipay']) && strpos($result['url'], $conf['localurl_alipay'])===0){
                            $result['url'] = $siteurl.substr($result['url'], strlen($conf['localurl_alipay']));
                        }
                    }
                    if(in_array($result['page'], ['wxpay_qrcode', 'wxpay_wap']) && !empty($conf['wxpay_qrcode_url'])){
                        $siteurl = $conf['wxpay_qrcode_url'];
                        if(strpos($result['url'], $siteurl)===0){
                            $result['url'] = $siteurl.substr($result['url'], strlen($siteurl));
                        }elseif(!empty($conf['localurl_wxpay']) && strpos($result['url'], $conf['localurl_wxpay'])===0){
                            $result['url'] = $siteurl.substr($result['url'], strlen($conf['localurl_wxpay']));
                        }
                    }
                    if($conf['check_pay_regoin'] > 0 && is_url($result['url']) && in_array($result['page'], ['alipay_qrcode', 'wxpay_qrcode', 'wxpay_wap'])
                    || is_url($result['url']) && !$selfurl && $conf['wxpay_qrpaylogin'] == 1 && in_array($result['page'], ['wxpay_qrcode', 'wxpay_wap'])
                    || is_url($result['url']) && !$selfurl && $conf['alipay_qrpaylogin'] == 1 && $result['page'] == 'alipay_qrcode'){
                        self::updateOrderPayUrl(TRADE_NO, $result['url']);
                        $result['url'] = $siteurl.'pay/checkpay/'.TRADE_NO.'/';
                    }
                    $json['pay_type'] = 'qrcode';
                    $json['pay_info'] = $result['url'];
                    break;
                case 'scheme': //小程序H5跳转
                    $json['pay_type'] = 'urlscheme';
                    $json['pay_info'] = $result['url'];
                    break;
                case 'jsapi': //JSAPI支付
                    $json['pay_type'] = 'jsapi';
                    $json['pay_info'] = $result['data'];
                    break;
                case 'app': //APP支付
                    $json['pay_type'] = 'app';
                    $json['pay_info'] = $result['data'];
                    break;
                case 'scan': //付款码支付
                    $json['pay_type'] = 'scan';
                    $json['pay_info'] = $result['data'];
                    break;
                case 'wxplugin': //微信小程序插件支付
                    $json['pay_type'] = 'wxplugin';
                    $json['pay_info'] = $result['data'];
                    break;
                case 'wxapp': //跳转微信小程序支付
                    $json['pay_type'] = 'wxapp';
                    $json['pay_info'] = $result['data'];
                    break;
                case 'error':
                    $checkerr = self::check_error_msg($result['msg']);
                    if($checkerr && $conf['check_paymsg_retry'] == 1 && $order['tid']!=3){
                        $returnurl = $siteurl.'submit2.php?typeid='.$order['type'].'&trade_no='.$order['trade_no'].'&retry=1';
                        $json['pay_type'] = 'jump';
                        $json['pay_info'] = $returnurl;
                    }else{
                        $json['code'] = -2;
                        $json['msg'] = $result['msg'];
                    }
                    break;
                default:
                    $json['pay_type'] = 'jump';
                    $json['pay_info'] = $siteurl.'pay/submit/'.TRADE_NO.'/';
                    break;
            }
            if($json['code'] == 0){
                if(is_array($json['pay_info'])) $json['pay_info'] = json_encode($json['pay_info']);
                $json['timestamp'] = time().'';
                $json['sign_type'] = 'RSA';
                $json['sign'] = self::makeSign($json, null);
            }
            exit(json_encode($json));
        }else{
            $json = ['code'=>1, 'trade_no'=>TRADE_NO];
            switch($type){
                case 'jump': //跳转URL
                    $json['payurl'] = $result['url'];
                    break;
                case 'html': //显示html跳转
                    $json['html'] = $result['data'];
                    break;
                case 'qrcode': //扫码支付
                    $selfurl = is_self_url($result['url']);
                    if($result['page'] == 'alipay_qrcode' && !empty($conf['alipay_qrcode_url'])){
                        $siteurl = $conf['alipay_qrcode_url'];
                        if(strpos($result['url'], $siteurl)===0){
                            $result['url'] = $siteurl.substr($result['url'], strlen($siteurl));
                        }elseif(!empty($conf['localurl_alipay']) && strpos($result['url'], $conf['localurl_alipay'])===0){
                            $result['url'] = $siteurl.substr($result['url'], strlen($conf['localurl_alipay']));
                        }
                    }
                    if(in_array($result['page'], ['wxpay_qrcode', 'wxpay_wap']) && !empty($conf['wxpay_qrcode_url'])){
                        $siteurl = $conf['wxpay_qrcode_url'];
                        if(strpos($result['url'], $siteurl)===0){
                            $result['url'] = $siteurl.substr($result['url'], strlen($siteurl));
                        }elseif(!empty($conf['localurl_wxpay']) && strpos($result['url'], $conf['localurl_wxpay'])===0){
                            $result['url'] = $siteurl.substr($result['url'], strlen($conf['localurl_wxpay']));
                        }
                    }
                    if($conf['check_pay_regoin'] > 0 && is_url($result['url']) && in_array($result['page'], ['alipay_qrcode', 'wxpay_qrcode', 'wxpay_wap'])
                    || is_url($result['url']) && !$selfurl && $conf['wxpay_qrpaylogin'] == 1 && in_array($result['page'], ['wxpay_qrcode', 'wxpay_wap'])
                    || is_url($result['url']) && !$selfurl && $conf['alipay_qrpaylogin'] == 1 && $result['page'] == 'alipay_qrcode'){
                        self::updateOrderPayUrl(TRADE_NO, $result['url']);
                        $result['url'] = $siteurl.'pay/checkpay/'.TRADE_NO.'/';
                    }
                    $json['qrcode'] = $result['url'];
                    break;
                case 'scheme': //小程序H5跳转
                    $json['urlscheme'] = $result['url'];
                    break;
                case 'error':
                    $checkerr = self::check_error_msg($result['msg']);
                    if($checkerr && $conf['check_paymsg_retry'] == 1 && $order['tid']!=3){
                        $returnurl = $siteurl.'submit2.php?typeid='.$order['type'].'&trade_no='.$order['trade_no'].'&retry=1';
                        $json['payurl'] = $returnurl;
                    }else{
                        $json['code'] = -2;
                        $json['msg'] = $result['msg'];
                    }
                    break;
                default:
                    $json['payurl'] = $siteurl.'pay/submit/'.TRADE_NO.'/';
                    break;
            }
            exit(json_encode($json));
        }
    }

    static private function check_error_msg($msg){
        global $conf, $channel, $DB;
        $flag = false;
        if(!empty($conf['check_paymsg']) && isset($channel)){
            $msglist = explode('|', $conf['check_paymsg']);
            foreach($msglist as $v){
                if(strpos($msg, $v) !== false){
                    if(!empty($channel['subid'])){
                        if($DB->exec("UPDATE pre_subchannel SET status=0 WHERE id='{$channel['subid']}'")){
                            if($conf['check_paymsg_notice'] == 1){
                                $title = $conf['sitename'].' - 子通道自动关闭提醒';
                                $content = '尊敬的管理员：支付通道“'.$channel['name'].'”下的子通道“'.$channel['subname'].'”因用户下单时出现异常提示“'.$msg.'”，已被系统自动关闭！<br/>----------<br/>'.$conf['sitename'].'<br/>'.date('Y-m-d H:i:s');
                                if($conf['msgconfig_risk'] == 1 && !empty($conf['msgrobot_url'])){
                                    \lib\MsgNotice::robot_webhook($conf['msgrobot_url'], $title, $content, true);
                                }else{
                                    $mail_name = $conf['mail_recv']?$conf['mail_recv']:$conf['mail_name'];
                                    send_mail($mail_name,$title,$content);
                                }
                            }
                        }
                    }else{
                        if($DB->exec("UPDATE pre_channel SET status=0 WHERE id='{$channel['id']}'")){
                            if($conf['check_paymsg_notice'] == 1){
                                $title = $conf['sitename'].' - 支付通道自动关闭提醒';
                                $content = '尊敬的管理员：支付通道“'.$channel['name'].'”因用户下单时出现异常提示“'.$msg.'”，已被系统自动关闭！<br/>----------<br/>'.$conf['sitename'].'<br/>'.date('Y-m-d H:i:s');
                                if($conf['msgconfig_risk'] == 1 && !empty($conf['msgrobot_url'])){
                                    \lib\MsgNotice::robot_webhook($conf['msgrobot_url'], $title, $content, true);
                                }else{
                                    $mail_name = $conf['mail_recv']?$conf['mail_recv']:$conf['mail_name'];
                                    send_mail($mail_name,$title,$content);
                                }
                            }
                        }
                    }
                    $flag = true;
                    break;
                }
            }
        }
        return $flag;
    }

    private static $accountingPermit=null;
    public static function consumeAccountingPermit($permit,$trade){
        global $DB;
        if(!$DB->db->inTransaction() || !$permit || $permit!==self::$accountingPermit || $permit->trade!==$trade) throw new \RuntimeException('非受控订单入账被拒绝');
        self::$accountingPermit=null; // one use, minted only after locked first-paid transition
    }

    // 订单回调处理
    static public function processOrder($isnotify, $order, $api_trade_no, $buyer = null, $bill_trade_no = null, $bill_mch_trade_no = null, $end_time = null, $redirect = true){
        global $DB,$conf,$siteurl;
        // This entrypoint owns its commit: no notification may escape an ambient transaction.
        if ($DB->db->inTransaction()) throw new \RuntimeException('支付回调不允许嵌套外层事务');
        $effects = [];
        Finance::transaction(function() use ($DB, &$order, &$effects, $isnotify, $api_trade_no, $buyer, $bill_trade_no, $bill_mch_trade_no, $end_time){
            $stored = Finance::row('SELECT * FROM pre_order WHERE trade_no=:trade FOR UPDATE', [':trade'=>$order['trade_no']]);
            if (!$stored) throw new \RuntimeException('支付订单不存在');
            // Presentation-only joined fields may be absent in the base order table.
            $requestedSettle = $order['settle'] ?? 0;
            $order = array_merge($order, $stored);
            $first = in_array((int)$stored['status'], [0, 4], true);
            $data = [];
            if ($first) {
                $data = ['status'=>1, 'endtime'=>'NOW()', 'date'=>'CURDATE()'];
                if ($requestedSettle > 0) $data['settle'] = $requestedSettle;
            }
            if ($first || (int)$stored['status'] === 1) {
                if (!empty($api_trade_no) && ($first || empty($stored['api_trade_no']))) $data['api_trade_no'] = $api_trade_no;
                if (!empty($buyer) && empty($stored['buyer'])) $data['buyer'] = $buyer;
                if (!empty($bill_trade_no) && ($first || empty($stored['bill_trade_no']))) $data['bill_trade_no'] = $bill_trade_no;
                if (!empty($bill_mch_trade_no) && ($first || empty($stored['bill_mch_trade_no']))) $data['bill_mch_trade_no'] = $bill_mch_trade_no;
                if (!empty($end_time) && $first) {
                    if (strtotime($end_time) === false) throw new \RuntimeException('支付完成时间格式错误');
                    $data['endtime'] = $end_time;
                    $data['date'] = date('Y-m-d', strtotime($end_time));
                }
                if ($data) Finance::checked($DB->update('order', $data, ['trade_no'=>$stored['trade_no']]));
                $order = array_merge($order, $data);
                if ($first) {
                    self::$accountingPermit=(object)['trade'=>$stored['trade_no']];
                    try { epay_account_order($order, $isnotify, $effects, self::$accountingPermit); }
                    finally { self::$accountingPermit=null; }
                }
            }
            $order = array_merge($order, Finance::row('SELECT * FROM pre_order WHERE trade_no=:trade', [':trade'=>$stored['trade_no']]));
            return $order;
        });
        epay_run_order_effects($effects);
        if(!$isnotify && $redirect){
            include_once SYSTEM_ROOT.'txprotect.php';
            if($order['status'] == 2 || $order['black']){
                $jumpurl = '/payerr.html';
                returnTemplate($jumpurl);
                return;
            }
            // 支付完成5分钟后禁止跳转回网站
            if(!empty($order['endtime']) && time() - strtotime($order['endtime']) > 300){
                $jumpurl = '/payok.html';
            }else{
                $url=creat_callback($order);
                $jumpurl = $url['return'];
            }
            returnTemplate($jumpurl);
        }
        return $order;
    }

    // 更新订单信息
    static public function updateOrder($trade_no, $api_trade_no, $buyer = null, $status = null){
        global $DB;
        return Finance::transaction(function() use($DB,$trade_no,$api_trade_no,$buyer,$status){
            $row=Finance::row('SELECT * FROM pre_order WHERE trade_no=:trade FOR UPDATE',[':trade'=>$trade_no]);
            if(!$row) throw new \RuntimeException('支付订单不存在');
            $data=[];
            // Checkout metadata must never regress paid/refunded/frozen states.
            if(!empty($api_trade_no) && empty($row['api_trade_no'])) $data['api_trade_no']=$api_trade_no;
            if(!empty($buyer) && empty($row['buyer'])) $data['buyer']=$buyer;
            if($status !== null && (int)$status !== 0){
                if((int)$status !== 4) throw new \RuntimeException('支付终态必须通过订单入账入口确认');
                if((int)$row['status'] === 0) $data['status']=4;
            }
            if($data) Finance::checked($DB->update('order',$data,['trade_no'=>$trade_no]));
            return true;
        });
    }

    // 更新订单扩展信息
    static public function updateOrderExt($trade_no, $data){
        global $DB;
        if (!is_array($data)) throw new \InvalidArgumentException('订单扩展信息必须为数组');
        return Finance::transaction(function() use ($DB, $trade_no, $data){
            $row = Finance::row('SELECT ext FROM pre_order WHERE trade_no=:trade FOR UPDATE', [':trade'=>$trade_no]);
            if (!$row) throw new \RuntimeException('支付订单不存在');
            $raw = $row['ext'];
            $latest = ($raw === null || $raw === '') ? [] : @unserialize($raw, ['allowed_classes'=>false]);
            if (!is_array($latest)) throw new \RuntimeException('订单扩展信息损坏，需核对');
            // These durable intents belong exclusively to the Checkout CAS writer.
            foreach (['_bepusdt_checkout', '_epusdt_checkout', '_epusdtapi_checkout'] as $key) unset($data[$key]);
            $merged = serialize(array_replace($latest, $data));
            if ($merged === $raw) return true;
            $n = Finance::checked($DB->exec('UPDATE pre_order SET ext=:new WHERE trade_no=:trade AND ext <=> :old', [':new'=>$merged, ':trade'=>$trade_no, ':old'=>$raw]));
            if ($n !== 1) throw new \RuntimeException('订单扩展信息并发冲突');
            return true;
        });
    }

    // 更新合单状态
    static public function updateOrderCombine($trade_no, $sub_orders = null){
        global $DB;
        return Finance::transaction(function() use($DB,$trade_no,$sub_orders){
            $order=Finance::row('SELECT status FROM pre_order WHERE trade_no=:trade FOR UPDATE',[':trade'=>$trade_no]);
            if(!$order) throw new \RuntimeException('合单订单不存在');
            $children=Finance::checked($DB->query('SELECT * FROM pre_suborder WHERE trade_no=:trade FOR UPDATE',[':trade'=>$trade_no]))->fetchAll(\PDO::FETCH_ASSOC);
            // Replays after a child/parent completion may not delete or reset children.
            if(!in_array((int)$order['status'],[0,4],true)) return false;
            foreach($children as $child) if((int)$child['status']!==0 || (int)$child['settle']!==0) return false;
            if($sub_orders!==null && !is_array($sub_orders)) throw new \RuntimeException('子订单格式错误');
            Finance::checked($DB->update('order',['combine'=>1],['trade_no'=>$trade_no]));
            if(!empty($sub_orders)){
                $seen=[];
                foreach($sub_orders as $data){
                    if(!is_array($data) || empty($data['sub_trade_no']) || isset($seen[$data['sub_trade_no']])) throw new \RuntimeException('子订单编号错误或重复');
                    $seen[$data['sub_trade_no']]=true;
                }
                Finance::checked($DB->delete('suborder',['trade_no'=>$trade_no]));
                foreach($sub_orders as $data){
                    $data['trade_no']=$trade_no;$data['status']=0;
                    Finance::checked($DB->insert('suborder',$data));
                }
            }
            return true;
        });
    }

    // 更新订单分账接收人
    static public function updateOrderProfits($order, $plugin){
        global $DB;
        $support_plugins = \lib\ProfitSharing\CommUtil::$plugins;
        if(in_array($plugin, $support_plugins)){
            $psreceiver = null;
            if($order['subchannel'] > 0){
                $psreceiver = $DB->getRow("SELECT * FROM `pre_psreceiver` WHERE `channel`='{$order['channel']}' AND `uid`='{$order['uid']}' AND `subchannel`='{$order['subchannel']}' AND `status`=1 ORDER BY id ASC LIMIT 1");
            }
            if(!$psreceiver) $psreceiver = $DB->getRow("SELECT * FROM `pre_psreceiver` WHERE `channel`='{$order['channel']}' AND `uid`='{$order['uid']}' AND `status`=1 ORDER BY id ASC LIMIT 1");
            if(!$psreceiver) $psreceiver = $DB->getRow("SELECT * FROM `pre_psreceiver` WHERE `channel`='{$order['channel']}' AND `uid` IS NULL AND `status`=1 ORDER BY id ASC LIMIT 1");
            if($psreceiver){
                if($psreceiver['subchannel'] > 0 && $order['subchannel']!=$psreceiver['subchannel']) return 0;
                if(!$psreceiver['minmoney'] || $order['realmoney']>=$psreceiver['minmoney']){
                    $DB->update('order', ['profits'=>$psreceiver['id']], ['trade_no'=>$order['trade_no']]);
                    return intval($psreceiver['id']);
                }
            }
        }
        return 0;
    }

    //支付宝直付通确认结算
    public static function alipaydSettle($channel, $order){
        $order = self::settlementOrder($order);
        if ((int)$order['settle'] === 2) return true;
        $alipay_config = require(PLUGIN_ROOT.'alipayd/inc/config.php');
        $alipaySevice = new \Alipay\AlipayTradeService($alipay_config);
        if($order['combine'] == 1){
            $sub_orders = self::getSubOrders($order['trade_no']);
            if(empty($sub_orders)) throw new Exception('子订单数据不存在');
            $failnum = 0;
            $errmsg = '';
            foreach($sub_orders as $sub_order){
                if($sub_order['settle'] == 0){
                    $settle = 0;
                    try{
                        $alipaySevice->settle_confirm($sub_order['api_trade_no'], $sub_order['money']);
                        $settle = 1;
                    }catch(Exception $e){
                        if(strpos($e->getMessage(), 'ALREADY_CONFIRM_SETTLE')!==false){
                            $settle = 1;
                        }else{
                            $failnum++;
                            $errmsg .= $e->getMessage().',';
                        }
                    }
                    if($settle == 1) self::updateSubOrderSettle($sub_order['sub_trade_no'], 1);
                }
            }
            if($failnum > 0) throw new Exception('部分子单结算失败，失败数量：'.$failnum.'，失败原因：'.$errmsg);
            return true;
        }
        try{
            $alipaySevice->settle_confirm($order['api_trade_no'], $order['realmoney']);
            return true;
        }catch(Exception $e){
            if(strpos($e->getMessage(), 'ALREADY_CONFIRM_SETTLE')!==false){
                return true;
            }else{
                throw $e;
            }
        }
    }

    //支付宝直付通结算失败处理
    public static function alipayd_settle_fail($channel, $order, $failmsg){
        global $conf, $DB;
        if($conf['alipay_settle_check'] == 1){
            if(!empty($channel['subid'])){
                if($DB->exec("UPDATE pre_subchannel SET status=0 WHERE id='{$channel['subid']}'")){
                    if($conf['alipay_settle_notice'] == 1){
                        $title = $conf['sitename'].' - 支付宝直付通结算失败提醒';
                        $content = '尊敬的管理员：支付通道“'.$channel['name'].'”下的子通道“'.$channel['subname'].'”因支付宝直付通结算失败（'.$failmsg.'），已被系统自动关闭！<br/>----------<br/>'.$conf['sitename'].'<br/>'.date('Y-m-d H:i:s');
                        if($conf['msgconfig_risk'] == 1 && !empty($conf['msgrobot_url'])){
                            \lib\MsgNotice::robot_webhook($conf['msgrobot_url'], $title, $content, true);
                        }else{
                            $mail_name = $conf['mail_recv']?$conf['mail_recv']:$conf['mail_name'];
                            send_mail($mail_name,$title,$content);
                        }
                    }
                }
            }else{
                if($DB->exec("UPDATE pre_channel SET status=0 WHERE id='{$channel['id']}'")){
                    if($conf['alipay_settle_notice'] == 1){
                        $title = $conf['sitename'].' - 支付宝直付通结算失败提醒';
                        $content = '尊敬的管理员：支付通道“'.$channel['name'].'”因支付宝直付通结算失败（'.$failmsg.'），已被系统自动关闭！<br/>----------<br/>'.$conf['sitename'].'<br/>'.date('Y-m-d H:i:s');
                        if($conf['msgconfig_risk'] == 1 && !empty($conf['msgrobot_url'])){
                            \lib\MsgNotice::robot_webhook($conf['msgrobot_url'], $title, $content, true);
                        }else{
                            $mail_name = $conf['mail_recv']?$conf['mail_recv']:$conf['mail_name'];
                            send_mail($mail_name,$title,$content);
                        }
                    }
                }
            }
        }
    }

    private static function settlementOrder($order){
        global $DB;
        if ($DB->db->inTransaction()) throw new Exception('上游结算不能位于数据库事务内');
        $fresh = Finance::row('SELECT * FROM pre_order WHERE trade_no=:trade', [':trade'=>$order['trade_no']]);
        if (!$fresh || (int)$fresh['status'] !== 1 || empty($fresh['api_trade_no'])) throw new Exception('本地订单未确认支付成功');
        if ((int)$fresh['settle'] === 4 && empty($order['_settlement_claim'])) throw new Exception('结算结果待核对，禁止直接重试');
        return array_merge($order, $fresh);
    }

    //微信收付通确认结算
    public static function wxpaynpSettle($channel, $order){
        $order = self::settlementOrder($order);
        if ((int)$order['settle'] === 2) return true;
        $wechatpay_config = require(PLUGIN_ROOT.'/wxpaynp/inc/config.php');
        if($wechatpay_config['ecommerce']){
            if(!$order['profits']){
                $client = new \WeChatPay\V3\ProfitsharingService($wechatpay_config);
                if($order['combine'] == 1){
                    $sub_orders = self::getSubOrders($order['trade_no']);
                    if(empty($sub_orders)) throw new Exception('子订单数据不存在');
                    $failnum = 0;
                    $errmsg = '';
                    foreach($sub_orders as $sub_order){
                        if($sub_order['settle'] == 0){
                            $settle = 0;
                            try{
                                $client->unfreeze($sub_order['sub_trade_no'], $sub_order['api_trade_no']);
                                $settle = 1;
                            }catch(Exception $e){
                                $failnum++;
                                $errmsg .= $e->getMessage().',';
                            }
                            if($settle == 1) self::updateSubOrderSettle($sub_order['sub_trade_no'], 1);
                        }
                    }
                    if($failnum > 0) throw new Exception('部分子单结算失败，失败数量：'.$failnum.'，失败原因：'.$errmsg);
                    return true;
                }
                return $client->unfreeze($order['trade_no'], $order['api_trade_no']);
            }else{
                throw new Exception('当前订单需要分账，请进入分账订单页面确认分账');
            }
        }else{
            throw new Exception('非平台收付通订单');
        }
    }

    // Resolve auxiliary config inside the selected plugin's final directory.
    private static function safePluginConfig($plugin){
        if(!is_string($plugin) || !preg_match('/^[a-zA-Z0-9_]{1,32}$/D', $plugin)){
            throw new Exception('插件名不符合规范');
        }
        $base = realpath(PLUGIN_ROOT);
        $root = realpath(PLUGIN_ROOT.$plugin);
        $file = realpath(PLUGIN_ROOT.$plugin.'/inc/config.php');
        if($base === false || $root === false || $file === false
            || !is_dir($root) || !is_file($file)
            || strpos($root, $base.DIRECTORY_SEPARATOR) !== 0
            || strpos($file, $root.DIRECTORY_SEPARATOR) !== 0){
            throw new Exception('插件配置路径不合法');
        }
        return $file;
    }

    //支付宝预授权资金支付
    public static function alipayPreAuthPay($channel, $order){
        global $conf;
        $alipay_config = require(self::safePluginConfig($channel['plugin']));
        $alipaySevice = new \Alipay\AlipayTradeService($alipay_config);
        $trade_no = $order['trade_no'];
        $bizContent = [
            'out_order_no' => $trade_no,
            'out_request_no' => $trade_no,
        ];
        $result = $alipaySevice->preAuthQuery($bizContent);
        //print_r($result);exit;
        if(!isset($result['auth_no'])) throw new Exception('预授权订单查询失败');
        if($result['rest_amount'] == 0) throw new Exception('剩余冻结金额为0');
        if($result['order_status'] == 'AUTHORIZED'){
            $auth_no = $result['auth_no'];
            $ordername = !empty($conf['ordername'])?ordername_replace($conf['ordername'],$order['name'],$order['uid'],$trade_no):$order['name'];
            $bizContent = [
                'out_trade_no' => $trade_no,
                'total_amount' => $result['rest_amount'],
                'subject' => $ordername,
                'product_code' => 'PREAUTH_PAY',
                'auth_no' => $auth_no,
                'auth_confirm_mode' => 'COMPLETE'
            ];
            return $alipaySevice->scanPay($bizContent);
        }else{
            throw new Exception('该笔订单非已授权状态，无需支付');
        }
    }

    //支付宝预授权资金解冻
    public static function alipayUnfreeze($channel, $order){
        $alipay_config = require(self::safePluginConfig($channel['plugin']));
        $alipaySevice = new \Alipay\AlipayTradeService($alipay_config);
        $trade_no = $order['trade_no'];
        $bizContent = [
            'out_order_no' => $trade_no,
            'out_request_no' => $trade_no,
        ];
        $result = $alipaySevice->preAuthQuery($bizContent);
        //print_r($result);exit;
        if(!isset($result['auth_no'])) throw new Exception('预授权订单查询失败');
        if($result['rest_amount'] == 0) throw new Exception('剩余冻结金额为0');
        if($result['order_status'] == 'AUTHORIZED'){
            $auth_no = $result['auth_no'];
            $bizContent = [
                'auth_no' => $auth_no,
                'out_request_no' => date("YmdHis").rand(11111,99999),
                'amount' => $result['rest_amount'],
                'remark' => '解冻资金'
            ];
            return $alipaySevice->preAuthUnfreeze($bizContent);
        }else{
            throw new Exception('该笔订单非已授权状态，无需解冻');
        }
    }

    //支付宝红包转账
    public static function alipayRedPacketTransfer($channel, $payee_user_id, $money, $order_id){
        $out_biz_no = date("YmdHis").rand(11111,99999);
        $alipay_config = require(self::safePluginConfig($channel['plugin']));
        $alipaySevice = new \Alipay\AlipayTransferService($alipay_config);
        $alipaySevice->redPacketTansfer($out_biz_no, $money, $payee_user_id, $conf['sitename'], $order_id);
    }

    //支付宝红包资金退回
    public static function alipayRedPacketRefund($channel, $trade_no, $money){
        $out_biz_no = date("YmdHis").rand(11111,99999);
        $alipay_config = require(self::safePluginConfig($channel['plugin']));
        $alipaySevice = new \Alipay\AlipayTransferService($alipay_config);
        $alipaySevice->redPacketRefund($out_biz_no, $trade_no, $money);
    }

    //加锁设置订单扩展数据
    public static function lockPayData($trade_no, $func){
        global $DB;
        $DB->beginTransaction();
        $data = $DB->getColumn("SELECT ext FROM pre_order WHERE trade_no=:trade_no FOR UPDATE", [':trade_no'=>$trade_no]);
        if($data) {
            $DB->rollBack();
            return @unserialize($data, ['allowed_classes'=>false]) ?: [];
        }
        try{
            $data = $func();
        }catch(\Exception $e){
            $DB->rollBack();
            throw $e;
        }
        if($data){
            $DB->update('order', ['ext'=>serialize($data)], ['trade_no' => $trade_no]);
        }
        $DB->commit();
        return $data;
    }

    public static function updateOrderPayUrl($trade_no, $pay_url){
        global $DB;
        $DB->update('order', ['payurl'=>$pay_url], ['trade_no'=>$trade_no]);
    }

    //获取微信客服跳转链接
    public static function getWxkfPayUrl($pay_url){
        global $order, $DB, $conf;

        $cookiesid = $_COOKIE['mysid'];
        if(!$cookiesid||!preg_match('/^[0-9a-z]{32}$/i', $cookiesid)){
            $cookiesid = getSid();
            epay_set_cookie("mysid", $cookiesid, time() + 2592000, "/");
        }

        if($conf['wework_paykfid'] > 0){
            $wxkfaccount = $DB->getRow("SELECT * FROM pre_wxkfaccount WHERE id=:id", [':id'=>$conf['wework_paykfid']]);
        }elseif($conf['wework_paymsgmode'] == 1){
            $usekflist = $DB->getAll("SELECT DISTINCT aid FROM pre_wxkflog WHERE `sid`=:sid AND addtime>=:addtime AND status=1", [':sid'=>$cookiesid, ':addtime'=>date("Y-m-d H:i:s", strtotime('-48 hours'))]);
            $usekfids = [0];
            foreach($usekflist as $usekf){
                $usekfids[] = intval($usekf['aid']);
            }
            $wxkfaccount = $DB->getRow("SELECT A.* FROM pre_wxkfaccount A LEFT JOIN pre_wework B ON A.wid=B.id WHERE A.id NOT IN (".implode(",", $usekfids).") AND B.status=1 ORDER BY A.usetime ASC LIMIT 1");
        }else{
            $wxkfaccount = $DB->getRow("SELECT A.* FROM pre_wxkfaccount A LEFT JOIN pre_wework B ON A.wid=B.id WHERE B.status=1 ORDER BY A.usetime ASC LIMIT 1");
        }
        if(!$wxkfaccount) return false;

        $DB->insert('wxkflog', ['trade_no'=>$order['trade_no'], 'aid'=>$wxkfaccount['id'], 'sid'=>$cookiesid, 'payurl'=>$pay_url, 'addtime'=>'NOW()']);
        $scene_param = 'orderid='.$order['trade_no'].'&money='.$order['realmoney'];
        try{
            if(!empty($wxkfaccount['url'])){
                $kfurl = $wxkfaccount['url'];
                $DB->update('wxkfaccount', ['usetime'=>'NOW()'], ['id'=>$wxkfaccount['id']]);
            }else{
                $wework = new wechat\WeWorkAPI($wxkfaccount['wid']);
                $kfurl = $wework->getKFURL($wxkfaccount['openkfid'], 'pay');
                $DB->update('wxkfaccount', ['url'=>$kfurl, 'usetime'=>'NOW()'], ['id'=>$wxkfaccount['id']]);
            }
            $kfurl = 'weixin://biz/ww/kefu/' . $kfurl . '&schema=1';
            $kfurl .= '&scene_param='.urlencode($scene_param);
            return $kfurl;
        }catch(\Exception $e){
            sysmsg($e->getMessage());
        }
    }

    private static function getWxappkfPayUrl($pay_url){
        global $order, $DB, $conf;
        $DB->update('order', ['payurl'=>$pay_url], ['trade_no'=>$order['trade_no']]);
        $path = 'pages/kefupay/pay';
        if($conf['wxappkf_path']) {
            $path = $conf['wxappkf_path'];
        }
        $query = 'orderid='.$order['trade_no'].'&money='.$order['realmoney'];
        $wechat = new \lib\wechat\WechatAPI($conf['wxappkf_applet']);
        try{
            return $wechat->generate_scheme($path, $query);
        }catch(\Exception $e){
            sysmsg($e->getMessage());
        }
    }

    //支付宝直付通&微信收付通延迟结算处理
    public static function settle_task(){
        global $DB;
        if ($DB->db->inTransaction()) throw new \RuntimeException('结算任务不允许嵌套外层事务');
        $orders = Finance::checked($DB->getAll("SELECT A.*,B.plugin FROM pre_order A LEFT JOIN pre_channel B ON A.channel=B.id WHERE A.status=1 AND A.settle=1 AND A.addtime<DATE_SUB(NOW(), INTERVAL 24 HOUR) AND B.plugin in ('alipayd','wxpaynp') ORDER BY A.trade_no ASC LIMIT 10"));
        foreach ($orders as $row) {
            $trade_no = $row['trade_no'];
            // Connection-scoped lock serializes workers without holding a DB transaction over HTTP.
            $lock = 'epay_settle_'.hash('sha256', $trade_no);
            $lock = substr($lock, 0, 64);
            $got = Finance::checked($DB->query('SELECT GET_LOCK(:name,0)', [':name'=>$lock]))->fetchColumn();
            if ((int)$got !== 1) continue;
            try {
                $fresh = Finance::row('SELECT * FROM pre_order WHERE trade_no=:trade', [':trade'=>$trade_no]);
                if (!$fresh || (int)$fresh['status'] !== 1 || (int)$fresh['settle'] !== 1) continue;
                $row = array_merge($row, $fresh);
                $channel = $row['subchannel'] > 0 ? \lib\Channel::getSub($row['subchannel']) : \lib\Channel::get($row['channel'], $DB->findColumn('user', 'channelinfo', ['uid'=>$row['uid']]));
                if (!$channel) continue;
                // Durable in-flight/uncertain marker. A crash or post-gateway write failure
                // must not let the scheduler blindly submit this settlement again.
                $claimed = Finance::checked($DB->update('order', ['settle'=>4], ['trade_no'=>$trade_no, 'status'=>1, 'settle'=>1]));
                if ($claimed !== 1) continue;
                $row['_settlement_claim'] = true;
                $gatewayError = null;
                $target = 2;
                try {
                    $result = $row['plugin'] === 'alipayd' ? self::alipaydSettle($channel, $row) : self::wxpaynpSettle($channel, $row);
                    if ($result === false || $result === null) {
                        $gatewayError = new Exception('上游未确认结算成功');
                        $target = 3;
                    }
                } catch (\Throwable $e) {
                    // Timeout and a failed child-state write are indistinguishable from
                    // remote success: retain the durable uncertain claim, never auto-replay.
                    if (strpos($e->getMessage(), 'ALREADY_CONFIRM_SETTLE') === false) {
                        $gatewayError = $e;
                        $target = 4;
                    }
                }
                // A failed local write is not a gateway failure. Leave uncertain for reconciliation.
                $changed = Finance::checked($DB->update('order', ['settle'=>$target], ['trade_no'=>$trade_no, 'status'=>1, 'settle'=>4]));
                if ($changed !== 1 && $target !== 4) throw new \RuntimeException('结算本地状态未确认，需核对');
                echo htmlspecialchars($trade_no, ENT_QUOTES, 'UTF-8').($gatewayError ? ' 结算失败<br/>' : ' 结算成功<br/>');
                if ($gatewayError && $row['plugin'] === 'alipayd') self::alipayd_settle_fail($channel, $row, $gatewayError->getMessage());
            } finally {
                Finance::checked($DB->query('SELECT RELEASE_LOCK(:name)', [':name'=>$lock]));
            }
        }
    }

    public static function getSubOrders($trade_no){
        global $DB;
        return $DB->getAll("SELECT * FROM pre_suborder WHERE trade_no=:trade_no", [':trade_no'=>$trade_no]);
    }

    public static function processSubOrders($trade_no, $sub_orders){
        global $DB;
        return Finance::transaction(function() use($DB,$trade_no,$sub_orders){
            if(!Finance::row('SELECT trade_no FROM pre_order WHERE trade_no=:trade FOR UPDATE',[':trade'=>$trade_no])) throw new \RuntimeException('合单订单不存在');
            foreach($sub_orders as $data){
                $child=Finance::row('SELECT * FROM pre_suborder WHERE trade_no=:trade AND sub_trade_no=:sub FOR UPDATE',[':trade'=>$trade_no,':sub'=>$data['sub_trade_no']]);
                if(!$child)throw new \RuntimeException('合单子订单不存在');
                if((int)$child['status']===0) Finance::checked($DB->update('suborder',['status'=>1,'api_trade_no'=>$data['api_trade_no']],['trade_no'=>$trade_no,'sub_trade_no'=>$data['sub_trade_no']]));
            }
            return true;
        });
    }

    public static function refundSubOrder($sub_trade_no, $refundmoney = null){
        global $DB;
        $DB->update('suborder', ['status'=>2, 'refundmoney'=>$refundmoney], ['sub_trade_no'=>$sub_trade_no]);
    }

    public static function updateSubOrderSettle($sub_trade_no, $settle){
        global $DB;
        Finance::checked($DB->update('suborder', ['settle'=>$settle], ['sub_trade_no'=>$sub_trade_no]));
        $row = Finance::row('SELECT settle FROM pre_suborder WHERE sub_trade_no=:trade', [':trade'=>$sub_trade_no]);
        if (!$row || (int)$row['settle'] !== (int)$settle) throw new \RuntimeException('子订单结算状态未确认');
    }
}
