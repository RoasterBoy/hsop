<?php
/**
 * Template for displaying single cemetery records
 */

get_header(); ?>

<div class="cemetery-record">
    <div class="cemetery-record-header">
        <h1 class="cemetery-record-title"><?php the_title(); ?></h1>
    </div>

    <div class="cemetery-record-content">
        <?php
        // Display additional metadata like Plot Number and Data first
        $plot_number = get_post_meta(get_the_ID(), '_plot_number', true);
        $plot_data = get_post_meta(get_the_ID(), '_plot_data', true);
        $location = get_post_meta(get_the_ID(), '_page_location', true);

        echo '<div class="cemetery-record-meta">';
        if ($plot_number) {
            echo '<span class="cemetery-record-plot-number"><strong>Plot Number:</strong> ' . esc_html($plot_number) . '</span><br>';
        }
        if ($plot_data) {
            echo '<span class="cemetery-record-plot-data"><strong>Plot Data:</strong> ' . esc_html($plot_data) . '</span><br>';
        }
        if ($location) {
            echo '<span class="cemetery-record-location"><strong>Location:</strong> ' . esc_html($location) . '</span>';
        }
        echo '</div>';
        
        // Display the main content if any
        if (have_posts()) : while (have_posts()) : the_post();
            the_content();
        endwhile; endif;
        ?>
    </div>

    <div class="cemetery-record-footer">
        <?php
        $footer = get_post_meta(get_the_ID(), '_page_footer', true);
//        $header = get_post_meta(get_the_ID(), '_page_header', true);
        $additional_info = get_post_meta(get_the_ID(), '_page_additional_info', true);

        if ($header) {
            echo '<div class="cemetery-record-header-text"><strong>Header:</strong> ' . esc_html($header) . '</div>';
        }
        if ($footer) {
            echo '<div class="cemetery-record-footer-text"><strong>Footer:</strong> ' . esc_html($footer) . '</div>';
        }
        if ($additional_info) {
            echo '<div class="cemetery-record-additional-info"><strong>Additional Info:</strong><br>' . nl2br(esc_html($additional_info)) . '</div>';
        }
        ?>
    </div>
    <div class="cemetery-record-images">
        <?php
        // Fixed: Use correct meta key for extracted image
        $extracted_image_id = get_post_meta(get_the_ID(), '_extracted_image_attachment_id', true);
        if ($extracted_image_id) {
            $image_caption = get_post_meta(get_the_ID(), '_image_caption', true);
            ?>
            <div class="cemetery-record-image">
                <?php echo wp_get_attachment_image($extracted_image_id, 'large'); ?>
                <?php if ($image_caption) : ?>
                    <div class="cemetery-record-image-caption"><?php echo esc_html($image_caption); ?></div>
                <?php endif; ?>
            </div>
            <?php
        }
        
        // Fixed: Use correct meta key for source page image
        $source_page_id = get_post_meta(get_the_ID(), '_source_page_attachment_id', true);
        if ($source_page_id) {
            echo '<div class="cemetery-record-image">' . wp_get_attachment_image($source_page_id, 'large') . '</div>';
        }
        ?>
    </div>

</div>

<?php get_footer(); ?>