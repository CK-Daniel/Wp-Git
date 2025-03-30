<?php
/**
 * Helper functions specifically for the Initial Sync process.
 *
 * @package WPGitHubSync\API
 */

namespace WPGitHubSync\API;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Initial Sync Helper class.
 */
class InitialSyncHelper {

    /**
     * Determine package type from path.
     *
     * @param string $package_path The path relative to wp-content (e.g., 'themes', 'plugins/my-plugin').
     * @return string The package type ('theme', 'plugin', 'upload', 'misc').
     */
    public static function get_package_type(string $package_path): string {
        if (strpos($package_path, 'themes') === 0) return 'theme';
        if (strpos($package_path, 'plugins') === 0) return 'plugin';
        if (strpos($package_path, 'uploads') === 0) return 'upload';
        return 'misc';
    }

    /**
     * Get individual items (subdirectories or files) within a package source directory.
     *
     * @param string $source_path  The absolute path to the source directory (e.g., /path/to/wp-content/plugins).
     * @param string $package_type The type determined by get_package_type().
     * @return array An array of absolute paths to the items within the source directory.
     */
    public static function get_package_items(string $source_path, string $package_type): array {
        $items = [];
        if (!is_dir($source_path)) {
            wp_github_sync_log("InitialSyncHelper: Source path is not a directory: {$source_path}", 'warning');
            return $items;
        }

        $contents = scandir($source_path);
        if ($contents === false) {
             wp_github_sync_log("InitialSyncHelper: Failed to scan directory: {$source_path}", 'error');
             return $items;
        }

        foreach ($contents as $item) {
            if ($item === '.' || $item === '..') continue;

            $item_path = $source_path . '/' . $item;

            // For themes and plugins, each immediate subdirectory is an item.
            // For uploads or misc, each file/directory could potentially be an item (adjust as needed).
            if (is_dir($item_path) && ($package_type === 'theme' || $package_type === 'plugin')) {
                $items[] = $item_path;
            } elseif ($package_type === 'upload' || $package_type === 'misc') {
                 // Decide how to handle uploads/misc - maybe sync top-level items?
                 // For now, let's include both files and dirs at the top level of uploads/misc.
                 $items[] = $item_path;
            }
            // Add logic here if you need to handle files directly under themes/plugins differently.
        }
        return $items;
    }

    /**
     * Generate the relative path within the GitHub repository for a subpackage.
     * Assumes the target structure in GitHub mirrors the wp-content structure.
     *
     * @param string $package_path    The package path relative to wp-content (e.g., 'themes', 'plugins').
     * @param string $subpackage_name The name of the specific item (e.g., 'twentytwentyone', 'my-cool-plugin').
     * @return string The relative path for GitHub (e.g., 'wp-content/themes/twentytwentyone').
     */
    public static function get_relative_path_for_github(string $package_path, string $subpackage_name): string {
        // Ensure package_path starts with 'wp-content/' for consistency in the repo.
        $base = 'wp-content/';
        if (strpos($package_path, $base) === 0) {
            // If it already starts with wp-content, just append the subpackage name if needed.
            // This case seems less likely given how paths are usually defined.
             return rtrim($package_path, '/') . '/' . $subpackage_name;
        } else {
            // Prepend 'wp-content/' and append subpackage name.
            return $base . rtrim($package_path, '/') . '/' . $subpackage_name;
        }
        // Example: package_path = 'themes', subpackage_name = 'mytheme' -> 'wp-content/themes/mytheme'
        // Example: package_path = 'plugins', subpackage_name = 'myplugin' -> 'wp-content/plugins/myplugin'
    }

    /**
     * Prepare environment for background task execution (timeouts, memory limits).
     */
    public static function prepare_environment() {
        // Attempt to disable time limit
        @set_time_limit(0);

        // Attempt to increase memory limit
        $current_memory_limit = ini_get('memory_limit');
        $current_memory_bytes = wp_convert_hr_to_bytes($current_memory_limit);
        // Request a reasonable amount, e.g., 256M or 512M
        $desired_memory_bytes = wp_convert_hr_to_bytes('256M');

        if ($current_memory_bytes > 0 && $current_memory_bytes < $desired_memory_bytes) {
            @ini_set('memory_limit', '256M');
            $new_limit = ini_get('memory_limit');
            wp_github_sync_log("InitialSyncHelper: Attempted to increase memory limit to 256M. New limit: {$new_limit}", 'debug');
        }
    }

