<?php
/**
 * Webhook Handler
 *
 * @package WPGitHubSync
 */

namespace WPGitHubSync\API;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles GitHub webhooks for automatic deployment
 */
class WebhookHandler {
    /**
     * Plugin version
     *
     * @var string
     */
    private $version;

    /**
     * Settings
     *
     * @var array
     */
    private $settings;

    /**
     * Initialize the webhook handler
     *
     * @param string $version Plugin version.
     */
    public function __construct( $version ) {
        $this->version = $version;
        $this->settings = get_option( 'wp_github_sync_settings', [] );
    }

    /**
     * Handle GitHub webhook
     */
    public function handle_webhook() {
        // Check if webhook sync is enabled
        if ( empty( $this->settings['webhook_sync'] ) ) {
            $this->log_message( 'Webhook received but webhook sync is disabled.', 'warning' );
            $this->send_response( 'error', 'Webhook sync is disabled.', 403 );
        }

        // Get the request body
        $request_body = file_get_contents( 'php://input' );
        if ( empty( $request_body ) ) {
            $this->log_message( 'Webhook received but request body is empty.', 'error' );
            $this->send_response( 'error', 'Empty request body.', 400 );
        }

        // Verify signature if webhook secret is set
        if ( ! empty( $this->settings['webhook_secret'] ) ) {
            if ( ! $this->verify_webhook_signature( $request_body, $this->settings['webhook_secret'] ) ) {
                $this->log_message( 'Webhook signature verification failed.', 'error' );
                $this->send_response( 'error', 'Invalid signature.', 403 );
            }
        }

        // Parse the payload
        $payload = json_decode( $request_body, true );
        if ( empty( $payload ) || json_last_error() !== JSON_ERROR_NONE ) {
            $this->log_message( 'Failed to parse webhook payload: ' . json_last_error_msg(), 'error' );
            $this->send_response( 'error', 'Invalid JSON payload.', 400 );
        }

        // Process the webhook
        $result = $this->process_webhook( $payload );

        if ( is_wp_error( $result ) ) {
            $this->log_message( 'Webhook processing failed: ' . $result->get_error_message(), 'error' );
            $this->send_response( 'error', $result->get_error_message(), 500 );
        }

        $this->send_response( 'success', 'Webhook processed successfully.' );
    }

    /**
     * Verify webhook signature
     *
     * @param string $payload The webhook payload.
     * @param string $secret  The webhook secret.
     * @return bool True if signature is valid, false otherwise.
     */
    private function verify_webhook_signature( $payload, $secret ) {
        if ( empty( $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ) ) {
            return false;
        }

        $signature = 'sha256=' . hash_hmac( 'sha256', $payload, $secret );
        return hash_equals( $_SERVER['HTTP_X_HUB_SIGNATURE_256'], $signature );
    }

    /**
     * Process webhook payload
     *
     * @param array $payload Webhook payload.
     * @return true|\WP_Error True on success or WP_Error on failure.
     */
    private function process_webhook( $payload ) {
        // Check event type
        $event = isset( $_SERVER['HTTP_X_GITHUB_EVENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_GITHUB_EVENT'] ) ) : '';
        
        if ( empty( $event ) ) {
            return new \WP_Error( 'missing_event', 'Missing GitHub event header.' );
        }

        $this->log_message( "Received GitHub webhook event: {$event}", 'info' );

