<?php
/**
 * Handles background jobs and progress tracking for WordPress GitHub Sync plugin.
 *
 * @package WPGitHubSync\Admin
 */

namespace WPGitHubSync\Admin;

// Use the new Progress_Tracker, BackgroundTaskRunner, and Job Handler classes
use WPGitHubSync\Admin\Progress_Tracker;
use WPGitHubSync\Admin\BackgroundTaskRunner;
use WPGitHubSync\Admin\Jobs\JobHandler; // Interface
use WPGitHubSync\Admin\Jobs\DeployJobHandler;
use WPGitHubSync\Admin\Jobs\InitialSyncJobHandler;
use WPGitHubSync\Admin\Jobs\FullSyncJobHandler; // Placeholder

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Job Manager class.
 */
class Job_Manager {

    /**
     * GitHub API client instance.
     *
     * @var \WPGitHubSync\API\API_Client
     */
    private $github_api;

    /**
     * Git Sync Manager instance.
     *
     * @var \WPGitHubSync\Sync\Sync_Manager
     */
    private $sync_manager;

    /**
     * Repository instance.
     *
     * @var \WPGitHubSync\API\Repository
     */
    private $repository;

    /**
     * Progress Tracker instance.
     *
     * @var Progress_Tracker
     */
    private $progress_tracker;

    /**
     * Background Task Runner instance.
     *
     * @var BackgroundTaskRunner
     */
    private $background_task_runner;

    /**
     * Array of job handlers.
     * @var array<string, JobHandler>
     */
    private $job_handlers = [];

    /**
     * Constructor.
     *
     * @param \WPGitHubSync\API\API_Client    $github_api            The GitHub API client instance.
     * @param \WPGitHubSync\Sync\Sync_Manager  $sync_manager          The Sync Manager instance.
     * @param \WPGitHubSync\API\Repository    $repository            The Repository instance.
     * @param Progress_Tracker                $progress_tracker      The Progress Tracker instance.
     * @param BackgroundTaskRunner            $background_task_runner The Background Task Runner instance.
     */
    public function __construct(
        \WPGitHubSync\API\API_Client $github_api,
        \WPGitHubSync\Sync\Sync_Manager $sync_manager,
        \WPGitHubSync\API\Repository $repository,
        Progress_Tracker $progress_tracker,
        BackgroundTaskRunner $background_task_runner // Inject BackgroundTaskRunner
    ) {
        $this->github_api = $github_api;
        $this->sync_manager = $sync_manager;
        $this->repository = $repository;
        $this->progress_tracker = $progress_tracker;
        $this->background_task_runner = $background_task_runner;

        // Instantiate and register job handlers
        $this->register_job_handlers();
    }

    /**
     * Instantiate and store job handlers.
     */
    private function register_job_handlers() {
        $this->job_handlers['deploy'] = new DeployJobHandler($this->github_api, $this->sync_manager);
        $this->job_handlers['initial'] = new InitialSyncJobHandler($this->github_api, $this->repository, $this->progress_tracker);
        $this->job_handlers['full'] = new FullSyncJobHandler($this->progress_tracker); // Placeholder
    }

    /**
     * Register WordPress hooks.
     */
    public function register_hooks() {
        // AJAX handlers are registered by AJAX_Handler class

        // Register Action Scheduler hooks for background processes
        add_action('wp_github_sync_run_background_deploy', array($this, 'run_background_deploy'), 10, 1);
        add_action('wp_github_sync_run_background_initial_sync', array($this, 'run_background_initial_sync'), 10, 1);
        add_action('wp_github_sync_run_background_full_sync', array($this, 'run_background_full_sync'), 10, 1);

        // Register Action Scheduler hook for chunked sync steps
        add_action('wp_github_sync_run_chunk_step', array($this, 'process_chunked_sync_step'), 10, 0); // No args needed for chunk step

        // Hook for handling actions on the Jobs admin page
        // Note: The exact page hook might depend on how Menu_Manager registers the page.
        // Assuming 'github-sync_page_wp-github-sync-jobs' based on common patterns.
        add_action('load-github-sync_page_wp-github-sync-jobs', array($this, 'handle_job_page_actions'));
    }

