<?php
/**
 * Plugin Name: Cemetery Records
 * Plugin URI: https://cemeteries.phillipston.org/
 * Description: A WordPress plugin for managing cemetery records with image support
 * Version: 2.0.6
 * Author: Phillipston Historical Society
 * Author URI: https://phillipston.org/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cemetery-records
 * Domain Path: /languages
 */

if (!defined('WPINC')) { die; }

define('CEMETERY_RECORDS_VERSION', '2.0.6'); // Updated version
define('CEMETERY_RECORDS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CEMETERY_RECORDS_PLUGIN_URL', plugin_dir_url(__FILE__));

class Cemetery_Records_Minimal {
    public function init() {
        $this->register_post_type();
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post_cemetery_record', array($this, 'save_post_meta'));
        add_action('after_setup_theme', array($this, 'add_image_sizes'));
        $this->setup_capabilities();
    }

    public function setup_capabilities() {
        $role = get_role('administrator');
        if ($role) {
            $caps = array('edit_cemetery_records', 'edit_others_cemetery_records', 'publish_cemetery_records', 'read_private_cemetery_records', 'delete_cemetery_records', 'edit_published_cemetery_records', 'delete_published_cemetery_records', 'read_cemetery_record', 'edit_cemetery_record', 'delete_cemetery_record', 'create_cemetery_records');
            foreach ($caps as $cap) { if (!$role->has_cap($cap)) { $role->add_cap($cap); } }
        }
    }

    public function register_post_type() {
        $labels = array('name' => __('Cemetery Records', 'cemetery-records'), 'singular_name' => __('Cemetery Record', 'cemetery-records'), 'add_new' => __('Add New', 'cemetery-records'), 'add_new_item' => __('Add New Record', 'cemetery-records'), 'edit_item' => __('Edit Record', 'cemetery-records'), 'new_item' => __('New Record', 'cemetery-records'), 'view_item' => __('View Record', 'cemetery-records'), 'search_items' => __('Search Records', 'cemetery-records'), 'not_found' => __('No records found', 'cemetery-records'), 'not_found_in_trash' => __('No records found in trash', 'cemetery-records'), 'all_items' => __('All Records', 'cemetery-records'), 'menu_name' => __('Cemetery Records', 'cemetery-records'));
        $args = array('labels' => $labels, 'public' => true, 'publicly_queryable' => true, 'show_ui' => true, 'show_in_menu' => true, 'show_in_nav_menus' => true, 'show_in_admin_bar' => true, 'has_archive' => true, 'hierarchical' => false, 'supports' => array('title', 'thumbnail', 'custom-fields', 'revisions'), 'menu_icon' => 'dashicons-book', 'rewrite' => array('slug' => 'cemetery-records', 'with_front' => true), 'show_in_rest' => true, 'rest_base' => 'cemetery-records', 'capability_type' => 'post', 'map_meta_cap' => true, 'menu_position' => 25);
        register_post_type('cemetery_record', $args);
    }

