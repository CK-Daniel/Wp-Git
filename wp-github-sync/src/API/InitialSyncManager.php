<?php
/**
 * Handles the Initial Sync process for the WordPress GitHub Sync plugin.
 * Manages chunking, file collection, state, and upload orchestration.
 * Delegates state management to SyncStateManager and helper functions to InitialSyncHelper.
 *
 * @package WPGitHubSync\API
 */

namespace WPGitHubSync\API;

use WPGitHubSync\Utils\FilesystemHelper;
// Use the new state manager and helper
use WPGitHubSync\API\SyncStateManager;
use WPGitHubSync\API\InitialSyncHelper;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Initial Sync Manager class.
 */
class InitialSyncManager {

    /**
     * API Client instance.
     * @var API_Client
     */
    private $api_client;

    /**
     * Repository Uploader instance.
     * @var Repository_Uploader
     */
    private $uploader;

    /**
     * Sync State Manager instance.
     * @var SyncStateManager
     */
    private $state_manager;

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
     * Statistics and reporting data (kept for overall process stats if needed).
     * @var array
     */
    private $stats = [];

    /**
     * Constructor.
     *
     * @param API_Client          $api_client The API client instance.
     * @param Repository_Uploader $uploader   The Repository Uploader instance.
     */
    public function __construct(API_Client $api_client, Repository_Uploader $uploader) {
        $this->api_client = $api_client;
        $this->uploader = $uploader;
        $this->wp_filesystem = FilesystemHelper::get_wp_filesystem();
        $this->state_manager = new SyncStateManager(); // Instantiate state manager
    }

    /**
     * Set a progress callback function.
     *
     * @param callable|null $callback Function that takes ($subStep, $detail, $stats).
     */
    public function set_progress_callback($callback) {
        $this->progress_callback = $callback;
        if ($this->uploader) {
            $this->uploader->set_progress_callback($callback);
        }
    }

    /**
     * Update progress via callback.
     *
     * @param int    $subStep Sub-step number.
     * @param string $detail  Progress detail message.
     * @param array  $stats   Optional stats array.
     */
    private function update_progress($subStep, $detail, $stats = []) {
        if (is_callable($this->progress_callback)) {
            // Ensure status is included if not provided
            if (!isset($stats['status'])) {
                 $current_progress = get_option(SyncStateManager::PROGRESS_OPTION, []);
                 $stats['status'] = $current_progress['status'] ?? 'running';
            }
            call_user_func($this->progress_callback, $subStep, $detail, $stats);
        }
    }

    /**
     * Start the initial sync process (pushing WP files to GitHub).
     * Sets up chunked state and schedules the first chunk.
     *
     * @param string $branch The branch name to commit to.
     * @return bool|\WP_Error True if chunking started, WP_Error on immediate failure.
     */
    public function start_initial_sync($branch = 'main') {
        // Check if already running using state manager
        if ($this->state_manager->is_sync_running()) {
             return new \WP_Error('sync_already_running', __('An initial sync process is already running.', 'wp-github-sync'));
        }

        // Environment setup using helper
        InitialSyncHelper::prepare_environment();

        // Reset statistics
        $this->reset_stats();

        // Initialize state using state manager
        $chunked_sync_state = $this->state_manager->initialize_state($branch);

        // Register shutdown function for fatal error handling using state manager
        // Note: Passing error_get_last() directly might not work reliably in register_shutdown_function.
        // It's better to call error_get_last() inside the shutdown function itself.
        register_shutdown_function(function() {
             $error = error_get_last();
             if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
                 $this->state_manager->mark_fatal_error($error);
             }
        });


