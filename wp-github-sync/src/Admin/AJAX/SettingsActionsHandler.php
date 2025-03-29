<?php
/**
 * Handles Settings related AJAX requests for the WordPress GitHub Sync plugin.
 *
 * @package WPGitHubSync\Admin\AJAX
 */

namespace WPGitHubSync\Admin\AJAX;

use WPGitHubSync\API\API_Client;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Settings Actions AJAX Handler class.
 */
class SettingsActionsHandler {

    use VerifiesRequestTrait; // Use the trait for verification

    /**
     * API Client instance.
     *
     * @var API_Client
     */
    private $github_api;

    /**
     * Constructor.
     *
     * @param API_Client $github_api The API Client instance.
     */
    public function __construct(API_Client $github_api) {
        $this->github_api = $github_api;
    }

    /**
     * Register AJAX hooks for settings actions.
     */
    public function register_hooks() {
        add_action('wp_ajax_wp_github_sync_refresh_branches', array($this, 'handle_ajax_refresh_branches'));
        add_action('wp_ajax_wp_github_sync_regenerate_webhook', array($this, 'handle_ajax_regenerate_webhook'));
        add_action('wp_ajax_wp_github_sync_test_connection', array($this, 'handle_ajax_test_connection'));
    }

    /**
     * Handle AJAX refresh branches request.
     */
    public function handle_ajax_refresh_branches() {
        $this->verify_request();

        wp_github_sync_log("Refresh Branches AJAX: Fetching branches from repository", 'info');

        // Refresh GitHub API client (in case settings changed)
        $this->github_api->initialize();

        // Get branches
        $branches = array();
        $branches_api = $this->github_api->get_branches();

        if (is_wp_error($branches_api)) {
            $error_message = $branches_api->get_error_message();
            wp_github_sync_log("Refresh Branches AJAX: Failed to fetch branches - {$error_message}", 'error');
            wp_send_json_error(array('message' => $error_message));
        } else {
            foreach ($branches_api as $branch_data) {
                if (isset($branch_data['name'])) {
                    $branches[] = $branch_data['name'];
                }
            }

            $branches_count = count($branches);
            wp_github_sync_log("Refresh Branches AJAX: Successfully fetched {$branches_count} branches", 'info');

            // Store the branches in the database for future use
            update_option('wp_github_sync_branches', $branches);

            wp_send_json_success(array('branches' => $branches));
        }
    }

    /**
     * Handle AJAX regenerate webhook secret request.
     */
    public function handle_ajax_regenerate_webhook() {
        $this->verify_request();

        wp_github_sync_log("Regenerate Webhook AJAX: Generating new webhook secret", 'info');

        // Get old secret for logging
        $old_secret = get_option('wp_github_sync_webhook_secret', '');
        $has_old_secret = !empty($old_secret);

        // Generate new webhook secret using the helper function
        $new_secret = wp_github_sync_generate_webhook_secret();
        update_option('wp_github_sync_webhook_secret', $new_secret);

        if ($has_old_secret) {
            wp_github_sync_log("Regenerate Webhook AJAX: Webhook secret was successfully regenerated", 'info');
        } else {
            wp_github_sync_log("Regenerate Webhook AJAX: New webhook secret was generated (no previous secret existed)", 'info');
        }

        wp_send_json_success(array(
            'message' => __('Webhook secret regenerated successfully.', 'wp-github-sync'),
            'secret' => $new_secret,
        ));
    }

