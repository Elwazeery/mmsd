<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-load.php');
require_once('../activity-log.php');

function verify_api_token()
{
    $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer (.+)/', $token, $matches)) {
        $token = $matches[1];
        global $wpdb;
        $user_id = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM $wpdb->usermeta WHERE meta_key = '_api_token' AND meta_value = %s", $token));
        if ($user_id) {
            $expiry = get_user_meta($user_id, '_token_expiry', true);
            if ($expiry && $expiry > time()) {
                return get_user_by('ID', $user_id);
            }
            log_activity(0, 'invalid_token', "Expired token used", $_SERVER['REMOTE_ADDR']);
            wp_send_json_error(['message' => 'Token expired', 'code' => 'token_expired'], 401);
        }
    }
    log_activity(0, 'invalid_token', "Invalid or missing token", $_SERVER['REMOTE_ADDR']);
    wp_send_json_error(['message' => 'Invalid or missing token', 'code' => 'invalid_token'], 401);
    exit;
}
