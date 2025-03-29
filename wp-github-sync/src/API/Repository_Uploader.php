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
     * @param string $github_path    Optional GitHub path where files should be placed.
     * @return string|\WP_Error Commit SHA on success or WP_Error on failure.
     */
    public function upload_files_to_github($directory, $branch, $commit_message, $github_path = '') {
        wp_github_sync_log("Starting GitHub upload process for branch: {$branch}", 'info');
        $this->update_progress(0, __('Starting upload process...', 'wp-github-sync'));

        try {
            // Basic validations
            if (!is_dir($directory) || !is_readable($directory)) {
                throw new \Exception(__('Directory does not exist or is not readable', 'wp-github-sync'));
            }
            $dir_contents = array_diff(scandir($directory), array('.', '..'));
            if (empty($dir_contents)) {
                 throw new \Exception(__('Directory is empty, nothing to upload', 'wp-github-sync'));
            }

            // 1. Get Repository Info & Default Branch
            $repo_info = $this->api_client->get_repository();
            if (is_wp_error($repo_info)) throw new \Exception($repo_info->get_error_message());
            $default_branch = $repo_info['default_branch'] ?? 'main';

            // 2. Get or Create Branch Reference
            $this->update_progress(1, __('Getting branch information...', 'wp-github-sync'));
            $reference = $this->branch_manager->get_or_create_branch_reference($branch, $default_branch);
            if (is_wp_error($reference)) throw new \Exception($reference->get_error_message());
            if (!isset($reference['object']['sha'])) throw new \Exception(__('Invalid branch reference received.', 'wp-github-sync'));
            $ref_sha = $reference['object']['sha'];

            // 3. Get Base Commit & Tree
            $this->update_progress(2, __('Fetching latest commit data...', 'wp-github-sync'));
            $commit = $this->api_client->request("repos/{$this->api_client->get_owner()}/{$this->api_client->get_repo()}/git/commits/{$ref_sha}");
            if (is_wp_error($commit)) throw new \Exception(__('Failed to get base commit: ', 'wp-github-sync') . $commit->get_error_message());
            $base_tree_sha = $commit['tree']['sha'];

            // 4. Create New Tree (Handled by TreeBuilder)
            // Progress updates for file scanning and blob creation happen within TreeBuilder
            $this->update_progress(3, __('Analyzing files and creating blobs...', 'wp-github-sync'));
            $new_tree_sha = $this->tree_builder->create_tree_from_directory($directory, $base_tree_sha, $github_path);
            if (is_wp_error($new_tree_sha)) throw new \Exception(__('Failed to create Git tree: ', 'wp-github-sync') . $new_tree_sha->get_error_message());
            $this->update_progress(5, __('Git tree created successfully.', 'wp-github-sync'));

            // 5. Create New Commit
            $this->update_progress(6, __('Creating new commit...', 'wp-github-sync'));
            $new_commit = $this->api_client->request(
                "repos/{$this->api_client->get_owner()}/{$this->api_client->get_repo()}/git/commits",
                'POST',
                [
                    'message' => $commit_message,
                    'tree' => $new_tree_sha,
                    'parents' => [$ref_sha] // Parent is the commit the branch ref pointed to
                ]
            );
            if (is_wp_error($new_commit)) throw new \Exception(__('Failed to create commit: ', 'wp-github-sync') . $new_commit->get_error_message());
            $new_commit_sha = $new_commit['sha'];
            wp_github_sync_log("Created new commit with SHA: {$new_commit_sha}", 'info');

            // 6. Update Branch Reference
            $this->update_progress(7, __('Updating branch reference...', 'wp-github-sync'));
            $update_result = $this->branch_manager->update_branch_reference($branch, $new_commit_sha);
            if (is_wp_error($update_result)) throw new \Exception($update_result->get_error_message());

            // 7. Final Success Update
            $this->update_progress(8, __('Upload completed successfully!', 'wp-github-sync'), ['status' => 'complete']);
            wp_github_sync_log("Successfully uploaded files and updated branch '{$branch}' to commit {$new_commit_sha}", 'info');

            // Store this commit as the last deployed commit (or last synced)
            // This might be better handled by the calling process (e.g., InitialSyncManager)
            update_option('wp_github_sync_last_pushed_commit', $new_commit_sha);

            return $new_commit_sha; // Return commit SHA on success

        } catch (\Exception $e) {
            $error_message = $e->getMessage();
            wp_github_sync_log("GitHub upload process failed: " . $error_message, 'error');
            $this->update_progress(0, __('Upload failed: ', 'wp-github-sync') . $error_message, ['status' => 'failed']);
            return new \WP_Error('github_upload_failed', $error_message);
        }
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
