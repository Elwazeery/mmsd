<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-load.php');
require_once('api-utils.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/admin-panel/inc/activity-log.php');

$user = verify_api_token();
$method = $_SERVER['REQUEST_METHOD'];
$ip = $_SERVER['REMOTE_ADDR'];

if ($method === 'POST') {
    $items = isset($_POST['items']) ? json_decode(stripslashes($_POST['items']), true) : [];
    $total = floatval($_POST['total'] ?? 0);
    $status = sanitize_text_field($_POST['status'] ?? 'Pending');
    $custom_fields = isset($_POST['custom_fields']) ? json_decode(stripslashes($_POST['custom_fields']), true) : [];
    $payment_method = sanitize_text_field($_POST['payment_method'] ?? '');

    // Validate inputs
    if (empty($items)) {
        wp_send_json_error(['message' => 'Order items are required', 'code' => 'invalid_items'], 400);
    }
    $allowed_statuses = array_keys(get_option('mmsb_settings_order_statuses', ['Pending' => 'Pending', 'Processing' => 'Processing', 'Shipped' => 'Shipped', 'Delivered' => 'Delivered', 'Cancelled' => 'Cancelled']));
    if (!in_array($status, $allowed_statuses)) {
        wp_send_json_error(['message' => 'Invalid order status', 'code' => 'invalid_status'], 400);
    }
    $allowed_payment_methods = get_option('mmsb_settings_payment_methods', ['cod' => 'Cash on Delivery', 'vodafone_cash' => 'Vodafone Cash', 'instapay' => 'Instapay']);
    if (!array_key_exists($payment_method, $allowed_payment_methods)) {
        wp_send_json_error(['message' => 'Invalid payment method', 'code' => 'invalid_method'], 400);
    }

    // Validate items and calculate total
    $calculated_total = 0;
    $delivery_fee = floatval(get_option('mmsb_settings_delivery_fee', 20));
    $tax_rate = floatval(get_option('mmsb_settings_tax_rate', 0));
    foreach ($items as &$item) {
        $item['medicine_id'] = intval($item['medicine_id'] ?? 0);
        $item['quantity'] = intval($item['quantity'] ?? 0);
        if ($item['medicine_id'] <= 0 || $item['quantity'] <= 0) {
            wp_send_json_error(['message' => 'Invalid medicine ID or quantity', 'code' => 'invalid_quantity'], 400);
        }
        $medicine = get_post($item['medicine_id']);
        if (!$medicine || $medicine->post_type !== 'medicine') {
            wp_send_json_error(['message' => 'Medicine not found', 'code' => 'invalid_medicine'], 404);
        }
        $stock = intval(get_post_meta($item['medicine_id'], '_medicine_stock', true));
        if ($stock < $item['quantity']) {
            wp_send_json_error(['message' => 'Insufficient stock for medicine ID: ' . $item['medicine_id'], 'code' => 'invalid_quantity'], 400);
        }
        $price = floatval(get_post_meta($item['medicine_id'], '_medicine_price', true));
        $item['price'] = $price;
        $calculated_total += $price * $item['quantity'];
    }
    $tax = $calculated_total * ($tax_rate / 100);
    $calculated_total += $tax + $delivery_fee;
    if (abs($calculated_total - $total) > 0.01) {
        wp_send_json_error(['message' => 'Total does not match calculated total', 'code' => 'invalid_total'], 400);
    }

    // Create order
    $post_id = wp_insert_post([
        'post_title' => 'ORDER-' . time(),
        'post_type' => 'order',
        'post_status' => 'publish'
    ], true);
    if (is_wp_error($post_id)) {
        wp_send_json_error(['message' => 'Failed to create order: ' . $post_id->get_error_message(), 'code' => 'server_error'], 400);
    }

    // Update order meta
    $meta_data = [
        '_order_customer' => $user->ID,
        '_order_total' => $total,
        '_order_status' => $status,
        '_order_items' => maybe_serialize($items),
        '_delivery_fee' => $delivery_fee,
        '_additional_fees' => 0,
        '_order_payment_method' => $payment_method
    ];
    foreach ($custom_fields as $key => $value) {
        $meta_data[$key] = is_array($value) ? maybe_serialize($value) : sanitize_text_field($value);
    }
    foreach ($meta_data as $key => $value) {
        update_post_meta($post_id, $key, $value);
    }
    $history = [[
        'date' => current_time('mysql'),
        'action' => 'create_order',
        'details' => 'Order created by user ID: ' . $user->ID,
        'user_id' => $user->ID
    ]];
    update_post_meta($post_id, '_order_history', json_encode($history));

    // Create payment
    $payment_id = wp_insert_post([
        'post_title' => 'PAYMENT-' . time(),
        'post_type' => 'payment',
        'post_status' => 'publish'
    ], true);
    if (is_wp_error($payment_id)) {
        wp_delete_post($post_id, true);
        wp_send_json_error(['message' => 'Failed to create payment: ' . $payment_id->get_error_message(), 'code' => 'server_error'], 400);
    }
    $payment_meta = [
        '_payment_amount' => $total,
        '_payment_method' => $payment_method,
        '_payment_status' => 'Pending',
        '_payment_order_id' => $post_id
    ];
    foreach ($payment_meta as $key => $value) {
        update_post_meta($payment_id, $key, $value);
    }

    // Send confirmation email
    $business_name = get_option('mmsb_settings_business_name', 'Pharmacy');
    $business_email = get_option('mmsb_settings_business_email', get_option('admin_email'));
    $subject = sprintf(__('%s - Order Confirmation #%s', 'mmsb'), $business_name, $post_id);
    $message = sprintf(
        "<p>Hello %s,</p><p>Your order #%s has been received. Total: %s %s</p><p>Payment Method: %s</p>",
        esc_html($user->user_login),
        $post_id,
        number_format($total, 2),
        get_option('mmsb_settings_currency', 'SAR'),
        esc_html($allowed_payment_methods[$payment_method])
    );
    $headers = ['Content-Type: text/html; charset=UTF-8', 'From: ' . $business_name . ' <' . $business_email . '>'];
    wp_mail($user->user_email, $subject, $message, $headers);

    log_activity($user->ID, 'create_order', "Created order ID: $post_id with payment ID: $payment_id", $ip);
    wp_send_json_success(['order_id' => $post_id, 'payment_id' => $payment_id]);
} elseif ($method === 'GET') {
    if (isset($_GET['order_id'])) {
        $order_id = intval($_GET['order_id']);
        $order = get_post($order_id);
        if (!$order || $order->post_type !== 'order' || get_post_meta($order_id, '_order_customer', true) != $user->ID) {
            wp_send_json_error(['message' => 'Order not found or not authorized', 'code' => 'not_found'], 404);
        }
        $meta = get_post_meta($order_id);
        $items = maybe_unserialize($meta['_order_items'][0] ?? []);
        $customer_id = intval($meta['_order_customer'][0] ?? 0);
        $customer = get_user_by('ID', $customer_id);
        $payment = get_posts([
            'post_type' => 'payment',
            'meta_query' => [['key' => '_payment_order_id', 'value' => $order_id]]
        ]);
        $custom_fields = [];
        foreach ($meta as $key => $value) {
            if (strpos($key, '_') !== 0) {
                $custom_fields[$key] = maybe_unserialize($value[0]);
            }
        }
        wp_send_json_success([
            'id' => $order->ID,
            'title' => $order->post_title,
            'customer' => $customer ? [
                'id' => $customer->ID,
                'username' => $customer->user_login,
                'address' => get_user_meta($customer->ID, '_user_address', true)
            ] : null,
            'total' => floatval($meta['_order_total'][0] ?? 0),
            'status' => $meta['_order_status'][0] ?? '',
            'items' => $items,
            'payment_method' => $meta['_order_payment_method'][0] ?? '',
            'payment' => $payment ? [
                'id' => $payment[0]->ID,
                'amount' => floatval(get_post_meta($payment[0]->ID, '_payment_amount', true)),
                'method' => get_post_meta($payment[0]->ID, '_payment_method', true),
                'status' => get_post_meta($payment[0]->ID, '_payment_status', true)
            ] : null,
            'custom_fields' => $custom_fields,
            'date' => get_the_date('c', $order)
        ]);
    } else {
        $page = max(1, intval($_GET['page'] ?? 1));
        $search = sanitize_text_field($_GET['search'] ?? '');
        $args = [
            'post_type' => 'order',
            'posts_per_page' => 10,
            'paged' => $page,
            's' => $search,
            'meta_query' => [['key' => '_order_customer', 'value' => $user->ID]]
        ];
        $query = new WP_Query($args);
        $orders = [];
        foreach ($query->posts as $post) {
            $meta = get_post_meta($post->ID);
            $payment = get_posts([
                'post_type' => 'payment',
                'meta_query' => [['key' => '_payment_order_id', 'value' => $post->ID]]
            ]);
            $orders[] = [
                'id' => $post->ID,
                'title' => $post->post_title,
                'total' => floatval($meta['_order_total'][0] ?? 0),
                'status' => $meta['_order_status'][0] ?? '',
                'payment_method' => $meta['_order_payment_method'][0] ?? '',
                'payment' => $payment ? [
                    'id' => $payment[0]->ID,
                    'amount' => floatval(get_post_meta($payment[0]->ID, '_payment_amount', true)),
                    'method' => get_post_meta($payment[0]->ID, '_payment_method', true),
                    'status' => get_post_meta($payment[0]->ID, '_payment_status', true)
                ] : null,
                'date' => get_the_date('c', $post)
            ];
        }
        wp_send_json_success([
            'orders' => $orders,
            'total_pages' => $query->max_num_pages,
            'current_page' => $page,
            'total_items' => $query->found_posts
        ]);
    }
} else {
    wp_send_json_error(['message' => 'Method not allowed', 'code' => 'method_not_allowed'], 405);
}
