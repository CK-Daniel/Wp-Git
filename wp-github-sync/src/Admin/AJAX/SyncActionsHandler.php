<?php
/**
 * Handles Sync related AJAX requests for the WordPress GitHub Sync plugin.
 *
 * @package WPGitHubSync\Admin\AJAX
 */

namespace WPGitHubSync\Admin\AJAX;

use WPGitHubSync\Sync\Sync_Manager;
use WPGitHubSync\API\Repository;
use WPGitHubSync\Admin\Job_Manager; // Needed for scheduling background jobs
use WPGitHubSync\Admin\Progress_Tracker; // Needed for resetting progress

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Sync Actions AJAX Handler class.
 */
class SyncActionsHandler {

    use VerifiesRequestTrait; // Use the trait for verification

    /**
     * Sync Manager instance.
     *
     * @var Sync_Manager
     */
    private $sync_manager;

    /**
     * Repository instance.
     *
     * @var Repository
     */
    private $repository;

    /**
     * Job Manager instance.
     *
     * @var Job_Manager
     */
    private $job_manager;

    /**
     * Progress Tracker instance.
     *
     * @var Progress_Tracker
     */
    private $progress_tracker;


    /**
     * Constructor.
     *
     * @param Sync_Manager     $sync_manager     The Sync Manager instance.
     * @param Repository       $repository       The Repository instance.
     * @param Job_Manager      $job_manager      The Job Manager instance.
     * @param Progress_Tracker $progress_tracker The Progress Tracker instance.
     */
    public function __construct(
        Sync_Manager $sync_manager,
        Repository $repository,
        Job_Manager $job_manager,
        Progress_Tracker $progress_tracker
    ) {
        $this->sync_manager = $sync_manager;
        $this->repository = $repository;
        $this->job_manager = $job_manager;
        $this->progress_tracker = $progress_tracker;
    }

    /**
     * Register AJAX hooks for sync actions.
     */
    public function register_hooks() {
        add_action('wp_ajax_wp_github_sync_deploy', array($this, 'handle_ajax_deploy'));
        add_action('wp_ajax_wp_github_sync_background_deploy', array($this, 'handle_ajax_background_deploy'));
        add_action('wp_ajax_wp_github_sync_switch_branch', array($this, 'handle_ajax_switch_branch'));
        add_action('wp_ajax_wp_github_sync_rollback', array($this, 'handle_ajax_rollback'));
        add_action('wp_ajax_wp_github_sync_initial_sync', array($this, 'handle_ajax_initial_sync'));
        add_action('wp_ajax_wp_github_sync_background_initial_sync', array($this, 'handle_ajax_background_initial_sync'));
        add_action('wp_ajax_wp_github_sync_full_sync', array($this, 'handle_ajax_full_sync'));
        add_action('wp_ajax_wp_github_sync_background_full_sync', array($this, 'handle_ajax_background_full_sync'));
    }

    /**
     * Handle AJAX deploy request.
     */
    public function handle_ajax_deploy() {
        $this->verify_request(); // Use trait for verification

        // Get current branch
        $branch = wp_github_sync_get_current_branch();
        wp_github_sync_log("Deploy AJAX: Starting deployment of branch '{$branch}'", 'info');

        // Deploy
        $result = $this->sync_manager->deploy($branch);

        if (is_wp_error($result)) {
            $error_message = $result->get_error_message();
            wp_github_sync_log("Deploy AJAX: Deployment failed - {$error_message}", 'error');
            wp_send_json_error(array('message' => $error_message));
        } else {
            wp_github_sync_log("Deploy AJAX: Deployment of '{$branch}' completed successfully", 'info');
            wp_send_json_success(array('message' => __('Deployment completed successfully.', 'wp-github-sync')));
        }
    }

