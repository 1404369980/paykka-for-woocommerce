<?php
/**
 * @wordpress-plugin
 * Plugin Name:       PayKKa for WooCommerce
 * Plugin URI:        https://github.com/1404369980/paykka-for-woocommerce
 * Description:       PayKKa Hosted 与 Embedded Payments（卡 / Apple Pay / Google Pay），支持 WooCommerce 结账与 Blocks。
 * Version:           1.5.6
 * Author:            Fengqiao Yi
 * Author URI:        https://github.com/1404369980/paykka-for-woocommerce
 * License:           GNU General Public License v3.0
 * License URI:       http://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       paykka-for-woocommerce
 * Domain Path:       /i18n/languages/
 * GitHub Plugin URI: https://github.com/1404369980/paykka-for-woocommerce
 * Requires at least: 6.0
 * Tested up to: 6.6.2
 * Requires Plugins: woocommerce
 * WC requires at least: 9.0.0
 * WC tested up to: 9.6.2
 *
 * ************
 * Attribution
 * ************
 */

defined('ABSPATH') || exit;

if (!defined('PAYKKA_PLUGIN_PATH')) {
    define('PAYKKA_PLUGIN_PATH', plugin_dir_path(__FILE__));
}
if (!defined('PAYKKA_PLUGIN_URL')) {
    define('PAYKKA_PLUGIN_URL', plugin_dir_url(__FILE__));
}
if (!defined('FENGQIAO_PAYKKA_URL')) {
    define('FENGQIAO_PAYKKA_URL', PAYKKA_PLUGIN_PATH);
}


add_action('plugins_loaded', 'woocommerce_paykka_init', 0);
function woocommerce_paykka_init()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }
    $base = plugin_dir_path(__FILE__);
    require_once $base . 'classes/utils/class-paykka-utils.php';
    require_once $base . 'classes/lib/Paykka/Request/PaykkaCallBackHandler.php';
    require_once $base . 'classes/lib/Paykka/Request/PaykkaWebHookHandler.php';
    new \lib\Paykka\Request\PaykkaWebHookHandler();
    new \lib\Paykka\Request\PaykkaCallBackHandler();

    function woocommerce_paykka_add_gateway($methods)
    {
        $methods[] = 'Paykka_Credit_Card_Gateway';
        $methods[] = 'Paykka_Card_Gateway';
        return $methods;
    }
    add_filter('woocommerce_payment_gateways', 'woocommerce_paykka_add_gateway');

    function plugin_abspath_paykka()
    {
        return trailingslashit(plugin_dir_path(__FILE__));
    }
    function plugin_url_paykka()
    {
        return untrailingslashit(plugins_url('/', __FILE__));
    }

    require_once $base . 'classes/wc-paykka-credit-card-gateway.php';
    require_once $base . 'classes/wc-paykka-card-gateway.php';

    /**
     * wc-ajax 在 template_redirect 触发；此时若仅依赖网关构造函数注册 hook，
     * 支付网关可能尚未实例化，会导致 200 空响应（前端 JSON parse 失败）。
     */
    add_action('wc_ajax_paykka_card_create_session', 'paykka_card_ajax_create_session');
}

/**
 * Blocks Credit Card：创建 PayKKa session。
 */
function paykka_card_ajax_create_session()
{
    if (!function_exists('WC') || !WC()->payment_gateways()) {
        wp_send_json_error(array('message' => __('WooCommerce unavailable', 'paykka-for-woocommerce')), 500);
    }
    $gateways = WC()->payment_gateways()->payment_gateways();
    if (empty($gateways['paykka-card']) || !is_object($gateways['paykka-card']) || !method_exists($gateways['paykka-card'], 'ajax_create_session')) {
        wp_send_json_error(array('message' => __('Credit Card gateway unavailable', 'paykka-for-woocommerce')), 500);
    }
    $gateways['paykka-card']->ajax_create_session();
}


function paykka_gateway_block_support()
{
    // 检查 WooCommerce Blocks 的 AbstractPaymentMethodType 类是否存在
    if (class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        require_once plugin_dir_path(__FILE__) . 'includes/blocks/wc-gateway-paykka-support.php';
        require_once plugin_dir_path(__FILE__) . 'includes/blocks/wc-gateway-paykka-card-support.php';
        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            function (Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
                $payment_method_registry->register(new WC_Gateway_Paykka_Support());
                $payment_method_registry->register(new WC_Gateway_Paykka_Card_Support());
            }
        );
    }
}
add_action('woocommerce_blocks_loaded', 'paykka_gateway_block_support');

/**
 * 结账可用网关中：若 Credit Card 排在 Hosted 前面，则对调，避免默认选中落到 Card。
 * 不改变与其他支付方式的相对顺序。
 *
 * @param array $gateways
 * @return array
 */
