<?php

class Cemetery_Records {
    public function init() {
        // Register post type
        add_action('init', array($this, 'register_post_type'));
        
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Register scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_public_assets'));
        
        // Add meta boxes
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        
        // Save post meta
        add_action('save_post_cemetery_record', array($this, 'save_post_meta'));
        
        // Add image sizes
        add_action('after_setup_theme', array($this, 'add_image_sizes'));
    }

    public function register_post_type() {
        $labels = array(
            'name' => __('Cemetery Records', 'cemetery-records'),
            'singular_name' => __('Cemetery Record', 'cemetery-records'),
            'add_new' => __('Add New', 'cemetery-records'),
            'add_new_item' => __('Add New Record', 'cemetery-records'),
            'edit_item' => __('Edit Record', 'cemetery-records'),
            'new_item' => __('New Record', 'cemetery-records'),
            'view_item' => __('View Record', 'cemetery-records'),
            'search_items' => __('Search Records', 'cemetery-records'),
            'not_found' => __('No records found', 'cemetery-records'),
            'not_found_in_trash' => __('No records found in trash', 'cemetery-records'),
            'all_items' => __('All Records', 'cemetery-records'),
            'menu_name' => __('Cemetery Records', 'cemetery-records')
        );

        $capabilities = array(
            'edit_post' => 'edit_cemetery_record',
            'edit_posts' => 'edit_cemetery_records',
            'edit_others_posts' => 'edit_others_cemetery_records',
            'publish_posts' => 'publish_cemetery_records',
            'read_post' => 'read_cemetery_record',
            'read_private_posts' => 'read_private_cemetery_records',
            'delete_post' => 'delete_cemetery_record',
            'delete_posts' => 'delete_cemetery_records',
            'create_posts' => 'create_cemetery_records'
        );

        $args = array(
            'labels' => $labels,
            'public' => true,
            'publicly_queryable' => true,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_nav_menus' => true,
            'show_in_admin_bar' => true,
            'has_archive' => true,
            'hierarchical' => false,
            'supports' => array(
                'title',
                'thumbnail',
                'custom-fields',
                'revisions'
            ),
            'menu_icon' => 'dashicons-book',
            'rewrite' => array(
                'slug' => 'cemetery-records',
                'with_front' => true
            ),
            'show_in_rest' => true,
            'rest_base' => 'cemetery-records',
            'capability_type' => array('cemetery_record', 'cemetery_records'),
            'map_meta_cap' => true,
            'capabilities' => $capabilities
        );

        register_post_type('cemetery_record', $args);
    }

