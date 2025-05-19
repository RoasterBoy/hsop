<?php
/**
 * Plugin Name: Cemetery Records
 * Plugin URI: https://cemeteries.phillipston.org/
 * Description: A WordPress plugin for managing cemetery records with image support
 * Version: 1.4.9
 * Author: Phillipston Historical Society
 * Author URI: https://phillipston.org/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cemetery-records
 * Domain Path: /languages
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Define plugin constants
define('CEMETERY_RECORDS_VERSION', '1.4.8');
define('CEMETERY_RECORDS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CEMETERY_RECORDS_PLUGIN_URL', plugin_dir_url(__FILE__));

// Require the core plugin classes
require_once CEMETERY_RECORDS_PLUGIN_DIR . 'includes/class-cemetery-records.php';
require_once CEMETERY_RECORDS_PLUGIN_DIR . 'includes/class-cemetery-records-import-export.php';
require_once CEMETERY_RECORDS_PLUGIN_DIR . 'includes/class-cemetery-records-background-process.php';

// Initialize the plugin
function cemetery_records_v148_run() {
    $plugin = new Cemetery_Records();
    $plugin->init();

    $import_export = new Cemetery_Records_Import_Export();
    $import_export->init();
}

// Hook into WordPress
add_action('plugins_loaded', 'cemetery_records_v148_run');

// Register activation hook
register_activation_hook(__FILE__, 'cemetery_records_v148_activate');

function cemetery_records_v148_activate() {
    // Ensure proper capabilities are set
    $role = get_role('administrator');
    if ($role) {
        $role->add_cap('edit_cemetery_records');
        $role->add_cap('edit_others_cemetery_records');
        $role->add_cap('publish_cemetery_records');
        $role->add_cap('read_private_cemetery_records');
        $role->add_cap('delete_cemetery_records');
        $role->add_cap('edit_published_cemetery_records');
        $role->add_cap('delete_published_cemetery_records');
        $role->add_cap('read_cemetery_record');
        $role->add_cap('edit_cemetery_record');
        $role->add_cap('delete_cemetery_record');
        $role->add_cap('create_cemetery_records');
    }

    // Register post type to ensure rewrite rules are set
    $plugin = new Cemetery_Records();
    $plugin->register_post_type();

    // Flush rewrite rules
    flush_rewrite_rules();
}

// Register deactivation hook
register_deactivation_hook(__FILE__, 'cemetery_records_v148_deactivate');

function cemetery_records_v148_deactivate() {
    // Remove capabilities
    $role = get_role('administrator');
    if ($role) {
        $role->remove_cap('edit_cemetery_records');
        $role->remove_cap('edit_others_cemetery_records');
        $role->remove_cap('publish_cemetery_records');
        $role->remove_cap('read_private_cemetery_records');
        $role->remove_cap('delete_cemetery_records');
        $role->remove_cap('edit_published_cemetery_records');
        $role->remove_cap('delete_published_cemetery_records');
        $role->remove_cap('read_cemetery_record');
        $role->remove_cap('edit_cemetery_record');
        $role->remove_cap('delete_cemetery_record');
        $role->remove_cap('create_cemetery_records');
    }

    // Flush rewrite rules
    flush_rewrite_rules();
}

// Add admin menu
function cemetery_records_v148_admin_menu() {
    // First check if user has required capabilities
    if (!current_user_can('manage_options')) {
        return;
    }

    // Check if the Import/Export class exists and is accessible
    if (!class_exists('Cemetery_Records_Import_Export')) {
        error_log('Cemetery Records: Import/Export class not found in admin_menu');
        // Try to load it directly
        $import_export_file = CEMETERY_RECORDS_PLUGIN_DIR . 'includes/class-cemetery-records-import-export.php';
        if (file_exists($import_export_file)) {
            require_once $import_export_file;
        }
    }

    if (!class_exists('Cemetery_Records_Import_Export')) {
        error_log('Cemetery Records: Import/Export class still not found after direct load attempt');
        return;
    }

    try {
        $import_export = new Cemetery_Records_Import_Export();
        
        add_submenu_page(
            'edit.php?post_type=cemetery_record',
            __('Import/Export', 'cemetery-records'),
            __('Import/Export', 'cemetery-records'),
            'manage_options',
            'cemetery-records-import-export',
            array($import_export, 'render_page')
        );
    } catch (Exception $e) {
        error_log('Cemetery Records: Error initializing Import/Export menu: ' . $e->getMessage());
        error_log('Cemetery Records: Stack trace: ' . $e->getTraceAsString());
    }
}

// Add plugin action links
function cemetery_records_v148_plugin_action_links($links) {
    $plugin_links = array(
        '<a href="' . admin_url('edit.php?post_type=cemetery_record&page=cemetery-records-import-export') . '">' . 
        __('Import/Export', 'cemetery-records') . '</a>'
    );
    return array_merge($plugin_links, $links);
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'cemetery_records_v148_plugin_action_links');

// Admin styles
function cemetery_records_v148_admin_styles($hook) {
    if (strpos($hook, 'cemetery-records') !== false || 
        (get_post_type() === 'cemetery_record' && ($hook === 'post.php' || $hook === 'post-new.php'))) {
        
        wp_enqueue_style(
            'cemetery-records-admin',
            CEMETERY_RECORDS_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            CEMETERY_RECORDS_VERSION
        );
    }
}
add_action('admin_enqueue_scripts', 'cemetery_records_v148_admin_styles');

// Public styles
function cemetery_records_v148_public_styles() {
    if (is_singular('cemetery_record')) {
        wp_enqueue_style(
            'cemetery-records-public',
            CEMETERY_RECORDS_PLUGIN_URL . 'assets/css/public.css',
            array(),
            CEMETERY_RECORDS_VERSION
        );
    }
}
add_action('wp_enqueue_scripts', 'cemetery_records_v148_public_styles');

// Load custom template for single cemetery records
function cemetery_records_v148_template($template) {
    if (is_singular('cemetery_record')) {
        $custom_template = CEMETERY_RECORDS_PLUGIN_DIR . 'templates/single-cemetery_record.php';
        if (file_exists($custom_template)) {
            return $custom_template;
        }
    }
    return $template;
}
add_filter('single_template', 'cemetery_records_v148_template');

// Add AJAX handlers for import process
add_action('wp_ajax_start_import', 'cemetery_records_v148_start_import');
add_action('wp_ajax_check_import_progress', 'cemetery_records_v148_check_progress');

function cemetery_records_v148_start_import() {
    check_ajax_referer('cemetery_records_import', 'nonce');

    try {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }

        // Initialize background process
        $background_process = new Cemetery_Records_Import_Background_Process();
        $import_id = $background_process->get_import_id();

        // Get import data from uploaded file
        $import_data = json_decode(file_get_contents($_FILES['import_file']['tmp_name']), true);
        if (!$import_data) {
            wp_send_json_error(array('message' => 'Invalid JSON file'));
            return;
        }

        // Setup progress tracking
        $total_records = count($import_data);
        $background_process->save_progress(array(
            'status' => 'processing',
            'current_record' => 0,
            'total_records' => $total_records,
            'percent_complete' => 0
        ));

        // Queue records for processing
        foreach ($import_data as $index => $record) {
            $background_process->push_to_queue(array(
                'record' => $record,
                'record_number' => $index + 1,
                'extracted_images_path' => $_POST['extracted_images_path'],
                'source_pages_path' => $_POST['source_pages_path'],
                'results' => array()
            ));
        }

        // Start the background process
        $background_process->save()->dispatch();

        wp_send_json_success(array(
            'import_id' => $import_id,
            'message' => 'Import started successfully'
        ));
    } catch (Exception $e) {
        wp_send_json_error(array(
            'message' => 'Failed to start import: ' . $e->getMessage()
        ));
    }
}

function cemetery_records_v148_check_progress() {
    check_ajax_referer('cemetery_records_import', 'nonce');

    try {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }

        $import_id = $_POST['import_id'];
        $upload_dir = wp_upload_dir();
        $progress_file = $upload_dir['basedir'] . '/cemetery-records-logs/import-progress-' . $import_id . '.json';

        if (!file_exists($progress_file)) {
            wp_send_json_error(array('message' => 'Import not found'));
            return;
        }

        $progress = json_decode(file_get_contents($progress_file), true);
        wp_send_json_success($progress);
    } catch (Exception $e) {
        wp_send_json_error(array(
            'message' => 'Failed to check progress: ' . $e->getMessage()
        ));
    }
} 