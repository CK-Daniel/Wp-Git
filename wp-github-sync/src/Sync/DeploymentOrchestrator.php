<?php
/**
 * Orchestrates the deployment process, coordinating backups, maintenance mode,
 * downloads, and file synchronization.
 *
 * @package WPGitHubSync\Sync
 */

namespace WPGitHubSync\Sync;

use WPGitHubSync\API\API_Client;
use WPGitHubSync\API\Repository;
use WPGitHubSync\Sync\Backup_Manager;
use WPGitHubSync\Sync\File_Sync;
use WPGitHubSync\Utils\FilesystemHelper;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Deployment Orchestrator class.
 */
class DeploymentOrchestrator {

    /** @var API_Client */
    private $github_api;
    /** @var Repository */
    private $repository;
    /** @var Backup_Manager */
    private $backup_manager;
    /** @var File_Sync */
    private $file_sync;
    /** @var \WP_Filesystem_Base|null */
    private $wp_filesystem;

    /**
     * Constructor.
     *
     * @param API_Client     $github_api     The GitHub API client.
     * @param Repository     $repository     The Repository instance.
     * @param Backup_Manager $backup_manager The Backup Manager instance.
     * @param File_Sync      $file_sync      The File Sync instance.
     */
    public function __construct(
        API_Client $github_api,
        Repository $repository,
        Backup_Manager $backup_manager,
        File_Sync $file_sync
    ) {
        $this->github_api = $github_api;
        $this->repository = $repository;
        $this->backup_manager = $backup_manager;
        $this->file_sync = $file_sync;
        $this->wp_filesystem = FilesystemHelper::get_wp_filesystem();
    }

    /**
     * Execute the deployment process for a specific commit or branch.
     *
     * @param string $ref The commit SHA or branch name to deploy.
     * @return bool|\WP_Error True on success or WP_Error on failure.
     */
    public function execute_deployment(string $ref) {
        // Check and set deployment lock using transient
        if (get_transient('wp_github_sync_deployment_lock')) {
            $error_message = __('A deployment is already in progress. Please wait until it completes.', 'wp-github-sync');
            wp_github_sync_log("Deploy Orchestrator: {$error_message}", 'warning');
            return new \WP_Error('deployment_in_progress', $error_message);
        }

        $ref_display = (strlen($ref) === 40) ? substr($ref, 0, 8) : $ref;
        wp_github_sync_log("Deploy Orchestrator: Attempting to start deployment of {$ref_display}", 'info');

        // Set lock with a 15-minute expiry
        if (!set_transient('wp_github_sync_deployment_lock', true, 15 * MINUTE_IN_SECONDS)) {
             wp_github_sync_log("Deploy Orchestrator: FAILED - Could not set deployment lock transient.", 'error');
             // Even if lock fails, proceed but log heavily? Or return error? Let's return error.
             return new \WP_Error('lock_failed', __('Could not set deployment lock. Please try again.', 'wp-github-sync'));
        }
        wp_github_sync_log("Deploy Orchestrator: Deployment lock set for {$ref_display}", 'debug');

        $backup_path = '';
        $maintenance_enabled = false;
        $temp_dir = '';
        $result = null;

        try {
            if (!$this->wp_filesystem) {
                throw new \Exception(__('Could not initialize WordPress filesystem.', 'wp-github-sync'));
            }
            wp_github_sync_log("Deploy Orchestrator: Filesystem initialized for {$ref_display}", 'debug');

            // 1. Create Backup
            $create_backup = get_option('wp_github_sync_create_backup', true);
            if ($create_backup) {
                wp_github_sync_log("Deploy Orchestrator: Creating backup for {$ref_display}...", 'info');
                $backup_path = $this->backup_manager->create_backup();
                if (is_wp_error($backup_path)) throw new \Exception($backup_path->get_error_message());
                wp_github_sync_log("Deploy Orchestrator: Backup created at {$backup_path}", 'info');
            }

            // 2. Enable Maintenance Mode
            $maintenance_mode_option = get_option('wp_github_sync_maintenance_mode', true);
            if ($maintenance_mode_option) {
                wp_github_sync_log("Deploy Orchestrator: Enabling maintenance mode for {$ref_display}...", 'info');
                wp_github_sync_maintenance_mode(true);
                $maintenance_enabled = true;
            }

            // 3. Prepare Temporary Directory
            wp_github_sync_log("Deploy Orchestrator: Preparing temporary directory for {$ref_display}...", 'debug');
            $temp_dir_base = trailingslashit($this->wp_filesystem->wp_content_dir()) . 'upgrade/';
            $temp_dir = $temp_dir_base . 'wp-github-sync-temp-' . wp_generate_password(8, false);
            if ($this->wp_filesystem->exists($temp_dir)) {
                FilesystemHelper::recursive_rmdir($temp_dir); // Clean up previous if exists
            }
            if (!$this->wp_filesystem->mkdir($temp_dir, FS_CHMOD_DIR)) {
                throw new \Exception(__('Failed to create temporary directory.', 'wp-github-sync'));
            }
            wp_github_sync_log("Deploy Orchestrator: Created temporary directory {$temp_dir}", 'debug');


            // 4. Download Repository Archive
            wp_github_sync_log("Deploy Orchestrator: Downloading repository content for {$ref_display}...", 'info');
            $download_result = $this->repository->download_repository($ref, $temp_dir);
            if (is_wp_error($download_result)) {
                 wp_github_sync_log("Deploy Orchestrator: FAILED during download/extract for {$ref_display} - " . $download_result->get_error_message(), 'error');
                 throw new \Exception($download_result->get_error_message());
            }
            wp_github_sync_log("Deploy Orchestrator: Repository downloaded and extracted for {$ref_display}.", 'info');

            // 5. Sync Files
            wp_github_sync_log("Deploy Orchestrator: Syncing files to wp-content for {$ref_display}...", 'info');
            $sync_result = $this->file_sync->sync_files($temp_dir, WP_CONTENT_DIR);
            if (is_wp_error($sync_result)) {
                 wp_github_sync_log("Deploy Orchestrator: FAILED during file sync for {$ref_display} - " . $sync_result->get_error_message(), 'error');
                 throw new \Exception($sync_result->get_error_message());
            }
            wp_github_sync_log("Deploy Orchestrator: File synchronization complete for {$ref_display}.", 'info');

            // 6. Update Deployment Status
            wp_github_sync_log("Deploy Orchestrator: Updating deployment status for {$ref_display}...", 'debug');
            $this->update_deployment_status($ref);
            $result = true; // Mark success

            wp_github_sync_log("Deploy Orchestrator: Deployment of {$ref_display} completed successfully.", 'info');
            do_action('wp_github_sync_after_deploy', $ref, true); // Success hook

        } catch (\Exception $e) {
            $result = new \WP_Error('deployment_failed', $e->getMessage());
            wp_github_sync_log("Deploy Orchestrator: FAILED - " . $e->getMessage(), 'error');
            do_action('wp_github_sync_after_deploy', $ref, false, $result); // Failure hook

            // Attempt Restore if backup exists
            if ($create_backup && !empty($backup_path) && $this->wp_filesystem && $this->wp_filesystem->exists($backup_path)) {
                wp_github_sync_log("Deploy Orchestrator: Attempting restore from backup {$backup_path}...", 'info');
                $restore_result = $this->backup_manager->restore_from_backup($backup_path);
                if (is_wp_error($restore_result)) {
                     wp_github_sync_log("Deploy Orchestrator: Restore from backup FAILED - " . $restore_result->get_error_message(), 'error');
                } else {
                     wp_github_sync_log("Deploy Orchestrator: Restore from backup successful.", 'info');
                }
            }
        } finally {
            // Cleanup: Temp dir, Maintenance mode, Lock
            if (!empty($temp_dir) && $this->wp_filesystem && $this->wp_filesystem->exists($temp_dir)) {
                FilesystemHelper::recursive_rmdir($temp_dir);
                wp_github_sync_log("Deploy Orchestrator: Cleaned up temporary directory {$temp_dir}", 'debug');
            }
            if ($maintenance_enabled) {
                wp_github_sync_maintenance_mode(false);
                wp_github_sync_log("Deploy Orchestrator: Disabled maintenance mode.", 'info');
            }
            delete_transient('wp_github_sync_deployment_lock');
            wp_github_sync_log("Deploy Orchestrator: Deployment lock released.", 'debug');
        }

        return $result;
    }

