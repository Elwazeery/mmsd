<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-load.php');
require_once('api-utils.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/admin-panel/inc/activity-log.php');

$user = verify_api_token();
$method = $_SERVER['REQUEST_METHOD'];
$ip = $_SERVER['REMOTE_ADDR'];

if ($method === 'POST') {
    $order_id = intval($_POST['order_id'] ?? 0);
    $amount = floatval($_POST['amount'] ?? 0);
    $payment_method = sanitize_text_field($_POST['method'] ?? '');
    $status = sanitize_text_field($_POST['status'] ?? 'Pending');

    // Validate inputs
    $order = get_post($order_id);
    if (!$order || $order->post_type !== 'order' || get_post_meta($order_id, '_order_customer', true) != $user->ID) {
        wp_send_json_error(['message' => 'Order not found or not authorized', 'code' => 'invalid_order'], 404);
    }
    $order_total = floatval(get_post_meta($order_id, '_order_total', true));
    if (abs($amount - $order_total) > 0.01) {
        wp_send_json_error(['message' => 'Amount does not match order total', 'code' => 'invalid_amount'], 400);
    }
    $allowed_methods = get_option('mmsb_settings_payment_methods', ['cod' => 'Cash on Delivery', 'vodafone_cash' => 'Vodafone Cash', 'instapay' => 'Instapay']);
    if (!array_key_exists($payment_method, $allowed_methods)) {
        wp_send_json_error(['message' => 'Invalid payment method', 'code' => 'invalid_method'], 400);
    }
    $allowed_statuses = ['Pending', 'Completed', 'Failed'];
    if (!in_array($status, $allowed_statuses)) {
        wp_send_json_error(['message' => 'Invalid payment status', 'code' => 'invalid_status'], 400);
    }

    // Create payment
    $post_id = wp_insert_post([
        'post_title' => 'PAYMENT-' . time(),
        'post_type' => 'payment',
        'post_status' => 'publish'
    ], true);
    if (is_wp_error($post_id)) {
        wp_send_json_error(['message' => 'Failed to create payment: ' . $post_id->get_error_message(), 'code' => 'server_error'], 400);
    }

    // Update payment meta
    $meta_data = [
        '_payment_amount' => $amount,
        '_payment_method' => $payment_method,
        '_payment_status' => $status,
        '_payment_order_id' => $order_id
    ];
    foreach ($meta_data as $key => $value) {
        update_post_meta($post_id, $key, $value);
    }

    log_activity($user->ID, 'create_payment', "Created payment ID: $post_id for order ID: $order_id", $ip);
    wp_send_json_success(['payment_id' => $post_id]);
} elseif ($method === 'GET') {
    if (isset($_GET['payment_id'])) {
        $payment_id = intval($_GET['payment_id']);
        $payment = get_post($payment_id);
        if (!$payment || $payment->post_type !== 'payment') {
            wp_send_json_error(['message' => 'Payment not found', 'code' => 'not_found'], 404);
        }
        $order_id = intval(get_post_meta($payment_id, '_payment_order_id', true));
        if (get_post_meta($order_id, '_order_customer', true) != $user->ID) {
            wp_send_json_error(['message' => 'Payment not authorized', 'code' => 'not_found'], 404);
        }
        $meta = get_post_meta($payment_id);
        wp_send_json_success([
            'id' => $payment->ID,
            'title' => $payment->post_title,
            'amount' => floatval($meta['_payment_amount'][0] ?? 0),
            'method' => $meta['_payment_method'][0] ?? '',
            'status' => $meta['_payment_status'][0] ?? '',
            'order_id' => $order_id,
            'date' => get_the_date('c', $payment)
        ]);
    } else {
        $page = max(1, intval($_GET['page'] ?? 1));
        $search = sanitize_text_field($_GET['search'] ?? '');
        $cache_key = 'user_orders_' . $user->ID . '_page_' . $page . '_search_' . md5($search);
        $order_ids = get_transient($cache_key);
        if (false === $order_ids) {
            $order_ids = get_posts([
                'post_type' => 'order',
                'meta_key' => '_order_customer',
                'meta_value' => $user->ID,
                'fields' => 'ids',
                'posts_per_page' => -1
            ]);
            set_transient($cache_key, $order_ids, HOUR_IN_SECONDS);
        }
        $args = [
            'post_type' => 'payment',
            'posts_per_page' => 10,
            'paged' => $page,
            's' => $search,
            'meta_query' => [[
                'key' => '_payment_order_id',
                'value' => $order_ids,
                'compare' => 'IN'
            ]]
        ];
        $query = new WP_Query($args);
        $payments = [];
        foreach ($query->posts as $post) {
            $meta = get_post_meta($post->ID);
            $payments[] = [
                'id' => $post->ID,
                'title' => $post->post_title,
                'amount' => floatval($meta['_payment_amount'][0] ?? 0),
                'method' => $meta['_payment_method'][0] ?? '',
                'status' => $meta['_payment_status'][0] ?? '',
                'order_id' => intval($meta['_payment_order_id'][0] ?? 0),
                'date' => get_the_date('c', $post)
            ];
        }
        wp_send_json_success([
            'payments' => $payments,
            'total_pages' => $query->max_num_pages,
            'current_page' => $page,
            'total_items' => $query->found_posts
        ]);
    }
} else {
    wp_send_json_error(['message' => 'Method not allowed', 'code' => 'method_not_allowed'], 405);
}
