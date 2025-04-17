<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-load.php');
require_once('auth.php');
require_once('activity-log.php');
require_login();

// Google Drive API setup (replace with your credentials)
define('GOOGLE_CLIENT_ID', 'your-client-id');
define('GOOGLE_CLIENT_SECRET', 'your-client-secret');
define('GOOGLE_REFRESH_TOKEN', 'your-refresh-token');
define('GOOGLE_FOLDER_ID', 'your-folder-id');

// Include Google API Client Library
if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    die('Google API Client Library is not installed. Run "composer require google/apiclient:^2.0".');
}
require_once __DIR__ . '/vendor/autoload.php';

function create_database_backup()
{
    global $wpdb;
    $upload_dir = wp_upload_dir();
    $backup_dir = $upload_dir['basedir'] . '/backups';
    $date = new DateTime('now', new DateTimeZone('Asia/Riyadh'));
    $backup_file = "$backup_dir/backup-{$date->format('Y-m-d')}.sql";

    if (!file_exists($backup_dir)) {
        mkdir($backup_dir, 0755, true);
    }

    $db_host = DB_HOST;
    $db_user = DB_USER;
    $db_pass = DB_PASSWORD;
    $db_name = DB_NAME;

    $command = "mysqldump --host=$db_host --user=$db_user --password=$db_pass $db_name > $backup_file 2>&1";
    exec($command, $output, $return_var);

    if ($return_var === 0 && file_exists($backup_file) && filesize($backup_file) > 0) {
        error_log("Backup created: $backup_file");
        log_activity(get_current_user_id(), 'create_backup', "Created database backup: $backup_file");
        return $backup_file;
    }

    error_log("Backup failed: Return code $return_var, Output: " . implode("\n", $output));
    log_activity(get_current_user_id(), 'create_backup_failed', "Failed to create backup: " . implode("; ", $output));
    return false;
}

function upload_to_google_drive($file_path)
{
    try {
        $client = new Google_Client();
        $client->setClientId(GOOGLE_CLIENT_ID);
        $client->setClientSecret(GOOGLE_CLIENT_SECRET);
        $client->setAccessType('offline');
        $client->setScopes(['https://www.googleapis.com/auth/drive.file']);
        $client->refreshToken(GOOGLE_REFRESH_TOKEN);

        $service = new \Google\Service\Drive($client);

        $file = new \Google\Service\Drive\DriveFile();
        $file->setName(basename($file_path));
        $file->setParents([GOOGLE_FOLDER_ID]);

        $result = $service->files->create($file, [
            'data' => file_get_contents($file_path),
            'mimeType' => 'application/octet-stream',
            'uploadType' => 'multipart'
        ]);

        log_activity(get_current_user_id(), 'upload_backup', "Uploaded backup to Google Drive: " . basename($file_path));
        return true;
    } catch (Exception $e) {
        error_log("Upload failed: " . $e->getMessage());
        log_activity(get_current_user_id(), 'upload_backup_failed', "Failed to upload backup: " . $e->getMessage());
        return false;
    }
}

function run_daily_backup()
{
    $backup_file = create_database_backup();
    if ($backup_file) {
        if (!upload_to_google_drive($backup_file)) {
            log_activity(get_current_user_id(), 'upload_backup_warning', "Backup saved locally: $backup_file");
            return;
        }
        unlink($backup_file);
    }
}

function get_recent_backups()
{
    try {
        $client = new Google_Client();
        $client->setClientId(GOOGLE_CLIENT_ID);
        $client->setClientSecret(GOOGLE_CLIENT_SECRET);
        $client->setAccessType('offline');
        $client->setScopes(['https://www.googleapis.com/auth/drive.file']);
        $client->refreshToken(GOOGLE_REFRESH_TOKEN);

        $service = new \Google\Service\Drive($client);
        $files = $service->files->listFiles([
            'q' => "'" . GOOGLE_FOLDER_ID . "' in parents",
            'orderBy' => 'createdTime desc',
            'pageSize' => 10
        ])->getFiles();

        $backups = [];
        foreach ($files as $file) {
            $backups[] = [
                'name' => $file->getName(),
                'date' => $file->getCreatedTime(),
                'link' => $file->getWebContentLink()
            ];
        }
        return $backups;
    } catch (Exception $e) {
        log_activity(get_current_user_id(), 'list_backups_failed', "Failed to list backups: " . $e->getMessage());
        return [];
    }
}

