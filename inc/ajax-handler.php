<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-load.php');
require_once('auth.php');
require_once('config.php');
require_once('activity-log.php');
require_login();
header('Content-Type: application/json');

$action = sanitize_text_field($_POST['action'] ?? '');
$nonce = $_POST['nonce'] ?? '';
if (!wp_verify_nonce($nonce, 'admin_panel_nonce') && !wp_verify_nonce($nonce, 'settings_nonce') && !wp_verify_nonce($nonce, 'fake_data_nonce')) {
    wp_send_json_error(['message' => 'Nonce verification failed'], 403);
}

$current_user = wp_get_current_user();

switch ($action) {
    // Medicine Actions
    case 'add_medicine':
    case 'edit_medicine':
        $post_id = $action === 'add_medicine' ? wp_insert_post([
            'post_title' => sanitize_text_field($_POST['medicine_name']),
            'post_type' => 'medicine',
            'post_status' => 'publish',
        ]) : intval($_POST['medicine_id']);
        if ($post_id) {
            wp_update_post(['ID' => $post_id, 'post_title' => sanitize_text_field($_POST['medicine_name'])]);
            $meta_data = [
                '_medicine_price' => floatval($_POST['medicine_price']),
                '_medicine_stock' => intval($_POST['medicine_stock']),
                '_medicine_sku' => sanitize_text_field($_POST['medicine_sku']),
                '_medicine_description' => sanitize_textarea_field($_POST['medicine_description']),
            ];
            if (!empty($_POST['custom_fields'])) {
                $custom_fields = json_decode($_POST['custom_fields'], true);
                foreach ($custom_fields as $key => $value) {
                    $meta_data[sanitize_key($key)] = sanitize_text_field($value);
                }
            }
            update_post_meta_data($post_id, $meta_data);
            if (!empty($_POST['medicine_category'])) {
                wp_set_post_terms($post_id, array_map('intval', $_POST['medicine_category']), 'medicine_category');
            }
            if (!empty($_FILES['cropped_image'])) {
                $upload = wp_upload_bits($_FILES['cropped_image']['name'], null, file_get_contents($_FILES['cropped_image']['tmp_name']));
                if (!$upload['error']) {
                    $attachment_id = wp_insert_attachment([
                        'guid' => $upload['url'],
                        'post_mime_type' => $_FILES['cropped_image']['type'],
                        'post_title' => 'Featured Image',
                        'post_content' => '',
                        'post_status' => 'inherit',
                    ], $upload['file'], $post_id);
                    require_once(ABSPATH . 'wp-admin/includes/image.php');
                    $attach_data = wp_generate_attachment_metadata($attachment_id, $upload['file']);
                    wp_update_attachment_metadata($attachment_id, $attach_data);
                    update_post_meta($post_id, '_featured_image', $upload['url']);
                    set_post_thumbnail($post_id, $attachment_id);
                }
            }
            if (!empty($_FILES['gallery_images'])) {
                $gallery = get_post_meta($post_id, '_gallery_images', true) ?: [];
                foreach ($_FILES['gallery_images']['tmp_name'] as $index => $tmp_name) {
                    if ($_FILES['gallery_images']['error'][$index] === UPLOAD_ERR_OK) {
                        $upload = wp_upload_bits($_FILES['gallery_images']['name'][$index], null, file_get_contents($tmp_name));
                        if (!$upload['error']) {
                            $attachment_id = wp_insert_attachment([
                                'guid' => $upload['url'],
                                'post_mime_type' => $_FILES['gallery_images']['type'][$index],
                                'post_title' => 'Gallery Image',
                                'post_content' => '',
                                'post_status' => 'inherit',
                            ], $upload['file'], $post_id);
                            $attach_data = wp_generate_attachment_metadata($attachment_id, $upload['file']);
                            wp_update_attachment_metadata($attachment_id, $attach_data);
                            $gallery[] = $upload['url'];
                        }
                    }
                }
                update_post_meta($post_id, '_gallery_images', $gallery);
            }
            log_activity($current_user->ID, $action, $action === 'add_medicine' ? "Added medicine ID: $post_id" : "Edited medicine ID: $post_id", $_SERVER['REMOTE_ADDR']);
            wp_send_json_success(['message' => $action === 'add_medicine' ? 'Medicine added' : 'Medicine updated']);
        }
        wp_send_json_error(['message' => 'Failed to process medicine'], 400);
        break;

    case 'delete_medicine':
        $medicine_id = intval($_POST['medicine_id']);
        if (delete_medicine($medicine_id)) {
            log_activity($current_user->ID, 'delete_medicine', "Deleted medicine ID: $medicine_id", $_SERVER['REMOTE_ADDR']);
            wp_send_json_success(['message' => 'Medicine deleted']);
        }
        wp_send_json_error(['message' => 'Failed to delete medicine'], 400);
        break;

    case 'delete_gallery_image':
        $medicine_id = intval($_POST['medicine_id']);
        $image_url = sanitize_text_field($_POST['image_url']);
        $gallery = get_post_meta($medicine_id, '_gallery_images', true) ?: [];
        if (($key = array_search($image_url, $gallery)) !== false) {
            unset($gallery[$key]);
            update_post_meta($medicine_id, '_gallery_images', array_values($gallery));
            $attachment_id = attachment_url_to_postid($image_url);
            if ($attachment_id) {
                wp_delete_attachment($attachment_id, true);
            }
            log_activity($current_user->ID, 'delete_gallery_image', "Deleted gallery image from medicine ID: $medicine_id", $_SERVER['REMOTE_ADDR']);
            wp_send_json_success(['message' => 'Image deleted']);
        }
        wp_send_json_error(['message' => 'Image not found'], 404);
        break;

    case 'search_medicines':
        $page = intval($_POST['page'] ?? 1);
        $per_page = 10;
        wp_send_json_success(get_paginated_posts_data('medicine', sanitize_text_field($_POST['search']), $page, $per_page));
        break;

    // Order Actions
    case 'add_order':
        check_ajax_referer('admin_panel_nonce', 'nonce');
        $order_number = sanitize_text_field($_POST['order_number']);
        $customer_id = intval($_POST['customer_id']);
        $order_status = sanitize_text_field($_POST['order_status']);
        $payment_method = sanitize_text_field($_POST['payment_method']);
        $delivery_fee = floatval($_POST['delivery_fee']);
        $additional_fees = floatval($_POST['additional_fees']);
        $order_total = floatval($_POST['order_total']);
        $items = json_decode(stripslashes($_POST['items']), true);

        $settings = [
            'order_statuses' => get_option('mmsb_settings_order_statuses', ['pending' => 'Pending', 'processing' => 'Processing', 'shipped' => 'Shipped', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled']),
            'payment_methods' => get_option('mmsb_settings_payment_methods', ['cod' => 'Cash on Delivery', 'vodafone_cash' => 'Vodafone Cash', 'instapay' => 'Instapay']),
            'tax_rate' => floatval(get_option('mmsb_settings_tax_rate', 0))
        ];

        // Validate inputs
        if (empty($items)) {
            wp_send_json_error(['message' => 'Order items are required'], 400);
        }
        if (!array_key_exists($order_status, $settings['order_statuses'])) {
            wp_send_json_error(['message' => 'Invalid order status'], 400);
        }
        if (!array_key_exists($payment_method, $settings['payment_methods'])) {
            wp_send_json_error(['message' => 'Invalid payment method'], 400);
        }
        $calculated_total = 0;
        foreach ($items as $item) {
            $medicine_id = intval($item['medicine_id']);
            $quantity = intval($item['quantity']);
            $medicine = get_post($medicine_id);
            if (!$medicine || $medicine->post_type !== 'medicine') {
                wp_send_json_error(['message' => 'Invalid medicine ID: ' . $medicine_id], 400);
            }
            $stock = intval(get_post_meta($medicine_id, '_medicine_stock', true));
            if ($stock < $quantity) {
                wp_send_json_error(['message' => 'Insufficient stock for medicine ID: ' . $medicine_id], 400);
            }
            $price = floatval(get_post_meta($medicine_id, '_medicine_price', true));
            $calculated_total += $price * $quantity;
        }
        $tax = $calculated_total * ($settings['tax_rate'] / 100);
        $calculated_total += $tax + $delivery_fee + $additional_fees;
        if (abs($calculated_total - $order_total) > 0.01) {
            wp_send_json_error(['message' => 'Total does not match calculated total'], 400);
        }

        // Create order
        $order_id = wp_insert_post([
            'post_title' => $order_number,
            'post_type' => 'order',
            'post_status' => 'publish'
        ], true);
        if (is_wp_error($order_id)) {
            wp_send_json_error(['message' => 'Failed to create order: ' . $order_id->get_error_message()], 400);
        }

        // Update order meta
        $meta_data = [
            '_order_customer' => $customer_id,
            '_order_total' => $order_total,
            '_order_status' => $order_status,
            '_order_items' => maybe_serialize($items),
            '_delivery_fee' => $delivery_fee,
            '_additional_fees' => $additional_fees,
            '_order_payment_method' => $payment_method
        ];
        foreach ($meta_data as $key => $value) {
            update_post_meta($order_id, $key, $value);
        }
        $history = [[
            'date' => current_time('mysql'),
            'action' => 'create_order',
            'details' => 'Order created by admin',
            'user_id' => get_current_user_id()
        ]];
        update_post_meta($order_id, '_order_history', json_encode($history));

        // Deduct stock
        foreach ($items as $item) {
            $medicine_id = intval($item['medicine_id']);
            $quantity = intval($item['quantity']);
            $stock = intval(get_post_meta($medicine_id, '_medicine_stock', true));
            update_post_meta($medicine_id, '_medicine_stock', $stock - $quantity);
        }

        // Create payment
        $payment_id = wp_insert_post([
            'post_title' => 'PAYMENT-' . time(),
            'post_type' => 'payment',
            'post_status' => 'publish'
        ], true);
        if (is_wp_error($payment_id)) {
            wp_delete_post($order_id, true);
            wp_send_json_error(['message' => 'Failed to create payment: ' . $payment_id->get_error_message()], 400);
        }
        $payment_meta = [
            '_payment_amount' => $order_total,
            '_payment_method' => $payment_method,
            '_payment_status' => 'Pending',
            '_payment_order_id' => $order_id
        ];
        foreach ($payment_meta as $key => $value) {
            update_post_meta($payment_id, $key, $value);
        }

        // Send confirmation email
        $user = get_user_by('ID', $customer_id);
        $subject = sprintf(__('%s - Order Confirmation #%s', 'mmsb'), $settings['business_name'], $order_id);
        $message = sprintf(
            "<p>Hello %s,</p><p>Your order #%s has been received. Total: %s %s</p><p>Payment Method: %s</p>",
            esc_html($user->user_login),
            $order_id,
            number_format($order_total, 2),
            get_option('mmsb_settings_currency', 'SAR'),
            esc_html($settings['payment_methods'][$payment_method])
        );
        $headers = ['Content-Type: text/html; charset=UTF-8', 'From: ' . $settings['business_name'] . ' <' . $settings['business_email'] . '>'];
        wp_mail($user->user_email, $subject, $message, $headers);

        log_activity(get_current_user_id(), 'add_order', "Created order ID: $order_id with payment ID: $payment_id", $_SERVER['REMOTE_ADDR']);
        wp_send_json_success(['message' => 'Order created', 'order_id' => $order_id]);
        break;

    case 'edit_order':
        check_ajax_referer('admin_panel_nonce', 'nonce');
        $order_id = intval($_POST['order_id']);
        $order_number = sanitize_text_field($_POST['order_number']);
        $customer_id = intval($_POST['customer_id']);
        $order_status = sanitize_text_field($_POST['order_status']);
        $payment_method = sanitize_text_field($_POST['payment_method']);
        $delivery_fee = floatval($_POST['delivery_fee']);
        $additional_fees = floatval($_POST['additional_fees']);
        $order_total = floatval($_POST['order_total']);
        $items = json_decode(stripslashes($_POST['items']), true);

        $settings = [
            'order_statuses' => get_option('mmsb_settings_order_statuses', ['pending' => 'Pending', 'processing' => 'Processing', 'shipped' => 'Shipped', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled']),
            'payment_methods' => get_option('mmsb_settings_payment_methods', ['cod' => 'Cash on Delivery', 'vodafone_cash' => 'Vodafone Cash', 'instapay' => 'Instapay']),
            'tax_rate' => floatval(get_option('mmsb_settings_tax_rate', 0))
        ];

        // Validate order
        $order = get_post($order_id);
        if (!$order || $order->post_type !== 'order') {
            wp_send_json_error(['message' => 'Invalid order'], 400);
        }

        // Validate inputs
        if (empty($items)) {
            wp_send_json_error(['message' => 'Order items are required'], 400);
        }
        if (!array_key_exists($order_status, $settings['order_statuses'])) {
            wp_send_json_error(['message' => 'Invalid order status'], 400);
        }
        if (!array_key_exists($payment_method, $settings['payment_methods'])) {
            wp_send_json_error(['message' => 'Invalid payment method'], 400);
        }
        $calculated_total = 0;
        foreach ($items as $item) {
            $medicine_id = intval($item['medicine_id']);
            $quantity = intval($item['quantity']);
            $medicine = get_post($medicine_id);
            if (!$medicine || $medicine->post_type !== 'medicine') {
                wp_send_json_error(['message' => 'Invalid medicine ID: ' . $medicine_id], 400);
            }
            $stock = intval(get_post_meta($medicine_id, '_medicine_stock', true));
            if ($stock < $quantity) {
                wp_send_json_error(['message' => 'Insufficient stock for medicine ID: ' . $medicine_id], 400);
            }
            $price = floatval(get_post_meta($medicine_id, '_medicine_price', true));
            $calculated_total += $price * $quantity;
        }
        $tax = $calculated_total * ($settings['tax_rate'] / 100);
        $calculated_total += $tax + $delivery_fee + $additional_fees;
        if (abs($calculated_total - $order_total) > 0.01) {
            wp_send_json_error(['message' => 'Total does not match calculated total'], 400);
        }

        // Update order
        wp_update_post([
            'ID' => $order_id,
            'post_title' => $order_number
        ]);

        // Update order meta
        $meta_data = [
            '_order_customer' => $customer_id,
            '_order_total' => $order_total,
            '_order_status' => $order_status,
            '_order_items' => maybe_serialize($items),
            '_delivery_fee' => $delivery_fee,
            '_additional_fees' => $additional_fees,
            '_order_payment_method' => $payment_method
        ];
        foreach ($meta_data as $key => $value) {
            update_post_meta($order_id, $key, $value);
        }
        $history = json_decode(get_post_meta($order_id, '_order_history', true), true) ?: [];
        $history[] = [
            'date' => current_time('mysql'),
            'action' => 'update_order',
            'details' => 'Order updated by admin',
            'user_id' => get_current_user_id()
        ];
        update_post_meta($order_id, '_order_history', json_encode($history));

        // Deduct stock (restore previous stock first if editing)
        $old_items = maybe_unserialize(get_post_meta($order_id, '_order_items', true)) ?: [];
        foreach ($old_items as $old_item) {
            $medicine_id = intval($old_item['medicine_id']);
            $quantity = intval($old_item['quantity']);
            $stock = intval(get_post_meta($medicine_id, '_medicine_stock', true));
            update_post_meta($medicine_id, '_medicine_stock', $stock + $quantity);
        }
        foreach ($items as $item) {
            $medicine_id = intval($item['medicine_id']);
            $quantity = intval($item['quantity']);
            $stock = intval(get_post_meta($medicine_id, '_medicine_stock', true));
            update_post_meta($medicine_id, '_medicine_stock', $stock - $quantity);
        }

        // Update existing payment
        $existing_payment = get_posts([
            'post_type' => 'payment',
            'meta_query' => [['key' => '_payment_order_id', 'value' => $order_id]],
            'posts_per_page' => 1
        ]);
        if ($existing_payment) {
            $payment_id = $existing_payment[0]->ID;
            update_post_meta($payment_id, '_payment_amount', $order_total);
            update_post_meta($payment_id, '_payment_method', $payment_method);
        } else {
            // Create new payment if none exists
            $payment_id = wp_insert_post([
                'post_title' => 'PAYMENT-' . time(),
                'post_type' => 'payment',
                'post_status' => 'publish'
            ], true);
            if (is_wp_error($payment_id)) {
                wp_send_json_error(['message' => 'Failed to create payment: ' . $payment_id->get_error_message()], 400);
            }
            $payment_meta = [
                '_payment_amount' => $order_total,
                '_payment_method' => $payment_method,
                '_payment_status' => 'Pending',
                '_payment_order_id' => $order_id
            ];
            foreach ($payment_meta as $key => $value) {
                update_post_meta($payment_id, $key, $value);
            }
        }

        log_activity(get_current_user_id(), 'edit_order', "Updated order ID: $order_id", $_SERVER['REMOTE_ADDR']);
        wp_send_json_success(['message' => 'Order updated', 'order_id' => $order_id]);
        break;

    case 'delete_order':
        $order_id = intval($_POST['order_id']);
        if (wp_delete_post($order_id, true)) {
            log_activity($current_user->ID, 'delete_order', "Deleted order ID: $order_id", $_SERVER['REMOTE_ADDR']);
            wp_send_json_success(['message' => 'Order deleted']);
        }
        wp_send_json_error(['message' => 'Failed to delete order'], 400);
        break;

    // Payment Actions
    case 'add_payment':
        check_ajax_referer('admin_panel_nonce', 'nonce');
        $order_id = intval($_POST['order_id']);
        $amount = floatval($_POST['amount']);
        $method = sanitize_text_field($_POST['method']);
        $status = sanitize_text_field($_POST['status']);
        $settings = [
            'payment_methods' => get_option('mmsb_settings_payment_methods', ['cod' => 'Cash on Delivery', 'vodafone_cash' => 'Vodafone Cash', 'instapay' => 'Instapay'])
        ];

        // Validate order
        $order = get_post($order_id);
        if (!$order || $order->post_type !== 'order') {
            wp_send_json_error(['message' => 'Invalid order'], 400);
        }

        // Check for existing payment
        $existing_payment = get_posts([
            'post_type' => 'payment',
            'meta_query' => [['key' => '_payment_order_id', 'value' => $order_id]],
            'posts_per_page' => 1
        ]);
        if ($existing_payment) {
            wp_send_json_error(['message' => 'Payment already exists for this order'], 400);
        }

        // Validate inputs
        if (!array_key_exists($method, $settings['payment_methods'])) {
            wp_send_json_error(['message' => 'Invalid payment method'], 400);
        }
        if (!in_array($status, ['Pending', 'Completed', 'Failed'])) {
            wp_send_json_error(['message' => 'Invalid payment status'], 400);
        }
        $order_total = floatval(get_post_meta($order_id, '_order_total', true));
        if (abs($amount - $order_total) > 0.01) {
            wp_send_json_error(['message' => 'Amount does not match order total'], 400);
        }

        // Create payment
        $payment_id = wp_insert_post([
            'post_title' => 'PAYMENT-' . time(),
            'post_type' => 'payment',
            'post_status' => 'publish'
        ], true);
        if (is_wp_error($payment_id)) {
            wp_send_json_error(['message' => 'Failed to create payment: ' . $payment_id->get_error_message()], 400);
        }

        // Update payment meta
        $payment_meta = [
            '_payment_amount' => $amount,
            '_payment_method' => $method,
            '_payment_status' => $status,
            '_payment_order_id' => $order_id
        ];
        foreach ($payment_meta as $key => $value) {
            update_post_meta($payment_id, $key, $value);
        }

        // Update order status based on payment status
        if ($status === 'Completed') {
            update_post_meta($order_id, '_order_status', 'processing');
        } elseif ($status === 'Failed') {
            update_post_meta($order_id, '_order_status', 'cancelled');
        }

        log_activity(get_current_user_id(), 'add_payment', "Created payment ID: $payment_id for order ID: $order_id", $_SERVER['REMOTE_ADDR']);
        wp_send_json_success(['message' => 'Payment created', 'payment_id' => $payment_id]);
        break;

    case 'edit_payment':
        check_ajax_referer('admin_panel_nonce', 'nonce');
        $payment_id = intval($_POST['payment_id']);
        $amount = floatval($_POST['amount']);
        $method = sanitize_text_field($_POST['method']);
        $status = sanitize_text_field($_POST['status']);
        $settings = [
            'payment_methods' => get_option('mmsb_settings_payment_methods', ['cod' => 'Cash on Delivery', 'vodafone_cash' => 'Vodafone Cash', 'instapay' => 'Instapay'])
        ];

        // Validate payment
        $payment = get_post($payment_id);
        if (!$payment || $payment->post_type !== 'payment') {
            wp_send_json_error(['message' => 'Invalid payment'], 400);
        }

        // Validate inputs
        if (!array_key_exists($method, $settings['payment_methods'])) {
            wp_send_json_error(['message' => 'Invalid payment method'], 400);
        }
        if (!in_array($status, ['Pending', 'Completed', 'Failed'])) {
            wp_send_json_error(['message' => 'Invalid payment status'], 400);
        }
        $order_id = intval(get_post_meta($payment_id, '_payment_order_id', true));
        $order_total = floatval(get_post_meta($order_id, '_order_total', true));
        if (abs($amount - $order_total) > 0.01) {
            wp_send_json_error(['message' => 'Amount does not match order total'], 400);
        }

        // Update payment meta
        update_post_meta($payment_id, '_payment_amount', $amount);
        update_post_meta($payment_id, '_payment_method', $method);
        update_post_meta($payment_id, '_payment_status', $status);

        // Update order status based on payment status
        if ($status === 'Completed') {
            update_post_meta($order_id, '_order_status', 'processing');
        } elseif ($status === 'Failed') {
            update_post_meta($order_id, '_order_status', 'cancelled');
        }

        log_activity(get_current_user_id(), 'edit_payment', "Updated payment ID: $payment_id", $_SERVER['REMOTE_ADDR']);
        wp_send_json_success(['message' => 'Payment updated']);
        break;

    // User Actions (for roles.php - Admins/Mods)
    case 'get_user':
        $user = get_user_by('id', intval($_POST['user_id']));
        if ($user) {
            wp_send_json_success([
                'username' => $user->user_login,
                'email' => $user->user_email,
                'role' => $user->roles[0],
                'address' => get_user_meta($user->ID, '_user_address', true)
            ]);
        }
        wp_send_json_error(['message' => 'User not found'], 404);
        break;

    case 'add_user':
        $user_id = wp_insert_user([
            'user_login' => sanitize_text_field($_POST['username']),
            'user_email' => sanitize_email($_POST['email']),
            'user_pass' => $_POST['password'],
            'role' => sanitize_text_field($_POST['role'])
        ]);
        if (!is_wp_error($user_id)) {
            update_user_meta($user_id, '_user_address', sanitize_textarea_field($_POST['address']));
            log_activity($current_user->ID, 'add_user', "Added user: {$_POST['username']}", $_SERVER['REMOTE_ADDR']);
            wp_send_json_success(['message' => 'User added']);
        }
        wp_send_json_error(['message' => $user_id->get_error_message()], 400);
        break;

    case 'edit_user':
        $user_id = intval($_POST['user_id']);
        if ($user_id === $current_user->ID && $current_user->roles[0] !== sanitize_text_field($_POST['role'])) {
            wp_send_json_error(['message' => 'Cannot change your own role'], 403);
        }
        $result = wp_update_user([
            'ID' => $user_id,
            'user_login' => sanitize_text_field($_POST['username']),
            'user_email' => sanitize_email($_POST['email']),
            'role' => sanitize_text_field($_POST['role'])
        ]);
        if (!is_wp_error($result)) {
            if (!empty($_POST['password'])) {
                wp_set_password($_POST['password'], $user_id);
            }
            update_user_meta($user_id, '_user_address', sanitize_textarea_field($_POST['address']));
            log_activity($current_user->ID, 'edit_user', "Edited user ID: $user_id", $_SERVER['REMOTE_ADDR']);
            wp_send_json_success(['message' => 'User updated']);
        }
        wp_send_json_error(['message' => $result->get_error_message()], 400);
        break;

    case 'delete_user':
        $user_id = intval($_POST['user_id']);
        if ($user_id === $current_user->ID) {
            wp_send_json_error(['message' => 'Cannot delete yourself'], 403);
        }
        if (wp_delete_user($user_id)) {
            log_activity($current_user->ID, 'delete_user', "Deleted user ID: $user_id", $_SERVER['REMOTE_ADDR']);
            wp_send_json_success(['message' => 'User deleted']);
        }
        wp_send_json_error(['message' => 'Failed to delete user'], 400);
        break;

    // Category Actions (for taxonomies.php)
    case 'get_category':
        $term = get_term(intval($_POST['term_id']), 'medicine_category');
        if ($term && !is_wp_error($term)) {
            wp_send_json_success([
                'name' => $term->name,
                'slug' => $term->slug,
                'description' => $term->description
            ]);
        }
        wp_send_json_error(['message' => 'Category not found'], 404);
        break;

    case 'add_category':
        $result = wp_insert_term(
            sanitize_text_field($_POST['name']),
            'medicine_category',
            [
                'slug' => sanitize_text_field($_POST['slug']),
                'description' => sanitize_textarea_field($_POST['description'])
            ]
        );
        if (!is_wp_error($result)) {
            log_activity($current_user->ID, 'add_category', "Added category: {$_POST['name']}", $_SERVER['REMOTE_ADDR']);
            wp_send_json_success(['message' => 'Category added']);
        }
        wp_send_json_error(['message' => $result->get_error_message()], 400);
        break;

    case 'edit_category':
        $result = wp_update_term(
            intval($_POST['term_id']),
            'medicine_category',
            [
                'name' => sanitize_text_field($_POST['name']),
                'slug' => sanitize_text_field($_POST['slug']),
                'description' => sanitize_textarea_field($_POST['description'])
            ]
        );
        if (!is_wp_error($result)) {
            log_activity($current_user->ID, 'edit_category', "Edited category ID: {$_POST['term_id']}", $_SERVER['REMOTE_ADDR']);
            wp_send_json_success(['message' => 'Category updated']);
        }
        wp_send_json_error(['message' => $result->get_error_message()], 400);
        break;

    case 'delete_category':
        $result = wp_delete_term(intval($_POST['term_id']), 'medicine_category');
        if ($result && !is_wp_error($result)) {
            log_activity($current_user->ID, 'delete_category', "Deleted category ID: {$_POST['term_id']}", $_SERVER['REMOTE_ADDR']);
            wp_send_json_success(['message' => 'Category deleted']);
        }
        wp_send_json_error(['message' => 'Failed to delete category'], 400);
        break;

    // Role Actions
    case 'add_role':
    case 'edit_role':
        $role_key = $action === 'add_role' ? sanitize_text_field($_POST['role_key_new']) : sanitize_text_field($_POST['role_key']);
        $role_name = sanitize_text_field($_POST['role_name']);
        $capabilities = json_decode($_POST['capabilities'], true) ?: [];
        if ($action === 'add_role') {
            add_role($role_key, $role_name, $capabilities);
        } else {
            global $wp_roles;
            $wp_roles->remove_role($role_key);
            add_role($role_key, $role_name, $capabilities);
        }
        log_activity($current_user->ID, $action, $action === 'add_role' ? "Added role: $role_key" : "Edited role: $role_key", $_SERVER['REMOTE_ADDR']);
        wp_send_json_success(['message' => $action === 'add_role' ? 'Role added' : 'Role updated']);
        break;

    case 'delete_role':
        $role_key = sanitize_text_field($_POST['role_key']);
        global $wp_roles;
        $wp_roles->remove_role($role_key);
        log_activity($current_user->ID, 'delete_role', "Deleted role: $role_key", $_SERVER['REMOTE_ADDR']);
        wp_send_json_success(['message' => 'Role deleted']);
        break;

    case 'get_role':
        $role_key = sanitize_text_field($_POST['role_key']);
        global $wp_roles;
        $role = $wp_roles->get_role($role_key);
        if ($role) {
            wp_send_json_success(['name' => $wp_roles->roles[$role_key]['name'], 'capabilities' => $role->capabilities]);
        }
        wp_send_json_error(['message' => 'Role not found'], 404);
        break;

    // Ticket Actions
    case 'add_ticket_reply':
        $ticket_id = intval($_POST['ticket_id']);
        $reply_content = sanitize_textarea_field($_POST['reply_content']);
        $replies = get_post_meta($ticket_id, '_ticket_replies', true) ?: [];
        $replies[] = [
            'user_id' => $current_user->ID,
            'content' => $reply_content,
            'date' => current_time('mysql'),
        ];
        update_post_meta($ticket_id, '_ticket_replies', $replies);
        update_post_meta($ticket_id, '_ticket_status', sanitize_text_field($_POST['ticket_status']));
        log_activity($current_user->ID, 'add_ticket_reply', "Added reply to ticket ID: $ticket_id", $_SERVER['REMOTE_ADDR']);
        wp_send_json_success(['message' => 'Reply added']);
        break;

    // Settings Actions
    case 'save_settings':
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }
        $settings = [
            'delivery_fee' => floatval($_POST['delivery_fee']),
            'currency' => sanitize_text_field($_POST['currency']),
            'stock_alert' => intval($_POST['stock_alert']),
            'tax_rate' => floatval($_POST['tax_rate']),
            'business_name' => sanitize_text_field($_POST['business_name']),
            'business_email' => sanitize_email($_POST['business_email']),
            'business_phone' => sanitize_text_field($_POST['business_phone']),
            'backup_schedule' => sanitize_text_field($_POST['backup_schedule']),
            'dark_mode' => $_POST['dark_mode'] ? '1' : '0',
            'sms_enabled' => $_POST['sms_enabled'] ? '1' : '0',
            'sms_api_key' => sanitize_text_field($_POST['sms_api_key']),
            'sms_sender_id' => sanitize_text_field($_POST['sms_sender_id'] ?? ''),
            'maintenance_mode' => $_POST['maintenance_mode'] ? '1' : '0',
            'log_retention' => intval($_POST['log_retention']),
            'email_templates' => [
                'shipped' => wp_kses_post($_POST['email_templates']['shipped']),
                'delivered' => wp_kses_post($_POST['email_templates']['delivered']),
                'cancelled' => wp_kses_post($_POST['email_templates']['cancelled'] ?? '')
            ],
            'order_statuses' => array_map('sanitize_text_field', $_POST['order_statuses']),
            'payment_methods' => array_map('sanitize_text_field', $_POST['payment_methods'] ?? []),
            'custom_fields_orders' => array_map(function ($field) {
                return [
                    'key' => sanitize_key($field['key']),
                    'label' => sanitize_text_field($field['label']),
                    'type' => sanitize_text_field($field['type']),
                    'options' => sanitize_text_field($field['options'] ?? '')
                ];
            }, $_POST['custom_fields_orders'] ?? []),
            'custom_fields_medicines' => array_map(function ($field) {
                return [
                    'key' => sanitize_key($field['key']),
                    'label' => sanitize_text_field($field['label']),
                    'type' => sanitize_text_field($field['type']),
                    'options' => sanitize_text_field($field['options'] ?? '')
                ];
            }, $_POST['custom_fields_medicines'] ?? [])
        ];
        if (!empty($_FILES['business_logo'])) {
            $upload = wp_upload_bits($_FILES['business_logo']['name'], null, file_get_contents($_FILES['business_logo']['tmp_name']));
            if (!$upload['error']) {
                $settings['business_logo'] = $upload['url'];
            }
        } else {
            $settings['business_logo'] = get_option('mmsb_settings_business_logo', '');
        }
        foreach ($settings as $key => $value) {
            update_option("mmsb_settings_$key", $value);
        }
        log_activity($current_user->ID, 'save_settings', 'Updated settings', $_SERVER['REMOTE_ADDR']);
        wp_send_json_success(['message' => 'Settings saved']);
        break;

    case 'reset_settings':
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }
        $section = sanitize_text_field($_POST['section']);
        $defaults = [
            'general' => [
                'business_name' => 'My Pharmacy',
                'business_email' => '',
                'business_phone' => '',
                'business_logo' => '',
                'currency' => 'SAR',
                'dark_mode' => '0',
                'maintenance_mode' => '0'
            ],
            'orders' => [
                'delivery_fee' => 20,
                'tax_rate' => 0,
                'stock_alert' => 10,
                'order_statuses' => ['pending' => 'Pending', 'processing' => 'Processing', 'shipped' => 'Shipped', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled'],
                'payment_methods' => ['cod' => 'Cash on Delivery', 'vodafone_cash' => 'Vodafone Cash', 'instapay' => 'Instapay']
            ],
            'emails' => [
                'email_templates' => [
                    'shipped' => "<p>Hello, {customer_name}</p><p>Your order #{order_id} was shipped on {date}.</p><p>Details: {order_details}</p>",
                    'delivered' => "<p>Hello, {customer_name}</p><p>Your order #{order_id} was successfully delivered.</p><p>Thank you for shopping with us!</p>",
                    'cancelled' => "<p>Hello, {customer_name}</p><p>Your order #{order_id} has been cancelled. Reason: {cancel_reason}</p><p>Contact us for assistance.</p>"
                ]
            ],
            'integrations' => [
                'backup_schedule' => 'daily',
                'sms_enabled' => '0',
                'sms_api_key' => '',
                'sms_sender_id' => ''
            ],
            'custom-fields' => [
                'custom_fields_orders' => [],
                'custom_fields_medicines' => []
            ],
            'logs' => [
                'log_retention' => 30
            ]
        ];
        if (isset($defaults[$section])) {
            foreach ($defaults[$section] as $key => $value) {
                update_option("mmsb_settings_$key", $value);
            }
            log_activity($current_user->ID, 'reset_settings', "Reset $section settings", $_SERVER['REMOTE_ADDR']);
            wp_send_json_success(['message' => 'Settings reset']);
        }
        wp_send_json_error(['message' => 'Invalid section'], 400);
        break;

    case 'preview_email':
        $template = wp_kses_post($_POST['template']);
        $type = sanitize_text_field($_POST['type']);
        if (!in_array($type, ['shipped', 'delivered', 'cancelled'])) {
            wp_send_json_error(['message' => 'Invalid email type'], 400);
        }
        $html = str_replace(
            ['{customer_name}', '{order_id}', '{date}', '{order_details}', '{cancel_reason}'],
            ['John Doe', '123', date('Y-m-d'), 'Sample order details', 'Customer request'],
            $template
        );
        wp_send_json_success(['html' => $html]);
        break;

    // Navigation Logging (for sidebar.php)
    case 'log_navigation':
        $page = sanitize_text_field($_POST['page']);
        log_activity($current_user->ID, 'navigate', "Navigated to page: $page", $_SERVER['REMOTE_ADDR']);
        wp_send_json_success(['message' => 'Navigation logged']);
        break;

    // Test SMS Action (SMS Misr Integration)
    case 'test_sms':
        if (!wp_verify_nonce($nonce, 'settings_nonce') || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
        }
        $sms_enabled = get_option('mmsb_settings_sms_enabled', '0');
        $sms_api_key = get_option('mmsb_settings_sms_api_key', '');
        $sms_sender_id = get_option('mmsb_settings_sms_sender_id', '');
        if ($sms_enabled !== '1' || empty($sms_api_key) || empty($sms_sender_id)) {
            wp_send_json_error(['message' => 'SMS integration is not configured']);
        }

        $test_message = 'Test SMS from My Pharmacy';
        $admin_phone = get_option('mmsb_settings_business_phone', '');
        if (empty($admin_phone)) {
            wp_send_json_error(['message' => 'Admin phone number not configured']);
        }

        // Format phone number to E.164 (e.g., +201234567890)
        $admin_phone = preg_replace('/[^0-9+]/', '', $admin_phone);
        if (!preg_match('/^\+201[0-2,5][0-9]{8}$/', $admin_phone)) {
            wp_send_json_error(['message' => 'Invalid phone number format. Use E.164 format (e.g., +201234567890)']);
        }

        // SMS Misr API configuration
        $api_url = 'https://smsmisr.com/api/SMS/';
        list($username, $password) = explode(':', $sms_api_key); // Assume api_key is username:password
        $api_params = [
            'environment' => '2', // Test environment
            'username' => $username,
            'password' => $password,
            'sender' => $sms_sender_id,
            'mobile' => $admin_phone,
            'language' => '1', // English
            'message' => urlencode($test_message)
        ];

        // Make HTTP POST request
        $response = wp_remote_post($api_url, [
            'method' => 'POST',
            'timeout' => 30,
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => $api_params
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => 'Failed to send test SMS: ' . $response->get_error_message()]);
        }

        $response_body = wp_remote_retrieve_body($response);
        $response_code = wp_remote_retrieve_response_code($response);
        $result = json_decode($response_body, true);

        if ($response_code === 200 && isset($result['code']) && $result['code'] === '1901') {
            log_activity($current_user->ID, 'test_sms', 'Sent test SMS to ' . $admin_phone, $_SERVER['REMOTE_ADDR']);
            wp_send_json_success(['message' => 'Test SMS sent successfully']);
        } else {
            $error_message = $result['code'] ?? 'Unknown error';
            $error_descriptions = [
                '1902' => 'Invalid request',
                '1903' => 'Invalid username or password',
                '1904' => 'Invalid sender ID',
                '1906' => 'Invalid mobile number',
                '1907' => 'Insufficient credit',
                '1908' => 'Server under maintenance',
                '1909' => 'Invalid date/time format',
                '1910' => 'Invalid message',
                '1911' => 'Invalid language',
                '1912' => 'Message too long',
            ];
            $error_message = $error_descriptions[$result['code']] ?? $error_message;
            wp_send_json_error(['message' => 'Failed to send test SMS: ' . $error_message]);
        }
        break;

    default:
        wp_send_json_error(['message' => 'Unknown action'], 400);
}

function delete_medicine($post_id)
{
    $attachments = get_attached_media('', $post_id);
    foreach ($attachments as $attachment) {
        wp_delete_attachment($attachment->ID, true);
    }
    return wp_delete_post($post_id, true);
}

function update_post_meta_data($post_id, $meta_data)
{
    foreach ($meta_data as $key => $value) {
        update_post_meta($post_id, $key, $value);
    }
}

function get_paginated_posts_data($post_type, $search, $page, $per_page)
{
    $args = [
        'post_type' => $post_type,
        'posts_per_page' => $per_page,
        'post_status' => 'publish',
        'paged' => $page
    ];
    if ($search) {
        $args['s'] = $search;
    }
    $query = new WP_Query($args);
    $posts = [];
    while ($query->have_posts()) {
        $query->the_post();
        $post_id = get_the_ID();
        $posts[] = [
            'id' => $post_id,
            'title' => get_the_title(),
            'date' => get_the_date(),
            'meta' => get_post_meta($post_id),
            'terms' => wp_get_post_terms($post_id, 'medicine_category', ['fields' => 'ids'])
        ];
    }
    wp_reset_postdata();
    return [
        'posts' => $posts,
        'total_pages' => $query->max_num_pages,
        'current_page' => $page
    ];
}

function getOrderDetails($order_id)
{
    $items = get_post_meta($order_id, '_order_items', true) ? maybe_unserialize(get_post_meta($order_id, '_order_items', true)) : [];
    $details = '<ul>';
    foreach ($items as $item) {
        $medicine = get_post($item['medicine_id']);
        $details .= "<li>{$medicine->post_title} x {$item['quantity']} - {$item['price']}</li>";
    }
    $details .= '</ul>';
    return $details;
}

add_action('wp_ajax_update_order_status', function () {
    check_ajax_referer('admin_panel_nonce', 'nonce');
    $order_id = intval($_POST['order_id']);
    $status = sanitize_text_field($_POST['status']);
    $order = get_post($order_id);
    if ($order && $order->post_type === 'order') {
        $allowed_statuses = get_option('mmsb_settings_order_statuses', ['pending' => 'Pending', 'processing' => 'Processing', 'shipped' => 'Shipped', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled']);
        if (!array_key_exists($status, $allowed_statuses)) {
            wp_send_json_error(['message' => 'Invalid status'], 400);
        }
        update_post_meta($order_id, '_order_status', $status);
        $history = get_post_meta($order_id, '_order_history', true) ? json_decode(get_post_meta($order_id, '_order_history', true), true) : [];
        $history[] = [
            'date' => current_time('mysql'),
            'action' => 'status_update',
            'details' => "Status changed to $status",
            'user_id' => get_current_user_id()
        ];
        update_post_meta($order_id, '_order_history', json_encode($history));
        log_activity(get_current_user_id(), 'update_order_status', "Order ID: $order_id status changed to $status", $_SERVER['REMOTE_ADDR']);
        wp_send_json_success(['message' => 'Status updated']);
    }
    wp_send_json_error(['message' => 'Invalid order'], 400);
});

add_action('wp_ajax_cancel_order', function () {
    check_ajax_referer('admin_panel_nonce', 'nonce');
    $order_id = intval($_POST['order_id']);
    $reason = sanitize_textarea_field($_POST['reason']);
    $order = get_post($order_id);
    if ($order && $order->post_type === 'order') {
        update_post_meta($order_id, '_order_status', 'cancelled');
        $history = get_post_meta($order_id, '_order_history', true) ? json_decode(get_post_meta($order_id, '_order_history', true), true) : [];
        $history[] = [
            'date' => current_time('mysql'),
            'action' => 'cancel_order',
            'details' => "Order cancelled: $reason",
            'user_id' => get_current_user_id()
        ];
        update_post_meta($order_id, '_order_history', json_encode($history));
        log_activity(get_current_user_id(), 'cancel_order', "Order ID: $order_id cancelled: $reason", $_SERVER['REMOTE_ADDR']);
        wp_send_json_success(['message' => 'Order cancelled', 'order_id' => $order_id]);
    }
    wp_send_json_error(['message' => 'Invalid order'], 400);
});

add_action('wp_ajax_send_order_email', function () {
    check_ajax_referer('admin_panel_nonce', 'nonce');
    $order_id = intval($_POST['order_id']);
    $status = sanitize_text_field($_POST['status']);
    $order = get_post($order_id);
    if ($order && $order->post_type === 'order') {
        $customer_id = get_post_meta($order_id, '_order_customer', true);
        $customer = get_user_by('ID', $customer_id);
        $template = get_option("mmsb_settings_email_templates")[$status] ?? '';
        if (!$template) {
            wp_send_json_error(['message' => 'No email template'], 400);
        }
        $replacements = [
            '{customer_name}' => $customer->user_login,
            '{order_id}' => $order_id,
            '{date}' => current_time('Y-m-d'),
            '{order_details}' => getOrderDetails($order_id),
            '{cancel_reason}' => get_post_meta($order_id, '_cancel_reason', true) ?: 'Not specified'
        ];
        $message = str_replace(array_keys($replacements), array_values($replacements), $template);
        $business_name = get_option('mmsb_settings_business_name', 'My Pharmacy');
        $business_email = get_option('mmsb_settings_business_email', 'no-reply@example.com');
        $subject = "Order #$order_id - " . ucfirst($status);
        $headers = ["Content-Type: text/html; charset=UTF-8", "From: $business_name <$business_email>"];
        wp_mail($customer->user_email, $subject, $message, $headers);
        log_activity(get_current_user_id(), 'send_order_email', "Sent $status email for order ID: $order_id", $_SERVER['REMOTE_ADDR']);
        wp_send_json_success(['message' => 'Email sent']);
    }
    wp_send_json_error(['message' => 'Invalid order'], 400);
});
