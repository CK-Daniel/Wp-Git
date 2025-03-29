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
     * @param string $base_tree_sha The SHA of the base tree.
     * @return string|\WP_Error The SHA of the created tree or WP_Error on failure.
     */
    public function create_single_tree_api_call(array $tree_items_batch, string $base_tree_sha) {
        if (empty($tree_items_batch)) {
            return new \WP_Error('empty_tree_batch', __('Cannot create tree with an empty batch of items.', 'wp-github-sync'));
        }

        $this->update_progress(8, "Creating Git tree chunk with " . count($tree_items_batch) . " items"); // Adjust step number later
        wp_github_sync_log("Creating tree chunk with " . count($tree_items_batch) . " items based on tree {$base_tree_sha}", 'info');

        $tree_request_data = ['tree' => $tree_items_batch];
        // Always include base_tree if it exists, unless it's empty (truly initial commit).
        if (!empty($base_tree_sha)) {
             $tree_request_data['base_tree'] = $base_tree_sha;
        }

        $tree_result = $this->api_client->request(
            "repos/{$this->api_client->get_owner()}/{$this->api_client->get_repo()}/git/trees",
            'POST',
            $tree_request_data
        );

        if (is_wp_error($tree_result)) {
            $error_msg = "Failed to create tree chunk: " . $tree_result->get_error_message();
            wp_github_sync_log($error_msg, 'error');
            return new \WP_Error('tree_creation_failed', $error_msg);
        }

        if (!isset($tree_result['sha'])) {
             $error_msg = "Tree creation API call did not return a SHA.";
             wp_github_sync_log($error_msg, 'error');
             return new \WP_Error('tree_creation_no_sha', $error_msg);
        }

        $new_tree_sha = $tree_result['sha'];
        wp_github_sync_log("Created tree chunk successfully with SHA: {$new_tree_sha}", 'debug');

        return $new_tree_sha;
    }

} // End class TreeBuilder
