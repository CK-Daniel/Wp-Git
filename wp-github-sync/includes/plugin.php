<?php
/**
 * Plugin activation, deactivation, and uninstallation functions.
 *
 * @package WPGitHubSync
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Check if the server meets requirements for the plugin.
 *
 * @return bool|string True if requirements are met, error message otherwise.
 */
function wp_github_sync_check_requirements() {
    // Check PHP version
    if (version_compare(PHP_VERSION, '7.2', '<')) {
        return sprintf(__('WordPress GitHub Sync requires PHP 7.2 or higher. You are running PHP %s.', 'wp-github-sync'), PHP_VERSION);
    }
    
    // Check if WordPress version meets minimum requirement
    global $wp_version;
    if (version_compare($wp_version, '5.2', '<')) {
        return sprintf(__('WordPress GitHub Sync requires WordPress 5.2 or higher. You are running WordPress %s.', 'wp-github-sync'), $wp_version);
    }
    
    // Check if needed extensions are available
    $missing_extensions = array();
    $required_extensions = array(
        'curl' => __('for making API requests to GitHub', 'wp-github-sync'),
        'json' => __('for processing API responses', 'wp-github-sync'),
        'zip' => __('for extracting downloaded repositories', 'wp-github-sync'),
    );
    
    foreach ($required_extensions as $ext => $reason) {
        if (!extension_loaded($ext)) {
            $missing_extensions[] = sprintf(__('%s (%s)', 'wp-github-sync'), $ext, $reason);
        }
    }
    
    // Optional but recommended extensions
    $recommended_extensions = array(
        'openssl' => __('for secure encryption of tokens', 'wp-github-sync'),
        'fileinfo' => __('for better file type detection', 'wp-github-sync'),
        'mbstring' => __('for proper UTF-8 handling', 'wp-github-sync'),
    );
    
    // Check directory permissions
    if (!is_writable(WP_CONTENT_DIR)) {
        return __('WordPress GitHub Sync requires write access to the wp-content directory.', 'wp-github-sync');
    }
    
    if (!empty($missing_extensions)) {
        return sprintf(
            __('WordPress GitHub Sync requires the following PHP extensions: %s', 'wp-github-sync'),
            implode(', ', $missing_extensions)
        );
    }
    
    return true;
}

/**
 * Plugin activation hook.
 */
function wp_github_sync_activate() {
    // Check requirements before activating
    $requirements = wp_github_sync_check_requirements();
    if ($requirements !== true) {
        deactivate_plugins(plugin_basename(WP_GITHUB_SYNC_DIR . 'wp-github-sync.php'));
        wp_die($requirements);
    }
    
    // Create required directories
    $backup_dir = WP_CONTENT_DIR . '/wp-github-sync-backups';
    if (!file_exists($backup_dir)) {
        wp_mkdir_p($backup_dir);
    }
    
    // Check if the backup directory was created successfully
    if (!file_exists($backup_dir)) {
        deactivate_plugins(plugin_basename(WP_GITHUB_SYNC_DIR . 'wp-github-sync.php'));
        wp_die(__('Failed to create backup directory. Please check permissions.', 'wp-github-sync'));
    }

    // Generate webhook secret if it doesn't exist
    if (!get_option('wp_github_sync_webhook_secret')) {
        update_option('wp_github_sync_webhook_secret', wp_github_sync_generate_webhook_secret());
    }

    // Initialize encryption key if needed
    if (!get_option('wp_github_sync_encryption_key') && !defined('WP_GITHUB_SYNC_ENCRYPTION_KEY')) {
        update_option('wp_github_sync_encryption_key', wp_generate_password(64, true, true));
    }
    
    // Make sure the webhook secret generation function is available
    if (!function_exists('wp_github_sync_generate_webhook_secret')) {
        require_once WP_GITHUB_SYNC_DIR . 'includes/utils/helper-functions.php';
    }

    // Set default options if they don't exist
    $default_options = array(
        'wp_github_sync_branch' => 'main',
        'wp_github_sync_auto_sync' => false,
        'wp_github_sync_auto_sync_interval' => 5,
        'wp_github_sync_auto_deploy' => false,
        'wp_github_sync_webhook_deploy' => true,
        'wp_github_sync_create_backup' => true,
        'wp_github_sync_backup_config' => false,
        'wp_github_sync_maintenance_mode' => true,
        'wp_github_sync_notify_updates' => false,
        'wp_github_sync_delete_removed' => true,
        'wp_github_sync_auth_method' => 'pat',
    );

    foreach ($default_options as $option => $value) {
        if (get_option($option) === false) {
            update_option($option, $value);
        }
    }

    // Create a Sync_Manager instance and call its activate method
    try {
        // Only create the bare minimum required objects
        $api_client = new \WPGitHubSync\API\API_Client();
        $sync_manager = new \WPGitHubSync\Sync\Sync_Manager($api_client);
        $sync_manager->activate();
    } catch (\Exception $e) {
        // Log the error and continue - we've already set the base options
        if (function_exists('wp_github_sync_log')) {
            wp_github_sync_log('Error during Sync_Manager activation: ' . $e->getMessage(), 'error', true);
        }
    }
    
    // Flush rewrite rules to register the webhook endpoint
    flush_rewrite_rules();
    
    // Register chunked sync handler
    add_action('wp_github_sync_process_chunk', 'wp_github_sync_process_chunk_handler');
}

