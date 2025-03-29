<?php
/**
 * GitHub Repository operations for the WordPress GitHub Sync plugin.
 * Handles downloading archives and comparing references.
 * Delegates initial sync logic to InitialSyncManager.
 *
 * @package WPGitHubSync\API
 */

namespace WPGitHubSync\API;

// Use the new helper classes
use WPGitHubSync\Utils\FilesystemHelper;
use WPGitHubSync\API\InitialSyncManager;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * GitHub Repository operations class.
 */
class Repository {

    /**
     * API Client instance.
     * @var API_Client
     */
    private $api_client;

    /**
     * Initial Sync Manager instance.
     * @var InitialSyncManager
     */
    private $initial_sync_manager;

    /**
     * WordPress Filesystem instance.
     * @var \WP_Filesystem_Base|null
     */
    private $wp_filesystem;

    /**
     * Progress callback function.
     * @var callable|null
     */
    private $progress_callback = null;

    /**
     * Initialize the Repository class.
     *
     * @param API_Client          $api_client The API client instance.
     * @param InitialSyncManager  $initial_sync_manager The Initial Sync Manager instance.
     */
    public function __construct(
        API_Client $api_client,
        InitialSyncManager $initial_sync_manager
    ) {
        $this->api_client = $api_client;
        $this->initial_sync_manager = $initial_sync_manager; // Store InitialSyncManager

        // Initialize WP Filesystem using the helper
        $this->wp_filesystem = FilesystemHelper::get_wp_filesystem();

        // Ensure dependencies get the progress callback if set later
        $this->set_progress_callback($this->progress_callback);
    }

    /**
     * Set a progress callback function.
     *
     * @param callable|null $callback Function that takes ($subStep, $detail, $stats).
     */
    public function set_progress_callback($callback) {
        $this->progress_callback = $callback;
        // Pass to dependencies that need it
        if ($this->initial_sync_manager) {
            $this->initial_sync_manager->set_progress_callback($callback);
        }
    }

    /**
     * Download a repository archive to a specific directory.
     *
     * @param string $ref        The branch or commit reference.
     * @param string $target_dir The directory to extract to.
     * @return bool|\WP_Error True on success or WP_Error on failure.
     */
    public function download_repository($ref = 'main', $target_dir = '') {
        if (empty($target_dir)) {
            return new \WP_Error('missing_target_dir', __('Target directory not specified.', 'wp-github-sync'));
        }

        if (!$this->wp_filesystem) {
             return new \WP_Error('filesystem_error', __('Could not initialize WordPress filesystem.', 'wp-github-sync'));
        }

        // Create target directory if it doesn't exist
        if (!$this->wp_filesystem->exists($target_dir)) {
            if (!$this->wp_filesystem->mkdir($target_dir, FS_CHMOD_DIR)) {
                 wp_github_sync_log("Failed to create target directory: {$target_dir}", 'error');
                 return new \WP_Error('mkdir_failed', __('Failed to create target directory.', 'wp-github-sync'));
            }
        }

        // Get archive URL
        $archive_url = $this->api_client->get_archive_url($ref);

        // Download the zip file to a temporary file
        $temp_file = download_url($archive_url, 300); // 5 min timeout

        if (is_wp_error($temp_file)) {
            wp_github_sync_log('Failed to download repository archive: ' . $temp_file->get_error_message(), 'error');
            return $temp_file;
        }

        // Extract the zip file using FilesystemHelper
        $result = FilesystemHelper::extract_zip($temp_file, $target_dir);

        // Clean up temp file
        if ($temp_file && $this->wp_filesystem->exists($temp_file)) {
            $this->wp_filesystem->delete($temp_file);
        }

        return $result;
    }

    /**
     * Compare two references (branches or commits) to get the differences.
     *
     * @param string $base The base reference.
     * @param string $head The head reference.
     * @return array|\WP_Error Comparison data or WP_Error on failure.
     */
    public function compare($base, $head) {
        // Ensure owner and repo are set in the API client
        if (empty($this->api_client->get_owner()) || empty($this->api_client->get_repo())) {
             return new \WP_Error('missing_repo_info', __('Repository owner/name not configured in API Client.', 'wp-github-sync'));
        }
        return $this->api_client->request(
            "repos/{$this->api_client->get_owner()}/{$this->api_client->get_repo()}/compare/{$base}...{$head}"
        );
    }

    /**
     * Start the initial sync process (pushing WP files to GitHub).
     * Delegates to InitialSyncManager.
     *
     * @param string $branch The branch name to commit to.
     * @return bool|\WP_Error True if chunking started, WP_Error on immediate failure or completion.
     */
    public function initial_sync($branch = 'main') {
        return $this->initial_sync_manager->start_initial_sync($branch);
    }

    /**
     * Continue a chunked sync process based on saved state.
     * Delegates to InitialSyncManager.
     *
     * @param array $sync_state The saved sync state
     * @param string $branch The branch name to commit to
     * @return bool|\WP_Error True on success or WP_Error on failure, null if processing continues.
     */
    public function continue_chunked_sync($sync_state, $branch) {
        return $this->initial_sync_manager->continue_chunked_sync($sync_state, $branch);
    }

    // --- All other methods related to initial sync, chunking, file processing, etc., ---
    // --- have been moved to InitialSyncManager or FilesystemHelper. ---

} // End class Repository
