<?php

class Cemetery_Records_Import_Export {
    private $supported_image_types = array(
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'tif' => 'image/tiff',
        'tiff' => 'image/tiff',
        'png' => 'image/png'
    );
    // Add a property to track if we are in an import action context
    private $is_processing_import_action = false;
    private $current_import_timestamp = null; // To store the timestamp for the current import

    private $default_fields = array(
        'extracted_image',
        'image_caption',
        'page_additional_info',
        'page_footer',
        'page_header',
        'page_location',
        'source_page',
        'record_uuid'
    );

    private $log_file;
    private $import_start_time;
    private $source_page_cache = array();
    private $source_page_reference_count = array();
    private $last_progress_time;
    private $total_records;
    private $processed_records;
    private $progress_file;
    private $last_successful_record;

    public function __construct() {
        try {
            // Start session if not already started
            if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
                session_start();
            }
            
            // Initialize state tracking
            $this->init_state();
            
            // Setup directories and logging
            $this->setup_directories();
            
            // Initialize logging system
            $this->init_logging();
            
            // Load previous progress
            $this->init_progress();
            
        } catch (Exception $e) {
            $this->handle_fatal_error($e);
            throw $e;
        }
    }

    private function init_state() {
        $this->total_records = 0;
        $this->processed_records = 0;
        $this->last_progress_time = time();
        $this->import_start_time = current_time('Y-m-d H:i:s');
        $this->source_page_cache = array();
        $this->source_page_reference_count = array();
        $this->last_successful_record = 0;
    }

    private function setup_directories() {
        // Get WordPress upload directory with validation
        $upload_dir = wp_upload_dir();
        if (is_wp_error($upload_dir) || empty($upload_dir['basedir'])) {
            throw new Exception('Invalid upload directory configuration: ' . 
                (is_wp_error($upload_dir) ? $upload_dir->get_error_message() : 'Empty basedir'));
        }

        // Ensure base paths exist and are writable
        $required_dirs = array(
            'logs' => $upload_dir['basedir'] . '/cemetery-records-logs',
            'temp' => $upload_dir['basedir'] . '/cemetery-records-temp',
            'cache' => $upload_dir['basedir'] . '/cemetery-records-cache'
        );

        foreach ($required_dirs as $name => $dir) {
            if (!file_exists($dir)) {
                if (!wp_mkdir_p($dir)) {
                    throw new Exception("Failed to create {$name} directory: {$dir}");
                }
            }
            if (!is_writable($dir)) {
                throw new Exception("{$name} directory is not writable: {$dir}");
            }
        }

        // Set file paths with unique timestamp
        $timestamp = date('Y-m-d-His');
        $this->current_import_timestamp = $timestamp; // Store timestamp for this import
        $this->log_file = $required_dirs['logs'] . '/import-' . $timestamp . '.log';
        $this->progress_file = $required_dirs['logs'] . '/import-progress-' . $timestamp . '.json';
    }

    private function init_logging() {
        // Verify log file is writable
        $test_write = @file_put_contents($this->log_file, '');
        if ($test_write === false) {
            throw new Exception('Cannot write to log file: ' . $this->log_file);
        }

        // Initialize log with system information
        $this->log_system_info();
    }

    private function log_system_info() {
        $system_info = array(
            'Import Session Started',
            'PHP Version: ' . PHP_VERSION,
            'WordPress Version: ' . get_bloginfo('version'),
            'Memory Limit: ' . ini_get('memory_limit'),
            'Max Execution Time: ' . ini_get('max_execution_time'),
            'Upload Max Filesize: ' . ini_get('upload_max_filesize'),
            'Post Max Size: ' . ini_get('post_max_size'),
            'Server OS: ' . PHP_OS,
            'Server Software: ' . $_SERVER['SERVER_SOFTWARE'],
            'Database Version: ' . $GLOBALS['wpdb']->db_version(),
            'PHP Extensions: ' . implode(', ', get_loaded_extensions())
        );

        foreach ($system_info as $info) {
            $this->log_message($info, 'system');
        }
    }

    private function init_progress() {
        $this->last_successful_record = $this->load_progress();
        if ($this->last_successful_record > 0) {
            $this->log_message("Resuming import from record: " . $this->last_successful_record);
        }
    }

    private function handle_fatal_error($exception) {
        // Log to WordPress error log as fallback
        error_log('Cemetery Records Fatal Error: ' . $exception->getMessage());
        error_log('Stack trace: ' . $exception->getTraceAsString());
        
        // Attempt to log to our file if available
        if (!empty($this->log_file)) {
            $error_data = array(
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
                'memory_usage' => size_format(memory_get_usage(true)),
                'peak_memory' => size_format(memory_get_peak_usage(true))
            );
            
            @file_put_contents(
                $this->log_file,
                '[' . date('Y-m-d H:i:s') . '] [fatal] ' . json_encode($error_data) . "\n",
                FILE_APPEND
            );
        }
    }

    private function load_progress() {
        if (file_exists($this->progress_file)) {
            $progress_data = json_decode(file_get_contents($this->progress_file), true);
            if (isset($progress_data['last_record'])) {
                return intval($progress_data['last_record']);
            }
        }
        return 0;
    }

    private function save_progress($record_index) {
        $progress_data = array(
            'last_record' => $record_index,
            'timestamp' => current_time('Y-m-d H:i:s'),
            'total_records' => $this->total_records,
            'processed_records' => $this->processed_records
        );
        file_put_contents($this->progress_file, json_encode($progress_data), LOCK_EX);
    }

    private function log_message($message, $type = 'info') {
        try {
            if (empty($this->log_file)) {
                error_log('Cemetery Records: ' . $message);
                return;
            }

            $timestamp = current_time('Y-m-d H:i:s');
            $memory_usage = size_format(memory_get_usage(true));
            $peak_memory = size_format(memory_get_peak_usage(true)); 
            $log_entry = "[$timestamp] [$type] [Memory: $memory_usage, Peak: $peak_memory] $message\n";
            
            $written = @file_put_contents($this->log_file, $log_entry, FILE_APPEND | LOCK_EX);
            
            if ($written === false) {
                // If writing to our log file fails, fall back to WordPress error log
                error_log('Cemetery Records: ' . $message);
                error_log('Cemetery Records Error: Failed to write to log file - ' . $this->log_file);
            }
        } catch (Exception $e) {
            error_log('Cemetery Records Logging Error: ' . $e->getMessage());
            error_log('Original message: ' . $message);
        }
    }

    private function log_progress() {
        $current_time = time();
        if ($current_time - $this->last_progress_time >= 30) { // Log progress every 30 seconds
            $percentage = ($this->processed_records / $this->total_records) * 100;
            $elapsed_time = $current_time - strtotime($this->import_start_time);
            $this->log_message(
                sprintf(
                    "Progress: %d/%d records (%.2f%%) processed. Elapsed time: %d minutes. Memory: %s",
                    $this->processed_records,
                    $this->total_records,
                    $percentage,
                    floor($elapsed_time / 60),
                    size_format(memory_get_usage(true))
                ),
                'progress'
            );
            $this->last_progress_time = $current_time;
        }
    }

    public function init() {
        // Check user capabilities before adding admin actions
        if (current_user_can('manage_options')) {
            add_action('admin_post_export_cemetery_records', array($this, 'export_records'));
            add_action('admin_post_import_cemetery_records', array($this, 'import_records'));
            add_action('admin_post_delete_all_records', array($this, 'delete_all_records'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
            add_filter('cemetery_records_export_fields', array($this, 'filter_export_fields'));
            
            // Add action to save paths to session
            add_action('admin_post_save_image_paths', array($this, 'save_image_paths'));
        }
    }

    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'cemetery-records-import-export') !== false) {
            wp_enqueue_script(
                'cemetery-records-admin-import-export',
                CEMETERY_RECORDS_PLUGIN_URL . 'assets/js/import-export.js',
                array('jquery'),
                CEMETERY_RECORDS_VERSION,
                true
            );

            // Localize the script with some data
            wp_localize_script(
                'cemetery-records-admin-import-export',
                'cemeteryRecordsImport',
                array(
                    'ajaxurl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('cemetery_records_import')
                )
            );
        }
    }

    public function render_page() {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'cemetery-records'));
        }

        // Check if the Import/Export class is properly initialized
        if (!isset($this->log_file) || !isset($this->progress_file)) {
            wp_die(__('Import/Export functionality is not properly initialized. Please check the plugin settings.', 'cemetery-records'));
        }

        // Display import results if they exist
        if (isset($_GET['imported']) || isset($_GET['failed'])) {
            $imported = isset($_GET['imported']) ? intval($_GET['imported']) : 0;
            $failed = isset($_GET['failed']) ? intval($_GET['failed']) : 0;
            ?>
            <div class="notice notice-success">
                <p>
                    <?php 
                    printf(
                        __('Import completed. Successfully imported %d records. Failed to import %d records.', 'cemetery-records'),
                        $imported,
                        $failed
                    ); 
                    ?>
                </p>
            </div>
            <?php
        }

        // Display delete results if they exist
        if (isset($_GET['deleted'])) {
            $deleted = intval($_GET['deleted']);
            ?>
            <div class="notice notice-success">
                <p>
                    <?php 
                    printf(
                        __('Successfully deleted %d records.', 'cemetery-records'),
                        $deleted
                    ); 
                    ?>
                </p>
            </div>
            <?php
        }
        ?>
        <div class="wrap">
            <h1><?php _e('Import/Export Cemetery Records', 'cemetery-records'); ?></h1>

            <!-- Delete All Records Section -->
            <div class="card" style="border-left: 4px solid #dc3232;">
                <h2><?php _e('Delete All Records', 'cemetery-records'); ?></h2>
                <p><?php _e('Warning: This will permanently delete all cemetery records. This action cannot be undone.', 'cemetery-records'); ?></p>
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" id="delete-all-form">
                    <?php wp_nonce_field('delete_all_records', 'cemetery_records_delete_nonce'); ?>
                    <input type="hidden" name="action" value="delete_all_records">
                    <p>
                        <input type="submit" class="button button-delete" value="<?php _e('Delete All Records', 'cemetery-records'); ?>" 
                               style="background: #dc3232; border-color: #dc3232; color: white;" 
                               onclick="return confirm('<?php _e('Are you sure you want to delete all cemetery records? This action cannot be undone.', 'cemetery-records'); ?>');">
                    </p>
                </form>
            </div>

            <!-- Export Section -->
            <div class="card">
                <h2><?php _e('Export Records', 'cemetery-records'); ?></h2>
                <p><?php _e('Download all cemetery records as a JSON file.', 'cemetery-records'); ?></p>
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                    <?php wp_nonce_field('export_cemetery_records', 'cemetery_records_export_nonce'); ?>
                    <input type="hidden" name="action" value="export_cemetery_records">
                    <p><input type="submit" class="button button-primary" value="<?php _e('Export Records', 'cemetery-records'); ?>"></p>
                </form>
            </div>

            <!-- Import Section -->
            <div class="card">
                <h2><?php _e('Import Records', 'cemetery-records'); ?></h2>
                <p><?php _e('Import cemetery records from a JSON file.', 'cemetery-records'); ?></p>
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data" id="cemetery-records-import-form">
                    <?php wp_nonce_field('import_cemetery_records', 'cemetery_records_import_nonce'); ?>
                    <input type="hidden" name="action" value="import_cemetery_records">
                    
                    <p>
                        <label for="import_file"><?php _e('JSON File:', 'cemetery-records'); ?></label><br>
                        <input type="file" name="import_file" id="import_file" accept=".json" required>
                    </p>

                    <div class="image-paths">
                        <p>
                            <label for="extracted_images_path"><?php _e('Extracted Images Directory:', 'cemetery-records'); ?></label><br>
                            <input type="text" name="extracted_images_path" id="extracted_images_path" 
                                   class="regular-text path-input" 
                                   value="<?php echo esc_attr($_SESSION['extracted_images_path'] ?? ''); ?>"
                                   placeholder="/path/to/extracted/images"
                                   autocomplete="off">
                            <span class="description"><?php _e('Full path to the directory containing extracted images', 'cemetery-records'); ?></span>
                        </p>

                        <p>
                            <label for="source_pages_path"><?php _e('Source Pages Directory:', 'cemetery-records'); ?></label><br>
                            <input type="text" name="source_pages_path" id="source_pages_path" 
                                   class="regular-text path-input" 
                                   value="<?php echo esc_attr($_SESSION['source_pages_path'] ?? ''); ?>"
                                   placeholder="/path/to/source/pages"
                                   autocomplete="off">
                            <span class="description"><?php _e('Full path to the directory containing source page images', 'cemetery-records'); ?></span>
                        </p>
                    </div>

                    <p><input type="submit" class="button button-primary" value="<?php _e('Import Records', 'cemetery-records'); ?>"></p>
                </form>
            </div>
        </div>
        <?php
    }

    public function filter_export_fields($fields) {
        return array_merge($this->default_fields, $fields);
    }

    public function export_records() {
        if (!isset($_POST['cemetery_records_export_nonce']) || 
            !wp_verify_nonce($_POST['cemetery_records_export_nonce'], 'export_cemetery_records')) {
            wp_die('Invalid request');
        }

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized access');
        }

        $args = array(
            'post_type' => 'cemetery_record',
            'posts_per_page' => -1,
            'post_status' => 'pending'
        );

        $records = get_posts($args);
        $export_data = array();
        
        // Get all custom fields including any added via filter
        $export_fields = apply_filters('cemetery_records_export_fields', $this->default_fields);

        foreach ($records as $record) {
            $record_data = array();
            
            // Export all registered fields
            foreach ($export_fields as $field) {
                $value = get_post_meta($record->ID, '_' . $field, true);
                
                // Handle special cases for image fields
                if (($field === 'extracted_image' || $field === 'source_page') && !empty($value)) {
                    // Get the original filename if it exists
                    $original_name = get_post_meta($value, "_{$field}_original_name", true);
                    
                    if ($original_name) {
                        // Use the original filename if available
                        $record_data[$field] = $original_name;
                    } else {
                        // Try to reconstruct the original filename
                        $attachment = get_post($value);
                        if ($attachment) {
                            $file_path = get_attached_file($value);
                            if ($file_path) {
                                $filename = basename($file_path);
                                $record_data[$field] = $filename;
                            }
                        }
                    }
                    
                    // Add URL for reference but don't include in export data
                    $url = wp_get_attachment_url($value);
                    if ($url) {
                        $record_data[$field . '_url'] = $url;
                    }
                } else if ($field === 'image_bounding_box' && !empty($value)) {
                    // Ensure bounding box is in array format
                    $record_data[$field] = is_array($value) ? $value : array(0, 0, 0, 0);
                } else if (!empty($value)) {
                    // Handle all other fields normally
                    $record_data[$field] = $value;
                }
            }

            // Add the record content if it exists
            if (!empty($record->post_content)) {
                $record_data['page_additional_info'] = $record->post_content;
            }

            // Ensure required fields exist even if empty
            foreach ($this->default_fields as $field) {
                if (!isset($record_data[$field])) {
                    $record_data[$field] = '';
                }
            }

            // Ensure record has a UUID
            if (empty($record_data['record_uuid'])) {
                $record_data['record_uuid'] = $this->generate_uuid();
                update_post_meta($record->ID, '_record_uuid', $record_data['record_uuid']);
                $this->log_message("Generated and saved new UUID for existing record: " . $record_data['record_uuid']);
            }

            $export_data[] = $record_data;
        }

        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="cemetery-records-export-' . date('Y-m-d') . '.json"');
        header('Pragma: no-cache');

        echo json_encode($export_data, JSON_PRETTY_PRINT);
        exit;
    }

    public function import_records() {
        try {
            // Set a flag to indicate we are processing an import action
            $this->is_processing_import_action = true;
            
            // Check for existing import flag to prevent restart
            $import_flag_key = 'cemetery_records_import_in_progress';
            $import_timestamp = get_transient($import_flag_key);
            
            if ($import_timestamp && $import_timestamp !== $this->current_import_timestamp) {
                $this->log_message("Detected attempt to restart import. Previous import still in progress: " . $import_timestamp);
                $this->log_message("Current timestamp: " . $this->current_import_timestamp);
                wp_redirect(admin_url('edit.php?post_type=cemetery_record&page=cemetery-records-import-export&error=import_in_progress'));
                session_write_close();
                exit;
            }
            
            // Set flag that import is in progress with this timestamp
            set_transient($import_flag_key, $this->current_import_timestamp, 3600); // 1 hour timeout
            
            // Start logging
            $this->log_message("Starting import process");
            $this->log_message("POST data: " . print_r($_POST, true));
            $this->log_message("FILES data: " . print_r($_FILES, true));
            
            if (!isset($_POST['cemetery_records_import_nonce'])) {
                $this->log_message("Missing nonce", "error");
                wp_die('Invalid request - missing nonce');
            }
            
            if (!wp_verify_nonce($_POST['cemetery_records_import_nonce'], 'import_cemetery_records')) {
                $this->log_message("Invalid nonce value", "error");
                wp_die('Invalid request - invalid nonce');
            }

            if (!current_user_can('manage_options')) {
                $this->log_message("Unauthorized access attempt", "error");
                wp_die('Unauthorized access');
            }

            // Validate file upload
            if (!isset($_FILES['import_file'])) {
                $this->log_message("No file uploaded", "error");
                wp_die('No file was uploaded');
            }

            if ($_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
                $error_message = $this->get_upload_error_message($_FILES['import_file']['error']);
                $this->log_message("File upload error: " . $error_message, "error");
                wp_die($error_message);
            }

            $file_name = $_FILES['import_file']['name'];
            $file_extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            
            $this->log_message("Uploaded file: {$file_name} (type: {$file_extension})");
            
            if ($file_extension !== 'json') {
                $this->log_message("Invalid file type: " . $file_extension, "error");
                wp_die('Please upload a JSON file. The file must have a .json extension.');
            }

            // Process paths with detailed logging
            $this->log_message("Processing image paths...");
            
            $extracted_images_path = isset($_POST['extracted_images_path']) ? $_POST['extracted_images_path'] : '';
            $source_pages_path = isset($_POST['source_pages_path']) ? $_POST['source_pages_path'] : '';
            
            $this->log_message("Original paths:");
            $this->log_message("- Extracted images: " . $extracted_images_path);
            $this->log_message("- Source pages: " . $source_pages_path);

            $extracted_images_path = $this->validate_and_normalize_path($extracted_images_path);
            $source_pages_path = $this->validate_and_normalize_path($source_pages_path);

            $this->log_message("Normalized paths:");
            $this->log_message("- Extracted images: " . $extracted_images_path);
            $this->log_message("- Source pages: " . $source_pages_path);

            // Read and validate JSON with detailed logging
            $this->log_message("Reading JSON file...");
            
            $tmp_name = $_FILES['import_file']['tmp_name'];
            $this->log_message("Temporary file location: " . $tmp_name);
            
            if (!file_exists($tmp_name)) {
                $this->log_message("Temporary file does not exist: " . $tmp_name, "error");
                wp_die('Uploaded file not found');
            }

            $json_content = file_get_contents($tmp_name);
            if ($json_content === false) {
                $this->log_message("Failed to read uploaded file: " . $tmp_name, "error");
                wp_die('Failed to read the uploaded file');
            }

            $this->log_message("File size: " . strlen($json_content) . " bytes");

            $import_data = json_decode($json_content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->log_message("JSON decode error: " . json_last_error_msg(), "error");
                $this->log_message("First 1000 characters of content: " . substr($json_content, 0, 1000));
                wp_die('Invalid JSON format: ' . json_last_error_msg());
            }

            if (!is_array($import_data)) {
                $this->log_message("Invalid data structure - not an array. Type: " . gettype($import_data), "error");
                wp_die('Invalid data structure in JSON file. Expected an array of records.');
            }

            $record_count = count($import_data);
            $this->log_message("Found {$record_count} records to import");
            
            if ($record_count === 0) {
                $this->log_message("No records found in import file", "error");
                wp_die('No records found in the import file');
            }

            // Log sample of first record (with sensitive data redacted)
            $sample_record = $import_data[0];
            $this->log_message("Sample record structure: " . print_r($this->redact_sensitive_data($sample_record), true));
            
            // Process the import
            $this->log_message("Starting import processing...");
            $results = $this->process_import_data($import_data, $extracted_images_path, $source_pages_path);
            
            $this->log_message("Import completed. Success: {$results['imported']}, Failed: {$results['failed']}, Images Success: {$results['images_success']}, Images Failed: {$results['images_failed']}");

            // Final cleanup
            $this->cleanup_final_import($results);

            // Mark import as complete before redirect
            delete_transient($import_flag_key);
            set_transient('cemetery_records_import_completed', $this->current_import_timestamp, 86400); // 24 hour record
            
            // Write session and close it
            session_write_close();

            // Add log file to redirect URL
            wp_redirect(add_query_arg(
                array(
                    'page' => 'cemetery-records-import-export',
                    'imported' => $results['imported'],
                    'failed' => $results['failed'],
                    'images_success' => $results['images_success'],
                    'images_failed' => $results['images_failed'],
                    'log' => basename($this->log_file)
                ),
                admin_url('edit.php?post_type=cemetery_record')
            ));
            exit;

        } catch (Exception $e) {
            $this->log_message("Fatal error during import: " . $e->getMessage(), "error");
            $this->log_message("Stack trace: " . $e->getTraceAsString(), "error");
            
            // Clear import flag on error
            delete_transient('cemetery_records_import_in_progress');
            
            // Clean up session
            session_write_close();
            
            wp_die('Import failed: ' . $e->getMessage());
            exit;
        }
    }

    /**
     * Clean up resources after import completes
     */
    private function cleanup_final_import($results) {
        $this->log_message("Performing final cleanup...");
        
        // Ensure all database transactions are closed
        global $wpdb;
        $wpdb->query('COMMIT');
        
        // Force garbage collection
        if (function_exists('gc_collect_cycles')) {
            $cycles = gc_collect_cycles();
            $this->log_message("Garbage collection completed: {$cycles} cycles collected");
        }
        
        // Log memory state after import
        $this->log_detailed_memory_state("Final Import State");
        
        // Clear caches
        $this->source_page_cache = array();
        $this->source_page_reference_count = array();
        
        $this->log_message("Final cleanup completed");
    }

    private function validate_and_normalize_path($path) {
        $path = trim($path);
        if (empty($path)) {
            $this->log_message("Empty path provided", "error");
            return '';
        }

        // Log the original path
        $this->log_message("Validating path: " . $path);

        // Get WordPress upload directory
        $upload_dir = wp_upload_dir();
        if (is_wp_error($upload_dir)) {
            $this->log_message("Failed to get upload directory: " . $upload_dir->get_error_message(), "error");
            return '';
        }

        // Try multiple path variations
        $possible_paths = array(
            $path,  // Original path
            ABSPATH . ltrim($path, '/'),  // Relative to WordPress root
            $upload_dir['basedir'] . '/' . ltrim($path, '/'),  // Relative to uploads directory
            dirname(plugin_dir_path(__FILE__)) . '/' . ltrim($path, '/'),  // Relative to plugin directory
            realpath($path),  // Try to resolve real path
            // Add more specific paths for your environment
            '/Users/kh/Library/CloudStorage/Dropbox/Cemetery/' . ltrim($path, '/'),  // Local development path
        );

        // Log all paths we're going to try
        $this->log_message("Trying paths:\n" . implode("\n", array_filter($possible_paths)));

        foreach ($possible_paths as $try_path) {
            if (empty($try_path)) continue;
            
            // Clean up the path
            $try_path = wp_normalize_path($try_path);
            
            // Log each attempt
            $this->log_message("Checking path: " . $try_path);
            
            // Check if path exists and is accessible
            if (file_exists($try_path)) {
                $this->log_message("Path exists: " . $try_path);
                if (is_readable($try_path)) {
                    $this->log_message("Path is readable: " . $try_path);
                    if (is_dir($try_path)) {
                        $this->log_message("Found valid directory: " . $try_path);
                        return rtrim($try_path, '/\\');
                    } else {
                        $this->log_message("Path exists but is not a directory: " . $try_path, "error");
                    }
                } else {
                    $this->log_message("Path exists but is not readable: " . $try_path, "error");
                }
            } else {
                $this->log_message("Path does not exist: " . $try_path, "error");
            }
        }

        // If we get here, none of the paths worked
        $error_message = sprintf(
            'Directory does not exist or is not accessible. Tried:%s',
            "\n- " . implode("\n- ", array_filter($possible_paths))
        );
        $this->log_message($error_message, "error");
        
        // Return the original path if nothing else worked
        // This allows for cases where the directory exists but isn't accessible through the usual methods
        $this->log_message("Returning original path as fallback: " . $path);
        return $path;
    }

    private function get_upload_error_message($error_code) {
        $upload_errors = array(
            UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
            UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form',
            UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload'
        );

        return isset($upload_errors[$error_code]) 
            ? $upload_errors[$error_code] 
            : 'Unknown upload error';
    }

    public function process_import_data($import_data, $extracted_images_path, $source_pages_path) {
        try {
            $results = array(
                'imported' => 0,
                'failed' => 0,
                'images_success' => 0,
                'images_failed' => 0,
                'errors' => array()
            );

            $this->log_message("Starting process_import_data");
            $this->log_message("Memory usage at start: " . memory_get_usage(true) . " bytes");
            $this->log_message("Memory peak: " . memory_get_peak_usage(true) . " bytes");

            if (!is_array($import_data)) {
                throw new Exception('Invalid import data format');
            }

            $this->total_records = count($import_data);
            $this->log_message("Processing {$this->total_records} records");

            foreach ($import_data as $index => $record) {
                $record_number = $index + 1;
                $this->log_message("Processing record {$record_number} of {$this->total_records}");
                
                try {
                    // Check if we're reaching PHP execution time limit
                    if (function_exists('set_time_limit')) {
                        @set_time_limit(300); // Reset time limit for each record
                    }
                    
                    // Save progress before processing each record
                    $this->save_progress($index);
                    
                    // Process the record with timeout monitoring
                    $this->process_single_record_with_timeout(
                        $record,
                        $record_number,
                        $extracted_images_path,
                        $source_pages_path,
                        $results
                    );

                    // Log progress after each successful record
                    $this->log_message("Successfully processed record {$record_number}");
                    $this->log_progress();

                    // Cleanup memory if needed
                    if ($this->should_cleanup_memory()) {
                        $this->log_message("Performing memory cleanup after record {$record_number}");
                        $this->cleanup_memory("after_record_{$record_number}");
                    }

                } catch (Exception $e) {
                    $this->handle_record_error($e, $record_number, $results);
                    
                    // Check if we should continue processing
                    if ($results['failed'] > 10 && $results['imported'] == 0) {
                        // If we've had 10 failures and no successful imports, abort
                        throw new Exception("Aborting import after multiple consecutive failures");
                    }
                    
                    continue;
                }
                
                // Prevent memory issues by occasionally forcing garbage collection
                if ($record_number % 50 == 0) {
                    $this->log_message("Periodic memory cleanup at record {$record_number}");
                    $this->cleanup_memory("periodic_record_{$record_number}");
                }
            }

            $this->log_message("Import processing completed");
            $this->log_message("Final results:");
            $this->log_message("- Imported: {$results['imported']}");
            $this->log_message("- Failed: {$results['failed']}");
            $this->log_message("- Images Success: {$results['images_success']}");
            $this->log_message("- Images Failed: {$results['images_failed']}");
            
            if (!empty($results['errors'])) {
                $this->log_message("Errors encountered:");
                foreach ($results['errors'] as $error) {
                    $this->log_message("- " . $error);
                }
            }

            return $results;

        } catch (Exception $e) {
            $this->log_message("Fatal error in process_import_data: " . $e->getMessage(), "error");
            $this->log_message("Stack trace: " . $e->getTraceAsString(), "error");
            throw $e;
        }
    }

    /**
     * Clean up memory resources
     */
    private function cleanup_memory($context = "general") {
        $this->log_message("Memory state before cleanup ({$context}): " . size_format(memory_get_usage(true)));
        
        // Clear internal caches that are safe to reset
        $this->source_page_cache = array();
        
        // Force garbage collection if available
        if (function_exists('gc_collect_cycles')) {
            $cycles = gc_collect_cycles();
            $this->log_message("Garbage collection ({$context}): {$cycles} cycles collected");
        }
        
        $this->log_message("Memory state after cleanup ({$context}): " . size_format(memory_get_usage(true)));
    }

    private function process_single_record_with_timeout($record, $record_number, $extracted_images_path, $source_pages_path, &$results) {
        $timeout = 300; // 5 minutes timeout
        $start_time = time();

        try {
            $this->log_message("Starting process_single_record_with_timeout for record {$record_number}");
            $this->log_message("Memory usage before processing: " . size_format(memory_get_usage(true)));

            // Start transaction
            global $wpdb;
            $wpdb->query('START TRANSACTION');
            $this->log_message("Transaction started for record {$record_number}");

            // Validate record data
            $this->log_message("Validating record data");
            if (!$this->validate_record($record)) {
                throw new Exception("Invalid record data for record {$record_number}: " . print_r($this->redact_sensitive_data($record), true));
            }

            // Try to find existing record based on content
            $existing_post_id = $this->find_existing_record($record);
            
            // Create or update the post with timeout check
            $this->log_message(($existing_post_id ? "Updating" : "Creating") . " post for record {$record_number}");
            $post_id = $this->create_or_update_record_post($record, $existing_post_id);
            
            if (!$post_id) {
                throw new Exception(($existing_post_id ? "Post update" : "Post creation") . " failed for record {$record_number}");
            }
            
            $elapsed_time = time() - $start_time;
            if ($elapsed_time > $timeout) {
                throw new Exception("Post " . ($existing_post_id ? "update" : "creation") . " timeout for record {$record_number} (elapsed: {$elapsed_time}s)");
            }
            
            $this->log_message("Post " . ($existing_post_id ? "updated" : "created") . " successfully with ID: {$post_id}");

            // Process images with timeout check
            $this->log_message("Processing images for record {$record_number}");
            $this->log_message("- Extracted images path: {$extracted_images_path}");
            $this->log_message("- Source pages path: {$source_pages_path}");
            
            $image_result = $this->process_record_images(
                $post_id,
                $record,
                $extracted_images_path,
                $source_pages_path,
                $results
            );

            $elapsed_time = time() - $start_time;
            if (!$image_result) {
                throw new Exception("Image processing failed for record {$record_number}");
            }
            
            if ($elapsed_time > $timeout) {
                throw new Exception("Image processing timeout for record {$record_number} (elapsed: {$elapsed_time}s)");
            }

            // Add record number to metadata for reference
            update_post_meta($post_id, '_cemetery_import_record_number', $record_number);
            update_post_meta($post_id, '_cemetery_import_timestamp', $this->current_import_timestamp);

            // Commit transaction
            $this->log_message("Committing transaction for record {$record_number}");
            $wpdb->query('COMMIT');
            
            // Increment success counter
            $results['imported']++;
            $this->processed_records++;
            
            $this->log_message("Successfully " . ($existing_post_id ? "updated" : "completed") . " record {$record_number}");
            $this->log_message("Memory usage after processing: " . size_format(memory_get_usage(true)));
            $this->log_message("Total processing time: " . (time() - $start_time) . " seconds");
            
            $this->log_message("Record {$record_number} processed in " . (time() - $start_time) . " seconds. Memory: " . 
                size_format(memory_get_usage(true)) . ", Peak: " . size_format(memory_get_peak_usage(true)));
            
            return true;

        } catch (Exception $e) {
            $this->log_message("Error in process_single_record_with_timeout for record {$record_number}: " . $e->getMessage(), "error");
            $this->log_message("Stack trace: " . $e->getTraceAsString(), "error");
            
            try {
                $this->log_message("Rolling back transaction for record {$record_number}");
                $wpdb->query('ROLLBACK');
            } catch (Exception $rollback_e) {
                $this->log_message("Error during rollback: " . $rollback_e->getMessage(), "error");
            }
            
            // Increment failure counter
            $results['failed']++;
            $this->processed_records++;
            
            throw $e;
        }
    }

    /**
     * Validate that a record has the minimum required fields
     */
    private function validate_record($record) {
        if (!is_array($record)) {
            return false;
        }
        
        // Validate required fields
        $required_fields = array('page_header', 'page_location');
        foreach ($required_fields as $field) {
            if (!isset($record[$field]) || empty($record[$field])) {
                $this->log_message("Missing required field: {$field}", "error");
                return false;
            }
        }
        
        return true;
    }

    /**
     * Find existing record based on content matching
     */
    private function find_existing_record($record) {
        $this->log_message("Searching for existing record");
        
        // Check for UUID first
        if (!empty($record['record_uuid'])) {
            $args = array(
                'post_type' => 'cemetery_record',
                'posts_per_page' => 1,
                'meta_query' => array(
                    array(
                        'key' => '_record_uuid',
                        'value' => $record['record_uuid']
                    )
                )
            );
            
            $existing_posts = get_posts($args);
            if (!empty($existing_posts)) {
                $post_id = $existing_posts[0]->ID;
                $this->log_message("Found existing record with UUID match, ID: {$post_id}");
                return $post_id;
            }
        }
        
        $this->log_message("No existing record found");
        return null;
    }

    /**
     * Create new record or update existing one
     */
    private function create_or_update_record_post($record, $existing_post_id = null) {
        try {
            // Generate title from available fields
            $title_parts = array();
            
            if (!empty($record['page_header'])) {
                $title_parts[] = $record['page_header'];
            }
            if (!empty($record['page_location'])) {
                $title_parts[] = $record['page_location'];
            }
            if (!empty($record['image_caption'])) {
                $title_parts[] = $record['image_caption'];
            }

            $title = !empty($title_parts) ? implode(' - ', $title_parts) : 'Cemetery Record';
            
            $post_data = array(
                'post_title' => wp_strip_all_tags($title),
                'post_type' => 'cemetery_record',
                'post_status' => 'publish'
            );

            // If page_additional_info exists and is not empty, use it as post content
            if (!empty($record['page_additional_info'])) {
                $additional_info = $record['page_additional_info'];
                if (is_array($additional_info)) {
                    $additional_info = json_encode($additional_info);
                }
                $post_data['post_content'] = wp_kses_post($additional_info);
            }

            // If we have an existing post ID, update it
            if ($existing_post_id) {
                $post_data['ID'] = $existing_post_id;
                $this->log_message("Updating existing post with data: " . print_r($post_data, true));
                $post_id = wp_update_post($post_data);
            } else {
                $this->log_message("Creating new post with data: " . print_r($post_data, true));
                $post_id = wp_insert_post($post_data);
            }

            if (is_wp_error($post_id)) {
                $this->log_message("Failed to " . ($existing_post_id ? "update" : "create") . " record: " . $post_id->get_error_message(), "error");
                throw new Exception('Failed to ' . ($existing_post_id ? "update" : "create") . ' record: ' . $post_id->get_error_message());
            }

            // Ensure record has a UUID
            if (empty($record['record_uuid'])) {
                $record['record_uuid'] = $this->generate_uuid();
                $this->log_message("Generated new UUID for record: " . $record['record_uuid']);
            }

            // Save all metadata
            foreach ($record as $key => $value) {
                if ($key !== 'ID' && $key !== 'post_type') {
                    update_post_meta($post_id, '_' . $key, $value);
                }
            }

            $this->log_message("Successfully " . ($existing_post_id ? "updated" : "created") . " post with ID: {$post_id}");
            return $post_id;

        } catch (Exception $e) {
            $this->log_message("Error in create_or_update_record_post: " . $e->getMessage(), "error");
            throw $e;
        }
    }

    private function generate_uuid() {
        // Generate a version 4 UUID
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);    // Set version to 0100
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);    // Set bits 6-7 to 10

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function should_cleanup_memory() {
        $memory_limit = $this->get_memory_limit_bytes();
        $current_usage = memory_get_usage(true);
        return ($current_usage > ($memory_limit * 0.8)); // Cleanup at 80% usage
    }

    private function get_memory_limit_bytes() {
        $memory_limit = ini_get('memory_limit');
        $unit = strtolower(substr($memory_limit, -1));
        $value = intval($memory_limit);
        
        switch ($unit) {
            case 'g': $value *= 1024;
            case 'm': $value *= 1024;
            case 'k': $value *= 1024;
        }
        
        return $value;
    }

    private function handle_record_error($exception, $record_number, &$results) {
        $error_info = array(
            'record' => $record_number,
            'message' => $exception->getMessage(),
            'memory_usage' => size_format(memory_get_usage(true))
        );
        
        $results['errors'][] = $error_info;
        $results['failed']++;
        
        $this->log_message(
            "Error processing record {$record_number}: " . $exception->getMessage(),
            'error'
        );
    }

    private function process_record_images($post_id, $record, $extracted_images_path, $source_pages_path, &$results) {
        $start_time = microtime(true);
        $timeout = 300; // 5 minutes timeout

        try {
            // Set up error handling
            $old_error_handler = set_error_handler(array($this, 'handle_image_error'));
            
            // Process extracted image
            if (!empty($record['extracted_image'])) {
                $this->log_message("Starting extracted image import for record $post_id");
                
                if ((microtime(true) - $start_time) > $timeout) {
                    throw new Exception("Timeout while processing extracted image");
                }

                // Check if we have a URL from export
                if (!empty($record['extracted_image_url'])) {
                    $this->log_message("Found extracted image URL: {$record['extracted_image_url']}");
                    
                    // Try to get attachment ID from URL
                    $extracted_image_id = $this->get_attachment_id_from_url($record['extracted_image_url']);
                    
                    if ($extracted_image_id) {
                        $this->log_message("Found existing attachment for extracted image: {$extracted_image_id}");
                        update_post_meta($post_id, '_extracted_image', $extracted_image_id);
                        set_post_thumbnail($post_id, $extracted_image_id);
                        $results['images_success']++;
                        return true;
                    }
                }

                // If no URL or couldn't find attachment, try file path
                if (!empty($extracted_images_path)) {
                    // Get original filename, handling both ID and filename formats
                    $original_filename = $this->get_image_filename($record['extracted_image'], 'extracted');
                    if (!$original_filename) {
                        $results['images_failed']++;
                        $this->log_message("Could not determine extracted image filename from: {$record['extracted_image']}", "error");
                        return false;
                    }
                    
                    $this->log_message("Original extracted image path: {$original_filename}");
                    
                    $image_path = $this->find_image_file($extracted_images_path, $original_filename);
                    if ($image_path) {
                        // Check file size before processing
                        $file_size = filesize($image_path);
                        $this->log_message("Extracted image size: " . size_format($file_size));

                        $extracted_image_id = $this->import_local_image_with_timeout(
                            $image_path, 
                            $post_id, 
                            'extracted',
                            $timeout - (microtime(true) - $start_time)
                        );

                        if ($extracted_image_id) {
                            update_post_meta($post_id, '_extracted_image', $extracted_image_id);
                            set_post_thumbnail($post_id, $extracted_image_id);
                            $results['images_success']++;
                            $this->log_message("Added extracted image {$image_path} to record {$post_id}");
                        } else {
                            $results['images_failed']++;
                            $this->log_message("Failed to add extracted image {$image_path} to record {$post_id}", "error");
                        }
                    } else {
                        $results['images_failed']++;
                        $this->log_message("Could not find extracted image file: {$original_filename}", "error");
                    }
                } else {
                    $results['images_failed']++;
                    $this->log_message("No extracted images path provided", "error");
                }
            }

            // Process source page image with separate try-catch
            try {
                if (!empty($record['source_page'])) {
                    if ((microtime(true) - $start_time) > $timeout) {
                        throw new Exception("Timeout before processing source page");
                    }

                    // Check if we have a URL from export
                    if (!empty($record['source_page_url'])) {
                        $this->log_message("Found source page URL: {$record['source_page_url']}");
                        
                        // Try to get attachment ID from URL
                        $source_page_id = $this->get_attachment_id_from_url($record['source_page_url']);
                        
                        if ($source_page_id) {
                            $this->log_message("Found existing attachment for source page: {$source_page_id}");
                            update_post_meta($post_id, '_source_page', $source_page_id);
                            $results['images_success']++;
                            return true;
                        }
                    }

                    // If no URL or couldn't find attachment, try file path
                    if (!empty($source_pages_path)) {
                        $this->log_message("Starting source page import for record $post_id");
                        
                        // Get original filename, handling both ID and filename formats
                        $original_filename = $this->get_image_filename($record['source_page'], 'source');
                        if (!$original_filename) {
                            $results['images_failed']++;
                            $this->log_message("Could not determine source page filename from: {$record['source_page']}", "error");
                            return false;
                        }
                        
                        $this->log_message("Original source page path: {$original_filename}");
                        
                        $image_path = $this->find_image_file($source_pages_path, $original_filename);
                        if (!$image_path) {
                            throw new Exception("Could not find source page file: {$original_filename}");
                        }

                        // Check file size before processing
                        $file_size = filesize($image_path);
                        $this->log_message("Source page size: " . size_format($file_size));

                        if ($file_size > 5 * 1024 * 1024) {
                            $this->log_message("Large source page detected, running garbage collection");
                            if (function_exists('gc_collect_cycles')) {
                                gc_collect_cycles();
                            }
                        }

                        $remaining_time = $timeout - (microtime(true) - $start_time);
                        if ($remaining_time < 60) {
                            throw new Exception("Insufficient time remaining for source page processing");
                        }

                        add_filter('big_image_size_threshold', '__return_false', 100);
                        
                        $source_page_id = $this->get_or_create_source_page_with_timeout(
                            $image_path, 
                            $post_id,
                            min(180, $remaining_time)
                        );

                        remove_filter('big_image_size_threshold', '__return_false', 100);

                        if ($source_page_id) {
                            update_post_meta($post_id, '_source_page', $source_page_id);
                            $results['images_success']++;
                            $this->log_message("Processed source page for record {$post_id}");
                        } else {
                            throw new Exception("Failed to process source page");
                        }
                    } else {
                        $results['images_failed']++;
                        $this->log_message("No source pages path provided", "error");
                    }
                }
            } catch (Exception $e) {
                $results['images_failed']++;
                $this->log_message("Error processing source page: " . $e->getMessage(), "error");
                $this->log_message("Stack trace: " . $e->getTraceAsString(), "error");
                // Continue with the rest of the record processing
                return true;
            }

            if ($old_error_handler) {
                set_error_handler($old_error_handler);
            }

            return true;

        } catch (Exception $e) {
            $this->log_message("Fatal error in image processing: " . $e->getMessage(), "error");
            $this->log_message("Stack trace: " . $e->getTraceAsString(), "error");
            
            if (isset($old_error_handler)) {
                set_error_handler($old_error_handler);
            }
            
            return false;
        }
    }

    /**
     * Get attachment ID from URL
     */
    private function get_attachment_id_from_url($url) {
        global $wpdb;
        
        // Try to get the attachment ID from the database
        $attachment = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM $wpdb->posts WHERE guid = %s OR guid = %s;", 
            $url,
            str_replace('http:', 'https:', $url)
        ));
        
        if (!empty($attachment[0])) {
            return $attachment[0];
        }
        
        // If not found, try to parse the URL and get the image name
        $parsed_url = parse_url($url);
        if (isset($parsed_url['path'])) {
            $filename = basename($parsed_url['path']);
            
            // Try to find attachment by filename
            $attachment_id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s",
                '%' . $wpdb->esc_like($filename)
            ));
            
            if ($attachment_id) {
                return $attachment_id;
            }
        }
        
        return false;
    }

    private function get_image_filename($value, $type = 'extracted') {
        // If it's already a filename with an extension, return it
        if (preg_match('/\.(jpg|jpeg|png|gif|tif|tiff)$/i', $value)) {
            return $value;
        }

        // If it's a numeric ID, try to get the original filename from the metadata
        if (is_numeric($value)) {
            $this->log_message("Value appears to be an attachment ID: {$value}");
            
            // Try to get the original filename from post meta
            $meta_key = $type === 'extracted' ? '_extracted_image_original_name' : '_source_page_original_name';
            $original_name = get_post_meta($value, $meta_key, true);
            
            if ($original_name) {
                $this->log_message("Found original filename in meta: {$original_name}");
                return $original_name;
            }
            
            // If no original name in meta, try to construct it from the record title
            global $wpdb;
            $post_title = $wpdb->get_var($wpdb->prepare(
                "SELECT post_title FROM $wpdb->posts WHERE ID = %d",
                $value
            ));
            
            if ($post_title) {
                // Construct filename based on type
                $filename = $type === 'extracted' 
                    ? "LCR - {$post_title}_img_01.jpg"
                    : "LCR - {$post_title}.jpg";
                
                $this->log_message("Constructed filename from title: {$filename}");
                return $filename;
            }
        }
        
        // If all else fails, log the issue and return false
        $this->log_message("Could not determine filename from value: {$value}", "error");
        return false;
    }

    public function handle_image_error($errno, $errstr, $errfile, $errline) {
        // Log the error
        $error_type = $this->get_error_type($errno);
        $this->log_message("PHP {$error_type}: {$errstr} in {$errfile} on line {$errline}", "error");
        
        // Don't halt execution for deprecated warnings
        if ($errno === E_DEPRECATED || $errno === E_USER_DEPRECATED) {
            return true;
        }
        
        // Let PHP handle other errors
        return false;
    }

    private function get_error_type($errno) {
        switch ($errno) {
            case E_ERROR: return 'ERROR';
            case E_WARNING: return 'WARNING';
            case E_PARSE: return 'PARSE';
            case E_NOTICE: return 'NOTICE';
            case E_CORE_ERROR: return 'CORE_ERROR';
            case E_CORE_WARNING: return 'CORE_WARNING';
            case E_COMPILE_ERROR: return 'COMPILE_ERROR';
            case E_COMPILE_WARNING: return 'COMPILE_WARNING';
            case E_USER_ERROR: return 'USER_ERROR';
            case E_USER_WARNING: return 'USER_WARNING';
            case E_USER_NOTICE: return 'USER_NOTICE';
            case E_STRICT: return 'STRICT';
            case E_RECOVERABLE_ERROR: return 'RECOVERABLE_ERROR';
            case E_DEPRECATED: return 'DEPRECATED';
            case E_USER_DEPRECATED: return 'USER_DEPRECATED';
            default: return 'UNKNOWN';
        }
    }

    private function import_local_image_with_timeout($file_path, $post_id, $type = 'generic', $timeout = 300) {
        $start_time = microtime(true);
        $this->log_message("Starting image import with {$timeout}s timeout: $file_path");

        try {
            // Raise memory limit
            if (function_exists('wp_raise_memory_limit')) {
                wp_raise_memory_limit('image');
            }

            // Basic validation
            if (!file_exists($file_path)) {
                throw new Exception("Source file does not exist: {$file_path}");
            }

            $file_size = filesize($file_path);
            if ($file_size === 0) {
                throw new Exception("Source file is empty: {$file_path}");
            }

            // Process in chunks for large files
            if ($file_size > 5 * 1024 * 1024) { // 5MB
                return $this->import_large_image_chunked($file_path, $post_id, $type, $timeout);
            }

            // Regular import for smaller files
            return $this->import_local_image($file_path, $post_id, $type);

        } catch (Exception $e) {
            $this->log_message("Image import failed: " . $e->getMessage(), "error");
            return false;
        }
    }

    private function import_large_image_chunked($file_path, $post_id, $type, $timeout) {
        $start_time = microtime(true);
        $chunk_size = 1024 * 1024; // 1MB chunks
        
        try {
            // Create temporary file
            $temp_file = wp_tempnam('cemetery_import_');
            if (!$temp_file) {
                throw new Exception("Failed to create temporary file");
            }

            // Copy file in chunks
            $this->copy_file_with_chunks($file_path, $temp_file, filesize($file_path), $chunk_size);

            // Check remaining time
            $remaining_time = $timeout - (microtime(true) - $start_time);
            if ($remaining_time < 60) {
                throw new Exception("Insufficient time remaining after file copy");
            }

            // Import the temporary file
            $result = $this->import_local_image($temp_file, $post_id, $type);

            // Cleanup
            @unlink($temp_file);

            return $result;

        } catch (Exception $e) {
            if (isset($temp_file) && file_exists($temp_file)) {
                @unlink($temp_file);
            }
            throw $e;
        }
    }

    /**
     * Copy a file in chunks to avoid memory issues with large files
     */
    private function copy_file_with_chunks($source_file, $dest_file, $file_size, $chunk_size) {
        $this->log_message("Copying file in chunks: $source_file to $dest_file (size: " . size_format($file_size) . ")");
        
        $source = fopen($source_file, 'rb');
        $dest = fopen($dest_file, 'wb');
        
        if (!$source || !$dest) {
            throw new Exception("Failed to open source or destination file for chunked copy");
        }
        
        $bytes_copied = 0;
        $chunk_count = 0;
        
        while (!feof($source)) {
            $chunk = fread($source, $chunk_size);
            if ($chunk === false) {
                throw new Exception("Error reading from source file during chunked copy");
            }
            
            $write_result = fwrite($dest, $chunk);
            if ($write_result === false) {
                throw new Exception("Error writing to destination file during chunked copy");
            }
            
            $bytes_copied += strlen($chunk);
            $chunk_count++;
            
            if ($chunk_count % 10 == 0) {
                $progress = round(($bytes_copied / $file_size) * 100, 2);
                $this->log_message("Chunked copy progress: {$progress}% complete ({$bytes_copied} of {$file_size} bytes)");
                
                // Check for memory issues and clean up if needed
                if ($this->should_cleanup_memory()) {
                    $this->cleanup_memory("chunked_copy");
                }
            }
        }
        
        fclose($source);
        fclose($dest);
        
        $this->log_message("Chunked copy completed: $source_file to $dest_file");
    }

    private function get_or_create_source_page_with_timeout($image_path, $post_id, $timeout) {
        $start_time = microtime(true);
        $cache_key = md5($image_path);

        try {
            // Check cache first
            if (isset($this->source_page_cache[$cache_key])) {
                $source_page_id = $this->source_page_cache[$cache_key];
                if (get_post_type($source_page_id) === 'attachment') {
                    $this->log_message("Reusing cached source page (ID: {$source_page_id})");
                    
                    // Track reference count for this source page
                    if (!isset($this->source_page_reference_count[$source_page_id])) {
                        $this->source_page_reference_count[$source_page_id] = 0;
                    }
                    $this->source_page_reference_count[$source_page_id]++;
                    
                    return $source_page_id;
                }
                unset($this->source_page_cache[$cache_key]);
            }

            // Check for timeout
            if ((microtime(true) - $start_time) > $timeout) {
                throw new Exception("Timeout while checking source page cache");
            }

            // Look for existing source page
            $existing_id = $this->find_existing_source_page($image_path);
            if ($existing_id) {
                $this->source_page_cache[$cache_key] = $existing_id;
                
                // Track reference count for this source page
                if (!isset($this->source_page_reference_count[$existing_id])) {
                    $this->source_page_reference_count[$existing_id] = 0;
                }
                $this->source_page_reference_count[$existing_id]++;
                
                return $existing_id;
            }

            // Check for timeout before import
            $remaining_time = $timeout - (microtime(true) - $start_time);
            if ($remaining_time < 60) {
                throw new Exception("Insufficient time remaining for source page import");
            }

            // Import new source page
            $source_page_id = $this->import_local_image_with_timeout(
                $image_path,
                $post_id,
                'source',
                $remaining_time
            );

            if ($source_page_id) {
                $this->source_page_cache[$cache_key] = $source_page_id;
                update_post_meta($source_page_id, '_cemetery_source_page_hash', $cache_key);
                
                // Initialize reference count for new source page
                $this->source_page_reference_count[$source_page_id] = 1;
                
                return $source_page_id;
            }

            throw new Exception("Failed to import source page");

        } catch (Exception $e) {
            $this->log_message("Source page processing failed: " . $e->getMessage(), "error");
            return false;
        }
    }

    private function find_existing_source_page($image_path) {
        $cache_key = md5($image_path);
        
        // Query for attachments with our custom meta key
        $args = array(
            'post_type' => 'attachment',
            'posts_per_page' => 1,
            'meta_query' => array(
                array(
                    'key' => '_cemetery_source_page_hash',
                    'value' => $cache_key,
                    'compare' => '='
                )
            )
        );

        $query = new WP_Query($args);
        
        if ($query->have_posts()) {
            return $query->posts[0]->ID;
        }

        return false;
    }

    public function delete_all_records() {
        // Verify nonce
        if (!isset($_POST['cemetery_records_delete_nonce']) || 
            !wp_verify_nonce($_POST['cemetery_records_delete_nonce'], 'delete_all_records')) {
            wp_die('Invalid request');
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized access');
        }

        // Get all cemetery records
        $args = array(
            'post_type' => 'cemetery_record',
            'posts_per_page' => -1,
            'post_status' => 'any'
        );

        $records = get_posts($args);
        $deleted_count = 0;
        $source_page_refs = array();

        // First pass: count references to source pages
        foreach ($records as $record) {
            $source_page_id = get_post_meta($record->ID, '_source_page', true);
            if ($source_page_id) {
                if (!isset($source_page_refs[$source_page_id])) {
                    $source_page_refs[$source_page_id] = 0;
                }
                $source_page_refs[$source_page_id]++;
            }
        }

        // Second pass: delete records and associated images
        foreach ($records as $record) {
            // Delete extracted image (always safe to delete as it's unique to the record)
            $extracted_image_id = get_post_meta($record->ID, '_extracted_image', true);
            if ($extracted_image_id) {
                wp_delete_attachment($extracted_image_id, true);
            }

            // Handle source page deletion
            $source_page_id = get_post_meta($record->ID, '_source_page', true);
            if ($source_page_id) {
                $source_page_refs[$source_page_id]--;
                // Only delete source page if this was the last reference
                if ($source_page_refs[$source_page_id] <= 0) {
                    wp_delete_attachment($source_page_id, true);
                    $this->log_message("Deleted source page {$source_page_id} - no more references");
                } else {
                    $this->log_message("Kept source page {$source_page_id} - still has {$source_page_refs[$source_page_id]} references");
                }
            }

            // Delete the record
            wp_delete_post($record->ID, true);
            $deleted_count++;
        }

        // Clear transients related to imports
        delete_transient('cemetery_records_import_in_progress');
        delete_transient('cemetery_records_import_completed');

        // Redirect back with results
        wp_redirect(add_query_arg(
            array(
                'page' => 'cemetery-records-import-export',
                'deleted' => $deleted_count
            ),
            admin_url('edit.php?post_type=cemetery_record')
        ));
        exit;
    }

    private function log_detailed_memory_state($stage) {
        $memory_info = array(
            'memory_usage' => size_format(memory_get_usage(true)),
            'peak_memory' => size_format(memory_get_peak_usage(true)),
            'memory_limit' => ini_get('memory_limit'),
            'wp_memory_limit' => WP_MEMORY_LIMIT,
            'max_execution_time' => ini_get('max_execution_time'),
            'mysql_vars' => array()
        );

        // Get MySQL variables if possible
        global $wpdb;
        if ($wpdb) {
            $mysql_vars = $wpdb->get_results("SHOW VARIABLES LIKE '%timeout%'");
            foreach ($mysql_vars as $var) {
                $memory_info['mysql_vars'][$var->Variable_name] = $var->Value;
            }
        }

        $this->log_message(
            sprintf(
                "%s - Detailed Memory State:\n%s",
                $stage,
                print_r($memory_info, true)
            ),
            'debug'
        );
    }

    private function redact_sensitive_data($data) {
        if (!is_array($data)) {
            return $data;
        }
        
        $redacted = array();
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $redacted[$key] = $this->redact_sensitive_data($value);
            } else {
                // Keep the structure but redact potentially sensitive values
                $redacted[$key] = '[REDACTED]';
            }
        }
        return $redacted;
    }

    private function normalize_image_path($filename) {
        // Split the path into directory and filename
        $path_parts = pathinfo($filename);
        $directory = !empty($path_parts['dirname']) && $path_parts['dirname'] !== '.' ? $path_parts['dirname'] : '';
        $basename = $path_parts['basename'];
        
        // Log original components
        $this->log_message("Path components:");
        $this->log_message("- Directory: '$directory'");
        $this->log_message("- Basename: '$basename'");
        
        // Clean up the directory path
        if (!empty($directory)) {
            $directory = rtrim($directory, '/') . '/';
        }
        
        // Keep the original filename intact, just clean up the path
        $normalized_path = $directory . $basename;
        
        $this->log_message("Normalized path: '$normalized_path'");
        return $normalized_path;
    }

    private function find_image_file($directory, $filename) {
        try {
            if (empty($directory) || empty($filename)) {
                throw new Exception("Empty directory or filename provided");
            }

            // Clean up the directory path
            $directory = rtrim($directory, '/');
            
            // Log the original search parameters
            $this->log_message("Searching for file:");
            $this->log_message("- Directory: $directory");
            $this->log_message("- Original filename: $filename");

            // Remove any directory prefix from filename if it matches the directory
            $filename = preg_replace('#^imgsrc/#', '', $filename);
            $filename = preg_replace('#^pagefiles/#', '', $filename);
            
            // Array of possible filename variations
            $filename_variations = array(
                $filename,  // Original filename
                str_replace('-', ' - ', $filename),  // Replace single hyphens with spaced hyphens
                str_replace(' - ', '-', $filename),  // Replace spaced hyphens with single hyphens
                str_replace(' ', '\ ', $filename)    // Escape spaces for shell
            );

            // Try each variation
            foreach ($filename_variations as $variant) {
                $full_path = $directory . '/' . $variant;
                $this->log_message("Trying path: $full_path");
                
                if (file_exists($full_path)) {
                    $this->log_message("Found file with variant: $full_path");
                    return $full_path;
                }
            }

            // If no exact matches, try case-insensitive directory scan
            if ($handle = opendir($directory)) {
                $lower_variations = array_map('strtolower', $filename_variations);
                
                while (false !== ($entry = readdir($handle))) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }
                    
                    // Check each variation against the current entry
                    if (in_array(strtolower($entry), $lower_variations)) {
                        $found_path = $directory . '/' . $entry;
                        $this->log_message("Found file with case-insensitive match: $found_path");
                        closedir($handle);
                        return $found_path;
                    }
                }
                closedir($handle);
            }

            // Log directory contents for debugging
            $this->log_message("File not found. Directory contents of: $directory");
            if ($handle = opendir($directory)) {
                $files = array();
                $count = 0;
                while (false !== ($entry = readdir($handle))) {
                    if ($entry !== '.' && $entry !== '..') {
                        $files[] = $entry;
                        $count++;
                        if ($count >= 50) {
                            $files[] = '... (more files, listing truncated)';
                            break;
                        }
                    }
                }
                closedir($handle);
                
                // Sort files for easier reading
                sort($files);
                $this->log_message("Found " . count($files) . " files in directory");
                foreach ($files as $file) {
                    $this->log_message("- $file");
                }
            }

            $this->log_message("Could not find file: $filename in $directory", "error");
            return false;

        } catch (Exception $e) {
            $this->log_message("Error in find_image_file: " . $e->getMessage(), "error");
            return false;
        }
    }

    private function import_local_image($file_path, $post_id, $type = 'generic') {
        try {
            $start_time = microtime(true);
            $this->log_message("Starting import_local_image for type: {$type}");

            // Basic validation
            if (!file_exists($file_path)) {
                throw new Exception("Source file does not exist: {$file_path}");
            }

            $file_size = filesize($file_path);
            if ($file_size === 0) {
                throw new Exception("Source file is empty: {$file_path}");
            }
            $this->log_message("File size: " . size_format($file_size));

            // Get file info
            $file_type = wp_check_filetype(basename($file_path), null);
            if (!$file_type['type']) {
                throw new Exception("Invalid file type for: {$file_path}");
            }
            $this->log_message("File type: {$file_type['type']}");

            // Get WordPress upload directory
            $upload_dir = wp_upload_dir();
            if (is_wp_error($upload_dir)) {
                throw new Exception("Failed to get WordPress upload directory: " . $upload_dir->get_error_message());
            }
            $this->log_message("Upload directory obtained successfully");

            // Create date-based directories if they don't exist
            $year_month = date('Y/m');
            $target_dir = $upload_dir['basedir'] . '/' . $year_month;
            if (!file_exists($target_dir)) {
                if (!wp_mkdir_p($target_dir)) {
                    throw new Exception("Failed to create target directory: {$target_dir}");
                }
            }
            $this->log_message("Target directory ready: {$target_dir}");

            // Generate unique filename
            $filename = wp_unique_filename($target_dir, basename($file_path));
            $target_file = $target_dir . '/' . $filename;
            $this->log_message("Target file path: {$target_file}");

            // Copy file to uploads directory with timeout check
            $this->log_message("Copying file...");
            if (!@copy($file_path, $target_file)) {
                $error = error_get_last();
                throw new Exception("Failed to copy file. Error: " . ($error ? $error['message'] : 'Unknown error'));
            }
            $this->log_message("File copied successfully");

            // Set correct file permissions
            $stat = stat(dirname($target_file));
            $perms = $stat['mode'] & 0000666;
            chmod($target_file, $perms);
            $this->log_message("File permissions set");

            // Check for timeout before proceeding
            if ((microtime(true) - $start_time) > 290) {
                throw new Exception("Operation timeout during file copy");
            }

            // Prepare attachment data
            $attachment = array(
                'post_mime_type' => $file_type['type'],
                'post_title' => preg_replace('/\.[^.]+$/', '', $filename),
                'post_content' => '',
                'post_status' => 'inherit',
                'guid' => $upload_dir['url'] . '/' . $year_month . '/' . $filename
            );

            // Insert the attachment with timeout check
            $this->log_message("Inserting attachment...");
            $attach_id = wp_insert_attachment($attachment, $target_file, $post_id);
            if (is_wp_error($attach_id)) {
                throw new Exception("Failed to insert attachment: " . $attach_id->get_error_message());
            }
            $this->log_message("Attachment inserted with ID: {$attach_id}");

            // Check for timeout before proceeding
            if ((microtime(true) - $start_time) > 290) {
                throw new Exception("Operation timeout after attachment insertion");
            }

            // Include image handling functions
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            require_once(ABSPATH . 'wp-admin/includes/media.php');

            // Generate metadata with timeout and fallback handling
            $this->log_message("Generating attachment metadata...");
            
            // Set a shorter timeout for metadata generation
            $metadata_timeout = 60; // 60 seconds max for metadata
            $metadata_start = microtime(true);
            
            // Disable image scaling and thumbnails temporarily
            add_filter('big_image_size_threshold', '__return_false');
            add_filter('intermediate_image_sizes', '__return_empty_array');
            
            try {
                // Try to get image dimensions without loading the whole image
                $imagesize = @getimagesize($target_file);
                if ($imagesize) {
                    $this->log_message("Got image dimensions: {$imagesize[0]}x{$imagesize[1]}");
                    
                    // Create basic metadata
                    $attach_data = array(
                        'width' => $imagesize[0],
                        'height' => $imagesize[1],
                        'file' => $year_month . '/' . $filename,
                        'sizes' => array(),
                        'image_meta' => array(
                            'aperture' => 0,
                            'credit' => '',
                            'camera' => '',
                            'caption' => '',
                            'created_timestamp' => 0,
                            'copyright' => '',
                            'focal_length' => 0,
                            'iso' => 0,
                            'shutter_speed' => 0,
                            'title' => '',
                            'orientation' => 0,
                        )
                    );
                    
                    $this->log_message("Created basic metadata");
                } else {
                    // Fallback to WordPress metadata generation with timeout
                    $this->log_message("Falling back to WordPress metadata generation");
                    
                    // Set time limit for just this operation
                    @set_time_limit($metadata_timeout);
                    
                    $attach_data = wp_generate_attachment_metadata($attach_id, $target_file);
                }
                
                if (!$attach_data) {
                    throw new Exception("Failed to generate metadata");
                }
            } catch (Exception $e) {
                // If metadata generation fails, create minimal metadata
                $this->log_message("Metadata generation failed, using minimal metadata: " . $e->getMessage());
                $attach_data = array(
                    'file' => $year_month . '/' . $filename,
                    'sizes' => array()
                );
            } finally {
                // Restore filters
                remove_filter('big_image_size_threshold', '__return_false');
                remove_filter('intermediate_image_sizes', '__return_empty_array');
            }
            
            $this->log_message("Updating attachment metadata");
            wp_update_attachment_metadata($attach_id, $attach_data);
            $this->log_message("Metadata updated successfully");

            // Add custom meta to identify the image type
            update_post_meta($attach_id, '_cemetery_image_type', $type);
            
            // Store the original filename for reference
            update_post_meta($attach_id, "_{$type}_image_original_name", basename($file_path));

            // If this is an extracted image, set it as the featured image
            if ($type === 'extracted') {
                set_post_thumbnail($post_id, $attach_id);
                $this->log_message("Set as featured image");
            }

            // Update attachment parent
            wp_update_post(array(
                'ID' => $attach_id,
                'post_parent' => $post_id
            ));

            $elapsed_time = round(microtime(true) - $start_time, 2);
            $this->log_message("Successfully imported image: {$filename} (ID: {$attach_id}, Type: {$type}, Time: {$elapsed_time}s)");
            return $attach_id;

        } catch (Exception $e) {
            $this->log_message("Error in import_local_image: " . $e->getMessage(), "error");
            $this->log_message("Stack trace: " . $e->getTraceAsString(), "error");
            
            // Cleanup on failure
            if (isset($target_file) && file_exists($target_file)) {
                @unlink($target_file);
                $this->log_message("Cleaned up target file after error");
            }
            
            if (isset($attach_id)) {
                wp_delete_attachment($attach_id, true);
                $this->log_message("Cleaned up attachment after error");
            }
            
            return false;
        }
    }

    public function save_image_paths() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized access');
        }

        if (isset($_POST['extracted_images_path'])) {
            $_SESSION['extracted_images_path'] = sanitize_text_field($_POST['extracted_images_path']);
        }
        
        if (isset($_POST['source_pages_path'])) {
            $_SESSION['source_pages_path'] = sanitize_text_field($_POST['source_pages_path']);
        }

        // Save paths to database as well for persistence
        update_option('cemetery_records_extracted_images_path', $_SESSION['extracted_images_path'] ?? '');
        update_option('cemetery_records_source_pages_path', $_SESSION['source_pages_path'] ?? '');

        // Write session before sending response
        session_write_close();

        // Send JSON response
        wp_send_json_success(array(
            'message' => 'Paths saved successfully',
            'extracted_path' => $_SESSION['extracted_images_path'] ?? '',
            'source_path' => $_SESSION['source_pages_path'] ?? ''
        ));
    }
    
    /**
     * Destructor to ensure proper cleanup
     */
    public function __destruct() {
        try {
            // If we were processing an import, log the end
            if ($this->is_processing_import_action) {
                $this->log_message("Import process ended - destructor called");
                $this->log_detailed_memory_state("Destructor");
                
                // Ensure open database transactions are closed
                global $wpdb;
                $wpdb->query('COMMIT');
            }
            
            // Close session if it's active
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
        } catch (Exception $e) {
            error_log('Cemetery Records Error in destructor: ' . $e->getMessage());
        }
    }
}