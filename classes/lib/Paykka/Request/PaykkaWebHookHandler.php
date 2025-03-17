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


      /**
     * 注册 Webhook 端点
     */
    public function register_webhook_endpoint() {
        error_log('Webhook endpoint registered'); // 调试日志
        register_rest_route('paykka/v1', '/webhook', array(
            'methods'  => 'POST',
            'callback' => array($this, 'handle_paykka_webhook_request'),
            'permission_callback' => '__return_true',
        ));
        error_log('注册webhook成功');
    }


    function handle_paykka_webhook_request($request) {
        // 获取请求数据
        $payload = $request->get_body();
        $data = json_decode($payload, true);
        $this ->process_payment_webhook($data);
        return new \WP_REST_Response(array('ret_code' => '000000', 'ret_msg'=> 'Success'), 200);
    }
    

    // 处理 Webhook 数据
    public function process_payment_webhook($webHookOrder)
    {
        error_log('process_payment_webhook:' . $webHookOrder . '');
        $order_id = $webHookOrder['trans_id'];
        $payment_status = $webHookOrder['status'];

        $order = wc_get_order($order_id);
        if (!$order) {
            error_log('订单id未找到:' . $order_id . '');
            return;
        }

        switch ($payment_status) {
            case 'SUCCESS':
                $order->update_status('completed', 'Payment Successful');
                break;
            case 'FAILURE':
                $order->update_status('failed', 'Payment Failed');
                break;
            case 'REFUNDED':
                $order->update_status('refunded', 'Payment Refunded');
                break;
            default:
                error_log('不支持的交易状态' . $payment_status . '');
                break;
        }
    }


    // 验证 Webhook 请求
    public function validate_webhook($data)
    {
        $signature = $_SERVER['HTTP_X_PAYKKA_SIGNATURE'];
        $secret_key = 'your_secret_key';
        $expected_signature = hash_hmac('sha256', json_encode($data), $secret_key);
        return hash_equals($expected_signature, $signature);
    }

}