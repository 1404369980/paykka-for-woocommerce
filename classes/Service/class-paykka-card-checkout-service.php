<?php
/**
 * Paykka Payments（Embedded / paykka-card）业务层。
 *
 * 与 Hosted 隔离：attempt / session 缓存 / 下单绑定仅服务 Card。
 * 网关类只做 WC 适配；PayKKa HTTP 调用走 RequestHandler（接口层）。
 */

defined('ABSPATH') || exit;

use lib\Paykka\Request\PaykkaCallBackHandler;

class Paykka_Card_Checkout_Service
{
    const CHANNEL          = 'card';
    const GATEWAY_ID       = 'paykka-card';
    const SESSION_KEY      = 'paykka_card_session';
    const ORDER_SESSION_KEY = 'paykka_card_checkout_order_id';
    const ATTEMPT_SESSION_KEY = 'paykka_card_attempt_id';

    /** @var string */
    private $payment_method_title;

    /** @var string yes|no */
    private $enable_saved_cards;

    /**
     * @param string $payment_method_title
     * @param string $enable_saved_cards
     */
    public function __construct($payment_method_title = '', $enable_saved_cards = 'no')
    {
        $this->payment_method_title = $payment_method_title !== '' ? $payment_method_title : __('Payments', 'paykka-for-woocommerce');
        $this->enable_saved_cards   = $enable_saved_cards === 'yes' ? 'yes' : 'no';
    }

    /**
     * 进入结账页时生成（或复用）Card 专用 attempt_id。
     *
     * @return string
     */
    public static function get_or_create_attempt_id()
    {
        if (!function_exists('WC') || !WC()->session) {
            return '';
        }
        $id = WC()->session->get(self::ATTEMPT_SESSION_KEY);
        if (!is_string($id) || $id === '') {
            // 兼容旧 key（一次迁移）
            $legacy = WC()->session->get('paykka_checkout_attempt_id');
            if (is_string($legacy) && $legacy !== '') {
                $id = $legacy;
            } else {
                $id = 'a' . substr(md5(uniqid('paykka_card', true)), 0, 16) . gmdate('His') . (string) wp_rand(10, 99);
                $id = substr(preg_replace('/[^a-zA-Z0-9]/', '', $id), 0, 64);
            }
            WC()->session->set(self::ATTEMPT_SESSION_KEY, $id);
            WC()->session->__unset('paykka_checkout_attempt_id');
            WC()->session->save_data();
        }
        return $id;
    }

    /**
     * @param string $attempt_id
     * @return string
     */
    public static function new_trans_id($attempt_id)
    {
        $attempt_id = preg_replace('/[^a-zA-Z0-9]/', '', (string) $attempt_id);
        $trans_id   = $attempt_id . 'c' . gmdate('YmdHis') . wp_rand(100, 999);
        if (strlen($trans_id) > 64) {
            $trans_id = substr($attempt_id . 'c' . substr(md5(uniqid($attempt_id, true)), 0, 12), 0, 64);
        }
        return $trans_id;
    }

    /**
     * 选中 Payments：Create COMPONENT Session（不建 WC 订单）。
     *
     * @param bool $force_new
     * @return array|\WP_Error
     */
    public function create_component_session($force_new = false)
    {
        if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty() || !WC()->session) {
            return new \WP_Error('paykka_empty_cart', __('购物车为空或结账会话已过期。', 'paykka-for-woocommerce'));
        }

        WC()->cart->calculate_totals();

        $attempt_id = self::get_or_create_attempt_id();
        if ($attempt_id === '') {
            return new \WP_Error('paykka_attempt_missing', __('结账会话已过期，请刷新结账页后重试。', 'paykka-for-woocommerce'));
        }

        $fingerprint = $this->fingerprint();
        $cached      = $this->get_session_cache();
        if (!$force_new && $this->is_reusable_cache($cached, $attempt_id, $fingerprint)) {
            return $this->format_session_response($attempt_id, (string) $cached['session_id'], $cached);
        }

        $trans_id = self::new_trans_id($attempt_id);
        $amount   = $this->cart_amount_minor();
        $currency = get_woocommerce_currency();
        $return_url = PaykkaCallBackHandler::getCallbackUrlByAttempt($attempt_id);

