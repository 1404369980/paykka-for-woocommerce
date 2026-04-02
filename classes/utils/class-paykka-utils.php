<?php

if (!defined('ABSPATH')) {
    exit;
}
// if (!class_exists('WC_Settings_Page')) return;

/**
 * 是否开启 Paykka Debug 模式（开发时用于输出详细 error_log）。
 * 为 true 当：后台「开启 Debug 模式」勾选，或 wp-config 中 WP_DEBUG 为 true。
 *
 * @return bool
 */
function paykka_is_debug()
{
    if (function_exists('get_option')) {
        // 未保存过选项时默认视为开启，便于开发
        if (get_option('paykka_debug_mode', 'yes') === 'yes') {
            return true;
        }
    }
    return defined('WP_DEBUG') && WP_DEBUG;
}

/**
 * 是否记录 Paykka 接口请求/响应日志。
 * 为 true 当：Debug 模式、或勾选「记录请求结果日志」、或 WP_DEBUG_LOG。
 *
 * @return bool
 */
function paykka_is_log_enabled()
{
    if (paykka_is_debug()) {
        return true;
    }
    if (function_exists('get_option') && get_option('paykka_log_request_result', 'yes') === 'yes') {
        return true;
    }
    return defined('WP_DEBUG_LOG') && WP_DEBUG_LOG;
}

/**
 * 加载 Paykka 配置文件（可选）
 * 优先：wp-content/paykka-config.php（升级插件不覆盖），其次：插件目录 paykka-config.php。
 * 配置文件 return 数组，需包含 'env' => 'production'|'sandbox'，可选 'production'/'sandbox' 下 api_base_url、checkout_base_url。
 *
 * @return array{env?: string, production?: array, sandbox?: array}
 */
function paykka_load_config()
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }
    $config = array();
    $candidates = array();
    if (defined('ABSPATH')) {
        $candidates[] = ABSPATH . 'wp-content/paykka-config.php';
    }
    if (defined('PAYKKA_PLUGIN_PATH')) {
        $candidates[] = PAYKKA_PLUGIN_PATH . 'paykka-config.php';
    }
    foreach ($candidates as $path) {
        if (is_readable($path)) {
            $loaded = include $path;
            if (is_array($loaded)) {
                $config = $loaded;
            }
            break;
        }
    }
    return $config;
}

/**
 * 是否使用沙箱/测试环境（生产 = false，测试 = true）
 * 优先级：配置文件 env > wp-config 常量 PAYKKA_ENV > 后台 Sandbox 勾选。
 *
 * @return bool
 */
function paykka_is_sandbox()
{
    $config = paykka_load_config();
    if (!empty($config['env'])) {
        $env = strtolower((string) $config['env']);
        if ($env === 'production' || $env === 'prod') {
            return false;
        }
        if ($env === 'sandbox' || $env === 'test') {
            return true;
        }
    }
    if (defined('PAYKKA_ENV')) {
        $env = strtolower((string) PAYKKA_ENV);
        if ($env === 'production' || $env === 'prod') {
            return false;
        }
        if ($env === 'sandbox' || $env === 'test') {
            return true;
        }
    }
    return get_option('paykka_sandbox_flag', 'yes') === 'yes';
}

// API 地址：
// 欧洲（生产）：https://openapi.eu.paykka.com
// 香港（生产）：https://openapi.aq.paykka.com
// 沙箱（测试）：https://openapi-sandbox.paykka.com
if (!defined('PAYKKA_API_BASE_SANDBOX')) {
    define('PAYKKA_API_BASE_SANDBOX', 'https://openapi-sandbox.paykka.com');
}
if (!defined('PAYKKA_API_BASE_EU_PROD')) {
    define('PAYKKA_API_BASE_EU_PROD', 'https://openapi.eu.paykka.com');
}
if (!defined('PAYKKA_API_BASE_HK_PROD')) {
    define('PAYKKA_API_BASE_HK_PROD', 'https://openapi.aq.paykka.com');
}
// 兼容旧常量名（历史 AP 视为香港）
if (!defined('PAYKKA_API_BASE_AP_PROD')) {
    define('PAYKKA_API_BASE_AP_PROD', PAYKKA_API_BASE_HK_PROD);
}
if (!defined('PAYKKA_API_BASE_PROD')) {
    define('PAYKKA_API_BASE_PROD', 'https://openapi.eu.paykka.com');
}
if (!defined('PAYKKA_CHECKOUT_BASE_SANDBOX')) {
    define('PAYKKA_CHECKOUT_BASE_SANDBOX', 'https://checkout-fat.eu.paykka.com');
}
if (!defined('PAYKKA_CHECKOUT_BASE_PROD')) {
    define('PAYKKA_CHECKOUT_BASE_PROD', 'https://checkout.eu.paykka.com');
}

