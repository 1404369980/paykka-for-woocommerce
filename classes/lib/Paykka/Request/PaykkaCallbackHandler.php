<?php
namespace lib\Paykka\Request;

class PaykkaCallBackHandler
{
    public static $CALLBACK_CODE = 'WC_Gateway_Paykka_Payment_callback';
    private $gateway;
    /**
     * 初始化 Webhook 处理类
     */
    public function __construct()
    {
   
        // 注册 Webhook 端点
        add_action('woocommerce_api_wc_gateway_paykka_payment_callback', array($this, 'handle_payment_callback'));
    }

    public function handle_payment_callback()
    {
        ob_start(); // 开启输出缓冲区
       

        $order_id = $_REQUEST['order_id'];
        //  error_log('');
        $order = wc_get_order($order_id);

        $order->payment_complete();
        WC()->cart->empty_cart();
        wc_reduce_stock_levels($order_id);

        require_once FENGQIAO_PAYKKA_URL . '/classes/wc-paykka-credit-card-gateway.php';
        $gateway =  new \Paykka_Credit_Card_Gateway();
        $return_url = $gateway->get_return_url($order);
        // $return_url = $this -> gateway->get_return_url($order);

        ob_end_clean();
        wp_safe_redirect($return_url);
        exit;
    }


}