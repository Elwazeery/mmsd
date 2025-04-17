<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-load.php');
require_once('activity-log.php');

function require_login()
{
    if (!is_user_logged_in()) {
        wp_redirect('/admin-panel/login.php');
        exit;
    }
    if (!current_user_can('manage_options') && !current_user_can('moderate_content')) {
        wp_die('You are not authorized to access this page');
    }
}

function login_user($username, $password)
{
    $transient_key = 'login_attempts_' . md5($username . $_SERVER['REMOTE_ADDR']);
    $attempts = get_transient($transient_key) ?: 0;
    if ($attempts >= 5) {
        wp_send_json_error(['message' => 'Too many login attempts. Try again in 15 minutes.']);
    }
    $credentials = [
        'user_login' => sanitize_text_field($username),
        'user_password' => $password,
        'remember' => false
    ];
    $user = wp_signon($credentials, false);
    if (is_wp_error($user)) {
        set_transient($transient_key, $attempts + 1, 15 * MINUTE_IN_SECONDS);
        log_activity(0, 'failed_login', "Failed login attempt for: $username");
        wp_send_json_error(['message' => 'Invalid credentials.']);
    }
    delete_transient($transient_key);
    wp_set_current_user($user->ID);
    wp_set_auth_cookie($user->ID);
    log_activity($user->ID, 'login', "User logged in: $username");
    return true;
}

if (isset($_POST['action']) && $_POST['action'] === 'login') {
    header('Content-Type: application/json');
    $nonce = $_POST['nonce'] ?? '';
    if (!wp_verify_nonce($nonce, 'admin_panel_nonce')) {
        wp_send_json_error(['message' => 'Nonce verification failed']);
    }
    if (login_user($_POST['username'], $_POST['password'])) {
        wp_send_json_success(['message' => 'Login successful']);
    }
    wp_send_json_error(['message' => 'Invalid username or password']);
}