        // --- Start of actual sync logic ---
        try {
            // 1. Verify Authentication
            $this->update_progress(0, "Verifying authentication");
            $auth_test = $this->api_client->test_authentication();
            if ($auth_test !== true) {
                throw new \Exception(sprintf(__('GitHub authentication failed: %s', 'wp-github-sync'), is_string($auth_test) ? $auth_test : 'Unknown error'));
            }
            $this->update_progress(1, "Authentication verified successfully");
            $this->state_manager->update_state(['stage' => 'repository_check', 'progress_step' => 1]); // Use state manager

            // 2. Verify Repository & Initialize if needed
            $this->update_progress(2, "Verifying repository access");
            $init_needed = false;
            $repo_info = $this->api_client->get_repository();
            if (is_wp_error($repo_info)) {
                $error_message = $repo_info->get_error_message();
                if (strpos($error_message, 'Git Repository is empty') !== false || strpos($error_message, 'Not Found') !== false || strpos($error_message, '404') !== false) {
                    $init_needed = true;
                } else {
                    throw new \Exception(sprintf(__('Failed to access repository: %s', 'wp-github-sync'), $error_message));
                }
            }

            if ($init_needed) {
                $this->update_progress(2, "Repository is empty, performing initialization");
                $this->update_progress(3, "Creating initial repository structure");
                $init_result = $this->api_client->initialize_repository($branch); // API Client handles initialization now
                if (is_wp_error($init_result)) {
                     throw new \Exception(sprintf(__('Failed to initialize repository: %s', 'wp-github-sync'), $init_result->get_error_message()));
                }
                wp_github_sync_log("Repository initialized successfully, continuing with sync", 'info');
            }
            $this->state_manager->update_state(['stage' => 'prepare_temp_directory', 'progress_step' => 3]); // Use state manager

            // 3. Prepare Temporary Directory
            $this->update_progress(3, "Preparing temporary directory");
            if (!$this->wp_filesystem) {
                throw new \Exception(__('Could not initialize WordPress filesystem.', 'wp-github-sync'));
            }
            $temp_dir_base = trailingslashit($this->wp_filesystem->wp_content_dir()) . 'upgrade/';
            $temp_dir = $temp_dir_base . 'wp-github-sync-init-' . wp_generate_password(12, false);

            if (!$this->wp_filesystem->mkdir($temp_dir, FS_CHMOD_DIR)) {
                throw new \Exception(sprintf(__('Failed to create temporary directory: %s', 'wp-github-sync'), $temp_dir));
            }
            if (!$this->wp_filesystem->is_dir($temp_dir)) {
                 FilesystemHelper::recursive_rmdir($temp_dir); // Attempt cleanup
                 throw new \Exception(sprintf(__('Temporary directory not accessible after creation: %s', 'wp-github-sync'), $temp_dir));
            }
            $this->state_manager->update_state(['temp_dir' => $temp_dir]); // Use state manager

            // 4. Define Paths and Start Chunking
            $paths_to_sync = apply_filters('wp_github_sync_paths', [
                'wp-content/themes' => true,
                'wp-content/plugins' => true,
                'wp-content/uploads' => false, // Default to not sync media
            ]);
            $this->state_manager->update_state([ // Use state manager
                'paths_to_sync' => $paths_to_sync,
                'stage' => 'collecting_files',
                'progress_step' => 4,
                'current_path_index' => 0
            ]);
            wp_github_sync_log("Starting chunked file processing", 'info');
            $this->update_progress(4, "Starting chunked file collection");

            // Schedule the first chunk using helper
            InitialSyncHelper::schedule_next_chunk();

            // Return special error to indicate background processing
            return new \WP_Error(
                'sync_in_progress',
                __('The initial sync has started and will continue in the background. This may take several minutes depending on the size of your site.', 'wp-github-sync')
            );

        } catch (\Exception $e) {
            wp_github_sync_log("Initial sync setup failed: " . $e->getMessage(), 'error');
            $this->update_progress(0, "Initial sync failed: " . $e->getMessage(), ['status' => 'failed']);
            $this->state_manager->cleanup_state(); // Use state manager for cleanup
            return new \WP_Error('initial_sync_failed', $e->getMessage());
        }
    }

    /**
     * Continue a chunked sync process based on saved state.
     * This is the entry point for subsequent chunk processing.
     *
     * @param array  $sync_state The saved sync state from WP options.
     * @param string $branch     The branch name being synced.
     * @return bool|\WP_Error True on success/completion, WP_Error on failure, null if processing continues.
     */
    public function continue_chunked_sync($sync_state, $branch) {
        wp_github_sync_log("Continuing chunked sync process at stage: " . ($sync_state['stage'] ?? 'unknown'), 'info');

        // Check for fatal error flag using state manager
        if ($this->state_manager->has_fatal_error($sync_state)) {
            wp_github_sync_log("Detected fatal error flag in sync state, attempting recovery or cleanup.", 'error');
            $this->state_manager->cleanup_state($sync_state);
            return $this->handle_chunk_error($sync_state, __('Sync failed due to a fatal error. Please check logs.', 'wp-github-sync'));
        }

        // Ensure filesystem is ready
        if (!$this->wp_filesystem) {
            return $this->handle_chunk_error($sync_state, __('Could not initialize WordPress filesystem.', 'wp-github-sync'));
        }

        // Ensure temp dir exists if needed for the stage
        $temp_dir = $sync_state['temp_dir'] ?? '';
        if (in_array($sync_state['stage'], ['collecting_files', 'uploading_files']) && (empty($temp_dir) || !$this->wp_filesystem->is_dir($temp_dir))) {
             return $this->handle_chunk_error($sync_state, __('Temporary directory missing or invalid.', 'wp-github-sync'));
        }

        // Environment setup using helper
        InitialSyncHelper::prepare_environment();

        try {
            switch ($sync_state['stage']) {
                case 'collecting_files':
                    $result = $this->process_chunked_file_collection($sync_state, $branch);
                    break;
                case 'uploading_files':
                    $result = $this->process_chunked_upload($sync_state, $branch);
                    break;
                default:
                    return $this->handle_chunk_error($sync_state, __('Unknown chunked sync stage', 'wp-github-sync'));
            }

            // If result is true, sync is fully complete
            if ($result === true) {
                wp_github_sync_log("Chunked sync process fully completed.", 'info');
                $this->state_manager->cleanup_state($sync_state); // Use state manager
                // Final progress update should have happened in the last step
                return true;
            }
            // If result is WP_Error, handle error
            elseif (is_wp_error($result)) {
                return $this->handle_chunk_error($sync_state, $result->get_error_message());
            }
            // Otherwise (result is null or indicates continuation), schedule next chunk using helper
            else {
                InitialSyncHelper::schedule_next_chunk();
                return null; // Indicate processing is ongoing
            }

        } catch (\Exception $e) {
            return $this->handle_chunk_error($sync_state, $e->getMessage(), $e);
        }
    }

    // --- Private Chunk Processing Methods ---

    /**
     * Process a chunk of file collection.
     */
    private function process_chunked_file_collection($sync_state, $branch) {
        $temp_dir = $sync_state['temp_dir'];
        $paths_to_sync = $sync_state['paths_to_sync'];
        $current_path_index = $sync_state['current_path_index'] ?? 0;
        $path_keys = array_keys($paths_to_sync);

        if (!isset($path_keys[$current_path_index])) {
            // All paths processed, move to upload stage
            $this->state_manager->update_state(['stage' => 'uploading_files', 'progress_step' => 6]); // Use state manager
            $this->update_progress(6, "File collection complete, preparing upload.");
            return null; // Continue to next stage in the next chunk
        }

        $current_path = $path_keys[$current_path_index];
        $include = $paths_to_sync[$current_path];

        if (!$include) {
            // Skip disabled path
            $this->state_manager->update_state(['current_path_index' => $current_path_index + 1]); // Use state manager
            return null; // Continue to next path in the next chunk
        }

        $this->update_progress(5, "Processing directory " . ($current_path_index + 1) . "/" . count($path_keys) . ": " . $current_path);
        wp_github_sync_log("Processing directory chunk: {$current_path}", 'info');

        // Determine source path
        $wp_content_dir = $this->wp_filesystem->wp_content_dir();
        if (strpos($current_path, 'wp-content/') === 0) {
            $rel_path = substr($current_path, strlen('wp-content/'));
            $source_path = trailingslashit($wp_content_dir) . $rel_path;
        } else {
            wp_github_sync_log("Skipping invalid path (must be within wp-content): {$current_path}", 'warning');
            $this->state_manager->update_state(['current_path_index' => $current_path_index + 1]); // Use state manager
            return null;
        }

        // Validate source path
        if (!FilesystemHelper::is_safe_path($source_path) || !$this->wp_filesystem->exists($source_path) || !FilesystemHelper::is_within_wordpress($source_path)) {
            wp_github_sync_log("Skipping invalid or non-existent source path: {$source_path}", 'warning');
            $this->state_manager->update_state(['current_path_index' => $current_path_index + 1]); // Use state manager
            return null;
        }

        // Prepare destination path
        $dest_path = trailingslashit($temp_dir) . FilesystemHelper::normalize_path($current_path);
        $dest_parent_dir = dirname($dest_path);
        if (!$this->wp_filesystem->exists($dest_parent_dir)) {
            if (!$this->wp_filesystem->mkdir($dest_parent_dir, FS_CHMOD_DIR)) {
                 throw new \Exception("Failed to create destination parent directory: {$dest_parent_dir}");
            }
        }

        // Copy directory using FilesystemHelper
        $copy_result = FilesystemHelper::copy_directory($source_path, $dest_path);
        if (is_wp_error($copy_result)) {
            wp_github_sync_log("Failed to copy {$current_path}: " . $copy_result->get_error_message(), 'error');
            // Log error but continue to next path
        } else {
            wp_github_sync_log("Successfully copied {$current_path} to temporary directory", 'debug');
        }

        // Move to the next path for the next chunk
        $this->state_manager->update_state(['current_path_index' => $current_path_index + 1]); // Use state manager
        return null; // Indicate processing continues
    }

    /**
     * Process the upload stage (single chunk).
     */
    private function process_chunked_upload($sync_state, $branch) {
        $temp_dir = $sync_state['temp_dir'];
        $site_name = $sync_state['site_name'] ?? get_bloginfo('name');

        $this->update_progress(7, "Uploading files to GitHub");
        wp_github_sync_log("Starting upload to GitHub from {$temp_dir}", 'info');

        // Upload files using Repository_Uploader
        $result = $this->uploader->upload_files_to_github(
            $temp_dir,
            $branch,
            "Initial sync from {$site_name}"
        );

        // Cleanup happens regardless of result in continue_chunked_sync

        if (is_wp_error($result)) {
            throw new \Exception(sprintf(__('Failed to upload to GitHub: %s', 'wp-github-sync'), $result->get_error_message()));
        }

        $this->update_progress(8, "Initial sync completed successfully", ['status' => 'complete']);
        wp_github_sync_log("Initial sync upload completed successfully", 'info');
        return true; // Indicate completion
    }

    // --- Helper Methods ---

    /**
     * Handle errors during chunk processing.
     *
     * @param array      $sync_state The current sync state.
     * @param string     $message    The error message.
     * @param \Exception|null $exception Optional exception object.
     * @return \WP_Error The WP_Error object representing the failure.
     */
    private function handle_chunk_error($sync_state, $message, $exception = null) {
        wp_github_sync_log("Error during chunked sync stage '{$sync_state['stage']}': " . $message, 'error');
        if ($exception) {
            wp_github_sync_log("Stack trace: " . $exception->getTraceAsString(), 'error');
        }
        $this->update_progress($sync_state['progress_step'] ?? 0, "Error: " . $message, ['status' => 'failed']);
        $this->state_manager->cleanup_state($sync_state); // Use state manager
        return new \WP_Error('chunked_sync_error', $message);
    }

    /**
     * Reset internal statistics.
     */
    private function reset_stats() {
        $this->stats = [
            'start_time' => microtime(true),
            'files_scanned' => 0,
            'files_skipped' => 0,
            'files_included' => 0,
            'total_size' => 0,
            'large_files_found' => 0,
            'errors' => [],
            'warnings' => [],
            'skipped_files' => []
        ];
    }

    // --- Removed Methods (Moved to InitialSyncHelper or SyncStateManager) ---
    // Removed: prepare_environment()
    // Removed: register_fatal_error_handler()
    // Removed: update_chunk_state()
    // Removed: schedule_next_chunk()
    // Removed: cleanup_sync_state()
    // Removed: get_package_type()
    // Removed: get_package_items()
    // Removed: get_relative_path_for_github()
    // Removed: copy_package_directory()
    // Removed: prepare_files_for_initial_sync()
    // Removed: create_standard_repo_files()

} // End class InitialSyncManager
