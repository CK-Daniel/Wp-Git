<?php
/**
 * Handles GitHub Webhook requests for the WordPress GitHub Sync plugin.
 *
 * @package WPGitHubSync\Sync
 */

namespace WPGitHubSync\Sync;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Webhook Handler class.
 */
class WebhookHandler {

    /**
     * Constructor.
     */
    public function __construct() {
        // Dependencies can be injected here if needed (e.g., Job_Manager or Sync_Manager)
    }

    /**
     * Register the webhook endpoint for GitHub.
     */
    public function register_webhook_endpoint() {
        register_rest_route('wp-github-sync/v1', '/webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_webhook'),
            'permission_callback' => '__return_true', // Public endpoint, verify signature internally
        ));
    }

    /**
     * Handle GitHub webhook request.
     *
     * @param \WP_REST_Request $request The request object.
     * @return \WP_REST_Response The response object.
     */
    public function handle_webhook(\WP_REST_Request $request) {
        // Check request method (already handled by register_rest_route, but good practice)
        if ($request->get_method() !== 'POST') {
            wp_github_sync_log('Webhook received non-POST request', 'error');
            return new \WP_REST_Response(['message' => 'Method not allowed'], 405);
        }

        // Verify User-Agent
        $user_agent = $request->get_header('user-agent');
        if (empty($user_agent) || strpos($user_agent, 'GitHub-Hookshot/') !== 0) {
            wp_github_sync_log('Webhook received invalid User-Agent: ' . esc_html($user_agent), 'error');
            return new \WP_REST_Response(['message' => 'Invalid User-Agent'], 403);
        }

        // Get the event type
        $event_type = $request->get_header('x-github-event');
        if (empty($event_type)) {
            wp_github_sync_log('Webhook missing X-GitHub-Event header', 'error');
            return new \WP_REST_Response(['message' => 'Missing event type'], 400);
        }

        // Get the raw payload
        $payload = $request->get_body();
        if (empty($payload)) {
            wp_github_sync_log('Webhook received empty payload', 'error');
            return new \WP_REST_Response(['message' => 'Empty payload'], 400);
        }

        // Get the signature header
        $signature = $request->get_header('x-hub-signature-256');
        if (empty($signature)) {
            $signature = $request->get_header('x-hub-signature'); // Fallback for older SHA1 signature
            if (empty($signature)) {
                wp_github_sync_log('Webhook missing signature header', 'error');
                return new \WP_REST_Response(['message' => 'Missing signature'], 401);
            }
        }

        // Get the webhook secret from options
        $secret = get_option('wp_github_sync_webhook_secret', '');
        if (empty($secret)) {
            wp_github_sync_log('Webhook secret not configured', 'error');
            return new \WP_REST_Response(['message' => 'Webhook not configured'], 500);
        }

        // Verify the signature
        if (!wp_github_sync_verify_webhook_signature($payload, $signature, $secret)) {
            wp_github_sync_log('Webhook signature verification failed', 'error');
            return new \WP_REST_Response(['message' => 'Signature verification failed'], 401);
        }

        wp_github_sync_log('Webhook signature verified successfully', 'info');

        // Decode the payload
        $data = json_decode($payload, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
             wp_github_sync_log('Webhook payload JSON decode error: ' . json_last_error_msg(), 'error');
             return new \WP_REST_Response(['message' => 'Invalid JSON payload'], 400);
        }

        // Process only 'push' events for now
        if ($event_type !== 'push') {
            wp_github_sync_log("Webhook received non-push event: {$event_type}", 'info');
            return new \WP_REST_Response(['message' => "Event type '{$event_type}' ignored"], 200);
        }

        if (!isset($data['ref'])) {
            wp_github_sync_log('Webhook push event missing ref data', 'warning');
            return new \WP_REST_Response(['message' => 'Push event missing ref data'], 400);
        }

        // Extract branch name from ref (e.g., refs/heads/main -> main)
        $branch = preg_replace('#^refs/heads/#', '', $data['ref']);

        // Check if this is the branch we're tracking
        $tracked_branch = wp_github_sync_get_current_branch();
        if ($branch !== $tracked_branch) {
            wp_github_sync_log("Webhook received for branch '{$branch}', but tracking '{$tracked_branch}'. Ignoring.", 'info');
            return new \WP_REST_Response(['message' => "Branch '{$branch}' does not match tracked branch '{$tracked_branch}'"], 200);
        }

        // Check if webhook deployments are enabled
        $webhook_deploy_enabled = get_option('wp_github_sync_webhook_deploy', true);

        if (!$webhook_deploy_enabled) {
            wp_github_sync_log('Webhook deployment is disabled. Marking update available only.', 'info');
            // Mark update as available if commit SHA exists
            if (isset($data['after']) && $data['after'] !== '0000000000000000000000000000000000000000') { // Check for non-zero commit
                update_option('wp_github_sync_latest_commit', [
                    'sha' => $data['after'],
                    'message' => isset($data['head_commit']['message']) ? $data['head_commit']['message'] : '',
                    'author' => isset($data['head_commit']['author']['name']) ? $data['head_commit']['author']['name'] : '',
                    'date' => isset($data['head_commit']['timestamp']) ? $data['head_commit']['timestamp'] : '',
                    'timestamp' => time(),
                ]);
                update_option('wp_github_sync_update_available', true);
            }
            return new \WP_REST_Response(['message' => 'Webhook deployment disabled, update marked'], 200);
        }

        // Check for existing deployment lock
        if (get_transient('wp_github_sync_deployment_lock')) {
            wp_github_sync_log('Webhook received, but deployment already in progress. Ignoring.', 'warning');
            return new \WP_REST_Response(['message' => 'Deployment already in progress'], 429); // 429 Too Many Requests
        }

        // Schedule deployment in the background
        $this->schedule_background_deployment($branch);

        return new \WP_REST_Response(['message' => 'Deployment queued'], 202); // 202 Accepted
    }

    /**
     * Schedule a background deployment using Action Scheduler or WP Cron.
     *
     * @param string $ref The reference (branch name) to deploy.
     */
    private function schedule_background_deployment(string $ref) {
        $params = ['branch' => $ref];
        $hook = 'wp_github_sync_run_background_deploy'; // Hook handled by Job_Manager

        // Use Action Scheduler if available
        if (function_exists('as_schedule_single_action')) {
            // Check if an identical job is already scheduled
            $actions = as_get_scheduled_actions([
                'hook' => $hook,
                'args' => ['params' => $params],
                'status' => \ActionScheduler_Store::STATUS_PENDING,
            ], 'ids');

            if (empty($actions)) {
                as_schedule_single_action(time() + 5, $hook, ['params' => $params], 'wp-github-sync'); // 5 sec delay
                wp_github_sync_log("Background deployment scheduled via Action Scheduler for branch '{$ref}'", 'info');
            } else {
                wp_github_sync_log("Identical background deployment already scheduled for branch '{$ref}'. Skipping.", 'info');
            }
        } else {
            // Fallback to WP Cron
            if (!wp_next_scheduled($hook, [$params])) {
                wp_schedule_single_event(time() + 5, $hook, [$params]);
                wp_github_sync_log("Background deployment scheduled via WP Cron for branch '{$ref}'", 'warning');
            } else {
                 wp_github_sync_log("Identical background deployment already scheduled via WP Cron for branch '{$ref}'. Skipping.", 'info');
            }
        }
    }

} // End class WebhookHandler
