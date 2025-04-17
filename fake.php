<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-load.php');
require_once('inc/auth.php');
require_once('inc/activity-log.php');
require_once('config.php');
require_login();

// Restrict to admins only
if (!current_user_can('manage_options')) {
    wp_die('You are not authorized to access this page');
}

// CHANGE: Check for Faker library
if (!class_exists('Faker\Factory')) {
    wp_die('Faker library not found. Please install it via Composer.');
}

// Include Faker library (assumes Composer installation)
require_once($_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php');

use Faker\Factory;

$settings = [
    'order_statuses' => get_option('mmsb_settings_order_statuses', ['pending' => 'Pending', 'processing' => 'Processing', 'shipped' => 'Shipped', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled']),
    'delivery_fee' => floatval(get_option('mmsb_settings_delivery_fee', 20)),
    'tax_rate' => floatval(get_option('mmsb_settings_tax_rate', 0)),
    'currency' => get_option('mmsb_settings_currency', 'SAR'),
];

// Handle form submission
$results = [];
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_fake_data'])) {
    // CHANGE: Use specific nonce
    check_admin_referer('fake_data_nonce');

    $faker = Factory::create();
    $data_types = array_filter($_POST['data_types'] ?? [], 'sanitize_text_field');
    $counts = array_map('intval', $_POST['counts'] ?? []);
    $current_user = wp_get_current_user();

    // Generate Medicine Categories
    if (!empty($data_types['categories'])) {
        $count = min($counts['categories'] ?? 5, 20);
        for ($i = 0; $i < $count; $i++) {
            $category_name = ucfirst($faker->word) . ' Medicine';
            $result = wp_insert_term($category_name, 'medicine_category', [
                'slug' => sanitize_title($category_name),
                'description' => $faker->sentence
            ]);
            if (!is_wp_error($result)) {
                $results['categories'][] = $category_name;
            } else {
                $errors[] = "Failed to create category: $category_name";
            }
        }
        log_activity($current_user->ID, 'generate_fake_categories', "Generated $count medicine categories", $_SERVER['REMOTE_ADDR']);
    }

    // Generate Medicines
    if (!empty($data_types['medicines'])) {
        $count = min($counts['medicines'] ?? 10, 100);
        $categories = get_terms(['taxonomy' => 'medicine_category', 'hide_empty' => false, 'fields' => 'ids']);
        for ($i = 0; $i < $count; $i++) {
            $post_id = wp_insert_post([
                'post_title' => $faker->words(2, true) . ' Tablet',
                'post_type' => 'medicine',
                'post_status' => 'publish',
                'post_content' => $faker->paragraph
            ]);
            if ($post_id) {
                $meta_data = [
                    '_medicine_price' => $faker->randomFloat(2, 5, 100),
                    '_medicine_stock' => $faker->numberBetween(10, 200),
                    '_medicine_sku' => $faker->uuid,
                    '_medicine_description' => $faker->paragraph
                ];
                update_post_meta_data($post_id, $meta_data);
                if (!empty($categories)) {
                    wp_set_post_terms($post_id, [$faker->randomElement($categories)], 'medicine_category');
                }
                $results['medicines'][] = $post_id;
            } else {
                $errors[] = "Failed to create medicine: " . $faker->words(2, true);
            }
        }
        log_activity($current_user->ID, 'generate_fake_medicines', "Generated $count medicines", $_SERVER['REMOTE_ADDR']);
    }

    // Generate Users
    if (!empty($data_types['users'])) {
        $count = min($counts['users'] ?? 10, 100);
        for ($i = 0; $i < $count; $i++) {
            $username = $faker->userName;
            $email = $faker->safeEmail;
            $user_id = wp_insert_user([
                'user_login' => $username,
                'user_email' => $email,
                'user_pass' => $faker->password,
                'role' => 'customer',
                'display_name' => $faker->name
            ]);
            if (!is_wp_error($user_id)) {
                update_user_meta($user_id, '_user_address', $faker->address);
                // ADD: Generate API token for Ionic app
                $token = wp_generate_password(32, false);
                update_user_meta($user_id, '_api_token', $token);
                update_user_meta($user_id, '_token_expiry', time() + 30 * 24 * 3600);
                $results['users'][] = $user_id;
            } else {
                $errors[] = "Failed to create user: $username";
            }
        }
        log_activity($current_user->ID, 'generate_fake_users', "Generated $count users", $_SERVER['REMOTE_ADDR']);
    }

    // Generate Orders
    if (!empty($data_types['orders'])) {
        $count = min($counts['orders'] ?? 10, 100);
        $users = get_users(['role' => 'customer', 'fields' => 'ID']);
        $medicines = get_posts(['post_type' => 'medicine', 'posts_per_page' => -1, 'fields' => 'ids']);
        if (empty($users) || empty($medicines)) {
            $errors[] = 'Generate users and medicines before creating orders';
        } else {
            for ($i = 0; $i < $count; $i++) {
                $items = [];
                $item_count = $faker->numberBetween(1, 3);
                $subtotal = 0;
                for ($j = 0; $j < $item_count; $j++) {
                    $medicine_id = $faker->randomElement($medicines);
                    $quantity = $faker->numberBetween(1, 5);
                    $price = floatval(get_post_meta($medicine_id, '_medicine_price', true)) ?: 10.00;
                    $subtotal += $quantity * $price;
                    $items[] = [
                        'medicine_id' => $medicine_id,
                        'quantity' => $quantity,
                        'price' => $price
                    ];
                }
                $delivery_fee = $settings['delivery_fee'];
                $tax = $subtotal * ($settings['tax_rate'] / 100);
                $total = $subtotal + $delivery_fee + $tax;

                $post_id = wp_insert_post([
                    'post_title' => 'ORDER-' . $faker->unique()->numberBetween(1000, 9999),
                    'post_type' => 'order',
                    'post_status' => 'publish',
                ]);
                if ($post_id) {
                    $meta_data = [
                        '_order_customer' => $faker->randomElement($users),
                        '_order_total' => $total,
                        '_order_status' => $faker->randomElement(array_keys($settings['order_statuses'])),
                        '_order_items' => maybe_serialize($items),
                        '_delivery_fee' => $delivery_fee,
                        '_additional_fees' => 0
                    ];
                    update_post_meta_data($post_id, $meta_data);
                    $history = [[
                        'date' => current_time('mysql'),
                        'action' => 'create_order',
                        'details' => 'Order created (fake data)',
                        'user_id' => $current_user->ID
                    ]];
                    update_post_meta($post_id, '_order_history', json_encode($history));
                    $results['orders'][] = $post_id;
                } else {
                    $errors[] = "Failed to create order: ORDER-" . $faker->numberBetween(1000, 9999);
                }
            }
            log_activity($current_user->ID, 'generate_fake_orders', "Generated $count orders", $_SERVER['REMOTE_ADDR']);
        }
    }

    // Generate Payments
    if (!empty($data_types['payments'])) {
        $count = min($counts['payments'] ?? 10, 100);
        $orders = get_posts(['post_type' => 'order', 'posts_per_page' => -1, 'fields' => 'ids']);
        if (empty($orders)) {
            $errors[] = 'Generate orders before creating payments';
        } else {
            for ($i = 0; $i < $count; $i++) {
                $order_id = $faker->randomElement($orders);
                $total = floatval(get_post_meta($order_id, '_order_total', true)) ?: 50.00;
                $post_id = wp_insert_post([
                    'post_title' => 'PAYMENT-' . $faker->unique()->numberBetween(1000, 9999),
                    'post_type' => 'payment',
                    'post_status' => 'publish',
                ]);
                if ($post_id) {
                    $meta_data = [
                        '_payment_amount' => $total,
                        '_payment_method' => $faker->randomElement(['credit_card', 'paypal', 'bank_transfer']),
                        '_payment_status' => $faker->randomElement(['Pending', 'Completed', 'Failed'])
                    ];
                    update_post_meta_data($post_id, $meta_data);
                    $results['payments'][] = $post_id;
                } else {
                    $errors[] = "Failed to create payment: PAYMENT-" . $faker->numberBetween(1000, 9999);
                }
            }
            log_activity($current_user->ID, 'generate_fake_payments', "Generated $count payments", $_SERVER['REMOTE_ADDR']);
        }
    }

    // Generate Tickets
    if (!empty($data_types['tickets'])) {
        $count = min($counts['tickets'] ?? 10, 100);
        $users = get_users(['role' => 'customer', 'fields' => 'ID']);
        if (empty($users)) {
            $errors[] = 'Generate users before creating tickets';
        } else {
            for ($i = 0; $i < $count; $i++) {
                $post_id = wp_insert_post([
                    'post_title' => 'Ticket: ' . $faker->sentence(4),
                    'post_type' => 'ticket',
                    'post_status' => 'publish',
                    'post_content' => $faker->paragraph
                ]);
                if ($post_id) {
                    $meta_data = [
                        '_ticket_status' => $faker->randomElement(['open', 'in_progress', 'closed']),
                        '_ticket_customer' => $faker->randomElement($users),
                        '_ticket_replies' => json_encode([[
                            'user_id' => $current_user->ID,
                            'content' => $faker->paragraph,
                            'date' => current_time('mysql')
                        ]])
                    ];
                    update_post_meta_data($post_id, $meta_data);
                    $results['tickets'][] = $post_id;
                } else {
                    $errors[] = "Failed to create ticket: " . $faker->sentence(4);
                }
            }
            log_activity($current_user->ID, 'generate_fake_tickets', "Generated $count tickets", $_SERVER['REMOTE_ADDR']);
        }
    }
}

include 'inc/header.php';
include 'inc/sidebar.php';
?>

<div class="content">
    <h1 class="mb-4 text-primary">Generate Fake Data</h1>
    <div class="card mb-4">
        <div class="card-body">
            <?php if (!empty($results) || !empty($errors)): ?>
                <?php if (!empty($results)): ?>
                    <div class="alert alert-success">
                        <strong>Success!</strong> Generated:
                        <ul>
                            <?php if (!empty($results['categories'])): ?>
                                <li><?php echo count($results['categories']); ?> Medicine Categories</li>
                            <?php endif; ?>
                            <?php if (!empty($results['medicines'])): ?>
                                <li><?php echo count($results['medicines']); ?> Medicines</li>
                            <?php endif; ?>
                            <?php if (!empty($results['users'])): ?>
                                <li><?php echo count($results['users']); ?> Users</li>
                            <?php endif; ?>
                            <?php if (!empty($results['orders'])): ?>
                                <li><?php echo count($results['orders']); ?> Orders</li>
                            <?php endif; ?>
                            <?php if (!empty($results['payments'])): ?>
                                <li><?php echo count($results['payments']); ?> Payments</li>
                            <?php endif; ?>
                            <?php if (!empty($results['tickets'])): ?>
                                <li><?php echo count($results['tickets']); ?> Tickets</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <strong>Errors:</strong>
                        <ul>
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo esc_html($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            <form method="POST">
                <!-- CHANGE: Use specific nonce -->
                <?php wp_nonce_field('fake_data_nonce'); ?>
                <input type="hidden" name="generate_fake_data" value="1">
                <div class="mb-3">
                    <h5>Select Data Types to Generate</h5>
                    <div class="form-check">
                        <input type="checkbox" name="data_types[categories]" id="generate_categories" class="form-check-input" value="1">
                        <label class="form-check-label" for="generate_categories">Medicine Categories</label>
                        <input type="number" name="counts[categories]" class="form-control mt-2" placeholder="Number of categories (1-20)" min="1" max="20" value="5" style="max-width: 200px;">
                    </div>
                    <div class="form-check">
                        <input type="checkbox" name="data_types[medicines]" id="generate_medicines" class="form-check-input" value="1">
                        <label class="form-check-label" for="generate_medicines">Medicines</label>
                        <input type="number" name="counts[medicines]" class="form-control mt-2" placeholder="Number of medicines (1-100)" min="1" max="100" value="10" style="max-width: 200px;">
                    </div>
                    <div class="form-check">
                        <input type="checkbox" name="data_types[users]" id="generate_users" class="form-check-input" value="1">
                        <label class="form-check-label" for="generate_users">Users</label>
                        <input type="number" name="counts[users]" class="form-control mt-2" placeholder="Number of users (1-100)" min="1" max="100" value="10" style="max-width: 200px;">
                    </div>
                    <div class="form-check">
                        <input type="checkbox" name="data_types[orders]" id="generate_orders" class="form-check-input" value="1">
                        <label class="form-check-label" for="generate_orders">Orders</label>
                        <input type="number" name="counts[orders]" class="form-control mt-2" placeholder="Number of orders (1-100)" min="1" max="100" value="10" style="max-width: 200px;">
                    </div>
                    <div class="form-check">
                        <input type="checkbox" name="data_types[payments]" id="generate_payments" class="form-check-input" value="1">
                        <label class="form-check-label" for="generate_payments">Payments</label>
                        <input type="number" name="counts[payments]" class="form-control mt-2" placeholder="Number of payments (1-100)" min="1" max="100" value="10" style="max-width: 200px;">
                    </div>
                    <div class="form-check">
                        <input type="checkbox" name="data_types[tickets]" id="generate_tickets" class="form-check-input" value="1">
                        <label class="form-check-label" for="generate_tickets">Tickets</label>
                        <input type="number" name="counts[tickets]" class="form-control mt-2" placeholder="Number of tickets (1-100)" min="1" max="100" value="10" style="max-width: 200px;">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Generate Data</button>
                <!-- ADD: Clear Fake Data button -->
                <button type="button" class="btn btn-danger" onclick="clearFakeData()">Clear Fake Data</button>
            </form>
        </div>
    </div>
</div>

<style>
    .form-check {
        margin-bottom: 15px;
    }

    .form-check-input {
        margin-right: 10px;
    }

    .alert {
        margin-bottom: 20px;
    }
</style>

<script>
    // ADD: Clear Fake Data function
    function clearFakeData() {
        if (!confirm('Are you sure you want to delete all fake data? This cannot be undone.')) return;
        $.ajax({
            url: '<?php echo admin_url('admin-ajax.php'); ?>',
            type: 'POST',
            data: {
                action: 'clear_fake_data',
                nonce: '<?php echo wp_create_nonce('fake_data_nonce'); ?>'
            },
            success: function(result) {
                if (result.success) {
                    showAlert(result.data.message, 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showAlert(result.data.message || 'Failed to clear fake data', 'danger');
                }
            },
            error: function() {
                showAlert('An error occurred while clearing fake data', 'danger');
            }
        });
    }
</script>

<?php include 'inc/footer.php'; ?>