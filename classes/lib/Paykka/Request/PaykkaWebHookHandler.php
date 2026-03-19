<?php
namespace lib\Paykka\Request;

class PaykkaWebHookHandler
{
    public static $WEB_HOOK_URL = '/paykka/v1/webhook';
    /**
     * 初始化 Webhook 处理类
     */
    public function __construct()
    {
        // 注册 Webhook 端点
        add_action('rest_api_init', array($this, 'register_webhook_endpoint'));
    }

    public static function getWebHookUrl()
    {
        return rest_url(PaykkaWebHookHandler::$WEB_HOOK_URL);
    }


    /**
     * 注册 Webhook 端点
     */
    public function register_webhook_endpoint()
    {
        // error_log('Webhook endpoint registered'); // 调试日志
        register_rest_route('paykka/v1', '/webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_paykka_webhook_request'),
            'permission_callback' => '__return_true',
        ));
        // error_log('注册webhook成功');
    }


    function handle_paykka_webhook_request($request)
    {
        // 获取请求数据
        $payload = $request->get_body();
        $data = json_decode($payload, true);
        $this->process_payment_webhook($data);
        return new \WP_REST_Response(array('ret_code' => '000000', 'ret_msg' => 'Success'), 200);
    }


    // 处理 Webhook 数据
    public function process_payment_webhook($webHookOrder)
    {
        if (!is_array($webHookOrder) || empty($webHookOrder['trans_id'])) {
            if (function_exists('paykka_is_debug') && paykka_is_debug()) {
                error_log('[Paykka Webhook] Invalid payload: ' . wp_json_encode($webHookOrder));
            }
            return;
        }

        $order_id = $webHookOrder['trans_id'];
        $payment_status = isset($webHookOrder['status']) ? (string) $webHookOrder['status'] : '';

        $order = wc_get_order($order_id);
        if (!$order || !$order->get_id()) {
            if (function_exists('paykka_is_debug') && paykka_is_debug()) {
                error_log('[Paykka Webhook] Order not found: ' . $order_id);
            }
            return;
        }

        require_once PAYKKA_PLUGIN_PATH . 'classes/lib/Paykka/Request/PaykkaRequestHandler.php';
        $paykkaPaymentHelper = new PaykkaRequestHandler();
        $query_result = $paykkaPaymentHelper->queryPayment((string) $order_id, '', '');
        if (is_array($query_result) && isset($query_result['ret_code']) && $query_result['ret_code'] === '000000') {
            $paykkaPaymentHelper->syncOrderByQueryResult($order, $query_result, 'webhook');
            return;
        }

        if (!empty($webHookOrder['order_id'])) {
            $order->update_meta_data('_paykka_order_id', sanitize_text_field((string) $webHookOrder['order_id']));
            $order->save();
        }

        if (function_exists('paykka_is_debug') && paykka_is_debug()) {
            error_log('[Paykka Webhook] Query failed fallback to payload status. order_id=' . $order_id . ' query=' . wp_json_encode($query_result));
        }
        switch ($payment_status) {
            case 'SUCCESS':
                $order->payment_complete();
                break;
            case 'AUTHORIZED':
                $order->update_status('on-hold', 'PayKKa authorized, awaiting capture.');
                break;
            case 'FAILURE':
            case 'CANCELED':
                $order->update_status('failed', 'Payment Failed');
                break;
            case 'REFUNDED':
                $order->update_status('refunded', 'Payment Refunded');
                break;
            default:
                if ($payment_status === '') {
                    $order->add_order_note('PayKKa webhook received without status, query failed.');
                    break;
                }
                if (function_exists('paykka_is_debug') && paykka_is_debug()) {
                    error_log('[Paykka Webhook] Unhandled status: ' . $payment_status);
                }
                break;
        }
    }


    /**
     * 验证 Webhook 签名（若 Paykka 提供 secret 可在后台配置后在此校验）
     * 当前 handle_paykka_webhook_request 未调用此方法，按需接入。
     */
    public function validate_webhook($data)
    {
        $secret = get_option('paykka_webhook_secret', '');
        if ($secret === '' || empty($_SERVER['HTTP_X_PAYKKA_SIGNATURE'])) {
            return false;
        }
        $expected = hash_hmac('sha256', is_string($data) ? $data : wp_json_encode($data), $secret);
        return hash_equals($expected, sanitize_text_field(wp_unslash($_SERVER['HTTP_X_PAYKKA_SIGNATURE'])));
    }

}