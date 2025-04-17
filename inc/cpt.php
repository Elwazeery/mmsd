<?php
function register_custom_post_types()
{
    register_post_type('medicine', [
        'labels' => ['name' => 'Medicines', 'singular_name' => 'Medicine'],
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => false,
        'supports' => ['title', 'thumbnail'],
        'capability_type' => 'post',
        'capabilities' => ['create_posts' => 'manage_options'],
        'map_meta_cap' => true,
    ]);
    register_post_type('order', [
        'labels' => ['name' => 'Orders', 'singular_name' => 'Order'],
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => false,
        'supports' => ['title'],
        'capability_type' => 'post',
        'capabilities' => ['create_posts' => 'manage_options'],
        'map_meta_cap' => true,
    ]);
    register_post_type('payment', [
        'labels' => ['name' => 'Payments', 'singular_name' => 'Payment'],
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => false,
        'supports' => ['title'],
        'capability_type' => 'post',
        'capabilities' => ['create_posts' => 'manage_options', 'edit_posts' => 'do_not_allow', 'delete_posts' => 'do_not_allow'],
        'map_meta_cap' => true,
    ]);
    register_post_type('ticket', [
        'labels' => ['name' => 'Tickets', 'singular_name' => 'Ticket'],
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => false,
        'supports' => ['title'],
        'capability_type' => 'post',
        'capabilities' => ['create_posts' => 'manage_options'],
        'map_meta_cap' => true,
    ]);
}
add_action('init', 'register_custom_post_types');
