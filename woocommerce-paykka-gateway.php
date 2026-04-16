<?php
/**
 * @wordpress-plugin
 * Plugin Name:       PayKKa for WooCommerce
 * Plugin URI:        https://github.com/1404369980/paykka-for-woocommerce
 * Description:       PayKKa Hosted 收银台支付（跳转 Paykka 完成付款），支持 WooCommerce 结账与 Blocks。
 * Version:           1.3.1
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
}


function paykka_gateway_block_support()
{
    // 检查 WooCommerce Blocks 的 AbstractPaymentMethodType 类是否存在
    if (class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        require_once plugin_dir_path(__FILE__) . 'includes/blocks/wc-gateway-paykka-support.php';
        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            function (Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
                $payment_method_registry->register(new WC_Gateway_Paykka_Support());
            }
        );
    }
}
add_action('woocommerce_blocks_loaded', 'paykka_gateway_block_support');


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

