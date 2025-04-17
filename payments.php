<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-load.php');
require_once('inc/auth.php');
require_login();

$page = intval($_GET['paged'] ?? 1);
$per_page = 10;
$search = sanitize_text_field($_GET['s'] ?? '');

// Load payment methods
$payment_methods = get_option('mmsb_settings_payment_methods', ['cod' => 'Cash on Delivery', 'vodafone_cash' => 'Vodafone Cash', 'instapay' => 'Instapay']);

// Get paginated payments
$payments = get_paginated_posts_data('payment', $search, $page, $per_page);

// AJAX handler for editing payment
add_action('wp_ajax_edit_payment', function () {
    check_ajax_referer('admin_panel_nonce', 'nonce');
    $payment_id = intval($_POST['payment_id']);
    $amount = floatval($_POST['amount']);
    $method = sanitize_text_field($_POST['method']);
    $status = sanitize_text_field($_POST['status']);
    $payment = get_post($payment_id);
    $payment_methods = get_option('mmsb_settings_payment_methods', ['cod' => 'Cash on Delivery', 'vodafone_cash' => 'Vodafone Cash', 'instapay' => 'Instapay']);
    if (!$payment || $payment->post_type !== 'payment') {
        wp_send_json_error(['message' => 'Invalid payment'], 400);
    }
    if (!array_key_exists($method, $payment_methods)) {
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
});

// AJAX handler for adding payment
add_action('wp_ajax_add_payment', function () {
    check_ajax_referer('admin_panel_nonce', 'nonce');
    $order_id = intval($_POST['order_id']);
    $amount = floatval($_POST['amount']);
    $method = sanitize_text_field($_POST['method']);
    $status = sanitize_text_field($_POST['status']);
    $payment_methods = get_option('mmsb_settings_payment_methods', ['cod' => 'Cash on Delivery', 'vodafone_cash' => 'Vodafone Cash', 'instapay' => 'Instapay']);

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
    if (!array_key_exists($method, $payment_methods)) {
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
    $meta_data = [
        '_payment_amount' => $amount,
        '_payment_method' => $method,
        '_payment_status' => $status,
        '_payment_order_id' => $order_id
    ];
    foreach ($meta_data as $key => $value) {
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
});

include 'inc/header.php';
include 'inc/sidebar.php';
?>

<div class="content">
    <h1 class="mb-4 text-primary">Payments</h1>
    <div class="card mb-4">
        <div class="card-body">
            <form id="payment-search" class="mb-3">
                <div class="input-group">
                    <input type="text" name="s" class="form-control" placeholder="Search payments..." value="<?php echo esc_attr($search); ?>">
                    <button type="submit" class="btn btn-primary">Search</button>
                </div>
            </form>
            <button class="btn btn-success mb-3" data-bs-toggle="modal" data-bs-target="#addPaymentModal">Add Payment</button>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Title</th>
                            <th>Order ID</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments['posts'] as $payment): ?>
                            <tr>
                                <td><?php echo $payment['id']; ?></td>
                                <td><?php echo esc_html($payment['title']); ?></td>
                                <td><?php echo esc_html($payment['meta']['_payment_order_id'][0] ?? '-'); ?></td>
                                <td><?php echo esc_html($payment['meta']['_payment_amount'][0] ?? '0'); ?></td>
                                <td><?php echo esc_html($payment_methods[$payment['meta']['_payment_method'][0]] ?? $payment['meta']['_payment_method'][0] ?? 'Unknown'); ?></td>
                                <td><?php echo esc_html($payment['meta']['_payment_status'][0] ?? 'Unknown'); ?></td>
                                <td><?php echo esc_html($payment['date']); ?></td>
                                <td>
                                    <button class="btn btn-sm btn-primary edit-payment" data-id="<?php echo $payment['id']; ?>" data-bs-toggle="modal" data-bs-target="#paymentModal">Edit</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($payments['total_pages'] > 1): ?>
                <nav>
                    <ul class="pagination">
                        <?php for ($i = 1; $i <= $payments['total_pages']; $i++): ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?paged=<?php echo $i; ?>&s=<?php echo urlencode($search); ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Edit Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Payment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="payment-form" class="ajax-form">
                    <input type="hidden" name="payment_id" id="payment_id">
                    <input type="hidden" name="action" value="edit_payment">
                    <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('admin_panel_nonce'); ?>">
                    <div class="mb-3">
                        <label class="form-label">Amount</label>
                        <input type="number" name="amount" id="payment_amount" class="form-control" step="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Method</label>
                        <select name="method" id="payment_method" class="form-control" required>
                            <option value="">Select Payment Method</option>
                            <?php foreach ($payment_methods as $key => $label): ?>
                                <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select name="status" id="payment_status" class="form-control" required>
                            <option value="Pending">Pending</option>
                            <option value="Completed">Completed</option>
                            <option value="Failed">Failed</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">Save</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Add Payment Modal -->
<div class="modal fade" id="addPaymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Payment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="add-payment-form" class="ajax-form">
                    <input type="hidden" name="action" value="add_payment">
                    <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('admin_panel_nonce'); ?>">
                    <div class="mb-3">
                        <label class="form-label">Order ID</label>
                        <input type="number" name="order_id" id="add_payment_order_id" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Amount</label>
                        <input type="number" name="amount" id="add_payment_amount" class="form-control" step="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Method</label>
                        <select name="method" id="add_payment_method" class="form-control" required>
                            <option value="">Select Payment Method</option>
                            <?php foreach ($payment_methods as $key => $label): ?>
                                <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select name="status" id="add_payment_status" class="form-control" required>
                            <option value="Pending">Pending</option>
                            <option value="Completed">Completed</option>
                            <option value="Failed">Failed</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">Save</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    $(document).ready(function() {
        function showAlert(message, type) {
            const alert = `<div class="alert alert-${type} alert-dismissible fade show">${message}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>`;
            $('.content').prepend(alert);
            setTimeout(() => $('.alert').remove(), 3000);
        }

        // Edit Payment
        $('.edit-payment').on('click', function() {
            const id = $(this).data('id');
            $.ajax({
                url: '/admin-panel/api/payment.php',
                type: 'GET',
                data: {
                    payment_id: id
                },
                success: function(result) {
                    if (result.success) {
                        $('#payment_id').val(id);
                        $('#payment_amount').val(result.data.amount);
                        $('#payment_method').val(result.data.method);
                        $('#payment_status').val(result.data.status);
                    }
                }
            });
        });

        // Add Payment
        $('#add-payment-form').on('submit', function(e) {
            e.preventDefault();
            $.ajax({
                url: '/admin-panel/inc/ajax-handler.php',
                type: 'POST',
                data: $(this).serialize(),
                success: function(result) {
                    if (result.success) {
                        showAlert('Payment created', 'success');
                        $('#addPaymentModal').modal('hide');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showAlert(result.data.message, 'danger');
                    }
                }
            });
        });
    });
</script>
<?php include 'inc/footer.php'; ?>