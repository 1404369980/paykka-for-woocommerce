<?php
/**
 * Paykka_Credit_Card_Gateway class.
 *
 * @extends WC_Payment_Gateway
 */

use lib\Paykka\Request\PaykkaRequestHandler;
use lib\Paykka\Request\PaykkaWeb;


class Paykka_Credit_Card_Gateway extends WC_Payment_Gateway
{
    private $merchant_id;

    public function __construct()
    {
        $this->id = 'paykka';
        $this->has_fields = false;
        $this->version = '8.2.0';
        $this->icon = '';
        $this->method_description = __('PayKKa Hosted Page payments.', 'paykka-for-woocommerce');
        $this->method_title = __('PayKKa Hosted Page', 'paykka-for-woocommerce');

        $this->title = __('PayKKa Hosted Page', 'paykka-for-woocommerce');
        $this->description = __('PayKKa Hosted Page payments.', 'paykka-for-woocommerce');

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
        // 这个动作挂钩保存设置
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));

        // 回调
        // add_action('woocommerce_api_wc_gateway_custom_payment_callback', array($this, 'handle_payment_callback'));


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
        $current_tab = isset($_GET['section']) ? sanitize_text_field($_GET['section']) : 'connection';

        echo '<h2>' . esc_html($this->get_method_title()) . '</h2>';
        echo '<nav class="nav-tab-wrapper">';

        $tabs = [
            'connection' => '连接设置',
            'standard' => '标准支付',
            'advanced' => '高级设置',
        ];

        foreach ($tabs as $key => $label) {
            $class = ($current_tab === $key) ? 'nav-tab nav-tab-active' : 'nav-tab';
            $url = admin_url('admin.php?page=wc-settings&tab=checkout&section=paykka&subtab=' . $key);
            echo '<a href="' . esc_url($url) . '" class="' . esc_attr($class) . '">' . esc_html($label) . '</a>';
        }

        echo '</nav>';

        // 加载设置表单字段
        $subtab = isset($_GET['subtab']) ? sanitize_text_field($_GET['subtab']) : 'connection';
        $settings = $this->get_settings_fields($subtab);
        WC_Admin_Settings::output_fields($settings);
    }

    // 保存设置
    public function process_admin_options()
    {
        $subtab = isset($_GET['subtab']) ? sanitize_text_field($_GET['subtab']) : 'connection';
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
                        'id' => 'paykka_sandbox_flag'
                    ],
                    [
                        'title' => 'Sandbox Public Key',
                        'type' => 'text',
                        'id' => 'paykka_sandbox_public_key'
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
                        'title' => 'Sandbox Client Key',
                        'type' => 'text',
                        'id' => 'paykka_client_key'
                    ],
                    [
                        'title' => 'Public Key',
                        'type' => 'text',
                        'id' => 'paykka_public_key'
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
                        'title' => 'Client Key',
                        'type' => 'text',
                        'id' => 'paykka_client_key'
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
                        'title' => '开启调试模式',
                        'type' => 'checkbox',
                        'id' => 'paykka_debug',
                        'default' => 'no'
                    ],
                    [
                        'type' => 'sectionend',
                        'id' => 'paykka_advanced_end'
                    ]
                ];
            case 'connection':
            default:
                return [
                    [
                        'title' => '连接设置',
                        'type' => 'title',
                        'id' => 'paykka_conn_title'
                    ],
                    [
                        'title' => 'API Key',
                        'type' => 'text',
                        'id' => 'paykka_api_key'
                    ],
                    [
                        'title' => '商户号',
                        'type' => 'text',
                        'id' => 'paykka_merchant_id'
                    ],
                    [
                        'type' => 'sectionend',
                        'id' => 'paykka_conn_end'
                    ]
                ];
        }
    }

    public function payment_fields()
    {
        // echo '<div id="custom-payment-fields">
        //     <label>Custom Field <span class="required">*</span></label>
        //     <input type="text" name="custom_field" autocomplete="off">
        // </div>';
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
        // $order_data = json_encode($order->get_data(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        // error_log("Order Data: \n" . $order_data);

        // $cart_json = json_encode(WC()->cart->get_cart(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        // error_log("Cart Data: \n" . $cart_json);


        // $order->update_status('on-hold', __('Awaiting custom payment', 'woocommerce-custom-payment-gateway'));

        // 标记订单为已支付
        // $order->payment_complete();

        // 减少库存
        // wc_reduce_stock_levels($order_id);

        // 清空购物车
        // WC()->cart->empty_cart();

        // 返回成功和重定向链接

        // $url_code = $this->do_paykka_payment($order);
        require_once FENGQIAO_PAYKKA_URL . 'classes/lib/Paykka/Request/PaykkaRequestHandler.php';

        $paykkaPaymentHelper = new PaykkaRequestHandler();
        error_log("PaykkaRequestHandler: \n");
        $response_data = $paykkaPaymentHelper->buildSessionUrl($order);
        ob_end_clean();

        if (isset($response_data['ret_code']) && $response_data['ret_code'] === '000000') {
            return array(
                'result' => 'success',
                'redirect' => $response_data['data']['session_url'],
            );
        } else {
            return [
                'result' => 'failure',
                'message' => $response_data['ret_msg']
            ];
        }
    }

    public function handle_payment_callback()
    {
        ob_start(); // 开启输出缓冲区

        $order_id = $_REQUEST['order_id'];
        $order = wc_get_order($order_id);

        $order->payment_complete();
        WC()->cart->empty_cart();
        wc_reduce_stock_levels($order_id);

        $return_url = $this->get_return_url($order);

        ob_end_clean();
        wp_safe_redirect($return_url);
        exit;
    }


    private function do_paykka_payment($order)
    {
        $timestamp = time();
        $cart_total = $order->get_total() * 100;
        // 待优化--TODO
        $now = new DateTime('now', new DateTimeZone('UTC'));
        // 转换为香港时间
        $now->setTimezone(new DateTimeZone('Asia/Hong_Kong'));
        // 使用 DateInterval 对象来添加 5 分钟
        $now->add(new DateInterval('PT5M'));
        $expire_time = $now->format('Y-m-d H:i:s');
        $callback_url = add_query_arg('wc-api', 'WC_Gateway_Custom_Payment_callback', home_url('/')) . "&order_id=" . $order->get_id();

        $http_body = '{
            "version": "v1.0",
            "merchant_id": "' . $this->merchant_id . '",
            "payment_type": "PURCHASE",
            "trans_id": "m' . $timestamp . '",
            "timestamp": ' . $timestamp . ',
            "currency": "' . $order->get_currency() . '",
            "amount": "' . $cart_total . '",
            "notify_url": "https://pub-dev.eu.paykka.com/prefix/callback?id=m11785643765251",
            "return_url": "' . $callback_url . '",
            "expire_time": "' . $expire_time . '",
            "session_mode": "HOST",
            "display_merchant_name": "Paykka Test Merchant 38",
            "display_locale": "zh-CN",
            "theme_id": "TQZ",
            "goods": [
                {
                    "id": "6903743507161",
                    "name": "Client poverty mountain porch correct sight interested western adapt almost",
                    "description": "Hide criteria whole much soft chapter duty boot everybody regularly someone film officer gaze mount glove stage manner crisis promise edge commission entrance recovery widespread dead shrug hungry fourth base huge tendency drug history rare inside matter physical the heaven significance enable chief corporate settle station yes easily son absolute",
                    "category": "KEEEOFLZLQ",
                    "brand": "诺君安",
                    "link": "http://vkcqntwhqyr4l.cc/6176333747692/UAgqdRtfWUxU5rm60vH7.shop",
                    "price": 9068,
                    "quantity": 9759,
                    "delivery_date": "1989-01-28T01:55:04+08:00",
                    "picture_url": "http://rl1vnqfy0ts8.org/3946280769095/XE91M0jYlt9Li3V7l7ISVLANAL.jpg"
                },
                {
                    "id": "6903700259065",
                    "name": "Teen peak swear buyer sight greatest arrangement cover off constant",
                    "description": "New publicly tree future hotel addition even frame dangerous command reading eager chain chief assessment religious connection ultimate slice intention again present ceiling who vote west meanwhile area legal Bible buck egg environment wall educational emerge identity recognize increasingly time nature golden sugar resist confirm pie impression extend valley anniversary",
                    "category": "ZAZRZI",
                    "brand": "蓝贝股份",
                    "link": "https://jfd0hcre.cn/7399179281482/Z9z5QfaM1zFlAxwYAaPMfyF9mwvi.shop",
                    "price": 6667,
                    "quantity": 4220,
                    "delivery_date": "2020-07-13T23:20:06+08:00",
                    "picture_url": "http://v1f.tv/5522950716815/H8Om1xdsUD8OwfIW8bxFwDoOKmu.jpg"
                }
            ],
            "bill": {
                "country": "US",
                "email": "",
                "state": "",
                "city": "",
                "address_line1": "",
                "postal_code": "",
                "first_name": "",
                "last_name": "",
                "area_code": "",
                "phone_number": ""
            },
            "shipping": {
                "first_name": "Kerrie",
                "middle_name": "Donald",
                "last_name": "Schranz",
                "address_line1": "广西壮族自治区南宁市邕宁区联谷北路981号达富名都10栋4单元0503房",
                "address_line2": "湖南省长沙市雨花区贤云中路999号达富御院10栋4单元0301房",
                "country": "ML",
                "state": "内蒙古自治区",
                "city": "赤峰市",
                "postal_code": "150404",
                "email": "ruq3s6we@outlook.com",
                "area_code": "0315",
                "phone_number": "64663854"
            },
            "customer": {
                "id": "NMQKZI",
                "registration_time": "2084-02-23T12:10:43+08:00",
                "past_transactions": 9747,
                "area_code": "0391",
                "phone_number": "88355298",
                "date_of_birth": "PQLCVEYZEC",
                "gender": "LFFWTZ",
                "first_shopping_time": "2024-02-10T01:15:22+08:00",
                "last_shopping_time": "1986-07-19T09:56:54+08:00",
                "level": "VVVVVVVIP",
                "email": "jl@zoho.com",
                "pay_ip": "103.54.8.194",
                "order_ip": "ae59:6016:988a:c23c:1ffc:38a4:aa92:73a0"
            },
            "payment": {
                "store_payment_method": true,
                "token_usage": "CARD_ON_FILE",
                "shopper_reference": "f4911bc8b17106a08f2f7a89a9fc4d11",
                "token": "",
                "card_no": "4242424242424242"
            },
            "authentication": {
                "challenge_indicator": "",
                "authentication_only": false
            }
        }';

        // 定义请求头
        $headers = array(
            'Content-Type' => 'application/json', // 设置内容类型为 JSON
            'signature' => 'BearerYOUR_ACCESS_TOKEN', // 添加认证头
        );

        $response = wp_remote_post('https://pub-dev.eu.paykka.com/apis/session', array(
            'headers' => $headers,
            'body' => $http_body,
        ));

        // 检查请求是否出错
        if (is_wp_error($response)) {
            wc_add_notice('Payment error: ' . $response->get_error_message(), 'error');
            return;
        }

        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);

        // echo '<script>console.log("回调准备' . $data . '")</script>';
        return $response_data['data']['session_url'];
    }
}