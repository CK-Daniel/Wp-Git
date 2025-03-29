<?php
/**
 * Handles OAuth related AJAX requests for the WordPress GitHub Sync plugin.
 *
 * @package WPGitHubSync\Admin\AJAX
 */

namespace WPGitHubSync\Admin\AJAX;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * OAuth Actions AJAX Handler class.
 */
class OAuthActionsHandler {

    use VerifiesRequestTrait; // Use the trait for verification

    /**
     * Constructor.
     */
    public function __construct() {
        // Dependencies can be added here if needed (e.g., OAuth_Handler)
    }

    /**
     * Register AJAX hooks for OAuth actions.
     */
    public function register_hooks() {
        add_action('wp_ajax_wp_github_sync_oauth_connect', array($this, 'handle_ajax_oauth_connect'));
        add_action('wp_ajax_wp_github_sync_oauth_disconnect', array($this, 'handle_ajax_oauth_disconnect'));
    }

    /**
     * Handle AJAX OAuth connect request.
     */
    public function handle_ajax_oauth_connect() {
        $this->verify_request();

        // In a real implementation, you would have registered a GitHub OAuth App
        $client_id = defined('WP_GITHUB_SYNC_OAUTH_CLIENT_ID') ? WP_GITHUB_SYNC_OAUTH_CLIENT_ID : '';

        if (empty($client_id)) {
            wp_send_json_error(array('message' => __('GitHub OAuth Client ID is not configured. Please define WP_GITHUB_SYNC_OAUTH_CLIENT_ID in your wp-config.php file.', 'wp-github-sync')));
        }

        // Generate a state value for security
        $state = wp_generate_password(24, false);
        update_option('wp_github_sync_oauth_state', $state);

        // Generate the auth URL
        $redirect_uri = admin_url('admin.php?page=wp-github-sync-settings&github_oauth_callback=1');
        $oauth_url = add_query_arg(
            array(
                'client_id' => $client_id,
                'redirect_uri' => urlencode($redirect_uri),
                'scope' => 'repo', // Request repository access
                'state' => $state,
            ),
            'https://github.com/login/oauth/authorize'
        );

        wp_send_json_success(array('oauth_url' => $oauth_url));
    }

    /**
     * Handle AJAX OAuth disconnect request.
     */
    public function handle_ajax_oauth_disconnect() {
        $this->verify_request();

        // Delete the stored OAuth token
        delete_option('wp_github_sync_oauth_token');
        // Also clear related OAuth data if any
        delete_option('wp_github_sync_oauth_state');

        wp_send_json_success(array('message' => __('Successfully disconnected from GitHub OAuth.', 'wp-github-sync')));
    }

} // End class OAuthActionsHandler
