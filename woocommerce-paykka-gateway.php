<?php
/**
 * @wordpress-plugin
 * Plugin Name:       PayKKa for WooCommerce
 * Plugin URI:        http://www.angelleye.com/product/PayKKa-for-woocommerce-plugin/
 * Description:       Easily add the PayKKa Complete Payments Platform including PayKKa Checkout, Pay Later, Venmo, Direct Credit Processing, and alternative payment methods like Apple Pay, Google Pay, and more! Also fully supports Braintree Payments.
 * Version:           4.5.21
 * Author:            Fengqiao Yi
 * Author URI:        https://github.com/1404369980/paykka-for-woocommerce.git
 * License:           GNU General Public License v3.0
 * License URI:       http://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       paykka-for-woocommerce
 * Domain Path:       /i18n/languages/
 * GitHub Plugin URI: https://github.com/1404369980/paykka-for-woocommerce.git
 * Requires at least: 5.8
 * Tested up to: 6.6.2
 * Requires Plugins: woocommerce
 * WC requires at least: 3.0.0
 * WC tested up to: 9.3.2
 *
 * ************
 * Attribution
 * ************
 * PayKKa for WooCommerce is a derivative work of the code from WooThemes / SkyVerge,
 * which is licensed with GPLv3. This code is also licensed under the terms
 * of the GNU Public License, version 3.
 */

defined('ABSPATH') || exit;

if (!defined('FENGQIAO_PAYKKA_URL')) {
    define('FENGQIAO_PAYKKA_URL', plugin_dir_path(__FILE__));
}
if (!defined('PAYKKA_PLUGIN_URL')) {
    define('PAYKKA_PLUGIN_PATH', plugin_dir_path(__FILE__));
    define('PAYKKA_PLUGIN_URL', plugin_dir_url(__FILE__));
}

// 交易回调
// require_once FENGQIAO_PAYKKA_URL . '\classes\lib\Paykka\Request\PaykkaWebHookHandler.php';

// 引入 Webhook 处理类
require_once plugin_dir_path(__FILE__) . 'classes/lib/Paykka/Request/PaykkaWebHookHandler.php';
require_once plugin_dir_path(__FILE__) . 'classes/lib/Paykka/Request/PaykkaCallBackHandler.php';


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
    new \lib\Paykka\Request\PaykkaWebHookHandler();
    new \lib\Paykka\Request\PaykkaCallBackHandler();

    require_once plugin_basename('classes/wc-paykka-credit-card-gateway.php');
    require_once plugin_basename('classes/wc-paykka-embedded-gateway.php');
}


function paykka_gateway_block_support()
{
    // 检查 WooCommerce Blocks 的 AbstractPaymentMethodType 类是否存在
    if (class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        // 引入支付方法的区块支持类
        require_once plugin_dir_path(__FILE__) . 'includes/blocks/wc-gateway-paykka-support.php';
        require_once plugin_dir_path(__FILE__) . 'includes/blocks/wc-gateway-paykka-embedded-support.php';

        // 注册支付方法的区块支持
        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            function (Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
                $payment_method_registry->register(new WC_Gateway_Paykka_Support());
                $payment_method_registry->register(new WC_Gateway_Paykka_Embedded_Support());
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
    $page_title = 'Paykka Payment';
    // $page_content = '[paykka_payment_shortcode]'; // 通过 Shortcode 加载支付内容
    $page_content='';
    $page_slug = 'paykka-payment';

    // 检查页面是否已存在
    $page_check = get_page_by_path($page_slug);
    if (!$page_check) {
        $page_id = wp_insert_post([
            'post_title' => $page_title,
            'post_content' => $page_content,
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_name' => $page_slug,
        ]);
        // 关键：刷新 URL 规则

        $page = get_page_by_path('paykka-payment');
        error_log("url:".get_permalink($page->id));
        flush_rewrite_rules();
        
    }
}
register_activation_hook(__FILE__, 'paykka_create_payment_page');


// Shortcode 显示支付页面
// function paykka_payment_shortcode()
// {
//     $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;

//     return '<h1>支付订单 #' . esc_html($order_id) . '</h1>
//         <iframe src="https://paykka.com/checkout?order_id=' . esc_html($order_id) . '" width="100%" height="600px" style="border: none;"></iframe>';
// }
// add_shortcode('paykka_payment_shortcode', 'paykka_payment_shortcode');

function paykka_custom_payment_template($template) {
    if (is_page('paykka-payment')) {
        return plugin_dir_path(__FILE__) . 'templates/paykka-payment.php';
    }
    return $template;
}
add_filter('template_include', 'paykka_custom_payment_template');



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


