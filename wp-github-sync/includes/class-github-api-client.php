<?php
/**
 * GitHub API integration for the WordPress GitHub Sync plugin.
 *
 * @package WPGitHubSync
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * GitHub API integration class.
 */
class GitHub_API_Client {

    /**
     * GitHub API base URL.
     *
     * @var string
     */
    private $api_base_url = 'https://api.github.com';

    /**
     * GitHub repository owner.
     *
     * @var string
     */
    private $owner;

    /**
     * GitHub repository name.
     *
     * @var string
     */
    private $repo;

    /**
     * GitHub access token.
     *
     * @var string
     */
    private $token;

    /**
     * Initialize the GitHub API client.
     */
    public function __construct() {
        $this->initialize();
    }

    /**
     * Initialize the GitHub API client with repository and authentication information.
     */
    public function initialize() {
        $repo_url = get_option('wp_github_sync_repository', '');
        
        // Parse repository URL
        if (!empty($repo_url)) {
            $parsed_url = $this->parse_github_url($repo_url);
            if ($parsed_url) {
                $this->owner = $parsed_url['owner'];
                $this->repo = $parsed_url['repo'];
            }
        }
        
        // Get authentication token
        $auth_method = get_option('wp_github_sync_auth_method', 'pat');
        
        if ($auth_method === 'pat') {
            // Personal Access Token
            $encrypted_token = get_option('wp_github_sync_access_token', '');
            if (!empty($encrypted_token)) {
                $this->token = wp_github_sync_decrypt($encrypted_token);
            }
        } elseif ($auth_method === 'oauth') {
            // OAuth token
            $encrypted_token = get_option('wp_github_sync_oauth_token', '');
            if (!empty($encrypted_token)) {
                $this->token = wp_github_sync_decrypt($encrypted_token);
            }
        }
    }

    /**
     * Parse a GitHub URL to extract owner and repository name.
     *
     * @param string $url The GitHub repository URL.
     * @return array|false Array with owner and repo keys, or false if invalid.
     */
    public function parse_github_url($url) {
        // Handle HTTPS URLs
        if (preg_match('#^https?://github\.com/([^/]+)/([^/]+)(?:\.git)?$#', $url, $matches)) {
            return [
                'owner' => $matches[1],
                'repo' => rtrim($matches[2], '.git'),
            ];
        }
        
        // Handle git@ URLs
        if (preg_match('#^git@github\.com:([^/]+)/([^/]+)(?:\.git)?$#', $url, $matches)) {
            return [
                'owner' => $matches[1],
                'repo' => rtrim($matches[2], '.git'),
            ];
        }
        
        return false;
    }

