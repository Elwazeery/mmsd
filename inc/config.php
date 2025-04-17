<?php

/**
 * Configuration and utility functions for the pharmacy management system.
 */

/**
 * Register custom taxonomies.
 */
function register_taxonomies()
{
    register_taxonomy('medicine_category', 'medicine', [
        'labels' => [
            'name' => 'Medicine Categories',
            'singular_name' => 'Category',
            'add_new_item' => 'Add New Category',
            'edit_item' => 'Edit Category',
            'update_item' => 'Update Category',
            'all_items' => 'All Categories',
            'search_items' => 'Search Categories',
        ],
        'hierarchical' => true,
        'show_ui' => true,
        'show_in_rest' => true,
        'public' => true,
    ]);
}
add_action('init', 'register_taxonomies');

/**
 * Retrieve all posts of a given post type with metadata and terms.
 *
 * @param string $post_type The post type to query.
 * @param string $search Optional search term.
 * @return array Array of post data.
 */
function get_posts_data($post_type, $search = '', $limit = 100)
{
    $args = [
        'post_type' => $post_type,
        'posts_per_page' => $limit,
        'post_status' => 'publish',
    ];
    if ($search) {
        $args['s'] = sanitize_text_field($search);
    }
    $query = new WP_Query($args);
    $data = [];
    while ($query->have_posts()) {
        $query->the_post();
        $data[] = [
            'id' => get_the_ID(),
            'title' => get_the_title(),
            'meta' => get_post_meta(get_the_ID()),
            'thumbnail' => get_the_post_thumbnail_url(),
            'date' => get_the_date(),
            'terms' => wp_get_post_terms(get_the_ID(), 'medicine_category', ['fields' => 'ids']),
        ];
    }
    wp_reset_postdata();
    return $data;
}

/**
 * Retrieve paginated posts of a given post type with metadata and terms.
 *
 * @param string $post_type The post type to query.
 * @param string $search Optional search term.
 * @param int $page Current page number.
 * @param int $per_page Number of posts per page.
 * @return array Array of paginated post data.
 */
function get_paginated_posts_data($post_type, $search = '', $page = 1, $per_page = 10)
{
    $args = [
        'post_type' => $post_type,
        'posts_per_page' => $per_page,
        'post_status' => 'publish',
        'paged' => $page,
    ];
    if ($search) {
        $args['s'] = sanitize_text_field($search);
    }
    $query = new WP_Query($args);
    $data = [];
    while ($query->have_posts()) {
        $query->the_post();
        $data[] = [
            'id' => get_the_ID(),
            'title' => get_the_title(),
            'meta' => get_post_meta(get_the_ID()),
            'thumbnail' => get_the_post_thumbnail_url(),
            'date' => get_the_date(),
            'terms' => wp_get_post_terms(get_the_ID(), 'medicine_category', ['fields' => 'ids']),
        ];
    }
    wp_reset_postdata();
    return [
        'posts' => $data,
        'total_pages' => $query->max_num_pages,
        'current_page' => $page,
    ];
}

/**
 * Update post meta data for a given post.
 *
 * @param int $post_id The post ID.
 * @param array $meta_fields Array of meta key-value pairs.
 */
function update_post_meta_data($post_id, $meta_fields)
{
    foreach ($meta_fields as $key => $value) {
        if (is_array($value)) {
            $value = array_map('sanitize_text_field', $value);
        } else {
            $value = sanitize_text_field($value);
        }
        update_post_meta($post_id, $key, $value);
    }
}
