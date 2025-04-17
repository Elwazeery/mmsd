<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-load.php');
$current_user = wp_get_current_user();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="/admin-panel/assets/css/custom.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@5.15.4/css/all.min.css" rel="stylesheet">
</head>

<body>
    <header class="header">
        <nav class="navbar navbar-expand-lg">
            <div class="container-fluid">
                <a class="navbar-brand text-white" href="index.php">
                    <img src="/admin-panel/assets/img/logo.png" alt="Logo" width="40" class="me-2">
                    Admin Panel
                </a>
                <div class="ms-auto d-flex align-items-center">
                    <span class="text-white me-3"><?php echo esc_html($current_user->display_name); ?></span>
                    <a href="logout.php" class="btn btn-outline-light">Logout</a>
                </div>
            </div>
        </nav>
    </header>
    <div class="d-flex">