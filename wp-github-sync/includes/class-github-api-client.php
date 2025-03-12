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
        
        // First check for unencrypted token in environment for development purposes
        $dev_token = defined('WP_GITHUB_SYNC_DEV_TOKEN') ? WP_GITHUB_SYNC_DEV_TOKEN : '';
        if (!empty($dev_token)) {
            wp_github_sync_log("Using development token from environment variable", 'debug');
            $this->token = $dev_token;
        } else {
            if ($auth_method === 'pat') {
                // Personal Access Token
                $encrypted_token = get_option('wp_github_sync_access_token', '');
                if (!empty($encrypted_token)) {
                    $decrypted = wp_github_sync_decrypt($encrypted_token);
                    if ($decrypted !== false) {
                        $this->token = $decrypted;
                        wp_github_sync_log("Successfully decrypted PAT token", 'debug');
                    } else {
                        wp_github_sync_log("Failed to decrypt PAT token", 'error');
                    }
                } else {
                    wp_github_sync_log("No PAT token found in options", 'error');
                }
            } elseif ($auth_method === 'oauth') {
                // OAuth token
                $encrypted_token = get_option('wp_github_sync_oauth_token', '');
                if (!empty($encrypted_token)) {
                    $decrypted = wp_github_sync_decrypt($encrypted_token);
                    if ($decrypted !== false) {
                        $this->token = $decrypted;
                        wp_github_sync_log("Successfully decrypted OAuth token", 'debug');
                    } else {
                        wp_github_sync_log("Failed to decrypt OAuth token", 'error');
                    }
                } else {
                    wp_github_sync_log("No OAuth token found in options", 'error');
                }
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
        // Trim whitespace
        $url = trim($url);
        
        if (empty($url)) {
            wp_github_sync_log("Cannot parse empty GitHub URL", 'error');
            return false;
        }
        
        // Log the URL we're trying to parse
        wp_github_sync_log("Parsing GitHub URL: {$url}", 'debug');
        
        // Handle HTTPS URLs
        if (preg_match('#^https?://github\.com/([^/]+)/([^/]+?)(?:\.git)?(?:/)?$#', $url, $matches)) {
            $owner = $matches[1];
            $repo = rtrim($matches[2], '.git');
            
            wp_github_sync_log("Successfully parsed HTTPS URL: owner={$owner}, repo={$repo}", 'debug');
            
            return [
                'owner' => $owner,
                'repo' => $repo,
            ];
        }
        
        // Handle git@ URLs
        if (preg_match('#^git@github\.com:([^/]+)/([^/]+?)(?:\.git)?$#', $url, $matches)) {
            $owner = $matches[1];
            $repo = rtrim($matches[2], '.git');
            
            wp_github_sync_log("Successfully parsed SSH URL: owner={$owner}, repo={$repo}", 'debug');
            
            return [
                'owner' => $owner,
                'repo' => $repo,
            ];
        }
        
        // Handle potential API URLs
        if (preg_match('#^https?://api\.github\.com/repos/([^/]+)/([^/]+)(?:/.*)?$#', $url, $matches)) {
            $owner = $matches[1];
            $repo = $matches[2];
            
            wp_github_sync_log("Successfully parsed API URL: owner={$owner}, repo={$repo}", 'debug');
            
            return [
                'owner' => $owner,
                'repo' => $repo,
            ];
        }
        
        // Handle raw owner/repo format
        if (preg_match('#^([^/]+)/([^/]+?)(?:\.git)?$#', $url, $matches)) {
            $owner = $matches[1];
            $repo = rtrim($matches[2], '.git');
            
            wp_github_sync_log("Successfully parsed owner/repo format: owner={$owner}, repo={$repo}", 'debug');
            
            return [
                'owner' => $owner,
                'repo' => $repo,
            ];
        }
        
        wp_github_sync_log("Failed to parse GitHub URL: {$url}", 'error');
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
        // Check if we have what we need to make a request
        if (empty($this->token)) {
            wp_github_sync_log("API request failed: No GitHub token available", 'error');
            return new WP_Error('github_api_no_token', __('No GitHub authentication token available. Please check your settings.', 'wp-github-sync'));
        }
        
        // Check if this endpoint requires owner/repo information
        if (strpos($endpoint, 'repos/') === 0 && (empty($this->owner) || empty($this->repo))) {
            wp_github_sync_log("API request failed: Missing owner/repo for endpoint: {$endpoint}", 'error');
            return new WP_Error('github_api_no_repo', __('Repository owner or name is missing. Please check your repository URL.', 'wp-github-sync'));
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
        
        // Debug logging for authentication issues
        $token_length = strlen($this->token);
        $token_start = $token_length > 4 ? substr($this->token, 0, 4) : '****';
        $token_end = $token_length > 4 ? substr($this->token, -4) : '****';
        wp_github_sync_log("Request to: {$url}", 'debug');
        wp_github_sync_log("Authorization token length: {$token_length}", 'debug');
        wp_github_sync_log("Authorization token prefix/suffix: {$token_start}...{$token_end}", 'debug');
        wp_github_sync_log("Headers: " . print_r($args['headers'], true), 'debug');
        
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
            
            // Additional logging for Bad credentials error
            if ($error_message === 'Bad credentials') {
                $token_length = strlen($this->token);
                $token_start = $token_length > 4 ? substr($this->token, 0, 4) : '****';
                $token_end = $token_length > 4 ? substr($this->token, -4) : '****';
                wp_github_sync_log("Bad credentials error. Token info - Length: {$token_length}, Start: {$token_start}, End: {$token_end}", 'error');
                wp_github_sync_log("Full response body: " . wp_remote_retrieve_body($response), 'error');
                
                // Check if token might be empty or invalid
                if ($token_length < 10) {
                    wp_github_sync_log("Token appears to be too short or empty", 'error');
                }
            }
            
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
    
    /**
     * Create a new repository for the authenticated user.
     *
     * @param string $repo_name        The name of the repository to create.
     * @param string $description      Optional. The repository description.
     * @param bool   $private          Optional. Whether the repository should be private. Default false.
     * @param bool   $auto_init        Optional. Whether to initialize the repository with a README. Default true.
     * @param string $default_branch   Optional. The default branch name. Default 'main'.
     * @return array|WP_Error Repository data or WP_Error on failure.
     */
    public function create_repository($repo_name, $description = '', $private = false, $auto_init = true, $default_branch = 'main') {
        if (empty($this->token)) {
            return new WP_Error('github_api_not_configured', __('GitHub API token is not configured.', 'wp-github-sync'));
        }
        
        $data = [
            'name' => $repo_name,
            'description' => empty($description) ? __('WordPress site synced with GitHub', 'wp-github-sync') : $description,
            'private' => (bool) $private,
            'auto_init' => (bool) $auto_init,
            'default_branch' => $default_branch,
        ];
        
        // Make a direct request without requiring owner/repo to be set
        $url = trailingslashit($this->api_base_url) . 'user/repos';
        
        $args = [
            'method' => 'POST',
            'headers' => [
                'Accept' => 'application/vnd.github.v3+json',
                'Authorization' => 'token ' . $this->token,
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'),
                'Content-Type' => 'application/json',
            ],
            'timeout' => 30,
            'body' => wp_json_encode($data),
        ];
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            wp_github_sync_log('GitHub API request failed: ' . $response->get_error_message(), 'error');
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);
        
        // Check for API errors
        if ($response_code >= 400) {
            $error_message = isset($response_data['message']) ? $response_data['message'] : 'Unknown API error';
            wp_github_sync_log("GitHub API error ({$response_code}): {$error_message}", 'error');
            return new WP_Error("github_api_{$response_code}", $error_message);
        }
        
        // Update the owner and repo properties
        if (isset($response_data['owner']['login']) && isset($response_data['name'])) {
            $this->owner = $response_data['owner']['login'];
            $this->repo = $response_data['name'];
        }
        
        return $response_data;
    }
    
    /**
     * Check if a repository exists and is accessible with the current authentication.
     *
     * @param string $owner The repository owner. If empty, uses currently set owner.
     * @param string $repo  The repository name. If empty, uses currently set repo.
     * @return bool True if repository exists and is accessible, false otherwise.
     */
    public function repository_exists($owner = '', $repo = '') {
        // Use provided values or current ones
        $check_owner = !empty($owner) ? $owner : $this->owner;
        $check_repo = !empty($repo) ? $repo : $this->repo;
        
        if (empty($check_owner) || empty($check_repo)) {
            wp_github_sync_log("Cannot check repository: owner or repo is empty", 'error');
            return false;
        }
        
        // Temporarily store the current owner/repo
        $current_owner = $this->owner;
        $current_repo = $this->repo;
        
        // Set the owner/repo to check
        $this->owner = $check_owner;
        $this->repo = $check_repo;
        
        // Try to get the repository
        $response = $this->get_repository();
        
        // Restore the original owner/repo
        $this->owner = $current_owner;
        $this->repo = $current_repo;
        
        return !is_wp_error($response);
    }
    
    /**
     * Set a temporary token for testing purposes.
     * 
     * This bypasses the normal encryption and is intended only for test connections.
     * 
     * @param string $token The unencrypted GitHub token to use temporarily
     */
    public function set_temporary_token($token) {
        if (!empty($token)) {
            $this->token = $token;
            wp_github_sync_log("Set temporary token for testing", 'debug');
        }
    }
    
    /**
     * Get the authenticated user's login name.
     * 
     * @return string|null The user login or null if not available
     */
    public function get_user_login() {
        $user = $this->request('user');
        
        if (!is_wp_error($user) && isset($user['login'])) {
            return $user['login'];
        }
        
        return null;
    }
    
    /**
     * Test if authentication is working correctly.
     *
     * @return bool|string True if authentication is working, error message otherwise.
     */
    public function test_authentication() {
        // First check if token is set
        if (empty($this->token)) {
            return 'No authentication token found';
        }
        
        // Make a simple request to the user endpoint to check authentication
        $response = $this->request('user');
        
        if (is_wp_error($response)) {
            return $response->get_error_message();
        }
        
        // If we got a valid response with a login, authentication is working
        if (isset($response['login'])) {
            return true;
        }
        
        return 'Unknown authentication error';
    }
    
    /**
     * Get information about the authenticated user.
     *
     * @return array|WP_Error User data or WP_Error on failure.
     */
    public function get_user() {
        return $this->request('user');
    }
    
    /**
     * Create an initial commit with WordPress files to a new repository.
     * 
     * @param string $branch The branch name to commit to.
     * @return bool|WP_Error True on success or WP_Error on failure.
     */
    public function initial_sync($branch = 'main') {
        // Set up basic commit information
        $user = $this->get_user();
        
        if (is_wp_error($user)) {
            return $user;
        }
        
        // Get site info for commit message
        $site_url = get_bloginfo('url');
        $site_name = get_bloginfo('name');
        
        // Create a temporary directory to prepare files
        $temp_dir = wp_tempnam('wp-github-sync-');
        @unlink($temp_dir); // Remove the file so we can create a directory with the same name
        wp_mkdir_p($temp_dir);
        
        // Define paths to sync
        $paths_to_sync = apply_filters('wp_github_sync_paths', [
            'wp-content/themes' => true,
            'wp-content/plugins' => true,
            'wp-content/uploads' => false, // Default to not sync media
        ]);
        
        // Prepare files to sync
        $result = $this->prepare_files_for_initial_sync($temp_dir, $paths_to_sync);
        
        if (is_wp_error($result)) {
            // Clean up
            $this->recursive_rmdir($temp_dir);
            return $result;
        }
        
        // Create a README.md file at the root
        $readme_content = "# {$site_name}\n\nWordPress site synced with GitHub.\n\nSite URL: {$site_url}\n\n";
        $readme_content .= "## About\n\nThis repository contains the themes, plugins, and configuration for the WordPress site.\n";
        $readme_content .= "It is managed by the [WordPress GitHub Sync](https://github.com/yourusername/wp-github-sync) plugin.\n";
        
        file_put_contents($temp_dir . '/README.md', $readme_content);
        
        // Create a .gitignore file
        $gitignore_content = "# WordPress core files\nwp-admin/\nwp-includes/\nwp-*.php\n\n";
        $gitignore_content .= "# Exclude sensitive files\nwp-config.php\n*.log\n.htaccess\n\n";
        $gitignore_content .= "# Exclude cache and backup files\n*.cache\n*.bak\n*~\n\n";
        
        file_put_contents($temp_dir . '/.gitignore', $gitignore_content);
        
        // Upload files to GitHub using the Git Data API
        try {
            $result = $this->upload_files_to_github($temp_dir, $branch, "Initial sync from {$site_name}");
            
            // Clean up temporary directory regardless of success or failure
            $this->recursive_rmdir($temp_dir);
            
            return $result;
        } catch (Exception $e) {
            // Clean up temporary directory on exception
            $this->recursive_rmdir($temp_dir);
            
            // Log exception details
            wp_github_sync_log("Exception during initial sync: " . $e->getMessage(), 'error');
            
            return new WP_Error('sync_exception', $e->getMessage());
        }
    }
    
    /**
     * Upload files to GitHub using the Git Data API.
     *
     * This method implements a complete workflow to upload files to GitHub:
     * 1. Gets the reference to the target branch
     * 2. Gets the commit the reference points to
     * 3. Gets the tree the commit points to
     * 4. Creates a new tree with the new files
     * 5. Creates a new commit pointing to the new tree
     * 6. Updates the reference to point to the new commit
     *
     * @param string $directory     Directory containing files to upload
     * @param string $branch        Branch to upload to
     * @param string $commit_message Commit message
     * @return bool|WP_Error True on success or WP_Error on failure
     */
    protected function upload_files_to_github($directory, $branch, $commit_message) {
        wp_github_sync_log("Starting GitHub upload process for branch: {$branch}", 'info');
        
        // Check if directory exists and is readable
        if (!is_dir($directory) || !is_readable($directory)) {
            return new WP_Error('invalid_directory', __('Directory does not exist or is not readable', 'wp-github-sync'));
        }
        
        // Validate branch name (basic validation)
        if (!preg_match('/^[a-zA-Z0-9_\-\.\/]+$/', $branch)) {
            return new WP_Error('invalid_branch', __('Invalid branch name', 'wp-github-sync'));
        }
        
        // Check rate limit before starting expensive operation
        $rate_limit = $this->request('rate_limit');
        if (!is_wp_error($rate_limit) && isset($rate_limit['resources']['core']['remaining'])) {
            $remaining = $rate_limit['resources']['core']['remaining'];
            if ($remaining < 100) { // Arbitrary threshold, adjust as needed
                wp_github_sync_log("GitHub API rate limit low: {$remaining} remaining", 'warning');
                // Continue anyway, but log warning
            }
        }
        
        // First, verify we can access the repository
        $repo_info = $this->get_repository();
        if (is_wp_error($repo_info)) {
            wp_github_sync_log("Repository access check failed: " . $repo_info->get_error_message(), 'error');
            return new WP_Error('github_api_repo_access', __('Cannot access repository. Please check your authentication and repository URL.', 'wp-github-sync'));
        }
        
        // Verify the branch is valid (basic validation)
        if (!preg_match('/^[a-zA-Z0-9_\-\.\/]+$/', $branch)) {
            wp_github_sync_log("Invalid branch name: {$branch}", 'error');
            return new WP_Error('invalid_branch', __('Invalid branch name. Branch names can only contain letters, numbers, dashes, underscores, dots, and forward slashes.', 'wp-github-sync'));
        }
        
        // Step 1: Get all branches to see what exists
        $all_branches = $this->get_branches();
        if (is_wp_error($all_branches)) {
            wp_github_sync_log("Failed to get branches: " . $all_branches->get_error_message(), 'error');
            return new WP_Error('github_api_error', __('Failed to get branches. Please check your authentication.', 'wp-github-sync'));
        }
        
        // Check if our target branch exists
        $branch_exists = false;
        $default_branch = isset($repo_info['default_branch']) ? $repo_info['default_branch'] : 'main';
        
        foreach ($all_branches as $branch_data) {
            if (isset($branch_data['name']) && $branch_data['name'] === $branch) {
                $branch_exists = true;
                break;
            }
        }
        
        if ($branch_exists) {
            // Branch exists, get reference (using both possible endpoint forms)
            // GitHub API v3 has slight inconsistencies in how it handles references
            $reference = $this->request("repos/{$this->owner}/{$this->repo}/git/refs/heads/{$branch}");
            
            // If the first form fails, try the alternative
            if (is_wp_error($reference)) {
                wp_github_sync_log("First reference endpoint failed, trying alternative", 'debug');
                $reference = $this->request("repos/{$this->owner}/{$this->repo}/git/refs/heads/{$branch}");
                
                // If both fail, return error
                if (is_wp_error($reference)) {
                    wp_github_sync_log("Failed to get reference for existing branch {$branch}: " . $reference->get_error_message(), 'error');
                    return new WP_Error('github_api_error', __('Failed to get branch reference. Please verify your GitHub authentication credentials have sufficient permissions.', 'wp-github-sync'));
                }
            }
        } else {
            // Branch doesn't exist, we need to create it
            wp_github_sync_log("Branch {$branch} not found. Attempting to create it from {$default_branch}.", 'info');
            
            // Get the SHA of the default branch
            $default_ref = $this->request("repos/{$this->owner}/{$this->repo}/git/refs/heads/{$default_branch}");
            if (is_wp_error($default_ref)) {
                wp_github_sync_log("Failed to get default branch reference: " . $default_ref->get_error_message(), 'error');
                
                // If default branch also doesn't exist, try to create an empty commit for the first branch
                if (strpos($default_ref->get_error_message(), 'Not Found') !== false) {
                    wp_github_sync_log("Default branch not found. Creating initial commit.", 'info');
                        
                        // Create an empty tree first
                        $empty_tree = $this->request(
                            "repos/{$this->owner}/{$this->repo}/git/trees",
                            'POST',
                            [
                                'tree' => []
                            ]
                        );
                        
                        if (is_wp_error($empty_tree)) {
                            wp_github_sync_log("Failed to create empty tree: " . $empty_tree->get_error_message(), 'error');
                            return new WP_Error('github_api_error', __('Failed to create initial commit tree', 'wp-github-sync'));
                        }
                        
                        // Create the initial commit with the empty tree
                        $initial_commit = $this->request(
                            "repos/{$this->owner}/{$this->repo}/git/commits",
                            'POST',
                            [
                                'message' => 'Initial commit',
                                'tree' => $empty_tree['sha'],
                                'parents' => []
                            ]
                        );
                        
                        if (is_wp_error($initial_commit)) {
                            wp_github_sync_log("Failed to create initial commit: " . $initial_commit->get_error_message(), 'error');
                            return new WP_Error('github_api_error', __('Failed to create initial commit', 'wp-github-sync'));
                        }
                        
                        // Create the main branch reference with the initial commit
                        $create_main_ref = $this->request(
                            "repos/{$this->owner}/{$this->repo}/git/refs",
                            'POST',
                            [
                                'ref' => "refs/heads/{$branch}",
                                'sha' => $initial_commit['sha']
                            ]
                        );
                        
                        if (is_wp_error($create_main_ref)) {
                            wp_github_sync_log("Failed to create main branch reference: " . $create_main_ref->get_error_message(), 'error');
                            return new WP_Error('github_api_error', __('Failed to create initial branch reference', 'wp-github-sync'));
                        }
                        
                        $reference = $create_main_ref;
                    } else {
                        return new WP_Error('github_api_error', __('Failed to get default branch reference', 'wp-github-sync'));
                    }
                } else {
                    // Create the new branch from default
                    $create_ref = $this->request(
                        "repos/{$this->owner}/{$this->repo}/git/refs",
                        'POST',
                        [
                            'ref' => "refs/heads/{$branch}",
                            'sha' => $default_ref['object']['sha']
                        ]
                    );
                    
                    if (is_wp_error($create_ref)) {
                        wp_github_sync_log("Failed to create new branch: " . $create_ref->get_error_message(), 'error');
                        return new WP_Error('github_api_error', __('Failed to create new branch', 'wp-github-sync'));
                    }
                    
                    $reference = $create_ref;
                }
            } else {
                wp_github_sync_log("Failed to get branch reference: " . $reference->get_error_message(), 'error');
                return new WP_Error('github_api_error', __('Failed to get branch reference', 'wp-github-sync'));
            }
        }
        
        $ref_sha = $reference['object']['sha'];
        wp_github_sync_log("Got reference SHA: {$ref_sha}", 'debug');
        
        // Step 2: Get the commit the reference points to
        $commit = $this->request("repos/{$this->owner}/{$this->repo}/git/commits/{$ref_sha}");
        
        if (is_wp_error($commit)) {
            return new WP_Error('github_api_error', __('Failed to get commit', 'wp-github-sync'));
        }
        
        $base_tree_sha = $commit['tree']['sha'];
        wp_github_sync_log("Got base tree SHA: {$base_tree_sha}", 'debug');
        
        // Step 3: Create blobs for each file
        $tree_items = [];
        $files_processed = 0;
        $total_size = 0;
        $upload_limit = 50 * 1024 * 1024; // 50MB recommended limit to avoid issues
        $max_files = 1000; // GitHub has limits on tree size
        
        // Recursive function to process directories
        $process_directory = function($dir, $base_path = '') use (&$process_directory, &$tree_items, &$files_processed, &$total_size, $upload_limit, $max_files) {
            $files = scandir($dir);
            
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                
                // Skip hidden files and common unwanted patterns
                if (strpos($file, '.') === 0 || in_array($file, ['node_modules', 'vendor', '.git', 'cache'])) {
                    wp_github_sync_log("Skipping '{$file}' (hidden or excluded pattern)", 'debug');
                    continue;
                }
                
                $path = $dir . '/' . $file;
                $relative_path = $base_path . ($base_path ? '/' : '') . $file;
                
                // Normalize the path to use forward slashes for GitHub
                $relative_path = str_replace('\\', '/', $relative_path);
                
                if (is_dir($path)) {
                    // For directories, recursively process them
                    $process_directory($path, $relative_path);
                } else {
                    // Check file limits
                    if ($files_processed >= $max_files) {
                        wp_github_sync_log("Reached max file limit ({$max_files}). Some files were not processed.", 'warning');
                        return;
                    }
                    
                    // Check if file is binary or text
                    $finfo = new finfo(FILEINFO_MIME);
                    $mime_type = $finfo->file($path);
                    $is_binary = (strpos($mime_type, 'text/') !== 0 && 
                                 strpos($mime_type, 'application/json') !== 0 &&
                                 strpos($mime_type, 'application/xml') !== 0);
                    
                    // Get file size
                    $file_size = filesize($path);
                    
                    // Skip if file is too large (GitHub API has a 100MB limit)
                    if ($file_size > 50 * 1024 * 1024) {
                        wp_github_sync_log("Skipping file {$relative_path} (too large: " . round($file_size/1024/1024, 2) . "MB)", 'warning');
                        continue;
                    }
                    
                    // Check if this file would exceed our total size limit
                    if ($total_size + $file_size > $upload_limit) {
                        wp_github_sync_log("Total upload limit reached. Skipping remaining files.", 'warning');
                        return;
                    }
                    
                    try {
                        // For files, create a blob and add to tree
                        $content = file_get_contents($path);
                        
                        // Skip if file couldn't be read
                        if ($content === false) {
                            wp_github_sync_log("Skipping file {$relative_path} (couldn't read file)", 'warning');
                            continue;
                        }
                        
                        // Create blob based on file type
                        $blob_data = [];
                        if ($is_binary) {
                            $blob_data = [
                                'content' => base64_encode($content),
                                'encoding' => 'base64'
                            ];
                        } else {
                            $blob_data = [
                                'content' => $content,
                                'encoding' => 'utf-8'
                            ];
                        }
                        
                        $blob = $this->request(
                            "repos/{$this->owner}/{$this->repo}/git/blobs",
                            'POST',
                            $blob_data
                        );
                        
                        if (is_wp_error($blob)) {
                            wp_github_sync_log("Failed to create blob for {$relative_path}: " . $blob->get_error_message(), 'error');
                            continue;
                        }
                        
                        // Determine file mode (executable or regular file)
                        $file_mode = '100644'; // Regular file
                        if (is_executable($path)) {
                            $file_mode = '100755'; // Executable file
                        }
                        
                        $tree_items[] = [
                            'path' => $relative_path,
                            'mode' => $file_mode,
                            'type' => 'blob',
                            'sha' => $blob['sha']
                        ];
                        
                        $files_processed++;
                        $total_size += $file_size;
                        
                        if ($files_processed % 25 === 0) {
                            wp_github_sync_log("Processed {$files_processed} files, total size: " . round($total_size/1024/1024, 2) . "MB", 'info');
                        }
                    } catch (Exception $e) {
                        wp_github_sync_log("Error processing file {$relative_path}: " . $e->getMessage(), 'error');
                        continue;
                    }
                }
            }
        };
        
        // Process the directory
        $process_directory($directory);
        
        if (empty($tree_items)) {
            return new WP_Error('github_api_error', __('No files to upload', 'wp-github-sync'));
        }
        
        wp_github_sync_log("Created " . count($tree_items) . " blobs", 'info');
        
        // Step 4: Create a new tree with the new files
        wp_github_sync_log("Creating tree with " . count($tree_items) . " files", 'info');
        
        // Check if we have a large number of files that may exceed GitHub's limits
        $max_items_per_request = 100; // GitHub may have issues with too many items in a single request
        $chunks = array_chunk($tree_items, $max_items_per_request);
        
        if (count($chunks) > 1) {
            wp_github_sync_log("Files split into " . count($chunks) . " chunks due to size", 'info');
            
            // We need to build the tree incrementally
            $current_base_tree = $base_tree_sha;
            
            foreach ($chunks as $index => $chunk) {
                wp_github_sync_log("Processing chunk " . ($index + 1) . " of " . count($chunks), 'info');
                
                $chunk_tree = $this->request(
                    "repos/{$this->owner}/{$this->repo}/git/trees",
                    'POST',
                    [
                        'base_tree' => $current_base_tree,
                        'tree' => $chunk
                    ]
                );
                
                if (is_wp_error($chunk_tree)) {
                    wp_github_sync_log("Failed to create tree chunk " . ($index + 1) . ": " . $chunk_tree->get_error_message(), 'error');
                    return new WP_Error('github_api_error', __('Failed to create tree chunk', 'wp-github-sync'));
                }
                
                // Use this tree as the base for the next chunk
                $current_base_tree = $chunk_tree['sha'];
                wp_github_sync_log("Created tree chunk " . ($index + 1) . " with SHA: {$current_base_tree}", 'debug');
            }
            
            $new_tree_sha = $current_base_tree;
        } else {
            // Single chunk approach for smaller trees
            $new_tree = $this->request(
                "repos/{$this->owner}/{$this->repo}/git/trees",
                'POST',
                [
                    'base_tree' => $base_tree_sha,
                    'tree' => $tree_items
                ]
            );
            
            if (is_wp_error($new_tree)) {
                wp_github_sync_log("Failed to create tree: " . $new_tree->get_error_message(), 'error');
                return new WP_Error('github_api_error', __('Failed to create tree', 'wp-github-sync'));
            }
            
            $new_tree_sha = $new_tree['sha'];
        }
        
        wp_github_sync_log("Final tree created with SHA: {$new_tree_sha}", 'info');
        
        // Step 5: Create a new commit
        try {
            $new_commit = $this->request(
                "repos/{$this->owner}/{$this->repo}/git/commits",
                'POST',
                [
                    'message' => $commit_message,
                    'tree' => $new_tree_sha,
                    'parents' => [$ref_sha]
                ]
            );
            
            if (is_wp_error($new_commit)) {
                wp_github_sync_log("Failed to create commit: " . $new_commit->get_error_message(), 'error');
                return new WP_Error('github_api_error', __('Failed to create commit', 'wp-github-sync'));
            }
            
            $new_commit_sha = $new_commit['sha'];
            wp_github_sync_log("Created new commit with SHA: {$new_commit_sha}", 'info');
            
            // Step 6: Update the reference
            $update_ref = $this->request(
                "repos/{$this->owner}/{$this->repo}/git/refs/heads/{$branch}",
                'PATCH',
                [
                    'sha' => $new_commit_sha,
                    'force' => true // Force update in case of non-fast-forward update
                ]
            );
            
            if (is_wp_error($update_ref)) {
                wp_github_sync_log("Failed to update reference: " . $update_ref->get_error_message(), 'error');
                return new WP_Error('github_api_error', __('Failed to update reference', 'wp-github-sync'));
            }
            
            wp_github_sync_log("Successfully updated reference to new commit", 'info');
            
            // Store this commit as the last deployed commit
            update_option('wp_github_sync_last_deployed_commit', $new_commit_sha);
            
            return true;
        } catch (Exception $e) {
            wp_github_sync_log("Exception during commit creation or reference update: " . $e->getMessage(), 'error');
            return new WP_Error('github_api_exception', $e->getMessage());
        }
    }
    
    /**
     * Prepare files for initial sync by copying them to a temporary directory.
     *
     * @param string $temp_dir     The temporary directory to copy files to.
     * @param array  $paths_to_sync Associative array of paths to sync and whether to include them.
     * @return bool|WP_Error True on success or WP_Error on failure.
     */
    private function prepare_files_for_initial_sync($temp_dir, $paths_to_sync) {
        // Get WP path constants
        $wp_content_dir = WP_CONTENT_DIR;
        $abspath = ABSPATH;
        
        // Create wp-content directory in temp dir
        wp_mkdir_p($temp_dir . '/wp-content');
        
        // Copy each path that's enabled
        foreach ($paths_to_sync as $path => $include) {
            if (!$include) {
                continue;
            }
            
            $source_path = $abspath . '/' . $path;
            $dest_path = $temp_dir . '/' . $path;
            
            // Make sure source path exists
            if (!file_exists($source_path)) {
                continue;
            }
            
            // Create destination directory
            wp_mkdir_p(dirname($dest_path));
            
            // Copy directory
            $this->copy_directory($source_path, $dest_path);
        }
        
        return true;
    }
    
    /**
     * Copy a directory recursively.
     *
     * @param string $source The source directory.
     * @param string $dest   The destination directory.
     * @return bool True on success or false on failure.
     */
    private function copy_directory($source, $dest) {
        // Create destination directory if it doesn't exist
        if (!file_exists($dest)) {
            wp_mkdir_p($dest);
        }
        
        // Get all files and directories
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $item) {
            $target_path = $dest . '/' . $iterator->getSubPathName();
            
            if ($item->isDir()) {
                // Create directory if it doesn't exist
                if (!file_exists($target_path)) {
                    wp_mkdir_p($target_path);
                }
            } else {
                // Copy file
                copy($item, $target_path);
            }
        }
        
        return true;
    }
    
    /**
     * Recursively remove a directory.
     *
     * @param string $dir The directory to remove.
     * @return bool True on success or false on failure.
     */
    private function recursive_rmdir($dir) {
        if (!file_exists($dir)) {
            return true;
        }
        
        if (!is_dir($dir)) {
            return unlink($dir);
        }
        
        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') {
                continue;
            }
            
            $path = $dir . '/' . $item;
            
            if (is_dir($path)) {
                $this->recursive_rmdir($path);
            } else {
                unlink($path);
            }
        }
        
        return rmdir($dir);
    }
}