/**
 * 获取 Paykka API 地区：hk（香港）| eu（欧洲）
 *
 * @return string 'hk'|'eu'
 */
function paykka_get_api_region()
{
    $region = get_option('paykka_api_region', 'eu');
    if ($region === 'ap') {
        return 'hk';
    }
    return $region === 'hk' ? 'hk' : 'eu';
}

/**
 * 获取 Paykka 后端 API 基地址（根据地区 + 生产/测试 + 配置文件）
 * 优先级：配置文件当前环境的 api_base_url > 生产/测试默认地址。
 *
 * @return string 不含末尾斜杠的完整基地址，如 https://openapi.eu.paykka.com
 */
function paykka_get_api_base_url()
{
    $sandbox = paykka_is_sandbox();
    $config = paykka_load_config();
    $env_key = $sandbox ? 'sandbox' : 'production';
    if (!empty($config[$env_key]['api_base_url']) && is_string($config[$env_key]['api_base_url'])) {
        return rtrim($config[$env_key]['api_base_url'], '/');
    }
    if ($sandbox) {
        return PAYKKA_API_BASE_SANDBOX;
    }
    $region = paykka_get_api_region();
    return $region === 'hk' ? PAYKKA_API_BASE_HK_PROD : PAYKKA_API_BASE_EU_PROD;
}

/**
 * 获取 Paykka 收银台前端基地址（JS/CSS 与 setApiUrl/setCDNUrl 用）
 * 优先级：配置文件当前环境的 checkout_base_url > 默认生产/测试地址。
 *
 * @return string 不含末尾斜杠的完整基地址，如 https://checkout.eu.paykka.com
 */
function paykka_get_checkout_base_url()
{
    $sandbox = paykka_is_sandbox();
    $config = paykka_load_config();
    $env_key = $sandbox ? 'sandbox' : 'production';
    if (!empty($config[$env_key]['checkout_base_url']) && is_string($config[$env_key]['checkout_base_url'])) {
        return rtrim($config[$env_key]['checkout_base_url'], '/');
    }
    return $sandbox ? PAYKKA_CHECKOUT_BASE_SANDBOX : PAYKKA_CHECKOUT_BASE_PROD;
}

/**
 * 获取 Paykka 配置（根据沙箱开关返回对应环境的 key/merchant_id）
 *
 * @return array{paykka_client_key: string, paykka_private_key: string, paykka_merchant_id: string}
 */
function getPaykkaSettings()
{
    $sandbox = paykka_is_sandbox();
    $prefix = $sandbox ? 'paykka_sandbox_' : 'paykka_';
    $client_key_id = $sandbox ? 'paykka_sandbox_client_key' : 'paykka_client_key';

    return array(
        'paykka_client_key'  => get_option($client_key_id, ''),
        'paykka_private_key' => get_option($prefix . 'private_key', ''),
        'paykka_merchant_id' => get_option($prefix . 'merchant_id', ''),
    );
}

/** @var string 订单 meta：待绑定到 WC 退款单的 PayKKa 流水队列（FIFO） */
function paykka_refund_link_queue_meta_key()
{
    return '_paykka_refund_link_queue';
}

/**
 * process_refund 成功后入队，在 woocommerce_order_refunded 中按退款金额匹配并写入退款单 meta。
 *
 * @param \WC_Order $order
 * @param string    $refund_trans_id
 * @param string    $refund_order_id
 * @param float     $amount Woo 退款金额（与本次退款一致）
 */
