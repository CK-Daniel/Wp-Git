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
    // Get the current sync state
    $sync_state = get_option('wp_github_sync_chunked_sync_state', null);
    
    // Skip if no sync is in progress
    if (!$sync_state) {
        return;
    }
    
    // Check if temp directory still exists
    if (!empty($sync_state['temp_dir']) && !is_dir($sync_state['temp_dir'])) {
        wp_github_sync_log("Temporary directory missing, aborting chunked sync", 'error');
        delete_option('wp_github_sync_chunked_sync_state');
        return;
    }
    
    // Create the repository object
    try {
        $api_client = new \WPGitHubSync\API\API_Client();
        $repository = new \WPGitHubSync\API\Repository($api_client);
        
        // Get the branch
        $branch = isset($sync_state['branch']) ? $sync_state['branch'] : 'main';
        
        // Continue the chunked sync
        wp_github_sync_log("Continuing chunked sync operation in stage: " . $sync_state['stage'], 'info');
        $result = $repository->continue_chunked_sync($sync_state, $branch);
        
        // If the result is a WP_Error with code 'sync_in_progress', processing is continuing in chunks
        if (is_wp_error($result) && $result->get_error_code() === 'sync_in_progress') {
            wp_github_sync_log("Chunk processed successfully, continuing...", 'info');
        } 
        // If true, process has more work but next chunk is already scheduled
        else if ($result === true) {
            wp_github_sync_log("Chunk processed, next chunk scheduled", 'info');
        }
        // If we got an error, log it and stop chunked processing
        else if (is_wp_error($result)) {
            wp_github_sync_log("Error during chunked sync: " . $result->get_error_message(), 'error');
            delete_option('wp_github_sync_chunked_sync_state');
        }
        // If we got something else, assume sync is complete
        else {
            wp_github_sync_log("Chunked sync completed successfully", 'info');
            delete_option('wp_github_sync_chunked_sync_state');
        }
    } catch (\Exception $e) {
        wp_github_sync_log("Exception during chunked sync: " . $e->getMessage(), 'error');
        delete_option('wp_github_sync_chunked_sync_state');
    }
}