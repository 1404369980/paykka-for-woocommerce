<?php
/**
 * Paykka_Credit_Card_Gateway class.
 *
 * @extends WC_Payment_Gateway
 */

use lib\Paykka\Request\PaykkaRequestHandler;

class Paykka_Credit_Card_Gateway extends WC_Payment_Gateway
{
    /** @var string 插件版本（避免 PHP 8.2+ 动态属性弃用） */
    public $version = '';

    /** @var bool */
    public $testmode = false;

    /** @var string */
    public $private_key = '';

    /** @var string */
    public $publishable_key = '';

    /** @var string */
    private $merchant_id = '';

    public function __construct()
    {
        $this->id = 'paykka';
        $this->has_fields = false;
        $this->version = '8.2.0';
        $this->icon = '';
        $this->method_description = __('与 PayPal 类似：结账页仅显示「Paykka」一项，顾客选择后直接进入对应支付流程。支付模式在「支付方式」中选定。', 'paykka-for-woocommerce');
        $this->method_title = __('Paykka', 'paykka-for-woocommerce');

        $this->title = __('Paykka', 'paykka-for-woocommerce');
        $this->description = __('使用 Paykka 安全支付', 'paykka-for-woocommerce');

        $this->supports = array(
            'products',
            'refunds',
        );

        // 具有所有选项字段的方法
        $this->init_form_fields();

        // 加载设置。
        $this->init_settings();

        $this->enabled = get_option('paykka_enabled', 'yes');
        $this->title = get_option('paykka_title', __('Paykka', 'paykka-for-woocommerce'));
        $this->description = get_option('paykka_description', __('使用 Paykka 安全支付', 'paykka-for-woocommerce'));
        $this->testmode = 'yes' === $this->get_option('testmode');
        $this->private_key = $this->testmode ? $this->get_option('sandbox_private_key') : $this->get_option('private_key');

        $this->publishable_key = $this->testmode ? $this->get_option('test_publishable_key') : $this->get_option('publishable_key');
        $this->merchant_id = $this->testmode ? $this->get_option('sandbox_merchant_id') : $this->get_option('merchant_id');
        // 这个动作挂钩保存设置
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
        add_action('rest_api_init', array($this, 'register_paykka_rest_routes'), 30);
        add_action('woocommerce_order_refunded', 'paykka_attach_refund_link_on_order_refunded', 10, 2);
    }

