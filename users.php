<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-load.php');
require_once('inc/auth.php');
require_login();
$page = intval($_GET['paged'] ?? 1);
$per_page = 10;
$search = sanitize_text_field($_GET['s'] ?? '');
$args = ['number' => $per_page, 'paged' => $page];
if ($search) {
    $args['search'] = '*' . $search . '*';
}
$users_query = new WP_User_Query($args);
$users = $users_query->get_results();
$total_pages = ceil($users_query->get_total() / $per_page);
$roles = wp_roles()->get_names();
include 'inc/header.php';
include 'inc/sidebar.php';
?>

<div class="content">
    <h1 class="mb-4 text-primary">Users</h1>
    <div class="card mb-4">
        <div class="card-body">
            <form id="user-search" class="mb-3">
                <div class="input-group">
                    <input type="text" name="s" class="form-control" placeholder="Search users..." value="<?php echo esc_attr($search); ?>">
                    <button type="submit" class="btn btn-primary">Search</button>
                </div>
            </form>
            <button class="btn btn-success mb-3" data-bs-toggle="modal" data-bs-target="#userModal">Add User</button>
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?php echo $user->ID; ?></td>
                            <td><?php echo esc_html($user->user_login); ?></td>
                            <td><?php echo esc_html($user->user_email); ?></td>
                            <td><?php echo esc_html($roles[$user->roles[0]] ?? 'None'); ?></td>
                            <td>
                                <button class="btn btn-sm btn-primary edit-user" data-id="<?php echo $user->ID; ?>" data-bs-toggle="modal" data-bs-target="#userModal">Edit</button>
                                <button class="btn btn-sm btn-danger delete-user" data-id="<?php echo $user->ID; ?>">Delete</button>
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
                                <a class="page-link" href="?paged=<?php echo $i; ?>&s=<?php echo urlencode($search); ?>"><?php echo $i; ?></a>
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
                    <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('admin_panel_nonce'); ?>">
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
                        <input type="password" name="password" id="password" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Role</label>
                        <select name="role" id="role" class="form-control">
                            <?php foreach ($roles as $role_key => $role_name): ?>
                                <option value="<?php echo $role_key; ?>"><?php echo $role_name; ?></option>
                            <?php endforeach; ?>
                        </select>
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
            const id = $(this).data('id');
            $.ajax({
                url: '/admin-panel/api/user.php',
                type: 'GET',
                data: {
                    user_id: id
                },
                success: function(result) {
                    if (result.success) {
                        $('#user_id').val(id);
                        $('#form-action').val('edit_user');
                        $('#username').val(result.data.username);
                        $('#email').val(result.data.email);
                        $('#role').val(result.data.role);
                        $('#password').removeAttr('required');
                    }
                }
            });
        });

        $('.delete-user').on('click', function() {
            if (confirm('Are you sure you want to delete this user?')) {
                const id = $(this).data('id');
                $.ajax({
                    url: '/admin-panel/inc/ajax-handler.php',
                    type: 'POST',
                    data: {
                        action: 'delete_user',
                        user_id: id,
                        nonce: '<?php echo wp_create_nonce('admin_panel_nonce'); ?>'
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
    });
</script>
<?php include 'inc/footer.php'; ?>