        require_once PAYKKA_PLUGIN_PATH . 'classes/lib/Paykka/Request/PaykkaRequestHandler.php';
        $response = (new \lib\Paykka\Request\PaykkaRequestHandler())->createComponentSessionFromCart(array(
            'channel'            => self::CHANNEL,
            'attempt_id'         => $attempt_id,
            'trans_id'           => $trans_id,
            'amount'             => $amount,
            'currency'           => $currency,
            'return_url'         => $return_url,
            'cancel_url'         => $return_url,
            'enable_saved_cards' => $this->enable_saved_cards,
        ));

        if (!is_array($response) || empty($response['data']['session_id']) || (isset($response['ret_code']) && $response['ret_code'] !== '000000')) {
            $message = is_array($response) && !empty($response['ret_msg']) ? (string) $response['ret_msg'] : __('PayKKa 支付会话创建失败，请稍后重试。', 'paykka-for-woocommerce');
            return new \WP_Error('paykka_session_failed', wp_strip_all_tags($message));
        }

        $session_id = sanitize_text_field((string) $response['data']['session_id']);
        $cache      = array(
            'channel'     => self::CHANNEL,
            'attempt_id'  => $attempt_id,
            'trans_id'    => $trans_id,
            'session_id'  => $session_id,
            'fingerprint' => $fingerprint,
            'amount'      => $amount,
            'currency'    => $currency,
            'return_url'  => $return_url,
        );
        $this->set_session_cache($cache);
        self::remember_mapping($attempt_id, $trans_id, $session_id, 0);

