<?php
/**
 * Handles Utility related AJAX requests for the WordPress GitHub Sync plugin.
 *
 * @package WPGitHubSync\Admin\AJAX
 */

namespace WPGitHubSync\Admin\AJAX;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Utility Actions AJAX Handler class.
 */
class UtilityActionsHandler {

    use VerifiesRequestTrait; // Use the trait for verification

    /**
     * Constructor.
     */
    public function __construct() {
        // Dependencies can be added here if needed
    }

    /**
     * Register AJAX hooks for utility actions.
     */
    public function register_hooks() {
        add_action('wp_ajax_wp_github_sync_log_error', array($this, 'handle_ajax_log_error'));
        add_action('wp_ajax_wp_github_sync_check_requirements', array($this, 'handle_ajax_check_requirements'));
    }

    /**
     * Handle AJAX log error request.
     */
    public function handle_ajax_log_error() {
        $this->verify_request();

        // Get error details from AJAX request
        $error_context = isset($_POST['error_context']) ? sanitize_text_field($_POST['error_context']) : 'Unknown context';
        $error_status = isset($_POST['error_status']) ? sanitize_text_field($_POST['error_status']) : 'Unknown status';
        $error_message = isset($_POST['error_message']) ? sanitize_text_field($_POST['error_message']) : 'Unknown error';

        // Log error with detailed information
        wp_github_sync_log(
            "JavaScript Error - Context: {$error_context}, Status: {$error_status}, Message: {$error_message}",
            'error',
            true // Force logging even if debug mode is off
        );

        // No need to send a detailed response
        wp_send_json_success();
    }

