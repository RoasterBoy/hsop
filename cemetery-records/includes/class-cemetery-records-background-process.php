<?php

require_once(dirname(__FILE__) . '/class-wp-async-request.php');
require_once(dirname(__FILE__) . '/class-wp-background-process.php');

class Cemetery_Records_Import_Background_Process extends Cemetery_Records_Background_Process {
    protected $action = 'cemetery_records_import';
    protected $import_id;
    protected $progress_file;
    protected $log_file;
    protected $max_execution_time;
    protected $start_time;
    protected $memory_limit;
    protected $initial_memory;

    public function __construct() {
        parent::__construct();
        
        // Setup progress tracking
        $upload_dir = wp_upload_dir();
        $this->setup_tracking_files($upload_dir['basedir']);
        
        // Set execution limits
        $this->max_execution_time = ini_get('max_execution_time');
        if (!$this->max_execution_time || $this->max_execution_time < 1) {
            $this->max_execution_time = 30; // Default to 30 seconds
        }
        $this->memory_limit = $this->get_memory_limit() * 0.9; // 90% of max memory
        $this->start_time = time();
        $this->initial_memory = memory_get_usage(true);
    }

    private function setup_tracking_files($base_dir) {
        $logs_dir = $base_dir . '/cemetery-records-logs';
        if (!file_exists($logs_dir)) {
            wp_mkdir_p($logs_dir);
        }

        $this->import_id = uniqid('import_');
        $this->progress_file = $logs_dir . '/import-progress-' . $this->import_id . '.json';
        $this->log_file = $logs_dir . '/import-' . $this->import_id . '.log';
        
        // Initialize progress file
        $this->save_progress(array(
            'status' => 'starting',
            'current_record' => 0,
            'total_records' => 0,
            'percent_complete' => 0,
            'last_error' => null,
            'memory_usage' => size_format(memory_get_usage(true)),
            'peak_memory' => size_format(memory_get_peak_usage(true))
        ));
    }