        // Process different event types
        switch ( $event ) {
            case 'push':
                return $this->handle_push_event( $payload );
                
            case 'ping':
                return $this->handle_ping_event( $payload );
                
            case 'pull_request':
                return $this->handle_pull_request_event( $payload );
                
            default:
                $this->log_message( "Unhandled webhook event: {$event}", 'warning' );
                return true; // Not an error, just not handled
        }
    }

    /**
     * Handle push event
     *
     * @param array $payload Push event payload.
     * @return true|\WP_Error True on success or WP_Error on failure.
     */
    private function handle_push_event( $payload ) {
        // Check if this is the repository we're configured for
        if ( ! $this->is_configured_repository( $payload ) ) {
            $this->log_message( 'Push event received for a different repository.', 'info' );
            return true; // Not an error, just not our repository
        }

        // Get branch from ref (format: refs/heads/branch-name)
        $ref = isset( $payload['ref'] ) ? $payload['ref'] : '';
        $branch = str_replace( 'refs/heads/', '', $ref );

        if ( empty( $branch ) ) {
            return new \WP_Error( 'missing_branch', 'Missing branch information in push event.' );
        }

        // Check if this branch is configured for auto-deploy
        if ( ! $this->should_auto_deploy( $branch ) ) {
            $this->log_message( "Push to {$branch} received, but not configured for auto-deploy.", 'info' );
            return true; // Not an error, just not configured for auto-deploy
        }

        // Get environment for this branch
        $environment = $this->get_environment_for_branch( $branch );

        // Schedule deployment
        $this->log_message( "Scheduling deployment for push to {$branch} in environment {$environment}.", 'info' );
        
        wp_schedule_single_event( 
            time(), 
            'wp_github_sync_deploy_webhook', 
            [
                'branch' => $branch,
                'commit' => isset( $payload['after'] ) ? $payload['after'] : '',
                'environment' => $environment,
                'payload' => $payload,
            ]
        );

        return true;
    }

    /**
     * Handle ping event
     *
     * @param array $payload Ping event payload.
     * @return true True on success.
     */
    private function handle_ping_event( $payload ) {
        $repo = isset( $payload['repository']['full_name'] ) ? $payload['repository']['full_name'] : 'unknown';
        $hook_id = isset( $payload['hook_id'] ) ? $payload['hook_id'] : 'unknown';
        
        $this->log_message( "Received ping from GitHub for repository {$repo} (Hook ID: {$hook_id}).", 'info' );
        
        return true;
    }

    /**
     * Handle pull request event
     *
     * @param array $payload Pull request event payload.
     * @return true True on success.
     */
    private function handle_pull_request_event( $payload ) {
        // Check if this is the repository we're configured for
        if ( ! $this->is_configured_repository( $payload ) ) {
            return true; // Not an error, just not our repository
        }

        $action = isset( $payload['action'] ) ? $payload['action'] : '';
        $pr_number = isset( $payload['number'] ) ? $payload['number'] : 'unknown';
        
        // Only handle certain PR actions
        if ( ! in_array( $action, [ 'opened', 'reopened', 'synchronize', 'closed' ], true ) ) {
            return true;
        }

        // If PR was merged, handle it specially
        if ( $action === 'closed' && isset( $payload['pull_request']['merged'] ) && $payload['pull_request']['merged'] === true ) {
            $target_branch = isset( $payload['pull_request']['base']['ref'] ) ? $payload['pull_request']['base']['ref'] : '';
            
            $this->log_message( "Pull request #{$pr_number} was merged into {$target_branch}.", 'info' );
            
            // Check if this branch is configured for auto-deploy
            if ( $this->should_auto_deploy( $target_branch ) ) {
                $environment = $this->get_environment_for_branch( $target_branch );
                
                $this->log_message( "Scheduling deployment for merged PR to {$target_branch} in environment {$environment}.", 'info' );
                
                wp_schedule_single_event( 
                    time(), 
                    'wp_github_sync_deploy_webhook', 
                    [
                        'branch' => $target_branch,
                        'commit' => isset( $payload['pull_request']['merge_commit_sha'] ) ? $payload['pull_request']['merge_commit_sha'] : '',
                        'environment' => $environment,
                        'payload' => $payload,
                    ]
                );
            }
        } else {
            $this->log_message( "Pull request #{$pr_number} {$action}.", 'info' );
        }

        return true;
    }

    /**
     * Check if the webhook is for the configured repository
     *
     * @param array $payload Webhook payload.
     * @return bool True if this is the configured repository, false otherwise.
     */
    private function is_configured_repository( $payload ) {
        if ( empty( $this->settings['repo_url'] ) ) {
            return false;
        }

        // Extract repository details from settings
        $repo_parts = $this->parse_repo_url( $this->settings['repo_url'] );
        $configured_repo = $repo_parts['owner'] . '/' . $repo_parts['repo'];

        // Get repository from payload
        $repo = '';
        if ( isset( $payload['repository']['full_name'] ) ) {
            $repo = $payload['repository']['full_name'];
        } elseif ( isset( $payload['pull_request']['base']['repo']['full_name'] ) ) {
            $repo = $payload['pull_request']['base']['repo']['full_name'];
        }

        return $repo === $configured_repo;
    }

    /**
     * Parse repository URL
     *
     * @param string $url Repository URL.
     * @return array Array with owner and repo keys.
     */
    private function parse_repo_url( $url ) {
        $result = [
            'owner' => '',
            'repo'  => '',
        ];
        
        // Remove trailing slash
        $url = rtrim( $url, '/' );
        
        // Extract from GitHub URL format
        if ( preg_match( '#^https?://github\.com/([^/]+)/([^/]+)/?$#', $url, $matches ) ) {
            $result['owner'] = $matches[1];
            $result['repo'] = $matches[2];
            
            // Remove .git suffix if exists
            $result['repo'] = preg_replace( '/\.git$/', '', $result['repo'] );
        }
        
        return $result;
    }

    /**
     * Check if a branch should be auto-deployed
     *
     * @param string $branch Branch name.
     * @return bool True if the branch should be auto-deployed, false otherwise.
     */
    private function should_auto_deploy( $branch ) {
        // Check environments
        $environments = get_option( 'wp_github_sync_environments', [] );
        
        foreach ( $environments as $env_key => $env_data ) {
            if ( ! empty( $env_data['enabled'] ) && 
                 ! empty( $env_data['branch'] ) && 
                 $env_data['branch'] === $branch &&
                 ! empty( $env_data['auto_deploy'] ) ) {
                return true;
            }
        }

        // Check if branch matches sync branch setting
        if ( ! empty( $this->settings['sync_branch'] ) && 
             $branch === $this->settings['sync_branch'] && 
             ! empty( $this->settings['auto_sync'] ) ) {
            return true;
        }

        return false;
    }

    /**
     * Get environment name for a branch
     *
     * @param string $branch Branch name.
     * @return string Environment name.
     */
    private function get_environment_for_branch( $branch ) {
        // Check environments
        $environments = get_option( 'wp_github_sync_environments', [] );
        
        foreach ( $environments as $env_key => $env_data ) {
            if ( ! empty( $env_data['enabled'] ) && 
                 ! empty( $env_data['branch'] ) && 
                 $env_data['branch'] === $branch ) {
                return $env_key;
            }
        }

        // Default to production if no environment is configured for this branch
        return 'production';
    }

    /**
     * Send response to webhook request
     *
     * @param string $status  Response status (success or error).
     * @param string $message Response message.
     * @param int    $code    HTTP status code.
     */
    private function send_response( $status, $message, $code = 200 ) {
        status_header( $code );
        header( 'Content-Type: application/json' );
        echo wp_json_encode( [
            'status'  => $status,
            'message' => $message,
        ] );
        exit;
    }

    /**
     * Log a message to the plugin's log
     *
     * @param string $message Message to log.
     * @param string $level   Log level (debug, info, warning, error).
     */
    private function log_message( $message, $level = 'info' ) {
        if ( function_exists( 'wp_github_sync_log' ) ) {
            wp_github_sync_log( '[Webhook] ' . $message, $level );
        }
    }
}