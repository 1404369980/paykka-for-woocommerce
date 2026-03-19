<?php
namespace lib\Paykka\Request;

use lib\Paykka\Api\Bill;
use lib\Paykka\Api\Browser;
use lib\Paykka\Api\Shipping;
use lib\Paykka\Api\Goods;
use lib\Paykka\Api\PayCustomer;
use lib\Paykka\Api\PaymentInfo;
use lib\Paykka\Api\PaymentRequest;
use lib\Paykka\Request\PaykkaWebHookHandler;
use lib\Paykka\Request\PaykkaCallBackHandler;


$paykka_base = defined('PAYKKA_PLUGIN_PATH') ? PAYKKA_PLUGIN_PATH : (defined('FENGQIAO_PAYKKA_URL') ? FENGQIAO_PAYKKA_URL : '');
require_once $paykka_base . 'classes/lib/Paykka/Api/Bill.php';
require_once $paykka_base . 'classes/lib/Paykka/Api/Browser.php';
require_once $paykka_base . 'classes/lib/Paykka/Api/Shipping.php';
require_once $paykka_base . 'classes/lib/Paykka/Api/Goods.php';
require_once $paykka_base . 'classes/lib/Paykka/Api/PayCustomer.php';
require_once $paykka_base . 'classes/lib/Paykka/Api/PaymentInfo.php';
require_once $paykka_base . 'classes/lib/Paykka/Api/PaymentRequest.php';
require_once $paykka_base . 'classes/lib/Paykka/Request/PaykkaWebHookHandler.php';
require_once $paykka_base . 'classes/lib/Paykka/Request/PaykkaCallBackHandler.php';

class PaykkaRequestHandler
{

    public function buildSessionId($order, $session_mode)
    {
        return $this->handlerSession($order, $session_mode);
    }

    public function buildSessionUrl($order): mixed
    {
        return $this->handlerSession($order, 'HOSTED');
    }


