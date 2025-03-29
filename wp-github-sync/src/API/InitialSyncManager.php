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
     * @param Repository_Uploader $uploader   The Repository Uploader instance (may become redundant).
     * @param BlobCreator         $blob_creator The Blob Creator instance.
     * @param TreeBuilder         $tree_builder The Tree Builder instance.
     * @param BranchManager       $branch_manager The Branch Manager instance.
     */
    public function __construct(
        API_Client $api_client,
        Repository_Uploader $uploader, // Keep uploader for now if other methods use it, or remove if fully redundant
        GitData\BlobCreator $blob_creator,
        GitData\TreeBuilder $tree_builder,
        GitData\BranchManager $branch_manager
     ) {
        $this->api_client = $api_client;
        $this->uploader = $uploader;
        $this->blob_creator = $blob_creator; // Store injected instance
        $this->tree_builder = $tree_builder; // Store injected instance
        $this->branch_manager = $branch_manager; // Store injected instance
        $this->wp_filesystem = FilesystemHelper::get_wp_filesystem();
        $this->state_manager = new SyncStateManager(); // Instantiate state manager

        // Pass progress callback to relevant helpers if needed
        $this->set_progress_callback($this->progress_callback);
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
            $base_commit_tree_sha = ''; // Store the base tree SHA
            $base_commit_sha = ''; // Store the base commit SHA
            $repo_info = $this->api_client->get_repository();
            if (is_wp_error($repo_info)) {
                $error_message = $repo_info->get_error_message();
                if (strpos($error_message, 'Git Repository is empty') !== false || strpos($error_message, 'Not Found') !== false || strpos($error_message, '404') !== false) {
                    $init_needed = true;
                } else {
                    throw new \Exception(sprintf(__('Failed to access repository: %s', 'wp-github-sync'), $error_message));
                }
            } else {
                 // Get the base commit tree SHA if repo exists
                 $default_branch = $repo_info['default_branch'] ?? 'main';
                 $branch_sha_result = $this->api_client->get_branch_sha($branch ?: $default_branch); // Use specified branch or default
                 if (!is_wp_error($branch_sha_result)) {
                     $base_commit_sha = $branch_sha_result; // This is the commit SHA
                     $commit_info = $this->api_client->request("repos/{$this->api_client->get_owner()}/{$this->api_client->get_repo()}/git/commits/{$base_commit_sha}");
                     if (!is_wp_error($commit_info) && isset($commit_info['tree']['sha'])) {
                         $base_commit_tree_sha = $commit_info['tree']['sha'];
                         wp_github_sync_log("Found base commit SHA: {$base_commit_sha} and tree SHA: {$base_commit_tree_sha} for branch '{$branch}'", 'debug');
                     } else {
                          wp_github_sync_log("Could not get base commit tree SHA for branch '{$branch}'. Proceeding without base tree.", 'warning');
                     }
                 } else {
                      wp_github_sync_log("Could not get branch SHA for '{$branch}'. Proceeding without base tree.", 'warning');
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
                // After init, the base tree and commit are likely empty or just have README
                $base_commit_tree_sha = ''; // Reset base tree SHA after init
                $base_commit_sha = ''; // Reset base commit SHA after init
            }
            // Save the base commit and tree SHA (even if empty) to the state
            $this->state_manager->update_state([
                'stage' => 'prepare_temp_directory',
                'progress_step' => 3,
                'base_commit_sha' => $base_commit_sha, // Save base commit SHA
                'base_commit_tree_sha' => $base_commit_tree_sha // Save base tree SHA
            ]);

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
        // Adjusted check: temp_dir is needed for scanning_temp_dir and creating_blobs stages
        if (in_array($sync_state['stage'], ['scanning_temp_dir', 'creating_blobs']) && (empty($temp_dir) || !$this->wp_filesystem->is_dir($temp_dir))) {
             return $this->handle_chunk_error($sync_state, __('Temporary directory missing or invalid for current stage.', 'wp-github-sync'));
        }
        // Removed check for 'collecting_files' as temp_dir might not be needed if collection is done.
        // Removed check for 'uploading_files' as it's replaced by new stages.


        // Environment setup using helper
        InitialSyncHelper::prepare_environment();

        try {
            switch ($sync_state['stage']) {
                case 'collecting_files':
                    $result = $this->process_chunked_file_collection($sync_state, $branch);
                    break;
                case 'scanning_temp_dir': // New stage
                    $result = $this->process_scan_temp_dir($sync_state);
                    break;
                case 'creating_blobs': // New stage
                    $result = $this->process_blob_creation_chunk($sync_state);
                    break;
                case 'preparing_tree_items': // New stage
                    $result = $this->process_prepare_tree_items($sync_state);
                    break;
                case 'creating_tree': // New stage (now only handles API calls)
                    $result = $this->process_tree_creation_chunk($sync_state);
                    break;
                case 'creating_commit': // New stage
                    $result = $this->process_commit_creation($sync_state, $branch);
                    break;
                // Removed 'uploading_files' stage as it's now broken down
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
            // All paths processed, move to scan the collected files before upload stages
            $this->state_manager->update_state(['stage' => 'scanning_temp_dir', 'progress_step' => 6]); // Use state manager
            $this->update_progress(6, "File collection complete, scanning files...");
            return null; // Continue to next stage (scanning) in the next chunk
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
     * Scan the temporary directory to build the list of files to process.
     */
    private function process_scan_temp_dir($sync_state) {
        $temp_dir = $sync_state['temp_dir'];
        wp_github_sync_log("Scanning temporary directory for files: {$temp_dir}", 'info');
        $this->update_progress(6, "Scanning collected files...");

        $files_to_process = [];
        $total_files = 0;
        $skipped_files = []; // Track skipped files during scan

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($temp_dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            $temp_dir_normalized = FilesystemHelper::normalize_path($temp_dir);
            $temp_dir_prefix_len = strlen(trailingslashit($temp_dir_normalized));

            foreach ($iterator as $item) {
                // Skip directories
                if ($item->isDir()) {
                    continue;
                }

                $full_path = FilesystemHelper::normalize_path($item->getPathname());
                // Calculate relative path within the temp dir, which corresponds to the GitHub path
                $github_relative_path = substr($full_path, $temp_dir_prefix_len);

                // Basic safety/ignore checks (redundant but safe)
                 if (strpos($item->getFilename(), '.') === 0 || in_array($item->getFilename(), ['node_modules', 'vendor', '.git', 'cache'])) {
                    wp_github_sync_log("Skipping excluded file during scan: {$github_relative_path}", 'debug');
                    $skipped_files[] = ['path' => $github_relative_path, 'reason' => 'excluded'];
                    continue;
                }
                 if (!FilesystemHelper::is_safe_path($github_relative_path)) {
                    wp_github_sync_log("Skipping unsafe path during scan: {$github_relative_path}", 'warning');
                    $skipped_files[] = ['path' => $github_relative_path, 'reason' => 'unsafe_path'];
                    continue;
                }

                // Determine file mode
                // Note: WP_Filesystem doesn't have a built-in is_executable. Use PHP's function.
                // This might not be reliable depending on filesystem permissions and PHP setup.
                $file_mode = is_executable($full_path) ? '100755' : '100644';

                $files_to_process[] = [
                    'local_full_path' => $full_path,
                    'github_relative_path' => $github_relative_path,
                    'mode' => $file_mode, // Store the mode
                ];
                $total_files++;
            }

        } catch (\Exception $e) {
             wp_github_sync_log("Exception during temporary directory scan: " . $e->getMessage(), 'error');
             throw new \Exception("Failed to scan temporary directory: " . $e->getMessage());
        }

        wp_github_sync_log("Scan complete. Found {$total_files} files to process.", 'info');
        if (!empty($skipped_files)) {
             wp_github_sync_log("Skipped " . count($skipped_files) . " files during scan.", 'warning');
             // Optionally store skipped files info
             // $this->state_manager->update_state(['scan_skipped_files' => $skipped_files]);
        }

        // Update state and move to blob creation
        $this->state_manager->update_state([
            'files_to_process' => $files_to_process,
            'total_files_to_process' => $total_files,
            'processed_file_index' => 0,
            'created_blobs' => [], // Reset blob list
            'stage' => 'creating_blobs',
            'progress_step' => 7,
        ]);
        $this->update_progress(7, "Starting blob creation for {$total_files} files...");

        return null; // Indicate processing continues in the next chunk
    }

    /**
     * Process a chunk of blob creation.
     */
    private function process_blob_creation_chunk($sync_state) {
        $files_to_process = $sync_state['files_to_process'] ?? [];
        $current_index = $sync_state['processed_file_index'] ?? 0;
        $created_blobs = $sync_state['created_blobs'] ?? [];
        $total_files = $sync_state['total_files_to_process'] ?? count($files_to_process);

        // Define how many blobs to create per chunk
        $blobs_per_chunk = apply_filters('wp_github_sync_blobs_per_chunk', 50);
        $processed_in_chunk = 0;

        // Use the injected BlobCreator instance
        $blob_creator = $this->blob_creator;


        wp_github_sync_log("Processing blob creation chunk starting from index {$current_index}", 'debug');

        while ($current_index < $total_files && $processed_in_chunk < $blobs_per_chunk) {
            $file_info = $files_to_process[$current_index];
            $local_path = $file_info['local_full_path'];
            $github_path = $file_info['github_relative_path'];

            $this->update_progress(7, "Creating blob " . ($current_index + 1) . "/{$total_files}: {$github_path}");

            $blob_result = $blob_creator->create_blob($local_path, $github_path);

            if (is_wp_error($blob_result)) {
                // Log error but continue processing other files in the chunk
                wp_github_sync_log("Failed to create blob for {$github_path}: " . $blob_result->get_error_message(), 'error');
                // Optionally store skipped file info in state
                // $sync_state['skipped_blobs'][] = ['path' => $github_path, 'reason' => $blob_result->get_error_message()];
            } else {
                // Store successful blob SHA mapped to its GitHub path
                $created_blobs[$github_path] = $blob_result['sha'];
            }

            $current_index++;
            $processed_in_chunk++;
        }

        // Update state with progress and created blobs for this chunk
        $this->state_manager->update_state([
            'processed_file_index' => $current_index,
            'created_blobs' => $created_blobs,
            // Optionally update skipped blobs: 'skipped_blobs' => $sync_state['skipped_blobs'] ?? []
        ]);

        // Check if all files have been processed
        if ($current_index >= $total_files) {
            // All blobs created, move to prepare the full tree item list
            wp_github_sync_log("Blob creation complete. Processed {$total_files} files.", 'info');
            $this->state_manager->update_state([
                'stage' => 'preparing_tree_items', // New stage
                'progress_step' => 8,
                // 'tree_items_batch' => [], // No longer needed here
                // 'last_tree_sha' => '', // No longer needed here
            ]);
            $this->update_progress(8, "Preparing Git tree structure...");
            return null; // Continue to next stage in the next chunk
        } else {
            // More blobs to create, schedule next chunk for this stage
            $this->update_progress(7, "Processed blob chunk, " . ($total_files - $current_index) . " remaining...");
            return null; // Indicate processing continues (blob creation)
        }
    }

    /**
     * Prepare the full list of tree items from created blobs and file info.
     */
    private function process_prepare_tree_items($sync_state) {
        $created_blobs = $sync_state['created_blobs'] ?? [];
        $files_info = $sync_state['files_to_process'] ?? []; // Contains path and mode

        wp_github_sync_log("Preparing tree items from " . count($created_blobs) . " created blobs.", 'info');
        $this->update_progress(8, "Building Git tree structure...");

        $tree_items = [];
        $files_info_map = [];

        // Create a map for quick lookup of file info by relative path
        foreach ($files_info as $info) {
            $files_info_map[$info['github_relative_path']] = $info;
        }

        foreach ($created_blobs as $github_path => $blob_sha) {
            // Find the corresponding file mode
            $mode = '100644'; // Default mode
            if (isset($files_info_map[$github_path]['mode'])) {
                $mode = $files_info_map[$github_path]['mode'];
            } else {
                 wp_github_sync_log("Could not find mode info for blob: {$github_path}. Defaulting to 100644.", 'warning');
            }

            $tree_items[] = [
                'path' => $github_path,
                'mode' => $mode,
                'type' => 'blob',
                'sha' => $blob_sha,
            ];
        }

        if (empty($tree_items)) {
             throw new \Exception("No valid tree items could be prepared from created blobs.");
        }

        // Sort tree items by path as recommended by GitHub API docs (though not strictly required)
        usort($tree_items, function ($a, $b) {
            return strcmp($a['path'], $b['path']);
        });

        // Update state with the full list and move to tree creation chunks
        $this->state_manager->update_state([
            'full_tree_items' => $tree_items, // Store the complete list
            'processed_tree_item_index' => 0, // Index for chunking tree creation
            'stage' => 'creating_tree',
            'progress_step' => 9,
            'last_tree_sha' => '', // Reset last tree SHA before starting tree chunks
        ]);
        $this->update_progress(9, "Starting Git tree creation...");

        return null; // Continue to next stage in the next chunk
    }

    /**
     * Process a chunk of tree creation using the API.
     */
    private function process_tree_creation_chunk($sync_state) {
        $full_tree_items = $sync_state['full_tree_items'] ?? [];
        $current_index = $sync_state['processed_tree_item_index'] ?? 0;
        $last_tree_sha = $sync_state['last_tree_sha'] ?? ''; // Base tree for this chunk
        $total_items = count($full_tree_items);

        // Define how many tree items to send per API call chunk
        $items_per_chunk = apply_filters('wp_github_sync_tree_items_per_chunk', 500);

        // Get the batch for this chunk
        $batch_items = array_slice($full_tree_items, $current_index, $items_per_chunk);

        if (empty($batch_items)) {
            // Should not happen if previous stage logic is correct, but handle defensively
            wp_github_sync_log("Tree creation: No items found in current batch (index {$current_index}). Moving to commit stage.", 'warning');
            $this->state_manager->update_state([
                'stage' => 'creating_commit',
                'progress_step' => 10,
                // Ensure last_tree_sha is carried over from the final successful chunk
            ]);
            $this->update_progress(10, "Preparing final commit...");
            return null; // Continue to commit stage
        }

        // Use the injected TreeBuilder instance
        $tree_builder = $this->tree_builder;

        $this->update_progress(9, "Creating Git tree chunk " . floor($current_index / $items_per_chunk) + 1 . "/" . ceil($total_items / $items_per_chunk));
        wp_github_sync_log("Processing tree creation chunk starting from index {$current_index} with " . count($batch_items) . " items.", 'debug');

        // Call the TreeBuilder to create this chunk
        // Determine the base tree: Use the repo's base commit tree for the very first chunk if last_tree_sha is empty,
        // otherwise use the SHA from the previous chunk.
        $base_tree_for_api = $last_tree_sha;
        if (empty($base_tree_for_api) && isset($sync_state['base_commit_tree_sha'])) { // Need to ensure base_commit_tree_sha is saved in state earlier
             // Use the original base commit tree SHA fetched during setup if this is the first tree chunk
             $base_tree_for_api = $sync_state['base_commit_tree_sha'];
             wp_github_sync_log("Using original base commit tree SHA {$base_tree_for_api} for first tree chunk.", 'debug');
        }


        $new_tree_sha_result = $tree_builder->create_single_tree_api_call($batch_items, $base_tree_for_api);

        if (is_wp_error($new_tree_sha_result)) {
            // Error creating tree chunk
            throw new \Exception("Failed to create tree chunk: " . $new_tree_sha_result->get_error_message());
        }

        $new_tree_sha = $new_tree_sha_result; // Result is the new SHA string
        $next_index = $current_index + count($batch_items);

        // Update state with the new tree SHA and progress
        $this->state_manager->update_state([
            'processed_tree_item_index' => $next_index,
            'last_tree_sha' => $new_tree_sha, // This becomes the base for the next chunk
        ]);

        // Check if all items have been processed
        if ($next_index >= $total_items) {
            // All tree chunks created, move to commit stage
            wp_github_sync_log("Tree creation complete. Final tree SHA: {$new_tree_sha}", 'info');
            $this->state_manager->update_state([
                'stage' => 'creating_commit',
                'progress_step' => 10,
                // last_tree_sha is already updated with the final tree SHA
            ]);
            $this->update_progress(10, "Preparing final commit...");
            return null; // Continue to next stage in the next chunk
        } else {
            // More tree items to process, schedule next chunk for this stage
            $this->update_progress(9, "Processed tree chunk, " . ($total_items - $next_index) . " items remaining...");
            return null; // Indicate processing continues (tree creation)
        }
    }

    /**
     * Process the final commit creation and branch update stage.
     */
    private function process_commit_creation($sync_state, $branch) {
        $final_tree_sha = $sync_state['last_tree_sha'] ?? '';
        $base_commit_sha = $sync_state['base_commit_sha'] ?? ''; // Parent commit
        $site_name = get_bloginfo('name'); // Get site name for commit message
        $commit_message = apply_filters('wp_github_sync_initial_commit_message', "Initial sync from {$site_name}");

        if (empty($final_tree_sha)) {
            throw new \Exception("Final tree SHA is missing, cannot create commit.");
        }

        $this->update_progress(10, "Creating final commit...");
        wp_github_sync_log("Creating final commit with tree SHA: {$final_tree_sha}", 'info');

        // Prepare commit data
        $commit_data = [
            'message' => $commit_message,
            'tree' => $final_tree_sha,
        ];
        // Add parent commit SHA if it exists (not the very first commit in an empty repo)
        if (!empty($base_commit_sha)) {
            $commit_data['parents'] = [$base_commit_sha];
        } else {
             wp_github_sync_log("No base commit SHA found, creating commit without parent (initial commit).", 'debug');
        }

        // Create the commit object
        $new_commit = $this->api_client->request(
            "repos/{$this->api_client->get_owner()}/{$this->api_client->get_repo()}/git/commits",
            'POST',
            $commit_data
        );

        if (is_wp_error($new_commit)) {
            throw new \Exception('Failed to create commit: ' . $new_commit->get_error_message());
        }
        $new_commit_sha = $new_commit['sha'];
        wp_github_sync_log("Created new commit with SHA: {$new_commit_sha}", 'info');

        // Update Branch Reference
        $this->update_progress(11, "Updating branch reference...");
        wp_github_sync_log("Updating branch '{$branch}' to point to commit {$new_commit_sha}", 'info');

        // Use the injected BranchManager instance
        $branch_manager = $this->branch_manager;

        $update_result = $branch_manager->update_branch_reference($branch, $new_commit_sha);
        if (is_wp_error($update_result)) {
            // Log error, but maybe don't fail the whole process? Or should we?
            // If the commit was created but ref update fails, repo is slightly inconsistent.
            wp_github_sync_log("Failed to update branch reference '{$branch}': " . $update_result->get_error_message(), 'error');
            // Let's throw exception for now to indicate failure clearly.
            throw new \Exception('Commit created, but failed to update branch reference: ' . $update_result->get_error_message());
        }

        // Final Success Update
        $this->update_progress(12, __('Initial sync completed successfully!', 'wp-github-sync'), ['status' => 'complete']);
        wp_github_sync_log("Successfully completed initial sync. Branch '{$branch}' updated to commit {$new_commit_sha}", 'info');

        // Store this commit as the last pushed commit
        update_option('wp_github_sync_last_pushed_commit', $new_commit_sha);

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
