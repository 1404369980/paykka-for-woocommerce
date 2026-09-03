<?php
/**
 * 沙箱模式下的 Payments（内嵌卡）自检。
 *
 * Payments 面板打不开时，顾客侧只看到「加载失败」，定位要翻 Network 和 error_log。
 * 这里在沙箱环境把可机检的原因（配置、密钥、SDK/API 可达性、HTTPS、整页缓存）直接
 * 列到结账页上。生产环境一律返回空结果，不向顾客暴露配置细节。
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('PAYKKA_DIAG_PROBE_TTL')) {
    // 结账页每次渲染都打外网不可接受，探测结果短时缓存
    define('PAYKKA_DIAG_PROBE_TTL', 300);
}

/**
 * 是否输出诊断信息：仅沙箱。
 *
 * @return bool
 */
function paykka_diag_enabled()
{
    return function_exists('paykka_is_sandbox') && paykka_is_sandbox();
}

/**
 * 沙箱判定来自哪一层配置（config 文件 > PAYKKA_ENV > 后台勾选）。
 *
 * @return string
 */
function paykka_diag_env_source()
{
    $config = function_exists('paykka_load_config') ? paykka_load_config() : array();
    if (!empty($config['env'])) {
        $env = strtolower((string) $config['env']);
        if (in_array($env, array('production', 'prod', 'sandbox', 'test'), true)) {
            return 'paykka-config.php';
        }
    }
    if (defined('PAYKKA_ENV')) {
        $env = strtolower((string) PAYKKA_ENV);
        if (in_array($env, array('production', 'prod', 'sandbox', 'test'), true)) {
            return 'PAYKKA_ENV';
        }
    }
    return 'paykka_sandbox_flag';
}

/**
 * 实际发起探测并缓存结果。放在 shutdown 上执行，不占用页面渲染时间。
 *
 * @param string $url
 * @return array{level: string, detail: string}
 */
function paykka_diag_run_probe($url)
{
    $response = wp_remote_get($url, array(
        'timeout'     => 5,
        'redirection' => 3,
        'sslverify'   => true,
    ));

    if (is_wp_error($response)) {
        $result = array(
            'level'  => 'fail',
            /* translators: %s: WP_Error message from the HTTP request */
            'detail' => sprintf(__('Request failed: %s', 'paykka-for-woocommerce'), $response->get_error_message()),
        );
    } else {
        $code   = (int) wp_remote_retrieve_response_code($response);
        $result = array(
            'level'  => ($code >= 200 && $code < 400) ? 'ok' : 'fail',
            /* translators: %d: HTTP status code */
            'detail' => sprintf(__('HTTP %d', 'paykka-for-woocommerce'), $code),
        );
    }

    set_transient('paykka_diag_probe_' . md5($url), $result, PAYKKA_DIAG_PROBE_TTL);
    return $result;
}

/**
 * 读取探测结果。结账页不能为了自检去等外网，因此没有缓存时先返回
 * pending，把真正的请求推到 shutdown，下次刷新即可看到结论。
 *
 * @param string $url
 * @return array{level: string, detail: string}
 */
function paykka_diag_probe_url($url)
{
    static $queued = array();

    $url = trim((string) $url);
    if ($url === '' || !wp_http_validate_url($url)) {
        return array('level' => 'fail', 'detail' => __('URL is empty or invalid', 'paykka-for-woocommerce'));
    }

    $cached = get_transient('paykka_diag_probe_' . md5($url));
    if (is_array($cached) && isset($cached['level'], $cached['detail'])) {
        return $cached;
    }

    if (!isset($queued[$url])) {
        $queued[$url] = true;
        add_action('shutdown', function () use ($url) {
            paykka_diag_run_probe($url);
        });
    }

    return array(
        'level'  => 'pending',
        'detail' => __('Checking in the background — refresh to see the result', 'paykka-for-woocommerce'),
    );
}

