<?php
/**
 * Handles generic setup and execution for background tasks.
 *
 * @package WPGitHubSync\Admin
 */

namespace WPGitHubSync\Admin;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Background Task Runner class.
 */
class BackgroundTaskRunner {

    /**
     * Prepare the environment for a background task.
     * Sets time limit and attempts to increase memory limit.
     */
    public function prepare_environment() {
        // Set unlimited timeout
        set_time_limit(0);

        // Try to increase memory limit
        $current_memory_limit = ini_get('memory_limit');
        $current_memory_bytes = wp_convert_hr_to_bytes($current_memory_limit);
        // Request a higher limit for background tasks
        $desired_memory_bytes = wp_convert_hr_to_bytes('512M');

        if ($current_memory_bytes < $desired_memory_bytes) {
            try {
                ini_set('memory_limit', '512M');
                $new_limit = ini_get('memory_limit');
                wp_github_sync_log("Background process - increased memory limit from {$current_memory_limit} to {$new_limit}", 'info');
            } catch (\Exception $e) {
                wp_github_sync_log("Could not increase memory limit: " . $e->getMessage(), 'warning');
            }
        } else {
             wp_github_sync_log("Background process - current memory limit ({$current_memory_limit}) is sufficient", 'debug');
        }
    }

    /**
     * Executes a given task callable within a prepared environment with error handling.
     *
     * @param string   $task_type        A descriptive name for the task type (e.g., 'initial_sync', 'deploy').
     * @param callable $task_callable    The function or method to execute for the task.
     * @param Progress_Tracker $progress_tracker The progress tracker instance.
     * @return mixed The result of the task callable.
     * @throws \Exception If the task callable throws an exception.
     */
    public function run(string $task_type, callable $task_callable, Progress_Tracker $progress_tracker) {
        $this->prepare_environment();
        wp_github_sync_log("Starting background task: {$task_type}", 'info');

        try {
            // Execute the actual task logic provided by the callable
            $result = call_user_func($task_callable);

            // Check if the task initiated chunking (indicated by WP_Error with 'sync_in_progress')
            $sync_state = get_option('wp_github_sync_chunked_sync_state', null);
            $is_chunking = ($result instanceof \WP_Error && $result->get_error_code() === 'sync_in_progress') || $sync_state;

            if (!$is_chunking) {
                // If not chunking, mark as complete and clear lock
                // Final progress update should happen within the $task_callable or just before returning here
                delete_option('wp_github_sync_sync_in_progress'); // Consider moving lock management outside runner
                wp_github_sync_log("Background task '{$task_type}' completed successfully and lock cleared", 'info');
            } else {
                 wp_github_sync_log("Background task '{$task_type}' continuing in chunks.", 'info');
                 // Progress will be updated by the chunk processor
            }

            return $result;

        } catch (\Exception $e) {
            // Log the error
            wp_github_sync_log("Background task '{$task_type}' failed: " . $e->getMessage(), 'error');
            wp_github_sync_log("Stack trace: " . $e->getTraceAsString(), 'error');

            // Update progress as failed using Progress_Tracker
            $progress_tracker->update_sync_progress(
                0, // Reset step on failure
                sprintf(__('Error during %s: %s', 'wp-github-sync'), $task_type, $e->getMessage()),
                'failed'
            );
             // Optionally add the full message to the main progress option
             update_option('wp_github_sync_sync_progress', array_merge($progress_tracker->get_progress(), [
                 'message' => sprintf(__('Synchronization failed: %s', 'wp-github-sync'), $e->getMessage())
             ]));

            // Clean up state on failure
            delete_option('wp_github_sync_sync_in_progress'); // Consider moving lock management outside runner
            delete_option('wp_github_sync_chunked_sync_state');

            // Rethrow the exception so the caller (e.g., Action Scheduler) knows it failed
            throw $e;
        }
    }
}