function paykka_enqueue_refund_paykka_link($order, $refund_trans_id, $refund_order_id, $amount)
{
    if (!$order || !is_a($order, 'WC_Order')) {
        return;
    }
    $refund_trans_id = trim((string) $refund_trans_id);
    $refund_order_id = trim((string) $refund_order_id);
    if ($refund_trans_id === '' && $refund_order_id === '') {
        return;
    }
    $key = paykka_refund_link_queue_meta_key();
    $queue = $order->get_meta($key, true);
    if (!is_array($queue)) {
        $queue = array();
    }
    $queue[] = array(
        'refund_trans_id' => $refund_trans_id,
        'refund_order_id' => $refund_order_id,
        'amount'          => (float) $amount,
        'ts'              => time(),
    );
    $order->update_meta_data($key, $queue);
    $order->save();
}

/**
 * @param int $order_id
 * @param int $refund_id
 */
function paykka_attach_refund_link_on_order_refunded($order_id, $refund_id)
{
    $order = wc_get_order($order_id);
    $refund = wc_get_order($refund_id);
    if (!$order || !$refund) {
        return;
    }
    if (!is_a($refund, 'WC_Order_Refund')) {
        return;
    }
    if ($order->get_payment_method() !== 'paykka') {
        return;
    }
    $key = paykka_refund_link_queue_meta_key();
    $queue = $order->get_meta($key, true);
    if (!is_array($queue) || $queue === array()) {
        return;
    }
    $decimal_places = wc_get_price_decimals();
    $target_minor = (int) round((float) $refund->get_amount() * pow(10, $decimal_places));
    $matched_idx = -1;
    $matched = null;
    foreach ($queue as $i => $item) {
        if (!is_array($item)) {
            continue;
        }
        $item_minor = (int) round((float) (isset($item['amount']) ? $item['amount'] : 0) * pow(10, $decimal_places));
        if ($item_minor === $target_minor) {
            $matched = $item;
            $matched_idx = $i;
            break;
        }
    }
    if ($matched === null) {
        if (function_exists('paykka_is_debug') && paykka_is_debug()) {
            error_log('[Paykka] refund link queue: no amount match for refund #' . $refund_id . ' order=' . $order_id);
        }
        return;
    }
    array_splice($queue, $matched_idx, 1);
    $order->update_meta_data($key, $queue);
    $order->save();

    if (!empty($matched['refund_trans_id'])) {
        $refund->update_meta_data('_paykka_refund_trans_id', sanitize_text_field((string) $matched['refund_trans_id']));
    }
    if (!empty($matched['refund_order_id'])) {
        $refund->update_meta_data('_paykka_refund_order_id', sanitize_text_field((string) $matched['refund_order_id']));
    }
    $refund->save();
}

/**
 * 按 PayKKa refund_trans_id 删除匹配的商店退款单（用于网关明确失败时冲正账目）。
 *
 * @param \WC_Order $order
 * @param string    $refund_trans_id
 * @return int 已删除的退款单 ID，0 表示未找到
 */
function paykka_delete_wc_refund_matching_trans_id($order, $refund_trans_id)
{
    if (!$order || !is_a($order, 'WC_Order')) {
        return 0;
    }
    $refund_trans_id = trim((string) $refund_trans_id);
    if ($refund_trans_id === '') {
        return 0;
    }
    foreach ($order->get_refunds() as $refund) {
        if (!is_a($refund, 'WC_Order_Refund')) {
            continue;
        }
        $stored = trim((string) $refund->get_meta('_paykka_refund_trans_id', true));
        if ($stored !== '' && $stored === $refund_trans_id) {
            $rid = $refund->get_id();
            if (function_exists('wc_delete_refund')) {
                wc_delete_refund($rid);
            } else {
                $refund->delete(true);
            }
            return $rid;
        }
    }
    return 0;
}

/**
 * 按 PayKKa refund_trans_id 查找匹配的 Woo 退款单 ID。
 *
 * @param \WC_Order $order
 * @param string    $refund_trans_id
 * @return int 退款单 ID，0 表示未找到
 */
function paykka_find_wc_refund_id_by_trans_id($order, $refund_trans_id)
{
    if (!$order || !is_a($order, 'WC_Order')) {
        return 0;
    }
    $refund_trans_id = trim((string) $refund_trans_id);
    if ($refund_trans_id === '') {
        return 0;
    }
    foreach ($order->get_refunds() as $refund) {
        if (!is_a($refund, 'WC_Order_Refund')) {
            continue;
        }
        $stored = trim((string) $refund->get_meta('_paykka_refund_trans_id', true));
        if ($stored !== '' && $stored === $refund_trans_id) {
            return (int) $refund->get_id();
        }
    }
    return 0;
}