/**
 * 检测可能缓存结账页的插件。整页缓存会让内嵌的 nonce 过期，
 * 表现为 create session 返回 403「Security check failed」。
 *
 * @return string[] 命中的插件名
 */
function paykka_diag_detect_page_cache()
{
    $hits = array();

    if (defined('WP_ROCKET_VERSION')) {
        $hits[] = 'WP Rocket';
    }
    if (defined('W3TC') || defined('W3TC_VERSION')) {
        $hits[] = 'W3 Total Cache';
    }
    if (defined('WPCACHEHOME')) {
        $hits[] = 'WP Super Cache';
    }
    if (defined('LSCWP_V') || defined('LSCWP_CURRENT_VERSION')) {
        $hits[] = 'LiteSpeed Cache';
    }
    if (defined('WPFC_MAIN_PATH') || class_exists('WpFastestCache')) {
        $hits[] = 'WP Fastest Cache';
    }
    if (defined('SiteGround_Optimizer\VERSION')) {
        $hits[] = 'SiteGround Optimizer';
    }
    if (defined('WPO_VERSION')) {
        $hits[] = 'WP-Optimize';
    }
    if (empty($hits) && defined('WP_CACHE') && WP_CACHE) {
        $hits[] = __('unknown (WP_CACHE is on)', 'paykka-for-woocommerce');
    }

    return $hits;
}

/**
 * 生成一条检查结果。
 *
 * @param string $id
 * @param string $level ok|warn|fail
 * @param string $label
 * @param string $detail
 * @return array
 */
function paykka_diag_item($id, $level, $label, $detail)
{
    return array(
        'id'     => $id,
        'level'  => $level,
        'label'  => $label,
        'detail' => $detail,
    );
}

/**
 * 服务端可机检的 Payments 前置条件。
 *
 * @param bool $probe_network 是否探测 SDK / API 地址
 * @return array{sandbox: bool, checks: array, meta: array}
 */
