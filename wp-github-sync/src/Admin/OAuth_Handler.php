<?php
/**
 * Handles GitHub OAuth flow for the WordPress GitHub Sync plugin.
 *
 * @package WPGitHubSync\Admin
 */

namespace WPGitHubSync\Admin;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * OAuth Handler class.
 */
class OAuth_Handler {

    /**
     * Constructor.
     */
    public function __construct() {
        // Dependencies can be added here later if needed
    }

    /**
     * Register WordPress hooks.
     */
    public function register_hooks() {
        // AJAX handlers are registered by AJAX_Handler class
        // We only need to register the callback handler here
        add_action('admin_init', array($this, 'handle_oauth_callback'));
    }

    // Removed handle_ajax_oauth_connect() - Logic moved to AJAX\OAuthActionsHandler
    // Removed handle_ajax_oauth_disconnect() - Logic moved to AJAX\OAuthActionsHandler

    /**
     * Handle OAuth callback from GitHub.
     */
    public function handle_oauth_callback() {
        // Check if this is an OAuth callback
        if (!isset($_GET['github_oauth_callback']) || $_GET['github_oauth_callback'] != 1) {
            return;
        }

        // Check if we have a code and state
        if (!isset($_GET['code']) || !isset($_GET['state'])) {
            add_settings_error(
                'wp_github_sync',
                'oauth_failed',
                __('GitHub OAuth authentication failed. Missing code or state.', 'wp-github-sync'),
                'error'
            );
            return;
        }

        // Verify state to prevent CSRF
        $stored_state = get_option('wp_github_sync_oauth_state', '');

        if (empty($stored_state) || $_GET['state'] !== $stored_state) {
            add_settings_error(
                'wp_github_sync',
                'oauth_failed',
                __('GitHub OAuth authentication failed. Invalid state.', 'wp-github-sync'),
                'error'
            );
            return;
        }

        // Clear the state
        delete_option('wp_github_sync_oauth_state');

        // Exchange code for access token
        $client_id = defined('WP_GITHUB_SYNC_OAUTH_CLIENT_ID') ? WP_GITHUB_SYNC_OAUTH_CLIENT_ID : '';
        $client_secret = defined('WP_GITHUB_SYNC_OAUTH_CLIENT_SECRET') ? WP_GITHUB_SYNC_OAUTH_CLIENT_SECRET : '';

        if (empty($client_id) || empty($client_secret)) {
            add_settings_error(
                'wp_github_sync',
                'oauth_failed',
                __('GitHub OAuth authentication failed. Client ID or Client Secret is not configured.', 'wp-github-sync'),
                'error'
            );
            return;
        }

        $response = wp_remote_post('https://github.com/login/oauth/access_token', array(
            'body' => array(
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'code' => $_GET['code'],
                'redirect_uri' => admin_url('admin.php?page=wp-github-sync-settings&github_oauth_callback=1'),
            ),
            'headers' => array(
                'Accept' => 'application/json', // Request JSON response
            ),
        ));

        if (is_wp_error($response)) {
            add_settings_error(
                'wp_github_sync',
                'oauth_failed',
                sprintf(__('GitHub OAuth authentication failed. Error: %s', 'wp-github-sync'), $response->get_error_message()),
                'error'
            );
            return;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!isset($body['access_token'])) {
            $error_message = isset($body['error_description']) ? $body['error_description'] : __('No access token received.', 'wp-github-sync');
            add_settings_error(
                'wp_github_sync',
                'oauth_failed',
                sprintf(__('GitHub OAuth authentication failed. %s', 'wp-github-sync'), $error_message),
                'error'
            );
            return;
        }

        // Store the token (encrypted)
        $encrypted_token = wp_github_sync_encrypt($body['access_token']);
        if ($encrypted_token === false) {
             add_settings_error(
                'wp_github_sync',
                'oauth_failed',
                __('Failed to encrypt the received OAuth token.', 'wp-github-sync'),
                'error'
            );
            return;
        }

        update_option('wp_github_sync_oauth_token', $encrypted_token);
        update_option('wp_github_sync_auth_method', 'oauth'); // Set auth method to OAuth

        // Clear PAT token if it exists, as OAuth is now primary
        delete_option('wp_github_sync_access_token');

        add_settings_error(
            'wp_github_sync',
            'oauth_success',
            __('Successfully connected to GitHub using OAuth.', 'wp-github-sync'),
            'success'
        );

        // Redirect back to settings page cleanly
        wp_safe_redirect(admin_url('admin.php?page=wp-github-sync-settings'));
        exit;
    }
}
