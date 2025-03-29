<?php
/**
 * Handles GitHub API rate limit checking and management.
 *
 * @package WPGitHubSync\API
 */

namespace WPGitHubSync\API;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Rate Limit Handler class.
 */
class RateLimitHandler {

    /**
     * API Client instance (needed to make rate_limit request).
     * @var API_Client
     */
    private $api_client;

    /**
     * Constructor.
     *
     * @param API_Client $api_client The API Client instance.
     */
    public function __construct(API_Client $api_client) {
        $this->api_client = $api_client;
    }

    /**
     * Check if we're close to hitting rate limits and should wait.
     * Uses cached data first, then makes an API call if needed or cache is low.
     * Handles primary and secondary rate limits, including Retry-After headers.
     *
     * @return int|false Seconds to wait, or false if okay to proceed.
     */
    public function check_and_wait() {
        // 1. Check for secondary rate limit backoff first (most restrictive)
        $secondary_backoff = get_transient('wp_github_sync_secondary_ratelimit_backoff');
        if ($secondary_backoff !== false) {
            $wait_time = (int)$secondary_backoff;
            wp_github_sync_log("Secondary rate limit backoff active: wait {$wait_time} seconds", 'warning');
            return $wait_time;
        }

        // 2. Check for Retry-After directive (from 403 or 429 responses)
        $retry_after = get_transient('wp_github_sync_retry_after');
        if ($retry_after !== false && $retry_after > time()) {
            $wait_time = $retry_after - time();
            wp_github_sync_log("Retry-After period active: wait {$wait_time} seconds", 'warning');
            return $wait_time;
        }

        // 3. Check primary rate limit (cached values)
        $rate_limit_remaining = get_option('wp_github_sync_rate_limit_remaining', false);
        $rate_limit_reset = get_option('wp_github_sync_rate_limit_reset', false);
        $threshold = apply_filters('wp_github_sync_rate_limit_threshold', 10); // Requests remaining threshold

        // If cache is empty or remaining is below threshold, fetch fresh data
        if ($rate_limit_remaining === false || $rate_limit_remaining < $threshold) {
            wp_github_sync_log("Rate limit cache low or empty ({$rate_limit_remaining}), fetching fresh data.", 'debug');
            $rate_limit_info = $this->api_client->request('rate_limit'); // Use API client to make request

            if (!is_wp_error($rate_limit_info) && isset($rate_limit_info['resources']['core'])) {
                $rate_limit_remaining = $rate_limit_info['resources']['core']['remaining'];
                $rate_limit_reset = $rate_limit_info['resources']['core']['reset'];

                // Update our cached values
                update_option('wp_github_sync_rate_limit_remaining', $rate_limit_remaining);
                update_option('wp_github_sync_rate_limit_reset', $rate_limit_reset);

                wp_github_sync_log("Fresh rate limit data: {$rate_limit_remaining} remaining, resets at " . date('Y-m-d H:i:s', $rate_limit_reset), 'debug');
            } else {
                // If we can't get fresh data, assume worst case (1 remaining)
                $rate_limit_remaining = 1;
                wp_github_sync_log("Could not fetch rate limit info, assuming 1 remaining.", 'warning');
            }
        }

        // 4. Decide whether to wait based on remaining requests
        if ($rate_limit_remaining !== false && $rate_limit_remaining < $threshold) {
            wp_github_sync_log("Rate limit low: {$rate_limit_remaining} remaining (threshold: {$threshold})", 'warning');

            if ($rate_limit_reset !== false) {
                $now = time();
                $wait_time = max(0, $rate_limit_reset - $now); // Ensure wait time is not negative

                if ($wait_time > 0) {
                    wp_github_sync_log("Rate limit will reset in {$wait_time} seconds. Waiting.", 'warning');
                    // Return a capped wait time to avoid excessively long waits
                    return min($wait_time, 60); // Wait up to 60 seconds
                } else {
                    wp_github_sync_log("Rate limit reset time has passed, but remaining count is low. Proceeding cautiously.", 'warning');
                    return false; // Reset time passed, okay to proceed
                }
            } else {
                // Cannot determine reset time, wait a default amount
                wp_github_sync_log("Cannot determine rate limit reset time, waiting default 60 seconds.", 'warning');
                return 60;
            }
        }

        // Rate limits look okay
        return false;
    }

    /**
     * Set a backoff period for secondary rate limits based on response headers.
     *
     * @param array $response_headers Headers from wp_remote_request response.
     */
    public function handle_secondary_rate_limit(array $response_headers) {
        $retry_after = $response_headers['retry-after'] ?? null;

        if (!empty($retry_after)) {
            $wait_time = (int)$retry_after;
            wp_github_sync_log("Secondary rate limit detected. Retry-After: {$wait_time} seconds", 'warning');
            set_transient('wp_github_sync_retry_after', time() + $wait_time, $wait_time + 5);
        } else {
            // If Retry-After header is missing, use exponential backoff based on attempts
            $attempt = get_option('wp_github_sync_secondary_ratelimit_attempt', 1);
            // Exponential backoff: 2^attempt seconds + jitter (random 0-1s)
            $backoff = pow(2, min($attempt, 6)) + (mt_rand(0, 1000) / 1000);
            $wait_until = time() + $backoff;

            wp_github_sync_log("Setting secondary rate limit backoff: {$backoff} seconds (attempt {$attempt})", 'warning');
            set_transient('wp_github_sync_secondary_ratelimit_backoff', $backoff, ceil($backoff) + 5); // Cache slightly longer than backoff

            // Increment attempt count for next time
            update_option('wp_github_sync_secondary_ratelimit_attempt', $attempt + 1);
        }
    }

    /**
     * Reset secondary rate limit backoff counters after a successful request.
     */
    public function reset_secondary_rate_limit_backoff() {
        if (get_transient('wp_github_sync_secondary_ratelimit_backoff') !== false || get_option('wp_github_sync_secondary_ratelimit_attempt', 1) > 1) {
             wp_github_sync_log("Resetting secondary rate limit backoff.", 'info');
             delete_transient('wp_github_sync_secondary_ratelimit_backoff');
             update_option('wp_github_sync_secondary_ratelimit_attempt', 1);
        }
    }

    /**
     * Update primary rate limit cache based on response headers.
     *
     * @param array $response_headers Headers from wp_remote_request response.
     */
    public function update_rate_limit_cache(array $response_headers) {
        $remaining = $response_headers['x-ratelimit-remaining'] ?? null;
        $reset = $response_headers['x-ratelimit-reset'] ?? null;

        if ($remaining !== null) {
            update_option('wp_github_sync_rate_limit_remaining', (int)$remaining);
            if ($reset !== null) {
                update_option('wp_github_sync_rate_limit_reset', (int)$reset);
            }
            wp_github_sync_log("Updated rate limit cache: {$remaining} remaining.", 'debug');
        }
    }

} // End class RateLimitHandler
