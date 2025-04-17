<?php
ini_set('html_errors', 0);
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);
define('WP_DEBUG', true); // Enable debugging for development
define('WP_DEBUG_DISPLAY', false); // Log errors instead of displaying
define('WP_DEBUG_LOG', true); // Save errors to wp-content/debug.log

// Use dynamic paths
define('WP_PATH', ABSPATH); // ABSPATH is defined by wp-load.php
define('WP_URL', home_url('/'));
define('ADMIN_PATH', __DIR__ . '/'); // admin-panel/inc/
define('ADMIN_URL', WP_URL . 'admin-panel/');


require_once WP_PATH . 'wp-load.php';
require_once ADMIN_PATH . '../config.php'; // Your config.php
require_once ADMIN_PATH . '../inc/auth.php'; // Your auth.php
require_once ADMIN_PATH . '../inc/activity-log.php'; // Your activity-log.php