    protected function task($item) {
        try {
            // Check time and memory limits before processing
            if ($this->should_stop_processing()) {
                $this->log_message('Stopping processing due to resource limits', 'warning');
                return false;
            }

            $this->log_message(sprintf(
                'Starting record %d processing. Memory: %s, Time elapsed: %d seconds, Record data: %s',
                $item['record_number'],
                size_format(memory_get_usage(true)),
                time() - $this->start_time,
                json_encode(array_keys($item['record'])) // Log record structure
            ));

            // Create a new instance for each record to prevent memory leaks
            $import_export = new Cemetery_Records_Import_Export();
            
            // Log image paths before processing
            if (isset($item['extracted_images_path'])) {
                $this->log_message(sprintf(
                    'Checking images path: %s - Exists: %s, Is readable: %s',
                    $item['extracted_images_path'],
                    file_exists($item['extracted_images_path']) ? 'Yes' : 'No',
                    is_readable($item['extracted_images_path']) ? 'Yes' : 'No'
                ));
            }

            // Add timeout monitoring with more granular logging
            $processing_start = microtime(true);
            
            // Set a shorter timeout for the single record processing
            $max_single_record_time = min(30, $this->max_execution_time * 0.5);
            
            // Register shutdown function to catch timeouts
            $shutdown_callback = function() use ($item, $processing_start) {
                $error = error_get_last();
                if ($error !== null && in_array($error['type'], [E_ERROR, E_RECOVERABLE_ERROR])) {
                    $this->log_message(sprintf(
                        'Fatal error processing record %d: %s in %s on line %d',
                        $item['record_number'],
                        $error['message'],
                        $error['file'],
                        $error['line']
                    ), 'error');
                }
            };
            register_shutdown_function($shutdown_callback);

            // Process with timeout monitoring
            $result = $import_export->process_single_record_with_timeout(
                $item['record'],
                $item['record_number'],
                $item['extracted_images_path'],
                $item['source_pages_path'],
                $item['results'],
                $max_single_record_time
            );

            $processing_time = microtime(true) - $processing_start;
            
            // Log detailed results
            $this->log_message(sprintf(
                'Record %d processing details - Time: %.2f sec, Memory delta: %s, Result status: %s',
                $item['record_number'],
                $processing_time,
                size_format(memory_get_usage(true) - $this->initial_memory),
                $result ? 'Success' : 'Failed'
            ));

            // Update progress with detailed stats
            $progress = $this->get_progress();
            $progress['current_record'] = $item['record_number'];
            $progress['percent_complete'] = round(($item['record_number'] / $progress['total_records']) * 100);
            $progress['memory_usage'] = size_format(memory_get_usage(true));
            $progress['peak_memory'] = size_format(memory_get_peak_usage(true));
            $progress['last_processing_time'] = round($processing_time, 2);
            $this->save_progress($progress);

            // Force garbage collection after each record
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }

            $this->log_message(sprintf(
                'Completed record %d. Processing time: %.2f seconds, Memory: %s, Peak: %s',
                $item['record_number'],
                $processing_time,
                size_format(memory_get_usage(true)),
                size_format(memory_get_peak_usage(true))
            ));

            return false; // Remove item from queue
        } catch (Exception $e) {
            $this->log_error($e, $item['record_number']);
            
            // Enhanced error logging
            $this->log_message(sprintf(
                'Exception details for record %d: Type: %s, Message: %s, File: %s, Line: %d, Memory: %s',
                $item['record_number'],
                get_class($e),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                size_format(memory_get_usage(true))
            ), 'error');
            
            // Check if we should continue processing
            if ($this->should_stop_processing()) {
                $this->log_message('Stopping import process due to critical error', 'error');
                wp_schedule_single_event(time() + 300, 'cemetery_records_retry_import', array($this->import_id));
                return false;
            }
            
            return false; // Remove item from queue despite error
        }
    }

    private function should_stop_processing() {
        static $last_check = 0;
        $now = time();
        
        // Only check every 5 seconds to reduce overhead
        if ($now - $last_check < 5) {
            return false;
        }
        $last_check = $now;

        // Check execution time
        $time_elapsed = $now - $this->start_time;
        $time_limit_exceeded = $time_elapsed > ($this->max_execution_time * 0.8);
        
        if ($time_limit_exceeded) {
            $this->log_message(sprintf(
                'Time limit approaching: %d of %d seconds used',
                $time_elapsed,
                $this->max_execution_time
            ), 'warning');
            return true;
        }

        // Check memory usage
        $current_memory = memory_get_usage(true);
        $memory_limit_exceeded = $current_memory > $this->memory_limit;
        
        if ($memory_limit_exceeded) {
            $this->log_message(sprintf(
                'Memory limit approaching: %s of %s used',
                size_format($current_memory),
                size_format($this->memory_limit)
            ), 'warning');
            return true;
        }

        return false;
    }

    protected function complete() {
        parent::complete();
        
        $progress = $this->get_progress();
        $progress['status'] = 'completed';
        $progress['completion_time'] = current_time('Y-m-d H:i:s');
        $progress['total_time'] = time() - $this->start_time;
        $progress['peak_memory'] = size_format(memory_get_peak_usage(true));
        $this->save_progress($progress);

        $this->log_message('Import process completed successfully');
    }

    private function save_progress($data) {
        file_put_contents($this->progress_file, json_encode($data), LOCK_EX);
    }

    private function get_progress() {
        if (file_exists($this->progress_file)) {
            return json_decode(file_get_contents($this->progress_file), true);
        }
        return array();
    }

    private function log_message($message, $type = 'info') {
        $log_entry = sprintf(
            "[%s] [%s] [Memory: %s] %s\n",
            current_time('Y-m-d H:i:s'),
            $type,
            size_format(memory_get_usage(true)),
            $message
        );
        
        file_put_contents($this->log_file, $log_entry, FILE_APPEND | LOCK_EX);
        
        if ($type === 'error' || $type === 'warning') {
            error_log("Cemetery Records Import: {$message}");
        }
    }

    private function log_error($exception, $record_number) {
        $error_data = array(
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'record_number' => $record_number,
            'trace' => $exception->getTraceAsString(),
            'memory_usage' => size_format(memory_get_usage(true)),
            'peak_memory' => size_format(memory_get_peak_usage(true)),
            'time_elapsed' => time() - $this->start_time
        );

        $this->log_message(
            sprintf(
                "Error processing record %d: %s\nTrace: %s",
                $record_number,
                $exception->getMessage(),
                $exception->getTraceAsString()
            ),
            'error'
        );

        $progress = $this->get_progress();
        $progress['last_error'] = $error_data;
        $progress['status'] = 'error';
        $this->save_progress($progress);
    }

    public function get_import_id() {
        return $this->import_id;
    }
} 