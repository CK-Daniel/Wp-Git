<?php
/**
 * GitHub API Client Interface
 *
 * @package WPGitHubSync
 */

namespace WPGitHubSync\API;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * GitHub API Client Interface
 */
interface ClientInterface {
    /**
     * Make a request to the GitHub API.
     *
     * @param string $endpoint The API endpoint.
     * @param string $method HTTP method (GET, POST, etc.).
     * @param array  $data Request data.
     * @return mixed Response data or WP_Error.
     */
    public function request( $endpoint, $method = 'GET', $data = [] );
    
    /**
     * Verify authentication with GitHub.
     *
     * @return bool|\WP_Error True if authenticated, WP_Error otherwise.
     */
    public function test_authentication();
    
    /**
     * Get repository details.
     *
     * @return mixed Repository data or WP_Error.
     */
    public function get_repository();
    
    /**
     * Get branches for the repository.
     *
     * @return array|\WP_Error List of branches or WP_Error.
     */
    public function get_branches();
    
    /**
     * Get commits for a branch.
     *
     * @param string $branch Branch name.
     * @param int    $per_page Number of commits to fetch.
     * @return array|\WP_Error List of commits or WP_Error.
     */
    public function get_commits( $branch = '', $per_page = 10 );
    
    /**
     * Get a specific commit.
     *
     * @param string $sha Commit SHA.
     * @return array|\WP_Error Commit data or WP_Error.
     */
    public function get_commit( $sha );
    
    /**
     * Get the contents of a file.
     *
     * @param string $path File path.
     * @param string $ref  Branch or commit SHA.
     * @return mixed File contents or WP_Error.
     */
    public function get_contents( $path, $ref = '' );
    
    /**
     * Create or update a file.
     *
     * @param string $path File path.
     * @param string $content File content.
     * @param string $message Commit message.
     * @param string $branch Branch name.
     * @param string $sha File SHA (only for updates).
     * @return mixed Response data or WP_Error.
     */
    public function create_or_update_file( $path, $content, $message, $branch = '', $sha = '' );
    
    /**
     * Download repository as zip.
     *
     * @param string $ref Branch or commit SHA.
     * @return string|\WP_Error Path to downloaded zip file or WP_Error.
     */
    public function download_archive( $ref = '' );
    
    /**
     * Compare two commits.
     *
     * @param string $base Base commit SHA or branch.
     * @param string $head Head commit SHA or branch.
     * @return array|\WP_Error Comparison data or WP_Error.
     */
    public function compare( $base, $head );
    
    /**
     * Get a specific branch.
     *
     * @param string $branch Branch name.
     * @return array|\WP_Error Branch data or WP_Error.
     */
    public function get_branch( $branch );
    
    /**
     * Create a new branch.
     *
     * @param string $branch Branch name.
     * @param string $sha    SHA to create branch from.
     * @return array|\WP_Error Response data or WP_Error.
     */
    public function create_branch( $branch, $sha );
    
    /**
     * Get branch SHA.
     *
     * @param string $branch Branch name.
     * @return string|\WP_Error SHA or WP_Error.
     */
    public function get_branch_sha( $branch );
}