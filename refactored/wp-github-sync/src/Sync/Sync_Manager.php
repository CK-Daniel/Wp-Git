<?php
/**
 * Git Sync Manager for the WordPress GitHub Sync plugin.
 *
 * @package WPGitHubSync
 */

namespace WPGitHubSync\Sync;

use WPGitHubSync\API\API_Client;
use WPGitHubSync\API\Repository;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Git Sync Manager class.
 */
class Sync_Manager {

    /**
     * GitHub API Client instance.
     *
     * @var API_Client
     */
    private $github_api;

    /**
     * Repository instance.
     *
     * @var Repository
     */
    private $repository;

    /**
     * The time a deployment was last initiated.
     *
     * @var int
     */
    private $last_deployment_time;

    /**
     * Constructor.
     *
     * @param API_Client $github_api The GitHub API client.
     */
    public function __construct($github_api) {
        $this->github_api = $github_api;
        $this->repository = new Repository($github_api);
        $this->last_deployment_time = get_option('wp_github_sync_last_deployment_time', 0);
    }

    /**
     * Register the webhook endpoint for GitHub.
     */
    public function register_webhook_endpoint() {
        register_rest_route('wp-github-sync/v1', '/webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_webhook'),
            'permission_callback' => '__return_true', // Public endpoint, we'll verify signature internally
        ));
    }

    /**
     * Setup cron schedules for automatic checking.
     */
    public function setup_cron_schedules() {
        $auto_sync_enabled = get_option('wp_github_sync_auto_sync', false);
        $auto_sync_interval = get_option('wp_github_sync_auto_sync_interval', 5);
        
        // Only setup if auto-sync is enabled
        if ($auto_sync_enabled) {
            if (!wp_next_scheduled('wp_github_sync_cron_hook')) {
                wp_schedule_event(time(), 'wp_github_sync_interval', 'wp_github_sync_cron_hook');
            }
            
            // Register custom interval
            add_filter('cron_schedules', array($this, 'add_cron_interval'));
        } else {
            // Clear the schedule if it exists but auto-sync is now disabled
            if (wp_next_scheduled('wp_github_sync_cron_hook')) {
                wp_clear_scheduled_hook('wp_github_sync_cron_hook');
            }
        }
    }

    /**
     * Add custom cron interval.
     *
     * @param array $schedules Existing cron schedules.
     * @return array Modified cron schedules.
     */
    public function add_cron_interval($schedules) {
        $interval = get_option('wp_github_sync_auto_sync_interval', 5);
        
        $schedules['wp_github_sync_interval'] = array(
            'interval' => $interval * MINUTE_IN_SECONDS,
            'display' => sprintf(__('Every %d minutes', 'wp-github-sync'), $interval),
        );
        
        return $schedules;
    }

    /**
     * Handle activation tasks.
     */
    public function activate() {
        // Create any required DB table or initial options
        if (!get_option('wp_github_sync_webhook_secret')) {
            update_option('wp_github_sync_webhook_secret', wp_github_sync_generate_webhook_secret());
        }
        
        // Setup cron schedules
        $this->setup_cron_schedules();
    }

    /**
     * Handle deactivation tasks.
     */
    public function deactivate() {
        // Clear scheduled cron job
        wp_clear_scheduled_hook('wp_github_sync_cron_hook');
    }

    /**
     * Check for updates from GitHub.
     */
    public function check_for_updates() {
        // Don't check if we're in the middle of a deployment
        if ($this->is_deployment_in_progress()) {
            wp_github_sync_log('Skipping update check because a deployment is in progress', 'info');
            return;
        }
        
        wp_github_sync_log('Checking for updates from GitHub', 'info');
        
        // Get the current branch
        $branch = wp_github_sync_get_current_branch();
        
        // Get the latest commit on this branch
        $latest_commit = $this->github_api->get_latest_commit($branch);
        
        if (is_wp_error($latest_commit)) {
            wp_github_sync_log('Error checking for updates: ' . $latest_commit->get_error_message(), 'error');
            return;
        }
        
        // Get the last deployed commit hash
        $last_deployed_commit = get_option('wp_github_sync_last_deployed_commit', '');
        
        // If the latest commit hash is different from the last deployed commit
        if (isset($latest_commit['sha']) && $latest_commit['sha'] !== $last_deployed_commit) {
            wp_github_sync_log(
                sprintf(
                    'New commit found: %s (Last deployed: %s)',
                    $latest_commit['sha'],
                    $last_deployed_commit ?: 'none'
                ),
                'info'
            );
            
            // Update the latest commit in options for UI display
            update_option('wp_github_sync_latest_commit', array(
                'sha' => $latest_commit['sha'],
                'message' => isset($latest_commit['commit']['message']) ? $latest_commit['commit']['message'] : '',
                'author' => isset($latest_commit['commit']['author']['name']) ? $latest_commit['commit']['author']['name'] : '',
                'date' => isset($latest_commit['commit']['author']['date']) ? $latest_commit['commit']['author']['date'] : '',
                'timestamp' => time(),
            ));
            
            // Check if auto-deploy is enabled
            $auto_deploy = get_option('wp_github_sync_auto_deploy', false);
            
            if ($auto_deploy) {
                // If so, deploy the new commit
                $this->deploy($latest_commit['sha']);
            } else {
                // Otherwise, set a flag to show there's an update pending
                update_option('wp_github_sync_update_available', true);
                
                // Send notification if enabled
                $notify_updates = get_option('wp_github_sync_notify_updates', false);
                if ($notify_updates) {
                    $this->send_update_notification($latest_commit);
                }
            }
        } else {
            wp_github_sync_log('No new commits found', 'info');
        }
    }

    /**
     * Deploy a specific commit or branch.
     *
     * @param string $ref The commit SHA or branch name to deploy.
     * @return bool|\WP_Error True on success or WP_Error on failure.
     */
    public function deploy($ref) {
        // Check if we're in the middle of a deployment
        if ($this->is_deployment_in_progress()) {
            return new \WP_Error(
                'deployment_in_progress',
                __('A deployment is already in progress. Please wait until it completes.', 'wp-github-sync')
            );
        }
        
        wp_github_sync_log("Starting deployment of {$ref}", 'info');
        
        // Set deployment in progress flag
        $this->set_deployment_in_progress(true);
        $this->last_deployment_time = time();
        update_option('wp_github_sync_last_deployment_time', $this->last_deployment_time);
        
        // Create backup if configured
        $create_backup = get_option('wp_github_sync_create_backup', true);
        $backup_path = '';
        
        if ($create_backup) {
            $backup_path = $this->create_backup();
            if (is_wp_error($backup_path)) {
                $this->set_deployment_in_progress(false);
                return $backup_path;
            }
        }
        
        // Enable maintenance mode if configured
        $maintenance_mode = get_option('wp_github_sync_maintenance_mode', true);
        if ($maintenance_mode) {
            wp_github_sync_maintenance_mode(true);
        }
        
        // Download and extract repository
        $temp_dir = WP_CONTENT_DIR . '/upgrade/wp-github-sync-temp';
        
        // Clean up temp directory if it exists
        if (file_exists($temp_dir)) {
            $this->recursive_rmdir($temp_dir);
        }
        
        // Create temp directory
        wp_mkdir_p($temp_dir);
        
        // Download repository to temp directory
        $download_result = $this->repository->download_repository($ref, $temp_dir);
        
        if (is_wp_error($download_result)) {
            wp_github_sync_log('Download failed: ' . $download_result->get_error_message(), 'error');
            
            // Restore from backup if we created one
            if ($create_backup && $backup_path && file_exists($backup_path)) {
                $this->restore_from_backup($backup_path);
            }
            
            // Disable maintenance mode
            if ($maintenance_mode) {
                wp_github_sync_maintenance_mode(false);
            }
            
            $this->set_deployment_in_progress(false);
            return $download_result;
        }
        
        // Sync files from temp directory to wp-content
        $sync_result = $this->sync_files($temp_dir, WP_CONTENT_DIR);
        
        // Clean up temp directory
        $this->recursive_rmdir($temp_dir);
        
        // Disable maintenance mode
        if ($maintenance_mode) {
            wp_github_sync_maintenance_mode(false);
        }
        
        if (is_wp_error($sync_result)) {
            wp_github_sync_log('Sync failed: ' . $sync_result->get_error_message(), 'error');
            
            // Restore from backup if we created one
            if ($create_backup && $backup_path && file_exists($backup_path)) {
                $this->restore_from_backup($backup_path);
            }
            
            $this->set_deployment_in_progress(false);
            return $sync_result;
        }
        
        // Update last deployed commit
        if (strlen($ref) === 40) {
            // If $ref is a commit SHA (40 chars), store it directly
            update_option('wp_github_sync_last_deployed_commit', $ref);
        } else {
            // If $ref is a branch, store the latest commit SHA for that branch
            $latest_commit = $this->github_api->get_latest_commit($ref);
            if (!is_wp_error($latest_commit) && isset($latest_commit['sha'])) {
                update_option('wp_github_sync_last_deployed_commit', $latest_commit['sha']);
            }
        }
        
        // Clear update available flag
        delete_option('wp_github_sync_update_available');
        
        // Add to deployment history
        $this->add_to_deployment_history($ref);
        
        // Set deployment completed
        $this->set_deployment_in_progress(false);
        
        wp_github_sync_log("Deployment of {$ref} completed successfully", 'info');
        
        // Action hook for after successful deployment
        do_action('wp_github_sync_after_deploy', $ref, true);
        
        return true;
    }

    /**
     * Switch to a different branch.
     *
     * @param string $branch The branch name to switch to.
     * @return bool|\WP_Error True on success or WP_Error on failure.
     */
    public function switch_branch($branch) {
        // Save current branch for rollback if needed
        $current_branch = wp_github_sync_get_current_branch();
        
        // Update the branch setting
        update_option('wp_github_sync_branch', $branch);
        
        // Deploy the branch
        $result = $this->deploy($branch);
        
        if (is_wp_error($result)) {
            // Switch back to the previous branch in settings
            update_option('wp_github_sync_branch', $current_branch);
            return $result;
        }
        
        return true;
    }

    /**
     * Roll back to a previous commit.
     *
     * @param string $commit_sha The commit SHA to roll back to.
     * @return bool|\WP_Error True on success or WP_Error on failure.
     */
    public function rollback($commit_sha) {
        // Deploy the specific commit
        return $this->deploy($commit_sha);
    }

    /**
     * Handle GitHub webhook.
     *
     * @param \WP_REST_Request $request The request object.
     * @return \WP_REST_Response The response object.
     */
    public function handle_webhook($request) {
        // Get the raw payload
        $payload = $request->get_body();
        
        // Get the signature header
        $signature = $request->get_header('x-hub-signature-256');
        if (empty($signature)) {
            $signature = $request->get_header('x-hub-signature');
        }
        
        // Get the webhook secret from options
        $secret = get_option('wp_github_sync_webhook_secret', '');
        
        // Verify the signature
        if (!wp_github_sync_verify_webhook_signature($payload, $signature, $secret)) {
            wp_github_sync_log('Webhook signature verification failed', 'error');
            return new \WP_REST_Response(
                array('message' => 'Signature verification failed'),
                401
            );
        }
        
        // Decode the payload
        $data = json_decode($payload, true);
        
        // Check if this is a push event
        if (!isset($data['ref'])) {
            return new \WP_REST_Response(
                array('message' => 'Not a push event'),
                200
            );
        }
        
        // Extract branch name from ref (refs/heads/main -> main)
        $branch = preg_replace('#^refs/heads/#', '', $data['ref']);
        
        // Check if this is the branch we're tracking
        $tracked_branch = wp_github_sync_get_current_branch();
        
        if ($branch !== $tracked_branch) {
            wp_github_sync_log("Webhook received for branch {$branch}, but we're tracking {$tracked_branch}", 'info');
            return new \WP_REST_Response(
                array('message' => "Branch {$branch} does not match tracked branch {$tracked_branch}"),
                200
            );
        }
        
        // Check if webhook deployments are enabled
        $webhook_deploy = get_option('wp_github_sync_webhook_deploy', true);
        
        if (!$webhook_deploy) {
            wp_github_sync_log('Webhook deployment is disabled. Updating available update status only.', 'info');
            
            // Just mark that an update is available
            if (isset($data['after'])) {
                update_option('wp_github_sync_latest_commit', array(
                    'sha' => $data['after'],
                    'message' => isset($data['head_commit']['message']) ? $data['head_commit']['message'] : '',
                    'author' => isset($data['head_commit']['author']['name']) ? $data['head_commit']['author']['name'] : '',
                    'date' => isset($data['head_commit']['timestamp']) ? $data['head_commit']['timestamp'] : '',
                    'timestamp' => time(),
                ));
                update_option('wp_github_sync_update_available', true);
            }
            
            return new \WP_REST_Response(
                array('message' => 'Update marked as available'),
                200
            );
        }
        
        // Queue deployment in the background
        $this->schedule_background_deployment($branch);
        
        return new \WP_REST_Response(
            array('message' => 'Deployment queued'),
            200
        );
    }

    /**
     * Background deployment handler.
     *
     * @param string $ref The reference (branch or commit) to deploy.
     */
    public function background_deploy($ref) {
        wp_github_sync_log("Starting background deployment of {$ref}", 'info');
        $this->deploy($ref);
    }

    /**
     * Add a deployment to history.
     *
     * @param string $ref The reference (commit or branch) that was deployed.
     */
    private function add_to_deployment_history($ref) {
        $history = get_option('wp_github_sync_deployment_history', array());
        
        // Get commit details
        $commit_data = array();
        
        if (strlen($ref) === 40) {
            // If $ref is a commit SHA, get details for that commit
            $commit_details = $this->github_api->request(
                "repos/{$this->github_api->get_owner()}/{$this->github_api->get_repo()}/commits/{$ref}"
            );
            if (!is_wp_error($commit_details)) {
                $commit_data = array(
                    'sha' => $ref,
                    'message' => isset($commit_details['commit']['message']) ? $commit_details['commit']['message'] : '',
                    'author' => isset($commit_details['commit']['author']['name']) ? $commit_details['commit']['author']['name'] : '',
                    'date' => isset($commit_details['commit']['author']['date']) ? $commit_details['commit']['author']['date'] : '',
                );
            }
        } else {
            // If $ref is a branch, get the latest commit for that branch
            $latest_commit = $this->github_api->get_latest_commit($ref);
            if (!is_wp_error($latest_commit)) {
                $commit_data = array(
                    'sha' => $latest_commit['sha'],
                    'message' => isset($latest_commit['commit']['message']) ? $latest_commit['commit']['message'] : '',
                    'author' => isset($latest_commit['commit']['author']['name']) ? $latest_commit['commit']['author']['name'] : '',
                    'date' => isset($latest_commit['commit']['author']['date']) ? $latest_commit['commit']['author']['date'] : '',
                );
            }
        }
        
        // Add deployment to history
        $history[] = array(
            'ref' => $ref,
            'commit' => $commit_data,
            'timestamp' => time(),
            'user' => wp_get_current_user()->user_login,
        );
        
        // Limit history to 20 items
        if (count($history) > 20) {
            $history = array_slice($history, -20);
        }
        
        update_option('wp_github_sync_deployment_history', $history);
    }

    /**
     * Create a backup of the current state.
     *
     * @return string|\WP_Error The backup path or WP_Error on failure.
     */
    private function create_backup() {
        $backup_manager = new Backup_Manager();
        return $backup_manager->create_backup();
    }

    /**
     * Restore from a backup.
     *
     * @param string $backup_path The path to the backup directory.
     * @return bool|\WP_Error True on success or WP_Error on failure.
     */
    private function restore_from_backup($backup_path) {
        $backup_manager = new Backup_Manager();
        return $backup_manager->restore_from_backup($backup_path);
    }

    /**
     * Sync files from source to target directory.
     *
     * @param string $source_dir The source directory.
     * @param string $target_dir The target directory.
     * @return bool|\WP_Error True on success or WP_Error on failure.
     */
    private function sync_files($source_dir, $target_dir) {
        $file_sync = new File_Sync();
        return $file_sync->sync_files($source_dir, $target_dir);
    }

    /**
     * Remove a directory recursively.
     *
     * @param string $dir The directory to remove.
     * @return bool True on success, false on failure.
     */
    private function recursive_rmdir($dir) {
        if (!is_dir($dir)) {
            return false;
        }
        
        $objects = scandir($dir);
        
        foreach ($objects as $object) {
            if ($object === '.' || $object === '..') {
                continue;
            }
            
            $path = $dir . '/' . $object;
            
            if (is_dir($path)) {
                $this->recursive_rmdir($path);
            } else {
                unlink($path);
            }
        }
        
        return rmdir($dir);
    }

    /**
     * Send an email notification about available updates.
     *
     * @param array $commit The commit data.
     */
    private function send_update_notification($commit) {
        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');
        $commit_message = isset($commit['commit']['message']) ? $commit['commit']['message'] : '';
        $commit_author = isset($commit['commit']['author']['name']) ? $commit['commit']['author']['name'] : '';
        $commit_date = isset($commit['commit']['author']['date']) ? $commit['commit']['author']['date'] : '';
        
        $subject = sprintf(__('[%s] New GitHub update available', 'wp-github-sync'), $site_name);
        
        $message = sprintf(
            __("Hello,\n\nA new update is available for your WordPress site from GitHub.\n\nCommit: %s\nAuthor: %s\nDate: %s\nMessage: %s\n\nYou can deploy this update from the WordPress admin area under 'GitHub Sync'.\n\nRegards,\nWordPress GitHub Sync", 'wp-github-sync'),
            substr($commit['sha'], 0, 8),
            $commit_author,
            $commit_date,
            $commit_message
        );
        
        wp_mail($admin_email, $subject, $message);
    }

    /**
     * Check if a deployment is currently in progress.
     *
     * @return bool True if a deployment is in progress, false otherwise.
     */
    private function is_deployment_in_progress() {
        $in_progress = get_option('wp_github_sync_deployment_in_progress', false);
        
        // If it's been more than 10 minutes since the last deployment started,
        // assume it failed and allow a new one
        if ($in_progress && (time() - $this->last_deployment_time) > 600) {
            $this->set_deployment_in_progress(false);
            return false;
        }
        
        return $in_progress;
    }

    /**
     * Set the deployment in progress flag.
     *
     * @param bool $in_progress Whether a deployment is in progress.
     */
    private function set_deployment_in_progress($in_progress) {
        update_option('wp_github_sync_deployment_in_progress', $in_progress);
    }

    /**
     * Schedule a background deployment using WordPress cron.
     *
     * @param string $ref The reference (branch or commit) to deploy.
     */
    private function schedule_background_deployment($ref) {
        if (!wp_next_scheduled('wp_github_sync_background_deploy', array($ref))) {
            wp_schedule_single_event(time(), 'wp_github_sync_background_deploy', array($ref));
            wp_github_sync_log("Background deployment scheduled for {$ref}", 'info');
        }
    }
}