        return $this->format_session_response($attempt_id, $session_id, $cache);
    }

    /**
     * 下单：创建/复用 pending 订单并绑定 Card session。
     *
     * @param array $checkout_data
     * @return \WC_Order|\WP_Error
     */
    public function bind_pending_order(array $checkout_data)
    {
        if (!function_exists('WC') || !WC()->session || !WC()->cart || WC()->cart->is_empty()) {
            return new \WP_Error('paykka_session', __('Session unavailable', 'paykka-for-woocommerce'));
        }

        $card_session = $this->get_session_cache();
        if (empty($card_session['session_id']) || empty($card_session['trans_id']) || empty($card_session['attempt_id'])) {
            return new \WP_Error('paykka_session_missing', __('Payment session not ready', 'paykka-for-woocommerce'));
        }
        if (!empty($card_session['channel']) && $card_session['channel'] !== self::CHANNEL) {
            return new \WP_Error('paykka_wrong_channel', __('Invalid payment channel', 'paykka-for-woocommerce'));
        }

        WC()->cart->calculate_totals();
        $cart_hash = WC()->cart->get_cart_hash();

        return $this->get_or_create_pending_order($checkout_data, $cart_hash, $card_session);
    }

    /**
     * @return array
     */
    public function get_session_cache()
    {
        if (!function_exists('WC') || !WC()->session) {
            return array();
        }
        $cached = WC()->session->get(self::SESSION_KEY);
        return is_array($cached) ? $cached : array();
    }

    /**
     * Card 专用：attempt → order。不回退 Session 盲绑定。
     *
     * @param string $attempt_id
     * @return int
     */
    public static function resolve_order_id_by_attempt($attempt_id)
    {
        $attempt_id = sanitize_text_field((string) $attempt_id);
        if ($attempt_id === '') {
            return 0;
        }

        $order_id = absint(get_transient(self::transient_attempt_key($attempt_id)));
        if (!$order_id) {
            // 兼容旧 transient 前缀
            $order_id = absint(get_transient('paykka_attempt_' . $attempt_id));
        }
        if (!$order_id) {
            return 0;
        }

        $order = wc_get_order($order_id);
        if (!$order || !$order->get_id()) {
            return 0;
        }
        if ($order->get_payment_method() !== self::GATEWAY_ID) {
            return 0;
        }
        $meta_attempt = (string) $order->get_meta('_paykka_attempt_id', true);
        if ($meta_attempt !== '' && !hash_equals($meta_attempt, $attempt_id)) {
            return 0;
        }
        return (int) $order->get_id();
    }

    /**
     * Webhook 兜底：仅解析 Card 渠道映射。
     *
     * @param string $trans_id
     * @param string $session_id
     * @return int
     */
    public static function resolve_order_id_by_trans_or_session($trans_id = '', $session_id = '')
    {
        $trans_id   = sanitize_text_field((string) $trans_id);
        $session_id = sanitize_text_field((string) $session_id);

        foreach (array(
            $trans_id !== '' ? get_transient(self::transient_trans_key($trans_id)) : false,
            $trans_id !== '' ? get_transient('paykka_trans_' . $trans_id) : false,
            $session_id !== '' ? get_transient(self::transient_sid_key($session_id)) : false,
            $session_id !== '' ? get_transient('paykka_sid_' . $session_id) : false,
        ) as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!empty($row['channel']) && $row['channel'] !== self::CHANNEL) {
                continue;
            }
            if (!empty($row['order_id'])) {
                $oid = absint($row['order_id']);
                if ($oid && self::order_is_card($oid)) {
                    return $oid;
                }
            }
            if (!empty($row['attempt_id'])) {
                $oid = self::resolve_order_id_by_attempt($row['attempt_id']);
                if ($oid) {
                    return $oid;
                }
            }
        }
        return 0;
    }

    /**
     * @param string   $attempt_id
     * @param string   $trans_id
     * @param string   $session_id
     * @param int      $order_id
     */
    public static function remember_mapping($attempt_id, $trans_id, $session_id = '', $order_id = 0)
    {
        $attempt_id = sanitize_text_field((string) $attempt_id);
        $trans_id   = sanitize_text_field((string) $trans_id);
        $session_id = sanitize_text_field((string) $session_id);
        $order_id   = absint($order_id);
        if ($attempt_id === '' || $trans_id === '') {
            return;
        }
        $payload = array(
            'channel'    => self::CHANNEL,
            'attempt_id' => $attempt_id,
            'trans_id'   => $trans_id,
            'session_id' => $session_id,
            'order_id'   => $order_id,
        );
        set_transient(self::transient_trans_key($trans_id), $payload, DAY_IN_SECONDS);
        if ($session_id !== '') {
            set_transient(self::transient_sid_key($session_id), $payload, DAY_IN_SECONDS);
        }
        if ($order_id > 0) {
            set_transient(self::transient_attempt_key($attempt_id), $order_id, DAY_IN_SECONDS);
        }
    }

    public static function clear_checkout_session()
    {
        if (!function_exists('WC') || !WC()->session) {
            return;
        }
        WC()->session->__unset(self::ATTEMPT_SESSION_KEY);
        WC()->session->__unset(self::SESSION_KEY);
        WC()->session->__unset(self::ORDER_SESSION_KEY);
        WC()->session->__unset('paykka_checkout_attempt_id');
        WC()->session->__unset('paykka_checkout_order_id');
        WC()->session->save_data();
    }

    /**
     * @param int $order_id
     * @return bool
     */
    private static function order_is_card($order_id)
    {
        $order = wc_get_order($order_id);
        return $order && $order->get_id() && $order->get_payment_method() === self::GATEWAY_ID;
    }

    private static function transient_attempt_key($attempt_id)
    {
        return 'paykka_card_attempt_' . $attempt_id;
    }

    private static function transient_trans_key($trans_id)
    {
        return 'paykka_card_trans_' . $trans_id;
    }

    private static function transient_sid_key($session_id)
    {
        return 'paykka_card_sid_' . $session_id;
    }

    private function set_session_cache(array $cache)
    {
        if (!function_exists('WC') || !WC()->session) {
            return;
        }
        WC()->session->set(self::SESSION_KEY, $cache);
        WC()->session->__unset(self::ORDER_SESSION_KEY);
        WC()->session->save_data();
    }

    private function is_reusable_cache(array $cached, $attempt_id, $fingerprint)
    {
        return !empty($cached['session_id'])
            && !empty($cached['trans_id'])
            && isset($cached['attempt_id']) && hash_equals((string) $cached['attempt_id'], (string) $attempt_id)
            && isset($cached['fingerprint']) && hash_equals((string) $cached['fingerprint'], (string) $fingerprint);
    }

    private function cart_amount_minor()
    {
        $decimal_places = (int) get_option('woocommerce_price_num_decimals', 2);
        return (int) round(((float) WC()->cart->get_total('edit')) * pow(10, $decimal_places));
    }

    private function fingerprint()
    {
        return hash('sha256', wp_json_encode(array(
            'currency' => get_woocommerce_currency(),
            'mode'     => 'COMPONENT',
            'channel'  => self::CHANNEL,
        )));
    }

    private function format_session_response($attempt_id, $session_id, array $cache = array())
    {
        $settings = getPaykkaSettings();
        $env      = function_exists('paykka_is_sandbox') && paykka_is_sandbox()
            ? 'sandbox'
            : (get_option('paykka_api_region', 'eu') === 'hk' ? 'hk' : 'eu');
        $amount = isset($cache['amount']) ? (int) $cache['amount'] : 0;
        if ($amount <= 0 && WC()->cart) {
            $amount = $this->cart_amount_minor();
        }
        $currency   = !empty($cache['currency']) ? (string) $cache['currency'] : get_woocommerce_currency();
        $return_url = !empty($cache['return_url'])
            ? (string) $cache['return_url']
            : PaykkaCallBackHandler::getCallbackUrlByAttempt($attempt_id);

        return array(
            'session_id'    => $session_id,
            'client_key'    => $settings['paykka_client_key'],
            'env'           => $env,
            'attempt_id'    => $attempt_id,
            'channel'       => self::CHANNEL,
            'order_id'      => 0,
            'amount'        => $amount,
            'currency'      => $currency,
            'return_url'    => $return_url,
            'checkout_base' => function_exists('paykka_get_checkout_base_url') ? paykka_get_checkout_base_url() : '',
        );
    }

    /**
     * @param array  $data
     * @param string $cart_hash
     * @param array  $card_session
     * @return \WC_Order|\WP_Error
     */
    private function get_or_create_pending_order($data, $cart_hash, array $card_session)
    {
        $attempt_id = (string) $card_session['attempt_id'];
        $session_id = (string) $card_session['session_id'];
        $trans_id   = (string) $card_session['trans_id'];

        $order_id = absint(WC()->session->get(self::ORDER_SESSION_KEY));
        if (!$order_id) {
            $order_id = self::resolve_order_id_by_attempt($attempt_id);
        }

        $order = $order_id ? wc_get_order($order_id) : null;
        $reuse = $order
            && $order->get_id()
            && $order->has_status(array('pending', 'failed'))
            && $order->get_payment_method() === self::GATEWAY_ID
            && (string) $order->get_meta('_paykka_attempt_id', true) === $attempt_id;

        if (!$reuse) {
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

        $this->populate_order($order, $data, $cart_hash);
        $order->update_meta_data('_paykka_checkout_order', 'yes');
        $order->update_meta_data('_paykka_sub_method', self::CHANNEL);
        $order->update_meta_data('_paykka_attempt_id', $attempt_id);
        $order->update_meta_data('_paykka_session_id', $session_id);
        $order->update_meta_data('_paykka_trans_id', $trans_id);
        if (!empty($card_session['fingerprint'])) {
            $order->update_meta_data('_paykka_session_fingerprint', (string) $card_session['fingerprint']);
        }
        $order->save();

        self::remember_mapping($attempt_id, $trans_id, $session_id, $order->get_id());

        WC()->session->set(self::ORDER_SESSION_KEY, $order->get_id());
        WC()->session->set('order_awaiting_payment', $order->get_id());
        WC()->session->save_data();

        return $order;
    }

    private function populate_order($order, $data, $cart_hash)
    {
        $order->set_created_via('paykka-card');
        $order->set_customer_id(get_current_user_id());
        $order->set_cart_hash($cart_hash);
        $order->set_currency(get_woocommerce_currency());
        $order->set_prices_include_tax('yes' === get_option('woocommerce_prices_include_tax'));
        $order->set_customer_ip_address(\WC_Geolocation::get_ip_address());
        $order->set_customer_user_agent(wc_get_user_agent());
        $order->set_payment_method(self::GATEWAY_ID);
        $order->set_payment_method_title($this->payment_method_title);
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
}
