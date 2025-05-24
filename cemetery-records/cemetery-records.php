<?php
/**
 * Plugin Name: Cemetery Records
 * Plugin URI: https://cemeteries.phillipston.org/
 * Description: A WordPress plugin for managing cemetery records with image support
 * Version: 1.5.0
 * Author: Phillipston Historical Society
 * Author URI: https://phillipston.org/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cemetery-records
 * Domain Path: /languages
 */

// If this file is called directly, abort.
if (!defined('WPINC')) { die; }

// Define plugin constants
define('CEMETERY_RECORDS_VERSION', '1.5.0');
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
            $caps = array('edit_cemetery_records','edit_others_cemetery_records','publish_cemetery_records','read_private_cemetery_records','delete_cemetery_records','edit_published_cemetery_records','delete_published_cemetery_records','read_cemetery_record','edit_cemetery_record','delete_cemetery_record','create_cemetery_records');
            foreach ($caps as $cap) { if (!$role->has_cap($cap)) { $role->add_cap($cap); } }
        }
    }
    public function register_post_type() {
        $labels = array('name' => __('Cemetery Records', 'cemetery-records'),'singular_name' => __('Cemetery Record', 'cemetery-records'),'add_new' => __('Add New', 'cemetery-records'),'add_new_item' => __('Add New Record', 'cemetery-records'),'edit_item' => __('Edit Record', 'cemetery-records'),'new_item' => __('New Record', 'cemetery-records'),'view_item' => __('View Record', 'cemetery-records'),'search_items' => __('Search Records', 'cemetery-records'),'not_found' => __('No records found', 'cemetery-records'),'not_found_in_trash' => __('No records found in trash', 'cemetery-records'),'all_items' => __('All Records', 'cemetery-records'),'menu_name' => __('Cemetery Records', 'cemetery-records'));
        $args = array('labels' => $labels,'public' => true,'publicly_queryable' => true,'show_ui' => true,'show_in_menu' => true,'has_archive' => true,'supports' => array('title', 'thumbnail', 'custom-fields', 'revisions'),'menu_icon' => 'dashicons-book','rewrite' => array('slug' => 'cemetery-records', 'with_front' => true),'show_in_rest' => true,'capability_type' => 'post','map_meta_cap' => true);
        register_post_type('cemetery_record', $args);
    }
    public function enqueue_admin_assets($hook) {
        if ('cemetery_record_page_cemetery-records-import-export' !== $hook) { return; }
        $script_path = CEMETERY_RECORDS_PLUGIN_DIR . 'assets/js/admin-import-export.js';
        if (file_exists($script_path)) {
            wp_enqueue_script('cemetery-records-admin-io', CEMETERY_RECORDS_PLUGIN_URL . 'assets/js/admin-import-export.js', array('jquery'), CEMETERY_RECORDS_VERSION, true);
            wp_localize_script('cemetery-records-admin-io', 'cemeteryIO', array('ajax_url' => admin_url('admin-ajax.php'),'nonce' => wp_create_nonce('cemetery_io_nonce')));
        }
    }
    public function add_meta_boxes() { add_meta_box('cemetery_record_details', __('Record Details', 'cemetery-records'), array($this, 'render_details_meta_box'), 'cemetery_record', 'normal', 'high'); }
    public function render_details_meta_box($post) {
        wp_nonce_field('cemetery_record_details_nonce', 'cemetery_record_details_nonce');
        $fields = array('page_header' => __('Page Header:', 'cemetery-records'),'page_footer' => __('Page Footer:', 'cemetery-records'),'page_location' => __('Location:', 'cemetery-records'),'image_caption' => __('Image Caption:', 'cemetery-records'),'page_additional_info' => __('Additional Information:', 'cemetery-records'));
        echo '<div>';
        foreach ($fields as $field => $label) {
            $value = get_post_meta($post->ID, '_' . $field, true);
            echo '<p><label for="' . esc_attr($field) . '">' . esc_html($label) . '</label><br>';
            if ($field === 'page_additional_info') { echo '<textarea id="' . esc_attr($field) . '" name="' . esc_attr($field) . '" rows="4" style="width:100%;">' . esc_textarea($value) . '</textarea>'; } else { echo '<input type="text" id="' . esc_attr($field) . '" name="' . esc_attr($field) . '" value="' . esc_attr($value) . '" style="width:100%;">'; }
            echo '</p>';
        }
        echo '</div>';
    }
    public function save_post_meta($post_id) {
        if (!isset($_POST['cemetery_record_details_nonce']) || !wp_verify_nonce(sanitize_key($_POST['cemetery_record_details_nonce']), 'cemetery_record_details') || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !current_user_can('edit_post', $post_id)) { return; }
        $fields = array('page_header', 'page_footer', 'page_location', 'page_additional_info', 'image_caption');
        foreach ($fields as $field) { if (isset($_POST[$field])) { update_post_meta($post_id, '_' . $field, sanitize_text_field(wp_unslash($_POST[$field]))); } }
    }
    public function add_image_sizes() { add_image_size('cemetery-record-thumb', 300, 300, true); }
}

