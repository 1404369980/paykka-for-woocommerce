<?php
/**
 * @wordpress-plugin
 * Plugin Name:       PayKKa for WooCommerce
 * Plugin URI:        https://github.com/1404369980/paykka-for-woocommerce
 * Description:       Easily add the PayKKa Complete Payments Platform including PayKKa Checkout, Direct Credit Processing, and alternative payment methods like Apple Pay, Google Pay.
 * Version:           1.0.0
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
 * Paykka 支付使用虚拟 URL，不创建真实页面，因此不会出现在导航栏中。
 * 虚拟页面通过重写规则 + template_redirect 实现。
 */

/** 支持的支付页面类型（与 URL 路径一致） */
function paykka_get_payment_page_types()
{
    return array('paykka-embedded', 'paykka-dropin', 'paykka-accordion', 'paykka-card-encrypted');
}

/**
 * 获取 Paykka 支付页面 URL（虚拟页面，不依赖数据库中的页面）
 *
 * @param string $type 类型: 'embedded'|'dropin'|'accordion'|'card-encrypted'
 * @return string
 */
function paykka_get_payment_url($type)
{
    $slugs = array(
        'embedded'       => 'paykka-embedded',
        'dropin'         => 'paykka-dropin',
        'accordion'      => 'paykka-accordion',
        'card-encrypted' => 'paykka-card-encrypted',
    );
    $slug = isset($slugs[$type]) ? $slugs[$type] : '';
    return $slug ? home_url('/' . $slug . '/') : home_url('/');
}

/** 注册虚拟页面重写规则 */
function paykka_add_rewrite_rules()
{
    foreach (paykka_get_payment_page_types() as $slug) {
        add_rewrite_rule('^' . preg_quote($slug, '/') . '/?$', 'index.php?paykka_payment_page=' . $slug, 'top');
    }
}
add_action('init', 'paykka_add_rewrite_rules');

/** 注册查询变量 */
function paykka_register_query_var($vars)
{
    $vars[] = 'paykka_payment_page';
    return $vars;
}
add_filter('query_vars', 'paykka_register_query_var');

/** 虚拟页面：拦截请求并输出对应模板（带主题 header/footer，不创建真实页面） */
function paykka_virtual_payment_page_redirect()
{
    $slug = get_query_var('paykka_payment_page');
    if (!$slug || !in_array($slug, paykka_get_payment_page_types(), true)) {
        return;
    }

    $template_map = array(
        'paykka-embedded'       => 'paykka-embedded.php',
        'paykka-dropin'         => 'paykka-dropin.php',
        'paykka-accordion'      => 'paykka-accordion.php',
        'paykka-card-encrypted' => 'paykka-card-encrypted.php',
    );
    $template_file = isset($template_map[$slug]) ? $template_map[$slug] : '';
    if (!$template_file) {
        return;
    }

    $template_path = PAYKKA_PLUGIN_PATH . 'templates/' . $template_file;
    if (!is_readable($template_path)) {
        return;
    }

    // 设置页面标题（供主题 header 使用）
    add_filter('document_title_parts', function ($parts) use ($slug) {
        $titles = array(
            'paykka-embedded'       => __('Paykka Embedded', 'paykka-for-woocommerce'),
            'paykka-dropin'         => __('Paykka DropIn Payment', 'paykka-for-woocommerce'),
            'paykka-accordion'      => __('Paykka Accordion Payment', 'paykka-for-woocommerce'),
            'paykka-card-encrypted' => __('Paykka Encrypted Card Payment', 'paykka-for-woocommerce'),
        );
        $parts['title'] = isset($titles[$slug]) ? $titles[$slug] : $parts['title'];
        return $parts;
    }, 10, 1);

    get_header();
    include $template_path;
    get_footer();
    exit;
}
add_action('template_redirect', 'paykka_virtual_payment_page_redirect', 5);

