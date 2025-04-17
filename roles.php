<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-load.php');
require_once('inc/auth.php');
require_once('activity-log.php');
require_login();

$page = intval($_GET['paged'] ?? 1);
$per_page = 10;
// CHANGE: Added search and sort parameters
$search = sanitize_text_field($_GET['s'] ?? '');
$sort = sanitize_text_field($_GET['sort'] ?? 'login_asc');
$selected_role = sanitize_text_field($_GET['role'] ?? '');

$admin_roles = ['administrator', 'editor']; // Add custom roles as needed
$query_args = [
    'role__in' => $selected_role ? [$selected_role] : $admin_roles,
    'number' => $per_page,
    'offset' => ($page - 1) * $per_page,
];
if ($search) {
    $query_args['search'] = '*' . $search . '*';
}
if ($sort) {
    if (in_array($sort, ['login_asc', 'login_desc'])) {
        $query_args['orderby'] = 'user_login';
        $query_args['order'] = $sort === 'login_asc' ? 'ASC' : 'DESC';
    } elseif (in_array($sort, ['email_asc', 'email_desc'])) {
        $query_args['orderby'] = 'user_email';
        $query_args['order'] = $sort === 'email_asc' ? 'ASC' : 'DESC';
    }
}
$users = get_users($query_args);
$total_users = count(get_users(array_merge($query_args, ['number' => -1])));
$total_pages = ceil($total_users / $per_page);

$wp_roles = wp_roles();
$roles = $wp_roles->get_names();

include 'inc/header.php';
include 'inc/sidebar.php';
?>

<div class="content">
    <h1 class="mb-4 text-primary">Admins & Moderators</h1>
    <div class="card mb-4">
        <div class="card-body">
            <!-- ADD: Role filter and search form -->
            <div class="row mb-3">
                <div class="col-md-4">
                    <form id="user-search" class="d-flex">
                        <input type="text" name="s" class="form-control me-2" placeholder="Search users..." value="<?php echo esc_attr($search); ?>">
                        <button type="submit" class="btn btn-outline-primary">Search</button>
                    </form>
                </div>
                <div class="col-md-4">
                    <select class="form-control" onchange="location.href='?role=' + this.value">
                        <option value="">All Roles</option>
                        <?php foreach ($admin_roles as $role): ?>
                            <option value="<?php echo $role; ?>" <?php selected($selected_role, $role); ?>><?php echo esc_html($roles[$role] ?? $role); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <select class="form-control" onchange="location.href='?sort=' + this.value">
                        <option value="login_asc" <?php selected($sort, 'login_asc'); ?>>Username (A-Z)</option>
                        <option value="login_desc" <?php selected($sort, 'login_desc'); ?>>Username (Z-A)</option>
                        <option value="email_asc" <?php selected($sort, 'email_asc'); ?>>Email (A-Z)</option>
                        <option value="email_desc" <?php selected($sort, 'email_desc'); ?>>Email (Z-A)</option>
                    </select>
                </div>
            </div>
            <button class="btn btn-success mb-3" data-bs-toggle="modal" data-bs-target="#userModal">Add User</button>
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Address</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?php echo esc_html($user->user_login); ?></td>
                            <td><?php echo esc_html($user->user_email); ?></td>
                            <td><?php echo esc_html($roles[$user->roles[0]] ?? $user->roles[0]); ?></td>
                            <td><?php echo esc_html(get_user_meta($user->ID, '_user_address', true)); ?></td>
                            <td>
                                <button class="btn btn-sm btn-primary edit-user" data-id="<?php echo $user->ID; ?>" data-bs-toggle="modal" data-bs-target="#userModal">Edit</button>
                                <?php if ($user->ID !== get_current_user_id() && current_user_can('manage_options')): ?>
                                    <button class="btn btn-sm btn-danger delete-user" data-id="<?php echo $user->ID; ?>">Delete</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($total_pages > 1): ?>
                <nav>
                    <ul class="pagination">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?paged=<?php echo $i; ?>&s=<?php echo urlencode($search); ?>&sort=<?php echo $sort; ?>&role=<?php echo $selected_role; ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="modal fade" id="userModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="user-form" class="ajax-form">
                    <input type="hidden" name="user_id" id="user_id">
                    <input type="hidden" name="action" id="form-action" value="add_user">
                    <!-- CHANGE: Use specific nonce -->
                    <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('user_nonce'); ?>">
                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" name="username" id="username" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" id="email" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <input type="password" name="password" id="password" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Role</label>
                        <select name="role" id="role" class="form-control" required>
                            <?php foreach ($admin_roles as $role): ?>
                                <option value="<?php echo $role; ?>"><?php echo esc_html($roles[$role] ?? $role); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <textarea name="address" id="address" class="form-control"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">Save</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    $(document).ready(function() {
        $('.edit-user').on('click', function() {
            const userId = $(this).data('id');
            $.ajax({
                // CHANGE: Use dynamic AJAX URL
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: {
                    action: 'get_user',
                    user_id: userId,
                    nonce: '<?php echo wp_create_nonce('user_nonce'); ?>'
                },
                success: function(result) {
                    if (result.success) {
                        $('#user_id').val(userId);
                        $('#username').val(result.data.username);
                        $('#email').val(result.data.email);
                        $('#role').val(result.data.role);
                        $('#address').val(result.data.address);
                        $('#password').prop('required', false);
                        $('#form-action').val('edit_user');
                        $('#userModal .modal-title').text('Edit User');
                    }
                }
            });
        });

        $('.delete-user').on('click', function() {
            if (confirm('Are you sure you want to delete this user?')) {
                const userId = $(this).data('id');
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'delete_user',
                        user_id: userId,
                        nonce: '<?php echo wp_create_nonce('user_nonce'); ?>'
                    },
                    success: function(result) {
                        if (result.success) {
                            showAlert(result.data.message, 'success');
                            setTimeout(() => location.reload(), 1000);
                        } else {
                            showAlert(result.data.message, 'danger');
                        }
                    }
                });
            }
        });

        $('#user-form').on('submit', function(e) {
            e.preventDefault();
            $.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: $(this).serialize(),
                success: function(result) {
                    if (result.success) {
                        showAlert(result.data.message, 'success');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showAlert(result.data.message, 'danger');
                    }
                }
            });
        });
    });
</script>
<?php include 'inc/footer.php'; ?>