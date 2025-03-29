<?php
/**
 * Handles building Git trees via the GitHub API.
 *
 * @package WPGitHubSync\API\GitData
 */

namespace WPGitHubSync\API\GitData;

use WPGitHubSync\API\API_Client;
use WPGitHubSync\API\GitData\BlobCreator;
use WPGitHubSync\Utils\FilesystemHelper;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Tree Builder class.
 */
class TreeBuilder {

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
     * Progress callback function.
     * @var callable|null
     */
    private $progress_callback = null;

    /**
     * File processing statistics.
     * @var array
     */
    private $file_stats = [
        'total_files' => 0,
        'processed_files' => 0,
        'binary_files' => 0,
        'text_files' => 0,
        'blobs_created' => 0,
        'failures' => 0
    ];

    /**
     * Constructor.
     *
     * @param API_Client  $api_client   The API Client instance.
     * @param BlobCreator $blob_creator The Blob Creator instance.
     */
    public function __construct(API_Client $api_client, BlobCreator $blob_creator) {
        $this->api_client = $api_client;
        $this->blob_creator = $blob_creator;
    }

    /**
     * Set a progress callback function.
     *
     * @param callable $callback Function that takes ($subStep, $detail, $stats).
     */
    public function set_progress_callback($callback) {
        $this->progress_callback = $callback;
        // Pass callback to BlobCreator as well if needed (assuming BlobCreator has this method)
        // $this->blob_creator->set_progress_callback($callback);
    }

    /**
     * Update progress via callback.
     *
     * @param int    $subStep Sub-step number.
     * @param string $detail  Progress detail message.
     * @param array  $stats   Optional additional stats.
     */
    private function update_progress($subStep, $detail, $stats = []) {
        if (is_callable($this->progress_callback)) {
            $stats = array_merge($this->file_stats, $stats);
            call_user_func($this->progress_callback, $subStep, $detail, $stats);
        }
    }

    /**
     * Create a new Git tree based on directory contents and a base tree.
     *
     * @param string $directory     The local directory containing files.
     * @param string $base_tree_sha The SHA of the base tree to build upon.
     * @param string $github_path   Optional path prefix for files within the GitHub repo.
     * @return string|\WP_Error The SHA of the newly created tree or WP_Error on failure.
     */
    public function create_tree_from_directory(string $directory, string $base_tree_sha, string $github_path = '') {
        wp_github_sync_log("Starting tree creation process based on directory: {$directory}", 'info');

        // Step 1: Create tree items (scan directory, create blobs)
        $tree_items_result = $this->create_tree_items($directory, $github_path);
        if (is_wp_error($tree_items_result)) {
            return $tree_items_result; // Propagate error
        }
        $tree_items = $tree_items_result['items'];
        $skipped_files = $tree_items_result['skipped'];

        if (empty($tree_items)) {
            // If no items were successfully processed (maybe all skipped), return specific error
            if (!empty($skipped_files)) {
                 return new \WP_Error('no_valid_files', __('No valid files found to upload after filtering.', 'wp-github-sync'));
            }
            return new \WP_Error('no_files_to_upload', __('Directory is empty or contains no uploadable files.', 'wp-github-sync'));
        }

        wp_github_sync_log("Created " . count($tree_items) . " tree items (" . $this->file_stats['blobs_created'] . " blobs).", 'info');
        if (!empty($skipped_files)) {
             wp_github_sync_log("Skipped " . count($skipped_files) . " files.", 'warning');
             // Optionally store skipped files info for later display
             set_transient('wp_github_sync_skipped_files_tree', $skipped_files, HOUR_IN_SECONDS);
        }

        // Step 2: Create the actual tree object via API, handling chunking
        $new_tree_sha = $this->create_tree_api_call($tree_items, $base_tree_sha);
        if (is_wp_error($new_tree_sha)) {
            return $new_tree_sha;
        }

        wp_github_sync_log("Final tree created successfully with SHA: {$new_tree_sha}", 'info');
        return $new_tree_sha;
    }