    /**
     * Update options after a successful deployment.
     *
     * @param string $ref The deployed reference (commit SHA or branch name).
     */
    private function update_deployment_status(string $ref) {
        $commit_sha = $ref;
        // If deploying a branch, get the actual commit SHA
        if (strlen($ref) !== 40) {
            wp_github_sync_log("Deploy Orchestrator: Fetching latest commit SHA for branch '{$ref}'", 'debug');
            $latest_commit = $this->github_api->get_latest_commit($ref);
            if (!is_wp_error($latest_commit) && isset($latest_commit['sha'])) {
                $commit_sha = $latest_commit['sha'];
            } else {
                 wp_github_sync_log("Deploy Orchestrator: Could not fetch commit SHA for branch '{$ref}', using branch name as reference.", 'warning');
                 // Keep $commit_sha as the branch name if lookup fails
            }
        }

        $commit_short_sha = substr($commit_sha, 0, 8);
        update_option('wp_github_sync_last_deployed_commit', $commit_sha);
        update_option('wp_github_sync_last_deployment_time', time()); // Store timestamp
        delete_option('wp_github_sync_update_available'); // Clear update flag
        $this->add_to_deployment_history($ref, $commit_sha); // Add to history log

        wp_github_sync_log("Deploy Orchestrator: Updated last deployed commit to {$commit_short_sha}", 'info');
    }

     /**
     * Add a deployment event to the history log.
     *
     * @param string $ref        The reference deployed (branch or commit).
     * @param string $commit_sha The actual commit SHA deployed.
     */
    private function add_to_deployment_history(string $ref, string $commit_sha) {
        $history = get_option('wp_github_sync_deployment_history', array());

        // Get commit details (best effort)
        $commit_data = ['sha' => $commit_sha]; // Always store the SHA
        $commit_details = $this->github_api->request(
            "repos/{$this->github_api->get_owner()}/{$this->github_api->get_repo()}/commits/{$commit_sha}"
        );
        if (!is_wp_error($commit_details)) {
            $commit_data['message'] = $commit_details['commit']['message'] ?? '';
            $commit_data['author'] = $commit_details['commit']['author']['name'] ?? 'Unknown';
            $commit_data['date'] = $commit_details['commit']['author']['date'] ?? '';
        }

        $history[] = [
            'ref' => $ref, // Store the requested ref (branch or commit)
            'commit' => $commit_data,
            'timestamp' => time(),
            'user' => is_user_logged_in() ? wp_get_current_user()->user_login : 'webhook/cron',
        ];

        // Limit history size
        $history_limit = apply_filters('wp_github_sync_history_limit', 20);
        if (count($history) > $history_limit) {
            $history = array_slice($history, -$history_limit);
        }

        update_option('wp_github_sync_deployment_history', $history);
    }

} // End class DeploymentOrchestrator
