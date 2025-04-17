<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-load.php');
require_once('inc/auth.php');
require_once('inc/activity-log.php');
require_login();

// Restrict to admins only
if (!current_user_can('manage_options')) {
    wp_die('You are not authorized to access this page');
}

// Load settings with fallback
$settings = [
    'delivery_fee' => floatval(get_option('mmsb_settings_delivery_fee', 20)),
    'currency' => get_option('mmsb_settings_currency', 'SAR'),
    'stock_alert' => intval(get_option('mmsb_settings_stock_alert', 10)),
    'tax_rate' => floatval(get_option('mmsb_settings_tax_rate', 0)),
    'business_name' => get_option('mmsb_settings_business_name', 'My Pharmacy'),
    'business_email' => get_option('mmsb_settings_business_email', ''),
    'business_phone' => get_option('mmsb_settings_business_phone', ''),
    'business_logo' => get_option('mmsb_settings_business_logo', ''),
    'backup_schedule' => get_option('mmsb_settings_backup_schedule', 'daily'),
    'dark_mode' => get_option('mmsb_settings_dark_mode', '0'),
    'sms_enabled' => get_option('mmsb_settings_sms_enabled', '0'),
    'sms_api_key' => get_option('mmsb_settings_sms_api_key', ''),
    'sms_sender_id' => get_option('mmsb_settings_sms_sender_id', ''),
    'maintenance_mode' => get_option('mmsb_settings_maintenance_mode', '0'),
    'log_retention' => intval(get_option('mmsb_settings_log_retention', 30)),
    'email_templates' => get_option('mmsb_settings_email_templates', [
        'shipped' => "<p>Hello, {customer_name}</p><p>Your order #{order_id} was shipped on {date}.</p><p>Details: {order_details}</p>",
        'delivered' => "<p>Hello, {customer_name}</p><p>Your order #{order_id} was successfully delivered.</p><p>Thank you for shopping with us!</p>",
        'cancelled' => "<p>Hello, {customer_name}</p><p>Your order #{order_id} has been cancelled. Reason: {cancel_reason}</p><p>Contact us for assistance.</p>"
    ]),
    'order_statuses' => get_option('mmsb_settings_order_statuses', ['pending' => 'Pending', 'processing' => 'Processing', 'shipped' => 'Shipped', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled']),
    'payment_methods' => get_option('mmsb_settings_payment_methods', ['cod' => 'Cash on Delivery', 'vodafone_cash' => 'Vodafone Cash', 'instapay' => 'Instapay']),
    'custom_fields_orders' => get_option('mmsb_settings_custom_fields_orders', []),
    'custom_fields_medicines' => get_option('mmsb_settings_custom_fields_medicines', [])
];

include 'inc/header.php';
include 'inc/sidebar.php';
?>

<div class="content">
    <h1 class="mb-4 text-primary">Settings</h1>
    <div class="card">
        <div class="card-body">
            <ul class="nav nav-tabs mb-4" id="settingsTabs" role="tablist">
                <li class="nav-item">
                    <a class="nav-link active" id="general-tab" data-bs-toggle="tab" href="#general" role="tab">General</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="orders-tab" data-bs-toggle="tab" href="#orders" role="tab">Orders</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="emails-tab" data-bs-toggle="tab" href="#emails" role="tab">Emails</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="integrations-tab" data-bs-toggle="tab" href="#integrations" role="tab">Integrations</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="custom-fields-tab" data-bs-toggle="tab" href="#custom-fields" role="tab">Custom Fields</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="logs-tab" data-bs-toggle="tab" href="#logs" role="tab">Logs</a>
                </li>
            </ul>
            <form id="settings-form" class="ajax-form" enctype="multipart/form-data">
                <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('settings_nonce'); ?>">
                <input type="hidden" name="action" value="save_settings">
                <div class="tab-content">
                    <!-- General Settings -->
                    <div class="tab-pane fade show active" id="general" role="tabpanel">
                        <div class="mb-3">
                            <label class="form-label">Pharmacy Name <span data-bs-toggle="tooltip" title="Appears in emails and customer invoices"><i class="fas fa-info-circle"></i></span></label>
                            <input type="text" name="business_name" class="form-control" value="<?php echo esc_attr($settings['business_name']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="business_email" class="form-control" value="<?php echo esc_attr($settings['business_email']); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Phone Number</label>
                            <input type="text" name="business_phone" class="form-control" value="<?php echo esc_attr($settings['business_phone']); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Pharmacy Logo</label>
                            <input type="file" name="business_logo" class="form-control" accept="image/*">
                            <?php if ($settings['business_logo']): ?>
                                <img src="<?php echo esc_url($settings['business_logo']); ?>" alt="Logo" width="100" class="mt-2 rounded">
                            <?php endif; ?>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Currency</label>
                            <select name="currency" class="form-control">
                                <option value="SAR" <?php selected($settings['currency'], 'SAR'); ?>>Saudi Riyal (SAR)</option>
                                <option value="USD" <?php selected($settings['currency'], 'USD'); ?>>US Dollar (USD)</option>
                                <option value="AED" <?php selected($settings['currency'], 'AED'); ?>>UAE Dirham (AED)</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input type="checkbox" name="dark_mode" class="form-check-input" value="1" <?php checked($settings['dark_mode'], '1'); ?>>
                                <label class="form-check-label">Enable Dark Mode</label>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input type="checkbox" name="maintenance_mode" class="form-check-input" value="1" <?php checked($settings['maintenance_mode'], '1'); ?>>
                                <label class="form-check-label">Maintenance Mode (Restricts access to admins)</label>
                            </div>
                        </div>
                        <button type="button" class="btn btn-outline-warning" onclick="resetSection('general')">Reset General Settings</button>
                    </div>
                    <!-- Orders Settings -->
                    <div class="tab-pane fade" id="orders" role="tabpanel">
                        <div class="mb-3">
                            <label class="form-label">Default Delivery Fee (<?php echo esc_html($settings['currency']); ?>) <span data-bs-toggle="tooltip" title="Applied to new orders"><i class="fas fa-info-circle"></i></span></label>
                            <input type="number" name="delivery_fee" class="form-control" value="<?php echo esc_attr($settings['delivery_fee']); ?>" min="0" step="0.01">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Tax Rate (%)</label>
                            <input type="number" name="tax_rate" class="form-control" value="<?php echo esc_attr($settings['tax_rate']); ?>" min="0" step="0.1">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Low Stock Alert Threshold</label>
                            <input type="number" name="stock_alert" class="form-control" value="<?php echo esc_attr($settings['stock_alert']); ?>" min="0">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Order Statuses</label>
                            <div id="order-statuses">
                                <?php foreach ($settings['order_statuses'] as $key => $label): ?>
                                    <div class="input-group mb-2">
                                        <input type="text" name="order_statuses[<?php echo esc_attr($key); ?>]" class="form-control" value="<?php echo esc_attr($label); ?>">
                                        <button type="button" class="btn btn-outline-danger" onclick="removeStatus(this)">Remove</button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="btn btn-outline-primary mt-2" onclick="addStatus()">Add Status</button>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Payment Methods</label>
                            <div id="payment-methods">
                                <?php foreach ($settings['payment_methods'] as $key => $label): ?>
                                    <div class="input-group mb-2">
                                        <input type="text" name="payment_methods[<?php echo esc_attr($key); ?>]" class="form-control" value="<?php echo esc_attr($label); ?>">
                                        <button type="button" class="btn btn-outline-danger" onclick="removePaymentMethod(this)">Remove</button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="btn btn-outline-primary mt-2" onclick="addPaymentMethod()">Add Payment Method</button>
                        </div>
                        <button type="button" class="btn btn-outline-warning" onclick="resetSection('orders')">Reset Orders Settings</button>
                    </div>
                    <!-- Email Settings -->
                    <div class="tab-pane fade" id="emails" role="tabpanel">
                        <div class="mb-3">
                            <label class="form-label">Shipped Email Template</label>
                            <textarea name="email_templates[shipped]" class="form-control" rows="5"><?php echo esc_textarea($settings['email_templates']['shipped']); ?></textarea>
                            <small>Variables: {customer_name}, {order_id}, {date}, {order_details}</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Delivered Email Template</label>
                            <textarea name="email_templates[delivered]" class="form-control" rows="5"><?php echo esc_textarea($settings['email_templates']['delivered']); ?></textarea>
                            <small>Variables: {customer_name}, {order_id}, {date}, {order_details}</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Cancelled Email Template</label>
                            <textarea name="email_templates[cancelled]" class="form-control" rows="5"><?php echo esc_textarea($settings['email_templates']['cancelled']); ?></textarea>
                            <small>Variables: {customer_name}, {order_id}, {date}, {order_details}, {cancel_reason}</small>
                        </div>
                        <div class="mb-3">
                            <button type="button" class="btn btn-outline-info" onclick="previewEmail('shipped')">Preview Shipped Email</button>
                            <button type="button" class="btn btn-outline-info" onclick="previewEmail('delivered')">Preview Delivered Email</button>
                            <button type="button" class="btn btn-outline-info" onclick="previewEmail('cancelled')">Preview Cancelled Email</button>
                        </div>
                        <button type="button" class="btn btn-outline-warning" onclick="resetSection('emails')">Reset Email Settings</button>
                    </div>
                    <!-- Integrations Settings -->
                    <div class="tab-pane fade" id="integrations" role="tabpanel">
                        <div class="mb-3">
                            <label class="form-label">Backup Schedule</label>
                            <select name="backup_schedule" class="form-control">
                                <option value="daily" <?php selected($settings['backup_schedule'], 'daily'); ?>>Daily</option>
                                <option value="weekly" <?php selected($settings['backup_schedule'], 'weekly'); ?>>Weekly</option>
                                <option value="monthly" <?php selected($settings['backup_schedule'], 'monthly'); ?>>Monthly</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input type="checkbox" name="sms_enabled" class="form-check-input" value="1" <?php checked($settings['sms_enabled'], '1'); ?>>
                                <label class="form-check-label">Enable SMS Notifications</label>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">SMS API Key (username:password)</label>
                            <input type="text" name="sms_api_key" class="form-control" value="<?php echo esc_attr($settings['sms_api_key']); ?>">
                            <small>Format: username:password from SMS Misr settings</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">SMS Sender ID</label>
                            <input type="text" name="sms_sender_id" class="form-control" value="<?php echo esc_attr($settings['sms_sender_id']); ?>">
                            <small>Enter your SMS Misr sender ID</small>
                        </div>
                        <div class="mb-3">
                            <button type="button" class="btn btn-outline-info" onclick="testSMS()">Test SMS</button>
                        </div>
                        <button type="button" class="btn btn-outline-warning" onclick="resetSection('integrations')">Reset Integrations Settings</button>
                    </div>
                    <!-- Custom Fields Settings -->
                    <div class="tab-pane fade" id="custom-fields" role="tabpanel">
                        <h5>Custom Fields for Orders</h5>
                        <div id="custom-fields-orders" class="mb-4">
                            <?php foreach ($settings['custom_fields_orders'] as $index => $field): ?>
                                <div class="custom-field-row mb-3" data-index="<?php echo $index; ?>">
                                    <div class="row">
                                        <div class="col-md-3">
                                            <input type="text" name="custom_fields_orders[<?php echo $index; ?>][key]" class="form-control" value="<?php echo esc_attr($field['key']); ?>" placeholder="Field Key (e.g., delivery_instructions)" required pattern="[a-z0-9_]+">
                                        </div>
                                        <div class="col-md-3">
                                            <input type="text" name="custom_fields_orders[<?php echo $index; ?>][label]" class="form-control" value="<?php echo esc_attr($field['label']); ?>" placeholder="Field Label (e.g., Delivery Instructions)" required>
                                        </div>
                                        <div class="col-md-3">
                                            <select name="custom_fields_orders[<?php echo $index; ?>][type]" class="form-control">
                                                <option value="text" <?php selected($field['type'], 'text'); ?>>Text</option>
                                                <option value="number" <?php selected($field['type'], 'number'); ?>>Number</option>
                                                <option value="select" <?php selected($field['type'], 'select'); ?>>Dropdown</option>
                                                <option value="checkbox" <?php selected($field['type'], 'checkbox'); ?>>Checkbox</option>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <input type="text" name="custom_fields_orders[<?php echo $index; ?>][options]" class="form-control" value="<?php echo esc_attr($field['options'] ?? ''); ?>" placeholder="Dropdown options (comma-separated)">
                                            <button type="button" class="btn btn-outline-danger mt-2" onclick="removeCustomField(this, 'orders')">Remove</button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="btn btn-outline-primary mb-4" onclick="addCustomField('orders')">Add Order Field</button>

                        <h5>Custom Fields for Medicines</h5>
                        <div id="custom-fields-medicines">
                            <?php foreach ($settings['custom_fields_medicines'] as $index => $field): ?>
                                <div class="custom-field-row mb-3" data-index="<?php echo $index; ?>">
                                    <div class="row">
                                        <div class="col-md-3">
                                            <input type="text" name="custom_fields_medicines[<?php echo $index; ?>][key]" class="form-control" value="<?php echo esc_attr($field['key']); ?>" placeholder="Field Key (e.g., prescription_required)" required pattern="[a-z0-9_]+">
                                        </div>
                                        <div class="col-md-3">
                                            <input type="text" name="custom_fields_medicines[<?php echo $index; ?>][label]" class="form-control" value="<?php echo esc_attr($field['label']); ?>" placeholder="Field Label (e.g., Prescription Required)" required>
                                        </div>
                                        <div class="col-md-3">
                                            <select name="custom_fields_medicines[<?php echo $index; ?>][type]" class="form-control">
                                                <option value="text" <?php selected($field['type'], 'text'); ?>>Text</option>
                                                <option value="number" <?php selected($field['type'], 'number'); ?>>Number</option>
                                                <option value="select" <?php selected($field['type'], 'select'); ?>>Dropdown</option>
                                                <option value="checkbox" <?php selected($field['type'], 'checkbox'); ?>>Checkbox</option>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <input type="text" name="custom_fields_medicines[<?php echo $index; ?>][options]" class="form-control" value="<?php echo esc_attr($field['options'] ?? ''); ?>" placeholder="Dropdown options (comma-separated)">
                                            <button type="button" class="btn btn-outline-danger mt-2" onclick="removeCustomField(this, 'medicines')">Remove</button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="btn btn-outline-primary" onclick="addCustomField('medicines')">Add Medicine Field</button>
                        <button type="button" class="btn btn-outline-warning mt-4" onclick="resetSection('custom-fields')">Reset Custom Fields</button>
                    </div>
                    <!-- Logs Settings -->
                    <div class="tab-pane fade" id="logs" role="tabpanel">
                        <div class="mb-3">
                            <label class="form-label">Log Retention Period (Days)</label>
                            <input type="number" name="log_retention" class="form-control" value="<?php echo esc_attr($settings['log_retention']); ?>" min="1">
                        </div>
                        <div class="mb-3">
                            <h5>Recent Changes</h5>
                            <div class="table-responsive">
                                <table class="table table-sm table-striped">
                                    <thead>
                                        <tr>
                                            <th>Timestamp</th>
                                            <th>Action</th>
                                            <th>Details</th>
                                            <th>IP</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $all_logs = [];
                                        $log_base = WP_CONTENT_DIR . '/logs/';
                                        for ($i = 0; $i < 7; $i++) {
                                            $date = date('Y/m/d', strtotime("-$i days"));
                                            $file = $log_base . $date . '.json';
                                            if (file_exists($file) && is_readable($file)) {
                                                $content = file_get_contents($file);
                                                $logs = json_decode($content, true) ?: [];
                                                if (json_last_error() === JSON_ERROR_NONE) {
                                                    $logs = array_filter($logs, function ($log) {
                                                        return strpos($log['action'], 'update_settings') !== false || strpos($log['action'], 'custom_fields') !== false;
                                                    });
                                                    $all_logs = array_merge($all_logs, $logs);
                                                }
                                            }
                                        }
                                        usort($all_logs, function ($a, $b) {
                                            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
                                        });
                                        $all_logs = array_slice($all_logs, 0, 5);
                                        foreach ($all_logs as $log):
                                        ?>
                                            <tr>
                                                <td><?php echo esc_html($log['timestamp']); ?></td>
                                                <td><?php echo esc_html($log['action']); ?></td>
                                                <td><?php echo esc_html($log['details']); ?></td>
                                                <td><?php echo esc_html($log['ip'] ?? 'Unknown'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <button type="button" class="btn btn-outline-warning" onclick="resetSection('logs')">Reset Logs Settings</button>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary mt-4">Save Changes</button>
            </form>
        </div>
    </div>
</div>

<!-- Email Preview Modal -->
<div class="modal fade" id="emailPreviewModal" tabindex="-1" aria-labelledby="emailPreviewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="emailPreviewModalLabel">Email Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <iframe id="emailPreviewFrame" style="width:100%;height:400px;border:none;"></iframe>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<style>
    .nav-tabs .nav-link {
        color: #004aad;
    }

    .nav-tabs .nav-link.active {
        background-color: #e9ecef;
        border-color: #004aad;
    }

    .tab-pane {
        padding: 20px 0;
    }

    .table-responsive {
        overflow-x: auto;
    }

    .table th,
    .table td {
        vertical-align: middle;
    }

    .custom-field-row .row {
        align-items: center;
    }

    .custom-field-row .btn {
        width: 100%;
    }
</style>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://kit.fontawesome.com/a076d05399.js"></script>
<script>
    $(document).ready(function() {
        const toast = new bootstrap.Toast($('<div class="toast"><div class="toast-body"></div></div>').appendTo('body')[0]);

        function showToast(message, type = 'success') {
            $('.toast').removeClass('bg-success bg-danger').addClass(`bg-${type}`);
            $('.toast-body').text(message);
            toast.show();
        }

        function addStatus() {
            const key = 'status_' + Date.now();
            $('#order-statuses').append(`
                <div class="input-group mb-2">
                    <input type="text" name="order_statuses[${key}]" class="form-control" value="">
                    <button type="button" class="btn btn-outline-danger" onclick="removeStatus(this)">Remove</button>
                </div>
            `);
        }

        function addPaymentMethod() {
            const key = 'method_' + Date.now();
            $('#payment-methods').append(`
                <div class="input-group mb-2">
                    <input type="text" name="payment_methods[${key}]" class="form-control" value="">
                    <button type="button" class="btn btn-outline-danger" onclick="removePaymentMethod(this)">Remove</button>
                </div>
            `);
        }

        window.addStatus = addStatus;
        window.removeStatus = function(btn) {
            $(btn).closest('.input-group').remove();
        };

        window.addPaymentMethod = addPaymentMethod;
        window.removePaymentMethod = function(btn) {
            $(btn).closest('.input-group').remove();
        };

        let customFieldIndexOrders = <?php echo count($settings['custom_fields_orders']); ?>;
        let customFieldIndexMedicines = <?php echo count($settings['custom_fields_medicines']); ?>;

        window.addCustomField = function(type) {
            const index = type === 'orders' ? customFieldIndexOrders++ : customFieldIndexMedicines++;
            const container = $(`#custom-fields-${type}`);
            container.append(`
                <div class="custom-field-row mb-3" data-index="${index}">
                    <div class="row">
                        <div class="col-md-3">
                            <input type="text" name="custom_fields_${type}[${index}][key]" class="form-control" placeholder="Field Key" required pattern="[a-z0-9_]+">
                        </div>
                        <div class="col-md-YOU ARE HERE3">
                            <input type="text" name="custom_fields_${type}[${index}][label]" class="form-control" placeholder="Field Label" required>
                        </div>
                        <div class="col-md-3">
                            <select name="custom_fields_${type}[${index}][type]" class="form-control">
                                <option value="text">Text</option>
                                <option value="number">Number</option>
                                <option value="select">Dropdown</option>
                                <option value="checkbox">Checkbox</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <input type="text" name="custom_fields_${type}[${index}][options]" class="form-control" placeholder="Dropdown options (comma-separated)">
                            <button type="button" class="btn btn-outline-danger mt-2" onclick="removeCustomField(this, '${type}')">Remove</button>
                        </div>
                    </div>
                </div>
            `);
        };

        window.removeCustomField = function(btn, type) {
            $(btn).closest('.custom-field-row').remove();
        };

        window.resetSection = function(section) {
            if (!confirm('Are you sure you want to reset the ' + section + ' settings?')) return;
            $.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: {
                    action: 'reset_settings',
                    section: section,
                    nonce: '<?php echo wp_create_nonce('settings_nonce'); ?>'
                },
                success: function(result) {
                    if (result.success) {
                        showToast('Settings reset successfully');
                        location.reload();
                    } else {
                        showToast('Failed to reset settings: ' + result.data.message, 'danger');
                    }
                }
            });
        };

        window.previewEmail = function(type) {
            const template = $(`textarea[name="email_templates[${type}]"]`).val();
            $.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: {
                    action: 'preview_email',
                    template: template,
                    type: type,
                    nonce: '<?php echo wp_create_nonce('settings_nonce'); ?>'
                },
                success: function(result) {
                    if (result.success) {
                        const iframe = $('#emailPreviewFrame')[0];
                        iframe.contentWindow.document.open();
                        iframe.contentWindow.document.write(result.data.html);
                        iframe.contentWindow.document.close();
                        $('#emailPreviewModal').modal('show');
                    } else {
                        showToast('Failed to preview email', 'danger');
                    }
                }
            });
        };

        window.testSMS = function() {
            $.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: {
                    action: 'test_sms',
                    nonce: '<?php echo wp_create_nonce('settings_nonce'); ?>'
                },
                success: function(result) {
                    if (result.success) {
                        showToast(result.data.message, 'success');
                    } else {
                        showToast('Failed to send test SMS: ' + result.data.message, 'danger');
                    }
                }
            });
        };

        $('[data-bs-toggle="tooltip"]').tooltip();
    });
</script>

<?php include 'inc/footer.php'; ?>