    /**
     * Handle AJAX system requirements check.
     * Verifies PHP settings, directory permissions, and GitHub credentials.
     */
    public function handle_ajax_check_requirements() {
        $this->verify_request();

        wp_github_sync_log("Starting system requirements check", 'info');

        $requirements = array();
        $requirements_passed = true;

        // 1. Check PHP version
        $php_version = phpversion();
        $min_php_version = '7.4.0';
        $php_check = version_compare($php_version, $min_php_version, '>=');
        $requirements[] = array(
            'name' => 'PHP Version',
            'status' => $php_check,
            'value' => $php_version,
            'expected' => '>= ' . $min_php_version,
            'message' => $php_check
                ? sprintf(__('PHP version %s is compatible', 'wp-github-sync'), $php_version)
                : sprintf(__('PHP version %s is too old, need %s or higher', 'wp-github-sync'), $php_version, $min_php_version)
        );
        $requirements_passed = $requirements_passed && $php_check;

        // 2. Check PHP extensions
        $required_extensions = array('curl', 'json', 'mbstring', 'zip');
        foreach ($required_extensions as $extension) {
            $has_extension = extension_loaded($extension);
            $requirements[] = array(
                'name' => sprintf(__('PHP %s Extension', 'wp-github-sync'), strtoupper($extension)),
                'status' => $has_extension,
                'value' => $has_extension ? __('Enabled', 'wp-github-sync') : __('Disabled', 'wp-github-sync'),
                'expected' => __('Enabled', 'wp-github-sync'),
                'message' => $has_extension
                    ? sprintf(__('%s extension is available', 'wp-github-sync'), strtoupper($extension))
                    : sprintf(__('%s extension is required but not available', 'wp-github-sync'), strtoupper($extension))
            );
            $requirements_passed = $requirements_passed && $has_extension;
        }

        // 3. Check PHP memory limit
        $memory_limit = ini_get('memory_limit');
        $min_memory = '64M';
        $memory_limit_bytes = wp_convert_hr_to_bytes($memory_limit);
        $min_memory_bytes = wp_convert_hr_to_bytes($min_memory);
        $memory_check = ($memory_limit_bytes >= $min_memory_bytes);
        $requirements[] = array(
            'name' => 'PHP Memory Limit',
            'status' => $memory_check,
            'value' => $memory_limit,
            'expected' => '>= ' . $min_memory,
            'message' => $memory_check
                ? sprintf(__('Memory limit %s is sufficient', 'wp-github-sync'), $memory_limit)
                : sprintf(__('Memory limit %s is too low, need at least %s', 'wp-github-sync'), $memory_limit, $min_memory)
        );
        $requirements_passed = $requirements_passed && $memory_check;

        // 4. Check PHP max execution time
        $max_execution_time = ini_get('max_execution_time');
        $min_execution_time = 30;
        $execution_check = ($max_execution_time == 0 || $max_execution_time >= $min_execution_time);
        $requirements[] = array(
            'name' => 'PHP Max Execution Time',
            'status' => $execution_check,
            'value' => $max_execution_time == 0 ? __('No limit', 'wp-github-sync') : $max_execution_time . __(' seconds', 'wp-github-sync'),
            'expected' => '>= ' . $min_execution_time . __(' seconds', 'wp-github-sync'),
            'message' => $execution_check
                ? ($max_execution_time == 0
                    ? __('No execution time limit (ideal)', 'wp-github-sync')
                    : sprintf(__('Max execution time %s seconds is sufficient', 'wp-github-sync'), $max_execution_time))
                : sprintf(__('Max execution time %s seconds is too low, need at least %s seconds', 'wp-github-sync'),
                    $max_execution_time, $min_execution_time)
        );
        $requirements_passed = $requirements_passed && $execution_check;

        // 5. Check temporary directory
        $tmp_dir = sys_get_temp_dir();
        $tmp_dir_writable = is_writable($tmp_dir);
        $requirements[] = array(
            'name' => 'Temporary Directory',
            'status' => $tmp_dir_writable,
            'value' => $tmp_dir,
            'expected' => __('Writable', 'wp-github-sync'),
            'message' => $tmp_dir_writable
                ? sprintf(__('Temporary directory %s is writable', 'wp-github-sync'), $tmp_dir)
                : sprintf(__('Temporary directory %s is not writable', 'wp-github-sync'), $tmp_dir)
        );
        $requirements_passed = $requirements_passed && $tmp_dir_writable;

        // 6. Check wp-content directory
        $wp_content_dir = WP_CONTENT_DIR;
        $wp_content_writable = is_writable($wp_content_dir);
        $requirements[] = array(
            'name' => 'WordPress Content Directory',
            'status' => $wp_content_writable,
            'value' => $wp_content_dir,
            'expected' => __('Writable', 'wp-github-sync'),
            'message' => $wp_content_writable
                ? sprintf(__('WordPress content directory %s is writable', 'wp-github-sync'), $wp_content_dir)
                : sprintf(__('WordPress content directory %s is not writable', 'wp-github-sync'), $wp_content_dir)
        );
        $requirements_passed = $requirements_passed && $wp_content_writable;

        // 7. Check if GitHub API is configured
        $token_configured = false;
        $auth_method = get_option('wp_github_sync_auth_method', 'pat');
        if ($auth_method === 'pat') {
            $token_configured = !empty(get_option('wp_github_sync_access_token', ''));
        } else if ($auth_method === 'oauth') {
            $token_configured = !empty(get_option('wp_github_sync_oauth_token', ''));
        } else if ($auth_method === 'github_app') {
             $token_configured = !empty(get_option('wp_github_sync_github_app_id', '')) &&
                                !empty(get_option('wp_github_sync_github_app_installation_id', '')) &&
                                !empty(get_option('wp_github_sync_github_app_key', ''));
        }
        $requirements[] = array(
            'name' => 'GitHub Authentication',
            'status' => $token_configured,
            'value' => $token_configured ? __('Configured', 'wp-github-sync') : __('Not configured', 'wp-github-sync'),
            'expected' => __('Configured', 'wp-github-sync'),
            'message' => $token_configured
                ? sprintf(__('GitHub %s authentication is configured', 'wp-github-sync'), strtoupper($auth_method))
                : sprintf(__('GitHub authentication is not configured. Please complete setup in settings.', 'wp-github-sync'))
        );
        $requirements_passed = $requirements_passed && $token_configured;

        // 8. Check if repository is configured
        $repo_configured = !empty(get_option('wp_github_sync_repository', ''));
        $requirements[] = array(
            'name' => 'GitHub Repository',
            'status' => $repo_configured,
            'value' => $repo_configured ? __('Configured', 'wp-github-sync') : __('Not configured', 'wp-github-sync'),
            'expected' => __('Configured', 'wp-github-sync'),
            'message' => $repo_configured
                ? __('GitHub repository is configured', 'wp-github-sync')
                : __('GitHub repository is not configured', 'wp-github-sync')
        );
        // Don't fail overall check if repository not configured - this is expected for initial setup

        // Determine overall status
        if ($requirements_passed) {
            wp_github_sync_log("All system requirements passed", 'info');
            wp_send_json_success(array(
                'message' => __('All system requirements check passed.', 'wp-github-sync'),
                'requirements' => $requirements
            ));
        } else {
            wp_github_sync_log("Some system requirements failed", 'warning');
            wp_send_json_error(array(
                'message' => __('Some system requirements check failed. See details below.', 'wp-github-sync'),
                'details' => $requirements
            ));
        }
    }

} // End class UtilityActionsHandler