    public function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=cemetery_record',
            __('Import/Export', 'cemetery-records'),
            __('Import/Export', 'cemetery-records'),
            'manage_options',
            'cemetery-records-import-export',
            array($this, 'render_import_export_page')
        );
    }

    public function render_import_export_page() {
        if (class_exists('Cemetery_Records_Import_Export')) {
            $import_export = new Cemetery_Records_Import_Export();
            $import_export->render_page();
        } else {
            wp_die(__('Import/Export functionality is not available.', 'cemetery-records'));
        }
    }

    public function enqueue_admin_assets($hook) {
        wp_enqueue_style(
            'cemetery-records-admin',
            CEMETERY_RECORDS_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            CEMETERY_RECORDS_VERSION
        );

        if (strpos($hook, 'cemetery-records') !== false) {
            wp_enqueue_script(
                'cemetery-records-admin',
                CEMETERY_RECORDS_PLUGIN_URL . 'assets/js/admin.js',
                array('jquery'),
                CEMETERY_RECORDS_VERSION,
                true
            );
        }
    }

    public function enqueue_public_assets() {
        if (is_singular('cemetery_record') || is_post_type_archive('cemetery_record')) {
            wp_enqueue_style(
                'cemetery-records-public',
                CEMETERY_RECORDS_PLUGIN_URL . 'assets/css/public.css',
                array(),
                CEMETERY_RECORDS_VERSION
            );
        }
    }

    public function add_meta_boxes() {
        add_meta_box(
            'cemetery_record_details',
            __('Record Details', 'cemetery-records'),
            array($this, 'render_details_meta_box'),
            'cemetery_record',
            'normal',
            'high'
        );
    }

    public function render_details_meta_box($post) {
        // Add nonce for security
        wp_nonce_field('cemetery_record_details', 'cemetery_record_details_nonce');

        // Get saved values
        $page_header = get_post_meta($post->ID, '_page_header', true);
        $page_footer = get_post_meta($post->ID, '_page_footer', true);
        $page_location = get_post_meta($post->ID, '_page_location', true);
        $page_additional_info = get_post_meta($post->ID, '_page_additional_info', true);
        $image_caption = get_post_meta($post->ID, '_image_caption', true);
        $extracted_image_id = get_post_meta($post->ID, '_extracted_image', true);
        $source_page_id = get_post_meta($post->ID, '_source_page', true);

        // Output form fields
        ?>
        <div class="cemetery-record-meta-box">
            <div class="cemetery-record-images">
                <div class="cemetery-record-image-section">
                    <h3><?php _e('Extracted Image', 'cemetery-records'); ?></h3>
                    <div class="cemetery-record-image-preview">
                        <?php 
                        if ($extracted_image_id) {
                            $full_size_url = wp_get_attachment_url($extracted_image_id);
                            $image = wp_get_attachment_image($extracted_image_id, 'large');
                            if ($image) {
                                echo '<a href="' . esc_url($full_size_url) . '" target="_blank">' . $image . '</a>';
                            } else {
                                echo '<p class="no-image">' . __('Image not found', 'cemetery-records') . '</p>';
                            }
                        } else {
                            echo '<p class="no-image">' . __('No image uploaded', 'cemetery-records') . '</p>';
                        }
                        ?>
                    </div>
                    <input type="hidden" name="extracted_image" id="extracted_image" value="<?php echo esc_attr($extracted_image_id); ?>">
                    <button type="button" class="button select-image" data-field="extracted_image">
                        <?php _e('Select Extracted Image', 'cemetery-records'); ?>
                    </button>
                    <?php if ($extracted_image_id): ?>
                        <button type="button" class="button remove-image" data-field="extracted_image">
                            <?php _e('Remove Image', 'cemetery-records'); ?>
                        </button>
                    <?php endif; ?>
                </div>

                <div class="cemetery-record-image-section">
                    <h3><?php _e('Source Page', 'cemetery-records'); ?></h3>
                    <div class="cemetery-record-image-preview">
                        <?php 
                        if ($source_page_id) {
                            $full_size_url = wp_get_attachment_url($source_page_id);
                            $image = wp_get_attachment_image($source_page_id, 'large');
                            if ($image) {
                                echo '<a href="' . esc_url($full_size_url) . '" target="_blank">' . $image . '</a>';
                            } else {
                                echo '<p class="no-image">' . __('Image not found', 'cemetery-records') . '</p>';
                            }
                        } else {
                            echo '<p class="no-image">' . __('No image uploaded', 'cemetery-records') . '</p>';
                        }
                        ?>
                    </div>
                    <input type="hidden" name="source_page" id="source_page" value="<?php echo esc_attr($source_page_id); ?>">
                    <button type="button" class="button select-image" data-field="source_page">
                        <?php _e('Select Source Page', 'cemetery-records'); ?>
                    </button>
                    <?php if ($source_page_id): ?>
                        <button type="button" class="button remove-image" data-field="source_page">
                            <?php _e('Remove Image', 'cemetery-records'); ?>
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="cemetery-record-fields">
                <p>
                    <label for="page_header"><?php _e('Page Header:', 'cemetery-records'); ?></label>
                    <input type="text" id="page_header" name="page_header" value="<?php echo esc_attr($page_header); ?>">
                </p>
                <p>
                    <label for="page_footer"><?php _e('Page Footer:', 'cemetery-records'); ?></label>
                    <input type="text" id="page_footer" name="page_footer" value="<?php echo esc_attr($page_footer); ?>">
                </p>
                <p>
                    <label for="page_location"><?php _e('Location:', 'cemetery-records'); ?></label>
                    <input type="text" id="page_location" name="page_location" value="<?php echo esc_attr($page_location); ?>">
                </p>
                <p>
                    <label for="image_caption"><?php _e('Image Caption:', 'cemetery-records'); ?></label>
                    <input type="text" id="image_caption" name="image_caption" value="<?php echo esc_attr($image_caption); ?>">
                </p>
                <p>
                    <label for="page_additional_info"><?php _e('Additional Information:', 'cemetery-records'); ?></label>
                    <textarea id="page_additional_info" name="page_additional_info" rows="4"><?php echo esc_textarea($page_additional_info); ?></textarea>
                </p>
            </div>
        </div>
        <?php
    }

    public function save_post_meta($post_id) {
        // Check if our nonce is set
        if (!isset($_POST['cemetery_record_details_nonce'])) {
            return;
        }

        // Verify the nonce
        if (!wp_verify_nonce($_POST['cemetery_record_details_nonce'], 'cemetery_record_details')) {
            return;
        }

        // If this is an autosave, don't update the meta data
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Check user permissions
        if (!current_user_can('edit_cemetery_record', $post_id)) {
            return;
        }

        // Update meta fields
        $fields = array(
            'page_header',
            'page_footer',
            'page_location',
            'page_additional_info',
            'image_caption'
        );

        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                update_post_meta(
                    $post_id,
                    '_' . $field,
                    sanitize_text_field($_POST[$field])
                );
            }
        }
    }

    public function add_image_sizes() {
        add_image_size('cemetery-record-thumb', 300, 300, true);
        add_image_size('cemetery-record-medium', 600, 600, false);
        add_image_size('cemetery-record-large', 1200, 1200, false);
    }
} 