<?php
/**
 * Paykka_Credit_Card_Gateway class.
 *
 * @extends WC_Payment_Gateway
 */

use lib\Paykka\Request\PaykkaRequestHandler;


class Paykka_Drop_In_Gateway extends WC_Payment_Gateway
{
    private $merchant_id;

    public function __construct()
    {
        $this->id = 'paykka-drop-in';
        $this->has_fields = false;
        // $this->version = '8.2.0';
        $this->icon = '';
        $this->method_description = __('PayKKa Drop In Card payments.', 'paykka-for-woocommerce');
        $this->method_title = __('PayKKa Drop In Card', 'paykka-for-woocommerce');

        $this->title = __('PayKKa Drop In Card', 'paykka-for-woocommerce');
        $this->description = __('Use PayKKa Drop In Card to securely pay with your card.', 'paykka-for-woocommerce');

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

        // add_action('rest_api_init', [$this, 'register_drop_in_endpoint'], 30);
        add_action('rest_api_init', [$this, 'register_drop_in_session_endpoint'], 30);

    }


    // 保存元数据到订单
    public function register_drop_in_session_endpoint()
    {
        register_rest_route('paykka/v1', '/drop-in/session', array(
            'methods' => 'POST',
            'callback' => array($this, 'handler_drop_in_session'),
            'permission_callback' => '__return_true',
        ));
        error_log('注册webhook成功register_drop_in_session_endpoint');

    }


    public function handler_drop_in_session(\WP_REST_Request $request)
    {

        $params = $request->get_params();
        error_log('$params: ' . print_r($params, true));
        // $this -> encrypted_card_info_data = $params['encrypted_card_data'];
        // wp_cache_set( 'encrypted_card_data', $params['encrypted_card_data'], 'user_meta', 0 );
        error_log('is_user_logged_in' . is_user_logged_in() . ' ==== ' . get_current_user_id());

        if (!is_user_logged_in()) {
            throw new Exception('please please log in first');
        }
        $user_id = get_current_user_id();
        set_transient('encrypted_card_data' . $user_id, $params['encrypted_card_data'], 20);

        error_log('encrypted_card_data' . print_r(get_transient('encrypted_card_data'), true));
        return new WP_REST_Response([
            'success' => true,
            'message' => '10086'
        ]);
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
                'default' => __('PayKKa Drop In Card', 'paykka-for-woocommerce'),
                'desc_tip' => true
            ),
            'description' => array(
                'title' => __('Description', 'paykka-for-woocommerce'),
                'type' => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'paykka-for-woocommerce'),
                'default' => __('Pay securely with PayKKa Drop In Card payments', 'paykka-for-woocommerce'),
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
        error_log('encrypted_card_data' . print_r(get_transient('encrypted_card_data'), true));
        ob_start();
        $order = wc_get_order($order_id);
        $order->update_status('pending', '等待跳转到收银台');

        try {
            $this->handler_drop_in($order_id);
        } catch (Exception $e) {
            return [
                'result' => 'failure',
                'message' => $e->getMessage()
            ];
        }

        ob_end_clean();
        return ['result' => 'success', 'redirect' => $order->get_checkout_order_received_url()];

    }


    public function handler_drop_in($order_id)
    {
        require_once FENGQIAO_PAYKKA_URL . 'classes/lib/Paykka/Request/PaykkaRequestHandler.php';
        require_once FENGQIAO_PAYKKA_URL . '/classes/lib/Paykka/Request/PaykkaWebHookHandler.php';
        require_once FENGQIAO_PAYKKA_URL . '/classes/lib/Paykka/Request/PaykkaCallBackHandler.php';
        // require_once FENGQIAO_PAYKKA_URL . '/classes/lib/Paykka/Api/Browser.php';


        // wp_cache_get( 'user_billing_address_' . $user_id, 'user_meta' )

        if (!is_user_logged_in()) {
            throw new Exception('please please log in first');
        }
        $user_id = get_current_user_id();
        error_log('$get_current_user_id: ' . $user_id);


        $encrypted_card_data = get_transient('encrypted_card_data' . $user_id);
        $card_encrypted_encode = json_encode($encrypted_card_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $encrypted_card_decode = json_decode($card_encrypted_encode, true);
        if ( empty($encrypted_card_decode) || empty($encrypted_card_decode['encryptedCardNumber'])){
            throw new Exception('Payment card processing failed');
        }

        error_log('$encrypted_card_data1: ' . print_r($encrypted_card_data, true));

        $order = wc_get_order($order_id);

        $paykkaPaymentHelper = new PaykkaRequestHandler();
        error_log("encrypted_card_data: \n" . $encrypted_card_data);
        $response_data = $paykkaPaymentHelper->handlerCardPayment($order, $this->merchant_id, $this->private_key, $encrypted_card_data);
        error_log("payment_complete: \n");
        $order->payment_complete();


        if (!is_array($response_data)) {
            throw new Exception('Invalid API response format');
        }

        if (!isset($response_data['ret_code']) || !$response_data['ret_code'] === '000000') {
            $error_message = isset($response_data['ret_msg']) ? sanitize_text_field($response_data['ret_msg']) : __('Payment processing failed', 'your-text-domain');
            error_log(sprintf(
                '[Paykka Payment Error] Order %s - Code: %s, Message: %s',
                $order instanceof WC_Order ? $order->get_id() : 'N/A',
                $error_code,
                $error_message
            ));

            throw new Exception($error_message);
        }
    }
}