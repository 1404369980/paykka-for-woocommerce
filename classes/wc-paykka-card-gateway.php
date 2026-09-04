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
    public $version = '1.5.16';

    public function __construct()
    {
        $this->id                 = 'paykka-card';
        $this->has_fields         = true;
        $this->method_title       = __('Paykka Payments', 'paykka-for-woocommerce');
        $this->method_description = __('Embedded Payments (card / Apple Pay / Google Pay) inside the WooCommerce Blocks checkout. Selecting it shows the payment components, and customers pay by placing the order or using a wallet button. Paykka Hosted is unaffected.', 'paykka-for-woocommerce');
        $this->supports           = array('products', 'refunds');

        $this->init_form_fields();
        $this->init_settings();

        $this->enabled = $this->get_option('enabled', 'yes');
        // 标题默认 Payments，但完全以后台设置为准；仅在留空时回退到默认值
        $title             = trim((string) $this->get_option('title', ''));
        $this->title       = $title !== '' ? $title : __('Payments', 'paykka-for-woocommerce');
        $this->description = '';

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        // AJAX 与 order-pay 资源加载均由主插件文件注册：order-pay 页要到渲染模板时才实例化网关，
        // 构造函数里挂 wp_enqueue_scripts 已经太晚。
    }

    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __('Enable/Disable', 'paykka-for-woocommerce'),
                'type'    => 'checkbox',
                'label'   => __('Enable Paykka Payments (embedded)', 'paykka-for-woocommerce'),
                'default' => 'yes',
            ),
            'title' => array(
                'title'       => __('Title', 'paykka-for-woocommerce'),
                'type'        => 'text',
                'description' => __('Name of the payment method shown at checkout', 'paykka-for-woocommerce'),
                'default'     => __('Payments', 'paykka-for-woocommerce'),
                'desc_tip'    => true,
            ),
            'enable_saved_cards' => array(
                'title'   => __('Enable saved cards', 'paykka-for-woocommerce'),
                'type'    => 'checkbox',
                'label'   => __('Let logged-in customers choose to save their payment details (requires account-side support)', 'paykka-for-woocommerce'),
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
     * 取当前 order-pay 页对应的订单（/checkout/order-pay/{id}/?pay_for_order=true&key=…）。
     *
     * 订单 key 是该页唯一的身份凭据（游客同样凭 key 进入），因此校验与 WooCommerce
     * 的 order-pay 一致：id + key + 仍需付款。
     *
     * @return \WC_Order|null
     */
    public function get_order_pay_order()
    {
        if (is_admin()) {
            return null;
        }

        // 只认 order-pay 端点的 query var：is_checkout() 在早期钩子上可能返回缓存值。
        global $wp;
        $order_id = isset($wp->query_vars['order-pay']) ? absint($wp->query_vars['order-pay']) : 0;
        if (!$order_id || empty($_GET['pay_for_order']) || empty($_GET['key'])) {
            return null;
        }

        return $this->load_payable_order($order_id, wc_clean(wp_unslash($_GET['key'])));
    }

    /**
     * 按 id + key 载入一张仍可付款的订单。
     *
     * @param int    $order_id
     * @param string $order_key
     * @return \WC_Order|null
     */
    private function load_payable_order($order_id, $order_key)
    {
        $order_id  = absint($order_id);
        $order_key = trim((string) $order_key);
        if (!$order_id || $order_key === '') {
            return null;
        }

        $order = wc_get_order($order_id);
        if (!$order || !is_a($order, 'WC_Order') || $order->get_id() !== $order_id) {
            return null;
        }
        if (!hash_equals($order->get_order_key(), $order_key) || !$order->needs_payment()) {
            return null;
        }

        return $order;
    }

    public function payment_fields()
    {
        if (!$this->get_order_pay_order()) {
            // 购物车结账由 Blocks 承担，经典结账页仅显示说明。
            echo '<p>' . esc_html__('Please use the Blocks checkout page to pay with Payments.', 'paykka-for-woocommerce') . '</p>';
            return;
        }

        // order-pay：内嵌卡 / Apple Pay / Google Pay，点「Pay for order」时由 SDK 扣款。
        // 状态与错误行默认隐藏，就绪后页面上只剩卡组件。
        ?>
        <div class="paykka-checkout-fields paykka-order-pay-card"><div id="paykka-checkout-apple-pay" class="paykka-wallet-container paykka-apple-pay-container"></div><div id="paykka-checkout-google-pay" class="paykka-wallet-container paykka-google-pay-container"></div><p class="paykka-card-status" role="status" aria-live="polite" hidden></p><div id="paykka-checkout-card" class="paykka-card-container"></div><p class="paykka-card-error" role="alert" hidden></p></div>
        <?php
    }

    public function payment_scripts()
    {
        // 购物车结账的 Blocks 脚本由 WC_Gateway_Paykka_Card_Support 注册，这里只处理 order-pay。
        $order = $this->get_order_pay_order();
        if (!$order || !$this->is_available()) {
            return;
        }

        $checkout_base = function_exists('paykka_get_checkout_base_url') ? paykka_get_checkout_base_url() : '';
        $sdk_url       = rtrim($checkout_base, '/') . '/cp/card-checkout-ui.js';

        wp_enqueue_style('paykka-card-sdk', rtrim($checkout_base, '/') . '/cp/style.css', array(), $this->version);
        wp_enqueue_style(
            'paykka-checkout-card-local',
            PAYKKA_PLUGIN_URL . 'assets/css/checkout-card.css',
            array('paykka-card-sdk'),
            $this->version
        );

        wp_register_script('paykka-card-sdk', $sdk_url, array(), null, true);
        wp_enqueue_script(
            'paykka-order-pay-card',
            PAYKKA_PLUGIN_URL . 'assets/js/order-pay-card.js',
            array('jquery', 'paykka-card-sdk'),
            $this->version,
            true
        );

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations(
                'paykka-order-pay-card',
                'paykka-for-woocommerce',
                PAYKKA_PLUGIN_PATH . 'i18n/languages'
            );
        }

        wp_localize_script('paykka-order-pay-card', 'PaykkaOrderPayConfig', array(
            'gatewayId'    => $this->id,
            'ajaxUrl'      => WC_AJAX::get_endpoint('paykka_card_create_order_pay_session'),
            'noteAjaxUrl'  => WC_AJAX::get_endpoint('paykka_card_place_order_note'),
            'nonce'        => wp_create_nonce('paykka_card_checkout'),
            'orderId'      => (int) $order->get_id(),
            'orderKey'     => $order->get_order_key(),
            'sdkUrl'       => $sdk_url,
            'checkoutBase' => $checkout_base,
            'i18n'         => array(
                'loading'  => __('Preparing the secure payment form…', 'paykka-for-woocommerce'),
                'error'    => __('The PayKKa payment form failed to load. Please refresh and try again.', 'paykka-for-woocommerce'),
                // 与 Blocks 结账共用同一批文案，避免重复的待翻译条目
                'notReady' => __('The payment form is not ready yet. Please wait a moment before placing your order.', 'paykka-for-woocommerce'),
                'paying'   => __('Processing payment…', 'paykka-for-woocommerce'),
                'needCard' => __('Please enter your complete card details before placing the order, or use Apple Pay / Google Pay.', 'paykka-for-woocommerce'),
            ),
        ));
    }

    public function ajax_create_session()
    {
        if (!check_ajax_referer('paykka_card_checkout', 'security', false)) {
            wp_send_json_error(array('message' => __('Security check failed. Please refresh the checkout page and try again.', 'paykka-for-woocommerce')), 403);
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
     * order-pay 页：为已有订单创建 COMPONENT Session（不依赖购物车）。
     */
    public function ajax_create_order_pay_session()
    {
        if (!check_ajax_referer('paykka_card_checkout', 'security', false)) {
            wp_send_json_error(array('message' => __('Security check failed. Please refresh the page and try again.', 'paykka-for-woocommerce')), 403);
        }

        $order_id  = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $order_key = isset($_POST['order_key']) ? wc_clean(wp_unslash($_POST['order_key'])) : '';
        $force_new = !empty($_POST['force_new']);

        $result = $this->create_order_pay_session($order_id, $order_key, $force_new);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()), 400);
        }
        wp_send_json_success($result);
    }

    /**
     * 为已有待付款订单创建 COMPONENT Session。
     *
     * 与购物车结账不同：订单已经定型，金额/明细/地址都从订单读取，
     * 因此不校验购物车，也不再改动订单内容。
     *
     * @param int    $order_id
     * @param string $order_key
     * @param bool   $force_new 忽略可复用的 session，强制新建
     * @return array|\WP_Error
     */
    public function create_order_pay_session($order_id, $order_key, $force_new = false)
    {
        $order = $this->load_payable_order($order_id, $order_key);
        if (!$order) {
            return new \WP_Error(
                'paykka_invalid_order',
                __('This order cannot be paid for. Please refresh the page or contact us for assistance.', 'paykka-for-woocommerce')
            );
        }
        if (!$this->is_available()) {
            return new \WP_Error('paykka_unavailable', __('Payments is unavailable right now.', 'paykka-for-woocommerce'));
        }

        // 顾客可能从别的支付方式切过来，付款渠道以本次选择为准。
        if ($order->get_payment_method() !== $this->id) {
            $order->set_payment_method($this->id);
            $order->set_payment_method_title($this->title);
        }
        $order->update_meta_data('_paykka_sub_method', 'card');
        // 回调失败时据此回到 order-pay 页，而不是空购物车的结账页。
        $order->update_meta_data('_paykka_pay_for_order', 'yes');
        $order->save();

        // 账单/金额未变时可复用现有 session，避免重复调用 Create Session。
        $fingerprint     = $this->get_session_fingerprint($order);
        $session_id      = trim((string) $order->get_meta('_paykka_session_id', true));
        $old_fingerprint = (string) $order->get_meta('_paykka_session_fingerprint', true);
        if (!$force_new && $session_id !== '' && hash_equals($old_fingerprint, $fingerprint)) {
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
            $message = is_array($response) && !empty($response['ret_msg'])
                ? (string) $response['ret_msg']
                : __('Could not create the PayKKa payment session. Please try again later.', 'paykka-for-woocommerce');
            return new \WP_Error('paykka_session_failed', wp_strip_all_tags($message));
        }

        $session_id = sanitize_text_field((string) $response['data']['session_id']);
        $order->update_meta_data('_paykka_session_id', $session_id);
        $order->update_meta_data('_paykka_session_fingerprint', $fingerprint);
        $order->save();

        return $this->format_session_response($order, $session_id);
    }

    /**
     * Payments 下单时写订单备注。
     */
    public function ajax_place_order_note()
    {
        if (!check_ajax_referer('paykka_card_checkout', 'security', false)) {
            wp_send_json_error(array('message' => __('Security check failed. Please refresh the checkout page and try again.', 'paykka-for-woocommerce')), 403);
        }

        // order-pay 页没有购物车 session 上下文，凭 id + key 定位订单。
        if (!empty($_POST['order_id']) && !empty($_POST['order_key'])) {
            $order = $this->load_payable_order(
                absint($_POST['order_id']),
                wc_clean(wp_unslash($_POST['order_key']))
            );
            if (!$order) {
                wp_send_json_error(array('message' => __('Order not found', 'paykka-for-woocommerce')), 404);
            }
            if (function_exists('paykka_add_place_order_note')) {
                paykka_add_place_order_note($order, 'card');
            }
            wp_send_json_success(array('order_id' => (int) $order->get_id()));
        }

        if (!function_exists('WC') || !WC()->session) {
            wp_send_json_error(array('message' => __('Session unavailable', 'paykka-for-woocommerce')), 400);
        }
        $order_id = absint(WC()->session->get('paykka_card_checkout_order_id'));
        if (!$order_id) {
            $order_id = absint(WC()->session->get('order_awaiting_payment'));
        }
        $order = $order_id ? wc_get_order($order_id) : null;
        if (!$order || !$order->get_id()) {
            wp_send_json_error(array('message' => __('Order not found', 'paykka-for-woocommerce')), 404);
        }
        if (function_exists('paykka_add_place_order_note')) {
            paykka_add_place_order_note($order, 'card');
        }
        wp_send_json_success(array('order_id' => (int) $order->get_id()));
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
            return new \WP_Error('paykka_empty_cart', __('Your cart is empty or the checkout session has expired.', 'paykka-for-woocommerce'));
        }

        $data = $this->clean_checkout_data($posted_data);
        if (!empty($data['payment_method']) && $data['payment_method'] !== $this->id) {
            return new \WP_Error('paykka_wrong_method', __('Please select Payments before continuing.', 'paykka-for-woocommerce'));
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
            $message = is_array($response) && !empty($response['ret_msg']) ? (string) $response['ret_msg'] : __('Could not create the PayKKa payment session. Please try again later.', 'paykka-for-woocommerce');
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
                __('Please enter complete card details and finish the payment before placing the order.', 'paykka-for-woocommerce'),
                'error'
            );
            return array('result' => 'failure');
        }

        // 为已有订单付款时购物车与本单无关，不能清空。
        if ($order->get_meta('_paykka_pay_for_order', true) !== 'yes' && function_exists('WC') && WC()->cart) {
            WC()->cart->empty_cart();
        }
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
            return new \WP_Error('paykka_billing_email', __('Please enter a valid billing email address.', 'paykka-for-woocommerce'));
        }
        if ($country === '') {
            return new \WP_Error('paykka_billing_country', __('Please select a billing country/region.', 'paykka-for-woocommerce'));
        }
        if ($postcode !== '' && class_exists('WC_Validation') && !\WC_Validation::is_postcode($postcode, $country)) {
            return new \WP_Error(
                'paykka_billing_postcode',
                __('The billing postcode / ZIP is invalid. Please correct it for the selected country/region before paying.', 'paykka-for-woocommerce')
            );
        }
        if ($phone !== '' && class_exists('WC_Validation') && !\WC_Validation::is_phone($phone)) {
            return new \WP_Error(
                'paykka_billing_phone',
                __('The billing phone number is invalid.', 'paykka-for-woocommerce')
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