    /**
     * Handle actions triggered from the Jobs admin page (e.g., cancel, clear).
     */
    public function handle_job_page_actions() {
        if (isset($_GET['action']) && isset($_GET['nonce']) && wp_verify_nonce($_GET['nonce'], 'wp_github_sync_jobs_action')) {
            if ($_GET['action'] === 'cancel_chunked_sync' && current_user_can('manage_options')) {
                delete_option('wp_github_sync_chunked_sync_state');
                add_settings_error(
                    'wp_github_sync_jobs', // Use a specific setting group for this page's notices
                    'job_canceled',
                    __('Chunked sync job has been canceled.', 'wp-github-sync'),
                    'updated' // Use 'updated' for success messages
                );
                wp_github_sync_log("Chunked sync job canceled by user.", 'info');
            } elseif ($_GET['action'] === 'clear_scheduled_jobs' && current_user_can('manage_options')) {
                // Clear all scheduled Action Scheduler actions in our group
                $cleared_count = 0;
                if (function_exists('as_get_scheduled_actions')) {
                    $actions = as_get_scheduled_actions(['group' => 'wp-github-sync', 'status' => \ActionScheduler_Store::STATUS_PENDING]);
                    foreach ($actions as $action) {
                        as_cancel_action($action->get_id());
                        $cleared_count++;
                    }
                } else {
                     // Fallback for WP-Cron if Action Scheduler isn't active (less likely but safer)
                     $cron_events = _get_cron_array();
                     if (!empty($cron_events)) {
                         foreach ($cron_events as $timestamp => $hooks) {
                             foreach ($hooks as $hook => $events) {
                                 if (strpos($hook, 'wp_github_sync') !== false) {
                                     foreach ($events as $key => $event) {
                                         wp_unschedule_event($timestamp, $hook, $event['args']);
                                         $cleared_count++;
                                     }
                                 }
                             }
                         }
                     }
                }

                add_settings_error(
                    'wp_github_sync_jobs', // Use a specific setting group for this page's notices
                    'jobs_cleared',
                    sprintf(_n('%d scheduled job has been cleared.', '%d scheduled jobs have been cleared.', $cleared_count, 'wp-github-sync'), $cleared_count),
                    'updated' // Use 'updated' for success messages
                );
                 wp_github_sync_log("Cleared {$cleared_count} scheduled jobs by user.", 'info');
            }
            // Redirect to remove action parameters from URL after processing
            // This prevents accidental re-execution on refresh
            wp_safe_redirect(remove_query_arg(['action', 'nonce']));
            exit;
        }
    }

    // Removed handle_ajax_background_initial_sync() - Handled by AJAX_Handler
    // Removed handle_ajax_background_full_sync() - Handled by AJAX_Handler

    /**
     * Process background deployment task (Action Scheduler callback).
     *
     * @param array $params Parameters for the deploy operation (e.g., ['branch' => 'main']).
     */
    public function run_background_deploy($params) {
        $this->run_background_task('deploy', $params);
    }

     /**
     * Process background initial sync task (Action Scheduler callback).
     *
     * @param array $params Parameters for the initial sync operation (e.g., ['create_new_repo' => true, 'repo_name' => '...']).
     */
    public function run_background_initial_sync($params) {
         $this->run_background_task('initial', $params);
    }

     /**
     * Process background full sync task (Action Scheduler callback).
     *
     * @param array $params Parameters for the full sync operation.
     */
    public function run_background_full_sync($params) {
         $this->run_background_task('full', $params);
    }


    /**
     * Generic handler for running background tasks via Action Scheduler.
     *
     * @param string $type The type of sync ('initial', 'full', 'deploy').
     * @param array $params Parameters for the sync operation.
     */
    private function run_background_task($type, $params) {
        // Initialize progress
        $this->progress_tracker->reset_progress();
        $this->progress_tracker->update_sync_progress(1, __('Background process initializing', 'wp-github-sync'), 'running');

        // Get the appropriate handler
        if (!isset($this->job_handlers[$type])) {
            wp_github_sync_log("Job Manager: No handler registered for job type '{$type}'", 'error');
            $this->progress_tracker->update_sync_progress(0, "Error: Invalid job type '{$type}'", 'failed');
            return; // Or throw exception
        }
        $handler = $this->job_handlers[$type];

        // Define the task logic using the handler
        $task_callable = function() use ($handler, $params) {
            return $handler->handle($params);
        };

        // Execute the task using the BackgroundTaskRunner
        try {
            // The runner now handles environment prep, execution, and error logging/progress update on failure
            $this->background_task_runner->run($type . '_sync', $task_callable, $this->progress_tracker);
        } catch (\Exception $e) {
            // The runner re-throws the exception after handling it.
            // We catch it here primarily so Action Scheduler can mark the job as failed.
            wp_github_sync_log("Background task {$type} failed and exception was caught by Job_Manager.", 'error');
            // Do NOT re-throw here if using Action Scheduler, as it might cause duplicate failure handling.
        }
    }