     /**
     * Schedule the next chunk processing task using Action Scheduler or WP Cron.
     */
    public static function schedule_next_chunk() {
        $hook = 'wp_github_sync_run_chunk_step'; // The hook that triggers process_chunked_sync_step
        $args = []; // No arguments needed for the chunk step processor

        if (function_exists('as_schedule_single_action')) {
            // Check if an identical action is already scheduled to avoid duplicates
            $actions = as_get_scheduled_actions([
                'hook' => $hook,
                'status' => \ActionScheduler_Store::STATUS_PENDING,
                'per_page' => 1,
            ], 'ids');

            if (empty($actions)) {
                as_schedule_single_action(time() + 5, $hook, $args, 'wp-github-sync'); // 5 sec delay
                wp_github_sync_log("InitialSyncHelper: Next chunk scheduled via Action Scheduler.", 'debug');
            } else {
                 wp_github_sync_log("InitialSyncHelper: Next chunk already scheduled via Action Scheduler.", 'debug');
            }
        } else {
            // Fallback to WP Cron
            if (!wp_next_scheduled($hook, $args)) {
                wp_schedule_single_event(time() + 5, $hook, $args);
                wp_github_sync_log("InitialSyncHelper: Next chunk scheduled via WP-Cron.", 'debug');
            } else {
                 wp_github_sync_log("InitialSyncHelper: Next chunk already scheduled via WP-Cron.", 'debug');
            }
        }
    }

    /**
     * Process a chunk of file collection, copying one directory from wp-content to the temp dir.
     *
     * @param array $sync_state The current sync state.
     * @param \WP_Filesystem_Base $wp_filesystem WP Filesystem instance.
     * @return array|null Updated state keys to merge, or null if processing continues without state change.
     * @throws \Exception If a critical error occurs (e.g., cannot create directory).
     */
    public static function process_collection_chunk(array $sync_state, \WP_Filesystem_Base $wp_filesystem): ?array {
        $temp_dir = $sync_state['temp_dir'];
        $paths_to_sync = $sync_state['paths_to_sync'];
        $current_path_index = $sync_state['current_path_index'] ?? 0;
        $path_keys = array_keys($paths_to_sync);

        if (!isset($path_keys[$current_path_index])) {
            // All paths processed, return state update to move to next stage
            return ['stage' => 'scanning_temp_dir', 'progress_step' => 6];
        }

        $current_path = $path_keys[$current_path_index];
        $include = $paths_to_sync[$current_path];
        $next_path_index = $current_path_index + 1; // Calculate next index

        if (!$include) {
            // Skip disabled path, return state update to move to next index
            wp_github_sync_log("InitialSyncHelper: Skipping disabled path: {$current_path}", 'debug');
            return ['current_path_index' => $next_path_index];
        }

        wp_github_sync_log("InitialSyncHelper: Processing directory chunk: {$current_path}", 'info');

        // Determine source path
        $wp_content_dir = $wp_filesystem->wp_content_dir();
        if (strpos($current_path, 'wp-content/') === 0) {
            $rel_path = substr($current_path, strlen('wp-content/'));
            $source_path = trailingslashit($wp_content_dir) . $rel_path;
        } else {
            wp_github_sync_log("InitialSyncHelper: Skipping invalid path (must be within wp-content): {$current_path}", 'warning');
            return ['current_path_index' => $next_path_index];
        }

        // Validate source path
        if (!FilesystemHelper::is_safe_path($source_path) || !$wp_filesystem->exists($source_path) || !FilesystemHelper::is_within_wordpress($source_path)) {
            wp_github_sync_log("InitialSyncHelper: Skipping invalid or non-existent source path: {$source_path}", 'warning');
            return ['current_path_index' => $next_path_index];
        }

        // Prepare destination path
        $dest_path = trailingslashit($temp_dir) . FilesystemHelper::normalize_path($current_path);
        $dest_parent_dir = dirname($dest_path);
        if (!$wp_filesystem->exists($dest_parent_dir)) {
            if (!$wp_filesystem->mkdir($dest_parent_dir, FS_CHMOD_DIR)) {
                 throw new \Exception("Failed to create destination parent directory: {$dest_parent_dir}");
            }
        }

        // Copy directory using FilesystemHelper
        $copy_result = FilesystemHelper::copy_directory($source_path, $dest_path);
        if (is_wp_error($copy_result)) {
            wp_github_sync_log("InitialSyncHelper: Failed to copy {$current_path}: " . $copy_result->get_error_message(), 'error');
            // Log error but continue to next path
        } else {
            wp_github_sync_log("InitialSyncHelper: Successfully copied {$current_path} to temporary directory", 'debug');
        }

        // Return state update to move to the next path index
        return ['current_path_index' => $next_path_index];
    }

    /**
     * Scan the temporary directory to build the list of files to process.
     *
     * @param string $temp_dir The path to the temporary directory.
     * @return array ['files_to_process' => array, 'total_files' => int, 'skipped_files' => array]
     * @throws \Exception If scanning fails.
     */
    public static function scan_temp_directory_for_files(string $temp_dir): array {
        wp_github_sync_log("InitialSyncHelper: Scanning temporary directory for files: {$temp_dir}", 'info');

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
                    wp_github_sync_log("InitialSyncHelper: Skipping excluded file during scan: {$github_relative_path}", 'debug');
                    $skipped_files[] = ['path' => $github_relative_path, 'reason' => 'excluded'];
                    continue;
                }
                 if (!FilesystemHelper::is_safe_path($github_relative_path)) {
                    wp_github_sync_log("InitialSyncHelper: Skipping unsafe path during scan: {$github_relative_path}", 'warning');
                    $skipped_files[] = ['path' => $github_relative_path, 'reason' => 'unsafe_path'];
                    continue;
                }

