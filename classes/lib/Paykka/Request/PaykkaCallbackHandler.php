<?php
namespace lib\Paykka\Request;

class PaykkaCallBackHandler
{
    // private $gateway;
    /**
     * 初始化 Webhook 处理类
     */
    public function __construct()
    {
   
        // 注册 Webhook 端点
        add_action('woocommerce_api_wc_gateway_paykka_payment_callback', array($this, 'handle_payment_callback'));
    }

    public static function getCallbackUrl($order_id){
        return add_query_arg('wc-api', 'WC_Gateway_Paykka_Payment_callback', home_url('/')) . "&order_id=" . $order_id;
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

        // require_once plugin_basename('classes/wc-paykka-credit-card-gateway.php');
        // $gateway =  new \Paykka_Credit_Card_Gateway();
        // $return_url = $gateway->get_return_url($order);
        // $return_url = $this -> gateway->get_return_url($order);

        ob_end_clean();
        wp_safe_redirect(wc_get_account_endpoint_url('orders'));
        exit;
    }


}