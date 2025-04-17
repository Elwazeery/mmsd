<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-load.php');
require_once('activity-log.php');
$current_page = basename($_SERVER['PHP_SELF']);
$current_user = wp_get_current_user();

// Fetch dynamic counters
$pending_orders = count(get_posts([
    'post_type' => 'order',
    'post_status' => 'publish',
    'meta_query' => [['key' => '_order_status', 'value' => 'Pending']]
]));
$new_tickets = count(get_posts([
    'post_type' => 'ticket',
    'post_status' => 'publish',
    'meta_query' => [['key' => '_ticket_status', 'value' => 'Open']]
]));
?>
<div class="sidebar bg-gradient-primary shadow-sm">
    <div class="sidebar-header p-3 text-center">
        <h4 class="text-white mb-0">Pharmacy Admin</h4>
        <button class="btn btn-link text-white" id="toggleSidebar">
            <i class="fas fa-bars"></i>
        </button>
    </div>
    <ul class="nav flex-column mt-3">
        <!-- Dashboard -->
        <li class="nav-item">
            <a class="nav-link <?php echo $current_page === 'index.php' ? 'active' : ''; ?>" href="index.php">
                <i class="fas fa-tachometer-alt me-2"></i> <span>Dashboard</span>
            </a>
        </li>

        <!-- Management Section -->
        <li class="nav-item">
            <a class="nav-link collapsed" data-bs-toggle="collapse" href="#managementMenu" aria-expanded="false">
                <i class="fas fa-boxes me-2"></i> <span>Management</span>
                <i class="fas fa-chevron-down ms-auto"></i>
            </a>
            <div class="collapse" id="managementMenu">
                <ul class="nav flex-column ms-3">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $current_page === 'medicines.php' ? 'active' : ''; ?>" href="medicines.php">
                            <i class="fas fa-pills me-2"></i> Medicines
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $current_page === 'taxonomies.php' ? 'active' : ''; ?>" href="taxonomies.php">
                            <i class="fas fa-tags me-2"></i> Categories
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $current_page === 'order.php' ? 'active' : ''; ?>" href="order.php">
                            <i class="fas fa-shopping-cart me-2"></i> Orders
                            <?php if ($pending_orders): ?>
                                <span class="badge bg-danger ms-2"><?php echo $pending_orders; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $current_page === 'payments.php' ? 'active' : ''; ?>" href="payments.php">
                            <i class="fas fa-credit-card me-2"></i> Payments
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $current_page === 'tickets.php' ? 'active' : ''; ?>" href="tickets.php">
                            <i class="fas fa-ticket-alt me-2"></i> Tickets
                            <?php if ($new_tickets): ?>
                                <span class="badge bg-danger ms-2"><?php echo $new_tickets; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                </ul>
            </div>
        </li>

        <!-- Users Section -->
        <li class="nav-item">
            <a class="nav-link collapsed" data-bs-toggle="collapse" href="#usersMenu" aria-expanded="false">
                <i class="fas fa-users me-2"></i> <span>Users</span>
                <i class="fas fa-chevron-down ms-auto"></i>
            </a>
            <div class="collapse" id="usersMenu">
                <ul class="nav flex-column ms-3">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $current_page === 'users.php' ? 'active' : ''; ?>" href="users.php">
                            <i class="fas fa-user me-2"></i> All Users
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $current_page === 'roles.php' ? 'active' : ''; ?>" href="roles.php">
                            <i class="fas fa-user-shield me-2"></i> Admins & Mods
                        </a>
                    </li>
                </ul>
            </div>
        </li>

        <!-- System Section -->
        <li class="nav-item">
            <a class="nav-link collapsed" data-bs-toggle="collapse" href="#systemMenu" aria-expanded="false">
                <i class="fas fa-cogs me-2"></i> <span>System</span>
                <i class="fas fa-chevron-down ms-auto"></i>
            </a>
            <div class="collapse" id="systemMenu">
                <ul class="nav flex-column ms-3">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $current_page === 'settings.php' ? 'active' : ''; ?>" href="settings.php">
                            <i class="fas fa-cog me-2"></i> Settings
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $current_page === 'reports.php' ? 'active' : ''; ?>" href="reports.php">
                            <i class="fas fa-chart-bar me-2"></i> Reports
                        </a>
                    </li>
                </ul>
            </div>
        </li>

        <!-- Logs Section -->
        <li class="nav-item">
            <a class="nav-link <?php echo $current_page === 'activity-log.php' ? 'active' : ''; ?>" href="activity-log.php">
                <i class="fas fa-history me-2"></i> <span>Activity Log</span>
            </a>
        </li>

        <!-- User Actions -->
        <li class="nav-item mt-3 border-top pt-3">
            <a class="nav-link" href="?action=logout">
                <i class="fas fa-sign-out-alt me-2"></i> <span>Logout</span>
            </a>
        </li>
    </ul>
</div>

<style>
    .bg-gradient-primary {
        background: linear-gradient(180deg, #007bff 0%, #0056b3 100%);
    }

    .sidebar {
        min-height: 100vh;
        transition: all 0.3s;
        width: 250px;
    }

    .sidebar.collapsed {
        width: 60px;
    }

    .sidebar.collapsed .sidebar-header h4,
    .sidebar.collapsed .nav-link span,
    .sidebar.collapsed .fa-chevron-down {
        display: none;
    }

    .sidebar.collapsed .nav-link {
        text-align: center;
    }

    .nav-link {
        color: #fff !important;
        transition: background 0.2s;
    }

    .nav-link:hover {
        background: rgba(255, 255, 255, 0.1);
    }

    .nav-link.active {
        background: rgba(255, 255, 255, 0.2) !important;
    }

    .sidebar .collapse .nav-link {
        font-size: 0.9em;
        padding-left: 2.5rem;
    }

    .badge {
        font-size: 0.75em;
    }
</style>

<script>
    $(document).ready(function() {
        $('#toggleSidebar').on('click', function() {
            $('.sidebar').toggleClass('collapsed');
            $('.content').toggleClass('expanded');
        });

        $('.nav-link').on('click', function() {
            const page = $(this).attr('href').split('/').pop();
            if (page && page !== '#') {
                $.ajax({
                    url: '/admin-panel/inc/ajax-handler.php',
                    type: 'POST',
                    data: {
                        action: 'log_navigation',
                        page: page,
                        nonce: '<?php echo wp_create_nonce('admin_panel_nonce'); ?>'
                    }
                });
            }
        });

        // Ensure submenus are open for active pages
        const activeLink = $('.nav-link.active').closest('.collapse');
        if (activeLink.length) {
            activeLink.addClass('show');
            activeLink.prev().removeClass('collapsed').attr('aria-expanded', 'true');
        }
    });
</script>
<link href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@5.15.4/css/all.min.css" rel="stylesheet">
<?php
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    log_activity($current_user->ID, 'logout', 'User logged out', $_SERVER['REMOTE_ADDR']);
    wp_logout();
    wp_redirect('login.php');
    exit;
}
?>