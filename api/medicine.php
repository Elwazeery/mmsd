<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-load.php');
require_once('../activity-log.php');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['action']) && $_GET['action'] === 'get_categories') {
        $cache_key = 'medicine_categories';
        $categories = get_transient($cache_key);
        if (false === $categories) {
            $categories = get_terms([
                'taxonomy' => 'medicine_category',
                'hide_empty' => true
            ]);
            set_transient($cache_key, $categories, HOUR_IN_SECONDS);
        }
        log_activity(0, 'view_categories', 'Viewed medicine categories', $_SERVER['REMOTE_ADDR']);
        wp_send_json_success([
            'categories' => array_map(function ($cat) {
                return [
                    'id' => $cat->term_id,
                    'name' => $cat->name,
                    'slug' => $cat->slug
                ];
            }, $categories)
        ]);
    }

    if (isset($_GET['medicine_id'])) {
        $medicine_id = intval($_GET['medicine_id']);
        $medicine = get_post($medicine_id);
        if ($medicine && $medicine->post_type === 'medicine') {
            $categories = wp_get_post_terms($medicine_id, 'medicine_category', ['fields' => 'all']);
            log_activity(0, 'view_medicine', "Viewed medicine ID: $medicine_id", $_SERVER['REMOTE_ADDR']);
            wp_send_json_success([
                'id' => $medicine->ID,
                'name' => $medicine->post_title,
                'price' => get_post_meta($medicine_id, '_medicine_price', true),
                'stock' => get_post_meta($medicine_id, '_medicine_stock', true),
                'sku' => get_post_meta($medicine_id, '_medicine_sku', true),
                'description' => get_post_meta($medicine_id, '_medicine_description', true),
                'image' => get_post_meta($medicine_id, '_featured_image', true),
                'gallery' => get_post_meta($medicine_id, '_gallery_images', true) ?: [],
                'categories' => array_map(function ($cat) {
                    return ['id' => $cat->term_id, 'name' => $cat->name, 'slug' => $cat->slug];
                }, $categories),
                'custom_fields' => array_filter(get_post_meta($medicine_id), function ($key) {
                    return strpos($key, '_') !== 0;
                }, ARRAY_FILTER_USE_KEY)
            ]);
        }
        wp_send_json_error(['message' => 'Medicine not found', 'code' => 'not_found'], 404);
    } else {
        $page = intval($_GET['page'] ?? 1);
        $per_page = 10;
        $search = sanitize_text_field($_GET['search'] ?? '');
        $category = sanitize_text_field($_GET['category'] ?? '');
        $sort = sanitize_text_field($_GET['sort'] ?? ''); // e.g., price_asc, price_desc, name_asc, name_desc

        $args = [
            'post_type' => 'medicine',
            'posts_per_page' => $per_page,
            'post_status' => 'publish',
            'paged' => $page
        ];

        if ($search) {
            $args['s'] = $search;
        }

        if ($category) {
            $args['tax_query'] = [
                [
                    'taxonomy' => 'medicine_category',
                    'field' => is_numeric($category) ? 'term_id' : 'slug',
                    'terms' => $category
                ]
            ];
        }

        if ($sort) {
            if (in_array($sort, ['price_asc', 'price_desc'])) {
                $args['meta_key'] = '_medicine_price';
                $args['orderby'] = 'meta_value_num';
                $args['order'] = $sort === 'price_asc' ? 'ASC' : 'DESC';
            } elseif (in_array($sort, ['name_asc', 'name_desc'])) {
                $args['orderby'] = 'title';
                $args['order'] = $sort === 'name_asc' ? 'ASC' : 'DESC';
            }
        }

        $query = new WP_Query($args);
        $medicines = [];
        while ($query->have_posts()) {
            $query->the_post();
            $medicines[] = [
                'id' => get_the_ID(),
                'name' => get_the_title(),
                'price' => get_post_meta(get_the_ID(), '_medicine_price', true),
                'stock' => get_post_meta(get_the_ID(), '_medicine_stock', true),
                'sku' => get_post_meta(get_the_ID(), '_medicine_sku', true),
                'image' => get_post_meta(get_the_ID(), '_featured_image', true)
            ];
        }
        wp_reset_postdata();

        log_activity(0, 'view_medicines', "Viewed medicines page $page" . ($search ? " with search: $search" : '') . ($category ? " in category: $category" : '') . ($sort ? " sorted by: $sort" : ''), $_SERVER['REMOTE_ADDR']);
        wp_send_json_success([
            'medicines' => $medicines,
            'total_pages' => $query->max_num_pages,
            'current_page' => $page
        ]);
    }
}
