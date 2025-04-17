<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-load.php');
// ADD: Nonce verification
$nonce = $_GET['nonce'] ?? '';
if (!wp_verify_nonce($nonce, 'logout_nonce')) {
    wp_die('Invalid logout request');
}
// ADD: Log logout action
require_once('activity-log.php');
log_activity(get_current_user_id(), 'logout', 'User logged out', $_SERVER['REMOTE_ADDR']);
wp_logout();
// CHANGE: Use absolute URL for redirect
wp_redirect(home_url('/admin-panel/login.php'));
exit;
