<?php
/**
 * Handles Status Check related AJAX requests for the WordPress GitHub Sync plugin.
 *
 * @package WPGitHubSync\Admin\AJAX
 */

namespace WPGitHubSync\Admin\AJAX;

use WPGitHubSync\Admin\Progress_Tracker; // Needed for getting progress

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Status Check AJAX Handler class.
 */
class StatusCheckHandler {

    use VerifiesRequestTrait; // Use the trait for verification

    /**
     * Progress Tracker instance.
     *
     * @var Progress_Tracker
     */
    private $progress_tracker;

    /**
     * Constructor.
     *
     * @param Progress_Tracker $progress_tracker The Progress Tracker instance.
     */
    public function __construct(Progress_Tracker $progress_tracker) {
        $this->progress_tracker = $progress_tracker;
    }

    /**
     * Register AJAX hooks for status check actions.
     */
    public function register_hooks() {
        add_action('wp_ajax_wp_github_sync_check_progress', array($this, 'handle_ajax_check_progress'));
        add_action('wp_ajax_wp_github_sync_check_status', array($this, 'handle_ajax_check_status'));
    }

    /**
     * Handle AJAX check progress request
     */
    public function handle_ajax_check_progress() {
        $this->verify_request(); // Use trait for verification

        // Get current progress using Progress_Tracker
        $progress = $this->progress_tracker->get_progress();

        // Check Action Scheduler to see if a job is actually running
        $job_active = false;
        if (function_exists('as_get_scheduled_actions')) {
            $running_actions = as_get_scheduled_actions([
                'group' => 'wp-github-sync', // Check for any job in our group
                'status' => \ActionScheduler_Store::STATUS_RUNNING,
                'per_page' => 1,
            ], 'ids');
            $pending_actions = as_get_scheduled_actions([
                'group' => 'wp-github-sync',
                'status' => \ActionScheduler_Store::STATUS_PENDING,
                'per_page' => 1,
            ], 'ids');
            $job_active = !empty($running_actions) || !empty($pending_actions);
        } else {
            // Fallback: Check old lock if AS not available
            $job_active = get_option('wp_github_sync_sync_in_progress', false);
        }

        // If progress status is 'running' but no job is active, mark as failed/stalled
        if (isset($progress['status']) && $progress['status'] === 'running' && !$job_active) {
            wp_github_sync_log("Progress check found 'running' status but no active job. Marking as potentially stalled.", 'warning');
            $progress['status'] = 'failed'; // Or 'stalled'
            $progress['detail'] = __('Process appears stalled or failed unexpectedly.', 'wp-github-sync');
            // Update the stored progress
            $this->progress_tracker->update_sync_progress($progress['step'] ?? 0, $progress['detail'], $progress['status'], $progress['subStep'] ?? null);
        }

        wp_send_json_success($progress);
    }

    /**
     * Handle AJAX check status request.
     * This checks the status of a chunked sync operation or general background status.
     */
    public function handle_ajax_check_status() {
        $this->verify_request(); // Use trait for verification

        $in_progress = false;
        $status_message = __('No sync operation is currently in progress.', 'wp-github-sync');
        $progress_data = $this->progress_tracker->get_progress(); // Get latest progress data

        // Check Action Scheduler first
        $job_active = false;
        if (function_exists('as_get_scheduled_actions')) {
             $running_actions = as_get_scheduled_actions([
                 'group' => 'wp-github-sync',
                 'status' => \ActionScheduler_Store::STATUS_RUNNING,
                 'per_page' => 1,
             ], 'ids');
             $pending_actions = as_get_scheduled_actions([
                 'group' => 'wp-github-sync',
                 'status' => \ActionScheduler_Store::STATUS_PENDING,
                 'per_page' => 1,
             ], 'ids');
             $job_active = !empty($running_actions) || !empty($pending_actions);
        } else {
            // Fallback check using options
            $sync_state = get_option('wp_github_sync_chunked_sync_state', null);
            $old_lock = get_option('wp_github_sync_sync_in_progress', false);
            $job_active = $sync_state || ($progress_data && $progress_data['status'] === 'running') || $old_lock;
        }

        if ($job_active) {
            $in_progress = true;
            $status_message = $progress_data['detail'] ?? __('Processing...', 'wp-github-sync');
        } else {
            // If no job is active, ensure status isn't stuck on 'running'
            if (isset($progress_data['status']) && $progress_data['status'] === 'running') {
                 wp_github_sync_log("Status check found 'running' status but no active job. Resetting.", 'warning');
                 $this->progress_tracker->reset_progress(); // Reset progress if stuck
                 $progress_data = $this->progress_tracker->get_progress(); // Get reset progress
            }
        }

        // Prepare response
        $response = array(
            'in_progress' => $in_progress,
            'message' => $status_message,
            'timestamp' => time()
        );

        // Include detailed progress data if available and in progress
        if ($in_progress && $progress_data) {
             $response['stage'] = $progress_data['stage'] ?? ($progress_data['detail'] ?? 'unknown');
             $response['progress_step'] = $progress_data['progress_step'] ?? ($progress_data['step'] ?? 0);
             if (isset($progress_data['stats'])) {
                 $response['stats'] = $progress_data['stats'];
             }
             if (isset($progress_data['fileProgress'])) {
                 $response['fileProgress'] = $progress_data['fileProgress'];
             }
        }

        wp_send_json_success($response);
    }

} // End class StatusCheckHandler