    /**
     * Create tree items by scanning a directory and creating blobs.
     *
     * @param string $directory   The directory containing files to upload.
     * @param string $github_path Optional path prefix for GitHub.
     * @return array|\WP_Error ['items' => array, 'skipped' => array] or WP_Error.
     */
    private function create_tree_items(string $directory, string $github_path = '') {
        $tree_items = [];
        $files_processed = 0;
        $skipped_files = [];
        $max_files = 10000; // Increased limit, actual limit depends on total size and API response time

        // Reset stats for this operation
        $this->file_stats = [
            'total_files' => 0, 'processed_files' => 0, 'binary_files' => 0,
            'text_files' => 0, 'blobs_created' => 0, 'failures' => 0
        ];

        $this->update_progress(1, "Starting file analysis for tree");
        wp_github_sync_log("Scanning directory for tree items: {$directory}", 'debug');

        // Normalize GitHub path prefix
        if (!empty($github_path)) {
            $github_path = trim($github_path, '/');
            if (!empty($github_path)) $github_path .= '/';
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $item) {
                $file_path = $item->getPathname();
                $subpath = $iterator->getSubPathName();
                $github_relative_path = $github_path . str_replace('\\', '/', $subpath);

                // Skip directories themselves in the tree items array
                if ($item->isDir()) {
                    continue;
                }

                // Basic path safety check
                if (!FilesystemHelper::is_safe_path($subpath)) {
                    wp_github_sync_log("Skipping unsafe path: {$subpath}", 'warning');
                    $skipped_files[] = ['path' => $github_relative_path, 'reason' => 'unsafe_path'];
                    continue;
                }

                // Skip common unwanted files/dirs (redundant with initial scan but safer)
                if (strpos($item->getFilename(), '.') === 0 || in_array($item->getFilename(), ['node_modules', 'vendor', '.git', 'cache'])) {
                    wp_github_sync_log("Skipping excluded file/pattern: {$github_relative_path}", 'debug');
                    $skipped_files[] = ['path' => $github_relative_path, 'reason' => 'excluded'];
                    continue;
                }

                // Check file limits
                if ($files_processed >= $max_files) {
                    wp_github_sync_log("Reached max file limit ({$max_files}). Some files were not processed.", 'warning');
                    $skipped_files[] = ['path' => $github_relative_path, 'reason' => 'max_files_reached'];
                    // Stop processing further files for this tree
                    break;
                }

                // Update progress estimate
                $this->file_stats['total_files'] = max($this->file_stats['total_files'], $files_processed + 1); // Increment total estimate
                $this->file_stats['processed_files'] = $files_processed;
                if ($files_processed % 20 == 0) {
                     $this->update_progress(2, "Analyzing file: {$github_relative_path} ({$files_processed}/~{$this->file_stats['total_files']})");
                }

                // Create blob using BlobCreator
                $blob_result = $this->blob_creator->create_blob($file_path, $github_relative_path);

                if (is_wp_error($blob_result)) {
                    wp_github_sync_log("Failed to create blob for {$github_relative_path}: " . $blob_result->get_error_message(), 'error');
                    $skipped_files[] = ['path' => $github_relative_path, 'reason' => 'blob_creation_failed', 'error' => $blob_result->get_error_message()];
                    $this->file_stats['failures']++;
                    continue; // Skip this file
                }

                // Determine file mode
                $file_mode = is_executable($file_path) ? '100755' : '100644';

                $tree_items[] = [
                    'path' => $github_relative_path,
                    'mode' => $file_mode,
                    'type' => 'blob',
                    'sha' => $blob_result['sha']
                ];

                $files_processed++;
                $this->file_stats['blobs_created']++;
                // Update stats based on blob creator's determination (if possible, otherwise estimate)
                // $this->file_stats[$is_binary ? 'binary_files' : 'text_files']++;

            } // End foreach loop

        } catch (\Exception $e) {
             wp_github_sync_log("Exception during directory scan for tree items: " . $e->getMessage(), 'error');
             return new \WP_Error('tree_scan_exception', $e->getMessage());
        }

        // Final progress update for file processing
        $this->file_stats['total_files'] = $files_processed; // Update total accurately
        $this->file_stats['processed_files'] = $files_processed;
        $this->update_progress(3, "File analysis complete: {$files_processed} files processed.");

        return ['items' => $tree_items, 'skipped' => $skipped_files];
    }


    /**
     * Create the Git tree object via the GitHub API, handling chunking if necessary.
     *
     * @param array  $tree_items    Array of tree item objects.
     * @param string $base_tree_sha The SHA of the base tree.
     * @return string|\WP_Error The SHA of the final tree or WP_Error on failure.
     */
    private function create_tree_api_call(array $tree_items, string $base_tree_sha) {
        $this->update_progress(4, "Creating Git tree structure");
        wp_github_sync_log("Creating tree with " . count($tree_items) . " items based on tree {$base_tree_sha}", 'info');

        // GitHub recommends max 1000 items per tree request for performance,
        // but practical limits might be lower depending on path lengths etc. Let's use 500.
        $max_items_per_request = 500;
        $chunks = array_chunk($tree_items, $max_items_per_request);
        $current_base_tree = $base_tree_sha;

        if (count($chunks) > 1) {
            wp_github_sync_log("Tree items split into " . count($chunks) . " chunks for API requests.", 'info');
        }

        foreach ($chunks as $index => $chunk) {
            if (count($chunks) > 1) {
                $this->update_progress(4, "Creating tree chunk " . ($index + 1) . "/" . count($chunks));
                wp_github_sync_log("Processing tree chunk " . ($index + 1) . " of " . count($chunks), 'info');
            }

            $tree_request_data = ['tree' => $chunk];
            // Only include base_tree if it's not the very first tree being created (or first chunk)
            // Correction: Always include base_tree if it exists, even for the first chunk,
            // unless the base_tree_sha itself is empty (truly initial commit).
            if (!empty($current_base_tree)) {
                 $tree_request_data['base_tree'] = $current_base_tree;
            }

            $chunk_tree = $this->api_client->request(
                "repos/{$this->api_client->get_owner()}/{$this->api_client->get_repo()}/git/trees",
                'POST',
                $tree_request_data
            );

            if (is_wp_error($chunk_tree)) {
                $error_msg = "Failed to create tree chunk " . ($index + 1) . ": " . $chunk_tree->get_error_message();
                wp_github_sync_log($error_msg, 'error');
                return new \WP_Error('tree_creation_failed', $error_msg);
            }

            // Use the newly created tree SHA as the base for the next chunk
            $current_base_tree = $chunk_tree['sha'];
            wp_github_sync_log("Created tree chunk " . ($index + 1) . " with SHA: {$current_base_tree}", 'debug');
        }

        // The SHA of the last created chunk is the final tree SHA
        return $current_base_tree;
    }

} // End class TreeBuilder
