<?php
/**
 * Trait for handling retries of GitHub API requests.
 *
 * @package WPGitHubSync\API
 */

namespace WPGitHubSync\API;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Trait RequestRetryHandler.
 */
trait RequestRetryHandler {

    /**
     * Executes a request callable with retry logic.
     *
     * @param callable $request_callable The function that makes the actual wp_remote_request call.
     *                                   It should accept $url and $args, and return the response or WP_Error.
     * @param string   $url            The request URL.
     * @param array    $args           The arguments for wp_remote_request.
     * @param int      $max_retries    Maximum number of retries.
     * @param bool     $auto_retry     Whether to enable automatic retries.
     * @return array|\WP_Error The final response or the last WP_Error.
     */
    protected function execute_with_retries(callable $request_callable, string $url, array $args, int $max_retries = 2, bool $auto_retry = true) {
        $retry_count = 0;
        $retry_delay = 1; // Initial delay in seconds
        $response = null;

        do {
            // If this is a retry, delay before attempting again
            if ($retry_count > 0) {
                $delay_seconds = $retry_delay * pow(2, $retry_count - 1); // Exponential backoff
                wp_github_sync_log("Retry #{$retry_count} for request to {$url}. Delaying {$delay_seconds} seconds.", 'info');
                sleep($delay_seconds);
            }

            // Perform the request using the provided callable
            $response = call_user_func($request_callable, $url, $args);

            // Check for WP_Error (network level issues)
            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                $error_code = $response->get_error_code();
                wp_github_sync_log("Request failed (attempt ".($retry_count+1)."): {$error_code} - {$error_message}", 'error');

                // Only retry on specific network errors
                $can_retry = $auto_retry &&
                             (strpos($error_message, 'cURL error') !== false ||
                              strpos($error_message, 'connect() timed out') !== false ||
                              strpos($error_message, 'Connection reset') !== false);

                if (!$can_retry) {
                    wp_github_sync_log("Network error not retriable, giving up.", 'warning');
                    break; // Exit retry loop
                }
            } else {
                // Check HTTP response code for potentially retriable errors
                $response_code = wp_remote_retrieve_response_code($response);
                if ($response_code >= 400) {
                    wp_github_sync_log("Request returned HTTP {$response_code} (attempt ".($retry_count+1).")", 'warning');

                    // Retry on rate limits (403/429) and server errors (5xx)
                    $body = wp_remote_retrieve_body($response);
                    $body_data = json_decode($body, true);
                    $error_message = isset($body_data['message']) ? $body_data['message'] : '';

                    $can_retry = $auto_retry &&
                                ($response_code == 429 || // Too Many Requests
                                 $response_code >= 500 || // Server Errors
                                 ($response_code == 403 && // Forbidden - check for rate limit messages
                                  (strpos($error_message, 'rate limit') !== false ||
                                   strpos($error_message, 'secondary') !== false)));

                    if (!$can_retry) {
                        wp_github_sync_log("HTTP error code {$response_code} not retriable, continuing.", 'debug');
                        break; // Exit retry loop
                    }
                } else {
                    // Success (2xx or 3xx), no need to retry
                    break; // Exit retry loop
                }
            }

            $retry_count++;
        } while ($retry_count < $max_retries); // Use < for correct number of retries (0, 1, 2 = 3 attempts total if max_retries=3)

        // Return the final response (either success or the last error)
        return $response;
    }
}