    /**
     * 创建收银台 Session（Hosted / Drop-in / Component）
     * 文档: https://docs.paykka.com/zh-hans/payments/apis/payments/openapi/收银台/session-opl_1
     * 接口: POST /v3/payment/acq/session
     *
     * @param \WC_Order $order
     * @param string    $session_mode HOSTED | DROP_IN | COMPONENT
     * @return array|null 统一为 ['ret_code'=>'000000', 'data'=>['session_id'=>..., 'session_url'=>...]] 或失败信息
     */
    public function handlerSession($order, $session_mode)
    {
        $paykkaSettings = getPaykkaSettings();
        $PAYKKA_MERCHANT_ID = $paykkaSettings['paykka_merchant_id'];
        $PAYKKA_API_KEY = $paykkaSettings['paykka_private_key'];
        $sandbox = get_option('paykka_sandbox_flag') === 'yes';
        $app_id = $sandbox ? get_option('paykka_sandbox_app_id', '') : get_option('paykka_app_id', '');
        if ($app_id === '') {
            $app_id = $PAYKKA_MERCHANT_ID;
        }

        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $now->setTimezone(new \DateTimeZone('Asia/Hong_Kong'));
        $now->add(new \DateInterval('PT5M'));
        $expire_time = $now->format('Y-m-d\TH:i:sO');
        $timestamp = (string) round(microtime(true) * 1000);
        $nonce = (string) wp_rand(1000000000000000, 9999999999999999);

        $callback_url = PaykkaCallBackHandler::getCallbackUrl($order->get_id());
        $notify_url = PaykkaWebHookHandler::getWebHookUrl();
        $cancel_url = get_option('paykka_cancel_url', $callback_url);

        $decimal_places = get_option('woocommerce_price_num_decimals', 2);
        $order_amount = intval(round($order->get_total() * pow(10, $decimal_places)));

        $paymentRequest = new PaymentRequest();
        $paymentRequest->__set('merchant_id', $PAYKKA_MERCHANT_ID);
        $paymentRequest->__set('payment_type', 'PURCHASE');
        $paymentRequest->__set('trans_id', (string) $order->get_id());
        $paymentRequest->__set('currency', $order->get_currency());
        $paymentRequest->__set('amount', $order_amount);
        $paymentRequest->__set('notify_url', $notify_url);
        $paymentRequest->__set('return_url', $callback_url);
        $paymentRequest->__set('cancel_url', $cancel_url);
        $paymentRequest->__set('expire_time', $expire_time);
        $paymentRequest->__set('session_mode', $session_mode);

        $paykka_capture_method_flag = get_option('paykka_capture_method_flag');
        if ($paykka_capture_method_flag === 'yes') {
            $paymentRequest->__set('capture_method', 'MANUAL');
        } else {
            $paymentRequest->__set('capture_method', 'AUTOMATIC');
        }

        $paymentRequest->bill = $this->buildBill($order);
        $paymentRequest->shipping = $this->buildShipping($order);
        $paymentRequest->goods = $this->buildGoodsItems($order);
        $paymentRequest->customer = $this->buildCustomer($order);
        $paymentRequest->payment = new PaymentInfo();

        $http_body = $paymentRequest->toJson();
        $request_path = '/v3/payment/acq/session';
        $signStr = $this->paykkaSignV3('POST', $request_path, $timestamp, $nonce, $http_body, $PAYKKA_API_KEY);
        if ($signStr === null) {
            wc_add_notice(__('Payment signature error', 'paykka-for-woocommerce'), 'error');
            return null;
        }

        $headers = array(
            'Content-Type'       => 'application/json',
            'x-paykka-appid'     => $app_id,
            'x-paykka-timestamp' => $timestamp,
            'x-paykka-nonce'     => $nonce,
            'x-paykka-sign-alg'  => 'SHA256_WITH_RSA',
            'x-paykka-sign'      => $signStr,
        );

        $api_base = $this->getPaykkaApiBaseUrl();
        $response = wp_remote_post($api_base . '/v3/payment/acq/session', array(
            'headers' => $headers,
            'body' => $http_body,
            'timeout' => 16,
        ));

        if (is_wp_error($response)) {
            wc_add_notice('Payment error: ' . $response->get_error_message(), 'error');
            return null;
        }

        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);
        $http_code = wp_remote_retrieve_response_code($response);

        $this->logPaykkaResult('Session', $api_base . '/v3/payment/acq/session', $http_code, $response_body, $http_body);

        if ($http_code === 200 && is_array($response_data)) {
            if (!empty($response_data['error_code']) && $response_data['error_code'] !== '0' && $response_data['error_code'] !== '000000') {
                return array(
                    'ret_code' => $response_data['error_code'],
                    'ret_msg'  => isset($response_data['error_description']) ? $response_data['error_description'] : __('Session creation failed', 'paykka-for-woocommerce'),
                );
            }
            // 兼容两种返回：顶层 session_id/session_url 或 data.session_id/data.session_url
            $data = isset($response_data['data']) && is_array($response_data['data']) ? $response_data['data'] : $response_data;
            $session_id = isset($data['session_id']) ? $data['session_id'] : '';
            $session_url = isset($data['session_url']) ? $data['session_url'] : '';
            return array(
                'ret_code' => '000000',
                'ret_msg'  => '',
                'data'     => array(
                    'session_id' => $session_id,
                    'session_url' => $session_url,
                ),
            );
        }

