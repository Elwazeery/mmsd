<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-load.php');
require_once('inc/auth.php');
require_once('inc/activity-log.php');
require_login();

function get_counters()
{
    global $wpdb;
    return [
        'medicines' => wp_count_posts('medicine')->publish,
        'orders' => wp_count_posts('order')->publish,
        'users' => $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->users"),
        'payments' => wp_count_posts('payment')->publish,
        'stock_value' => number_format($wpdb->get_var("SELECT SUM(meta_price.meta_value * meta_stock.meta_value) 
            FROM $wpdb->postmeta meta_price 
            JOIN $wpdb->postmeta meta_stock ON meta_price.post_id = meta_stock.post_id 
            WHERE meta_price.meta_key = '_medicine_price' 
            AND meta_stock.meta_key = '_medicine_stock'"), 2),
        'low_stock' => $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->postmeta WHERE meta_key = '_medicine_stock' AND meta_value < 10"),
    ];
}

function get_sales_data()
{
    global $wpdb;
    $data = $wpdb->get_results("SELECT DATE(post_date) as date, COUNT(*) as count, SUM(meta_value) as total 
        FROM $wpdb->posts p JOIN $wpdb->postmeta pm ON p.ID = pm.post_id 
        WHERE p.post_type = 'order' AND pm.meta_key = '_order_total' AND p.post_date > DATE_SUB(NOW(), INTERVAL 30 DAY) 
        GROUP BY DATE(post_date)");
    return ['dates' => array_column($data, 'date'), 'counts' => array_column($data, 'count'), 'totals' => array_column($data, 'total')];
}

function get_recent_activities()
{
    global $wpdb;
    return $wpdb->get_results("SELECT * FROM {$wpdb->prefix}activity_log ORDER BY timestamp DESC LIMIT 10");
}

$counters = get_counters();
$currency = get_option('mmsb_settings')['currency'] ?? 'USD';
$activities = get_recent_activities();
include 'inc/header.php';
include 'inc/sidebar.php';
?>

<div class="content">
    <h1 class="mb-4 text-primary">Dashboard</h1>
    <div class="row mb-4">
        <?php foreach (['medicines' => 'Medicines', 'orders' => 'Orders', 'users' => 'Users', 'payments' => 'Payments', 'stock_value' => 'Stock Value ($currency)', 'low_stock' => 'Low Stock'] as $key => $label): ?>
            <div class="col-md-2 col-sm-6 mb-3">
                <div class="card text-white bg-<?php echo ['primary', 'success', 'info', 'warning', 'danger', 'secondary'][array_search($key, array_keys($counters))]; ?> shadow">
                    <div class="card-body text-center">
                        <h6><?php echo $label; ?></h6>
                        <h3 class="counter" data-target="<?php echo $counters[$key]; ?>">0</h3>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="row">
        <div class="col-md-6 mb-4">
            <div class="card shadow">
                <div class="card-body">
                    <h5>Sales Overview</h5>
                    <div id="salesChart"></div>
                </div>
            </div>
        </div>
        <div class="col-md-6 mb-4">
            <div class="card shadow">
                <div class="card-body">
                    <h5>Stock Levels</h5>
                    <div id="stockChart"></div>
                </div>
            </div>
        </div>
    </div>
    <div class="card">
        <div class="card-body">
            <h5>Recent Activities</h5>
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Action</th>
                        <th>Details</th>
                        <th>Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($activities as $activity): ?>
                        <tr>
                            <td><?php echo esc_html(get_user_by('ID', $activity->user_id)->user_login ?? 'System'); ?></td>
                            <td><?php echo esc_html($activity->action); ?></td>
                            <td><?php echo esc_html($activity->details); ?></td>
                            <td><?php echo esc_html(date_i18n('Y-m-d H:i:s', strtotime($activity->timestamp))); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<script>
    $(document).ready(function() {
        $('.counter').each(function() {
            let $this = $(this),
                countTo = $this.attr('data-target');
            $({
                countNum: 0
            }).animate({
                countNum: countTo
            }, {
                duration: 2000,
                easing: 'swing',
                step: function() {
                    $this.text(Math.floor(this.countNum));
                },
                complete: function() {
                    $this.text(this.countNum);
                }
            });
        });

        let salesData = <?php echo json_encode(get_sales_data()); ?>;
        new ApexCharts(document.querySelector("#salesChart"), {
            chart: {
                type: 'line',
                height: 300
            },
            series: [{
                    name: 'Orders',
                    data: salesData.counts
                },
                {
                    name: 'Revenue (<?php echo $currency; ?>)',
                    data: salesData.totals
                }
            ],
            xaxis: {
                categories: salesData.dates
            },
            colors: ['#007bff', '#28a745']
        }).render();

        new ApexCharts(document.querySelector("#stockChart"), {
            chart: {
                type: 'bar',
                height: 300
            },
            series: [{
                name: 'Low Stock',
                data: [<?php echo $counters['low_stock']; ?>]
            }],
            xaxis: {
                categories: ['Low Stock Items']
            },
            colors: ['#dc3545']
        }).render();
    });
</script>
<?php include 'inc/footer.php'; ?>