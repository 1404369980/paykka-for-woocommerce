<?php
/**
 * Paykka_Credit_Card_Gateway class.
 *
 * @extends WC_Payment_Gateway
 */

use lib\Paykka\Request\PaykkaRequestHandler;
use lib\Paykka\Request\PaykkaWebHookHandler;
use lib\Paykka\Request\PaykkaCallBackHandler;
use lib\Paykka\Api\Browser;


class Paykka_Encrypted_Card_Gateway extends WC_Payment_Gateway
{
    private $merchant_id;

    public function __construct()
    {
        $this->id = 'paykka-encrypted-card';
        $this->has_fields = false;
        // $this->version = '8.2.0';
        $this->icon = '';
        $this->method_description = __('PayKKa Paykka Encrypted Card Gateway Card payments.', 'paykka-for-woocommerce');
        $this->method_title = __('PayKKa Paykka Encrypted Card Gateway Card', 'paykka-for-woocommerce');

        $this->title = __('PayKKa Paykka Encrypted Card Gateway Card', 'paykka-for-woocommerce');
        $this->description = __('Use PayKKa Paykka Encrypted Card Gateway Card to securely pay with your card.', 'paykka-for-woocommerce');

        $this->supports = array(
            'products',
            'refunds',
        );

        // 具有所有选项字段的方法
        $this->init_form_fields();

        // 加载设置。
        $this->init_settings();

        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->testmode = 'yes' === $this->get_option('testmode');
        $this->private_key = $this->testmode ? $this->get_option('sandbox_private_key') : $this->get_option('private_key');

        $this->publishable_key = $this->testmode ? $this->get_option('test_publishable_key') : $this->get_option('publishable_key');
        $this->merchant_id = $this->testmode ? $this->get_option('merchant_id') : $this->get_option('sandbox_merchant_id');
        $this->client_key = $this->get_option('client_key');
        // 这个动作挂钩保存设置
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));

        add_action('rest_api_init', [$this, 'register_encrypted_card_endpoint'], 30);
    }

    public function init_form_fields()
    {

        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'paykka-for-woocommerce'),
                'label' => __('Enable PayKKa Payment Gateway', 'paykka-for-woocommerce'),
                'type' => 'checkbox',
                'description' => '',
                'default' => 'no'
            ),
            'title' => array(
                'title' => __('Title', 'paykka-for-woocommerce'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'paykka-for-woocommerce'),
                'default' => __('Credit Card', 'paykka-for-woocommerce'),
                'desc_tip' => true
            ),
            'description' => array(
                'title' => __('Description', 'paykka-for-woocommerce'),
                'type' => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'paykka-for-woocommerce'),
                'default' => __('Pay securely with your credit card.', 'paykka-for-woocommerce'),
                'desc_tip' => true
            ),
            'sandbox' => array(
                'title' => __('Sandbox', 'paykka-for-woocommerce'),
                'label' => __('Enable Sandbox Mode', 'paykka-for-woocommerce'),
                'type' => 'checkbox',
                'description' => __('Place the payment gateway in sandbox mode using sandbox API keys (real payments will not be taken).', 'paykka-for-woocommerce'),
                'default' => 'yes'
            ),
            'sandbox_public_key' => array(
                'title' => __('Sandbox Public Key', 'paykka-for-woocommerce'),
                'type' => 'textarea',
                'description' => __('Get your API keys from your PayKKa account.', 'paykka-for-woocommerce'),
                'default' => '',
                'desc_tip' => true,
                'custom_attributes' => array('autocomplete' => 'new-password'),
            ),
            'sandbox_private_key' => array(
                'title' => __('Sandbox Private Key', 'paykka-for-woocommerce'),
                'type' => 'textarea',
                'description' => __('Get your API keys from your PayKKa account.', 'paykka-for-woocommerce'),
                'default' => '',
                'desc_tip' => true,
                'custom_attributes' => array('autocomplete' => 'new-password'),
            ),
            'sandbox_merchant_id' => array(
                'title' => __('Sandbox Merchant ID', 'paykka-for-woocommerce'),
                'type' => 'textarea',
                'description' => __('Get your API keys from your PayKKa account.', 'paykka-for-woocommerce'),
                'default' => '',
                'desc_tip' => true,
                'custom_attributes' => array('autocomplete' => 'new-password'),
            ),
            'public_key' => array(
                'title' => __('Live Public Key', 'paykka-for-woocommerce'),
                'type' => 'textarea',
                'description' => __('Get your API keys from your PayKKa account.', 'paykka-for-woocommerce'),
                'default' => '',
                'desc_tip' => true,
                'custom_attributes' => array('autocomplete' => 'new-password'),
            ),
            'private_key' => array(
                'title' => __('Live Private Key', 'paykka-for-woocommerce'),
                'type' => 'textarea',
                'description' => __('Get your API keys from your PayKKa account.', 'paykka-for-woocommerce'),
                'default' => '',
                'desc_tip' => true,
                'custom_attributes' => array('autocomplete' => 'new-password'),
            ),
            'merchant_id' => array(
                'title' => __('Live Merchant ID', 'paykka-for-woocommerce'),
                'type' => 'textarea',
                'description' => __('Get your API keys from your PayKKa account.', 'paykka-for-woocommerce'),
                'default' => '',
                'desc_tip' => true
            ),
            'client_key' => array(
                'title' => __('Client Key', 'paykka-for-woocommerce'),
                'type' => 'textarea',
                'description' => __('Get your API keys from your PayKKa account.', 'paykka-for-woocommerce'),
                'default' => '',
                'desc_tip' => true
            ),

        );
    }

    public function process_admin_options()
    {
        parent::process_admin_options();
    }

    public function payment_fields()
    {
    }

    public function payment_scripts()
    {

        // echo '<script>console.log("准备下单")</script>';
    }

    public function validate_fields()
    {
        return true;
    }

    public function is_available()
    {
        // 检查支付方式是否已启用
        if ($this->enabled !== 'yes') {
            return false;
        }

        // 额外调试
        if (!is_checkout()) {
            // error_log('PayKKa Gateway is NOT available: Not on checkout page.');
            return true;
        }

        $test = $this->enabled === 'yes';
        // error_log('Payment gateway is available: ' . $this->enabled . ' :bool: ' . $test);
        return true;
    }

    public function receipt_page($order_id)
    {

    }


    public function process_payment($order_id)
    {

        ob_start();
        // 真实代码
        $order = wc_get_order($order_id);
        $order->update_status('pending', '等待跳转到收银台');
        ob_end_clean();
        $page = get_page_by_path('paykka-card-encrypted');
        return [
            'result' => 'success',
            'redirect' => add_query_arg('order_id', $order_id, get_permalink($page->ID)),
        ];
    }



    public function handler_encrypted_card(\WP_REST_Request $request)
    {
        require_once FENGQIAO_PAYKKA_URL . 'classes/lib/Paykka/Request/PaykkaRequestHandler.php';
        require_once FENGQIAO_PAYKKA_URL . '/classes/lib/Paykka/Request/PaykkaWebHookHandler.php';
        require_once FENGQIAO_PAYKKA_URL . '/classes/lib/Paykka/Request/PaykkaCallBackHandler.php';
        // require_once FENGQIAO_PAYKKA_URL . '/classes/lib/Paykka/Api/Browser.php';
        error_log("PaykkaRequestHandler: \n");

        $params = $request->get_params();
        // $browser_info_data = sanitize_text_field($params['browser_info']);
        // $browser_info = json_decode($browser_info_data, true);

        // $browser = new Browser();
        // $browser -> user_agent = $browser_info['userAgent'];
        // $browser -> color_depth = $browser_info['colorDepth'];
        // $browser -> language = $browser_info['language'];
        // // $browser -> java_enabled = $browser_info['userAgent'];
        // // $browser -> device_type = $browser_info['userAgent'];
        // // $browser -> terminal_type = $browser_info['userAgent'];
        // // $browser -> device_os = $browser_info['userAgent'];
        // $browser -> timezone_offset = $browser_info['timezone'];
        // $browser -> screen_height = $browser_info['screenHeight'];
        // $browser -> screen_width = $browser_info['screenWidth'];
        // $browser -> device_finger_print_id = $browser_info['userAgent'];
        // $browser -> fraud_detection_id = $browser_info['userAgent'];

        $encrypted_card_data = $params['encrypted_card_data'];
        $order_id = $params['order_id'];

        $order = wc_get_order($order_id);

        $paykkaPaymentHelper = new PaykkaRequestHandler();
        error_log("PaykkaRequestHandler: \n");
        $response_data = $paykkaPaymentHelper->handlerCardPayment($order, $this->merchant_id, $this->private_key, $encrypted_card_data);
        error_log("payment_complete: \n");
        $order->payment_complete();

        if (isset($response_data['ret_code']) && $response_data['ret_code'] === '000000') {
            return new WP_REST_Response([
                'success' => true,
                'redirect_url' => $this->get_return_url($order)
            ]);
        } else {
            $error_message = isset($response_data['ret_msg']) ? sanitize_text_field($response_data['ret_msg']) : __('Payment processing failed', 'your-text-domain');
            
            // 记录详细日志
            error_log('[Paykka Payment Error]:\n'. print_r( $response_data, true));
            return new WP_REST_Response([
                'success' => false,
                'message' => $error_message
            ]);
        }
    }

    public function register_encrypted_card_endpoint()
    {
        error_log('Webhook endpoint registered'); // 调试日志
        register_rest_route('paykka/v1', '/encrypted_card', array(
            'methods' => 'POST',
            'callback' => array($this, 'handler_encrypted_card'),
            'permission_callback' => '__return_true',
        ));
        error_log('注册webhook成功register_encrypted_card_endpoint');

    }
}