// Schedule daily backup
add_action('my_med_store_daily_backup', 'run_daily_backup');
if (!wp_next_scheduled('my_med_store_daily_backup')) {
    wp_schedule_event(strtotime('tomorrow 02:00', current_time('timestamp')), 'daily', 'my_med_store_daily_backup');
}

// AJAX handler for manual backup
if (isset($_POST['action']) && $_POST['action'] === 'manual_backup') {
    header('Content-Type: application/json');
    $nonce = $_POST['nonce'] ?? '';
    if (!wp_verify_nonce($nonce, 'admin_panel_nonce')) {
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }

    $backup_file = create_database_backup();
    if ($backup_file) {
        if (upload_to_google_drive($backup_file)) {
            unlink($backup_file);
            log_activity(get_current_user_id(), 'manual_backup', "Ran manual backup");
            wp_send_json_success(['message' => 'Backup created and uploaded']);
        }
        wp_send_json_error(['message' => 'Backup created but failed to upload']);
    }
    wp_send_json_error(['message' => 'Failed to create backup']);
}


function clean_old_local_backups($backup_dir, $days = 7)
{
    $files = glob("$backup_dir/*.sql");
    $now = time();
    foreach ($files as $file) {
        if (filemtime($file) < $now - $days * DAY_IN_SECONDS) {
            unlink($file);
        }
    }
}
add_action('my_med_store_daily_backup', function () {
    $upload_dir = wp_upload_dir();
    clean_old_local_backups($upload_dir['basedir'] . '/backups');
});

include 'header.php';
include 'sidebar.php';
$backups = get_recent_backups();
?>
<div class="content">
    <h1 class="mb-4 text-primary">Backups</h1>
    <div class="card mb-4">
        <div class="card-body p-4">
            <h5>Create Manual Backup</h5>
            <button id="manual-backup" class="btn btn-primary mb-3">Start Backup</button>
            <div id="backup-progress" class="progress" style="display: none;">
                <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%;" id="progress-bar"></div>
            </div>
            <div id="backup-status" class="mt-2"></div>
        </div>
    </div>
    <div class="card">
        <div class="card-body p-4">
            <h5>Recent Backups</h5>
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>File Name</th>
                        <th>Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($backups as $backup): ?>
                        <tr>
                            <td><?php echo esc_html($backup['name']); ?></td>
                            <td><?php echo esc_html(date_i18n('Y-m-d H:i:s', strtotime($backup['date']))); ?></td>
                            <td>
                                <a href="<?php echo esc_url($backup['link']); ?>" class="btn btn-sm btn-primary">Download</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    $(document).ready(function() {
        $('#manual-backup').on('click', function() {
            const $btn = $(this);
            $btn.prop('disabled', true);
            $('#backup-progress').show();
            $('#progress-bar').css('width', '30%').text('Creating backup...');
            $('#backup-status').text('');

            $.ajax({
                url: '/admin-panel/inc/backup.php',
                type: 'POST',
                data: {
                    action: 'manual_backup',
                    nonce: '<?php echo wp_create_nonce('admin_panel_nonce'); ?>'
                },
                success: function(result) {
                    if (result.success) {
                        $('#progress-bar').css('width', '100%').text('Completed!');
                        $('#backup-status').addClass('text-success').text(result.data.message);
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        $('#progress-bar').css('width', '0%').text('');
                        $('#backup-status').addClass('text-danger').text(result.data.message);
                    }
                },
                error: function() {
                    $('#progress-bar').css('width', '0%').text('');
                    $('#backup-status').addClass('text-danger').text('Connection error');
                },
                complete: function() {
                    $btn.prop('disabled', false);
                    setTimeout(() => {
                        $('#backup-progress').hide();
                        $('#progress-bar').css('width', '0%').text('');
                        $('#backup-status').removeClass('text-success text-danger').text('');
                    }, 3000);
                }
            });
        });
    });
</script>
<?php include 'footer.php'; ?>