function cemetery_records_get_instance() { static $instance = null; if ($instance === null) { $instance = new Cemetery_Records_Minimal(); } return $instance; }
add_action('init', function() { cemetery_records_get_instance()->init(); }, 0);
add_action('admin_menu', function() { add_submenu_page('edit.php?post_type=cemetery_record',__('Import/Export', 'cemetery-records'),__('Import/Export', 'cemetery-records'),'manage_options','cemetery-records-import-export','cemetery_records_render_import_export_page'); });

function cemetery_records_render_import_export_page() {
    if (!current_user_can('manage_options')) { wp_die(__('You do not have sufficient permissions to access this page.')); }
    ?>
    <style> .progress-bar-wrapper { width: 100%; background-color: #f3f3f3; border: 1px solid #ccc; border-radius: 4px; margin-top:10px; } .progress-bar { width: 0%; height: 30px; background-color: #4CAF50; text-align: center; line-height: 30px; color: white; border-radius: 4px; transition: width 0.4s ease; display: none; } </style>
    <div class="wrap">
        <h1><?php _e('Cemetery Records Import/Export', 'cemetery-records'); ?></h1>
        <div id="poststuff">
            <div class="postbox">
                <h2 class="hndle"><span><?php _e('Export Records to CSV', 'cemetery-records'); ?></span></h2>
                <div class="inside">
                    <p><?php _e('Click the button below to export all cemetery records to a CSV file.', 'cemetery-records'); ?></p>
                    <p><button id="cemetery-export-button" class="button button-primary"><?php _e('Start Export', 'cemetery-records'); ?></button></p>
                    <div class="progress-bar-wrapper"><div id="cemetery-export-progress-bar" class="progress-bar"></div></div>
                    <p id="cemetery-export-status" style="font-style: italic; margin-top:10px;"></p>
                </div>
            </div>
            <div class="postbox">
                <h2 class="hndle"><span><?php _e('Import Records from CSV', 'cemetery-records'); ?></span></h2>
                <div class="inside">
                    <p><strong><?php _e('Important:', 'cemetery-records'); ?></strong> <?php _e('For a round-trip, export your data first. You can then edit the CSV and re-import it to update records. To create new records, add new rows but leave the ID column blank.', 'cemetery-records'); ?></p>
                    <p><input type="file" id="cemetery-import-file" accept=".csv"></p>
                    <p><button id="cemetery-import-button" class="button button-primary"><?php _e('Start Import', 'cemetery-records'); ?></button></p>
                    <div class="progress-bar-wrapper"><div id="cemetery-import-progress-bar" class="progress-bar"></div></div>
                    <p id="cemetery-import-status" style="font-style: italic; margin-top:10px;"></p>
                </div>
            </div>
        </div>
    </div>
    <?php
}

// AJAX Handlers
add_action('wp_ajax_cemetery_export_preflight', 'cemetery_export_preflight_handler');
add_action('wp_ajax_cemetery_export_batch', 'cemetery_export_batch_handler');
add_action('wp_ajax_cemetery_import_batch', 'cemetery_import_batch_handler');

function cemetery_export_preflight_handler() {
    check_ajax_referer('cemetery_io_nonce', '_ajax_nonce');
    if (!current_user_can('manage_options')) { wp_send_json_error(['message' => 'Permission denied.']); }
    $query = new WP_Query(['post_type' => 'cemetery_record','post_status' => 'any','posts_per_page' => -1,'fields' => 'ids']);
    $total_records = $query->post_count;
    wp_send_json_success(['total_records' => $total_records,'batch_size' => 100,'total_pages' => ($total_records > 0) ? ceil($total_records / 100) : 0]);
}

function cemetery_export_batch_handler() {
    check_ajax_referer('cemetery_io_nonce', '_ajax_nonce');
    if (!current_user_can('manage_options')) { wp_send_json_error(['message' => 'Permission denied.']); }
    $page = isset($_POST['page']) ? absint($_POST['page']) : 1;
    $query = new WP_Query(['post_type' => 'cemetery_record','post_status' => 'any','posts_per_page' => 100,'paged' => $page,'orderby' => 'ID','order' => 'ASC']);
    $data = [];
    $export_fields = ['ID' => 'ID','Title' => 'post_title','Date' => 'post_date','Location' => 'page_location','Additional Info' => 'page_additional_info','Image Caption' => 'image_caption','Header' => 'page_header','Footer' => 'page_footer'];
    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();
            $row = [];
            foreach ($export_fields as $header => $key) {
                if ($key === 'ID') $row[$header] = $post_id;
                elseif ($key === 'post_title') $row[$header] = get_the_title();
                elseif ($key === 'post_date') $row[$header] = get_the_date('Y-m-d H:i:s');
                else $row[$header] = get_post_meta($post_id, '_' . $key, true);
            }
            $data[] = $row;
        }
    }
    wp_reset_postdata();
    wp_send_json_success($data);
}

