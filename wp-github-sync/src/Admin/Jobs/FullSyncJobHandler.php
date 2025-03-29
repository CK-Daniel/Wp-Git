<?php
/**
 * Handles the background full sync job (placeholder).
 *
 * @package WPGitHubSync\Admin\Jobs
 */

namespace WPGitHubSync\Admin\Jobs;

use WPGitHubSync\Admin\Progress_Tracker;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Full Sync Job Handler class.
 */
class FullSyncJobHandler implements JobHandler {

    /** @var Progress_Tracker */
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
     * Execute the full sync job (placeholder).
     *
     * @param array $params Parameters for the job.
     * @return bool True on success.
     * @throws \Exception If the job fails.
     */
    public function handle(array $params) {
        wp_github_sync_log("Full Sync Job Handler: Running full sync (Placeholder)", 'info');
        $this->progress_tracker->update_sync_progress(2, __('Full sync starting...', 'wp-github-sync'), 'running');

        // Placeholder for actual full sync logic
        sleep(2); // Simulate work

        $this->progress_tracker->update_sync_progress(8, __('Background full sync completed (Placeholder)', 'wp-github-sync'), 'complete');
        wp_github_sync_log("Full Sync Job Handler: Full sync job completed (Placeholder).", 'info');
        return true; // Indicate success
    }
}
