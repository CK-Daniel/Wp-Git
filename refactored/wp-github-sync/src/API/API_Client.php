<?php
/**
 * GitHub API integration for the WordPress GitHub Sync plugin.
 *
 * @package WPGitHubSync
 */

namespace WPGitHubSync\API;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * GitHub API integration class.
 */
class API_Client {

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
            return new \WP_Error('github_api_no_token', __('No GitHub authentication token available. Please check your settings.', 'wp-github-sync'));
        }
        
        // Check if this endpoint requires owner/repo information
        if (strpos($endpoint, 'repos/') === 0 && (empty($this->owner) || empty($this->repo))) {
            wp_github_sync_log("API request failed: Missing owner/repo for endpoint: {$endpoint}", 'error');
            return new \WP_Error('github_api_no_repo', __('Repository owner or name is missing. Please check your repository URL.', 'wp-github-sync'));
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
            
            return new \WP_Error("github_api_{$response_code}", $error_message);
        }
        
        return $response_data;
    }

    /**
     * Get repository information.
     *
     * @return array|\WP_Error Repository information or WP_Error on failure.
     */
    public function get_repository() {
        return $this->request("repos/{$this->owner}/{$this->repo}");
    }

    /**
     * Get branches for the repository.
     *
     * @return array|\WP_Error List of branches or WP_Error on failure.
     */
    public function get_branches() {
        return $this->request("repos/{$this->owner}/{$this->repo}/branches");
    }

    /**
     * Get commits for a specific branch.
     *
     * @param string $branch The branch name.
     * @param int    $count  The number of commits to fetch.
     * @return array|\WP_Error List of commits or WP_Error on failure.
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
     * @return array|\WP_Error Commit data or WP_Error on failure.
     */
    public function get_latest_commit($branch = 'main') {
        $commits = $this->get_commits($branch, 1);
        
        if (is_wp_error($commits)) {
            return $commits;
        }
        
        if (empty($commits) || !isset($commits[0])) {
            return new \WP_Error('no_commits', __('No commits found for this branch.', 'wp-github-sync'));
        }
        
        return $commits[0];
    }

    /**
     * Get the contents of a file from the repository.
     *
     * @param string $path The file path within the repository.
     * @param string $ref  The branch or commit reference.
     * @return array|\WP_Error File contents or WP_Error on failure.
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
     * Check if the authentication token is valid.
     *
     * @return bool True if token is valid, false otherwise.
     */
    public function is_token_valid() {
        $response = $this->request('user');
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
     * @return array|\WP_Error User data or WP_Error on failure.
     */
    public function get_user() {
        return $this->request('user');
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
     * Create a new repository for the authenticated user.
     *
     * @param string $repo_name      The name of the repository to create.
     * @param string $description    Optional. The repository description.
     * @param bool   $private        Optional. Whether the repository should be private. Default false.
     * @param bool   $auto_init      Optional. Whether to initialize the repository with a README. Default true.
     * @param string $default_branch Optional. The default branch name. Default 'main'.
     * @return array|\WP_Error Repository data or WP_Error on failure.
     */
    public function create_repository($repo_name, $description = '', $private = false, $auto_init = true, $default_branch = 'main') {
        if (empty($this->token)) {
            return new \WP_Error('github_api_not_configured', __('GitHub API token is not configured.', 'wp-github-sync'));
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
            return new \WP_Error("github_api_{$response_code}", $error_message);
        }
        
        // Update the owner and repo properties
        if (isset($response_data['owner']['login']) && isset($response_data['name'])) {
            $this->owner = $response_data['owner']['login'];
            $this->repo = $response_data['name'];
        }
        
        return $response_data;
    }

    /**
     * Get access to the owner property.
     * 
     * @return string The repository owner.
     */
    public function get_owner() {
        return $this->owner;
    }

    /**
     * Get access to the repo property.
     * 
     * @return string The repository name.
     */
    public function get_repo() {
        return $this->repo;
    }
}