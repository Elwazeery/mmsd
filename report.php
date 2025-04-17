<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-load.php');
require_once('inc/auth.php');
require_login();

function get_sales_report($start_date = '', $end_date = '')
{
    $args = [
        'post_type' => 'order',
        'posts_per_page' => -1,
        'post_status' => 'publish',
        'date_query' => [
            'after' => $start_date ?: date('Y-m-d', strtotime('-30 days')),
            'before' => $end_date ?: date('Y-m-d'),
            'inclusive' => true,
        ],
    ];
    $query = new WP_Query($args);
    $results = [];
    $by_date = [];
    foreach ($query->posts as $post) {
        $date = get_the_date('Y-m-d', $post);
        $total = floatval(get_post_meta($post->ID, '_order_total', true));
        if (!isset($by_date[$date])) {
            $by_date[$date] = ['count' => 0, 'total' => 0];
        }
        $by_date[$date]['count']++;
        $by_date[$date]['total'] += $total;
    }
    foreach ($by_date as $date => $data) {
        $results[] = (object)[
            'date' => $date,
            'count' => $data['count'],
            'total' => $data['total'],
        ];
    }
    usort($results, function ($a, $b) {
        return strtotime($a->date) - strtotime($b->date);
    });
    return $results;
}

function get_stock_report()
{
    $args = [
        'post_type' => 'medicine',
        'posts_per_page' => 10,
        'post_status' => 'publish',
        'meta_key' => '_medicine_stock',
        'orderby' => 'meta_value_num',
        'order' => 'ASC',
    ];
    $query = new WP_Query($args);
    $results = [];
    foreach ($query->posts as $post) {
        $results[] = (object)[
            'post_title' => $post->post_title,
            'stock' => intval(get_post_meta($post->ID, '_medicine_stock', true)),
        ];
    }
    return $results;
}

// CHANGE: Add date range filter
$start_date = sanitize_text_field($_GET['start_date'] ?? '');
$end_date = sanitize_text_field($_GET['end_date'] ?? '');
$sales = get_sales_report($start_date, $end_date);
$stock = get_stock_report();
// CHANGE: Use dynamic currency
$currency = get_option('mmsb_settings_currency', 'SAR');
include 'inc/header.php';
include 'inc/sidebar.php';
?>

<div class="content">
    <h1 class="mb-4 text-primary">Reports</h1>
    <!-- ADD: Date range filter -->
    <div class="card mb-4">
        <div class="card-body">
            <form class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Start Date</label>
                    <input type="date" name="start_date" class="form-control" value="<?php echo esc_attr($start_date); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">End Date</label>
                    <input type="date" name="end_date" class="form-control" value="<?php echo esc_attr($end_date); ?>">
                </div>
                <div class="col-md-4 align-self-end">
                    <button type="submit" class="btn btn-primary">Filter</button>
                    <!-- ADD: CSV export -->
                    <button type="button" class="btn btn-outline-success" onclick="exportCSV()">Export CSV</button>
                </div>
            </form>
        </div>
    </div>
    <div class="row">
        <div class="col-md-6 mb-4">
            <div class="card shadow">
                <div class="card-body">
                    <h5>Sales Report</h5>
                    <div id="salesReportChart"></div>
                </div>
            </div>
        </div>
        <div class="col-md-6 mb-4">
            <div class="card shadow">
                <div class="card-body">
                    <h5>Stock Report</h5>
                    <div id="stockReportChart"></div>
                </div>
            </div>
        </div>
    </div>
    <div class="card">
        <div class="card-body">
            <h5>Top Low Stock Items</h5>
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Medicine</th>
                        <th>Stock</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stock as $item): ?>
                        <tr>
                            <td><?php echo esc_html($item->post_title); ?></td>
                            <td><?php echo esc_html($item->stock); ?></td>
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
        const salesData = <?php echo json_encode(['dates' => array_column($sales, 'date'), 'counts' => array_column($sales, 'count'), 'totals' => array_column($sales, 'total')]); ?>;
        new ApexCharts(document.querySelector("#salesReportChart"), {
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

        const stockData = <?php echo json_encode(['names' => array_column($stock, 'post_title'), 'stocks' => array_column($stock, 'stock')]); ?>;
        new ApexCharts(document.querySelector("#stockReportChart"), {
            chart: {
                type: 'bar',
                height: 300
            },
            series: [{
                name: 'Stock',
                data: stockData.stocks
            }],
            xaxis: {
                categories: stockData.names
            },
            colors: ['#dc3545']
        }).render();

        // ADD: CSV export function
        window.exportCSV = function() {
            const sales = <?php echo json_encode($sales); ?>;
            let csv = 'Date,Orders,Revenue (<?php echo $currency; ?>)\n';
            sales.forEach(row => {
                csv += `${row.date},${row.count},${row.total}\n`;
            });
            const blob = new Blob([csv], {
                type: 'text/csv'
            });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'sales_report.csv';
            a.click();
            URL.revokeObjectURL(url);
        };
    });
</script>
<?php include 'inc/footer.php'; ?>