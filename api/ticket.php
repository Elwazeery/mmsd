<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-load.php');
require_once('api-utils.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/admin-panel/inc/activity-log.php');

$user = verify_api_token();
$method = $_SERVER['REQUEST_METHOD'];
$ip = $_SERVER['REMOTE_ADDR'];

if ($method === 'POST') {
    $action = sanitize_text_field($_POST['action'] ?? '');
    if ($action === 'reply') {
        $ticket_id = intval($_POST['ticket_id'] ?? 0);
        $content = sanitize_textarea_field($_POST['content'] ?? '');
        $ticket = get_post($ticket_id);
        if (!$ticket || $ticket->post_type !== 'ticket' || $ticket->post_author != $user->ID) {
            wp_send_json_error(['message' => 'Ticket not found or not authorized', 'code' => 'not_found'], 404);
        }
        if (empty($content)) {
            wp_send_json_error(['message' => 'Reply content is required', 'code' => 'invalid_input'], 400);
        }
        $replies = get_post_meta($ticket_id, '_ticket_replies', true) ? json_decode(get_post_meta($ticket_id, '_ticket_replies', true), true) : [];
        $replies[] = [
            'user_id' => $user->ID,
            'content' => $content,
            'date' => current_time('mysql')
        ];
        update_post_meta($ticket_id, '_ticket_replies', json_encode($replies));
        log_activity($user->ID, 'add_ticket_reply', "Added reply to ticket ID: $ticket_id", $ip);
        wp_send_json_success(['message' => 'Reply added']);
    } else {
        $title = sanitize_text_field($_POST['title'] ?? '');
        $content = sanitize_textarea_field($_POST['content'] ?? '');
        $status = sanitize_text_field($_POST['status'] ?? 'Open');
        if (empty($title) || empty($content)) {
            wp_send_json_error(['message' => 'Title and content are required', 'code' => 'invalid_input'], 400);
        }
        $allowed_statuses = ['Open', 'In Progress', 'Closed'];
        if (!in_array($status, $allowed_statuses)) {
            wp_send_json_error(['message' => 'Invalid ticket status', 'code' => 'invalid_status'], 400);
        }
        $post_id = wp_insert_post([
            'post_title' => $title,
            'post_content' => $content,
            'post_type' => 'ticket',
            'post_status' => 'publish',
            'post_author' => $user->ID
        ], true);
        if (is_wp_error($post_id)) {
            wp_send_json_error(['message' => 'Failed to create ticket: ' . $post_id->get_error_message(), 'code' => 'server_error'], 400);
        }
        update_post_meta($post_id, '_ticket_status', $status);
        update_post_meta($post_id, '_ticket_replies', json_encode([]));
        $business_name = get_option('mmsb_settings_business_name', 'Pharmacy');
        $business_email = get_option('mmsb_settings_business_email', get_option('admin_email'));
        $admin_email = get_option('admin_email');
        $subject = sprintf(__('%s - New Ticket #%s', 'mmsb'), $business_name, $post_id);
        $message = sprintf(
            "<p>New ticket created by %s.</p><p>Title: %s</p><p>Content: %s</p>",
            esc_html($user->user_login),
            esc_html($title),
            esc_html($content)
        );
        $headers = ['Content-Type: text/html; charset=UTF-8', 'From: ' . $business_name . ' <' . $business_email . '>'];
        wp_mail($admin_email, $subject, $message, $headers);
        log_activity($user->ID, 'create_ticket', "Created ticket ID: $post_id", $ip);
        wp_send_json_success(['ticket_id' => $post_id]);
    }
} elseif ($method === 'GET') {
    if (isset($_GET['ticket_id'])) {
        $ticket_id = intval($_GET['ticket_id']);
        $ticket = get_post($ticket_id);
        if (!$ticket || $ticket->post_type !== 'ticket' || $ticket->post_author != $user->ID) {
            wp_send_json_error(['message' => 'Ticket not found or not authorized', 'code' => 'not_found'], 404);
        }
        $meta = get_post_meta($ticket_id);
        wp_send_json_success([
            'id' => $ticket->ID,
            'title' => $ticket->post_title,
            'content' => $ticket->post_content,
            'status' => $meta['_ticket_status'][0] ?? '',
            'replies' => $meta['_ticket_replies'][0] ? json_decode($meta['_ticket_replies'][0], true) : [],
            'date' => get_the_date('c', $ticket)
        ]);
    } else {
        $page = max(1, intval($_GET['page'] ?? 1));
        $search = sanitize_text_field($_GET['search'] ?? '');
        $args = [
            'post_type' => 'ticket',
            'posts_per_page' => 10,
            'paged' => $page,
            's' => $search,
            'author' => $user->ID
        ];
        $query = new WP_Query($args);
        $tickets = [];
        foreach ($query->posts as $post) {
            $meta = get_post_meta($post->ID);
            $tickets[] = [
                'id' => $post->ID,
                'title' => $post->post_title,
                'status' => $meta['_ticket_status'][0] ?? '',
                'date' => get_the_date('c', $post)
            ];
        }
        wp_send_json_success([
            'tickets' => $tickets,
            'total_pages' => $query->max_num_pages,
            'current_page' => $page,
            'total_items' => $query->found_posts
        ]);
    }
} else {
    wp_send_json_error(['message' => 'Method not allowed', 'code' => 'method_not_allowed'], 405);
}