function cemetery_import_batch_handler() {
    check_ajax_referer('cemetery_io_nonce', '_ajax_nonce');
    if (!current_user_can('manage_options')) { wp_send_json_error(['message' => 'Permission denied.']); }
    
    $records = isset($_POST['records']) ? json_decode(stripslashes($_POST['records']), true) : [];
    if (empty($records) || !is_array($records)) {
        wp_send_json_error(['message' => 'No records received or invalid data format.']);
    }

    $created = 0;
    $updated = 0;
    
    // Reverse the export fields map to find the meta key from the CSV header
    $fields_map = ['ID' => 'ID','Title' => 'post_title','Date' => 'post_date','Location' => 'page_location','Additional Info' => 'page_additional_info','Image Caption' => 'image_caption','Header' => 'page_header','Footer' => 'page_footer'];
    
    foreach ($records as $record) {
        $post_id = !empty($record['ID']) ? absint($record['ID']) : 0;
        
        $post_data = [
            'post_type' => 'cemetery_record',
            'post_status' => 'publish',
            'post_title' => isset($record['Title']) ? sanitize_text_field($record['Title']) : '',
            'post_date' => isset($record['Date']) ? sanitize_text_field($record['Date']) : null
        ];

        if ($post_id > 0 && get_post_status($post_id)) {
            $post_data['ID'] = $post_id;
            wp_update_post($post_data);
            $updated++;
        } else {
            $post_id = wp_insert_post($post_data);
            $created++;
        }

        if ($post_id && !is_wp_error($post_id)) {
            foreach ($fields_map as $header => $key) {
                if (isset($record[$header]) && !in_array($key, ['ID', 'post_title', 'post_date'])) {
                    update_post_meta($post_id, '_' . $key, sanitize_text_field($record[$header]));
                }
            }
        }
    }
    
    wp_send_json_success(['created' => $created, 'updated' => $updated]);
}

// Activation/Deactivation hooks
register_activation_hook(__FILE__, function() { cemetery_records_get_instance()->init(); flush_rewrite_rules(); });
register_deactivation_hook(__FILE__, 'flush_rewrite_rules');

// Template loading
add_filter('single_template', function($template) { if (is_singular('cemetery_record')) { $custom_template = CEMETERY_RECORDS_PLUGIN_DIR . 'templates/single-cemetery_record.php'; if (file_exists($custom_template)) { return $custom_template; } } return $template; });

// Plugin action links
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) { $settings_link = '<a href="' . admin_url('edit.php?post_type=cemetery_record&page=cemetery-records-import-export') . '">' . __('Import/Export', 'cemetery-records') . '</a>'; array_unshift($links, $settings_link); return $links; });
