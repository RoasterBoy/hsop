<?php
/**
 * The template for displaying the archive of Cemetery Records.
 */

get_header(); ?>

<div id="primary" class="content-area">
    <main id="main" class="site-main">

        <?php if ( have_posts() ) : ?>

            <header class="page-header">
                <?php
                    // Display the archive title, e.g., "Cemetery Records"
                    post_type_archive_title( '<h1 class="page-title">', '</h1>' );
                ?>
            </header><?php
            // Start the Loop to display each record.
            while ( have_posts() ) :
                the_post();

                // Get the attachment ID for the extracted image from the post's metadata.
                $extracted_image_id = get_post_meta( get_the_ID(), '_extracted_image_attachment_id', true );
                ?>

                <article id="post-<?php the_ID(); ?>" <?php post_class('list-item-with-thumbnail'); ?>>
                    

                    <div class="record-content">
                        <header class="entry-header">
                            <?php
                            // Display the post title, linked to the single record page.
                            the_title( sprintf( '<h2 class="entry-title"><a href="%s" rel="bookmark">', esc_url( get_permalink() ) ), '</a></h2>' );
                            ?>
                        </header><div class="entry-summary">
                            <?php the_excerpt(); // Displays a short summary of the post. ?>
                        </div></div>

xs                    // Check if an extracted image ID exists.
                    if ( $extracted_image_id ) :
                        ?>
                        <div class="record-thumbnail">
                            <a href="<?php the_permalink(); ?>" title="<?php the_title_attribute(); ?>">
                                <?php
                                // Display the image from the ID, using the 'thumbnail' size.
                                echo wp_get_attachment_image( $extracted_image_id, 'thumbnail' );
                                ?>
                            </a>
    endif; ?>

                </article><?php
            endwhile;

            // Add pagination links if there are multiple pages of records.
            the_posts_navigation();

        else :
            // If no records are found, display a "not found" message.
            get_template_part( 'template-parts/content', 'none' );

        endif;
        ?>

    </main></div><?php
get_footer();