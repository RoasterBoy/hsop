<?php
/**
 * Plugin Name: Cemetery Records
 * Plugin URI: https://cemeteries.phillipston.org/
 * Description: A WordPress plugin for managing cemetery records with image support
 * Version: 1.3.13
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
define('CEMETERY_RECORDS_VERSION', '1.3.13');
define('CEMETERY_RECORDS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CEMETERY_RECORDS_PLUGIN_URL', plugin_dir_url(__FILE__));

// Require the core plugin class
require_once CEMETERY_RECORDS_PLUGIN_DIR . 'includes/class-cemetery-records.php';
require_once CEMETERY_RECORDS_PLUGIN_DIR . 'includes/class-cemetery-records-import-export.php';

// Initialize the plugin
function run_cemetery_records() {
    $plugin = new Cemetery_Records();
    $plugin->init();

    $import_export = new Cemetery_Records_Import_Export();
    $import_export->init();
}

// Hook into WordPress
add_action('plugins_loaded', 'run_cemetery_records');

// Register activation hook
register_activation_hook(__FILE__, 'cemetery_records_activate');

function cemetery_records_activate() {
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
register_deactivation_hook(__FILE__, 'cemetery_records_deactivate');

function cemetery_records_deactivate() {
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
function cemetery_records_v135_admin_menu() {
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
function cemetery_records_v135_plugin_action_links($links) {
    $plugin_links = array(
        '<a href="' . admin_url('edit.php?post_type=cemetery_record&page=cemetery-records-import-export') . '">' . 
        __('Import/Export', 'cemetery-records') . '</a>'
    );
    return array_merge($plugin_links, $links);
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'cemetery_records_v135_plugin_action_links');

// Admin styles
function cemetery_records_v135_admin_styles($hook) {
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
add_action('admin_enqueue_scripts', 'cemetery_records_v135_admin_styles');

// Public styles
function cemetery_records_v135_public_styles() {
    if (is_singular('cemetery_record')) {
        wp_enqueue_style(
            'cemetery-records-public',
            CEMETERY_RECORDS_PLUGIN_URL . 'assets/css/public.css',
            array(),
            CEMETERY_RECORDS_VERSION
        );
    }
}
add_action('wp_enqueue_scripts', 'cemetery_records_v135_public_styles');

// Load custom template for single cemetery records
function cemetery_records_v135_template($template) {
    if (is_singular('cemetery_record')) {
        $custom_template = CEMETERY_RECORDS_PLUGIN_DIR . 'templates/single-cemetery_record.php';
        if (file_exists($custom_template)) {
            return $custom_template;
        }
    }
    return $template;
}
add_filter('single_template', 'cemetery_records_v135_template'); 