/**
 * Plugin deactivation hook.
 */
function wp_github_sync_deactivate() {
    // Clear scheduled cron jobs
    wp_clear_scheduled_hook('wp_github_sync_cron_hook');
    wp_clear_scheduled_hook('wp_github_sync_background_deploy');
    wp_clear_scheduled_hook('wp_github_sync_process_chunk');

    // Flush rewrite rules
    flush_rewrite_rules();
}

/**
 * Plugin uninstall hook.
 */
function wp_github_sync_uninstall() {
    // Options to delete
    $options = array(
        'wp_github_sync_repository',
        'wp_github_sync_branch',
        'wp_github_sync_auth_method',
        'wp_github_sync_access_token',
        'wp_github_sync_oauth_token',
        'wp_github_sync_auto_sync',
        'wp_github_sync_auto_sync_interval',
        'wp_github_sync_auto_deploy',
        'wp_github_sync_webhook_deploy',
        'wp_github_sync_webhook_secret',
        'wp_github_sync_create_backup',
        'wp_github_sync_backup_config',
        'wp_github_sync_maintenance_mode',
        'wp_github_sync_notify_updates',
        'wp_github_sync_delete_removed',
        'wp_github_sync_last_deployed_commit',
        'wp_github_sync_latest_commit',
        'wp_github_sync_update_available',
        'wp_github_sync_deployment_in_progress',
        'wp_github_sync_last_deployment_time',
        'wp_github_sync_deployment_history',
        'wp_github_sync_last_backup',
        'wp_github_sync_encryption_key',
        'wp_github_sync_chunked_sync_state',
    );

    // Delete all options
    foreach ($options as $option) {
        delete_option($option);
    }

    // Remove the backup directory
    $backup_dir = WP_CONTENT_DIR . '/wp-github-sync-backups';
    if (file_exists($backup_dir)) {
        wp_github_sync_recursive_rmdir($backup_dir);
    }

    // Remove the debug log file
    $log_file = WP_CONTENT_DIR . '/wp-github-sync-debug.log';
    if (file_exists($log_file)) {
        @unlink($log_file);
    }
}

/**
 * Recursive directory removal (for uninstallation).
 *
 * @param string $dir The directory to remove.
 * @return bool True on success, false on failure.
 */
