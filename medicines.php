<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-load.php');
require_once('inc/auth.php');
require_login();
$page = intval($_GET['paged'] ?? 1);
$per_page = 10;
$search = sanitize_text_field($_GET['s'] ?? '');
$medicines = get_paginated_posts_data('medicine', $search, $page, $per_page);
$categories = get_terms(['taxonomy' => 'medicine_category', 'hide_empty' => false]);
include 'inc/header.php';
include 'inc/sidebar.php';
?>

<div class="content">
    <h1 class="mb-4 text-primary">Medicines</h1>
    <div class="card mb-4">
        <div class="card-body">
            <form id="medicine-search" class="mb-3">
                <div class="input-group">
                    <input type="text" name="s" class="form-control" placeholder="Search medicines..." value="<?php echo esc_attr($search); ?>">
                    <button type="submit" class="btn btn-primary">Search</button>
                </div>
            </form>
            <button class="btn btn-success mb-3" data-bs-toggle="modal" data-bs-target="#medicineModal">Add Medicine</button>
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Price</th>
                        <th>Stock</th>
                        <th>SKU</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($medicines['posts'] as $medicine): ?>
                        <tr>
                            <td><?php echo $medicine['id']; ?></td>
                            <td><?php echo esc_html($medicine['title']); ?></td>
                            <td><?php echo esc_html($medicine['meta']['_medicine_price'][0] ?? '0'); ?></td>
                            <td><?php echo esc_html($medicine['meta']['_medicine_stock'][0] ?? '0'); ?></td>
                            <td><?php echo esc_html($medicine['meta']['_medicine_sku'][0] ?? '-'); ?></td>
                            <td>
                                <button class="btn btn-sm btn-primary edit-medicine" data-id="<?php echo $medicine['id']; ?>" data-bs-toggle="modal" data-bs-target="#medicineModal">Edit</button>
                                <button class="btn btn-sm btn-danger delete-medicine" data-id="<?php echo $medicine['id']; ?>">Delete</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($medicines['total_pages'] > 1): ?>
                <nav>
                    <ul class="pagination">
                        <?php for ($i = 1; $i <= $medicines['total_pages']; $i++): ?>
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

