<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-load.php');
if (is_user_logged_in()) {
    wp_redirect('index.php');
    exit;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    // CHANGE: Use specific nonce
    $nonce = $_POST['nonce'] ?? '';
    if (!wp_verify_nonce($nonce, 'login_nonce')) {
        $error = 'Security check failed';
    } else {
        require_once('inc/auth.php');
        // CHANGE: Capture specific error from login_user
        $result = login_user($_POST['username'], $_POST['password']);
        if ($result === true) {
            wp_redirect('index.php');
            exit;
        } else {
            $error = $result ?: 'Invalid credentials';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- CHANGE: Add fallback for custom.css -->
    <link href="/admin-panel/assets/css/custom.css" rel="stylesheet" onerror="this.href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css';">
</head>

<body>
    <div class="content container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-4">
                <div class="card shadow">
                    <div class="card-body">
                        <h3 class="card-title text-center mb-4">Admin Login</h3>
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?php echo esc_html($error); ?></div>
                        <?php endif; ?>
                        <form id="login-form" class="ajax-form">
                            <input type="hidden" name="action" value="login">
                            <!-- CHANGE: Use specific nonce -->
                            <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('login_nonce'); ?>">
                            <div class="mb-3">
                                <label for="username" class="form-label">Username</label>
                                <input type="text" class="form-control" id="username" name="username" required>
                            </div>
                            <div class="mb-3">
                                <label for="password" class="form-label">Password</label>
                                <input type="password" class="form-control" id="password" name="password" required>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Login</button>
                            <!-- ADD: Forgot Password link -->
                            <div class="text-center mt-3">
                                <a href="/admin-panel/reset-password.php">Forgot Password?</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- CHANGE: Add fallback for custom.js -->
    <script src="/admin-panel/assets/js/custom.js" onerror="console.warn('Custom JS failed to load');"></script>
</body>

</html>