    /**
     * Make a request to the GitHub API.
     *
     * @param string $endpoint The API endpoint (without the base URL).
     * @param string $method   The HTTP method (GET, POST, etc.).
     * @param array  $data     The data to send with the request.
     * @return array|WP_Error The response or WP_Error on failure.
     */
    public function request($endpoint, $method = 'GET', $data = []) {
        if (empty($this->token) || empty($this->owner) || empty($this->repo)) {
            return new WP_Error('github_api_not_configured', __('GitHub API is not properly configured.', 'wp-github-sync'));
        }
        
        $url = trailingslashit($this->api_base_url) . ltrim($endpoint, '/');
        
        $args = [
            'method' => $method,
            'headers' => [
                'Accept' => 'application/vnd.github.v3+json',
                'Authorization' => 'token ' . $this->token,
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'),
            ],
            'timeout' => 30,
        ];
        
        if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($data);
        } elseif (!empty($data) && $method === 'GET') {
            $url = add_query_arg($data, $url);
        }
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            wp_github_sync_log('GitHub API request failed: ' . $response->get_error_message(), 'error');
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);
        
        // Log rate limit information
        $rate_limit_remaining = wp_remote_retrieve_header($response, 'x-ratelimit-remaining');
        if ($rate_limit_remaining !== '') {
            wp_github_sync_log(
                sprintf(
                    'GitHub API rate limit: %s remaining',
                    $rate_limit_remaining
                ),
                'debug'
            );
        }
        
        // Check for API errors
        if ($response_code >= 400) {
            $error_message = isset($response_data['message']) ? $response_data['message'] : 'Unknown API error';
            wp_github_sync_log("GitHub API error ({$response_code}): {$error_message}", 'error');
            return new WP_Error("github_api_{$response_code}", $error_message);
        }
        
        return $response_data;
    }

    /**
     * Get repository information.
     *
     * @return array|WP_Error Repository information or WP_Error on failure.
     */
    public function get_repository() {
        return $this->request("repos/{$this->owner}/{$this->repo}");
    }

    /**
     * Get branches for the repository.
     *
     * @return array|WP_Error List of branches or WP_Error on failure.
     */
    public function get_branches() {
        return $this->request("repos/{$this->owner}/{$this->repo}/branches");
    }

    /**
     * Get commits for a specific branch.
     *
     * @param string $branch The branch name.
     * @param int    $count  The number of commits to fetch.
     * @return array|WP_Error List of commits or WP_Error on failure.
     */
    public function get_commits($branch = 'main', $count = 10) {
        return $this->request("repos/{$this->owner}/{$this->repo}/commits", 'GET', [
            'sha' => $branch,
            'per_page' => $count,
        ]);
    }

    /**
     * Get the latest commit for a specific branch.
     *
     * @param string $branch The branch name.
     * @return array|WP_Error Commit data or WP_Error on failure.
     */
    public function get_latest_commit($branch = 'main') {
        $commits = $this->get_commits($branch, 1);
        
        if (is_wp_error($commits)) {
            return $commits;
        }
        
        if (empty($commits) || !isset($commits[0])) {
            return new WP_Error('no_commits', __('No commits found for this branch.', 'wp-github-sync'));
        }
        
        return $commits[0];
    }

    /**
     * Get the contents of a file from the repository.
     *
     * @param string $path   The file path within the repository.
     * @param string $ref    The branch or commit reference.
     * @return array|WP_Error File contents or WP_Error on failure.
     */
    public function get_contents($path, $ref = 'main') {
        return $this->request("repos/{$this->owner}/{$this->repo}/contents/{$path}", 'GET', [
            'ref' => $ref,
        ]);
    }

    /**
     * Get the download URL for a repository archive at a specific reference.
     *
     * @param string $ref The branch or commit reference.
     * @return string The download URL.
     */
    public function get_archive_url($ref = 'main') {
        return "https://api.github.com/repos/{$this->owner}/{$this->repo}/zipball/{$ref}";
    }

    /**
     * Download a repository archive to a specific directory.
     *
     * @param string $ref         The branch or commit reference.
     * @param string $target_dir  The directory to extract to.
     * @return bool|WP_Error True on success or WP_Error on failure.
     */
    public function download_repository($ref = 'main', $target_dir = '') {
        if (empty($target_dir)) {
            return new WP_Error('missing_target_dir', __('Target directory not specified.', 'wp-github-sync'));
        }
        
        // Create target directory if it doesn't exist
        if (!file_exists($target_dir)) {
            wp_mkdir_p($target_dir);
        }
        
        // Get archive URL
        $archive_url = $this->get_archive_url($ref);
        
        // Download the zip file to a temporary file
        $temp_file = download_url($archive_url, 300);
        
        if (is_wp_error($temp_file)) {
            wp_github_sync_log('Failed to download repository archive: ' . $temp_file->get_error_message(), 'error');
            return $temp_file;
        }
        
        // Extract the zip file
        $result = $this->extract_zip($temp_file, $target_dir);
        
        // Clean up temp file
        @unlink($temp_file);
        
        return $result;
    }

    /**
     * Extract a zip file to a directory.
     *
     * @param string $file       The path to the zip file.
     * @param string $target_dir The directory to extract to.
     * @return bool|WP_Error True on success or WP_Error on failure.
     */
    private function extract_zip($file, $target_dir) {
        global $wp_filesystem;
        
        // Initialize the WordPress filesystem
        if (empty($wp_filesystem)) {
            require_once ABSPATH . '/wp-admin/includes/file.php';
            WP_Filesystem();
        }
        
        // Use unzip_file which uses ZipArchive or PclZip
        $result = unzip_file($file, $target_dir);
        
        if (is_wp_error($result)) {
            wp_github_sync_log('Failed to extract zip file: ' . $result->get_error_message(), 'error');
            return $result;
        }
        
        // Move files from the extracted directory (which includes owner/repo-branch/) to target
        $extracted_dirs = glob($target_dir . '/*', GLOB_ONLYDIR);
        
        if (empty($extracted_dirs)) {
            return new WP_Error('no_extracted_dirs', __('No directories found after extraction.', 'wp-github-sync'));
        }
        
        $extracted_dir = reset($extracted_dirs);
        $extracted_contents = glob($extracted_dir . '/*');
        
        if (!empty($extracted_contents)) {
            foreach ($extracted_contents as $item) {
                $basename = basename($item);
                $destination = $target_dir . '/' . $basename;
                
                // If destination exists, remove it first
                if (file_exists($destination)) {
                    if (is_dir($destination)) {
                        $wp_filesystem->rmdir($destination, true);
                    } else {
                        $wp_filesystem->delete($destination);
                    }
                }
                
                // Move the item to the destination
                rename($item, $destination);
            }
        }
        
        // Remove the extracted dir (now empty)
        $wp_filesystem->rmdir($extracted_dir, true);
        
        return true;
    }

    /**
     * Compare two references (branches or commits) to get the differences.
     *
     * @param string $base The base reference.
     * @param string $head The head reference.
     * @return array|WP_Error Comparison data or WP_Error on failure.
     */
    public function compare($base, $head) {
        return $this->request("repos/{$this->owner}/{$this->repo}/compare/{$base}...{$head}");
    }

    /**
     * Check if the authentication token is valid.
     *
     * @return bool True if token is valid, false otherwise.
     */
    public function is_token_valid() {
        $response = $this->request('user');
        return !is_wp_error($response);
    }
}