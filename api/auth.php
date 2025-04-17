<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-load.php');
require_once('../inc/api-utils.php');
require_once('../activity-log.php');
header('Content-Type: application/json');

function rate_limit($action, $ip, $limit = 5, $period = 3600)
{
    $key = "rate_limit:$action:$ip";
    $count = get_transient($key);
    if (false === $count) {
        set_transient($key, 1, $period);
        return true;
    }
    if ($count >= $limit) {
        return false;
    }
    set_transient($key, $count + 1, $period);
    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = sanitize_text_field($_POST['action'] ?? '');
    $ip = $_SERVER['REMOTE_ADDR'];

    switch ($action) {
        case 'login':
            if (!rate_limit('login', $ip)) {
                wp_send_json_error(['message' => 'Too many login attempts', 'code' => 'rate_limit_exceeded'], 429);
            }
            $user = wp_signon([
                'user_login' => sanitize_text_field($_POST['username']),
                'user_password' => $_POST['password']
            ]);
            if (!is_wp_error($user)) {
                $token = wp_generate_password(32, false);
                update_user_meta($user->ID, '_api_token', $token);
                update_user_meta($user->ID, '_token_expiry', time() + 30 * 24 * 3600); // 30 days
                log_activity($user->ID, 'login', "User ID: $user->ID logged in", $ip);
                wp_send_json_success(['token' => $token, 'user_id' => $user->ID]);
            }
            log_activity(0, 'failed_login', "Failed login attempt for username: " . sanitize_text_field($_POST['username']), $ip);
            wp_send_json_error(['message' => 'Invalid credentials', 'code' => 'invalid_credentials'], 401);
            break;

        case 'request_reset':
            if (!rate_limit('request_reset', $ip)) {
                wp_send_json_error(['message' => 'Too many reset requests', 'code' => 'rate_limit_exceeded'], 429);
            }
            $email = sanitize_email($_POST['email']);
            $user = get_user_by('email', $email);
            if ($user) {
                $token = wp_generate_password(32, false);
                $expiry = time() + 24 * 3600; // 24 hours
                update_user_meta($user->ID, '_reset_token', $token);
                update_user_meta($user->ID, '_reset_expiry', $expiry);

                $business_name = get_option('mmsb_settings_business_name', 'My Pharmacy');
                $business_email = get_option('mmsb_settings_business_email', 'no-reply@example.com');

                $reset_link = add_query_arg(['token' => $token, 'email' => $email], home_url('/reset-password'));
                $subject = 'Password Reset Request';
                $message = "<p>Hello {$user->user_login},</p>";
                $message .= "<p>We received a request to reset your password. Click the link below to reset it:</p>";
                $message .= "<p><a href='$reset_link'>Reset Password</a></p>";
                $message .= "<p>This link will expire in 24 hours.</p>";
                $message .= "<p>If you did not request this, please ignore this email.</p>";
                $message .= "<p>Regards,<br>$business_name</p>";

                $headers = ["Content-Type: text/html; charset=UTF-8", "From: $business_name <$business_email>"];
                wp_mail($email, $subject, $message, $headers);

                log_activity($user->ID, 'request_password_reset', "Password reset requested for user ID: $user->ID", $ip);
                wp_send_json_success(['message' => 'Password reset link sent']);
            }
            wp_send_json_error(['message' => 'Email not found', 'code' => 'email_not_found'], 404);
            break;

        case 'reset_password':
            $token = sanitize_text_field($_POST['token']);
            $new_password = $_POST['new_password'];
            $email = sanitize_email($_POST['email']);

            if (!preg_match('/^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d]{8,}$/', $new_password)) {
                wp_send_json_error(['message' => 'Password must be at least 8 characters with at least one letter and one number', 'code' => 'weak_password'], 400);
            }

            $user = get_user_by('email', $email);
            if ($user) {
                $stored_token = get_user_meta($user->ID, '_reset_token', true);
                $expiry = get_user_meta($user->ID, '_reset_expiry', true);

                if ($stored_token === $token && $expiry > time()) {
                    wp_set_password($new_password, $user->ID);
                    delete_user_meta($user->ID, '_reset_token');
                    delete_user_meta($user->ID, '_reset_expiry');

                    $business_name = get_option('mmsb_settings_business_name', 'My Pharmacy');
                    $business_email = get_option('mmsb_settings_business_email', 'no-reply@example.com');
                    $subject = 'Password Reset Successful';
                    $message = "<p>Hello {$user->user_login},</p>";
                    $message .= "<p>Your password has been successfully reset.</p>";
                    $message .= "<p>If you did not perform this action, please contact support immediately.</p>";
                    $message .= "<p>Regards,<br>$business_name</p>";
                    $headers = ["Content-Type: text/html; charset=UTF-8", "From: $business_name <$business_email>"];
                    wp_mail($email, $subject, $message, $headers);

                    log_activity($user->ID, 'password_reset', "Password reset for user ID: $user->ID", $ip);
                    wp_send_json_success(['message' => 'Password reset successfully']);
                }
                log_activity(0, 'failed_password_reset', "Invalid or expired token for email: $email", $ip);
                wp_send_json_error(['message' => 'Invalid or expired token', 'code' => 'invalid_token'], 400);
            }
            wp_send_json_error(['message' => 'User not found', 'code' => 'user_not_found'], 404);
            break;

        default:
            wp_send_json_error(['message' => 'Invalid action', 'code' => 'invalid_action'], 400);
    }
}