function wp_github_sync_recursive_rmdir($dir) {
    if (!is_dir($dir)) {
        return false;
    }
    
    $objects = scandir($dir);
    
    foreach ($objects as $object) {
        if ($object === '.' || $object === '..') {
            continue;
        }
        
        $path = $dir . '/' . $object;
        
        if (is_dir($path)) {
            wp_github_sync_recursive_rmdir($path);
        } else {
            unlink($path);
        }
    }
    
    return rmdir($dir);
}

/**
 * Handler for processing chunks of the initial sync operation.
 * This function processes a single chunk of work and then schedules
 * the next chunk if more work remains.
 */
function wp_github_sync_process_chunk_handler() {
    // Get the current sync state with detailed logging
    $sync_state = get_option('wp_github_sync_chunked_sync_state', null);
    
    // Log entry point with timestamp
    wp_github_sync_log("BACKGROUND PROCESS: Chunk handler triggered at " . date('Y-m-d H:i:s'), 'info', true);
    
    // Skip if no sync is in progress
    if (!$sync_state) {
        wp_github_sync_log("BACKGROUND PROCESS: No active sync state found, exiting chunk handler", 'warning', true);
        return;
    }
    
    // Log detailed sync state information
    $stage = isset($sync_state['stage']) ? $sync_state['stage'] : 'unknown';
    $current_item = isset($sync_state['current_item']) ? $sync_state['current_item'] : 'unknown';
    $total_items = isset($sync_state['total_items']) ? $sync_state['total_items'] : 'unknown';
    $progress = isset($sync_state['progress']) ? round($sync_state['progress'] * 100, 2) . '%' : 'unknown';
    
    wp_github_sync_log("BACKGROUND PROCESS: Sync state details:", 'info', true);
    wp_github_sync_log("  Stage: {$stage}", 'info', true);
    wp_github_sync_log("  Current item: {$current_item}", 'info', true);
    wp_github_sync_log("  Total items: {$total_items}", 'info', true);
    wp_github_sync_log("  Progress: {$progress}", 'info', true);
    
    // Log memory usage information for diagnosing resource issues
    $memory_limit = ini_get('memory_limit');
    $memory_usage = memory_get_usage(true) / 1024 / 1024;
    $memory_peak = memory_get_peak_usage(true) / 1024 / 1024;
    wp_github_sync_log("BACKGROUND PROCESS: Memory stats - Usage: {$memory_usage}MB, Peak: {$memory_peak}MB, Limit: {$memory_limit}", 'debug', true);
    
    // Log if any timeouts are set
    $max_execution_time = ini_get('max_execution_time');
    wp_github_sync_log("BACKGROUND PROCESS: Max execution time: {$max_execution_time}s", 'debug', true);
    
    // Check if temp directory exists
    if (!empty($sync_state['temp_dir'])) {
        if (!is_dir($sync_state['temp_dir'])) {
            wp_github_sync_log("BACKGROUND PROCESS ERROR: Temporary directory missing: {$sync_state['temp_dir']}", 'error', true);
            
            // Check parent directory permissions for diagnosis
            $parent_dir = dirname($sync_state['temp_dir']);
            if (is_dir($parent_dir)) {
                $parent_perms = substr(sprintf('%o', fileperms($parent_dir)), -4);
                wp_github_sync_log("BACKGROUND PROCESS: Parent directory {$parent_dir} exists with permissions {$parent_perms}", 'debug', true);
                
                // Check if parent is writable
                if (is_writable($parent_dir)) {
                    wp_github_sync_log("BACKGROUND PROCESS: Parent directory is writable", 'debug', true);
                } else {
                    wp_github_sync_log("BACKGROUND PROCESS ERROR: Parent directory is not writable", 'error', true);
                }
            } else {
                wp_github_sync_log("BACKGROUND PROCESS ERROR: Parent directory {$parent_dir} does not exist", 'error', true);
            }
            
            // Add additional system diagnostics
            wp_github_sync_log("BACKGROUND PROCESS: Current user: " . (function_exists('get_current_user') ? get_current_user() : 'unknown'), 'debug', true);
            wp_github_sync_log("BACKGROUND PROCESS: PHP running as: " . (function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid())['name'] : 'unknown'), 'debug', true);
            
            delete_option('wp_github_sync_chunked_sync_state');
            return;
        } else {
            wp_github_sync_log("BACKGROUND PROCESS: Temporary directory exists: {$sync_state['temp_dir']}", 'debug', true);
        }
    }
    
    // Create the repository object with error handling
    try {
        wp_github_sync_log("BACKGROUND PROCESS: Initializing API client", 'debug', true);
        $api_client = new \WPGitHubSync\API\API_Client();
        
        wp_github_sync_log("BACKGROUND PROCESS: Creating repository object", 'debug', true);
        $repository = new \WPGitHubSync\API\Repository($api_client);
        
        // Get the branch
        $branch = isset($sync_state['branch']) ? $sync_state['branch'] : 'main';
        wp_github_sync_log("BACKGROUND PROCESS: Using branch: {$branch}", 'debug', true);
        
        // Continue the chunked sync with enhanced logging
        wp_github_sync_log("BACKGROUND PROCESS: Continuing chunked sync operation in stage: {$stage}", 'info', true);
        $start_time = microtime(true);
        
        $result = $repository->continue_chunked_sync($sync_state, $branch);
        
        $execution_time = round(microtime(true) - $start_time, 2);
        wp_github_sync_log("BACKGROUND PROCESS: Chunk execution time: {$execution_time} seconds", 'debug', true);
        
        // Detailed logging based on the result
        if (is_wp_error($result)) {
            $error_code = $result->get_error_code();
            $error_message = $result->get_error_message();
            
            if ($error_code === 'sync_in_progress') {
                wp_github_sync_log("BACKGROUND PROCESS: Chunk processed successfully, continuing with code: {$error_code}", 'info', true);
                
                // Log additional details from the sync_state
                $updated_sync_state = get_option('wp_github_sync_chunked_sync_state', null);
                if ($updated_sync_state) {
                    $new_progress = isset($updated_sync_state['progress']) ? round($updated_sync_state['progress'] * 100, 2) . '%' : 'unknown';
                    wp_github_sync_log("BACKGROUND PROCESS: Updated progress: {$new_progress}", 'info', true);
                    
                    // Check if next chunk is already scheduled
                    if (wp_next_scheduled('wp_github_sync_process_chunk')) {
                        $next_run = wp_next_scheduled('wp_github_sync_process_chunk');
                        $time_until = $next_run - time();
                        wp_github_sync_log("BACKGROUND PROCESS: Next chunk scheduled to run in {$time_until} seconds", 'debug', true);
                    } else {
                        wp_github_sync_log("BACKGROUND PROCESS WARNING: Next chunk not scheduled", 'warning', true);
                    }
                }
            } else {
                wp_github_sync_log("BACKGROUND PROCESS ERROR: Error during chunked sync: [{$error_code}] {$error_message}", 'error', true);
                
                // Detailed error diagnostics
                if (strpos($error_message, 'API') !== false || strpos($error_code, 'github') !== false) {
                    wp_github_sync_log("BACKGROUND PROCESS ERROR: GitHub API issue detected", 'error', true);
                    wp_github_sync_log("BACKGROUND PROCESS: Check token permissions and API rate limits", 'error', true);
                } else if (strpos($error_message, 'permission') !== false || strpos($error_message, 'access') !== false) {
                    wp_github_sync_log("BACKGROUND PROCESS ERROR: File system permission issue detected", 'error', true);
                    wp_github_sync_log("BACKGROUND PROCESS: Check WordPress directory permissions", 'error', true);
                } else if (strpos($error_message, 'memory') !== false) {
                    wp_github_sync_log("BACKGROUND PROCESS ERROR: Memory limit issue detected", 'error', true);
                    wp_github_sync_log("BACKGROUND PROCESS: Consider increasing PHP memory_limit", 'error', true);
                } else if (strpos($error_message, 'timeout') !== false || strpos($error_message, 'timed out') !== false) {
                    wp_github_sync_log("BACKGROUND PROCESS ERROR: Timeout issue detected", 'error', true);
                    wp_github_sync_log("BACKGROUND PROCESS: Consider increasing PHP max_execution_time", 'error', true);
                }
                
                delete_option('wp_github_sync_chunked_sync_state');
            }
        } else if ($result === true) {
            wp_github_sync_log("BACKGROUND PROCESS: Chunk processed successfully, next chunk scheduled", 'info', true);
            
            // Check if next chunk is scheduled
            if (wp_next_scheduled('wp_github_sync_process_chunk')) {
                $next_run = wp_next_scheduled('wp_github_sync_process_chunk');
                $time_until = $next_run - time();
                wp_github_sync_log("BACKGROUND PROCESS: Next chunk will run in {$time_until} seconds", 'debug', true);
            } else {
                wp_github_sync_log("BACKGROUND PROCESS WARNING: Expected next chunk to be scheduled but none found", 'warning', true);
                
                // Try to reschedule
                wp_schedule_single_event(time() + 10, 'wp_github_sync_process_chunk');
                wp_github_sync_log("BACKGROUND PROCESS: Attempted to reschedule next chunk", 'info', true);
            }
        } else {
            wp_github_sync_log("BACKGROUND PROCESS: Chunked sync completed successfully", 'info', true);
            
            // Log final statistics
            $total_chunks = isset($sync_state['chunks_processed']) ? $sync_state['chunks_processed'] : 'unknown';
            $start_timestamp = isset($sync_state['start_time']) ? $sync_state['start_time'] : 0;
            $total_time = $start_timestamp ? round((time() - $start_timestamp) / 60, 2) : 'unknown';
            
            wp_github_sync_log("BACKGROUND PROCESS: Sync summary - Total chunks: {$total_chunks}, Total time: {$total_time} minutes", 'info', true);
            
            // Clean up
            delete_option('wp_github_sync_chunked_sync_state');
            wp_github_sync_log("BACKGROUND PROCESS: Sync state cleared, process complete", 'info', true);
        }
    } catch (\Exception $e) {
        $exception_message = $e->getMessage();
        $exception_trace = $e->getTraceAsString();
        $exception_file = $e->getFile();
        $exception_line = $e->getLine();
        
        wp_github_sync_log("BACKGROUND PROCESS EXCEPTION: {$exception_message}", 'error', true);
        wp_github_sync_log("BACKGROUND PROCESS EXCEPTION: in {$exception_file}:{$exception_line}", 'error', true);
        wp_github_sync_log("BACKGROUND PROCESS EXCEPTION: Stack trace:", 'error', true);
        
        // Log stack trace lines for better diagnosis
        $trace_lines = explode("\n", $exception_trace);
        foreach (array_slice($trace_lines, 0, 10) as $line) { // Limit to first 10 lines
            wp_github_sync_log("  {$line}", 'error', true);
        }
        
        // Check for specific exception types
        if (strpos($exception_message, 'cURL error') !== false) {
            wp_github_sync_log("BACKGROUND PROCESS: Network connectivity issue detected", 'error', true);
        } else if (strpos($exception_message, 'memory') !== false) {
            wp_github_sync_log("BACKGROUND PROCESS: Memory limit issue detected", 'error', true);
        } else if (strpos($exception_message, 'Permission') !== false) {
            wp_github_sync_log("BACKGROUND PROCESS: Filesystem permission issue detected", 'error', true);
        }
        
        // Clean up and notify
        delete_option('wp_github_sync_chunked_sync_state');
        wp_github_sync_log("BACKGROUND PROCESS: Sync state cleared due to exception", 'error', true);
        
        // Set a transient to show admin notice
        set_transient('wp_github_sync_background_error', [
            'message' => $exception_message,
            'time' => time()
        ], DAY_IN_SECONDS);
    }
}