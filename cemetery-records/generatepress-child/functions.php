<?php
/**
 * Child theme functions and definitions.
 */

// This function properly loads the parent and child theme stylesheets.
add_action( 'wp_enqueue_scripts', 'my_cemetery_theme_enqueue_styles' );
function my_cemetery_theme_enqueue_styles() {
    wp_enqueue_style( 'parent-style', get_template_directory_uri() . '/style.css' );
}

// --- ADD YOUR CUSTOM FUNCTIONS BELOW THIS LINE ---

/**
 * Sorts the 'cemetery_record' custom post type by title in ascending order.
 */
function sort_cemetery_records_by_title( $query ) {
    if ( ! is_admin() && $query->is_main_query() && is_post_type_archive( 'cemetery_record' ) ) {
        $query->set( 'orderby', 'title' );
        $query->set( 'order', 'ASC' );
    }
}
add_action( 'pre_get_posts', 'sort_cemetery_records_by_title' );