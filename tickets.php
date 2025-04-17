<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-load.php');
require_once('inc/auth.php');
require_login();
$page = intval($_GET['paged'] ?? 1);
$per_page = 10;
$search = sanitize_text_field($_GET['s'] ?? '');
$tickets = get_paginated_posts_data('ticket', $search, $page, $per_page);
include 'inc/header.php';
include 'inc/sidebar.php';
?>

<div class="content">
    <h1 class="mb-4 text-primary">Tickets</h1>
    <div class="card mb-4">
        <div class="card-body">
            <form id="ticket-search" class="mb-3">
                <div class="input-group">
                    <input type="text" name="s" class="form-control" placeholder="Search tickets..." value="<?php echo esc_attr($search); ?>">
                    <button type="submit" class="btn btn-primary">Search</button>
                </div>
            </form>
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Title</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tickets['posts'] as $ticket): ?>
                        <tr>
                            <td><?php echo $ticket['id']; ?></td>
                            <td><?php echo esc_html($ticket['title']); ?></td>
                            <td><?php echo esc_html($ticket['meta']['_ticket_status'][0] ?? 'Open'); ?></td>
                            <td>
                                <button class="btn btn-sm btn-primary view-ticket" data-id="<?php echo $ticket['id']; ?>" data-bs-toggle="modal" data-bs-target="#ticketModal">View</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($tickets['total_pages'] > 1): ?>
                <nav>
                    <ul class="pagination">
                        <?php for ($i = 1; $i <= $tickets['total_pages']; $i++): ?>
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

<div class="modal fade" id="ticketModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Ticket</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="ticket-conversation" class="mb-3"></div>
                <form id="ticket-reply-form" class="ajax-form">
                    <input type="hidden" name="ticket_id" id="ticket_id">
                    <input type="hidden" name="action" value="add_ticket_reply">
                    <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('admin_panel_nonce'); ?>">
                    <div class="mb-3">
                        <label class="form-label">Reply</label>
                        <textarea name="reply_content" id="reply_content" class="form-control" required></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select name="ticket_status" id="ticket_status" class="form-control">
                            <option value="Open">Open</option>
                            <option value="In Progress">In Progress</option>
                            <option value="Closed">Closed</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">Send Reply</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    $(document).ready(function() {
        $('.view-ticket').on('click', function() {
            const id = $(this).data('id');
            $.ajax({
                url: '/admin-panel/api/ticket.php',
                type: 'GET',
                data: {
                    ticket_id: id
                },
                success: function(result) {
                    if (result.success) {
                        $('#ticket_id').val(id);
                        $('#ticket_status').val(result.data.meta._ticket_status[0]);
                        const replies = result.data.meta._ticket_replies ? JSON.parse(result.data.meta._ticket_replies[0]) : [];
                        let html = '';
                        replies.forEach(reply => {
                            const user = reply.user_id ? get_user_by('ID', reply.user_id).user_login : 'System';
                            html += `
                            <div class="card mb-2">
                                <div class="card-body">
                                    <strong>${user}</strong> <small>${reply.date}</small>
                                    <p>${reply.content}</p>
                                </div>
                            </div>
                        `;
                        });
                        $('#ticket-conversation').html(html);
                    }
                }
            });
        });
    });
</script>
<?php include 'inc/footer.php'; ?>