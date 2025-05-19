<?php
/**
 * Template for displaying single cemetery records
 */

get_header(); ?>

<div class="cemetery-record">
    <div class="cemetery-record-header">
        <h1 class="cemetery-record-title"><?php the_title(); ?></h1>
        <div class="cemetery-record-meta">
            <?php
            $location = get_post_meta(get_the_ID(), '_page_location', true);
            if ($location) {
                echo '<span class="cemetery-record-location">' . esc_html($location) . '</span>';
            }
            ?>
        </div>
    </div>

    <div class="cemetery-record-content">
        <?php
        // Display the main content if any
        if (have_posts()) : while (have_posts()) : the_post();
            the_content();
        endwhile; endif;
        ?>
    </div>

    <div class="cemetery-record-images">
        <?php
        // Display extracted image if available
        $extracted_image_id = get_post_meta(get_the_ID(), '_extracted_image', true);
        if ($extracted_image_id) {
            $image_caption = get_post_meta(get_the_ID(), '_image_caption', true);
            ?>
            <div class="cemetery-record-image">
                <?php
                echo wp_get_attachment_image($extracted_image_id, 'cemetery-record-large');
                if ($image_caption) {
                    echo '<div class="cemetery-record-image-caption">' . esc_html($image_caption) . '</div>';
                }
                ?>
            </div>
            <?php
        }

        // Display source page image if available
        $source_page_id = get_post_meta(get_the_ID(), '_source_page', true);
        if ($source_page_id) {
            ?>
            <div class="cemetery-record-image">
                <?php
                echo wp_get_attachment_image($source_page_id, 'cemetery-record-large');
                ?>
            </div>
            <?php
        }
        ?>
    </div>

    <div class="cemetery-record-footer">
        <?php
        // Display additional metadata
        $footer = get_post_meta(get_the_ID(), '_page_footer', true);
        $header = get_post_meta(get_the_ID(), '_page_header', true);
        $additional_info = get_post_meta(get_the_ID(), '_page_additional_info', true);

        if ($header) {
            echo '<div class="cemetery-record-header-text">' . esc_html($header) . '</div>';
        }

        if ($footer) {
            echo '<div class="cemetery-record-footer-text">' . esc_html($footer) . '</div>';
        }

        if ($additional_info) {
            if (is_array($additional_info)) {
                $additional_info = json_encode($additional_info, JSON_PRETTY_PRINT);
            }
            echo '<div class="cemetery-record-additional-info">' . nl2br(esc_html($additional_info)) . '</div>';
        }
        ?>
    </div>
</div>

<?php get_footer(); ?> 