    /**
     * Process a single step of the chunked initial sync (Action Scheduler callback).
     */
    public function process_chunked_sync_step() { // No arguments needed from Action Scheduler
        wp_github_sync_log("Processing chunked sync step via hook", 'info');

        // Get the current sync state
        $sync_state = get_option('wp_github_sync_chunked_sync_state', null);

        if (!$sync_state) {
            wp_github_sync_log("No chunked sync state found, aborting step.", 'warning');
            return;
        }

        // Check for fatal error flag
        if (isset($sync_state['fatal_error'])) {
            wp_github_sync_log("Detected fatal error flag in sync state, attempting recovery or cleanup.", 'error');
            // Implement recovery logic here if possible, or just clean up
            delete_option('wp_github_sync_chunked_sync_state');
            update_option('wp_github_sync_sync_progress', [
                'step' => 0,
                'detail' => __('Sync failed due to a fatal error. Please check logs.', 'wp-github-sync'),
                'status' => 'failed',
                'message' => __('Sync failed due to a fatal error. Please check logs.', 'wp-github-sync'),
                'timestamp' => time()
            ]);
            return;
        }

        // Set unlimited timeout for this chunk
        set_time_limit(0);

        // Try to increase memory limit
        $current_memory_limit = ini_get('memory_limit');
        $current_memory_bytes = wp_convert_hr_to_bytes($current_memory_limit);
        $desired_memory_bytes = wp_convert_hr_to_bytes('512M');

        if ($current_memory_bytes < $desired_memory_bytes) {
            try {
                ini_set('memory_limit', '512M');
            } catch (\Exception $e) { /* Ignore */ }
        }

        try {
            // Use the injected instances
            $api_client = $this->github_api;
            $repository = $this->repository;

            // Ensure the progress callback is set using Progress_Tracker's method
            $repository->set_progress_callback([$this->progress_tracker, 'update_sync_progress_from_callback']);

            // Call the continue method from the Repository class
            $branch = $sync_state['branch'] ?? 'main';
            $result = $repository->continue_chunked_sync($sync_state, $branch);

            // Check if the result indicates completion or an error
            if ($result === true) {
                // Sync completed successfully in this step
                wp_github_sync_log("Chunked sync process completed successfully.", 'info');
                // Final update handled within continue_chunked_sync or its sub-methods
            } elseif (is_wp_error($result)) {
                // An error occurred
                wp_github_sync_log("Error during chunked sync step: " . $result->get_error_message(), 'error');
                // Update progress with error using Progress_Tracker
                 $this->progress_tracker->update_sync_progress(
                    $sync_state['progress_step'] ?? 0,
                    sprintf(__('Error: %s', 'wp-github-sync'), $result->get_error_message()),
                    'failed'
                 );
                 update_option('wp_github_sync_sync_progress', array_merge(get_option('wp_github_sync_sync_progress', []), [
                     'message' => sprintf(__('Synchronization failed: %s', 'wp-github-sync'), $result->get_error_message())
                 ]));
                // Clean up state on error
                delete_option('wp_github_sync_chunked_sync_state');
            } else {
                // Processing is ongoing, next chunk should have been scheduled by continue_chunked_sync
                wp_github_sync_log("Chunk processed, next chunk scheduled.", 'debug');
            }

        } catch (\Exception $e) {
            wp_github_sync_log("Exception during chunked sync step: " . $e->getMessage(), 'error');
            wp_github_sync_log("Stack trace: " . $e->getTraceAsString(), 'error');
            // Update progress with error using Progress_Tracker
            $this->progress_tracker->update_sync_progress(
                 $sync_state['progress_step'] ?? 0,
                 sprintf(__('Error: %s', 'wp-github-sync'), $e->getMessage()),
                 'failed'
            );
             update_option('wp_github_sync_sync_progress', array_merge(get_option('wp_github_sync_sync_progress', []), [
                 'message' => sprintf(__('Synchronization failed: %s', 'wp-github-sync'), $e->getMessage())
             ]));
            // Clean up state on error
            delete_option('wp_github_sync_chunked_sync_state');
            // Rethrow the exception so Action Scheduler can mark the job as failed
            throw $e;
        }
    } // <-- Added missing closing brace for process_chunked_sync_step()

    // --- Removed handle_ajax_check_progress() - Handled by StatusCheckHandler ---
    // --- Removed handle_ajax_check_status() - Handled by StatusCheckHandler ---

    // --- Helper methods ---

    // Removed properties: $sync_progress, $file_processing_stats
    // Removed methods: update_sync_progress, update_sync_progress_from_callback, update_file_stats
    // These are now handled by the Progress_Tracker class.

    /**
     * Helper method to process create repo sync
     *
     * @param string $repo_name The repository name to create
     */
    // Removed perform_create_repo_sync() - Logic moved to InitialSyncJobHandler
    
    /**
     * Helper method to process existing repo sync
     */
    // Removed perform_existing_repo_sync() - Logic moved to InitialSyncJobHandler
}