/** 获取数据库中仍存在的 Paykka 支付页面 ID（用于删除或从导航排除） */
function paykka_get_legacy_payment_page_ids()
{
    $ids = array();
    foreach (paykka_get_payment_page_types() as $slug) {
        $page = get_page_by_path($slug);
        if ($page && !empty($page->ID)) {
            $ids[] = (int) $page->ID;
        }
    }
    return $ids;
}

/**
 * 一次性迁移：删除旧版创建的 4 个 Paykka 页面（不依赖重新激活插件）
 * 执行后设置选项，避免重复删除。
 */
function paykka_maybe_remove_legacy_pages()
{
    if (get_option('paykka_legacy_payment_pages_removed', false)) {
        return;
    }
    $removed = false;
    foreach (paykka_get_payment_page_types() as $slug) {
        $page = get_page_by_path($slug);
        if ($page && !empty($page->ID)) {
            wp_delete_post($page->ID, true);
            $removed = true;
        }
    }
    if ($removed) {
        paykka_add_rewrite_rules();
        flush_rewrite_rules();
    }
    update_option('paykka_legacy_payment_pages_removed', true);
}
add_action('init', 'paykka_maybe_remove_legacy_pages', 1);

/** 从 wp_list_pages 中排除 Paykka 支付页面（兜底：若页面仍存在则不在列表中显示） */
function paykka_exclude_from_page_list($exclude_array)
{
    return array_merge((array) $exclude_array, paykka_get_legacy_payment_page_ids());
}
add_filter('wp_list_pages_excludes', 'paykka_exclude_from_page_list');

/** 从所有导航菜单中移除 Paykka 支付页面（兜底） */
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

/** REST/区块请求中排除 Paykka 页面（区块主题“添加页面”等） */
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

/** 插件激活时：删除旧页面并刷新重写规则 */
function paykka_activation_flush_rewrites()
{
    delete_option('paykka_legacy_payment_pages_removed');
    paykka_maybe_remove_legacy_pages();
    paykka_add_rewrite_rules();
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'paykka_activation_flush_rewrites');

// function paykka_custom_payment_template($template)
// {
//     if (is_page('paykka-payment')) {
//         return plugin_dir_path(__FILE__) . 'templates/paykka-payment.php';
//     }
//     return $template;
// }
// add_filter('template_include', 'paykka_custom_payment_template');

function paykka_paykka_card_encrypted()
{
    ob_start(); // 开启输出缓冲
    include plugin_dir_path(__FILE__) . 'templates/paykka-card-encrypted.php'; // 加载 PHP 模板
    return ob_get_clean(); // 获取缓冲区内容并返回
}
add_shortcode('paykka-card-encrypted', 'paykka_paykka_card_encrypted');

function paykka_paykka_embedded()
{
    ob_start(); // 开启输出缓冲
    include plugin_dir_path(__FILE__) . 'templates/paykka-embedded.php'; // 加载 PHP 模板
    return ob_get_clean(); // 获取缓冲区内容并返回
}
add_shortcode('paykka-embedded', 'paykka_paykka_embedded');

function paykka_paykka_accordion()
{
    ob_start(); // 开启输出缓冲
    include plugin_dir_path(__FILE__) . 'templates/paykka-accordion.php'; // 加载 PHP 模板
    return ob_get_clean(); // 获取缓冲区内容并返回
}
add_shortcode('paykka-accordion', 'paykka_paykka_accordion');
function paykka_paykka_dropin()
{
    ob_start(); // 开启输出缓冲
    include plugin_dir_path(__FILE__) . 'templates/paykka-dropin.php'; // 加载 PHP 模板
    return ob_get_clean(); // 获取缓冲区内容并返回
}
add_shortcode('paykka-dropin', 'paykka_paykka_dropin');