                // Determine file mode
                $file_mode = is_executable($full_path) ? '100755' : '100644';

                $files_to_process[] = [
                    'local_full_path' => $full_path,
                    'github_relative_path' => $github_relative_path,
                    'mode' => $file_mode, // Store the mode
                ];
                $total_files++;
            }

        } catch (\Exception $e) {
             wp_github_sync_log("InitialSyncHelper: Exception during temporary directory scan: " . $e->getMessage(), 'error');
             throw new \Exception("Failed to scan temporary directory: " . $e->getMessage());
        }

        wp_github_sync_log("InitialSyncHelper: Scan complete. Found {$total_files} files to process.", 'info');
        if (!empty($skipped_files)) {
             wp_github_sync_log("InitialSyncHelper: Skipped " . count($skipped_files) . " files during scan.", 'warning');
        }

        return [
            'files_to_process' => $files_to_process,
            'total_files' => $total_files,
            'skipped_files' => $skipped_files
        ];
    }

    /**
     * Process a chunk of blob creation.
     *
     * @param array $sync_state   The current sync state.
     * @param GitData\BlobCreator $blob_creator The BlobCreator instance.
     * @param callable|null $progress_callback Optional progress callback.
     * @return array Updated state keys to merge.
     * @throws \Exception If blob creator is missing or other critical error.
     */
    public static function process_blob_creation_chunk(array $sync_state, GitData\BlobCreator $blob_creator, ?callable $progress_callback = null): array {
        wp_github_sync_log("InitialSyncHelper: Entering blob creation chunk processing", 'debug');
        $files_to_process = $sync_state['files_to_process'] ?? [];
        $current_index = $sync_state['processed_file_index'] ?? 0;
        $created_blobs = $sync_state['created_blobs'] ?? [];
        $total_files = $sync_state['total_files_to_process'] ?? count($files_to_process);

        // Define how many blobs to create per chunk
        $blobs_per_chunk = apply_filters('wp_github_sync_blobs_per_chunk', 50);
        $processed_in_chunk = 0;

        if (!$blob_creator) {
             throw new \Exception("BlobCreator instance not provided to InitialSyncHelper::process_blob_creation_chunk.");
        }

        wp_github_sync_log("InitialSyncHelper: Processing blob creation chunk starting from index {$current_index}", 'debug');

        while ($current_index < $total_files && $processed_in_chunk < $blobs_per_chunk) {
            $file_info = $files_to_process[$current_index];
            $local_path = $file_info['local_full_path'];
            $github_path = $file_info['github_relative_path'];

            // Update progress using the provided callback
            if (is_callable($progress_callback)) {
                call_user_func($progress_callback, 7, "Creating blob " . ($current_index + 1) . "/{$total_files}: {$github_path}");
            }

            $blob_result = $blob_creator->create_blob($local_path, $github_path);

            if (is_wp_error($blob_result)) {
                // Log error but continue processing other files in the chunk
                wp_github_sync_log("InitialSyncHelper: Failed to create blob for {$github_path}: " . $blob_result->get_error_message(), 'error');
                // Optionally store skipped file info in state
                // $sync_state['skipped_blobs'][] = ['path' => $github_path, 'reason' => $blob_result->get_error_message()];
            } else {
                // Store successful blob SHA mapped to its GitHub path
                $created_blobs[$github_path] = $blob_result['sha'];
            }

            $current_index++;
            $processed_in_chunk++;
        }

        $updates = [
            'processed_file_index' => $current_index,
            'created_blobs' => $created_blobs,
            // Optionally update skipped blobs: 'skipped_blobs' => $sync_state['skipped_blobs'] ?? []
        ];

        // Check if all files have been processed
        if ($current_index >= $total_files) {
            // All blobs created, update state to move to next stage
            wp_github_sync_log("InitialSyncHelper: Blob creation complete. Processed {$total_files} files.", 'info');
            $updates['stage'] = 'preparing_tree_items';
            $updates['progress_step'] = 8;
        } else {
            // More blobs to create, progress updated by caller
             if (is_callable($progress_callback)) {
                 call_user_func($progress_callback, 7, "Processed blob chunk, " . ($total_files - $current_index) . " remaining...");
             }
        }
        wp_github_sync_log("InitialSyncHelper: Exiting blob creation chunk processing", 'debug');
        return $updates;
    }

} // End class InitialSyncHelper