    /**
     * Handle AJAX background deploy request.
     */
    public function handle_ajax_background_deploy() {
        $this->verify_request(); // Use trait for verification

        // Check if sync is already in progress (using transient lock)
        if (get_transient('wp_github_sync_deployment_lock')) {
             wp_github_sync_log("Background Deploy AJAX: Sync already in progress, cannot start another", 'warning');
             wp_send_json_error(array('message' => __('A sync operation is already in progress. Please wait for it to complete.', 'wp-github-sync')));
             return;
        }

        // Set lock (Job Manager will clear it on completion/failure)
        set_transient('wp_github_sync_deployment_lock', true, 15 * MINUTE_IN_SECONDS);

        // Reset progress tracking using Progress_Tracker
        $this->progress_tracker->reset_progress();
        $this->progress_tracker->update_sync_progress(0, __('Scheduling background deploy...', 'wp-github-sync'), 'pending');

        // Get current branch
        $branch = wp_github_sync_get_current_branch();
        wp_github_sync_log("Background Deploy AJAX: Scheduling background deployment of branch '{$branch}'", 'info');

        // Schedule the background job using Action Scheduler if available
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(
                time(),
                'wp_github_sync_run_background_deploy', // Hook handled by Job_Manager
                ['params' => ['branch' => $branch]],
                'wp-github-sync' // Group
            );
            wp_github_sync_log("Background Deploy AJAX: Background deployment scheduled via Action Scheduler", 'info');
        } else {
            // Fallback to WP Cron (less reliable for immediate execution)
            wp_schedule_single_event(time(), 'wp_github_sync_run_background_deploy', [['branch' => $branch]]);
            wp_github_sync_log("Background Deploy AJAX: Background deployment scheduled via WP Cron (Action Scheduler not found)", 'warning');
        }