        if (is_array($response_data) && isset($response_data['ret_code'])) {
            return $response_data;
        }
        return array(
            'ret_code' => (string) $http_code,
            'ret_msg'  => isset($response_data['ret_msg']) ? $response_data['ret_msg'] : $response_body,
        );
    }

    /**
     * 交易查询（v3）
     * 文档: https://docs.paykka.com/zh-hans/payments/apis/payments/openapi/交易/payments-query-opl_1
     * 接口: POST /v3/payment/acq/query
     *
     * @param string $trans_id  商户订单号（Woo 订单号）
     * @param string $order_id  PayKKa 订单号
     * @param string $session_id PayKKa 收银台 ID
     * @return array
     */
    public function queryPayment($trans_id = '', $order_id = '', $session_id = '')
    {
        $trans_id = trim((string) $trans_id);
        $order_id = trim((string) $order_id);
        $session_id = trim((string) $session_id);
        if ($trans_id === '' && $order_id === '' && $session_id === '') {
            return array('ret_code' => '400', 'ret_msg' => 'Missing query identifiers');
        }

        $paykkaSettings = getPaykkaSettings();
        $merchant_id = $paykkaSettings['paykka_merchant_id'];
        $private_key = $paykkaSettings['paykka_private_key'];
        $sandbox = get_option('paykka_sandbox_flag') === 'yes';
        $app_id = $sandbox ? get_option('paykka_sandbox_app_id', '') : get_option('paykka_app_id', '');
        if ($app_id === '') {
            $app_id = $merchant_id;
        }

        $payload = array('merchant_id' => $merchant_id);
        if ($trans_id !== '') {
            $payload['trans_id'] = $trans_id;
        }
        if ($order_id !== '') {
            $payload['order_id'] = $order_id;
        }
        if ($session_id !== '') {
            $payload['session_id'] = $session_id;
        }

        $timestamp = (string) round(microtime(true) * 1000);
        $nonce = (string) wp_rand(1000000000000000, 9999999999999999);
        $http_body = wp_json_encode($payload);
        if (!is_string($http_body) || $http_body === '') {
            return array('ret_code' => '500', 'ret_msg' => 'Invalid query payload');
        }

        $request_path = '/v3/payment/acq/query';
        $signStr = $this->paykkaSignV3('POST', $request_path, $timestamp, $nonce, $http_body, $private_key);
        if ($signStr === null) {
            return array('ret_code' => '500', 'ret_msg' => 'Query signature error');
        }

        $headers = array(
            'Content-Type'       => 'application/json',
            'x-paykka-appid'     => $app_id,
            'x-paykka-timestamp' => $timestamp,
            'x-paykka-nonce'     => $nonce,
            'x-paykka-sign-alg'  => 'SHA256_WITH_RSA',
            'x-paykka-sign'      => $signStr,
        );

        $api_base = $this->getPaykkaApiBaseUrl();
        $response = wp_remote_post($api_base . $request_path, array(
            'headers' => $headers,
            'body' => $http_body,
            'timeout' => 16,
        ));
        if (is_wp_error($response)) {
            return array(
                'ret_code' => 'WP_ERROR',
                'ret_msg' => $response->get_error_message(),
            );
        }

        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);
        $http_code = wp_remote_retrieve_response_code($response);
        $this->logPaykkaResult('PaymentQuery', $api_base . $request_path, $http_code, $response_body, $http_body);

        if ($http_code === 200 && is_array($response_data)) {
            $ret_code = isset($response_data['ret_code']) ? (string) $response_data['ret_code'] : '';
            $error_code = isset($response_data['error_code']) ? (string) $response_data['error_code'] : '';
            if ($ret_code !== '' && $ret_code !== '0' && $ret_code !== '000000') {
                return array(
                    'ret_code' => $ret_code,
                    'ret_msg'  => isset($response_data['ret_msg']) ? (string) $response_data['ret_msg'] : __('Query failed', 'paykka-for-woocommerce'),
                    'data'     => $response_data,
                );
            }
            if ($error_code !== '' && $error_code !== '0' && $error_code !== '000000') {
                return array(
                    'ret_code' => $error_code,
                    'ret_msg'  => isset($response_data['error_description']) ? (string) $response_data['error_description'] : __('Query failed', 'paykka-for-woocommerce'),
                    'data'     => $response_data,
                );
            }
            // 兼容两种查询响应：
            // 1) ret_code/ret_msg + data.{status,order_id...}
            // 2) error_code/error_description + 顶层 {status,order_id...}
            $query_data = isset($response_data['data']) && is_array($response_data['data']) ? $response_data['data'] : $response_data;
            unset($query_data['ret_code'], $query_data['ret_msg'], $query_data['error_code'], $query_data['error_description']);
            $result = array_merge(
                array(
                    'ret_code' => '000000',
                    'ret_msg' => isset($response_data['ret_msg']) ? (string) $response_data['ret_msg'] : (isset($response_data['error_description']) ? (string) $response_data['error_description'] : ''),
                ),
                $query_data
            );
            $result['raw'] = $response_data;
            return $result;
        }

        if (is_array($response_data) && isset($response_data['ret_code'])) {
            return $response_data;
        }
        return array(
            'ret_code' => (string) $http_code,
            'ret_msg'  => is_array($response_data) && isset($response_data['ret_msg']) ? (string) $response_data['ret_msg'] : (string) $response_body,
        );
    }

    /**
     * 根据查询结果同步 Woo 订单状态，并写入 PayKKa 订单号（若有）
     *
     * @param \WC_Order $order
     * @param array     $query_result
     * @param string    $source 备注来源：callback / webhook
     * @return void
     */
    public function syncOrderByQueryResult($order, $query_result, $source = '')
    {
        if (!$order || !is_a($order, 'WC_Order') || !is_array($query_result)) {
            return;
        }

        $status = '';
        if (isset($query_result['status'])) {
            $status = strtoupper((string) $query_result['status']);
        } elseif (isset($query_result['data']['status'])) {
            $status = strtoupper((string) $query_result['data']['status']);
        }

        $paykka_order_id = '';
        if (!empty($query_result['order_id'])) {
            $paykka_order_id = (string) $query_result['order_id'];
        } elseif (!empty($query_result['data']['order_id'])) {
            $paykka_order_id = (string) $query_result['data']['order_id'];
        }
        if ($paykka_order_id !== '') {
            $order->update_meta_data('_paykka_order_id', sanitize_text_field($paykka_order_id));
        }
        switch ($status) {
            case 'SUCCESS':
                // 支付成功后统一落到 processing（而非 completed），便于后续发货流程处理
                if (!in_array($order->get_status(), array('processing', 'completed'), true)) {
                    $order->payment_complete();
                }
                if ($order->get_status() === 'completed') {
                    $order->update_status('processing', 'PayKKa status: SUCCESS');
                }
                break;
            case 'PROCESSING':
                if (!in_array($order->get_status(), array('processing', 'completed', 'on-hold'), true)) {
                    $order->update_status('on-hold', 'PayKKa status: PROCESSING');
                } else {
                    $order->add_order_note('PayKKa status: PROCESSING' . ($source !== '' ? ' (' . $source . ')' : ''));
                }
                break;
            case 'AUTHORIZED':
                if (!in_array($order->get_status(), array('processing', 'completed', 'on-hold'), true)) {
                    $order->update_status('on-hold', 'PayKKa authorized, awaiting capture.');
                }
                break;
            case 'FAILURE':
                $order->update_status('failed', 'PayKKa status: FAILURE');
                break;
            case 'CANCELED':
                $order->update_status('cancelled', 'PayKKa status: CANCELED');
                break;
            case 'REFUNDED':
                $order->update_status('refunded', 'PayKKa status: REFUNDED');
                break;
            case 'PARTIALLY_REFUNDED':
                $order->add_order_note('PayKKa status: PARTIALLY_REFUNDED' . ($source !== '' ? ' (' . $source . ')' : ''));
                break;
            case 'PARTIALLY_REVERSED':
                $order->add_order_note('PayKKa status: PARTIALLY_REVERSED' . ($source !== '' ? ' (' . $source . ')' : ''));
                break;
            default:
                if ($status !== '') {
                    $order->add_order_note('PayKKa status: ' . $status . ($source !== '' ? ' (' . $source . ')' : ''));
                }
                break;
        }
        $order->save();
    }


    public function handlerCardPayment($order, $card_encrypted_data)
    {
        $paykkaSettings = getPaykkaSettings();
        $PAYKKA_MERCHANT_ID = $paykkaSettings['paykka_merchant_id'];
        $PAYKKA_API_KEY = $paykkaSettings['paykka_private_key'];

        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        // 转换为香港时间
        $now->setTimezone(new \DateTimeZone('Asia/Hong_Kong'));
        // 使用 DateInterval 对象来添加 5 分钟
        $now->add(new \DateInterval('PT5M'));
        $expire_time = $now->format('Y-m-d H:i:s');
        $timestamp = round(microtime(true) * 1000);

        $callback_url = PaykkaCallBackHandler::getCallbackUrl($order->get_id());
        $notify_url = PaykkaWebHookHandler::getWebHookUrl();

        // 币种金额
        $decimal_places = get_option('woocommerce_price_num_decimals', 2);
        $order_amount = intval(round($order->get_total() * pow(10, $decimal_places)));

        $paymentRequest = new PaymentRequest();
        $paymentRequest->version = 'v1.2';
        $paymentRequest->__set('merchant_id', $PAYKKA_MERCHANT_ID);
        $paymentRequest->__set('payment_type', 'PURCHASE');
        $paymentRequest->__set('trans_id', $order->get_id());
        $paymentRequest->__set('timestamp', $timestamp);
        $paymentRequest->__set('currency', $order->get_currency());
        $paymentRequest->__set('amount', $order_amount);
        $paymentRequest->__set('notify_url', $notify_url);
        $paymentRequest->__set('return_url', $callback_url);
        $paymentRequest->__set('expire_time', $expire_time);

        // 设置仅授权
        $paykka_capture_method_flag = get_option('paykka_capture_method_flag');
        if ($paykka_capture_method_flag == 'yes') {
            $paymentRequest->__set('capture_method', 'MANUAL');
        }

        $paymentRequest->bill = $this->buildBill($order);
        $paymentRequest->shipping = $this->buildShipping($order);
        $paymentRequest->goods = $this->buildGoodsItems($order);
        $paymentRequest->customer = $this->buildCustomer($order);
        $paymentRequest->browser = new Browser();

        $card_encrypted = is_array($card_encrypted_data) ? $card_encrypted_data : (array) json_decode($card_encrypted_data, true);
        $payment = new PaymentInfo();
        $payment->encrypted_card_no = isset($card_encrypted['encryptedCardNumber']) ? $card_encrypted['encryptedCardNumber'] : '';
        $payment->encrypted_exp_year = isset($card_encrypted['encryptedExpireYear']) ? $card_encrypted['encryptedExpireYear'] : '';
        $payment->encrypted_exp_month = isset($card_encrypted['encryptedExpireMonth']) ? $card_encrypted['encryptedExpireMonth'] : '';
        $payment->encrypted_cvv = isset($card_encrypted['encryptedCVV']) ? $card_encrypted['encryptedCVV'] : '';
        $payment->payment_method = 'BANKCARD';
        $paymentRequest->payment = $payment;

        $http_body = $paymentRequest->toJson();
        $signStr = $this->paykkaSign($PAYKKA_MERCHANT_ID, $timestamp, $http_body, $PAYKKA_API_KEY);
        $headers = array(
            'Content-Type' => 'application/json',
            'signature' => $signStr,
            'type' => 'RSA256'
        );
        $api_base = $this->getPaykkaApiBaseUrl();
        $response = wp_remote_post($api_base . '/apis/payments', array(
            'headers' => $headers,
            'body' => $http_body,
            'timeout' => 16,
        ));
        if (is_wp_error($response)) {
            wc_add_notice('Payment error: ' . $response->get_error_message(), 'error');
            return;
        }
        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);
        $http_code = wp_remote_retrieve_response_code($response);
        $this->logPaykkaResult('CardPayment', $api_base . '/apis/payments', $http_code, $response_body, $http_body);
        return $response_data;
    }


    public function handlerGooglePayPayment($order, $google_token)
    {

        $paykkaSettings = getPaykkaSettings();
        $PAYKKA_MERCHANT_ID = $paykkaSettings['paykka_merchant_id'];
        $PAYKKA_API_KEY = $paykkaSettings['paykka_private_key'];

        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        // 转换为香港时间
        $now->setTimezone(new \DateTimeZone('Asia/Hong_Kong'));
        // 使用 DateInterval 对象来添加 5 分钟
        $now->add(new \DateInterval('PT5M'));
        $expire_time = $now->format('Y-m-d H:i:s');
        $timestamp = round(microtime(true) * 1000);

        $callback_url = PaykkaCallBackHandler::getCallbackUrl($order->get_id());
        $notify_url = PaykkaWebHookHandler::getWebHookUrl();

        // 币种金额
        $decimal_places = get_option('woocommerce_price_num_decimals', 2);
        $order_amount = intval(round($order->get_total() * pow(10, $decimal_places)));

        $paymentRequest = new PaymentRequest();
        $paymentRequest->version = 'v1.2';
        $paymentRequest->__set('merchant_id', $PAYKKA_MERCHANT_ID);
        $paymentRequest->__set('payment_type', 'PURCHASE');
        $paymentRequest->__set('trans_id', $order->get_id());
        $paymentRequest->__set('timestamp', $timestamp);
        $paymentRequest->__set('currency', $order->get_currency());
        $paymentRequest->__set('amount', $order_amount);
        $paymentRequest->__set('notify_url', $notify_url);
        $paymentRequest->__set('return_url', $callback_url);
        $paymentRequest->__set('expire_time', $expire_time);

        // 设置仅授权
        $paykka_capture_method_flag = get_option('paykka_capture_method_flag');
        if ($paykka_capture_method_flag == 'yes') {
            $paymentRequest->__set('capture_method', 'MANUAL');
        }

        $paymentRequest->bill = $this->buildBill($order);
        $paymentRequest->shipping = $this->buildShipping($order);
        $paymentRequest->goods = $this->buildGoodsItems($order);
        $paymentRequest->customer = $this->buildCustomer($order);
        $paymentRequest->browser = new Browser();

        $payment = new PaymentInfo();
        $payment->payment_method = 'GOOGLE_PAY';
        $payment->token_data = $google_token;

        $paymentRequest->payment = $payment;

        $http_body = $paymentRequest->toJson();
        $signStr = $this->paykkaSign($PAYKKA_MERCHANT_ID, $timestamp, $http_body, $PAYKKA_API_KEY);
        $headers = array(
            'Content-Type' => 'application/json',
            'signature' => $signStr,
            'type' => 'RSA256'
        );
        $api_base = $this->getPaykkaApiBaseUrl();
        $response = wp_remote_post($api_base . '/apis/payments', array(
            'headers' => $headers,
            'body' => $http_body,
            'timeout' => 16,
        ));
        if (is_wp_error($response)) {
            wc_add_notice('Payment error: ' . $response->get_error_message(), 'error');
            return;
        }
        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);
        $http_code = wp_remote_retrieve_response_code($response);
        $this->logPaykkaResult('GooglePay', $api_base . '/apis/payments', $http_code, $response_body, $http_body);
        return $response_data;
    }

    /**
     * 打印 Paykka 请求结果到 error_log（便于排查）
     * 当 WP_DEBUG 或 WP_DEBUG_LOG 为 true，或后台勾选「记录请求结果日志」时输出。
     *
     * @param string $api_name      接口名称，如 Session / CardPayment / GooglePay
     * @param string $request_url  请求完整 URL
     * @param int    $http_code    HTTP 状态码
     * @param string $response_body 响应体
     * @param string $request_body  请求体（仅在开启日志时输出，敏感信息注意）
     */
    private function logPaykkaResult($api_name, $request_url, $http_code, $response_body, $request_body = '')
    {
        if (!function_exists('paykka_is_log_enabled') || !paykka_is_log_enabled()) {
            return;
        }
        error_log('[Paykka] === ' . $api_name . ' ===');
        error_log('[Paykka] Request URL: ' . $request_url);
        error_log('[Paykka] HTTP Code: ' . (string) $http_code);
        error_log('[Paykka] Response: ' . (string) $response_body);
        if ($request_body !== '') {
            error_log('[Paykka] Request Body: ' . $request_body);
        }
    }

    /**
     * 根据沙箱/生产配置返回 Paykka 后端 API 基地址（生产环境走生产域名）
     *
     * @return string
     */
    private function getPaykkaApiBaseUrl()
    {
        if (function_exists('paykka_get_api_base_url')) {
            return paykka_get_api_base_url();
        }
        $sandbox = get_option('paykka_sandbox_flag') === 'yes';
        if ($sandbox) {
            return 'https://openapi-sandbox.paykka.com';
        }
        $region = get_option('paykka_api_region', 'eu');
        return $region === 'hk' ? 'https://openapi.aq.paykka.com' : 'https://openapi.eu.paykka.com';
    }

    /**
     * Paykka API 要求 bill.areaCode / shipping.areaCode 长度为 0-10
     *
     * @param string|null $value
     * @return string
     */
    private function truncateAreaCode($value)
    {
        if ($value === null || $value === '') {
            return '';
        }
        $s = is_string($value) ? $value : (string) $value;
        return substr($s, 0, 10);
    }

    private function buildBill($order)
    {
        $bill = new Bill();
        $bill->first_name = $order->get_billing_first_name();
        $bill->last_name = $order->get_billing_last_name();
        $bill->address_line1 = $order->get_billing_address_1();
        $bill->address_line2 = $order->get_billing_address_2();

        $bill->country = $order->get_billing_country();
        $bill->state = $order->get_billing_state();
        $bill->city = $order->get_billing_city();
        $bill->postal_code = $order->get_billing_postcode();

        $bill->email = $order->get_billing_email();
        $bill_phone_number = $order->get_billing_phone();
        $bill->area_code = '';
        $bill->phone_number = '';
        if (!empty($bill_phone_number)) {
            $raw_phone = trim((string) $bill_phone_number);
            $phone_parts = preg_split('/\s+/', $raw_phone, 2);
            if (is_array($phone_parts) && count($phone_parts) === 2 && $phone_parts[1] !== '') {
                $bill->area_code = $this->truncateAreaCode($phone_parts[0]);
                $bill->phone_number = $phone_parts[1];
            } else {
                // 未单独提供国家区号时，仅写入号码，避免把整串号码误写入 area_code
                $bill->phone_number = $raw_phone;
            }
        }
        return $bill;
    }


    private function buildShipping($order)
    {
        $ship = new Shipping();
        $ship->first_name = $order->get_shipping_first_name();
        $ship->last_name = $order->get_shipping_last_name();
        $ship->address_line1 = $order->get_shipping_address_1();
        $ship->address_line2 = $order->get_shipping_address_2();

        $ship->country = $order->get_shipping_country();
        $ship->state = $order->get_shipping_state();
        $ship->city = $order->get_shipping_city();
        $ship->postal_code = $order->get_shipping_postcode();

        $ship->area_code = '';
        $ship->phone_number = '';
        $ship_phone_number = $order->get_shipping_phone();
        if ($ship_phone_number !== null && $ship_phone_number !== '') {
            $raw_phone = trim((string) $ship_phone_number);
            $phone_parts = preg_split('/\s+/', $raw_phone, 2);
            if (is_array($phone_parts) && count($phone_parts) === 2 && $phone_parts[1] !== '') {
                $ship->area_code = $this->truncateAreaCode($phone_parts[0]);
                $ship->phone_number = $phone_parts[1];
            } else {
                // 未单独提供国家区号时，仅写入号码，避免把整串号码误写入 area_code
                $ship->phone_number = $raw_phone;
            }
        }
        return $ship;
    }

    private function buildCustomer($order)
    {
        $customer = new PayCustomer();
        $customer->id = $order->get_user_id();
        $customer->order_ip = $order->get_customer_ip_address() ?: '';
        $customer->pay_ip = $order->get_customer_ip_address() ?: '127.0.0.1';
        return $customer;
    }

    private function buildGoodsItems($order)
    {
        $decimal_places = get_option('woocommerce_price_num_decimals', 2);
        $goods_items = array();
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            if (!$product || !is_a($product, 'WC_Product')) {
                continue;
            }
            $goods = new Goods();
            $goods->id = $product->get_id();
            $goods->name = $product->get_name();
            $goods->description = $product->get_description();
            $goods->link = $product->get_permalink();
            $goods->price = intval(round($product->get_price() * pow(10, $decimal_places)));
            $goods->quantity = $item->get_quantity();
            $goods->delivery_date = $product->get_meta('_delivery_date');
            $goods->picture_url = get_post_meta($goods->id, '_picture_url', true);
            $goods_items[] = $goods;
        }
        return $goods_items;
    }


    /**
     * V3 签名（收银台等 v3 接口）
     * 文档: https://docs.paykka.com/zh-hans/payments/apis/introduction/api-certification
     * 签名串：HTTP方法\nURL路径\n时间戳\n随机串\n请求报文主体（每行以 \n 结束，参数为空也需 \n）
     *
     * @param string $method      如 POST
     * @param string $request_path 如 /v3/payment/acq/session（不含域名，无查询参数则不拼 ?）
     * @param string $timestamp   毫秒时间戳
     * @param string $nonce       防重放随机串
     * @param string $body        请求体 JSON 字符串
     * @param string $private_key 商户私钥（PEM 或裸 base64）
     * @return string|null 签名值（Base64 + URLEncode），失败返回 null
     */
    public function paykkaSignV3($method, $request_path, $timestamp, $nonce, $body, $private_key)
    {
        $method = $method !== '' ? $method : ' ';
        $request_path = $request_path !== '' ? $request_path : ' ';
        $timestamp = (string) $timestamp;
        $nonce = $nonce !== '' ? $nonce : ' ';
        $body = $body !== null && $body !== '' ? $body : ' ';

        $content = $method . "\n" . $request_path . "\n" . $timestamp . "\n" . $nonce . "\n" . $body;

        $pem = $this->normalizePrivateKeyPem($private_key);
        if ($pem === null) {
            return null;
        }

        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            if (function_exists('paykka_is_debug') && paykka_is_debug()) {
                while ($err = openssl_error_string()) {
                    error_log('[Paykka] ' . $err);
                }
            }
            return null;
        }

        $signature = '';
        $ok = openssl_sign($content, $signature, $key, OPENSSL_ALGO_SHA256);
        openssl_free_key($key);
        if (!$ok || $signature === '') {
            return null;
        }

        $base64 = base64_encode($signature);
        return rawurlencode($base64);
    }

    /**
     * 将私钥转为 PEM 字符串
     */
    private function normalizePrivateKeyPem($key)
    {
        $key = trim($key);
        if ($key === '') {
            return null;
        }
        if (strpos($key, '-----BEGIN') === 0) {
            return $key;
        }
        return "-----BEGIN PRIVATE KEY-----\n" . chunk_split(str_replace(array("\r", "\n", " "), '', $key), 64, "\n") . "-----END PRIVATE KEY-----\n";
    }

    /**
     * 旧版签名（v1/非 v3 接口如 /apis/payments 等如仍使用可保留）
     */
    public function paykkaSign($merchantId, $timestamp, $requestBody, $PAYKKA_API_KEY)
    {
        $content = sprintf("merchantId=%s&timestamp=%s&requestBody=%s", $merchantId, $timestamp, $requestBody);
        $pem = $this->normalizePrivateKeyPem($PAYKKA_API_KEY);
        if ($pem === null) {
            return '';
        }
        $privateKey = openssl_pkey_get_private($pem);
        if (!$privateKey) {
            if (function_exists('paykka_is_debug') && paykka_is_debug()) {
                while ($error = openssl_error_string()) {
                    error_log($error);
                }
            }
            return '';
        }
        $signature = null;
        openssl_sign($content, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        openssl_free_key($privateKey);
        if ($signature === false) {
            return '';
        }
        return rawurlencode(base64_encode($signature));
    }
}