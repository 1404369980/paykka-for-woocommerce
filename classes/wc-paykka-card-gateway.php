<?php
/**
 * Paykka Embedded Payments gateway (Card / Apple Pay / Google Pay).
 *
 * @extends WC_Payment_Gateway
 */

use lib\Paykka\Request\PaykkaRequestHandler;
use lib\Paykka\Request\PaykkaCallBackHandler;

class Paykka_Card_Gateway extends WC_Payment_Gateway
{
    /** @var string */
    public $version = '1.5.6';

    public function __construct()
    {
        $this->id                 = 'paykka-card';
        $this->has_fields         = true;
        $this->method_title       = __('Paykka Payments', 'paykka-for-woocommerce');
        $this->method_description = __('WooCommerce Blocks 结账页内嵌 Payments（银行卡 / Apple Pay / Google Pay）。选中后展示支付组件，点击下单或钱包按钮完成支付。不影响 Paykka Hosted。', 'paykka-for-woocommerce');
        $this->supports           = array('products', 'refunds');

        $this->init_form_fields();
        $this->init_settings();

        $this->enabled     = $this->get_option('enabled', 'yes');
        $this->title       = $this->normalize_checkout_title($this->get_option('title', __('Payments', 'paykka-for-woocommerce')));
        $this->description = '';

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        // AJAX 由主插件文件注册（见 paykka_card_register_ajax），避免 template_redirect 时网关未实例化导致空响应。
    }

    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __('启用/禁用', 'paykka-for-woocommerce'),
                'type'    => 'checkbox',
                'label'   => __('启用 Paykka Payments（Embedded）', 'paykka-for-woocommerce'),
                'default' => 'yes',
            ),
            'title' => array(
                'title'       => __('标题', 'paykka-for-woocommerce'),
                'type'        => 'text',
                'description' => __('结账页显示的支付方式名称', 'paykka-for-woocommerce'),
                'default'     => __('Payments', 'paykka-for-woocommerce'),
                'desc_tip'    => true,
            ),
            'enable_saved_cards' => array(
                'title'   => __('启用保存卡', 'paykka-for-woocommerce'),
                'type'    => 'checkbox',
                'label'   => __('允许已登录顾客勾选保存支付信息（需账户侧支持）', 'paykka-for-woocommerce'),
                'default' => 'no',
            ),
        );
    }

    public function is_available()
    {
        if ($this->enabled !== 'yes') {
            return false;
        }
        $settings = function_exists('getPaykkaSettings') ? getPaykkaSettings() : array();
        return !empty($settings['paykka_client_key']) && !empty($settings['paykka_merchant_id']) && !empty($settings['paykka_private_key']);
    }

    /**
     * 将历史默认标题 Credit Card 迁移为 Payments。
     *
     * @param string $title
     * @return string
     */
    private function normalize_checkout_title($title)
    {
        $title = is_string($title) ? trim($title) : '';
        if ($title === '' || $title === 'Credit Card') {
            return __('Payments', 'paykka-for-woocommerce');
        }
        return $title;
    }

    public function payment_fields()
    {
        // Payments 以内嵌 Blocks 结账为准；经典结账仅显示说明。
        echo '<p>' . esc_html__('请使用 Blocks 结账页完成 Payments 支付。', 'paykka-for-woocommerce') . '</p>';
    }

    public function payment_scripts()
    {
        // Blocks 脚本由 WC_Gateway_Paykka_Card_Support 注册，此处不加载经典结账脚本。
    }

    public function ajax_create_session()
    {
        if (!check_ajax_referer('paykka_card_checkout', 'security', false)) {
            wp_send_json_error(array('message' => __('安全校验失败，请刷新结账页后重试。', 'paykka-for-woocommerce')), 403);
        }

        $posted_data = array();
        if (!empty($_POST['billing'])) {
            $raw = wp_unslash($_POST['billing']);
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                $posted_data = is_array($decoded) ? $decoded : array();
            } elseif (is_array($raw)) {
                $posted_data = $raw;
            }
        } elseif (isset($_POST['post_data'])) {
            $posted_data = wp_unslash($_POST['post_data']);
        }
        if (empty($posted_data['payment_method'])) {
            $posted_data['payment_method'] = $this->id;
        }

        $result = $this->create_checkout_card_session($posted_data);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()), 400);
        }
        wp_send_json_success($result);
    }

    /**
     * 创建或复用待付款订单 + DROP_IN Session，供结账页 Card 组件使用。
     *
     * @param string|array $posted_data
     * @return array|\WP_Error
     */
    public function create_checkout_card_session($posted_data = '')
    {
        if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty() || !WC()->session) {
            return new \WP_Error('paykka_empty_cart', __('购物车为空或结账会话已过期。', 'paykka-for-woocommerce'));
        }

        $data = $this->clean_checkout_data($posted_data);
        if (!empty($data['payment_method']) && $data['payment_method'] !== $this->id) {
            return new \WP_Error('paykka_wrong_method', __('请选择 Payments 后再继续。', 'paykka-for-woocommerce'));
        }

        $billing_error = $this->validate_billing_for_session($data);
        if (is_wp_error($billing_error)) {
            return $billing_error;
        }

        $this->sync_checkout_customer($data);
        WC()->cart->calculate_totals();

        $cart_hash = WC()->cart->get_cart_hash();
        $order_id  = absint(WC()->session->get('paykka_card_checkout_order_id'));
        if (!$order_id) {
            $order_id = absint(WC()->session->get('order_awaiting_payment'));
        }
        $order = $order_id ? wc_get_order($order_id) : null;
        if (!$order || !$order->get_id() || !$order->has_status(array('pending', 'failed')) || !$order->has_cart_hash($cart_hash) || $order->get_payment_method() !== $this->id) {
            $order = wc_create_order(array(
                'status'      => 'pending',
                'customer_id' => get_current_user_id(),
            ));
            if (is_wp_error($order)) {
                return $order;
            }
        } else {
            $order->remove_order_items();
        }

        $this->populate_checkout_order($order, $data, $cart_hash);
        $order->update_meta_data('_paykka_checkout_order', 'yes');
        $order->update_meta_data('_paykka_sub_method', 'card');
        $order->save();

        // COMPONENT：每次请求新建 session（同一 session 二次 init 会失败，刷新页无法再打开）
        $force_new = !empty($data['force_new']) || !empty($_POST['force_new']);
        $fingerprint     = $this->get_session_fingerprint($order);
        $session_id      = trim((string) $order->get_meta('_paykka_session_id', true));
        $old_fingerprint = (string) $order->get_meta('_paykka_session_fingerprint', true);
        if (!$force_new && $session_id !== '' && hash_equals($old_fingerprint, $fingerprint)) {
            WC()->session->set('paykka_card_checkout_order_id', $order->get_id());
            WC()->session->set('order_awaiting_payment', $order->get_id());
            WC()->session->save_data();
            return $this->format_session_response($order, $session_id);
        }

        // 新 session 必须用新 trans_id，否则 PayKKa 会返回旧 session_id
        $trans_id = function_exists('paykka_begin_payment_attempt')
            ? paykka_begin_payment_attempt($order, 'card')
            : '';
        if ($trans_id === '') {
            return new \WP_Error('paykka_session_blocked', __('Unable to start a new PayKKa payment for this order.', 'paykka-for-woocommerce'));
        }

        // 让 RequestHandler 读取本网关的保存卡开关
        update_option('paykka_enable_saved_cards', $this->get_option('enable_saved_cards', 'no') === 'yes' ? 'yes' : 'no');

        require_once PAYKKA_PLUGIN_PATH . 'classes/lib/Paykka/Request/PaykkaRequestHandler.php';
        $response = (new PaykkaRequestHandler())->buildDropInSession($order);
        if (!is_array($response) || empty($response['data']['session_id']) || (isset($response['ret_code']) && $response['ret_code'] !== '000000')) {
            $message = is_array($response) && !empty($response['ret_msg']) ? (string) $response['ret_msg'] : __('PayKKa 支付会话创建失败，请稍后重试。', 'paykka-for-woocommerce');
            return new \WP_Error('paykka_session_failed', wp_strip_all_tags($message));
        }

        $session_id = sanitize_text_field((string) $response['data']['session_id']);
        $order->update_meta_data('_paykka_session_id', $session_id);
        $order->update_meta_data('_paykka_session_fingerprint', $fingerprint);
        $order->save();
        WC()->session->set('paykka_card_checkout_order_id', $order->get_id());
        WC()->session->set('order_awaiting_payment', $order->get_id());
        WC()->session->save_data();

        return $this->format_session_response($order, $session_id);
    }

    /**
     * Embedded Card 必须以组件 payment() 完成扣款；禁止仅凭 session 就当支付成功。
     */
    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order || !$order->get_id()) {
            return array('result' => 'failure', 'message' => __('Invalid order', 'paykka-for-woocommerce'));
        }

        // 仅当 PayKKa 已确认支付（回调/webhook 标记）才允许 WC 走成功分支
        $paid = $order->get_meta('_paykka_component_paid', true) === 'yes'
            || $order->is_paid()
            || $order->has_status(array('processing', 'completed'));
        if (!$paid) {
            wc_add_notice(
                __('请填写完整的银行卡信息并完成支付后再下单。', 'paykka-for-woocommerce'),
                'error'
            );
            return array('result' => 'failure');
        }

        WC()->cart->empty_cart();
        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url($order),
        );
    }

    public function process_refund($order_id, $amount = null, $reason = '')
    {
        // 与 Hosted 共用同一套退款逻辑：委托主网关实现。
        if (!class_exists('Paykka_Credit_Card_Gateway')) {
            return new \WP_Error('paykka_missing_gateway', __('Paykka gateway unavailable', 'paykka-for-woocommerce'));
        }
        $hosted = new Paykka_Credit_Card_Gateway();
        return $hosted->process_refund($order_id, $amount, $reason);
    }

    private function format_session_response($order, $session_id)
    {
        $settings = getPaykkaSettings();
        $env      = function_exists('paykka_is_sandbox') && paykka_is_sandbox() ? 'sandbox' : (get_option('paykka_api_region', 'eu') === 'hk' ? 'hk' : 'eu');
        return array(
            'session_id'    => $session_id,
            'client_key'    => $settings['paykka_client_key'],
            'env'           => $env,
            'order_id'      => (int) $order->get_id(),
            'return_url'    => PaykkaCallBackHandler::getCallbackUrl($order->get_id()),
            'checkout_base' => function_exists('paykka_get_checkout_base_url') ? paykka_get_checkout_base_url() : '',
        );
    }

    private function clean_checkout_data($posted_data)
    {
        if (is_string($posted_data) && $posted_data !== '') {
            parse_str(wp_unslash($posted_data), $posted_data);
        }
        if (!is_array($posted_data)) {
            return array();
        }
        return wc_clean($posted_data);
    }

    /**
     * 与 WooCommerce 结账规则对齐的账单校验（创建 Card Session 前）。
     *
     * @param array $data
     * @return true|\WP_Error
     */
    private function validate_billing_for_session($data)
    {
        $email    = isset($data['billing_email']) ? trim((string) $data['billing_email']) : '';
        $country  = isset($data['billing_country']) ? trim((string) $data['billing_country']) : '';
        $postcode = isset($data['billing_postcode']) ? trim((string) $data['billing_postcode']) : '';
        $phone    = isset($data['billing_phone']) ? trim((string) $data['billing_phone']) : '';

        if ($email === '' || !is_email($email)) {
            return new \WP_Error('paykka_billing_email', __('请填写有效的账单邮箱。', 'paykka-for-woocommerce'));
        }
        if ($country === '') {
            return new \WP_Error('paykka_billing_country', __('请选择账单国家/地区。', 'paykka-for-woocommerce'));
        }
        if ($postcode !== '' && class_exists('WC_Validation') && !\WC_Validation::is_postcode($postcode, $country)) {
            return new \WP_Error(
                'paykka_billing_postcode',
                __('账单邮编 / ZIP 格式不正确，请按国家/地区要求修改后再支付。', 'paykka-for-woocommerce')
            );
        }
        if ($phone !== '' && class_exists('WC_Validation') && !\WC_Validation::is_phone($phone)) {
            return new \WP_Error(
                'paykka_billing_phone',
                __('账单电话号码格式不正确。', 'paykka-for-woocommerce')
            );
        }
        return true;
    }

    private function sync_checkout_customer($data)
    {
        $fields = array('first_name', 'last_name', 'company', 'email', 'phone', 'address_1', 'address_2', 'city', 'postcode', 'state', 'country');
        $props  = array();
        foreach ($fields as $field) {
            foreach (array('billing', 'shipping') as $type) {
                $key = $type . '_' . $field;
                if (isset($data[$key]) && is_scalar($data[$key])) {
                    $props[$key] = (string) $data[$key];
                }
            }
        }
        if (!empty($props)) {
            WC()->customer->set_props($props);
        }
        if (empty($data['ship_to_different_address']) && WC()->cart->needs_shipping_address()) {
            foreach ($fields as $field) {
                $billing_key = 'billing_' . $field;
                if (isset($props[$billing_key])) {
                    $props['shipping_' . $field] = $props[$billing_key];
                }
            }
            WC()->customer->set_props(array_intersect_key($props, array_flip(array_map(function ($field) {
                return 'shipping_' . $field;
            }, $fields))));
        }
        WC()->customer->save();

        if (isset($data['shipping_method']) && is_array($data['shipping_method'])) {
            WC()->session->set('chosen_shipping_methods', $data['shipping_method']);
        }
    }

    private function populate_checkout_order($order, $data, $cart_hash)
    {
        $order->set_created_via('paykka-card');
        $order->set_customer_id(get_current_user_id());
        $order->set_cart_hash($cart_hash);
        $order->set_currency(get_woocommerce_currency());
        $order->set_prices_include_tax('yes' === get_option('woocommerce_prices_include_tax'));
        $order->set_customer_ip_address(WC_Geolocation::get_ip_address());
        $order->set_customer_user_agent(wc_get_user_agent());
        $order->set_payment_method($this->id);
        $order->set_payment_method_title($this->title);
        if (isset($data['order_comments'])) {
            $order->set_customer_note(wc_sanitize_textarea($data['order_comments']));
        }

        foreach (array('billing', 'shipping') as $type) {
            $address = array();
            foreach (array('first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone') as $field) {
                $key = $type . '_' . $field;
                if (isset($data[$key]) && is_scalar($data[$key])) {
                    $address[$field] = (string) $data[$key];
                }
            }
            if ($type === 'billing' && empty($address)) {
                $address = WC()->customer->get_billing();
            }
            if ($type === 'shipping' && empty($address)) {
                $address = WC()->customer->get_shipping();
            }
            $order->{'set_' . $type}($address);
        }

        WC()->checkout()->set_data_from_cart($order);
        $order->save();
    }

    private function get_session_fingerprint($order)
    {
        $payload = array(
            'cart_hash' => $order->get_cart_hash(),
            'total'     => $order->get_total(),
            'currency'  => $order->get_currency(),
            'billing'   => $order->get_address('billing'),
            'shipping'  => $order->get_address('shipping'),
        );
        return hash('sha256', wp_json_encode($payload));
    }
}