<div class="modal fade" id="medicineModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Medicine</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="medicine-form" class="ajax-form" enctype="multipart/form-data">
                    <input type="hidden" name="medicine_id" id="medicine_id">
                    <input type="hidden" name="action" id="form-action" value="add_medicine">
                    <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('admin_panel_nonce'); ?>">
                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input type="text" name="medicine_name" id="medicine_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Price</label>
                        <input type="number" name="medicine_price" id="medicine_price" class="form-control" step="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Stock</label>
                        <input type="number" name="medicine_stock" id="medicine_stock" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">SKU</label>
                        <input type="text" name="medicine_sku" id="medicine_sku" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="medicine_description" id="medicine_description" class="form-control"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Category</label>
                        <select name="medicine_category[]" id="medicine_category" class="form-control" multiple>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category->term_id; ?>"><?php echo esc_html($category->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Custom Fields</label>
                        <?php foreach (get_option('mmsb_settings_custom_fields_medicines', []) as $field): ?>
                            <div class="mb-2">
                                <label><?php echo esc_html($field['label']); ?></label>
                                <?php if ($field['type'] === 'text'): ?>
                                    <input type="text" name="custom_fields[<?php echo esc_attr($field['key']); ?>]" class="form-control">
                                <?php elseif ($field['type'] === 'number'): ?>
                                    <input type="number" name="custom_fields[<?php echo esc_attr($field['key']); ?>]" class="form-control">
                                <?php elseif ($field['type'] === 'select'): ?>
                                    <select name="custom_fields[<?php echo esc_attr($field['key']); ?>]" class="form-control">
                                        <?php foreach (explode(',', $field['options']) as $option): ?>
                                            <option value="<?php echo esc_attr(trim($option)); ?>"><?php echo esc_html(trim($option)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php elseif ($field['type'] === 'checkbox'): ?>
                                    <input type="checkbox" name="custom_fields[<?php echo esc_attr($field['key']); ?>]" value="1">
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>


                    <div class="mb-3">
                        <label class="form-label">Featured Image</label>
                        <input type="file" id="featured_image" accept="image/*" class="form-control">
                        <div id="cropper-container" style="display: none;">
                            <img id="cropper-image" style="max-width: 100%;">
                            <button type="button" id="crop-image" class="btn btn-primary mt-2">Crop Image</button>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Gallery Images</label>
                        <div id="dropzone-upload" class="dropzone"></div>
                        <div id="gallery-preview" class="mt-3"></div>
                    </div>


                    <button type="submit" class="btn btn-primary">Save</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/cropperjs@1.5.12/dist/cropper.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/cropperjs@1.5.12/dist/cropper.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/dropzone@5.9.3/dist/dropzone.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/dropzone@5.9.3/dist/dropzone.min.css" rel="stylesheet">
<script>
    $(document).ready(function() {
        let cropper;
        $('#featured_image').on('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    $('#cropper-container').show();
                    $('#cropper-image').attr('src', e.target.result);
                    cropper = new Cropper(document.getElementById('cropper-image'), {
                        aspectRatio: 1,
                        viewMode: 1,
                    });
                };
                reader.readAsDataURL(file);
            }
        });

        $('#crop-image').on('click', function() {
            const canvas = cropper.getCroppedCanvas();
            canvas.toBlob(function(blob) {
                const file = new File([blob], 'cropped_image.jpg', {
                    type: 'image/jpeg'
                });
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(file);
                $('#featured_image')[0].files = dataTransfer.files;
                $('#cropper-container').hide();
                cropper.destroy();
            });
        });

        const dropzone = new Dropzone('#dropzone-upload', {
            url: '/admin-panel/inc/ajax-handler.php',
            acceptedFiles: 'image/*',
            maxFiles: 5,
            addRemoveLinks: true,
            init: function() {
                this.on('addedfile', function(file) {
                    file.previewElement.querySelector('.dz-remove').addEventListener('click', () => {
                        this.removeFile(file);
                    });
                });
            }
        });

        $('.edit-medicine').on('click', function() {
            const id = $(this).data('id');
            $.ajax({
                url: '/admin-panel/api/medicine.php',
                type: 'GET',
                data: {
                    medicine_id: id
                },
                success: function(result) {
                    if (result.success) {
                        $('#medicine_id').val(id);
                        $('#form-action').val('edit_medicine');
                        $('#medicine_name').val(result.data.title);
                        $('#medicine_price').val(result.data.meta._medicine_price[0]);
                        $('#medicine_stock').val(result.data.meta._medicine_stock[0]);
                        $('#medicine_sku').val(result.data.meta._medicine_sku[0]);
                        $('#medicine_description').val(result.data.meta._medicine_description[0]);
                        $('#medicine_category').val(result.data.terms);
                        $('#gallery-preview').html('');
                        if (result.data.meta._gallery_images) {
                            result.data.meta._gallery_images.forEach(url => {
                                $('#gallery-preview').append(`
                                <div class="gallery-image">
                                    <img src="${url}" style="max-width: 100px;">
                                    <button class="btn btn-sm btn-danger delete-gallery-image" data-url="${url}">Delete</button>
                                </div>
                            `);
                            });
                        }
                    }
                }
            });
        });

        $(document).on('click', '.delete-gallery-image', function() {
            const url = $(this).data('url');
            $.ajax({
                url: '/admin-panel/inc/ajax-handler.php',
                type: 'POST',
                data: {
                    action: 'delete_gallery_image',
                    medicine_id: $('#medicine_id').val(),
                    image_url: url,
                    nonce: '<?php echo wp_create_nonce('admin_panel_nonce'); ?>'
                },
                success: function(result) {
                    if (result.success) {
                        $(`.delete-gallery-image[data-url="${url}"]`).parent().remove();
                        showAlert(result.data.message, 'success');
                    } else {
                        showAlert(result.data.message, 'danger');
                    }
                }
            });
        });

        $('.delete-medicine').on('click', function() {
            if (confirm('Are you sure you want to delete this medicine?')) {
                const id = $(this).data('id');
                $.ajax({
                    url: '/admin-panel/inc/ajax-handler.php',
                    type: 'POST',
                    data: {
                        action: 'delete_medicine',
                        medicine_id: id,
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

        function showAlert(message, type) {
            const alert = `<div class="alert alert-${type} alert-dismissible fade show">${message}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>`;
            $('.content').prepend(alert);
            setTimeout(() => $('.alert').remove(), 3000);
        }
    });
</script>
<?php include 'inc/footer.php'; ?>