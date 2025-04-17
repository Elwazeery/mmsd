<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-load.php');
require_once('../inc/api-utils.php');
require_once('../activity-log.php');
header('Content-Type: application/json');

$user = verify_api_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $user->ID;
    $username = sanitize_text_field($_POST['username']);
    if ($username !== $user->user_login && username_exists($username)) {
        wp_send_json_error(['message' => 'Username already exists', 'code' => 'username_exists'], 400);
    }
    $updated = wp_update_user([
        'ID' => $user_id,
        'user_login' => $username,
    ]);
    if (!is_wp_error($updated)) {
        if (!empty($_POST['password'])) {
            wp_set_password($_POST['password'], $user_id);
        }
        update_user_meta($user_id, '_user_address', sanitize_textarea_field($_POST['address']));
        log_activity($user_id, 'update_profile', "Updated profile for user ID: $user_id", $_SERVER['REMOTE_ADDR']);
        wp_send_json_success(['message' => 'Profile updated']);
    }
    log_activity($user_id, 'failed_update_profile', "Failed to update profile for user ID: $user_id", $_SERVER['REMOTE_ADDR']);
    wp_send_json_error(['message' => 'Failed to update profile', 'code' => 'server_error'], 400);
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    wp_send_json_success([
        'username' => $user->user_login,
        'email' => $user->user_email,
        'address' => get_user_meta($user->ID, '_user_address', true),
        'role' => $user->roles[0] ?? 'none',
        'registered' => $user->user_registered
    ]);
}
