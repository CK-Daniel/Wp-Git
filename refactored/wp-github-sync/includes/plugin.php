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
 * Plugin activation hook.
 */
function wp_github_sync_activate() {
    // Create required directories
    $backup_dir = WP_CONTENT_DIR . '/wp-github-sync-backups';
    if (!file_exists($backup_dir)) {
        wp_mkdir_p($backup_dir);
    }

    // Generate webhook secret if it doesn't exist
    if (!get_option('wp_github_sync_webhook_secret')) {
        update_option('wp_github_sync_webhook_secret', wp_github_sync_generate_webhook_secret());
    }

    // Initialize encryption key if needed
    if (!get_option('wp_github_sync_encryption_key') && !defined('WP_GITHUB_SYNC_ENCRYPTION_KEY')) {
        update_option('wp_github_sync_encryption_key', wp_generate_password(64, true, true));
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

    // Flush rewrite rules to register the webhook endpoint
    flush_rewrite_rules();
}

/**
 * Plugin deactivation hook.
 */
function wp_github_sync_deactivate() {
    // Clear scheduled cron job
    wp_clear_scheduled_hook('wp_github_sync_cron_hook');
    wp_clear_scheduled_hook('wp_github_sync_background_deploy');

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