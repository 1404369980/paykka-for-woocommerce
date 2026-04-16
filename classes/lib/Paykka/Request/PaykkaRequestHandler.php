<?php
namespace lib\Paykka\Request;

use lib\Paykka\Api\Bill;
use lib\Paykka\Api\Shipping;
use lib\Paykka\Api\Goods;
use lib\Paykka\Api\PayCustomer;
use lib\Paykka\Api\PaymentInfo;
use lib\Paykka\Api\PaymentRequest;
use lib\Paykka\Request\PaykkaWebHookHandler;
use lib\Paykka\Request\PaykkaCallBackHandler;


$paykka_base = defined('PAYKKA_PLUGIN_PATH') ? PAYKKA_PLUGIN_PATH : (defined('FENGQIAO_PAYKKA_URL') ? FENGQIAO_PAYKKA_URL : '');
require_once $paykka_base . 'classes/lib/Paykka/Api/Bill.php';
require_once $paykka_base . 'classes/lib/Paykka/Api/Shipping.php';
require_once $paykka_base . 'classes/lib/Paykka/Api/Goods.php';
require_once $paykka_base . 'classes/lib/Paykka/Api/PayCustomer.php';
require_once $paykka_base . 'classes/lib/Paykka/Api/PaymentInfo.php';
require_once $paykka_base . 'classes/lib/Paykka/Api/PaymentRequest.php';
require_once $paykka_base . 'classes/lib/Paykka/Request/PaykkaWebHookHandler.php';
require_once $paykka_base . 'classes/lib/Paykka/Request/PaykkaCallBackHandler.php';

class PaykkaRequestHandler
{

    // ========================================================================
    // 公共基础方法：签名、header 构造、HTTP 请求、响应解析
    // ========================================================================

    /**
     * 获取 API 基地址（生产/沙箱/地区）
     */
    public function getPaykkaApiBaseUrl()
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
     * 获取当前环境的 app_id（沙箱/生产），不填则退回到 merchant_id
     */
    public function getAppId($merchant_id = '')
    {
        $sandbox = get_option('paykka_sandbox_flag') === 'yes';
        $app_id = $sandbox ? get_option('paykka_sandbox_app_id', '') : get_option('paykka_app_id', '');
        if ($app_id === '' && $merchant_id !== '') {
            $app_id = $merchant_id;
        }
        return $app_id;
    }

    /**
     * V3 签名
     * 文档: https://docs.paykka.com/zh-hans/payments/apis/introduction/api-certification
     *
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
        // PHP 8.0+：OpenSSLAsymmetricKey 自动释放；openssl_free_key() 已弃用。
        if (!$ok || $signature === '') {
            return null;
        }

        return rawurlencode(base64_encode($signature));
    }

    /**
     * 旧版签名（v1 接口如 /apis/payments）
     */
    public function paykkaSign($merchantId, $timestamp, $requestBody, $private_key)
    {
        $content = sprintf("merchantId=%s&timestamp=%s&requestBody=%s", $merchantId, $timestamp, $requestBody);
        $pem = $this->normalizePrivateKeyPem($private_key);
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
        if ($signature === false) {
            return '';
        }
        return rawurlencode(base64_encode($signature));
    }

    /**
     * 构造 v3 请求 header（含签名）
     *
     * @return array|null header 数组，签名失败返回 null
     */
    public function buildV3Headers($request_path, $http_body, $private_key, $app_id)
    {
        $timestamp = (string) round(microtime(true) * 1000);
        $nonce = (string) wp_rand(1000000000000000, 9999999999999999);
        $signStr = $this->paykkaSignV3('POST', $request_path, $timestamp, $nonce, $http_body, $private_key);
        if ($signStr === null) {
            return null;
        }
        return array(
            'Content-Type'       => 'application/json',
            'x-paykka-appid'     => $app_id,
            'x-paykka-timestamp' => $timestamp,
            'x-paykka-nonce'     => $nonce,
            'x-paykka-sign-alg'  => 'SHA256_WITH_RSA',
            'x-paykka-sign'      => $signStr,
        );
    }

