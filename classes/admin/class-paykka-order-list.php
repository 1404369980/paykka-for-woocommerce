<?php
/**
 * 订单列表 / 订单详情展示 PayKKa 网关订单号（_paykka_order_id）。
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 注册订单列表列（传统 CPT + HPOS）。
 */
function paykka_register_order_list_hooks()
{
    // 传统订单 CPT
    add_filter('manage_edit-shop_order_columns', 'paykka_add_order_list_column', 20);
    add_action('manage_shop_order_posts_custom_column', 'paykka_render_order_list_column_cpt', 20, 2);

    // HPOS 订单列表
    add_filter('woocommerce_shop_order_list_table_columns', 'paykka_add_order_list_column', 20);
    add_action('woocommerce_shop_order_list_table_custom_column', 'paykka_render_order_list_column_hpos', 20, 2);

    // 订单详情页
    add_action('woocommerce_admin_order_data_after_billing_address', 'paykka_render_order_admin_gateway_id', 20);
}

/**
 * @param array $columns
 * @return array
 */
function paykka_add_order_list_column($columns)
{
    $new = array();
    foreach ($columns as $key => $label) {
        $new[$key] = $label;
        // 插在订单状态后；若无 status 则追加在末尾前一列
        if ($key === 'order_status') {
            $new['paykka_order_id'] = __('PayKKa Order ID', 'paykka-for-woocommerce');
        }
    }
    if (!isset($new['paykka_order_id'])) {
        $new['paykka_order_id'] = __('PayKKa Order ID', 'paykka-for-woocommerce');
    }
    return $new;
}

/**
 * @param \WC_Order|false $order
 * @return string
 */
function paykka_get_gateway_order_id_display($order)
{
    if (!$order || !is_a($order, 'WC_Order')) {
        return '—';
    }
    $gw = trim((string) $order->get_meta('_paykka_order_id', true));
    return $gw !== '' ? $gw : '—';
}

/**
 * CPT 列内容。
 *
 * @param string $column
 * @param int    $post_id
 */
function paykka_render_order_list_column_cpt($column, $post_id)
{
    if ($column !== 'paykka_order_id') {
        return;
    }
    $order = wc_get_order($post_id);
    echo esc_html(paykka_get_gateway_order_id_display($order));
}

/**
 * HPOS 列内容。
 *
 * @param string    $column
 * @param \WC_Order $order
 */
function paykka_render_order_list_column_hpos($column, $order)
{
    if ($column !== 'paykka_order_id') {
        return;
    }
    echo esc_html(paykka_get_gateway_order_id_display($order));
}

/**
 * 订单编辑页账单地址下方展示。
 *
 * @param \WC_Order $order
 */
function paykka_render_order_admin_gateway_id($order)
{
    if (!$order || !is_a($order, 'WC_Order')) {
        return;
    }
    $gw = trim((string) $order->get_meta('_paykka_order_id', true));
    if ($gw === '') {
        return;
    }
    echo '<p class="form-field form-field-wide paykka-admin-order-id">';
    echo '<strong>' . esc_html__('PayKKa Order ID', 'paykka-for-woocommerce') . ':</strong> ';
    echo '<code style="user-select:all;">' . esc_html($gw) . '</code>';
    echo '</p>';
}
