<?php
/*
Plugin Name: My Med Store Image Sizes
Description: Limits WordPress image sizes to thumbnail, medium, and full for My Med Store admin panel.
Version: 1.0
Author: Your Name
*/

// Define custom image sizes
function my_med_store_image_sizes() {
    // Set thumbnail size (150x150, cropped)
    update_option('thumbnail_size_w', 150);
    update_option('thumbnail_size_h', 150);
    update_option('thumbnail_crop', 1);

    // Set medium size (300x300, cropped)
    update_option('medium_size_w', 300);
    update_option('medium_size_h', 300);
    update_option('medium_crop', 1);

    // Remove other default sizes
    update_option('medium_large_size_w', 0);
    update_option('medium_large_size_h', 0);
    update_option('large_size_w', 0);
    update_option('large_size_h', 0);
}
add_action('after_setup_theme', 'my_med_store_image_sizes');

// Limit intermediate image sizes to thumbnail and medium
function my_med_store_limit_image_sizes($sizes) {
    return ['thumbnail', 'medium'];
}
add_filter('intermediate_image_sizes', 'my_med_store_limit_image_sizes');

// Remove additional image sizes from advanced handling
function my_med_store_limit_advanced_sizes($sizes) {
    return array_intersect($sizes, ['thumbnail', 'medium']);
}
add_filter('intermediate_image_sizes_advanced', 'my_med_store_limit_advanced_sizes');

// Ensure only desired sizes are generated
function my_med_store_image_size_names($sizes) {
    return [
        'thumbnail' => 'مصغرة (150x150)',
        'medium' => 'متوسط (300x300)',
        'full' => 'كامل'
    ];
}
add_filter('image_size_names_choose', 'my_med_store_image_size_names');
?>