function paykka_diag_card_report($probe_network = true)
{
    if (!paykka_diag_enabled()) {
        return array('sandbox' => false, 'checks' => array(), 'meta' => array());
    }

    $checks   = array();
    $settings = function_exists('getPaykkaSettings') ? getPaykkaSettings() : array();

    $checkout_base = function_exists('paykka_get_checkout_base_url') ? paykka_get_checkout_base_url() : '';
    $api_base      = function_exists('paykka_get_api_base_url') ? paykka_get_api_base_url() : '';
    $sdk_url       = $checkout_base !== '' ? rtrim($checkout_base, '/') . '/cp/card-checkout-ui.js' : '';

    // 环境来源：沙箱开关的兜底默认值是 yes，线上站最容易在这里错配
    $checks[] = paykka_diag_item(
        'environment',
        'ok',
        __('Environment', 'paykka-for-woocommerce'),
        /* translators: %s: the config layer that decided the environment */
        sprintf(__('Sandbox, decided by %s', 'paykka-for-woocommerce'), paykka_diag_env_source())
    );

    // 网关开关（Blocks 的 is_active 也读这里）
    $card_settings = get_option('woocommerce_paykka-card_settings', array());
    $card_enabled  = is_array($card_settings) && isset($card_settings['enabled']) && $card_settings['enabled'] === 'yes';
    $checks[]      = paykka_diag_item(
        'gateway_enabled',
        $card_enabled ? 'ok' : 'fail',
        __('Payments gateway', 'paykka-for-woocommerce'),
        $card_enabled
            ? __('Enabled', 'paykka-for-woocommerce')
            : __('Disabled — enable it under WooCommerce → Settings → Payments → Paykka Payments', 'paykka-for-woocommerce')
    );

    // 三个密钥缺一不可，is_available() 会直接隐藏支付方式
    $key_labels = array(
        'paykka_client_key'  => __('Sandbox client key', 'paykka-for-woocommerce'),
        'paykka_merchant_id' => __('Sandbox merchant ID', 'paykka-for-woocommerce'),
        'paykka_private_key' => __('Sandbox private key', 'paykka-for-woocommerce'),
    );
    foreach ($key_labels as $key => $label) {
        $value = isset($settings[$key]) ? trim((string) $settings[$key]) : '';
        $checks[] = paykka_diag_item(
            $key,
            $value !== '' ? 'ok' : 'fail',
            $label,
            $value !== ''
                ? __('Configured', 'paykka-for-woocommerce')
                : __('Missing — fill it in under the Paykka connection settings', 'paykka-for-woocommerce')
        );
    }

    // 私钥非空但 OpenSSL 解析不了时，签名一定失败（Create Session 返回签名错误）
    $private_key = isset($settings['paykka_private_key']) ? trim((string) $settings['paykka_private_key']) : '';
    if ($private_key !== '') {
        $pem = "-----BEGIN PRIVATE KEY-----\n"
            . chunk_split(str_replace(array("\r", "\n", " "), '', $private_key), 64, "\n")
            . "-----END PRIVATE KEY-----\n";
        if (strpos($private_key, '-----BEGIN') === 0) {
            $pem = $private_key;
        }
        $parsed = function_exists('openssl_pkey_get_private') ? openssl_pkey_get_private($pem) : false;
        if ($parsed === false) {
            // 清空 OpenSSL 错误队列，避免污染后续真实签名的日志
            if (function_exists('openssl_error_string')) {
                while (openssl_error_string()) {
                    continue;
                }
            }
        }
        $checks[] = paykka_diag_item(
            'private_key_format',
            $parsed !== false ? 'ok' : 'fail',
            __('Private key format', 'paykka-for-woocommerce'),
            $parsed !== false
                ? __('OpenSSL can load it', 'paykka-for-woocommerce')
                : __('OpenSSL cannot load it — re-paste the PKCS#8 private key', 'paykka-for-woocommerce')
        );
    }

    // 卡组件在非 HTTPS 页面上通常拒绝挂载
    $checks[] = paykka_diag_item(
        'https',
        is_ssl() ? 'ok' : 'warn',
        __('HTTPS', 'paykka-for-woocommerce'),
        is_ssl()
            ? __('Checkout is served over HTTPS', 'paykka-for-woocommerce')
            : __('Checkout is not HTTPS — the card component may refuse to load', 'paykka-for-woocommerce')
    );

    // 整页缓存会把 nonce 冻在 HTML 里
    $cache_hits = paykka_diag_detect_page_cache();
    $checks[]   = paykka_diag_item(
        'page_cache',
        empty($cache_hits) ? 'ok' : 'warn',
        __('Page cache', 'paykka-for-woocommerce'),
        empty($cache_hits)
            ? __('No page cache plugin detected', 'paykka-for-woocommerce')
            : sprintf(
                /* translators: %s: comma separated plugin names */
                __('Detected %s — exclude the checkout page, a cached nonce makes session creation return 403', 'paykka-for-woocommerce'),
                implode(', ', $cache_hits)
            )
    );

    if ($probe_network) {
        $sdk_probe = paykka_diag_probe_url($sdk_url);
        $checks[]  = paykka_diag_item(
            'sdk_reachable',
            $sdk_probe['level'],
            __('Card SDK URL', 'paykka-for-woocommerce'),
            $sdk_probe['detail'] . ' — ' . $sdk_url
        );

        // 服务器能连上 API 不代表顾客浏览器能连上 SDK，反之亦然，两个都留着
        $api_probe = paykka_diag_probe_url($api_base);
        $checks[]  = paykka_diag_item(
            'api_reachable',
            $api_probe['level'] === 'fail' ? 'warn' : $api_probe['level'],
            __('API base URL', 'paykka-for-woocommerce'),
            $api_probe['detail'] . ' — ' . $api_base
        );
    }

    return array(
        'sandbox' => true,
        'checks'  => $checks,
        'meta'    => array(
            'sdkUrl'       => $sdk_url,
            'apiBase'      => $api_base,
            'checkoutBase' => $checkout_base,
            'envSource'    => paykka_diag_env_source(),
        ),
    );
}
