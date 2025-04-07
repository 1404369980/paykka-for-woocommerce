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

if (!defined('FENGQIAO_PAYKKA_URL')) {
    define('FENGQIAO_PAYKKA_URL', plugin_dir_path(__FILE__));
}
if (!defined('PAYKKA_PLUGIN_URL')) {
    define('PAYKKA_PLUGIN_PATH', plugin_dir_path(__FILE__));
    define('PAYKKA_PLUGIN_URL', plugin_dir_url(__FILE__));
}


add_action('plugins_loaded', 'woocommerce_paykka_init', 0);
function woocommerce_paykka_init()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }
    /**
     * Epayco add method.
     *
     * @param array $methods all WooCommerce methods.
     */
    function woocommerce_payfast_add_gateway($methods)
    {
        $methods[] = 'Paykka_Credit_Card_Gateway';
        $methods[] = 'Paykka_Embedded_Gateway';
        $methods[] = 'Paykka_Encrypted_Card_Gateway';
        $methods[] = 'Paykka_Drop_In_Gateway';
        return $methods;
    }
    add_filter('woocommerce_payment_gateways', 'woocommerce_payfast_add_gateway');


    function plugin_abspath_paykka()
    {
        return trailingslashit(plugin_dir_path(__FILE__));
    }

    function plugin_url_paykka()
    {
        return untrailingslashit(plugins_url('/', __FILE__));
    }
    // 初始化 Webhook 处理类
    require_once plugin_dir_path(__FILE__) . 'classes/lib/Paykka/Request/PaykkaCallBackHandler.php';
    require_once plugin_dir_path(__FILE__) . 'classes/lib/Paykka/Request/PaykkaWebHookHandler.php';
    new \lib\Paykka\Request\PaykkaWebHookHandler();
    new \lib\Paykka\Request\PaykkaCallBackHandler();

    require_once plugin_basename('classes/wc-paykka-credit-card-gateway.php');
    require_once plugin_basename('classes/wc-paykka-embedded-gateway.php');

    require_once plugin_basename('classes/wc-paykka-encrypted-card-gateway.php');
    require_once plugin_basename('classes/wc-paykka-drop-in-gateway.php');
}


function paykka_gateway_block_support()
{
    // 检查 WooCommerce Blocks 的 AbstractPaymentMethodType 类是否存在
    if (class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        // 引入支付方法的区块支持类
        require_once plugin_dir_path(__FILE__) . 'includes/blocks/wc-gateway-paykka-support.php';
        require_once plugin_dir_path(__FILE__) . 'includes/blocks/wc-gateway-paykka-embedded-support.php';
        require_once plugin_dir_path(__FILE__) . 'includes/blocks/wc-gateway-paykka-encrypted-card-support.php';
        require_once plugin_dir_path(__FILE__) . 'includes/blocks/wc-gateway-paykka-drop-in-support.php';

        // 注册支付方法的区块支持
        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            function (Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
                $payment_method_registry->register(new WC_Gateway_Paykka_Support());
                $payment_method_registry->register(new WC_Gateway_Paykka_Embedded_Support());
                $payment_method_registry->register(new WC_Gateway_Paykka_Encrypted_Card_Support());
                $payment_method_registry->register(new WC_Gateway_Paykka_Drop_In_Support());
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


// 插件激活时创建页面
function paykka_create_payment_page()
{
    $page_slug = 'paykka-embedded';
    // 检查页面是否已存在
    $page_check = get_page_by_path($page_slug);
    if (!$page_check) {
        $page_paykka_payment_id = wp_insert_post([
            'post_title' => 'Paykka Embedded',
            'post_content' => '[paykka-embedded]',
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_name' => $page_slug,
            'show_in_nav_menus' => false,
        ]);
        if ($page_paykka_payment_id) {
            update_post_meta($page_paykka_payment_id, '_wp_page_template', 'default'); // 使用默认模板
        }
        // $page = get_page_by_path('paykka-payment');
        // error_log("url:" . get_permalink($page->id));
    }


    $page_card_encry_slug = 'paykka-card-encrypted';
    $page_card_encry_check = get_page_by_path($page_card_encry_slug);
    if (!$page_card_encry_check) {
        // $template_content = file_exists($template_path) ? file_get_contents($template_path) : '';

        $page_card_encry_slug_id = wp_insert_post([
            'post_title' => 'Paykka Encrypted Card Payment',
            'post_content' => '[paykka-card-encrypted]',
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_name' => $page_card_encry_slug,
            'show_in_nav_menus' => false,
        ]);
        if ($page_card_encry_slug_id) {
            update_post_meta($page_card_encry_slug_id, '_wp_page_template', 'default'); // 使用默认模板
        }
    }

    // 刷新规则
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'paykka_create_payment_page');

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


function paykka_hide_page_title()
{
    // 移除空白标题
    if (is_page('paykka-card-encrypted')) {
        add_filter('the_title', '__return_empty_string'); // 隐藏标题
    }
    // 移除空白标题
    if (is_page('paykka-embedded')) {
        add_filter('the_title', '__return_empty_string'); // 隐藏标题
    }
}
add_action('template_redirect', 'paykka_hide_page_title');


add_action('wp_enqueue_scripts', function() {
    // 强制在结账页预加载关键脚本
    if (function_exists('is_checkout') && is_checkout()) {
        $scripts = [
            'wc-store',
            'wc-checkout',
            'wc-blocks-data'
        ];
        foreach ($scripts as $handle) {
            if (!wp_script_is($handle, 'registered')) {
                wp_register_script($handle, '', [], '', true);
            }
            wp_enqueue_script($handle);
        }
    }
}, 5); // 优先级设为5确保最早加载


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


