<?php
/**
 * Paykka Embedded Payments gateway（WC 适配层）。
 * 业务：Paykka_Card_Checkout_Service；HTTP：PaykkaRequestHandler。
 *
 * @extends WC_Payment_Gateway
 */

class Paykka_Card_Gateway extends WC_Payment_Gateway
{
    /** @var string */
    public $version = '1.5.33';

    /** @var Paykka_Card_Checkout_Service|null */
    private $checkout_service;

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
                'label'   => __('允许已登录顾客勾选保存支付信息（仅本支付方式，不影响 Hosted）', 'paykka-for-woocommerce'),
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
        return !empty($settings['paykka_client_key'])
            && !empty($settings['paykka_merchant_id'])
            && !empty($settings['paykka_private_key']);
    }

    public function payment_fields()
    {
        echo '<p>' . esc_html__('请使用 Blocks 结账页完成 Payments 支付。', 'paykka-for-woocommerce') . '</p>';
    }

    public function ajax_create_session()
    {
        if (!$this->verify_ajax_nonce()) {
            return;
        }

        $data = $this->parse_posted_checkout_data();
        if (empty($data['payment_method'])) {
            $data['payment_method'] = $this->id;
        }

        $result = $this->create_checkout_card_session($data);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()), 400);
        }
        wp_send_json_success($result);
    }

    public function ajax_place_order_note()
    {
        if (!$this->verify_ajax_nonce()) {
            return;
        }

        $data = $this->parse_posted_checkout_data();
        if (!empty($data)) {
            $this->sync_checkout_customer($data);
        }

        $service = $this->checkout_service();
        $order   = $service->bind_pending_order($data);
        if (is_wp_error($order)) {
            wp_send_json_error(array('message' => $order->get_error_message()), 400);
        }

        if (function_exists('paykka_add_place_order_note')) {
            paykka_add_place_order_note($order, 'card');
        }

        $cache = $service->get_session_cache();
        wp_send_json_success(array(
            'order_id'   => (int) $order->get_id(),
            'attempt_id' => isset($cache['attempt_id']) ? (string) $cache['attempt_id'] : '',
            'channel'    => Paykka_Card_Checkout_Service::CHANNEL,
            'total'      => $order->get_total(),
            'currency'   => $order->get_currency(),
        ));
    }

    /**
     * 收银台支付失败：把已绑定 WC 订单标为 failed。
     */
    public function ajax_mark_payment_failed()
    {
        if (!$this->verify_ajax_nonce()) {
            return;
        }

        $message = '';
        if (isset($_POST['message'])) {
            $message = sanitize_text_field(wp_unslash((string) $_POST['message']));
        }

        $order = $this->checkout_service()->mark_payment_failed($message);
        if (is_wp_error($order)) {
            wp_send_json_error(array('message' => $order->get_error_message()), 400);
        }

        wp_send_json_success(array(
            'order_id' => (int) $order->get_id(),
            'status'   => $order->get_status(),
        ));
    }

    /**
     * @param string|array $posted_data
     * @return array|\WP_Error
     */
    public function create_checkout_card_session($posted_data = '')
    {
        $data = is_array($posted_data) ? wc_clean($posted_data) : $this->clean_checkout_data($posted_data);
        if (!empty($data['payment_method']) && $data['payment_method'] !== $this->id) {
            return new \WP_Error('paykka_wrong_method', __('请选择 Payments 后再继续。', 'paykka-for-woocommerce'));
        }

        $billing_error = $this->validate_billing_for_session($data);
        if (is_wp_error($billing_error)) {
            return $billing_error;
        }

        $this->sync_checkout_customer($data);
        $force_new = !empty($data['force_new']) || !empty($_POST['force_new']);

        $service = $this->checkout_service();
        $result  = $service->create_component_session($force_new);
        if (is_wp_error($result)) {
            return $result;
        }

        // 对齐同行：Session 就绪后立即绑定 pending WC 订单，保证支付前/失败后都有单可查。
        $order = $service->bind_pending_order($data);
        if (is_wp_error($order)) {
            return $order;
        }
        $result['order_id'] = (int) $order->get_id();

        return $result;
    }

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order || !$order->get_id()) {
            return array('result' => 'failure', 'message' => __('Invalid order', 'paykka-for-woocommerce'));
        }

        $paid = $order->get_meta('_paykka_component_paid', true) === 'yes'
            || $order->is_paid()
            || $order->has_status(array('processing', 'completed'));
        if (!$paid) {
            wc_add_notice(__('请填写完整的银行卡信息并完成支付后再下单。', 'paykka-for-woocommerce'), 'error');
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
        if (!class_exists('Paykka_Credit_Card_Gateway')) {
            return new \WP_Error('paykka_missing_gateway', __('Paykka gateway unavailable', 'paykka-for-woocommerce'));
        }
        return (new Paykka_Credit_Card_Gateway())->process_refund($order_id, $amount, $reason);
    }

    /**
     * @return Paykka_Card_Checkout_Service
     */
    private function checkout_service()
    {
        if ($this->checkout_service instanceof Paykka_Card_Checkout_Service) {
            return $this->checkout_service;
        }
        if (!class_exists('Paykka_Card_Checkout_Service')) {
            require_once PAYKKA_PLUGIN_PATH . 'classes/Service/class-paykka-card-checkout-service.php';
        }
        $this->checkout_service = new Paykka_Card_Checkout_Service(
            $this->title,
            $this->get_option('enable_saved_cards', 'no')
        );
        return $this->checkout_service;
    }

    private function verify_ajax_nonce()
    {
        if (check_ajax_referer('paykka_card_checkout', 'security', false)) {
            return true;
        }
        wp_send_json_error(array('message' => __('安全校验失败，请刷新结账页后重试。', 'paykka-for-woocommerce')), 403);
        return false;
    }

    /**
     * @return array
     */
    private function parse_posted_checkout_data()
    {
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
        return $this->clean_checkout_data($posted_data);
    }

    private function normalize_checkout_title($title)
    {
        $title = is_string($title) ? trim($title) : '';
        if ($title === '' || $title === 'Credit Card') {
            return __('Payments', 'paykka-for-woocommerce');
        }
        return $title;
    }

    private function clean_checkout_data($posted_data)
    {
        if (is_string($posted_data) && $posted_data !== '') {
            parse_str(wp_unslash($posted_data), $posted_data);
        }
        return is_array($posted_data) ? wc_clean($posted_data) : array();
    }

    /**
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
            return new \WP_Error('paykka_billing_postcode', __('账单邮编 / ZIP 格式不正确，请按国家/地区要求修改后再支付。', 'paykka-for-woocommerce'));
        }
        if ($phone !== '' && class_exists('WC_Validation') && !\WC_Validation::is_phone($phone)) {
            return new \WP_Error('paykka_billing_phone', __('账单电话号码格式不正确。', 'paykka-for-woocommerce'));
        }
        return true;
    }

    private function sync_checkout_customer($data)
    {
        if (!function_exists('WC') || !WC()->customer) {
            return;
        }

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

        if (empty($data['ship_to_different_address']) && WC()->cart && WC()->cart->needs_shipping_address()) {
            foreach ($fields as $field) {
                $billing_key = 'billing_' . $field;
                if (isset($props[$billing_key])) {
                    $props['shipping_' . $field] = $props[$billing_key];
                }
            }
            $shipping_keys = array();
            foreach ($fields as $field) {
                $shipping_keys[] = 'shipping_' . $field;
            }
            WC()->customer->set_props(array_intersect_key($props, array_flip($shipping_keys)));
        }
        WC()->customer->save();

        if (isset($data['shipping_method']) && is_array($data['shipping_method'])) {
            WC()->session->set('chosen_shipping_methods', $data['shipping_method']);
        }
    }
}