    public function register_paykka_rest_routes()
    {
        register_rest_route('paykka/v1', '/drop-in/session', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handler_drop_in_session'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route('paykka/v1', '/encrypted_card', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handler_encrypted_card'),
            'permission_callback' => '__return_true',
        ));
    }

    public function handler_drop_in_session(\WP_REST_Request $request)
    {
        if (!is_user_logged_in()) {
            return new \WP_REST_Response(array('success' => false, 'message' => __('Please log in first', 'paykka-for-woocommerce')), 401);
        }
        $params = $request->get_params();
        $encrypted = isset($params['encrypted_card_data']) ? $params['encrypted_card_data'] : null;
        if (empty($encrypted)) {
            return new \WP_REST_Response(array('success' => false, 'message' => __('Missing card data', 'paykka-for-woocommerce')), 400);
        }
        $user_id = get_current_user_id();
        set_transient('encrypted_card_data' . $user_id, $encrypted, 20);
        return new \WP_REST_Response(array('success' => true));
    }

    public function handler_encrypted_card(\WP_REST_Request $request)
    {
        $params = $request->get_params();
        $order_id = isset($params['order_id']) ? absint($params['order_id']) : 0;
        $encrypted_card_data = isset($params['encrypted_card_data']) ? $params['encrypted_card_data'] : null;
        if (!$order_id || !is_array($encrypted_card_data)) {
            return new \WP_REST_Response(array('success' => false, 'message' => __('Invalid request', 'paykka-for-woocommerce')), 400);
        }
        $order = wc_get_order($order_id);
        if (!$order || !$order->get_id()) {
            return new \WP_REST_Response(array('success' => false, 'message' => __('Order not found', 'paykka-for-woocommerce')), 404);
        }
        $paykkaPaymentHelper = new PaykkaRequestHandler();
        $response_data = $paykkaPaymentHelper->handlerCardPayment($order, $encrypted_card_data);
        if (isset($response_data['ret_code']) && $response_data['ret_code'] === '000000') {
            if (!empty($response_data['order_id'])) {
                $order->update_meta_data('_paykka_order_id', sanitize_text_field((string) $response_data['order_id']));
            }
            if (!in_array($order->get_status(), array('processing', 'completed', 'on-hold'), true)) {
                $order->update_status('on-hold', __('PayKKa payment accepted, awaiting confirmation', 'paykka-for-woocommerce'));
            } else {
                $order->add_order_note(__('PayKKa payment accepted, awaiting confirmation', 'paykka-for-woocommerce'));
            }
            $order->save();
            return new \WP_REST_Response(array(
                'success'       => true,
                'redirect_url'  => $this->get_return_url($order),
            ));
        }
        $error_message = isset($response_data['ret_msg']) ? sanitize_text_field($response_data['ret_msg']) : __('Payment processing failed', 'paykka-for-woocommerce');
        if (function_exists('paykka_is_debug') && paykka_is_debug() && is_array($response_data)) {
            error_log('[Paykka Encrypted Card Error] ' . wp_json_encode($response_data));
        }
        return new \WP_REST_Response(array('success' => false, 'message' => $error_message));
    }

    public function init_form_fields()
    {

        // $this->form_fields = array(
        //     'enabled' => array(
        //         'title' => __('Enable/Disable', 'paykka-for-woocommerce'),
        //         'label' => __('Enable PayKKa Payment Gateway', 'paykka-for-woocommerce'),
        //         'type' => 'checkbox',
        //         'description' => '',
        //         'default' => 'no'
        //     ),
        //     'title' => array(
        //         'title' => __('Title', 'paykka-for-woocommerce'),
        //         'type' => 'text',
        //         'description' => __('This controls the title which the user sees during checkout.', 'paykka-for-woocommerce'),
        //         'default' => __('PayKKa Hosted Page', 'paykka-for-woocommerce'),
        //         'desc_tip' => true
        //     ),
        //     'description' => array(
        //         'title' => __('Description', 'paykka-for-woocommerce'),
        //         'type' => 'textarea',
        //         'description' => __('This controls the description which the user sees during checkout.', 'paykka-for-woocommerce'),
        //         'default' => __('PayKKa Hosted Page payments.', 'paykka-for-woocommerce'),
        //         'desc_tip' => true
        //     ),
        //     'sandbox' => array(
        //         'title' => __('Sandbox', 'paykka-for-woocommerce'),
        //         'label' => __('Enable Sandbox Mode', 'paykka-for-woocommerce'),
        //         'type' => 'checkbox',
        //         'description' => __('Place the payment gateway in sandbox mode using sandbox API keys (real payments will not be taken).', 'paykka-for-woocommerce'),
        //         'default' => 'yes'
        //     ),
        //     'sandbox_public_key' => array(
        //         'title' => __('Sandbox Public Key', 'paykka-for-woocommerce'),
        //         'type' => 'textarea',
        //         'description' => __('Get your API keys from your PayKKa account.', 'paykka-for-woocommerce'),
        //         'default' => '',
        //         'desc_tip' => true,
        //         'custom_attributes' => array('autocomplete' => 'new-password'),
        //     ),
        //     'sandbox_private_key' => array(
        //         'title' => __('Sandbox Private Key', 'paykka-for-woocommerce'),
        //         'type' => 'textarea',
        //         'description' => __('Get your API keys from your PayKKa account.', 'paykka-for-woocommerce'),
        //         'default' => '',
        //         'desc_tip' => true,
        //         'custom_attributes' => array('autocomplete' => 'new-password'),
        //     ),
        //     'sandbox_merchant_id' => array(
        //         'title' => __('Sandbox Merchant ID', 'paykka-for-woocommerce'),
        //         'type' => 'textarea',
        //         'description' => __('Get your API keys from your PayKKa account.', 'paykka-for-woocommerce'),
        //         'default' => '',
        //         'desc_tip' => true,
        //         'custom_attributes' => array('autocomplete' => 'new-password'),
        //     ),
        //     'public_key' => array(
        //         'title' => __('Live Public Key', 'paykka-for-woocommerce'),
        //         'type' => 'textarea',
        //         'description' => __('Get your API keys from your PayKKa account.', 'paykka-for-woocommerce'),
        //         'default' => '',
        //         'desc_tip' => true,
        //         'custom_attributes' => array('autocomplete' => 'new-password'),
        //     ),
        //     'private_key' => array(
        //         'title' => __('Live Private Key', 'paykka-for-woocommerce'),
        //         'type' => 'textarea',
        //         'description' => __('Get your API keys from your PayKKa account.', 'paykka-for-woocommerce'),
        //         'default' => '',
        //         'desc_tip' => true,
        //         'custom_attributes' => array('autocomplete' => 'new-password'),
        //     ),
        //     'merchant_id' => array(
        //         'title' => __('Live Merchant ID', 'paykka-for-woocommerce'),
        //         'type' => 'textarea',
        //         'description' => __('Get your API keys from your PayKKa account.', 'paykka-for-woocommerce'),
        //         'default' => '',
        //         'desc_tip' => true
        //     ),
        // );
    }


    // 🔥 核心：重写后台设置页 UI
    public function admin_options()
    {
        $current_tab = isset($_GET['subtab']) ? sanitize_text_field(wp_unslash($_GET['subtab'])) : 'connection';

        echo '<h2>' . esc_html($this->get_method_title()) . '</h2>';
        echo '<nav class="nav-tab-wrapper">';

        $tabs = array(
            'connection' => __('连接设置', 'paykka-for-woocommerce'),
            'standard'   => __('标准支付', 'paykka-for-woocommerce'),
            'methods'    => __('支付方式', 'paykka-for-woocommerce'),
            'advanced'   => __('高级设置', 'paykka-for-woocommerce'),
        );

        foreach ($tabs as $key => $label) {
            $class = ($current_tab === $key) ? 'nav-tab nav-tab-active' : 'nav-tab';
            $url = admin_url('admin.php?page=wc-settings&tab=checkout&section=paykka&subtab=' . $key);
            echo '<a href="' . esc_url($url) . '" class="' . esc_attr($class) . '">' . esc_html($label) . '</a>';
        }

        echo '</nav>';

        $subtab = isset($_GET['subtab']) ? sanitize_text_field($_GET['subtab']) : 'connection';
        echo '<input type="hidden" name="paykka_current_subtab" value="' . esc_attr($subtab) . '">';
        $settings = $this->get_settings_fields($subtab);
        WC_Admin_Settings::output_fields($settings);
    }

    // 保存设置：从 POST 读取当前 tab，避免保存时 subtab 丢失导致 Client Key 等未保存
    public function process_admin_options()
    {
        $subtab = isset($_POST['paykka_current_subtab']) ? sanitize_text_field(wp_unslash($_POST['paykka_current_subtab'])) : (isset($_GET['subtab']) ? sanitize_text_field($_GET['subtab']) : 'connection');
        $allowed = array('connection', 'standard', 'methods', 'advanced');
        if (!in_array($subtab, $allowed, true)) {
            $subtab = 'connection';
        }
        $settings = $this->get_settings_fields($subtab);
        WC_Admin_Settings::save_fields($settings);
    }


    // 多个页面的字段
    protected function get_settings_fields($tab)
    {
        switch ($tab) {
            case 'standard':
                return [
                    [
                        'title' => '连接设置',
                        'type' => 'title',
                        'id' => 'paykka_conn_title'
                    ],
                    [
                        'title' => 'Sandbox',
                        'type' => 'checkbox',
                        'id' => 'paykka_sandbox_flag',
                        'desc_tip' => true,
                        'description' => __('未勾选为生产、勾选为测试。可通过 paykka-config.php（env）或 wp-config 常量 PAYKKA_ENV 选择环境，优先级高于本勾选。', 'paykka-for-woocommerce'),
                    ],
                    [
                        'title' => 'Sandbox Private Key',
                        'type' => 'textarea',
                        'id' => 'paykka_sandbox_private_key'
                    ],
                    [
                        'title' => 'Sandbox Merchant Id',
                        'type' => 'text',
                        'id' => 'paykka_sandbox_merchant_id'
                    ],
                    [
                        'title' => 'Sandbox App Id (x-paykka-appid)',
                        'type' => 'text',
                        'id' => 'paykka_sandbox_app_id',
                        'desc_tip' => true,
                        'description' => __('可选，不填则使用 Sandbox Merchant Id。用于 v3 接口请求头。', 'paykka-for-woocommerce'),
                    ],
                    [
                        'title' => 'Sandbox Client Key',
                        'type' => 'text',
                        'id' => 'paykka_sandbox_client_key'
                    ],
                    [
                        'title' => 'Private Key',
                        'type' => 'textarea',
                        'id' => 'paykka_private_key'
                    ],
                    [
                        'title' => 'Merchant Id',
                        'type' => 'text',
                        'id' => 'paykka_merchant_id'
                    ],
                    [
                        'title' => 'App Id (x-paykka-appid)',
                        'type' => 'text',
                        'id' => 'paykka_app_id',
                        'desc_tip' => true,
                        'description' => __('可选，不填则使用 Merchant Id。用于 v3 接口请求头。', 'paykka-for-woocommerce'),
                    ],
                    [
                        'title' => 'Live Client Key',
                        'type' => 'text',
                        'id' => 'paykka_client_key'
                    ],
                    [
                        'title'   => __('API 地区', 'paykka-for-woocommerce'),
                        'type'    => 'select',
                        'id'      => 'paykka_api_region',
                        'default' => 'eu',
                        'options' => array(
                            'eu' => __('欧洲地区 (https://openapi.eu.paykka.com)', 'paykka-for-woocommerce'),
                            'hk' => __('香港地区 (https://openapi.aq.paykka.com)', 'paykka-for-woocommerce'),
                        ),
                        'desc_tip' => true,
                        'description' => __('生产环境下后端 API 调用使用的区域。欧洲与香港使用不同域名，请与 Paykka 账户所在区域一致。', 'paykka-for-woocommerce'),
                    ],
                    [
                        'type' => 'sectionend',
                        'id' => 'paykka_standard_end'
                    ]
                ];
            case 'advanced':
                return [
                    [
                        'title' => '高级设置',
                        'type' => 'title',
                        'id' => 'paykka_advanced_title'
                    ],
                    [
                        'title' => '开启 Debug 模式',
                        'type' => 'checkbox',
                        'id' => 'paykka_debug_mode',
                        'default' => 'yes',
                        'desc_tip' => true,
                        'description' => __('开发 Paykka 插件时建议勾选。将输出详细 error_log（下单、Webhook、签名等），便于排查。上线后请关闭。', 'paykka-for-woocommerce'),
                    ],
                    [
                        'title' => '记录请求结果日志',
                        'type' => 'checkbox',
                        'id' => 'paykka_log_request_result',
                        'default' => 'yes',
                        'desc_tip' => true,
                        'description' => __('开启后，每次 Paykka 接口请求的 URL、HTTP 状态码和响应体会写入 PHP error_log，便于排查。需在服务器或 wp-config 中配置 error_log 输出位置。', 'paykka-for-woocommerce'),
                    ],
                    [
                        'title' => '仅授权',
                        'type' => 'checkbox',
                        'id' => 'paykka_capture_method_flag'
                    ],
                    [
                        'type' => 'sectionend',
                        'id' => 'paykka_advanced_end'
                    ]
                ];
            case 'methods':
                return array(
                    array(
                        'title' => __('支付模式', 'paykka-for-woocommerce'),
                        'desc'  => __('结账页只显示「Paykka」一项（与 PayPal 一致），顾客选择后按下方所选模式跳转。', 'paykka-for-woocommerce'),
                        'type'  => 'title',
                        'id'    => 'paykka_methods_title',
                    ),
                    array(
                        'title'   => __('支付模式', 'paykka-for-woocommerce'),
                        'type'    => 'select',
                        'id'      => 'paykka_payment_mode',
                        'default' => 'hosted',
                        'options' => array(
                            'hosted'           => __('Hosted 收银台（跳转 Paykka 收银台）', 'paykka-for-woocommerce'),
                            'dropin'           => __('Drop-in 卡片（站内 Drop-in 页面）', 'paykka-for-woocommerce'),
                            'embedded'         => __('Component / Accordion（站内组件页）', 'paykka-for-woocommerce'),
                            'encrypted_card'   => __('Encrypted Card（站内加密卡页）', 'paykka-for-woocommerce'),
                            'google_pay'       => __('Google Pay', 'paykka-for-woocommerce'),
                        ),
                        'desc_tip' => true,
                        'description' => __('选定后，所有选择 Paykka 的订单均按此模式处理。', 'paykka-for-woocommerce'),
                    ),
                    array(
                        'type' => 'sectionend',
                        'id'   => 'paykka_methods_end',
                    ),
                );
            case 'connection':
            default:
                return array(
                    array(
                        'title' => __('连接设置', 'paykka-for-woocommerce'),
                        'type'  => 'title',
                        'id'    => 'paykka_conn_title',
                    ),
                    array(
                        'title'   => __('启用/禁用', 'paykka-for-woocommerce'),
                        'type'    => 'checkbox',
                        'id'      => 'paykka_enabled',
                        'default' => 'yes',
                        'desc'    => __('启用 Paykka 支付', 'paykka-for-woocommerce'),
                    ),
                    array(
                        'title'   => __('前台标题', 'paykka-for-woocommerce'),
                        'type'    => 'text',
                        'id'      => 'paykka_title',
                        'default' => __('Paykka', 'paykka-for-woocommerce'),
                        'desc_tip' => true,
                        'description' => __('结账时显示的支付方式名称', 'paykka-for-woocommerce'),
                    ),
                    array(
                        'title'   => __('前台描述', 'paykka-for-woocommerce'),
                        'type'    => 'textarea',
                        'id'      => 'paykka_description',
                        'default' => __('使用 Paykka 安全支付', 'paykka-for-woocommerce'),
                    ),
                    array(
                        'type' => 'sectionend',
                        'id'   => 'paykka_conn_end',
                    ),
                );
        }
    }

    /**
     * 当前后台选定的支付模式（单一模式，结账页不展示子选项，与 PayPal 一致）
     *
     * @return array [ ['id' => 'hosted', 'title' => '...'] ] 仅一个元素
     */
    protected function get_enabled_paykka_methods()
    {
        $mode = get_option('paykka_payment_mode', 'hosted');
        $titles = array(
            'hosted'           => __('Hosted 收银台', 'paykka-for-woocommerce'),
            'dropin'           => __('Drop-in 卡片', 'paykka-for-woocommerce'),
            'embedded'         => __('Component', 'paykka-for-woocommerce'),
            'encrypted_card'   => __('Encrypted Card', 'paykka-for-woocommerce'),
            'google_pay'       => __('Google Pay', 'paykka-for-woocommerce'),
        );
        if (!isset($titles[$mode])) {
            $mode = 'hosted';
        }
        return array(array('id' => $mode, 'title' => $titles[$mode]));
    }

    public function payment_fields()
    {
    }

    public function payment_scripts()
    {
    }

    public function validate_fields()
    {
        return true;
    }

    public function is_available()
    {
        return $this->enabled === 'yes';
    }

    public function receipt_page($order_id)
    {

    }


    public function process_payment($order_id)
    {
        ob_start();
        $order = wc_get_order($order_id);
        if (!$order || !$order->get_id()) {
            ob_end_clean();
            return array('result' => 'failure', 'message' => __('Invalid order', 'paykka-for-woocommerce'));
        }

        $method = strtolower(trim((string) get_option('paykka_payment_mode', 'hosted')));
        $order->update_meta_data('_paykka_sub_method', $method);
        $order->save();

        require_once PAYKKA_PLUGIN_PATH . 'classes/lib/Paykka/Request/PaykkaRequestHandler.php';
        $paykkaPaymentHelper = new PaykkaRequestHandler();

        if ($method === 'hosted') {
            ob_end_clean();
            return $this->process_payment_hosted($order, $order_id, $paykkaPaymentHelper);
        }
        if ($method === 'dropin') {
            ob_end_clean();
            return $this->process_payment_dropin($order, $order_id, $paykkaPaymentHelper);
        }
        if ($method === 'embedded') {
            ob_end_clean();
            return $this->process_payment_embedded($order, $order_id, $paykkaPaymentHelper);
        }
        if ($method === 'encrypted_card') {
            ob_end_clean();
            WC()->cart->empty_cart();
            return array(
                'result'   => 'success',
                'redirect' => add_query_arg('order_id', $order_id, paykka_get_payment_url('card-encrypted')),
            );
        }
        if ($method === 'google_pay') {
            return $this->process_payment_google_pay($order_id, $paykkaPaymentHelper);
        }

        // 未识别的模式（如选项未保存或被篡改）时按 hosted 处理
        $valid_methods = array('hosted', 'dropin', 'embedded', 'encrypted_card', 'google_pay');
        if (!in_array($method, $valid_methods, true)) {
            $method = 'hosted';
            ob_end_clean();
            return $this->process_payment_hosted($order, $order_id, $paykkaPaymentHelper);
        }

        ob_end_clean();
        return array('result' => 'failure', 'message' => __('Invalid payment method', 'paykka-for-woocommerce'));
    }

    private function process_payment_hosted($order, $order_id, PaykkaRequestHandler $paykkaPaymentHelper)
    {
        $response_data = $paykkaPaymentHelper->buildSessionUrl($order);
        if (function_exists('paykka_is_debug') && paykka_is_debug()) {
            error_log('[Paykka Hosted] process_payment order_id=' . $order_id . ' response_data=' . wp_json_encode($response_data));
        }
        if ($response_data === null) {
            wc_add_notice(__('Payment request failed. Please try again or choose another payment method.', 'paykka-for-woocommerce'), 'error');
            return array('result' => 'failure', 'message' => __('Payment request failed', 'paykka-for-woocommerce'));
        }
        if (isset($response_data['ret_code']) && $response_data['ret_code'] === '000000') {
            $session_url = isset($response_data['data']['session_url']) ? trim($response_data['data']['session_url']) : '';
            if ($session_url === '') {
                if (function_exists('paykka_is_debug') && paykka_is_debug()) {
                    error_log('[Paykka Hosted] session_url is empty, full response: ' . wp_json_encode($response_data));
                }
                wc_add_notice(__('Payment session created but redirect URL is missing. Please contact support.', 'paykka-for-woocommerce'), 'error');
                return array('result' => 'failure', 'message' => __('Missing redirect URL', 'paykka-for-woocommerce'));
            }
            WC()->cart->empty_cart();
            return array('result' => 'success', 'redirect' => $session_url);
        }
        $msg = isset($response_data['ret_msg']) ? $response_data['ret_msg'] : __('Payment session failed', 'paykka-for-woocommerce');
        wc_add_notice($msg, 'error');
        return array('result' => 'failure', 'message' => $msg);
    }

    private function process_payment_dropin($order, $order_id, PaykkaRequestHandler $paykkaPaymentHelper)
    {
        $response_data = $paykkaPaymentHelper->buildSessionId($order, 'DROP_IN');
        if (empty($response_data) || !isset($response_data['ret_code']) || $response_data['ret_code'] !== '000000') {
            return array(
                'result'   => 'failure',
                'message'  => isset($response_data['ret_msg']) ? $response_data['ret_msg'] : __('Payment session failed', 'paykka-for-woocommerce'),
            );
        }
        $session_id = isset($response_data['data']['session_id']) ? $response_data['data']['session_id'] : '';
        if ($session_id === '') {
            return array('result' => 'failure', 'message' => __('Session ID missing', 'paykka-for-woocommerce'));
        }
        $settings = function_exists('getPaykkaSettings') ? getPaykkaSettings() : array();
        $client_key = isset($settings['paykka_client_key']) ? $settings['paykka_client_key'] : '';
        $order->update_status('pending', __('Awaiting Paykka Drop-in', 'paykka-for-woocommerce'));
        WC()->cart->empty_cart();
        $callback_url = \lib\Paykka\Request\PaykkaCallBackHandler::getCallbackUrl($order->get_id());
        $notify_url = \lib\Paykka\Request\PaykkaWebHookHandler::getWebHookUrl();
        WC()->session->__unset('paykka_dropin_session_id');
        WC()->session->__unset('paykka_dropin_client_key');
        WC()->session->__unset('paykka_dropin_callback_url');
        WC()->session->__unset('paykka_dropin_notify_url');
        WC()->session->set('paykka_dropin_session_id', $session_id);
        WC()->session->set('paykka_dropin_client_key', $client_key);
        WC()->session->set('paykka_dropin_callback_url', $callback_url);
        WC()->session->set('paykka_dropin_notify_url', $notify_url);
        return array('result' => 'success', 'redirect' => paykka_get_payment_url('dropin'));
    }

    private function process_payment_embedded($order, $order_id, PaykkaRequestHandler $paykkaPaymentHelper)
    {
        $response_data = $paykkaPaymentHelper->buildSessionId($order, 'COMPONENT');
        if (empty($response_data) || !isset($response_data['ret_code']) || $response_data['ret_code'] !== '000000') {
            return array(
                'result'   => 'failure',
                'message'  => isset($response_data['ret_msg']) ? $response_data['ret_msg'] : __('Payment session failed', 'paykka-for-woocommerce'),
            );
        }
        $session_id = isset($response_data['data']['session_id']) ? $response_data['data']['session_id'] : '';
        if ($session_id === '') {
            return array('result' => 'failure', 'message' => __('Session ID missing', 'paykka-for-woocommerce'));
        }
        $settings = function_exists('getPaykkaSettings') ? getPaykkaSettings() : array();
        $client_key = isset($settings['paykka_client_key']) ? $settings['paykka_client_key'] : '';
        $order->update_status('pending', __('Awaiting Paykka Component', 'paykka-for-woocommerce'));
        WC()->cart->empty_cart();
        $callback_url = \lib\Paykka\Request\PaykkaCallBackHandler::getCallbackUrl($order->get_id());
        $notify_url = \lib\Paykka\Request\PaykkaWebHookHandler::getWebHookUrl();
        WC()->session->__unset('woocommerce_order_id');
        WC()->session->__unset('paykka_session_id');
        WC()->session->__unset('paykka_client_key');
        WC()->session->__unset('paykka_callback_url');
        WC()->session->__unset('paykka_notify_url');
        WC()->session->set('woocommerce_order_id', $order_id);
        WC()->session->set('paykka_session_id', $session_id);
        WC()->session->set('paykka_client_key', $client_key);
        WC()->session->set('paykka_callback_url', $callback_url);
        WC()->session->set('paykka_notify_url', $notify_url);
        return array('result' => 'success', 'redirect' => paykka_get_payment_url('accordion'));
    }

    private function process_payment_google_pay($order_id, PaykkaRequestHandler $paykkaPaymentHelper)
    {
        $raw_post = file_get_contents('php://input');
        $request_data = is_string($raw_post) ? json_decode($raw_post, true) : array();
        if (!is_array($request_data) || empty($request_data['payment_data'])) {
            return array('result' => 'failure', 'message' => __('Invalid payment data', 'paykka-for-woocommerce'));
        }
        $payment_google_data = null;
        foreach ($request_data['payment_data'] as $payment_item) {
            if (isset($payment_item['key']) && $payment_item['key'] === 'payment_google_data' && isset($payment_item['value'])) {
                $payment_google_data = json_decode($payment_item['value'], true);
                break;
            }
        }
        if (!$payment_google_data || empty($payment_google_data['paymentMethodData']['tokenizationData']['token'])) {
            return array('result' => 'failure', 'message' => __('Google Pay token missing', 'paykka-for-woocommerce'));
        }
        $google_token = $payment_google_data['paymentMethodData']['tokenizationData']['token'];
        $order = wc_get_order($order_id);
        if (!$order || !$order->get_id()) {
            return array('result' => 'failure', 'message' => __('Invalid order', 'paykka-for-woocommerce'));
        }
        $order->update_status('pending', __('Processing Google Pay', 'paykka-for-woocommerce'));
        WC()->cart->empty_cart();
        $response_data = $paykkaPaymentHelper->handlerGooglePayPayment($order, $google_token);
        if (isset($response_data['ret_code']) && $response_data['ret_code'] === '000000') {
            return array('result' => 'success', 'redirect' => $order->get_checkout_order_received_url());
        }
        return array(
            'result'   => 'failure',
            'message'  => isset($response_data['ret_msg']) ? $response_data['ret_msg'] : __('Payment failed', 'paykka-for-woocommerce'),
        );
    }

    /**
     * WooCommerce 退款入口
     * 仅在交易可退款时调用 PayKKa 退款接口，并通过退款查询同步状态。
     *
     * @param int        $order_id
     * @param float|null $amount
     * @param string     $reason
     * @return bool|\WP_Error
     */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);
        if (!$order || !$order->get_id()) {
            return new \WP_Error('paykka_invalid_order', __('Invalid order', 'paykka-for-woocommerce'));
        }
        if ($amount === null || (float) $amount <= 0) {
            return new \WP_Error('paykka_invalid_refund_amount', __('Invalid refund amount', 'paykka-for-woocommerce'));
        }

        require_once PAYKKA_PLUGIN_PATH . 'classes/lib/Paykka/Request/PaykkaRequestHandler.php';
        $paykkaPaymentHelper = new PaykkaRequestHandler();

        // 先查询交易，判断当前订单是否可退款；并补齐 PayKKa order_id
        $query_result = $paykkaPaymentHelper->queryPayment((string) $order_id, '', '');
        if (!is_array($query_result) || !isset($query_result['ret_code']) || $query_result['ret_code'] !== '000000') {
            $msg = is_array($query_result) && isset($query_result['ret_msg']) ? (string) $query_result['ret_msg'] : __('Payment query failed', 'paykka-for-woocommerce');
            return new \WP_Error('paykka_query_failed', $msg);
        }
        $paykkaPaymentHelper->syncOrderByQueryResult($order, $query_result, 'refund-check');

        $status = isset($query_result['status']) ? strtoupper((string) $query_result['status']) : '';
        if (!in_array($status, array('SUCCESS', 'AUTHORIZED', 'PARTIALLY_REFUNDED'), true)) {
            return new \WP_Error('paykka_not_refundable_status', sprintf(__('Order status %s is not refundable', 'paykka-for-woocommerce'), $status));
        }

        $able_to_refund_amount = 0;
        if (isset($query_result['balances']) && is_array($query_result['balances']) && isset($query_result['balances']['able_to_refund_amount'])) {
            $able_to_refund_amount = (int) $query_result['balances']['able_to_refund_amount'];
        }
        $decimal_places = get_option('woocommerce_price_num_decimals', 2);
        $refund_amount_minor = intval(round((float) $amount * pow(10, $decimal_places)));
        if ($refund_amount_minor <= 0) {
            return new \WP_Error('paykka_invalid_refund_amount', __('Invalid refund amount', 'paykka-for-woocommerce'));
        }
        if ($able_to_refund_amount > 0 && $refund_amount_minor > $able_to_refund_amount) {
            return new \WP_Error('paykka_refund_exceed_limit', __('Refund amount exceeds available refundable amount', 'paykka-for-woocommerce'));
        }

        $refund_reason = 'REQUESTED_BY_CUSTOMER';
        if (is_string($reason) && trim($reason) !== '') {
            $refund_reason = 'OTHER';
        }
        $refund_response = $paykkaPaymentHelper->refundPayment($order, (float) $amount, $refund_reason, '');
        if (!is_array($refund_response) || !isset($refund_response['ret_code']) || $refund_response['ret_code'] !== '000000') {
            $msg = is_array($refund_response) && isset($refund_response['ret_msg']) ? (string) $refund_response['ret_msg'] : __('Refund request failed', 'paykka-for-woocommerce');
            return new \WP_Error('paykka_refund_failed', $msg);
        }

        // 退款发起后立刻查询一次退款单，更新订单退款状态与退款订单号
        $refund_trans_id = isset($refund_response['refund_trans_id']) ? (string) $refund_response['refund_trans_id'] : '';
        $refund_order_id = isset($refund_response['refund_order_id']) ? (string) $refund_response['refund_order_id'] : '';
        if ($refund_trans_id !== '' || $refund_order_id !== '') {
            $refund_query_result = $paykkaPaymentHelper->queryRefund($refund_trans_id, $refund_order_id);
            if (is_array($refund_query_result) && isset($refund_query_result['ret_code']) && $refund_query_result['ret_code'] === '000000') {
                $paykkaPaymentHelper->syncOrderByRefundQueryResult($order, $refund_query_result, 'refund');
            } elseif (function_exists('paykka_is_debug') && paykka_is_debug()) {
                error_log('[Paykka Refund] refund query failed order_id=' . $order_id . ' query=' . wp_json_encode($refund_query_result));
            }
        }

        if (function_exists('paykka_enqueue_refund_paykka_link')) {
            paykka_enqueue_refund_paykka_link($order, $refund_trans_id, $refund_order_id, (float) $amount);
        }

        return true;
    }
}