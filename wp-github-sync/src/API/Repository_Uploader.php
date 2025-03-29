<?php
/**
 * GitHub Repository Uploader for the WordPress GitHub Sync plugin.
 * Orchestrates the upload process using GitData helpers.
 *
 * @package WPGitHubSync\API
 */

namespace WPGitHubSync\API;

// Use the new GitData helper classes
use WPGitHubSync\API\GitData\BlobCreator;
use WPGitHubSync\API\GitData\TreeBuilder;
use WPGitHubSync\API\GitData\BranchManager;
// FilesystemHelper might still be needed if is_binary_file check remains here
// use WPGitHubSync\Utils\FilesystemHelper;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * GitHub Repository Uploader class.
 */
class Repository_Uploader {

    /**
     * API Client instance.
     * @var API_Client
     */
    private $api_client;

    /**
     * Blob Creator instance.
     * @var BlobCreator
     */
    private $blob_creator;

    /**
     * Tree Builder instance.
     * @var TreeBuilder
     */
    private $tree_builder;

    /**
     * Branch Manager instance.
     * @var BranchManager
     */
    private $branch_manager;

    /**
     * Progress callback function
     * @var callable|null
     */
    private $progress_callback = null;

    /**
     * Initialize the Repository Uploader class.
     *
     * @param API_Client $api_client The API client instance.
     */
    public function __construct(API_Client $api_client) {
        $this->api_client = $api_client;
        // Instantiate helpers
        $this->blob_creator = new BlobCreator($api_client);
        $this->tree_builder = new TreeBuilder($api_client, $this->blob_creator);
        $this->branch_manager = new BranchManager($api_client);
    }

    /**
     * Set a progress callback function
     *
     * @param callable|null $callback Function that takes ($subStep, $detail, $stats)
     */
    public function set_progress_callback($callback) {
        $this->progress_callback = $callback;
        // Pass callback to helpers that support it
        $this->tree_builder->set_progress_callback($callback);
        // $this->blob_creator->set_progress_callback($callback); // If BlobCreator needs it
    }

    /**
     * Update progress via callback (kept for internal use if needed, but TreeBuilder handles file stats)
     *
     * @param int $subStep Sub-step number
     * @param string $detail Progress detail message
     * @param array $stats Optional additional stats
     */
    private function update_progress($subStep, $detail, $stats = []) {
        if (is_callable($this->progress_callback)) {
            // TreeBuilder now manages file_stats internally during create_tree_items
            call_user_func($this->progress_callback, $subStep, $detail, $stats);
        }
    }

    /**
     * Upload files to GitHub using the Git Data API.
     * Orchestrates the workflow using helper classes.
     *
     * @param string $directory      Directory containing files to upload.
     * @param string $branch         Branch to upload to.
     * @param string $commit_message Commit message.
     * @param string $tree_sha       The SHA of the final tree object representing the desired state.
     * @param string $parent_commit_sha The SHA of the parent commit.
     * @return string|\WP_Error Commit SHA on success or WP_Error on failure.
     */
    public function create_commit_and_update_ref($branch, $commit_message, $tree_sha, $parent_commit_sha) {
        wp_github_sync_log("Starting final commit and ref update for branch: {$branch}", 'info');
        $this->update_progress(10, __('Creating final commit...', 'wp-github-sync')); // Assuming step 10

        try {
            // 1. Create New Commit
            $commit_data = [
                'message' => $commit_message,
                'tree' => $tree_sha,
            ];
            if (!empty($parent_commit_sha)) {
                $commit_data['parents'] = [$parent_commit_sha];
            }

            $new_commit = $this->api_client->request(
                "repos/{$this->api_client->get_owner()}/{$this->api_client->get_repo()}/git/commits",
                'POST',
                $commit_data
            );
            if (is_wp_error($new_commit)) {
                throw new \Exception(__('Failed to create commit: ', 'wp-github-sync') . $new_commit->get_error_message());
            }
            $new_commit_sha = $new_commit['sha'];
            wp_github_sync_log("Created new commit with SHA: {$new_commit_sha}", 'info');

            // 2. Update Branch Reference
            $this->update_progress(11, __('Updating branch reference...', 'wp-github-sync')); // Assuming step 11
            $update_result = $this->branch_manager->update_branch_reference($branch, $new_commit_sha);
            if (is_wp_error($update_result)) {
                 // Log error, but maybe don't fail the whole process? Or should we?
                 // If the commit was created but ref update fails, repo is slightly inconsistent.
                 wp_github_sync_log("Failed to update branch reference '{$branch}': " . $update_result->get_error_message(), 'error');
                 // Let's throw exception for now to indicate failure clearly.
                 throw new \Exception('Commit created, but failed to update branch reference: ' . $update_result->get_error_message());
            }

            // 3. Final Success Update (Progress update handled by caller - InitialSyncManager)
            wp_github_sync_log("Successfully updated branch '{$branch}' to commit {$new_commit_sha}", 'info');

            // Store this commit as the last pushed commit (handled by caller - InitialSyncManager)
            // update_option('wp_github_sync_last_pushed_commit', $new_commit_sha);

            return $new_commit_sha; // Return commit SHA on success

        } catch (\Exception $e) {
            $error_message = $e->getMessage();
            wp_github_sync_log("GitHub commit/ref update process failed: " . $error_message, 'error');
            // Progress update on failure handled by caller
            return new \WP_Error('github_commit_failed', $error_message);
        }
    }

    // Add public getters for helpers if needed by InitialSyncManager
    public function get_blob_creator() {
        return $this->blob_creator;
    }
    public function get_tree_builder() {
        return $this->tree_builder;
    }
    public function get_branch_manager() {
        return $this->branch_manager;
    }

    // --- Removed Methods (Moved to GitData Helpers or Deprecated) ---
    // Removed: get_or_create_branch_reference() -> Moved to BranchManager
    // Removed: create_initial_branch() -> Moved to BranchManager
    // Removed: create_tree_items() -> Moved to TreeBuilder
    // Removed: list_directory_recursive() -> Moved to FilesystemHelper (or TreeBuilder if specific)
    // Removed: create_tree() -> Moved to TreeBuilder (as create_tree_api_call)
    // Removed: is_binary_file() -> Moved to BlobCreator (or FilesystemHelper)
    // Removed: file_stats property -> Managed within TreeBuilder

} // End class Repository_Uploader
