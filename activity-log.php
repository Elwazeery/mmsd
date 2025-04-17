<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-load.php');
require_once('inc/auth.php');

// Logging Functions
function log_activity($user_id, $action, $details, $ip = null)
{
    $date = new DateTime('now', new DateTimeZone('UTC'));
    $year = $date->format('Y');
    $month = $date->format('m');
    $day = $date->format('d');

    $log_dir = WP_CONTENT_DIR . "/logs/$year/$month";
    $log_file = "$log_dir/$day.json";

    if (!file_exists($log_dir)) {
        mkdir($log_dir, 0755, true);
    }

    $log_entry = [
        'user_id' => intval($user_id),
        'action' => sanitize_text_field($action),
        'details' => sanitize_text_field($details),
        'timestamp' => $date->format('Y-m-d H:i:s'),
        'ip' => sanitize_text_field($ip ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown')
    ];

    $logs = file_exists($log_file) ? json_decode(file_get_contents($log_file), true) : [];
    $logs[] = $log_entry;

    file_put_contents($log_file, json_encode($logs, JSON_PRETTY_PRINT));
}

function get_activity_logs($year, $month, $day, $page = 1, $per_page = 10)
{
    $log_file = WP_CONTENT_DIR . "/logs/$year/$month/$day.json";
    $logs = file_exists($log_file) ? json_decode(file_get_contents($log_file), true) : [];

    $offset = ($page - 1) * $per_page;
    $paged_logs = array_slice($logs, $offset, $per_page);

    return [
        'logs' => $paged_logs,
        'total' => count($logs),
        'total_pages' => ceil(count($logs) / $per_page),
        'current_page' => $page
    ];
}

function clean_old_logs()
{
    $retention = intval(get_option('mmsb_settings_log_retention', 30));
    $log_dir = WP_CONTENT_DIR . '/logs/';
    $cutoff = strtotime("-$retention days");
    foreach (glob("$log_dir/*/*/*.json") as $file) {
        if (filemtime($file) < $cutoff) {
            unlink($file);
        }
    }
}
add_action('wp_scheduled_delete', 'clean_old_logs');
// Admin Panel Log Viewer
require_login();

$page = intval($_GET['paged'] ?? 1);
$per_page = 10;
$year = sanitize_text_field($_GET['year'] ?? date('Y'));
$month = sanitize_text_field($_GET['month'] ?? date('m'));
$day = sanitize_text_field($_GET['day'] ?? date('d'));

$log_data = get_activity_logs($year, $month, $day, $page, $per_page);
$logs = $log_data['logs'];
$total_pages = $log_data['total_pages'];

include 'inc/header.php';
include 'inc/sidebar.php';
?>

<div class="content">
    <h1 class="mb-4 text-primary">Activity Log</h1>
    <div class="card mb-4">
        <div class="card-body">
            <form class="mb-3">
                <div class="row">
                    <div class="col-md-3">
                        <input type="number" name="year" class="form-control" value="<?php echo esc_attr($year); ?>" placeholder="Year" min="2000" max="9999">
                    </div>
                    <div class="col-md-3">
                        <input type="number" name="month" class="form-control" value="<?php echo esc_attr($month); ?>" placeholder="Month" min="1" max="12">
                    </div>
                    <div class="col-md-3">
                        <input type="number" name="day" class="form-control" value="<?php echo esc_attr($day); ?>" placeholder="Day" min="1" max="31">
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary w-100">Filter</button>
                    </div>
                </div>
            </form>
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Action</th>
                        <th>Details</th>
                        <th>Timestamp</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?php echo esc_html(get_user_by('ID', $log['user_id'])->user_login ?? 'System'); ?></td>
                            <td><?php echo esc_html($log['action']); ?></td>
                            <td><?php echo esc_html($log['details']); ?></td>
                            <td><?php echo esc_html($log['timestamp']); ?></td>
                            <td><?php echo esc_html($log['ip']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($total_pages > 1): ?>
                <nav>
                    <ul class="pagination">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?paged=<?php echo $i; ?>&year=<?php echo urlencode($year); ?>&month=<?php echo urlencode($month); ?>&day=<?php echo urlencode($day); ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php include 'inc/footer.php'; ?>