    public function enqueue_admin_assets($hook) {
        if ('cemetery_record_page_cemetery-records-import-export' !== $hook && get_post_type() !== 'cemetery_record') { return; }
        if ('cemetery_record_page_cemetery-records-import-export' === $hook) {
             $script_path = CEMETERY_RECORDS_PLUGIN_DIR . 'assets/js/admin-import-export.js';
            if (file_exists($script_path)) {
                wp_enqueue_script('cemetery-records-admin-io', CEMETERY_RECORDS_PLUGIN_URL . 'assets/js/admin-import-export.js', array('jquery'), CEMETERY_RECORDS_VERSION, true);
                wp_localize_script('cemetery-records-admin-io', 'cemeteryIO', array('ajax_url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('cemetery_io_nonce')));
            }
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
        wp_nonce_field('cemetery_record_details_save', 'cemetery_record_details_nonce');

        // Using actual meta keys as array keys and for form field names
        $meta_fields = array(
            '_page_header'                 => __('Page Header (Title):', 'cemetery-records'),
            '_page_footer'                 => __('Page Footer:', 'cemetery-records'),
            '_page_location'               => __('Location:', 'cemetery-records'),
            '_image_caption'               => __('Image Caption:', 'cemetery-records'),
            '_page_additional_info'        => __('Additional Information:', 'cemetery-records'),
            '_source_page_attachment_id'   => __('Source Page Attachment ID:', 'cemetery-records'),
            '_extracted_image_attachment_id' => __('Extracted Image Attachment ID:', 'cemetery-records')
        );

        echo '<div>';
        foreach ($meta_fields as $meta_key => $label) {
            $value = get_post_meta($post->ID, $meta_key, true);

            echo '<p><label for="' . esc_attr($meta_key) . '">' . esc_html($label) . '</label><br>';
            if ($meta_key === '_page_additional_info') {
                echo '<textarea id="' . esc_attr($meta_key) . '" name="' . esc_attr($meta_key) . '" rows="4" style="width:100%;">' . esc_textarea($value) . '</textarea>';
            } else {
                echo '<input type="text" id="' . esc_attr($meta_key) . '" name="' . esc_attr($meta_key) . '" value="' . esc_attr($value) . '" style="width:100%;">';
            }
            echo '</p>';
        }
        echo '</div>';

        // Displaying images with links to their Media Library edit screen
        $source_attachment_id = get_post_meta($post->ID, '_source_page_attachment_id', true);
        $extracted_attachment_id = get_post_meta($post->ID, '_extracted_image_attachment_id', true);

        if ($source_attachment_id) {
            $edit_link = get_edit_post_link($source_attachment_id);
            echo '<hr><h4>' . __('Source Page Image:', 'cemetery-records') . '</h4>';
            if ($edit_link) {
                echo '<p><a href="' . esc_url($edit_link) . '" target="_blank" title="' . esc_attr__('Click to view in Media Library', 'cemetery-records') . '">' . wp_get_attachment_image($source_attachment_id, 'medium') . '</a></p>';
                echo '<p><small>' . __('Click image to view in Media Library and zoom.', 'cemetery-records') . '</small></p>';
            } else {
                echo '<p>' . wp_get_attachment_image($source_attachment_id, 'medium') . '</p>';
            }
        }
        if ($extracted_attachment_id) {
            $edit_link = get_edit_post_link($extracted_attachment_id);
            echo '<hr><h4>' . __('Extracted Image:', 'cemetery-records') . '</h4>';
            if ($edit_link) {
                echo '<p><a href="' . esc_url($edit_link) . '" target="_blank" title="' . esc_attr__('Click to view in Media Library', 'cemetery-records') . '">' . wp_get_attachment_image($extracted_attachment_id, 'medium') . '</a></p>';
                echo '<p><small>' . __('Click image to view in Media Library and zoom.', 'cemetery-records') . '</small></p>';
            } else {
                echo '<p>' . wp_get_attachment_image($extracted_attachment_id, 'medium') . '</p>';
            }
        }
    }

    public function save_post_meta($post_id) {
        // Verify nonce
        if (!isset($_POST['cemetery_record_details_nonce']) || !wp_verify_nonce($_POST['cemetery_record_details_nonce'], 'cemetery_record_details_save')) {
            return;
        }
        // Check if it's an autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        // Check permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Define which fields to save from the form (using actual meta keys)
        $meta_keys_to_save = array(
            '_page_header', '_page_footer', '_page_location', '_image_caption', '_page_additional_info',
            '_source_page_attachment_id', '_extracted_image_attachment_id'
        );

        foreach ($meta_keys_to_save as $meta_key) {
            if (isset($_POST[$meta_key])) {
                $value = wp_unslash($_POST[$meta_key]);
                if ($meta_key === '_source_page_attachment_id' || $meta_key === '_extracted_image_attachment_id') {
                    update_post_meta($post_id, $meta_key, absint($value));
                } else {
                    update_post_meta($post_id, $meta_key, sanitize_text_field($value));
                }
            } else {
                 // If a field isn't set (e.g., an empty textarea might not be),
                 // you might want to delete the meta or save an empty string.
                 // For simplicity, we'll just update if set.
                 // update_post_meta($post_id, $meta_key, ''); // Example: save empty string if not set
            }
        }
    }



    public function add_image_sizes() {
        add_image_size('cemetery-record-thumb', 300, 300, true);
    }
}

function cemetery_records_get_instance() { static $instance = null; if ($instance === null) { $instance = new Cemetery_Records_Minimal(); } return $instance; }
add_action('init', function () { cemetery_records_get_instance()->init(); }, 0);

add_action('admin_menu', function () { add_submenu_page('edit.php?post_type=cemetery_record', __('Import/Export', 'cemetery-records'), __('Import/Export', 'cemetery-records'), 'manage_options', 'cemetery-records-import-export', 'cemetery_records_render_import_export_page'); });

function cemetery_records_render_import_export_page() {
    if (!current_user_can('manage_options')) { wp_die(__('You do not have sufficient permissions to access this page.')); }
    ?>
    <style> .progress-bar-wrapper { width: 100%; background-color: #f3f3f3; border: 1px solid #ccc; border-radius: 4px; margin-top:10px; } .progress-bar { width: 0%; height: 30px; background-color: #4CAF50; text-align: center; line-height: 30px; color: white; border-radius: 4px; transition: width 0.4s ease; display: none; } #cemetery-delete-all-button { background-color: #dc3545; border-color: #dc3545; color: #fff; } #cemetery-delete-all-button:hover { background-color: #c82333; border-color: #bd2130; } </style>
    <div class="wrap">
        <h1><?php _e('Cemetery Records Import/Export/Delete', 'cemetery-records'); ?></h1>
        <div id="poststuff">
            <div class="postbox"> <h2 class="hndle"><span><?php _e('Export Records to JSON', 'cemetery-records'); ?></span></h2> <div class="inside"><p><?php _e('Click the button below to export all cemetery records to a JSON file.', 'cemetery-records'); ?></p><p><button id="cemetery-export-button" class="button button-primary"><?php _e('Start Export', 'cemetery-records'); ?></button></p><div class="progress-bar-wrapper"><div id="cemetery-export-progress-bar" class="progress-bar"></div></div><p id="cemetery-export-status" style="font-style: italic; margin-top:10px;"></p></div></div>
            <div class="postbox"> <h2 class="hndle"><span><?php _e('Import Records from JSON', 'cemetery-records'); ?></span></h2> <div class="inside"><p><strong><?php _e('Important:', 'cemetery-records'); ?></strong> <?php _e('The importer expects image paths in your JSON (e.g., "my-image.jpg" or "folder/my-image.jpg") to be located in <code>wp-content/uploads/pagefiles/</code> for "source_page" fields and <code>wp-content/uploads/imgsrc/</code> for "extracted_image" fields. For round-trip imports, it expects paths relative to the uploads directory (e.g., "2023/05/my-image.jpg").', 'cemetery-records'); ?></p><p><input type="file" id="cemetery-import-file" accept=".json,application/json"></p><p><button id="cemetery-import-button" class="button button-primary"><?php _e('Start Import', 'cemetery-records'); ?></button></p><div class="progress-bar-wrapper"><div id="cemetery-import-progress-bar" class="progress-bar"></div></div><p id="cemetery-import-status" style="font-style: italic; margin-top:10px;"></p></div></div>
            <div class="postbox"> <h2 class="hndle" style="color:#dc3545;"><span><?php _e('Danger Zone', 'cemetery-records'); ?></span></h2> <div class="inside"><p><strong><?php _e('WARNING:', 'cemetery-records'); ?></strong> <?php _e('This action will permanently delete ALL cemetery records and cannot be undone. Please backup your database before proceeding.', 'cemetery-records'); ?></p><p><button id="cemetery-delete-all-button" class="button"><?php _e('Delete All Records', 'cemetery-records'); ?></button></p><p id="cemetery-delete-status" style="font-style: italic; margin-top:10px; color:#dc3545;"></p></div></div>
        </div>
    </div>
    <?php
}

add_action('wp_ajax_cemetery_export_preflight', 'cemetery_export_preflight_handler');
add_action('wp_ajax_cemetery_export_batch', 'cemetery_export_batch_handler');
add_action('wp_ajax_cemetery_import_batch', 'cemetery_import_batch_handler');
add_action('wp_ajax_cemetery_delete_all_batch', 'cemetery_delete_all_batch_handler');

function cemetery_export_preflight_handler() {
    check_ajax_referer('cemetery_io_nonce', '_ajax_nonce');
    if (!current_user_can('manage_options')) { wp_send_json_error(['message' => 'Permission denied.']); }
    $query = new WP_Query(['post_type' => 'cemetery_record', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids']);
    wp_send_json_success(['total_records' => $query->post_count, 'batch_size' => 100, 'total_pages' => ($query->post_count > 0) ? ceil($query->post_count / 100) : 0]);
}

function cemetery_import_batch_handler() {
    check_ajax_referer('cemetery_io_nonce', '_ajax_nonce');
    if (!current_user_can('manage_options')) { wp_send_json_error(['message' => 'Permission denied.']); }
    
    $records = isset($_POST['records']) ? json_decode(stripslashes($_POST['records']), true) : [];
    if (empty($records) || !is_array($records)) { wp_send_json_error(['message' => 'No records received or invalid data format.']); }

    $created = 0; $updated = 0;
    
    foreach ($records as $record) {
        $post_id = !empty($record['ID']) ? absint($record['ID']) : 0;
        
        $post_title = '';
        if (isset($record['Title'])) { $post_title = sanitize_text_field($record['Title']); } 
        elseif (isset($record['page_header'])) { $post_title = sanitize_text_field($record['page_header']); }

        $post_date_input = null;
        if (isset($record['Date'])) { $post_date_input = sanitize_text_field($record['Date']); }
        elseif (isset($record['post_date'])) { $post_date_input = sanitize_text_field($record['post_date']); }

        $post_data = ['post_type' => 'cemetery_record', 'post_status' => 'publish', 'post_title' => $post_title];
        if ($post_date_input) { $post_data['post_date'] = $post_date_input; }

        $existing_post = ($post_id > 0) ? get_post($post_id) : null;
        if ($existing_post && $existing_post->post_type === 'cemetery_record') {
            $post_data['ID'] = $post_id;
            wp_update_post($post_data);
            $updated++;
        } else {
            $new_post_id = wp_insert_post($post_data, true);
            if (is_wp_error($new_post_id)) { error_log("Cemetery Records: Error inserting post '{$post_title}': " . $new_post_id->get_error_message()); continue; }
            $post_id = $new_post_id;
            $created++;
        }

        if ($post_id && !is_wp_error($post_id)) {
            $original_source_meta_map = [ 
                'page_location'        => 'page_location', 
                'page_additional_info' => 'page_additional_info', 
                'image_caption'        => 'image_caption', 
                'page_header'          => 'page_header',
                'page_footer'          => 'page_footer'
            ];
            $round_trip_meta_map = [
                'Location'        => 'page_location', 
                'Additional Info' => 'page_additional_info', 
                'Image Caption'   => 'image_caption', 
                'Header'          => 'page_header', 
                'Footer'          => 'page_footer'
            ];

            foreach ($original_source_meta_map as $original_json_key => $internal_meta_stem) {
                $value_to_save = null;
                if (isset($record[$original_json_key])) {
                    $value_to_save = sanitize_text_field($record[$original_json_key]);
                } else { 
                    $round_trip_json_key = array_search($internal_meta_stem, $round_trip_meta_map, true);
                    if ($round_trip_json_key !== false && isset($record[$round_trip_json_key])) {
                        $value_to_save = sanitize_text_field($record[$round_trip_json_key]);
                    }
                }
                if ($value_to_save !== null) { 
                    update_post_meta($post_id, '_' . $internal_meta_stem, $value_to_save); 
                }
            }

            if (isset($record['source_page'])) { 
                cemetery_process_image_from_path($post_id, $record['source_page'], '_source_page_attachment_id', 'source_page'); 
            }
            if (isset($record['extracted_image'])) { 
                cemetery_process_image_from_path($post_id, $record['extracted_image'], '_extracted_image_attachment_id', 'extracted_image'); 
            }
        }
    }
    wp_send_json_success(['created' => $created, 'updated' => $updated]);
}

function cemetery_process_image_from_path($post_id, $path_from_json_value, $meta_key_for_attachment_id, $image_type) {
    // Ensure WordPress media functions are available
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');

    $trimmed_path = trim($path_from_json_value);
    if (empty($trimmed_path)) {
        error_log("Cemetery Records (Post ID: {$post_id}): Empty image path provided for {$image_type}.");
        return null;
    }

    $upload_dir = wp_upload_dir();
    $path_meta_key = str_replace('_id', '_path', $meta_key_for_attachment_id); // e.g., _source_page_attachment_path
    $is_staging_path = (strpos($trimmed_path, 'pagefiles/') === 0 || strpos($trimmed_path, 'imgsrc/') === 0 || strpos($trimmed_path, '/') === false);

    // CASE 1: Initial import from a staging directory (e.g., "image.jpg" or "pagefiles/image.jpg")
    if ($is_staging_path) {
        $source_file_in_staging = '';
        if (strpos($trimmed_path, '/') === false) { // Simple filename, e.g., "image.jpg"
            $staging_subdir = ($image_type === 'source_page') ? 'pagefiles/' : 'imgsrc/';
            $source_file_in_staging = $upload_dir['basedir'] . '/' . $staging_subdir . $trimmed_path;
        } else { // Path includes staging dir, e.g., "pagefiles/image.jpg"
            $source_file_in_staging = $upload_dir['basedir'] . '/' . $trimmed_path;
        }

        $original_filename = basename($trimmed_path);
        $sanitized_filename = sanitize_file_name($original_filename);

        // Check for duplicates in the main Media Library (not staging folders) using the sanitized name
        global $wpdb;
        $duplicate_check_sql = $wpdb->prepare(
            "SELECT pm.post_id, pm.meta_value FROM `{$wpdb->postmeta}` pm
             JOIN `{$wpdb->posts}` p ON pm.post_id = p.ID
             WHERE pm.meta_key = '_wp_attached_file'
             AND p.post_type = 'attachment'
             AND pm.meta_value LIKE %s",
            '%' . $wpdb->esc_like($sanitized_filename)
        );
        $existing_media_library_attachment = $wpdb->get_row($duplicate_check_sql);

        if ($existing_media_library_attachment && file_exists($upload_dir['basedir'] . '/' . $existing_media_library_attachment->meta_value)) {
            $existing_id = $existing_media_library_attachment->post_id;
            $existing_relative_path = $existing_media_library_attachment->meta_value;
            update_post_meta($post_id, $meta_key_for_attachment_id, $existing_id);
            update_post_meta($post_id, $path_meta_key, $existing_relative_path);
            error_log("Cemetery Records (Post ID: {$post_id}): Re-used existing Media Library attachment ID {$existing_id} for staging image '{$original_filename}'.");
            return $existing_id;
        }

        // If no duplicate found, proceed to sideload the original file from staging
        if (!file_exists($source_file_in_staging)) {
            error_log("Cemetery Records (Post ID: {$post_id}): Staging image file not found at '{$source_file_in_staging}'.");
            return null;
        }
        
        $file_array = array(
            'name'     => $original_filename, // Use the original filename for the upload
            'tmp_name' => $source_file_in_staging
        );

        $attach_id = media_handle_sideload($file_array, $post_id, null);

        if (is_wp_error($attach_id)) {
            error_log("Cemetery Records (Post ID: {$post_id}): Error sideloading image '{$original_filename}': " . $attach_id->get_error_message());
            return null;
        } else {
            $new_relative_path = get_post_meta($attach_id, '_wp_attached_file', true);
            update_post_meta($post_id, $meta_key_for_attachment_id, $attach_id);
            update_post_meta($post_id, $path_meta_key, $new_relative_path);
            error_log("Cemetery Records (Post ID: {$post_id}): Successfully sideloaded '{$original_filename}' as attachment ID {$attach_id}. Path: {$new_relative_path}");
            return $attach_id;
        }
    }
    // CASE 2: Round-trip import (path is like "2023/05/image.jpg")
    else {
        $target_relative_path = ltrim($trimmed_path, '/');
        $full_target_path_on_server = $upload_dir['basedir'] . '/' . $target_relative_path;

        global $wpdb;
        $existing_attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM `{$wpdb->postmeta}` WHERE meta_key = '_wp_attached_file' AND meta_value = %s",
            $target_relative_path
        ));

        if ($existing_attachment_id && file_exists($full_target_path_on_server)) {
            update_post_meta($post_id, $meta_key_for_attachment_id, $existing_attachment_id);
            update_post_meta($post_id, $path_meta_key, $target_relative_path);
            error_log("Cemetery Records (Post ID: {$post_id}): Re-used existing attachment ID {$existing_attachment_id} for round-trip image '{$target_relative_path}'.");
            return $existing_attachment_id;
        } else {
            error_log("Cemetery Records (Post ID: {$post_id}): Round-trip image '{$target_relative_path}' not found as an existing attachment or file missing. Path: {$full_target_path_on_server}");
            return null;
        }
    }
}

function cemetery_export_batch_handler() {
    check_ajax_referer('cemetery_io_nonce', '_ajax_nonce');
    if (!current_user_can('manage_options')) { wp_send_json_error(['message' => 'Permission denied.']); }
    $page = isset($_POST['page']) ? absint($_POST['page']) : 1;
    $query = new WP_Query(['post_type' => 'cemetery_record', 'post_status' => 'any', 'posts_per_page' => 100, 'paged' => $page, 'orderby' => 'ID', 'order' => 'ASC']);
    $data = [];
    $export_fields = [
        'ID' => 'ID', 'Title' => 'post_title', 'Date' => 'post_date', 
        'Location' => 'page_location', 'Additional Info' => 'page_additional_info', 
        'Image Caption' => 'image_caption', 'Header' => 'page_header', 'Footer' => 'page_footer',
	'Plot Number' => 'plot_number', // Custom Records
        'Plot Data' => 'plot_number', // Custom Records
        'source_page' => '_source_page_attachment_id', 
        'extracted_image' => '_extracted_image_attachment_id'
    ];
    if ($query->have_posts()) {
        foreach ($query->posts as $post_object) {
            $row = [];
            foreach ($export_fields as $header => $key) {
                if ($key === 'ID') { $row[$header] = $post_object->ID; } 
                elseif ($key === 'post_title') { $row[$header] = $post_object->post_title; } 
                elseif ($key === 'post_date') { $row[$header] = $post_object->post_date; } 
                elseif ($key === '_source_page_attachment_id' || $key === '_extracted_image_attachment_id') {
                    $path_meta_key = str_replace('_id', '_path', $key); 
                    $path_value = get_post_meta($post_object->ID, $path_meta_key, true);
                    $row[$header] = $path_value ? $path_value : '';
                }
                else { $row[$header] = get_post_meta($post_object->ID, '_' . $key, true); }
            }
            $data[] = $row;
        }
    }
    wp_send_json_success($data);
}

function cemetery_delete_all_batch_handler() {
    check_ajax_referer('cemetery_io_nonce', '_ajax_nonce');
    if (!current_user_can('manage_options')) { wp_send_json_error(['message' => 'Permission Denied.']); }
    $posts_to_delete = get_posts(['post_type' => 'cemetery_record', 'post_status' => 'any', 'numberposts' => 100, 'fields' => 'ids']);
    if (empty($posts_to_delete)) { wp_send_json_success(['deleted' => 0]); }
    $deleted_count = 0;
    foreach ($posts_to_delete as $post_id) { wp_delete_post($post_id, true); $deleted_count++; }
    wp_send_json_success(['deleted' => $deleted_count]);
}

register_activation_hook(__FILE__, function () { cemetery_records_get_instance()->init(); flush_rewrite_rules(); });
register_deactivation_hook(__FILE__, 'flush_rewrite_rules');
add_filter('single_template', function ($template) { if (is_singular('cemetery_record')) { $custom_template = CEMETERY_RECORDS_PLUGIN_DIR . 'templates/single-cemetery_record.php'; if (file_exists($custom_template)) { return $custom_template; } } return $template; });
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) { $settings_link = '<a href="' . admin_url('edit.php?post_type=cemetery_record&page=cemetery-records-import-export') . '">' . __('Import/Export', 'cemetery-records') . '</a>'; array_unshift($links, $settings_link); return $links; });

