<?php
/**
 * @wordpress-plugin
 * Plugin Name:       PayKKa for WooCommerce
 * Plugin URI:        http://www.angelleye.com/product/PayKKa-for-woocommerce-plugin/
 * Description:       Easily add the PayKKa Complete Payments Platform including PayKKa Checkout, Pay Later, Venmo, Direct Credit Processing, and alternative payment methods like Apple Pay, Google Pay, and more! Also fully supports Braintree Payments.
 * Version:           4.5.21
 * Author:            Angell EYE
 * Author URI:        http://www.angelleye.com/
 * License:           GNU General Public License v3.0
 * License URI:       http://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       paykka-for-woocommerce
 * Domain Path:       /i18n/languages/
 * GitHub Plugin URI: https://github.com/angelleye/PayKKa-woocommerce
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

 defined( 'ABSPATH' ) || exit;

if (! defined('FENGQIAO_PAYKKA_URL')) {
	define('FENGQIAO_PAYKKA_URL', plugin_dir_url(__FILE__));
}


add_action( 'plugins_loaded', 'woocommerce_paykka_init', 0 );
function woocommerce_paykka_init() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}
	/**
	 * Epayco add method.
	 *
	 * @param array $methods all WooCommerce methods.
	 */
    function woocommerce_payfast_add_gateway( $methods ) {
        $methods[] = 'Paykka_Credit_Card_Gateway';
        return $methods;
    }
	add_filter( 'woocommerce_payment_gateways', 'woocommerce_payfast_add_gateway' );


	function plugin_abspath_paykka() {
		return trailingslashit( plugin_dir_path( __FILE__ ) );
	}

	function plugin_url_paykka() {
		return untrailingslashit( plugins_url( '/', __FILE__ ) );
	}

    require_once plugin_basename( 'classes/wc-paykka-credit-card-gateway.php' );
}


function paykka_gateway_block_support() {
    // 检查 WooCommerce Blocks 的 AbstractPaymentMethodType 类是否存在
    if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
        // 引入支付方法的区块支持类
        require_once plugin_dir_path( __FILE__ ) . 'includes/blocks/wc-gateway-paykka-support.php';

        // 注册支付方法的区块支持
        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
                $payment_method_registry->register( new WC_Gateway_Paykka_Support() );
            }
        );
    }
}
add_action( 'woocommerce_blocks_loaded', 'paykka_gateway_block_support' );


add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});



// class Init_Paykka_Gateway{

//     public function __construct(){
//         add_action('plugins_loaded', array($this, 'init'), 10);
//     }

//     public function init(){
//         // wc-paykka-credit-card-gateway
//         add_filter('woocommerce_payment_gateways', array($this, 'add_paykka_gateway'));
//     }

//     public function add_paykka_pro_gateway($methods){
//         include_once(dirname(__FILE__) . '/classes/wc-paykka-credit-card-gateway.php');
//         if (class_exists('Paykka_Credit_Card_Gateway')) {
//             error_log('PayKKa Gateway Registered Successfully');
//             $methods[] = 'Paykka_Credit_Card_Gateway';
//         } else {
//             error_log('Error: PayKKa Gateway Class Not Found');
//         }
//         return $methods;
//     }


//     public function add_paykka_gateway($gateways){
//         include_once(dirname(__FILE__) . '/classes/wc-paykka-credit-card-gateway.php');
//         if (class_exists('Paykka_Credit_Card_Gateway')) {
//             error_log('PayKKa Gateway Registered Successfully');
//             $gateways[] = 'Paykka_Credit_Card_Gateway';
//         } else {
//             error_log('Error: PayKKa Gateway Class Not Found');
//         }
//         return $gateways;
//     }

// }

// new Init_Paykka_Gateway();

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


