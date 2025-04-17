<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-load.php');
require_once('inc/auth.php');
require_once('inc/activity-log.php');
require_login();

$page = intval($_GET['paged'] ?? 1);
$per_page = 10;
$search = sanitize_text_field($_GET['s'] ?? '');
$status_filter = sanitize_text_field($_GET['status'] ?? '');

// Load settings
$settings = [
    'order_statuses' => get_option('mmsb_settings_order_statuses', ['pending' => 'Pending', 'processing' => 'Processing', 'shipped' => 'Shipped', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled']),
    'delivery_fee' => floatval(get_option('mmsb_settings_delivery_fee', 20)),
    'tax_rate' => floatval(get_option('mmsb_settings_tax_rate', 0)),
    'business_name' => get_option('mmsb_settings_business_name', 'My Pharmacy'),
    'business_email' => get_option('mmsb_settings_business_email', 'no-reply@example.com'),
    'payment_methods' => get_option('mmsb_settings_payment_methods', ['cod' => 'Cash on Delivery', 'vodafone_cash' => 'Vodafone Cash', 'instapay' => 'Instapay']),
    'email_templates' => [
        'shipped' => get_option('mmsb_settings_email_shipped', "<p>Hello, {customer_name}</p><p>Your order #{order_id} was shipped on {date}.</p><p>Details: {order_details}</p>"),
        'delivered' => get_option('mmsb_settings_email_delivered', "<p>Hello, {customer_name}</p><p>Your order #{order_id} was successfully delivered.</p><p>Thank you for shopping with us!</p>")
    ]
];

$args = [
    'post_type' => 'order',
    'posts_per_page' => $per_page,
    'post_status' => 'publish',
    'paged' => $page,
    's' => $search
];

if ($status_filter) {
    $args['meta_query'] = [
        [
            'key' => '_order_status',
            'value' => $status_filter,
            'compare' => '='
        ]
    ];
}

$query = new WP_Query($args);
$orders = [];
while ($query->have_posts()) {
    $query->the_post();
    $order_id = get_the_ID();
    $orders[] = [
        'id' => $order_id,
        'title' => get_the_title(),
        'customer' => get_user_by('ID', get_post_meta($order_id, '_order_customer', true)),
        'total' => get_post_meta($order_id, '_order_total', true),
        'status' => get_post_meta($order_id, '_order_status', true),
        'date' => get_the_date()
    ];
}
$total_pages = $query->max_num_pages;
wp_reset_postdata();

$users = get_users(['role__in' => ['subscriber', 'customer']]);
$medicines = get_posts(['post_type' => 'medicine', 'posts_per_page' => -1, 'post_status' => 'publish']);

include 'inc/header.php';
include 'inc/sidebar.php';
?>

<div class="content">
    <h1 class="mb-4 text-primary">Orders</h1>
    <div class="card mb-4">
        <div class="card-body">
            <form id="order-search" class="mb-3">
                <div class="row">
                    <div class="col-md-6">
                        <div class="input-group">
                            <input type="text" name="s" class="form-control" placeholder="Search orders..." value="<?php echo esc_attr($search); ?>">
                            <button type="submit" class="btn btn-primary">Search</button>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <select name="status" class="form-control">
                            <option value="">All Statuses</option>
                            <?php foreach ($settings['order_statuses'] as $key => $label): ?>
                                <option value="<?php echo esc_attr($key); ?>" <?php selected($status_filter, $key); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary w-100">Filter</button>
                    </div>
                </div>
            </form>
            <button class="btn btn-success mb-3" data-bs-toggle="modal" data-bs-target="#orderModal">Add Order</button>
            <button class="btn btn-info mb-3" id="export-orders">Export to CSV</button>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Order Number</th>
                            <th>Customer</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order): ?>
                            <tr>
                                <td><?php echo $order['id']; ?></td>
                                <td><?php echo esc_html($order['title']); ?></td>
                                <td><?php echo esc_html($order['customer']->user_login ?? 'Unknown'); ?></td>
                                <td><?php echo esc_html($order['total']); ?></td>
                                <td>
                                    <select class="form-control quick-status" data-id="<?php echo $order['id']; ?>">
                                        <?php foreach ($settings['order_statuses'] as $key => $label): ?>
                                            <option value="<?php echo esc_attr($key); ?>" <?php selected($order['status'], $key); ?>><?php echo esc_html($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-primary view-order" data-id="<?php echo $order['id']; ?>" data-bs-toggle="modal" data-bs-target="#viewOrderModal">View</button>
                                    <button class="btn btn-sm btn-primary edit-order" data-id="<?php echo $order['id']; ?>" data-bs-toggle="modal" data-bs-target="#orderModal">Edit</button>
                                    <button class="btn btn-sm btn-danger cancel-order" data-id="<?php echo $order['id']; ?>">Cancel</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($total_pages > 1): ?>
                <nav>
                    <ul class="pagination">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?paged=<?php echo $i; ?>&s=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- View Order Modal -->
<div class="modal fade" id="viewOrderModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Order Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6>Order Information</h6>
                        <p><strong>Order Number:</strong> <span id="view-order-number"></span></p>
                        <p><strong>Customer:</strong> <span id="view-customer"></span></p>
                        <p><strong>Total:</strong> <span id="view-total"></span></p>
                        <p><strong>Status:</strong> <span id="view-status"></span></p>
                        <p><strong>Date:</strong> <span id="view-date"></span></p>
                        <p><strong>Delivery Fee:</strong> <span id="view-delivery-fee"></span></p>
                        <p><strong>Additional Fees:</strong> <span id="view-additional-fees"></span></p>
                        <p><strong>Payment Method:</strong> <span id="view-payment-method"></span></p>
                        <p><strong>Payment Status:</strong> <span id="view-payment-status"></span></p>
                    </div>
                    <div class="col-md-6">
                        <h6>Order Timeline</h6>
                        <div id="order-timeline" style="height: 200px;"></div>
                    </div>
                </div>
                <h6 class="mt-3">Items</h6>
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead>
                            <tr>
                                <th>Medicine</th>
                                <th>Quantity</th>
                                <th>Price</th>
                                <th>Subtotal</th>
                            </tr>
                        </thead>
                        <tbody id="view-items"></tbody>
                    </table>
                </div>
                <h6>History</h6>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Action</th>
                                <th>Details</th>
                                <th>User</th>
                            </tr>
                        </thead>
                        <tbody id="view-history"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Order Modal -->
<div class="modal fade" id="orderModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Order</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="order-form" class="ajax-form">
                    <input type="hidden" name="order_id" id="order_id">
                    <input type="hidden" name="action" id="form-action" value="add_order">
                    <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('admin_panel_nonce'); ?>">
                    <div class="mb-3">
                        <label class="form-label">Order Number</label>
                        <input type="text" name="order_number" id="order_number" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Customer</label>
                        <select name="customer_id" id="customer_id" class="form-control" required>
                            <option value="">Select Customer</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user->ID; ?>"><?php echo esc_html($user->user_login); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select name="order_status" id="order_status" class="form-control">
                            <?php foreach ($settings['order_statuses'] as $key => $label): ?>
                                <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Payment Method</label>
                        <select name="payment_method" id="payment_method" class="form-control" required>
                            <option value="">Select Payment Method</option>
                            <?php foreach ($settings['payment_methods'] as $key => $label): ?>
                                <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Delivery Fee</label>
                        <input type="number" name="delivery_fee" id="delivery_fee" class="form-control" step="0.01" value="<?php echo esc_attr($settings['delivery_fee']); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Additional Fees</label>
                        <input type="number" name="additional_fees" id="additional_fees" class="form-control" step="0.01" value="0">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Order Items</label>
                        <div id="order-items">
                            <div class="item-row mb-2">
                                <div class="row">
                                    <div class="col-md-5">
                                        <select name="items[0][medicine_id]" class="form-control medicine-select">
                                            <option value="">Select Medicine</option>
                                            <?php foreach ($medicines as $medicine): ?>
                                                <option value="<?php echo $medicine->ID; ?>" data-price="<?php echo esc_attr(get_post_meta($medicine->ID, '_medicine_price', true)); ?>" data-stock="<?php echo esc_attr(get_post_meta($medicine->ID, '_medicine_stock', true)); ?>"><?php echo esc_html($medicine->post_title); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <input type="number" name="items[0][quantity]" class="form-control quantity" min="1" value="1">
                                    </div>
                                    <div class="col-md-3">
                                        <input type="number" name="items[0][price]" class="form-control price" step="0.01" value="0">
                                    </div>
                                    <div class="col-md-2">
                                        <button type="button" class="btn btn-outline-danger remove-item">Remove</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-outline-primary mt-2" id="add-item">Add Item</button>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Total</label>
                        <input type="number" name="order_total" id="order_total" class="form-control" step="0.01" readonly>
                    </div>
                    <button type="submit" class="btn btn-primary">Save</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Cancel Order Modal -->
<div class="modal fade" id="cancelOrderModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Cancel Order</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="cancel-order-form" class="ajax-form">
                    <input type="hidden" name="order_id" id="cancel-order-id">
                    <input type="hidden" name="action" value="cancel_order">
                    <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('admin_panel_nonce'); ?>">
                    <div class="mb-3">
                        <label class="form-label">Cancellation Reason</label>
                        <textarea name="reason" id="cancel-reason" class="form-control" required></textarea>
                    </div>
                    <button type="submit" class="btn btn-danger">Cancel Order</button>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
    .table-responsive {
        overflow-x: auto;
    }

    .quick-status {
        width: 150px;
    }

    .item-row .row {
        align-items: center;
    }

    .gallery-image {
        display: inline-block;
        margin-right: 10px;
    }

    #order-timeline .apexcharts-timeline-series .apexcharts-timeline-marker {
        cursor: pointer;
    }
</style>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<script>
    $(document).ready(function() {
        function showAlert(message, type) {
            const alert = `<div class="alert alert-${type} alert-dismissible fade show">${message}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>`;
            $('.content').prepend(alert);
            setTimeout(() => $('.alert').remove(), 3000);
        }

        // Quick Status Update
        $('.quick-status').on('change', function() {
            const orderId = $(this).data('id');
            const status = $(this).val();
            $.ajax({
                url: '/admin-panel/inc/ajax-handler.php',
                type: 'POST',
                data: {
                    action: 'update_order_status',
                    order_id: orderId,
                    status: status,
                    nonce: '<?php echo wp_create_nonce('admin_panel_nonce'); ?>'
                },
                success: function(result) {
                    if (result.success) {
                        showAlert('Status updated', 'success');
                        sendStatusEmail(orderId, status);
                    } else {
                        showAlert(result.data.message, 'danger');
                    }
                }
            });
        });

        // View Order
        $('.view-order').on('click', function() {
            const id = $(this).data('id');
            $.ajax({
                url: '/admin-panel/api/order.php',
                type: 'GET',
                data: {
                    order_id: id
                },
                success: function(result) {
                    if (result.success) {
                        const order = result.data;
                        $('#view-order-number').text(order.title);
                        $('#view-customer').text(order.customer ? order.customer.username : 'Unknown');
                        $('#view-total').text(order.total);
                        $('#view-status').text(<?php echo json_encode($settings['order_statuses']); ?>[order.status] || order.status);
                        $('#view-date').text(order.date);
                        $('#view-delivery-fee').text(order.meta._delivery_fee || '<?php echo $settings['delivery_fee']; ?>');
                        $('#view-additional-fees').text(order.meta._additional_fees || '0');
                        $('#view-payment-method').text(<?php echo json_encode($settings['payment_methods']); ?>[order.payment_method] || order.payment_method);
                        $('#view-payment-status').text(order.payment ? order.payment.status : 'None');

                        // Items Table
                        let itemsHtml = '';
                        order.items.forEach(item => {
                            const subtotal = (item.quantity * item.price).toFixed(2);
                            itemsHtml += `
                                <tr>
                                    <td>${item.medicine_id} - ${getMedicineName(item.medicine_id)}</td>
                                    <td>${item.quantity}</td>
                                    <td>${item.price}</td>
                                    <td>${subtotal}</td>
                                </tr>`;
                        });
                        $('#view-items').html(itemsHtml);

                        // History Table
                        let historyHtml = '';
                        const history = order.meta._order_history ? JSON.parse(order.meta._order_history) : [];
                        history.forEach(entry => {
                            historyHtml += `
                                <tr>
                                    <td>${entry.date}</td>
                                    <td>${entry.action}</td>
                                    <td>${entry.details}</td>
                                    <td>${entry.user_id ? getUserName(entry.user_id) : 'System'}</td>
                                </tr>`;
                        });
                        $('#view-history').html(historyHtml);

                        // Timeline
                        const timelineData = history.map(entry => ({
                            x: entry.action,
                            y: new Date(entry.date).getTime(),
                            details: entry.details
                        }));
                        new ApexCharts(document.querySelector("#order-timeline"), {
                            chart: {
                                type: 'timeline',
                                height: 200
                            },
                            series: [{
                                data: timelineData
                            }],
                            xaxis: {
                                type: 'datetime'
                            },
                            colors: ['#007bff'],
                            tooltip: {
                                custom: function({
                                    series,
                                    seriesIndex,
                                    dataPointIndex
                                }) {
                                    const data = timelineData[dataPointIndex];
                                    return `<div class="p-2">${data.x}<br>${data.details}</div>`;
                                }
                            }
                        }).render();
                    }
                }
            });
        });

        // Edit Order
        $('.edit-order').on('click', function() {
            const id = $(this).data('id');
            $.ajax({
                url: '/admin-panel/api/order.php',
                type: 'GET',
                data: {
                    order_id: id
                },
                success: function(result) {
                    if (result.success) {
                        const order = result.data;
                        $('#order_id').val(id);
                        $('#form-action').val('edit_order');
                        $('#order_number').val(order.title);
                        $('#customer_id').val(order.meta._order_customer);
                        $('#order_status').val(order.meta._order_status);
                        $('#payment_method').val(order.meta._order_payment_method);
                        $('#delivery_fee').val(order.meta._delivery_fee || '<?php echo $settings['delivery_fee']; ?>');
                        $('#additional_fees').val(order.meta._additional_fees || '0');
                        $('#order_total').val(order.total);

                        // Populate Items
                        $('#order-items').empty();
                        order.items.forEach((item, index) => {
                            $('#order-items').append(`
                                <div class="item-row mb-2" data-index="${index}">
                                    <div class="row">
                                        <div class="col-md-5">
                                            <select name="items[${index}][medicine_id]" class="form-control medicine-select">
                                                <option value="">Select Medicine</option>
                                                <?php foreach ($medicines as $medicine): ?>
                                                    <option value="<?php echo $medicine->ID; ?>" data-price="<?php echo esc_attr(get_post_meta($medicine->ID, '_medicine_price', true)); ?>" data-stock="<?php echo esc_attr(get_post_meta($medicine->ID, '_medicine_stock', true)); ?>" ${item.medicine_id == <?php echo $medicine->ID; ?> ? 'selected' : ''}>
                                                        <?php echo esc_html($medicine->post_title); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <input type="number" name="items[${index}][quantity]" class="form-control quantity" min="1" value="${item.quantity}">
                                        </div>
                                        <div class="col-md-3">
                                            <input type="number" name="items[${index}][price]" class="form-control price" step="0.01" value="${item.price}">
                                        </div>
                                        <div class="col-md-2">
                                            <button type="button" class="btn btn-outline-danger remove-item">Remove</button>
                                        </div>
                                    </div>
                                </div>`);
                        });
                        updateTotal();
                    }
                }
            });
        });

        // Cancel Order
        $('.cancel-order').on('click', function() {
            const id = $(this).data('id');
            $('#cancel-order-id').val(id);
            $('#cancelOrderModal').modal('show');
        });

        $('#cancel-order-form').on('submit', function(e) {
            e.preventDefault();
            $.ajax({
                url: '/admin-panel/inc/ajax-handler.php',
                type: 'POST',
                data: $(this).serialize(),
                success: function(result) {
                    if (result.success) {
                        showAlert('Order cancelled', 'success');
                        $('#cancelOrderModal').modal('hide');
                        sendStatusEmail(result.data.order_id, 'cancelled');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showAlert(result.data.message, 'danger');
                    }
                }
            });
        });

        // Add Item
        let itemIndex = 1;
        $('#add-item').on('click', function() {
            $('#order-items').append(`
                <div class="item-row mb-2" data-index="${itemIndex}">
                    <div class="row">
                        <div class="col-md-5">
                            <select name="items[${itemIndex}][medicine_id]" class="form-control medicine-select">
                                <option value="">Select Medicine</option>
                                <?php foreach ($medicines as $medicine): ?>
                                    <option value="<?php echo $medicine->ID; ?>" data-price="<?php echo esc_attr(get_post_meta($medicine->ID, '_medicine_price', true)); ?>" data-stock="<?php echo esc_attr(get_post_meta($medicine->ID, '_medicine_stock', true)); ?>">
                                        <?php echo esc_html($medicine->post_title); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <input type="number" name="items[${itemIndex}][quantity]" class="form-control quantity" min="1" value="1">
                        </div>
                        <div class="col-md-3">
                            <input type="number" name="items[${itemIndex}][price]" class="form-control price" step="0.01" value="0">
                        </div>
                        <div class="col-md-2">
                            <button type="button" class="btn btn-outline-danger remove-item">Remove</button>
                        </div>
                    </div>
                </div>`);
            itemIndex++;
            updateTotal();
        });

        // Remove Item
        $(document).on('click', '.remove-item', function() {
            if ($('.item-row').length > 1) {
                $(this).closest('.item-row').remove();
                updateTotal();
            }
        });

        // Update Total
        function updateTotal() {
            let total = 0;
            $('.item-row').each(function() {
                const quantity = parseFloat($(this).find('.quantity').val()) || 0;
                const price = parseFloat($(this).find('.price').val()) || 0;
                total += quantity * price;
            });
            const deliveryFee = parseFloat($('#delivery_fee').val()) || 0;
            const additionalFees = parseFloat($('#additional_fees').val()) || 0;
            const taxRate = <?php echo $settings['tax_rate']; ?> / 100;
            total = total * (1 + taxRate) + deliveryFee + additionalFees;
            $('#order_total').val(total.toFixed(2));
        }

        $(document).on('change', '.medicine-select', function() {
            const price = $(this).find(':selected').data('price') || 0;
            $(this).closest('.item-row').find('.price').val(price);
            updateTotal();
        });

        $(document).on('input', '.quantity, .price, #delivery_fee, #additional_fees', updateTotal);

        // Validate Stock and Submit Order
        $('#order-form').on('submit', function(e) {
            e.preventDefault();
            const items = [];
            let valid = true;
            $('.item-row').each(function() {
                const medicineId = $(this).find('.medicine-select').val();
                const quantity = parseInt($(this).find('.quantity').val()) || 0;
                const stock = parseInt($(this).find('.medicine-select :selected').data('stock')) || 0;
                if (medicineId && quantity > 0) {
                    if (quantity > stock) {
                        showAlert(`Insufficient stock for medicine ID: ${medicineId}`, 'danger');
                        valid = false;
                    } else {
                        items.push({
                            medicine_id: medicineId,
                            quantity: quantity,
                            price: parseFloat($(this).find('.price').val()) || 0
                        });
                    }
                }
            });
            if (!valid || items.length === 0) {
                showAlert('Please add valid items with sufficient stock', 'danger');
                return;
            }
            const data = $(this).serializeArray();
            data.push({
                name: 'items',
                value: JSON.stringify(items)
            });
            $.ajax({
                url: '/admin-panel/inc/ajax-handler.php',
                type: 'POST',
                data: data,
                success: function(result) {
                    if (result.success) {
                        showAlert('Order saved', 'success');
                        $('#orderModal').modal('hide');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showAlert(result.data.message, 'danger');
                    }
                }
            });
        });

        // Export Orders
        $('#export-orders').on('click', function() {
            $.ajax({
                url: '/admin-panel/api/order.php',
                type: 'GET',
                data: {
                    page: 1,
                    per_page: 1000
                },
                success: function(result) {
                    if (result.success) {
                        const csv = ['ID,Order Number,Customer,Total,Status,Payment Method'];
                        result.data.orders.forEach(order => {
                            const customer = order.customer ? order.customer.username : 'Unknown';
                            const paymentMethod = <?php echo json_encode($settings['payment_methods']); ?>[order.payment_method] || order.payment_method;
                            csv.push(`${order.id},${order.title},${customer},${order.total},${order.status},${paymentMethod}`);
                        });
                        const blob = new Blob([csv.join('\n')], {
                            type: 'text/csv'
                        });
                        const link = document.createElement('a');
                        link.href = URL.createObjectURL(blob);
                        link.download = 'orders.csv';
                        link.click();
                    }
                }
            });
        });

        // Send Status Email
        function sendStatusEmail(orderId, status) {
            if (status in <?php echo json_encode($settings['email_templates']); ?>) {
                $.ajax({
                    url: '/admin-panel/inc/ajax-handler.php',
                    type: 'POST',
                    data: {
                        action: 'send_order_email',
                        order_id: orderId,
                        status: status,
                        nonce: '<?php echo wp_create_nonce('admin_panel_nonce'); ?>'
                    },
                    success: function(result) {
                        if (result.success) {
                            showAlert('Email sent', 'success');
                        }
                    }
                });
            }
        }

        // Helper Functions
        function getMedicineName(id) {
            const medicine = <?php echo json_encode(array_combine(wp_list_pluck($medicines, 'ID'), wp_list_pluck($medicines, 'post_title'))); ?>;
            return medicine[id] || 'Unknown';
        }

        function getUserName(id) {
            const users = <?php echo json_encode(array_combine(wp_list_pluck($users, 'ID'), wp_list_pluck($users, 'user_login'))); ?>;
            return users[id] || 'Unknown';
        }
    });
</script>
<?php include 'inc/footer.php'; ?>