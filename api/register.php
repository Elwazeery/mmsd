<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-load.php');
require_once('../activity-log.php');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitize_text_field($_POST['username']);
    $email = sanitize_email($_POST['email']);
    $password = $_POST['password'];
    $address = sanitize_textarea_field($_POST['address'] ?? '');

    // Validate inputs
    if (empty($username) || empty($email) || empty($password)) {
        log_activity(0, 'failed_register', "Missing required fields", $_SERVER['REMOTE_ADDR']);
        wp_send_json_error(['message' => 'Missing required fields', 'code' => 'missing_fields'], 400);
    }
    if (username_exists($username) || email_exists($email)) {
        log_activity(0, 'failed_register', "Username or email already exists: $username/$email", $_SERVER['REMOTE_ADDR']);
        wp_send_json_error(['message' => 'Username or email already exists', 'code' => 'user_exists'], 409);
    }
    if (!preg_match('/^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d]{8,}$/', $password)) {
        log_activity(0, 'failed_register', "Weak password for username: $username", $_SERVER['REMOTE_ADDR']);
        wp_send_json_error(['message' => 'Password must be at least 8 characters with at least one letter and one number', 'code' => 'weak_password'], 400);
    }

    $user_id = wp_insert_user([
        'user_login' => $username,
        'user_email' => $email,
        'user_pass' => $password,
        'role' => 'subscriber',
    ]);

    if (!is_wp_error($user_id)) {
        update_user_meta($user_id, '_user_address', $address);
        $token = wp_generate_password(32, false);
        update_user_meta($user_id, '_api_token', $token);
        update_user_meta($user_id, '_token_expiry', time() + 30 * 24 * 3600); // 30 days

        // Send welcome email
        $business_name = get_option('mmsb_settings_business_name', 'My Pharmacy');
        $business_email = get_option('mmsb_settings_business_email', 'no-reply@example.com');
        $subject = 'Welcome to ' . $business_name;
        $message = "<p>Hello $username,</p>";
        $message .= "<p>Welcome to $business_name! Your account has been created successfully.</p>";
        $message .= "<p>Start exploring our medicines and place your orders in the app.</p>";
        $message .= "<p>Regards,<br>$business_name</p>";
        $headers = ["Content-Type: text/html; charset=UTF-8", "From: $business_name <$business_email>"];
        wp_mail($email, $subject, $message, $headers);

        log_activity($user_id, 'register', "Registered new user ID: $user_id", $_SERVER['REMOTE_ADDR']);
        wp_send_json_success(['user_id' => $user_id, 'token' => $token]);
    }

    log_activity(0, 'failed_register', "Failed to register user: $username", $_SERVER['REMOTE_ADDR']);
    wp_send_json_error(['message' => 'Failed to register user', 'code' => 'server_error'], 500);
}
