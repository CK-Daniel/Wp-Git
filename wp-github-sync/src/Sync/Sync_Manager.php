<?php
/**
 * Git Sync Manager for the WordPress GitHub Sync plugin.
 * Acts as a coordinator, delegating tasks to specialized handlers.
 *
 * @package WPGitHubSync
 */

namespace WPGitHubSync\Sync;

use WPGitHubSync\API\API_Client;
use WPGitHubSync\API\Repository;
// Use the new helper/orchestrator classes
use WPGitHubSync\Sync\Backup_Manager;
use WPGitHubSync\Sync\File_Sync;
use WPGitHubSync\Sync\DeploymentOrchestrator;
use WPGitHubSync\Sync\WebhookHandler;
use WPGitHubSync\Sync\CronManager;
use WPGitHubSync\Utils\FilesystemHelper; // For filesystem access if needed


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
     * @var API_Client
     */
    private $github_api;

    /**
     * Repository instance.
     * @var Repository
     */
    private $repository;

    /**
     * Backup Manager instance.
     * @var Backup_Manager
     */
    private $backup_manager;

    /**
     * File Sync instance.
     * @var File_Sync
     */
    private $file_sync;

    /**
     * Deployment Orchestrator instance.
     * @var DeploymentOrchestrator
     */
    private $deployment_orchestrator;

    /**
     * Webhook Handler instance.
     * @var WebhookHandler
     */
    private $webhook_handler;

    /**
     * Cron Manager instance.
     * @var CronManager
     */
    private $cron_manager;

    /**
     * WordPress Filesystem instance.
     * @var \WP_Filesystem_Base|null
     */
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

        // Instantiate orchestrator and handlers
        $this->deployment_orchestrator = new DeploymentOrchestrator(
            $github_api, $repository, $backup_manager, $file_sync
        );
        $this->webhook_handler = new WebhookHandler(); // Pass dependencies if needed
        $this->cron_manager = new CronManager($github_api, $this); // Pass self or orchestrator if needed

        // Initialize WP Filesystem (can be moved to FilesystemHelper if preferred)
        $this->initialize_filesystem();
    }

    /**
     * Initialize the WP_Filesystem API.
     */
    private function initialize_filesystem() {
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once ABSPATH . '/wp-admin/includes/file.php';
            WP_Filesystem();
        }
        $this->wp_filesystem = $wp_filesystem;
    }

    /**
     * Register hooks for webhook and cron.
     */
    public function register_hooks() {
        $this->webhook_handler->register_webhook_endpoint();
        $this->cron_manager->register_hooks();
    }

    // Removed register_webhook_endpoint() - Moved to WebhookHandler
    // Removed setup_cron_schedules() - Moved to CronManager
    // Removed add_cron_interval() - Moved to CronManager

    /**
     * Handle activation tasks.
     */
    public function activate() {
        // Create any required DB table or initial options
        if (!get_option('wp_github_sync_webhook_secret')) {
            update_option('wp_github_sync_webhook_secret', wp_github_sync_generate_webhook_secret());
        }

        // Setup cron schedules via CronManager
        $this->cron_manager->setup_cron_schedules();
    }

    /**
     * Handle deactivation tasks.
     */
    public function deactivate() {
        // Clear scheduled cron job via CronManager
        $this->cron_manager->setup_cron_schedules(); // Calling setup checks if enabled and clears if not
    }

    // Removed check_for_updates() - Logic moved to CronManager::check_for_updates

    /**
     * Deploy a specific commit or branch. Delegates to DeploymentOrchestrator.
     *
     * @param string $ref The commit SHA or branch name to deploy.
     * @return bool|\WP_Error True on success or WP_Error on failure.
     */
    public function deploy($ref) {
        return $this->deployment_orchestrator->execute_deployment($ref);
    }

    /**
     * Switch to a different branch. Delegates deployment to DeploymentOrchestrator.
     *
     * @param string $branch The branch name to switch to.
     * @return bool|\WP_Error True on success or WP_Error on failure.
     */
    public function switch_branch($branch) {
        $current_branch = wp_github_sync_get_current_branch();
        wp_github_sync_log("Switch Branch: Attempting to switch from '{$current_branch}' to '{$branch}'", 'info');

        // Update setting first
        update_option('wp_github_sync_branch', $branch);

        // Trigger deployment via orchestrator
        $result = $this->deployment_orchestrator->execute_deployment($branch);

        if (is_wp_error($result)) {
            wp_github_sync_log("Switch Branch: Failed to deploy branch '{$branch}'. Reverting setting to '{$current_branch}'. Error: " . $result->get_error_message(), 'error');
            update_option('wp_github_sync_branch', $current_branch); // Revert setting on failure
            return $result;
        }

        wp_github_sync_log("Switch Branch: Successfully switched to and deployed branch '{$branch}'", 'info');
        return true;
    }

    /**
     * Roll back to a previous commit. Delegates deployment to DeploymentOrchestrator.
     *
     * @param string $commit_sha The commit SHA to roll back to.
     * @return bool|\WP_Error True on success or WP_Error on failure.
     */
    public function rollback($commit_sha) {
        $commit_short = substr($commit_sha, 0, 8);
        wp_github_sync_log("Rollback: Initiating rollback to commit '{$commit_short}'", 'info');

        $current_commit = get_option('wp_github_sync_last_deployed_commit', '');
        if ($current_commit == $commit_sha) {
            wp_github_sync_log("Rollback: Already at commit '{$commit_short}'.", 'info');
            return true;
        }

        // Trigger deployment via orchestrator
        $result = $this->deployment_orchestrator->execute_deployment($commit_sha);

        if (is_wp_error($result)) {
            wp_github_sync_log("Rollback: Failed to rollback to commit '{$commit_short}'. Error: " . $result->get_error_message(), 'error');
            return $result;
        }

        wp_github_sync_log("Rollback: Successfully rolled back to commit '{$commit_short}'", 'info');
        return true;
    }

    // Removed handle_webhook() - Moved to WebhookHandler
    // Removed background_deploy() - Handled by Job_Manager calling DeploymentOrchestrator
    // Removed add_to_deployment_history() - Moved to DeploymentOrchestrator
    // Removed create_backup() - Moved to Backup_Manager
    // Removed restore_from_backup() - Moved to Backup_Manager
    // Removed sync_files() - Moved to File_Sync
    // Removed send_update_notification() - Moved to CronManager (or a dedicated Notifier class)
    // Removed is_deployment_in_progress() - Handled by transient lock check in DeploymentOrchestrator
    // Removed set_deployment_in_progress() - Handled by transient lock in DeploymentOrchestrator
    // Removed schedule_background_deployment() - Moved to WebhookHandler/CronManager/Job_Manager

} // End class Sync_Manager