    /**
     * 发送 v3 POST 请求并返回解析后的响应
     *
     * @param string $request_path  如 /v3/payment/acq/session
     * @param string $http_body     JSON 字符串
     * @param string $api_name      日志标识
     * @return array ['http_code'=>int, 'response_data'=>array|null, 'response_body'=>string, 'wp_error'=>string|null]
     */
    public function postApi($request_path, $http_body, $headers, $api_name = 'API')
    {
        $api_base = $this->getPaykkaApiBaseUrl();
        $url = $api_base . $request_path;
        $response = wp_remote_post($url, array(
            'headers' => $headers,
            'body' => $http_body,
            'timeout' => 16,
        ));
        if (is_wp_error($response)) {
            $this->logPaykkaResult($api_name, $url, 0, $response->get_error_message(), $http_body);
            return array(
                'http_code' => 0,
                'response_data' => null,
                'response_body' => '',
                'wp_error' => $response->get_error_message(),
            );
        }
        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);
        $http_code = wp_remote_retrieve_response_code($response);
        $this->logPaykkaResult($api_name, $url, $http_code, $response_body, $http_body);
        return array(
            'http_code' => $http_code,
            'response_data' => $response_data,
            'response_body' => $response_body,
            'wp_error' => null,
        );
    }

    /**
     * 统一解析 v3 接口响应（兼容 ret_code/error_code 两种格式）
     *
     * @param array $api_result postApi() 返回值
     * @param bool  $flatten_data 是否将 data 节点展平到顶层
     * @return array 统一格式 ['ret_code'=>..., 'ret_msg'=>..., ...]
     */
    public function parseV3Response($api_result, $flatten_data = false)
    {
        if (!empty($api_result['wp_error'])) {
            return array('ret_code' => 'WP_ERROR', 'ret_msg' => $api_result['wp_error']);
        }

        $http_code = isset($api_result['http_code']) ? (int) $api_result['http_code'] : 0;
        $response_data = isset($api_result['response_data']) && is_array($api_result['response_data']) ? $api_result['response_data'] : null;
        $response_body = isset($api_result['response_body']) ? (string) $api_result['response_body'] : '';

        if ($http_code === 200 && is_array($response_data)) {
            $ret_code = isset($response_data['ret_code']) ? (string) $response_data['ret_code'] : '';
            $error_code = isset($response_data['error_code']) ? (string) $response_data['error_code'] : '';

            if ($ret_code !== '' && $ret_code !== '0' && $ret_code !== '000000') {
                return array(
                    'ret_code' => $ret_code,
                    'ret_msg'  => isset($response_data['ret_msg']) ? (string) $response_data['ret_msg'] : 'Failed',
                    'data'     => $response_data,
                );
            }
            if ($error_code !== '' && $error_code !== '0' && $error_code !== '000000' && $error_code !== '0000') {
                return array(
                    'ret_code' => $error_code,
                    'ret_msg'  => isset($response_data['error_description']) ? (string) $response_data['error_description'] : 'Failed',
                    'data'     => $response_data,
                );
            }

            $ret_msg = isset($response_data['ret_msg']) ? (string) $response_data['ret_msg']
                : (isset($response_data['error_description']) ? (string) $response_data['error_description'] : '');

            if ($flatten_data) {
                $query_data = isset($response_data['data']) && is_array($response_data['data']) ? $response_data['data'] : $response_data;
                unset($query_data['ret_code'], $query_data['ret_msg'], $query_data['error_code'], $query_data['error_description']);
                $result = array_merge(array('ret_code' => '000000', 'ret_msg' => $ret_msg), $query_data);
                $result['raw'] = $response_data;
                return $result;
            }
            return array_merge(array('ret_code' => '000000', 'ret_msg' => $ret_msg), $response_data);
        }

        if (is_array($response_data) && isset($response_data['ret_code'])) {
            return $response_data;
        }
        return array(
            'ret_code' => (string) $http_code,
            'ret_msg'  => is_array($response_data) && isset($response_data['ret_msg']) ? (string) $response_data['ret_msg'] : $response_body,
        );
    }

    /**
     * 将私钥转为 PEM 字符串
     */
    public function normalizePrivateKeyPem($key)
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
     * 打印请求结果到 error_log
     */
    public function logPaykkaResult($api_name, $request_url, $http_code, $response_body, $request_body = '')
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

    // ========================================================================
    // 业务方法：Hosted Session / 交易查询 / 退款 / 退款查询
    // ========================================================================

    public function buildSessionUrl($order): mixed
    {
        return $this->handlerSession($order, 'HOSTED');
    }

    /**
     * 创建收银台 Session（Hosted）
     * 接口: POST /v3/payment/acq/session
     */
    public function handlerSession($order, $session_mode)
    {
        $paykkaSettings = getPaykkaSettings();
        $merchant_id = $paykkaSettings['paykka_merchant_id'];
        $private_key = $paykkaSettings['paykka_private_key'];
        $app_id = $this->getAppId($merchant_id);

        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $now->setTimezone(new \DateTimeZone('Asia/Hong_Kong'));
        $now->add(new \DateInterval('PT5M'));
        $expire_time = $now->format('Y-m-d\TH:i:sO');

        $callback_url = PaykkaCallBackHandler::getCallbackUrl($order->get_id());
        $notify_url = PaykkaWebHookHandler::getWebHookUrl();
        $cancel_url = get_option('paykka_cancel_url', $callback_url);

        $decimal_places = get_option('woocommerce_price_num_decimals', 2);
        $order_amount = intval(round($order->get_total() * pow(10, $decimal_places)));

        $paymentRequest = new PaymentRequest();
        $paymentRequest->__set('merchant_id', $merchant_id);
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
        $paymentRequest->__set('capture_method', $paykka_capture_method_flag === 'yes' ? 'MANUAL' : 'AUTOMATIC');

        $paymentRequest->bill = $this->buildBill($order);
        $paymentRequest->shipping = $this->buildShipping($order);
        $paymentRequest->goods = $this->buildGoodsItems($order);
        $paymentRequest->customer = $this->buildCustomer($order);
        $paymentRequest->payment = new PaymentInfo();

        $http_body = $paymentRequest->toJson();
        $request_path = '/v3/payment/acq/session';
        $headers = $this->buildV3Headers($request_path, $http_body, $private_key, $app_id);
        if ($headers === null) {
            wc_add_notice(__('Payment signature error', 'paykka-for-woocommerce'), 'error');
            return null;
        }

        $api_result = $this->postApi($request_path, $http_body, $headers, 'Session');
        if (!empty($api_result['wp_error'])) {
            wc_add_notice('Payment error: ' . $api_result['wp_error'], 'error');
            return null;
        }

        $parsed = $this->parseV3Response($api_result);
        if (isset($parsed['ret_code']) && $parsed['ret_code'] === '000000') {
            $data = isset($parsed['data']) && is_array($parsed['data']) ? $parsed['data'] : $parsed;
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
        return $parsed;
    }

    /**
     * 交易查询（v3）
     * 接口: POST /v3/payment/acq/query
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
        $app_id = $this->getAppId($merchant_id);

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

        $http_body = wp_json_encode($payload);
        if (!is_string($http_body) || $http_body === '') {
            return array('ret_code' => '500', 'ret_msg' => 'Invalid query payload');
        }

        $request_path = '/v3/payment/acq/query';
        $headers = $this->buildV3Headers($request_path, $http_body, $private_key, $app_id);
        if ($headers === null) {
            return array('ret_code' => '500', 'ret_msg' => 'Query signature error');
        }

        $api_result = $this->postApi($request_path, $http_body, $headers, 'PaymentQuery');
        return $this->parseV3Response($api_result, true);
    }

    /**
     * 发起退款（v3）
     * 接口: POST /v3/payment/acq/refund
     */
    public function refundPayment($order, $amount, $reason = 'REQUESTED_BY_CUSTOMER', $refund_trans_id = '')
    {
        if (!$order || !is_a($order, 'WC_Order')) {
            return array('ret_code' => '400', 'ret_msg' => 'Invalid order');
        }
        $paykka_order_id = trim((string) $order->get_meta('_paykka_order_id', true));
        if ($paykka_order_id === '') {
            return array('ret_code' => '400', 'ret_msg' => 'Missing PayKKa order_id');
        }

        $paykkaSettings = getPaykkaSettings();
        $merchant_id = $paykkaSettings['paykka_merchant_id'];
        $private_key = $paykkaSettings['paykka_private_key'];
        $app_id = $this->getAppId($merchant_id);

        $decimal_places = get_option('woocommerce_price_num_decimals', 2);
        $refund_amount_minor = intval(round((float) $amount * pow(10, $decimal_places)));
        if ($refund_amount_minor <= 0) {
            return array('ret_code' => '400', 'ret_msg' => 'Invalid refund amount');
        }

        if ($refund_trans_id === '') {
            $refund_trans_id = 'R' . $order->get_id() . '-' . gmdate('YmdHis') . '-' . wp_rand(1000, 9999);
        }
        $reason = trim((string) $reason);
        $allowed_reasons = array('DUPLICATE', 'FRAUDULENT', 'REQUESTED_BY_CUSTOMER', 'OTHER');
        if (!in_array($reason, $allowed_reasons, true)) {
            $reason = 'OTHER';
        }

        $payload = array(
            'merchant_id' => $merchant_id,
            'refund_trans_id' => $refund_trans_id,
            'ori_order_id' => $paykka_order_id,
            'reason' => $reason,
            'refund_amount' => $refund_amount_minor,
            'currency' => $order->get_currency(),
            'notify_url' => PaykkaWebHookHandler::getWebHookUrl(),
        );

        $http_body = wp_json_encode($payload);
        if (!is_string($http_body) || $http_body === '') {
            return array('ret_code' => '500', 'ret_msg' => 'Invalid refund payload');
        }

        $request_path = '/v3/payment/acq/refund';
        $headers = $this->buildV3Headers($request_path, $http_body, $private_key, $app_id);
        if ($headers === null) {
            return array('ret_code' => '500', 'ret_msg' => 'Refund signature error');
        }

        $api_result = $this->postApi($request_path, $http_body, $headers, 'Refund');
        $parsed = $this->parseV3Response($api_result);
        if (isset($parsed['ret_code']) && $parsed['ret_code'] === '000000') {
            $parsed['refund_trans_id'] = $refund_trans_id;
        }
        return $parsed;
    }

    /**
     * 退款查询（v3）
     * 接口: POST /v3/payment/acq/refund/query
     */
    public function queryRefund($refund_trans_id = '', $refund_order_id = '')
    {
        $refund_trans_id = trim((string) $refund_trans_id);
        $refund_order_id = trim((string) $refund_order_id);
        if ($refund_trans_id === '' && $refund_order_id === '') {
            return array('ret_code' => '400', 'ret_msg' => 'Missing refund query identifiers');
        }

        $paykkaSettings = getPaykkaSettings();
        $merchant_id = $paykkaSettings['paykka_merchant_id'];
        $private_key = $paykkaSettings['paykka_private_key'];
        $app_id = $this->getAppId($merchant_id);

        $payload = array('merchant_id' => $merchant_id);
        if ($refund_trans_id !== '') {
            $payload['refund_trans_id'] = $refund_trans_id;
        }
        if ($refund_order_id !== '') {
            $payload['refund_order_id'] = $refund_order_id;
        }

        $http_body = wp_json_encode($payload);
        if (!is_string($http_body) || $http_body === '') {
            return array('ret_code' => '500', 'ret_msg' => 'Invalid refund query payload');
        }

        $request_path = '/v3/payment/acq/refund/query';
        $headers = $this->buildV3Headers($request_path, $http_body, $private_key, $app_id);
        if ($headers === null) {
            return array('ret_code' => '500', 'ret_msg' => 'Refund query signature error');
        }

        $api_result = $this->postApi($request_path, $http_body, $headers, 'RefundQuery');
        // 与 queryPayment 一致展平 data，便于 syncOrderByRefundQueryResult 读取 status / refund_order_id 等
        return $this->parseV3Response($api_result, true);
    }

    // ========================================================================
    // 订单状态同步
    // ========================================================================

    /**
     * 根据交易查询结果同步 Woo 订单状态，并写入 PayKKa 订单号
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

    /**
     * 根据退款查询结果同步订单状态与退款信息
     */
    private function refundSourceLabel($source)
    {
        return '';
    }

    private function addRefundNoteOnce($order, $note)
    {
        $note = trim((string) $note);
        if ($note === '') {
            return;
        }
        $last_note = (string) $order->get_meta('_paykka_last_refund_note', true);
        if ($last_note === $note) {
            return;
        }
        $order->add_order_note($note);
        $order->update_meta_data('_paykka_last_refund_note', $note);
    }

    private function buildRefundOpsSuffix($order, $refund_trans_id, $refund_amount_minor, $decimal_places)
    {
        $parts = array();
        $refund_trans_id = trim((string) $refund_trans_id);
        if ($refund_trans_id !== '') {
            $parts[] = 'Refund ID ' . $refund_trans_id;
        }
        if ((int) $refund_amount_minor > 0) {
            $amount = (float) $refund_amount_minor / pow(10, (int) $decimal_places);
            $parts[] = 'Amount ' . $order->get_currency() . ' ' . number_format($amount, (int) $decimal_places, '.', '');
        }
        if (empty($parts)) {
            return '';
        }
        return ' (' . implode(', ', $parts) . ')';
    }

    public function syncOrderByRefundQueryResult($order, $refund_query_result, $source = '')
    {
        if (!$order || !is_a($order, 'WC_Order') || !is_array($refund_query_result)) {
            return;
        }

        $status = isset($refund_query_result['status']) ? strtoupper((string) $refund_query_result['status']) : '';
        $refund_order_id = isset($refund_query_result['refund_order_id']) ? (string) $refund_query_result['refund_order_id'] : '';
        $refund_trans_id = isset($refund_query_result['refund_trans_id']) ? (string) $refund_query_result['refund_trans_id'] : '';
        if ($refund_order_id !== '') {
            $order->update_meta_data('_paykka_refund_order_id', sanitize_text_field($refund_order_id));
        }
        if ($refund_trans_id !== '') {
            $order->update_meta_data('_paykka_refund_trans_id', sanitize_text_field($refund_trans_id));
        }

        $refund_amount_minor = isset($refund_query_result['amount']) ? (int) $refund_query_result['amount'] : 0;
        $decimal_places = get_option('woocommerce_price_num_decimals', 2);
        $order_total_minor = intval(round((float) $order->get_total() * pow(10, $decimal_places)));
        $ops_suffix = $this->buildRefundOpsSuffix($order, $refund_trans_id, $refund_amount_minor, $decimal_places);

        switch ($status) {
            case 'SUCCESS':
                if ($refund_amount_minor > 0 && $refund_amount_minor >= $order_total_minor) {
                    $order->update_status('refunded', 'PayKKa refund SUCCESS');
                } else {
                    $this->addRefundNoteOnce($order, 'PayKKa refund succeeded.' . $ops_suffix);
                }
                break;
            case 'REVIEWING':
            case 'PROCESSING':
                $this->addRefundNoteOnce($order, 'PayKKa refund is processing.' . $ops_suffix);
                break;
            case 'FAILURE':
                $deleted_refund_id = 0;
                if (function_exists('paykka_delete_wc_refund_matching_trans_id')) {
                    $allow_revert = apply_filters(
                        'paykka_auto_delete_local_refund_on_gateway_failure',
                        true,
                        $order,
                        $refund_trans_id,
                        $refund_query_result,
                        $source
                    );
                    if ($allow_revert && $refund_trans_id !== '') {
                        $deleted_refund_id = paykka_delete_wc_refund_matching_trans_id($order, $refund_trans_id);
                    }
                }
                if ($deleted_refund_id > 0) {
                    $reloaded = wc_get_order($order->get_id());
                    if ($reloaded && is_a($reloaded, 'WC_Order')) {
                        $order = $reloaded;
                    }
                }
                $this->addRefundNoteOnce(
                    $order,
                    'PayKKa refund failed.' . $this->buildRefundOpsSuffix($order, $refund_trans_id, $refund_amount_minor, $decimal_places)
                );
                if ($deleted_refund_id > 0) {
                    $this->addRefundNoteOnce(
                        $order,
                        __('PayKKa: The local WooCommerce refund record was automatically removed.', 'paykka-for-woocommerce')
                    );
                }
                break;
            default:
                if ($status !== '') {
                    $this->addRefundNoteOnce($order, 'PayKKa refund status updated.' . $ops_suffix);
                }
                break;
        }
        $order->save();
    }

    // ========================================================================
    // 订单数据构建
    // ========================================================================

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
}