function paykka_prefer_hosted_before_card($gateways)
{
    if (!is_array($gateways) || empty($gateways['paykka']) || empty($gateways['paykka-card'])) {
        return $gateways;
    }
    $keys = array_keys($gateways);
    $pos_hosted = array_search('paykka', $keys, true);
    $pos_card   = array_search('paykka-card', $keys, true);
    if ($pos_hosted === false || $pos_card === false || $pos_hosted < $pos_card) {
        return $gateways;
    }

    $hosted = $gateways['paykka'];
    $card   = $gateways['paykka-card'];
    $out    = array();
    foreach ($gateways as $id => $gateway) {
        if ($id === 'paykka-card') {
            $out['paykka']      = $hosted;
            $out['paykka-card'] = $card;
            continue;
        }
        if ($id === 'paykka') {
            continue;
        }
        $out[$id] = $gateway;
    }
    return $out;
}
add_filter('woocommerce_available_payment_gateways', 'paykka_prefer_hosted_before_card', 20);

/**
 * 进入结账页（整页加载）时：若 Session 仍记着 Credit Card，改回 Hosted（可用时），
 * 实现「默认不选中 Card」；用户点击 Card 后再选中并加载。
 */
function paykka_checkout_default_away_from_card()
{
    if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return;
    }
    if (!function_exists('is_checkout') || !is_checkout() || is_order_received_page()) {
        return;
    }
    if (!function_exists('WC') || !WC()->session) {
        return;
    }
    if (WC()->session->get('chosen_payment_method') !== 'paykka-card') {
        return;
    }
    $gateways = WC()->payment_gateways()->get_available_payment_gateways();
    if (!empty($gateways['paykka'])) {
        WC()->session->set('chosen_payment_method', 'paykka');
    } else {
        WC()->session->set('chosen_payment_method', '');
    }
}
add_action('template_redirect', 'paykka_checkout_default_away_from_card', 5);

add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

/**
 * 旧版在数据库中创建的站内支付页面 slug（仅用于迁移：隐藏/删除遗留页面）。
 *
 * @return string[]
 */
function paykka_legacy_payment_page_slugs()
{
    return array('paykka-embedded', 'paykka-dropin', 'paykka-accordion', 'paykka-card-encrypted');
}

/** @return int[] */
function paykka_get_legacy_payment_page_ids()
{
    $ids = array();
    foreach (paykka_legacy_payment_page_slugs() as $slug) {
        $page = get_page_by_path($slug);
        if ($page && !empty($page->ID)) {
            $ids[] = (int) $page->ID;
        }
    }
    return $ids;
}

/**
 * 一次性迁移：删除旧版创建的 Paykka 站内支付页面（若存在）。
 */
function paykka_maybe_remove_legacy_pages()
{
    if (get_option('paykka_legacy_payment_pages_removed', false)) {
        return;
    }
    foreach (paykka_legacy_payment_page_slugs() as $slug) {
        $page = get_page_by_path($slug);
        if ($page && !empty($page->ID)) {
            wp_delete_post($page->ID, true);
        }
    }
    update_option('paykka_legacy_payment_pages_removed', true);
    flush_rewrite_rules(false);
}
add_action('init', 'paykka_maybe_remove_legacy_pages', 1);

function paykka_exclude_from_page_list($exclude_array)
{
    return array_merge((array) $exclude_array, paykka_get_legacy_payment_page_ids());
}
add_filter('wp_list_pages_excludes', 'paykka_exclude_from_page_list');

function paykka_remove_from_nav_menus($items, $args)
{
    $ids = paykka_get_legacy_payment_page_ids();
    if (empty($ids)) {
        return $items;
    }
    foreach ($items as $key => $item) {
        if (isset($item->object, $item->object_id) && $item->object === 'page' && in_array((int) $item->object_id, $ids, true)) {
            unset($items[$key]);
        }
    }
    return $items;
}
add_filter('wp_nav_menu_objects', 'paykka_remove_from_nav_menus', 10, 2);

function paykka_exclude_pages_from_rest_query($query)
{
    if (!defined('REST_REQUEST') || !REST_REQUEST) {
        return;
    }
    if ($query->get('post_type') !== 'page') {
        return;
    }
    $ids = paykka_get_legacy_payment_page_ids();
    if (empty($ids)) {
        return;
    }
    $query->set('post__not_in', array_merge((array) $query->get('post__not_in'), $ids));
}
add_action('pre_get_posts', 'paykka_exclude_pages_from_rest_query');

function paykka_activation_cleanup()
{
    delete_option('paykka_legacy_payment_pages_removed');
    paykka_maybe_remove_legacy_pages();
}
register_activation_hook(__FILE__, 'paykka_activation_cleanup');