    /**
     * Handle AJAX test connection request.
     */
    public function handle_ajax_test_connection() {
        $this->verify_request();

        // Check if a temporary token was provided for testing
        $temp_token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';
        $repo_url = isset($_POST['repo_url']) ? esc_url_raw($_POST['repo_url']) : get_option('wp_github_sync_repository', '');

        wp_github_sync_log("Test Connection AJAX: Testing GitHub connection" .
            (!empty($temp_token) ? " with new token" : " with existing token") .
            (!empty($repo_url) ? " and repository URL: {$repo_url}" : ""), 'info');

        // If a temporary token was provided, use it instead of the stored one
        if (!empty($temp_token)) {
            // Log token length and format for debugging without revealing the actual token
            $token_length = strlen($temp_token);
            $token_prefix = substr($temp_token, 0, 8);
            $token_suffix = substr($temp_token, -4);

            wp_github_sync_log("Test Connection AJAX: Using temporary token with length {$token_length}, prefix {$token_prefix}..., suffix ...{$token_suffix}", 'debug');

            // Validate token format before proceeding
            $is_valid_format = false;
            if (strpos($temp_token, 'github_pat_') === 0 || strpos($temp_token, 'ghp_') === 0 || strpos($temp_token, 'gho_') === 0 || (strlen($temp_token) === 40 && ctype_xdigit($temp_token))) {
                $is_valid_format = true;
            } else {
                wp_github_sync_log("Test Connection AJAX: Warning - Token format doesn't match known GitHub token patterns", 'warning');
            }

            // Set the token directly on the API client (bypassing encryption)
            $this->github_api->set_temporary_token($temp_token);
        } else {
            wp_github_sync_log("Test Connection AJAX: Using previously stored token", 'debug');
            // Ensure API client is initialized with stored token
            $this->github_api->initialize();
        }

        // Test authentication
        wp_github_sync_log("Test Connection AJAX: Starting authentication test", 'debug');
        $auth_test = $this->github_api->test_authentication();

        if ($auth_test === true) {
            $username = $this->github_api->get_user_login();
            wp_github_sync_log("Test Connection AJAX: Authentication successful! Authenticated as user: {$username}", 'info');

            // Authentication succeeded, now check repo if provided
            if (!empty($repo_url)) {
                // Parse the repo URL
                $parsed_url = $this->github_api->parse_github_url($repo_url);

                if ($parsed_url) {
                    $owner = $parsed_url['owner'];
                    $repo = $parsed_url['repo'];
                    wp_github_sync_log("Test Connection AJAX: Checking repository: {$owner}/{$repo}", 'info');

                    // Check if repo exists and is accessible
                    $repo_exists = $this->github_api->repository_exists($owner, $repo);

                    if ($repo_exists) {
                        wp_github_sync_log("Test Connection AJAX: Repository verified and accessible", 'info');
                        wp_send_json_success(array(
                            'message' => __('Success! Your GitHub credentials and repository are valid.', 'wp-github-sync'),
                            'username' => $username,
                            'repo_info' => array('owner' => $owner, 'repo' => $repo)
                        ));
                    } else {
                        wp_github_sync_log("Test Connection AJAX: Repository not accessible: {$owner}/{$repo}", 'error');
                        wp_send_json_error(array(
                            'message' => __('Authentication successful, but the repository could not be accessed. Please check your repository URL and ensure your token has access to it.', 'wp-github-sync'),
                            'username' => $username, 'auth_ok' => true, 'repo_error' => true
                        ));
                    }
                } else {
                    wp_github_sync_log("Test Connection AJAX: Invalid repository URL format: {$repo_url}", 'error');
                    wp_send_json_error(array(
                        'message' => __('Authentication successful, but the repository URL is invalid. Please provide a valid GitHub repository URL.', 'wp-github-sync'),
                        'username' => $username, 'auth_ok' => true, 'url_error' => true
                    ));
                }
            } else {
                // Authentication worked but no repo was provided
                wp_send_json_success(array(
                    'message' => __('Authentication successful! Your GitHub credentials are valid.', 'wp-github-sync'),
                    'username' => $username, 'auth_ok' => true, 'no_repo' => true
                ));
            }
        } else {
            wp_github_sync_log("Test Connection AJAX: Authentication failed - {$auth_test}", 'error');
            wp_send_json_error(array(
                'message' => sprintf(__('Authentication failed: %s', 'wp-github-sync'), $auth_test),
                'auth_error' => true
            ));
        }
    }

} // End class SettingsActionsHandler
