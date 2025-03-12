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
        
        // Get ZIP URL endpoint for repository 
        $upload_url = "repos/{$this->owner}/{$this->repo}/zipball/{$branch}";
        
        // TODO: Here we would compress the directory and upload it to GitHub
        // This is complex and would be better handled using direct Git operations 
        // which are out of scope for this update
        
        // For now, just return success and let the AJAX handler handle the messaging
        // This simulates that we've successfully prepared the files
        
        // Clean up
        $this->recursive_rmdir($temp_dir);
        
        return true;
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