function paykka_hide_page_title()
{
    $paykka_pages = array('paykka-card-encrypted', 'paykka-embedded', 'paykka-accordion', 'paykka-dropin');
    if (is_page($paykka_pages)) {
        add_filter('the_title', '__return_empty_string');
    }
}
add_action('template_redirect', 'paykka_hide_page_title');


// add_action('wp_enqueue_scripts', function() {
//     // 强制在结账页预加载关键脚本
//     if (function_exists('is_checkout') && is_checkout()) {
//         $scripts = [
//             'wc-store',
//             'wc-checkout',
//             'wc-blocks-data'
//         ];
//         foreach ($scripts as $handle) {
//             if (!wp_script_is($handle, 'registered')) {
//                 wp_register_script($handle, '', [], '', true);
//             }
//             wp_enqueue_script($handle);
//         }
//     }
// }, 5); // 优先级设为5确保最早加载


// add_action('rest_api_init', function () {
//     error_log("rest_api_init");
//     register_rest_route('paykka/v1', '/encrypted_card', [
//         'methods'  => 'GET',
//         'callback' => 'handle_encrypted_card',
//         'permission_callback' => '__return_true',
//     ]);
//     error_log("注册成功rest_api_init");
// });

// function handle_encrypted_card(WP_REST_Request $request) {
//     return new WP_REST_Response(['message' => 'API is working'], 200);
// }

// function paykka_register_template($templates) {
//     $templates['paykka-payment/paykka-template.php'] = 'PayKKa Payment Page';
//     return $templates;
// }
// add_filter('theme_page_templates', 'paykka_register_template');

// function paykka_load_template($template) {
//     global $post;

//     if ($post && get_page_template_slug($post->ID) === 'templates/paykka-card-encrypted.php') {
//         return plugin_dir_path(__FILE__) . 'templates/paykka-card-encrypted.php';
//     }

//     return $template;
// }
// add_filter('template_include', 'paykka_load_template');



// 创建页面
// 注册模板路径
// add_filter('woocommerce_locate_template', 'load_paykka_plugin_templates', 10, 3);
// function load_paykka_plugin_templates($template, $template_name, $template_path)
// {
//     $plugin_path = PAYKKA_PLUGIN_PATH . 'templates/';
//     if (file_exists($plugin_path . $template_name)) {
//         return $plugin_path . $template_name;
//     }
//     return $template;
// }

// 加载前端资源
// add_action('wp_enqueue_scripts', 'load_paykka_embedded_checkout_assets');
// function load_paykka_embedded_checkout_assets() {
//     if (is_page('paykka-embedded-checkout')) {
//         wp_enqueue_style('embedded-checkout-style', PAYKKA_PLUGIN_URL . 'assets/css/embedded-checkout.css');
//         wp_enqueue_script('embedded-checkout-script', PAYKKA_PLUGIN_URL . 'assets/js/embedded-checkout.js', array('jquery'), null, true);
//     }
// }

// add_action('wp_footer', function () {
//     $available_gateways = WC()->payment_gateways->get_available_payment_gateways();
//     foreach ($available_gateways as $gateway_id => $gateway) {
//         // 记录每个可用支付网关的 ID 和标题
//         error_log("支付网关 ID: $gateway_id, 标题: " . $gateway->title);
//     }
// });

// add_filter('woocommerce_available_payment_gateways', function($gateways) {
//     error_log('Available Payment Gateways: ' . print_r($gateways, true));
//     return $gateways;
// });

// add_action('wp_footer', function () {
//     if (is_checkout()) {
//         $gateways = WC()->payment_gateways->get_available_payment_gateways();
//         echo '<script>console.log("Available Payment Gateways: ", ' . json_encode(array_keys($gateways)) . ');</script>';
//     }
// });

// add_action('wp_footer', function () {
//     if (is_checkout()) {
//         echo '<script>console.log("Cart Needs Payment? ", ' . (WC()->cart->needs_payment() ? 'true' : 'false') . ');</script>';
//     }
// });


