<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-load.php');
require_once('inc/auth.php');
require_once('activity-log.php');
require_login();

$page = intval($_GET['paged'] ?? 1);
$per_page = 10;

$terms = get_terms([
    'taxonomy' => 'medicine_category',
    'hide_empty' => false,
    'number' => $per_page,
    'offset' => ($page - 1) * $per_page
]);
$total_terms = wp_count_terms(['taxonomy' => 'medicine_category', 'hide_empty' => false]);
$total_pages = ceil($total_terms / $per_page);

include 'inc/header.php';
include 'inc/sidebar.php';
?>

<div class="content">
    <h1 class="mb-4 text-primary">Medicine Categories</h1>
    <div class="card mb-4">
        <div class="card-body">
            <button class="btn btn-success mb-3" data-bs-toggle="modal" data-bs-target="#categoryModal">Add Category</button>
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Slug</th>
                        <th>Description</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($terms as $term): ?>
                        <tr>
                            <td><?php echo esc_html($term->name); ?></td>
                            <td><?php echo esc_html($term->slug); ?></td>
                            <td><?php echo esc_html($term->description); ?></td>
                            <td>
                                <button class="btn btn-sm btn-primary edit-category" data-id="<?php echo $term->term_id; ?>" data-bs-toggle="modal" data-bs-target="#categoryModal">Edit</button>
                                <button class="btn btn-sm btn-danger delete-category" data-id="<?php echo $term->term_id; ?>">Delete</button>
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
                                <a class="page-link" href="?paged=<?php echo $i; ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="modal fade" id="categoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="category-form" class="ajax-form">
                    <input type="hidden" name="term_id" id="term_id">
                    <input type="hidden" name="action" id="form-action" value="add_category">
                    <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('admin_panel_nonce'); ?>">
                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input type="text" name="name" id="name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Slug</label>
                        <input type="text" name="slug" id="slug" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="description" class="form-control"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">Save</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    $(document).ready(function() {
        $('.edit-category').on('click', function() {
            const termId = $(this).data('id');
            $.ajax({
                url: '/admin-panel/inc/ajax-handler.php',
                type: 'POST',
                data: {
                    action: 'get_category',
                    term_id: termId,
                    nonce: '<?php echo wp_create_nonce('admin_panel_nonce'); ?>'
                },
                success: function(result) {
                    if (result.success) {
                        $('#term_id').val(termId);
                        $('#name').val(result.data.name);
                        $('#slug').val(result.data.slug);
                        $('#description').val(result.data.description);
                        $('#form-action').val('edit_category');
                        $('#categoryModal .modal-title').text('Edit Category');
                    }
                }
            });
        });

        $('.delete-category').on('click', function() {
            if (confirm('Are you sure you want to delete this category?')) {
                const termId = $(this).data('id');
                $.ajax({
                    url: '/admin-panel/inc/ajax-handler.php',
                    type: 'POST',
                    data: {
                        action: 'delete_category',
                        term_id: termId,
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

        $('#category-form').on('submit', function(e) {
            e.preventDefault();
            $.ajax({
                url: '/admin-panel/inc/ajax-handler.php',
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