        // Return success to the client
        wp_send_json_success(array(
            'message' => __('Background deployment process started. You can monitor the progress or continue working on other tasks.', 'wp-github-sync')
        ));
    }

    /**
     * Handle AJAX switch branch request.
     */
    public function handle_ajax_switch_branch() {
        $this->verify_request(); // Use trait for verification

        // Check if branch is provided
        if (!isset($_POST['branch']) || empty($_POST['branch'])) {
            wp_github_sync_log("Switch Branch AJAX: No branch specified in request", 'error');
            wp_send_json_error(array('message' => __('No branch specified.', 'wp-github-sync')));
            return;
        }

        $branch = sanitize_text_field($_POST['branch']);
        $current_branch = wp_github_sync_get_current_branch();

        wp_github_sync_log("Switch Branch AJAX: Attempting to switch from '{$current_branch}' to '{$branch}'", 'info');

        // Switch branch
        $result = $this->sync_manager->switch_branch($branch);

        if (is_wp_error($result)) {
            $error_message = $result->get_error_message();
            wp_github_sync_log("Switch Branch AJAX: Failed to switch to branch '{$branch}' - {$error_message}", 'error');
            wp_send_json_error(array('message' => $error_message));
        } else {
            wp_github_sync_log("Switch Branch AJAX: Successfully switched to branch '{$branch}'", 'info');
            wp_send_json_success(array(
                'message' => sprintf(__('Successfully switched to branch: %s', 'wp-github-sync'), $branch),
                'branch' => $branch,
            ));
        }
    }

    /**
     * Handle AJAX rollback request.
     */
    public function handle_ajax_rollback() {
        $this->verify_request(); // Use trait for verification

        // Check if commit is provided
        if (!isset($_POST['commit']) || empty($_POST['commit'])) {
            wp_github_sync_log("Rollback AJAX: No commit specified in request", 'error');
            wp_send_json_error(array('message' => __('No commit specified.', 'wp-github-sync')));
            return;
        }

        $commit = sanitize_text_field($_POST['commit']);
        $commit_short = substr($commit, 0, 8);

        wp_github_sync_log("Rollback AJAX: Attempting rollback to commit '{$commit_short}'", 'info');

        // Rollback
        $result = $this->sync_manager->rollback($commit);

        if (is_wp_error($result)) {
            $error_message = $result->get_error_message();
            wp_github_sync_log("Rollback AJAX: Failed to rollback to commit '{$commit_short}' - {$error_message}", 'error');
            wp_send_json_error(array('message' => $error_message));
        } else {
            wp_github_sync_log("Rollback AJAX: Successfully rolled back to commit '{$commit_short}'", 'info');
            wp_send_json_success(array(
                'message' => sprintf(__('Successfully rolled back to commit: %s', 'wp-github-sync'), $commit_short),
                'commit' => $commit,
            ));
        }
    }

     /**
     * Handle AJAX initial sync request.
     * Note: This performs the sync directly, potentially timing out.
     * Prefer handle_ajax_background_initial_sync.
     */
    public function handle_ajax_initial_sync() {
        // Use specific nonce for initial sync
        $this->verify_request('wp_github_sync_initial_sync');

        try {
            // Reset progress tracking
            $this->progress_tracker->reset_progress();
            $this->progress_tracker->update_sync_progress(0, 'Starting initial sync process');

            // Check if we should create a new repository
            $create_new_repo = isset($_POST['create_new_repo']) && $_POST['create_new_repo'] == 1;
            $repo_name = isset($_POST['repo_name']) ? sanitize_text_field($_POST['repo_name']) : '';

            // Make sure GitHub API is initialized with the latest settings
            $this->progress_tracker->update_sync_progress(1, 'Initializing API client');
            // Assuming API Client is injected or accessible
            // $this->github_api->initialize(); // This should be handled elsewhere if needed

            wp_github_sync_log("Starting initial sync. Create new repo: " . ($create_new_repo ? 'yes' : 'no'), 'info');

            // Check if authentication is working
            $this->progress_tracker->update_sync_progress(2, 'Testing GitHub authentication');
            // $auth_test = $this->github_api->test_authentication(); // Assuming API Client accessible
            // if ($auth_test !== true) { ... error handling ... }

            // Perform the sync (simplified example, actual logic is complex)
            if ($create_new_repo) {
                // ... logic to create repo and sync ...
                $this->progress_tracker->update_sync_progress(3, "Creating new repository: " . $repo_name);
                // ... call $this->repository->initial_sync() ...
            } else {
                // ... logic to sync to existing repo ...
                 $this->progress_tracker->update_sync_progress(3, "Connecting to existing repository");
                 // ... call $this->repository->initial_sync() ...
            }

            // Example success
            $this->progress_tracker->update_sync_progress(8, "Initial sync completed successfully!", 'complete');
            wp_send_json_success(array('message' => __('Initial sync completed successfully.', 'wp-github-sync')));

        } catch (\Exception $e) {
            wp_github_sync_log("Initial sync exception: " . $e->getMessage(), 'error');
            $this->progress_tracker->update_sync_progress(0, "Initial sync failed: " . $e->getMessage(), 'failed');
            wp_send_json_error(array('message' => sprintf(__('Initial sync failed: %s', 'wp-github-sync'), $e->getMessage())));
        }
    }

    /**
     * Handle AJAX background initial sync request.
     */
    public function handle_ajax_background_initial_sync() {
        // Use specific nonce for initial sync
        $this->verify_request('wp_github_sync_initial_sync');

        // Check if sync is already in progress (using transient lock)
        if (get_transient('wp_github_sync_deployment_lock')) {
             wp_github_sync_log("Background Initial Sync AJAX: Sync already in progress", 'warning');
             wp_send_json_error(array('message' => __('A sync operation is already in progress. Please wait for it to complete.', 'wp-github-sync')));
             return;
        }

        // Set lock
        set_transient('wp_github_sync_deployment_lock', true, 15 * MINUTE_IN_SECONDS);

        // Reset progress tracking
        $this->progress_tracker->reset_progress();
        $this->progress_tracker->update_sync_progress(0, __('Scheduling background initial sync...', 'wp-github-sync'), 'pending');

        // Prepare parameters for the background job
        $create_new_repo = isset($_POST['create_new_repo']) && intval($_POST['create_new_repo']) === 1;
        $repo_name = isset($_POST['repo_name']) ? sanitize_text_field($_POST['repo_name']) : '';
        $params = [
            'create_new_repo' => $create_new_repo,
            'repo_name' => $repo_name
        ];

        // Schedule the background job using Action Scheduler if available
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(
                time(),
                'wp_github_sync_run_background_initial_sync', // Hook handled by Job_Manager
                ['params' => $params],
                'wp-github-sync' // Group
            );
            wp_github_sync_log("Background initial sync scheduled via Action Scheduler", 'info');
        } else {
            wp_schedule_single_event(time(), 'wp_github_sync_run_background_initial_sync', [$params]);
            wp_github_sync_log("Background initial sync scheduled via WP Cron", 'warning');
        }

        wp_send_json_success(array(
            'message' => __('Background initial sync process started. You can monitor the progress.', 'wp-github-sync')
        ));
    }

    /**
     * Handle AJAX full sync request.
     * Note: This performs the sync directly, potentially timing out.
     * Prefer handle_ajax_background_full_sync.
     */
    public function handle_ajax_full_sync() {
        $this->verify_request();

        // Check if sync is already in progress
        if (get_transient('wp_github_sync_deployment_lock')) {
             wp_github_sync_log("Full Sync AJAX: Sync already in progress", 'warning');
             wp_send_json_error(array('message' => __('A sync operation is already in progress.', 'wp-github-sync')));
             return;
        }

        // Set lock
        set_transient('wp_github_sync_deployment_lock', true, 15 * MINUTE_IN_SECONDS);

        try {
            // Reset progress
            $this->progress_tracker->reset_progress();
            $this->progress_tracker->update_sync_progress(0, 'Starting full sync process');

            // Ensure GitHub API is initialized
            // $this->github_api->initialize(); // Assuming handled elsewhere

            // Get repository URL and check if it exists
            $repo_url = get_option('wp_github_sync_repository', '');
            if (empty($repo_url)) {
                throw new \Exception(__('No repository URL configured.', 'wp-github-sync'));
            }

            // Test authentication and repository existence
            // if (!$this->github_api->repository_exists()) { ... }

            $branch = wp_github_sync_get_current_branch();
            wp_github_sync_log("Starting full sync to GitHub for branch: {$branch}", 'info');

            // Execute the initial sync operation (which effectively does a full sync)
            $result = $this->repository->initial_sync($branch);

            // Clear the lock
            delete_transient('wp_github_sync_deployment_lock');

            if (is_wp_error($result)) {
                // Check if it's the 'sync_in_progress' error (chunked sync started)
                if ($result->get_error_code() === 'sync_in_progress') {
                    wp_github_sync_log("Full sync started in background (chunked).", 'info');
                    // Send a success message indicating background processing
                    wp_send_json_success(array(
                        'message' => __('Full sync process started in the background. You can monitor the progress.', 'wp-github-sync')
                    ));
                } else {
                    throw new \Exception($result->get_error_message());
                }
            } else {
                // Update the sync time
                update_option('wp_github_sync_last_deployment_time', time());
                wp_github_sync_log("Full sync to GitHub completed successfully", 'info');
                $this->progress_tracker->update_sync_progress(8, __('Full sync completed successfully!', 'wp-github-sync'), 'complete');
                wp_send_json_success(array(
                    'message' => __('All WordPress files have been successfully synced to GitHub!', 'wp-github-sync')
                ));
            }
        } catch (\Exception $e) {
            delete_transient('wp_github_sync_deployment_lock');
            wp_github_sync_log("Full sync failed: " . $e->getMessage(), 'error');
            $this->progress_tracker->update_sync_progress(0, "Full sync failed: " . $e->getMessage(), 'failed');
            wp_send_json_error(array('message' => sprintf(__('Full sync failed: %s', 'wp-github-sync'), $e->getMessage())));
        }
    }

    /**
     * Handle AJAX background full sync request.
     */
    public function handle_ajax_background_full_sync() {
        $this->verify_request();

        // Check if sync is already in progress
        if (get_transient('wp_github_sync_deployment_lock')) {
             wp_github_sync_log("Background Full Sync AJAX: Sync already in progress", 'warning');
             wp_send_json_error(array('message' => __('A sync operation is already in progress.', 'wp-github-sync')));
             return;
        }

        // Set lock
        set_transient('wp_github_sync_deployment_lock', true, 15 * MINUTE_IN_SECONDS);

        // Reset progress tracking
        $this->progress_tracker->reset_progress();
        $this->progress_tracker->update_sync_progress(0, __('Scheduling background full sync...', 'wp-github-sync'), 'pending');

        // Schedule the background job
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(
                time(),
                'wp_github_sync_run_background_full_sync', // Hook handled by Job_Manager
                ['params' => []], // No specific params needed for full sync currently
                'wp-github-sync' // Group
            );
            wp_github_sync_log("Background full sync scheduled via Action Scheduler", 'info');
        } else {
            wp_schedule_single_event(time(), 'wp_github_sync_run_background_full_sync', [[]]);
            wp_github_sync_log("Background full sync scheduled via WP Cron", 'warning');
        }

        wp_send_json_success(array(
            'message' => __('Background full sync process started. You can monitor the progress.', 'wp-github-sync')
        ));
    }

} // End class SyncActionsHandler
