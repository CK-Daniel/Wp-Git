<?php
/**
 * Handles making HTTP requests to the GitHub API using WP_Http.
 *
 * @package WPGitHubSync\API
 */

namespace WPGitHubSync\API;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Request Handler class.
 */
class RequestHandler {

    /**
     * Make an HTTP request using wp_remote_request.
     *
     * @param string $url     The full URL for the request.
     * @param array  $args    Arguments for wp_remote_request (method, headers, body, timeout, etc.).
     * @param int    $max_retries Maximum number of retries on failure.
     * @param bool   $auto_retry Whether to automatically retry on specific failures.
     * @return array|\WP_Error The response array or WP_Error on failure.
     */
    public function execute(string $url, array $args, int $max_retries = 2, bool $auto_retry = true) {
        // Perform the request - Retries are handled by the RequestRetryHandler trait in API_Client
        $response = wp_remote_request($url, $args);

        // Log errors if they occur
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $error_code = $response->get_error_code();
            wp_github_sync_log("Request failed: {$error_code} - {$error_message}", 'error');
        } else {
            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code >= 400) {
                wp_github_sync_log("Request returned HTTP {$response_code}", 'warning');
            }
        }

        // Return the response (success or WP_Error)
        return $response;
    }
}
