<?php
/**
 * Handles the background initial sync job.
 *
 * @package WPGitHubSync\Admin\Jobs
 */

namespace WPGitHubSync\Admin\Jobs;

use WPGitHubSync\API\API_Client;
use WPGitHubSync\API\Repository;
use WPGitHubSync\Admin\Progress_Tracker; // Needed for progress updates

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Initial Sync Job Handler class.
 */
class InitialSyncJobHandler implements JobHandler {

    /** @var API_Client */
    private $github_api;
    /** @var Repository */
    private $repository;
    /** @var Progress_Tracker */
    private $progress_tracker;

    /**
     * Constructor.
     *
     * @param API_Client       $github_api       The API Client instance.
     * @param Repository       $repository       The Repository instance.
     * @param Progress_Tracker $progress_tracker The Progress Tracker instance.
     */
    public function __construct(API_Client $github_api, Repository $repository, Progress_Tracker $progress_tracker) {
        $this->github_api = $github_api;
        $this->repository = $repository;
        $this->progress_tracker = $progress_tracker;
    }

    /**
     * Execute the initial sync job.
     *
     * @param array $params Parameters, expected: 'create_new_repo', 'repo_name'.
     * @return bool|\WP_Error Result of the sync operation.
     * @throws \Exception If sync fails.
     */
    public function handle(array $params) {
        $create_new_repo = $params['create_new_repo'] ?? false;
        $repo_name = $params['repo_name'] ?? '';
        $branch = wp_github_sync_get_current_branch(); // Get target branch

        wp_github_sync_log("Initial Sync Job Handler: Running initial sync. Create new: " . ($create_new_repo ? 'yes' : 'no'), 'info');

        // Initialize API client
        $this->github_api->initialize();

        // Set progress callback for Repository class using Progress_Tracker's method
        $this->repository->set_progress_callback([$this->progress_tracker, 'update_sync_progress_from_callback']);

        // Perform repository creation if requested
        if ($create_new_repo) {
            if (empty($repo_name)) {
                throw new \Exception(__('Repository name is required to create a new repository.', 'wp-github-sync'));
            }
            $this->progress_tracker->update_sync_progress(3, "Creating repository: " . $repo_name);
            $site_name = get_bloginfo('name');
            $description = sprintf(__('WordPress site: %s', 'wp-github-sync'), $site_name);
            $create_result = $this->github_api->create_repository($repo_name, $description);

            if (is_wp_error($create_result)) {
                throw new \Exception(sprintf(__('Failed to create repository: %s', 'wp-github-sync'), $create_result->get_error_message()));
            }
            $repo_url = $create_result['html_url'] ?? '';
            if (empty($repo_url)) {
                throw new \Exception(__('Repository created, but URL missing from response.', 'wp-github-sync'));
            }
            update_option('wp_github_sync_repository', $repo_url); // Save the new repo URL
            $this->progress_tracker->update_sync_progress(4, "Repository created: " . $repo_url);
            // Re-initialize API client with new repo info
            $this->github_api->initialize();
        } else {
            // Verify existing repository access
            $this->progress_tracker->update_sync_progress(4, "Verifying repository access");
            if (!$this->github_api->repository_exists()) {
                throw new \Exception(__('Repository does not exist or is not accessible.', 'wp-github-sync'));
            }
            $this->progress_tracker->update_sync_progress(4, "Repository access verified");
        }

        // Start the initial sync process (delegated to Repository -> InitialSyncManager)
        $sync_result = $this->repository->initial_sync($branch);

        // Handle the result
        if (is_wp_error($sync_result)) {
            // Check if it's the 'sync_in_progress' notice (chunking started)
            if ($sync_result->get_error_code() === 'sync_in_progress') {
                 wp_github_sync_log("Initial Sync Job Handler: Chunked sync started.", 'info');
                 // Update progress to indicate background processing
                 $this->progress_tracker->update_sync_progress(5, __('Initial sync running in background...', 'wp-github-sync'), 'running');
                 return true; // Indicate the job initiated successfully, background steps will continue
            } else {
                // A real error occurred during setup
                throw new \Exception(sprintf(__('Initial sync failed: %s', 'wp-github-sync'), $sync_result->get_error_message()));
            }
        } else {
             // Sync completed immediately (unlikely for large sites, but possible)
             wp_github_sync_log("Initial Sync Job Handler: Sync completed immediately.", 'info');
             // The InitialSyncManager should handle the final progress update in this case.
             // If $sync_result contains the commit SHA, we could update options here, but it's better
             // if the sync process itself handles updating the last pushed commit.
             // update_option('wp_github_sync_last_pushed_commit', $sync_result);
             return true; // Indicate success
        }
    }
}
