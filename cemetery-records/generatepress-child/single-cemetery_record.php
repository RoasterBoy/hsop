<?php
/**
 * Template for displaying a single Cemetery Record
 */

get_header(); ?>

<div id="primary" class="content-area">
    <main id="main" class="site-main">

        <?php
        // Start the Loop.
        while ( have_posts() ) :
            the_post();

            // Get the current post ID
            $current_post_id = get_the_ID();
            
            // Display the post title
            the_title( '<h1 class="entry-title">', '</h1>' );

            // Display other meta data as needed
            // Example:
            $location = get_post_meta($current_post_id, '_page_location', true);
            if ($location) {
                echo '<p><strong>' . esc_html__('Location:', 'cemetery-records') . '</strong> ' . esc_html($location) . '</p>';
            }

            // Retrieve the attachment IDs from post meta
            $source_image_id = get_post_meta($current_post_id, '_source_page_attachment_id', true);
            $extracted_image_id = get_post_meta($current_post_id, '_extracted_image_attachment_id', true);

		            // Display any other content or meta fields you have
            $additional_info = get_post_meta($current_post_id, '_page_additional_info', true);
            if ($additional_info) {
                 echo '<hr style="margin: 2em 0;">';
                 echo '<h3>' . esc_html__('Additional Information', 'cemetery-records') . '</h3>';
                 echo '<div>' . wp_kses_post($additional_info) . '</div>';
            }
            // Display the source page image, linked to its full-size version
            if ($source_image_id) {
                $full_image_url = wp_get_attachment_url($source_image_id); // Get URL for the full-size image
                echo '<div class="cemetery-image-wrapper">';
                echo '<h3>' . esc_html__('Original Cemetery Page', 'cemetery-records') . '</h3>';
                if ($full_image_url) {
                    echo '<a href="' . esc_url($full_image_url) . '" target="_blank" title="' . esc_attr__('View full size', 'cemetery-records') . '">';
                    echo wp_get_attachment_image($source_image_id, 'large'); // Display the 'large' version
                    echo '</a>';
                    echo '<p><small>' . __('Click image to view full size.', 'cemetery-records') . '</small></p>';
                } else {
                    echo wp_get_attachment_image($source_image_id, 'large');
                }
                echo '</div>';
            }

            echo '<hr style="margin: 2em 0;">'; // Separator

            // Display the extracted image, linked to its full-size version
            if ($extracted_image_id) {
                $full_image_url = wp_get_attachment_url($extracted_image_id); // Get URL for the full-size image
                echo '<div class="cemetery-image-wrapper">';
                echo '<h3>' . esc_html__('Cemetery Marker', 'cemetery-records') . '</h3>';
                if ($full_image_url) {
                    echo '<a href="' . esc_url($full_image_url) . '" target="_blank" title="' . esc_attr__('View full size', 'cemetery-records') . '">';
                    echo wp_get_attachment_image($extracted_image_id, 'large'); // Display the 'large' version
                    echo '</a>';
                    echo '<p><small>' . __('Click image to view full size.', 'cemetery-records') . '</small></p>';
                } else {
                    echo wp_get_attachment_image($extracted_image_id, 'large');
                }
                echo '</div>';
            }




        endwhile; // End the loop.
        ?>

    </main></div><?php get_footer(); ?>