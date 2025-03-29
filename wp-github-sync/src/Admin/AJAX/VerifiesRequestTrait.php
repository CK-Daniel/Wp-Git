<?php
/**
 * Trait for common AJAX request verification (nonce, permissions).
 *
 * @package WPGitHubSync\Admin\AJAX
 */

namespace WPGitHubSync\Admin\AJAX;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Trait VerifiesRequestTrait.
 */
trait VerifiesRequestTrait {

    /**
     * Verify nonce and user permissions for an AJAX request.
     * Sends JSON error and dies if verification fails.
     *
     * @param string $nonce_action The expected nonce action. Defaults to 'wp_github_sync_nonce'.
     * @param string $capability   The required capability. Defaults to the plugin's default capability check.
     * @return bool True if verification passes (though script dies on failure).
     */
    protected function verify_request(string $nonce_action = 'wp_github_sync_nonce', string $capability = '') {
        // Use default capability check if none provided
        $can_user = empty($capability) ? wp_github_sync_current_user_can() : current_user_can($capability);

        if (!$can_user) {
            wp_github_sync_log("AJAX Error: Permission denied for action '{$nonce_action}'", 'error');
            wp_send_json_error(['message' => __('You do not have sufficient permissions to perform this action.', 'wp-github-sync')], 403);
            // wp_die(); // wp_send_json_error includes die()
        }

        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], $nonce_action)) {
            wp_github_sync_log("AJAX Error: Nonce verification failed for action '{$nonce_action}'", 'error');
            wp_send_json_error(['message' => __('Security check failed. Please refresh the page and try again.', 'wp-github-sync')], 403);
            // wp_die(); // wp_send_json_error includes die()
        }

        return true;
    }
}
