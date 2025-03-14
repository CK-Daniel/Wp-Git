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
     * GitHub API version to use.
     * 
     * @var string
     */
    private $api_version = '2022-11-28';

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
     * Authentication method.
     * 
     * @var string pat|oauth|github_app
     */
    private $auth_method;

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
        
        // Get authentication method
        $this->auth_method = get_option('wp_github_sync_auth_method', 'pat');
        
        // First check for unencrypted token in environment for development purposes
        $dev_token = defined('WP_GITHUB_SYNC_DEV_TOKEN') ? WP_GITHUB_SYNC_DEV_TOKEN : '';
        if (!empty($dev_token)) {
            wp_github_sync_log("Using development token from environment variable", 'debug');
            $this->token = $dev_token;
        } else {
            if ($this->auth_method === 'pat') {
                // Personal Access Token
                $encrypted_token = get_option('wp_github_sync_access_token', '');
                if (!empty($encrypted_token)) {
                    $decrypted = wp_github_sync_decrypt($encrypted_token);
                    if ($decrypted !== false) {
                        $this->token = $decrypted;
                        wp_github_sync_log("Successfully decrypted PAT token", 'debug');
                    } else {
                        wp_github_sync_log("Failed to decrypt PAT token, clearing invalid token", 'error');
                        // Clear the invalid token to force re-authentication
                        update_option('wp_github_sync_access_token', '');
                        add_action('admin_notices', function() {
                            echo '<div class="notice notice-error is-dismissible">';
                            echo '<p>' . esc_html__('GitHub Sync token could not be decrypted. Please re-enter your GitHub token in the settings.', 'wp-github-sync') . '</p>';
                            echo '</div>';
                        });
                    }
                } else {
                    wp_github_sync_log("No PAT token found in options", 'error');
                }
            } elseif ($this->auth_method === 'oauth') {
                // OAuth token
                $encrypted_token = get_option('wp_github_sync_oauth_token', '');
                if (!empty($encrypted_token)) {
                    $decrypted = wp_github_sync_decrypt($encrypted_token);
                    if ($decrypted !== false) {
                        $this->token = $decrypted;
                        wp_github_sync_log("Successfully decrypted OAuth token", 'debug');
                    } else {
                        wp_github_sync_log("Failed to decrypt OAuth token, clearing invalid token", 'error');
                        // Clear the invalid token to force re-authentication
                        update_option('wp_github_sync_oauth_token', '');
                        add_action('admin_notices', function() {
                            echo '<div class="notice notice-error is-dismissible">';
                            echo '<p>' . esc_html__('GitHub Sync OAuth token could not be decrypted. Please reconnect your GitHub account in the settings.', 'wp-github-sync') . '</p>';
                            echo '</div>';
                        });
                    }
                } else {
                    wp_github_sync_log("No OAuth token found in options", 'error');
                }
            } elseif ($this->auth_method === 'github_app') {
                // Check if we need to generate a new GitHub App token
                // We don't store GitHub App tokens permanently, instead we generate them as needed
                // GitHub App installation tokens are valid for 1 hour
                $cached_token = get_transient('wp_github_sync_github_app_token');
                
                if (!empty($cached_token)) {
                    wp_github_sync_log("Using cached GitHub App token", 'debug');
                    $this->token = $cached_token;
                } else {
                    wp_github_sync_log("No cached GitHub App token found, will generate during request", 'debug');
                    // We'll generate the token later when needed in the request method
                }
            }
            }
        }
        
        // Load cached API metadata
        $this->load_api_metadata();
    }
    
    /**
     * Load cached API metadata like etags for conditional requests.
     */
    private function load_api_metadata() {
        // This will be used for implementing conditional requests
        $this->etags = get_option('wp_github_sync_etags', array());
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
        try {
            // Generate token for GitHub App if needed
            if ($this->auth_method === 'github_app' && empty($this->token)) {
                wp_github_sync_log("Generating GitHub App token for request", 'debug');
                $token = $this->generate_github_app_token();
                
                if (is_wp_error($token)) {
                    return $token;
                }
                
                $this->token = $token;
            }
            
            // Check if we have what we need to make a request
            if (empty($this->token)) {
                wp_github_sync_log("API request failed: No GitHub token available", 'error');
                return new \WP_Error('github_api_no_token', __('No GitHub authentication token available. Please check your settings.', 'wp-github-sync'));
            }
            
            // Check rate limits before making a request (except for rate_limit endpoint itself)
            if ($endpoint !== 'rate_limit') {
                $rate_limit_wait = $this->check_rate_limits();
                if ($rate_limit_wait) {
                    // If we're too close to the limit, either wait or return an error
                    if ($rate_limit_wait > 60) {
                        // If wait time is too long, just return an error
                        $reset_time = date('H:i:s', time() + $rate_limit_wait);
                        return new \WP_Error(
                            'github_api_rate_limit',
                            sprintf(__('GitHub API rate limit reached. Please try again after %s.', 'wp-github-sync'), $reset_time)
                        );
                    } else {
                        // For shorter wait times, pause before continuing
                        wp_github_sync_log("Pausing for {$rate_limit_wait} seconds to avoid rate limits", 'info');
                        sleep(min(5, $rate_limit_wait)); // Wait up to 5 seconds maximum
                    }
                }
            }
            
            // Check if this endpoint requires owner/repo information
            if (strpos($endpoint, 'repos/') === 0 && (empty($this->owner) || empty($this->repo))) {
                wp_github_sync_log("API request failed: Missing owner/repo for endpoint: {$endpoint}", 'error');
                return new \WP_Error('github_api_no_repo', __('Repository owner or name is missing. Please check your repository URL.', 'wp-github-sync'));
            }
            
            $url = trailingslashit($this->api_base_url) . ltrim($endpoint, '/');
            
            // Build request headers according to GitHub API documentation
            $headers = [
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'),
                'X-GitHub-Api-Version' => $this->api_version,
            ];
            
            // Add authorization header based on token type
            // From GitHub docs: "In most cases, you can use Authorization: Bearer or Authorization: token to pass a token.
            // However, if you are passing a JSON web token (JWT), you must use Authorization: Bearer."
            
            // Always use Bearer for GitHub App installation tokens (ghs_) and JSON Web Tokens
            if (strpos($this->token, 'ghs_') === 0 || strpos($this->token, 'ey') === 0) { // JWT tokens start with "ey"
                $headers['Authorization'] = 'Bearer ' . $this->token;
            } 
            // For all other tokens (PAT, OAuth, etc), use Bearer per GitHub's recommendations
            else {
                $headers['Authorization'] = 'Bearer ' . $this->token;
                
                // Log the token type being used (for debugging)
                if (strpos($this->token, 'gho_') === 0) {
                    wp_github_sync_log("Using OAuth token format", 'debug');
                } else if (strpos($this->token, 'github_pat_') === 0) {
                    wp_github_sync_log("Using fine-grained PAT token format", 'debug');
                } else if (strlen($this->token) === 40 && ctype_xdigit($this->token)) {
                    wp_github_sync_log("Using classic PAT token format", 'debug');
                }
            }
            
            // Add conditional request header if we have an etag
            $resource_key = md5($endpoint . json_encode($data));
            if (isset($this->etags[$resource_key])) {
                $headers['If-None-Match'] = $this->etags[$resource_key];
                wp_github_sync_log("Using etag for conditional request: " . $this->etags[$resource_key], 'debug');
            }
            
            $args = [
                'method' => $method,
                'headers' => $headers,
                'timeout' => 30,
                'sslverify' => true,
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
            wp_github_sync_log("Request to: {$url} (Method: {$method})", 'debug');
            wp_github_sync_log("Authorization token length: {$token_length}", 'debug');
            wp_github_sync_log("Authorization token prefix/suffix: {$token_start}...{$token_end}", 'debug');
            
            // Don't log body for security unless it's small and not sensitive
            if (!empty($args['body']) && strlen($args['body']) < 500 && strpos($args['body'], 'password') === false && strpos($args['body'], 'token') === false) {
                wp_github_sync_log("Request body: " . $args['body'], 'debug');
            }
            
            // Make the API request with extended error handling
            $response = wp_remote_request($url, $args);
            
            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                $error_code = $response->get_error_code();
                wp_github_sync_log("GitHub API request failed: Code: {$error_code}, Message: {$error_message}", 'error');
                
                // Provide more specific error messages for common issues
                if (strpos($error_message, 'cURL error 60') !== false) {
                    wp_github_sync_log("SSL Certificate issue detected", 'error');
                    // Try with SSL verification disabled as a fallback
                    $args['sslverify'] = false;
                    wp_github_sync_log("Retrying request with SSL verification disabled", 'warning');
                    $response = wp_remote_request($url, $args);
                    
                    if (is_wp_error($response)) {
                        return new \WP_Error('github_api_ssl_error', __('Failed to connect to GitHub API due to SSL certificate issues. Please check your server configuration.', 'wp-github-sync'));
                    }
                } else {
                    return $response; // Return the original error
                }
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            
            // Debug log the response
            wp_github_sync_log("Response code: {$response_code}", 'debug');
            
            // Log the raw response body for debugging if there's an error
            if ($response_code >= 400) {
                wp_github_sync_log("Error response body: " . $response_body, 'error');
            }
            
            // Parse the response body
            $response_data = json_decode($response_body, true);
            
            // Check for JSON parsing errors
            if ($response_body && json_last_error() !== JSON_ERROR_NONE) {
                wp_github_sync_log("JSON parse error: " . json_last_error_msg(), 'error');
                wp_github_sync_log("Response body (first 200 chars): " . substr($response_body, 0, 200), 'error');
                return new \WP_Error('github_api_json_error', __('Failed to parse GitHub API response as JSON.', 'wp-github-sync'));
            }
            
            // Handle and log rate limit information
            $rate_limit_remaining = wp_remote_retrieve_header($response, 'x-ratelimit-remaining');
            $rate_limit_reset = wp_remote_retrieve_header($response, 'x-ratelimit-reset');
            
            if ($rate_limit_remaining !== '') {
                // Store the rate limit info
                update_option('wp_github_sync_rate_limit_remaining', $rate_limit_remaining);
                
                $rate_limit_message = sprintf('GitHub API rate limit: %s remaining', $rate_limit_remaining);
                
                // If rate limit is getting low, log at warning level
                if ($rate_limit_remaining < 10) {
                    wp_github_sync_log($rate_limit_message, 'warning');
                    
                    // Also log when the rate limit will reset
                    if ($rate_limit_reset !== '') {
                        $reset_time = date('Y-m-d H:i:s', (int)$rate_limit_reset);
                        wp_github_sync_log(sprintf('Rate limit will reset at: %s', $reset_time), 'warning');
                    }
                    
                    // If very low, pause for a while to avoid hitting limits
                    if ($rate_limit_remaining < 3 && $endpoint !== 'rate_limit') {
                        $sleep_seconds = 2;
                        wp_github_sync_log(sprintf('Rate limit critical, pausing requests for %d seconds', $sleep_seconds), 'warning');
                        sleep($sleep_seconds);
                    }
                } else {
                    wp_github_sync_log($rate_limit_message, 'debug');
                }
            }
            
            // Handle 304 Not Modified (conditional request) responses
            if ($response_code === 304) {
                wp_github_sync_log("Resource not modified (304) - using cached version", 'debug');
                
                // Return the cached data if available
                $cache_key = 'wp_github_sync_cache_' . md5($endpoint . json_encode($data));
                $cached_data = get_transient($cache_key);
                
                if ($cached_data !== false) {
                    return $cached_data;
                } else {
                    // If we somehow got a 304 but don't have cached data, make a fresh request
                    unset($this->etags[$resource_key]);
                    update_option('wp_github_sync_etags', $this->etags);
                    return $this->request($endpoint, $method, $data);
                }
            }
            
            // Check for API errors
            if ($response_code >= 400) {
                $error_message = isset($response_data['message']) ? $response_data['message'] : "Unknown API error (HTTP {$response_code})";
                wp_github_sync_log("GitHub API error ({$response_code}): {$error_message}", 'error');
                
                // Handle rate limiting and abuse prevention according to documentation
                if ($response_code === 403) {
                    // Check for rate limit messages
                    if (strpos($error_message, 'rate limit') !== false) {
                        $retry_after = wp_remote_retrieve_header($response, 'retry-after');
                        $rate_reset = wp_remote_retrieve_header($response, 'x-ratelimit-reset');
                        
                        if (!empty($retry_after)) {
                            $wait_time = (int)$retry_after;
                            wp_github_sync_log("Rate limit exceeded. Retry-After: {$wait_time} seconds", 'warning');
                            
                            // Store the retry-after timestamp
                            set_transient('wp_github_sync_retry_after', time() + $wait_time, $wait_time + 5);
                            
                            return new \WP_Error("github_api_rate_limit", 
                                sprintf(__('GitHub API rate limit exceeded. Please wait %d seconds before retrying.', 'wp-github-sync'), $wait_time),
                                ['retry_after' => $wait_time]
                            );
                        } elseif (!empty($rate_reset)) {
                            $wait_time = (int)$rate_reset - time();
                            if ($wait_time > 0) {
                                wp_github_sync_log("Rate limit exceeded. Reset in: {$wait_time} seconds", 'warning');
                                
                                // Update our cached rate limit values
                                update_option('wp_github_sync_rate_limit_remaining', 0);
                                update_option('wp_github_sync_rate_limit_reset', $rate_reset);
                                
                                return new \WP_Error("github_api_rate_limit", 
                                    sprintf(__('GitHub API rate limit exceeded. Resets in %d seconds.', 'wp-github-sync'), $wait_time),
                                    ['reset_in' => $wait_time]
                                );
                            }
                        }
                    } 
                    // Check for secondary rate limit / abuse detection
                    else if (
                        strpos($error_message, 'secondary rate limit') !== false || 
                        strpos($error_message, 'abuse detection') !== false
                    ) {
                        $retry_after = wp_remote_retrieve_header($response, 'retry-after');
                        
                        if (!empty($retry_after)) {
                            $wait_time = (int)$retry_after;
                            wp_github_sync_log("Secondary rate limit detected. Retry-After: {$wait_time} seconds", 'warning');
                            
                            // Store the retry-after timestamp
                            set_transient('wp_github_sync_retry_after', time() + $wait_time, $wait_time + 5);
                            
                            return new \WP_Error("github_api_secondary_limit", 
                                sprintf(__('GitHub API secondary rate limit exceeded. Please wait %d seconds before retrying.', 'wp-github-sync'), $wait_time),
                                ['retry_after' => $wait_time]
                            );
                        } else {
                            // Get current attempt number
                            $attempt = get_option('wp_github_sync_secondary_ratelimit_attempt', 1);
                            
                            // Implement exponential backoff
                            $backoff = $this->set_secondary_rate_limit_backoff($attempt);
                            
                            return new \WP_Error("github_api_secondary_limit", 
                                sprintf(__('GitHub API secondary rate limit exceeded. Using exponential backoff (%d seconds).', 'wp-github-sync'), $backoff),
                                ['backoff' => $backoff]
                            );
                        }
                    }
                }
                
                // Additional information for specific error cases
                if ($error_message === 'Bad credentials') {
                    $token_length = strlen($this->token);
                    $token_start = $token_length > 4 ? substr($this->token, 0, 4) : '****';
                    $token_end = $token_length > 4 ? substr($this->token, -4) : '****';
                    wp_github_sync_log("Bad credentials error. Token info - Length: {$token_length}, Start: {$token_start}, End: {$token_end}", 'error');
                    
                    // Check if token might be empty or invalid
                    if ($token_length < 10) {
                        wp_github_sync_log("Token appears to be too short or empty", 'error');
                    }
                    
                    return new \WP_Error("github_api_unauthorized", __('GitHub API authentication failed. Please check your access token.', 'wp-github-sync'));
                }
                
                // Include more details if available
                if (isset($response_data['errors']) && is_array($response_data['errors'])) {
                    $details = wp_json_encode($response_data['errors']);
                    wp_github_sync_log("Error details: " . $details, 'error');
                    $error_message .= ' - ' . $details;
                }
                
                return new \WP_Error("github_api_{$response_code}", $error_message);
            }
            
            // Store the ETag for conditional requests if provided
            $etag = wp_remote_retrieve_header($response, 'etag');
            if (!empty($etag)) {
                wp_github_sync_log("Storing ETag for future conditional requests: " . $etag, 'debug');
                $this->etags[$resource_key] = $etag;
                update_option('wp_github_sync_etags', $this->etags);
                
                // Also cache the response data
                $cache_key = 'wp_github_sync_cache_' . md5($endpoint . json_encode($data));
                set_transient($cache_key, $response_data, DAY_IN_SECONDS); // Cache for 24 hours
            }
            
            return $response_data;
            
        } catch (Exception $e) {
            // Catch any exceptions during the request
            $error_message = "Exception during API request: " . $e->getMessage();
            wp_github_sync_log($error_message, 'error');
            return new \WP_Error('github_api_exception', $error_message);
        }
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
    public function get_commits($branch = '', $count = 10) {
        // Use provided branch or get default branch
        $branchToUse = !empty($branch) ? $branch : $this->get_default_branch();
        
        return $this->request("repos/{$this->owner}/{$this->repo}/commits", 'GET', [
            'sha' => $branchToUse,
            'per_page' => $count,
        ]);
    }

    /**
     * Get the latest commit for a specific branch.
     *
     * @param string $branch The branch name.
     * @return array|\WP_Error Commit data or WP_Error on failure.
     */
    public function get_latest_commit($branch = '') {
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
    public function get_contents($path, $ref = '') {
        // Use provided ref or get default branch
        $refToUse = !empty($ref) ? $ref : $this->get_default_branch();
        
        return $this->request("repos/{$this->owner}/{$this->repo}/contents/{$path}", 'GET', [
            'ref' => $refToUse,
        ]);
    }

    /**
     * Get the download URL for a repository archive at a specific reference.
     *
     * @param string $ref The branch or commit reference.
     * @return string The download URL.
     */
    public function get_archive_url($ref = '') {
        // Use provided ref or get default branch
        $refToUse = !empty($ref) ? $ref : $this->get_default_branch();
        
        return "https://api.github.com/repos/{$this->owner}/{$this->repo}/zipball/{$refToUse}";
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
            
            // Also test the token immediately to see if it's valid
            try {
                $test_result = $this->test_authentication();
                if ($test_result === true) {
                    wp_github_sync_log("Temporary token successfully authenticated", 'debug');
                } else {
                    wp_github_sync_log("Temporary token authentication failed: " . $test_result, 'error');
                }
            } catch (Exception $e) {
                wp_github_sync_log("Exception testing temporary token: " . $e->getMessage(), 'error');
            }
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
        
        // Log token details for debugging
        $token_length = strlen($this->token);
        $token_masked = substr($this->token, 0, 4) . '...' . substr($this->token, -4);
        wp_github_sync_log("Testing authentication with token length: {$token_length}, token: {$token_masked}", 'debug');
        
        try {
            // Make a simple request directly to the GitHub API to avoid any internal abstractions
            $url = $this->api_base_url . '/user';
            
            // Determine the authorization header format based on the token type
            $auth_header = 'Bearer ' . $this->token;
            
            // Add headers according to GitHub documentation
            $args = array(
                'headers' => array(
                    'Accept' => 'application/vnd.github+json',
                    'Authorization' => $auth_header,
                    'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'),
                    'X-GitHub-Api-Version' => $this->api_version,
                ),
                'timeout' => 30,
            );
            
            // Log the token format for debugging
            if (strpos($this->token, 'ghs_') === 0) {
                wp_github_sync_log("Testing with GitHub App installation token", 'debug');
            } else if (strpos($this->token, 'gho_') === 0) {
                wp_github_sync_log("Testing with OAuth token", 'debug');
            } else if (strpos($this->token, 'github_pat_') === 0) {
                wp_github_sync_log("Testing with fine-grained personal access token", 'debug');
            } else if (strlen($this->token) === 40 && ctype_xdigit($this->token)) {
                wp_github_sync_log("Testing with classic personal access token", 'debug');
            } else {
                wp_github_sync_log("Testing with unidentified token format", 'debug');
            }
            
            wp_github_sync_log("Making direct request to GitHub API: {$url}", 'debug');
            $response = wp_remote_get($url, $args);
            
            if (is_wp_error($response)) {
                $error_message = "WP_Error: " . $response->get_error_message();
                wp_github_sync_log("Direct API request failed: {$error_message}", 'error');
                return $error_message;
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            
            wp_github_sync_log("API response code: {$response_code}", 'debug');
            
            // Check for bad response codes
            if ($response_code >= 400) {
                $error_data = json_decode($body, true);
                $error_message = isset($error_data['message']) ? $error_data['message'] : "HTTP error {$response_code}";
                wp_github_sync_log("API error: {$error_message}", 'error');
                
                // For bad credentials, provide more details
                if ($error_message === 'Bad credentials') {
                    return 'Invalid GitHub token (Bad credentials). Please check your token and make sure it has the necessary permissions.';
                }
                
                return $error_message;
            }
            
            // Parse the response
            $data = json_decode($body, true);
            
            // If we got a valid response with a login, authentication is working
            if (isset($data['login'])) {
                wp_github_sync_log("Authentication successful as user: {$data['login']}", 'debug');
                return true;
            }
            
            // If we get here, something unexpected happened
            wp_github_sync_log("Authentication check: Unexpected response format", 'warning');
            return 'Valid response received, but user data is missing. Please try again.';
            
        } catch (Exception $e) {
            $error_message = "Exception: " . $e->getMessage();
            wp_github_sync_log("Exception during authentication test: {$error_message}", 'error');
            return $error_message;
        }
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
    public function create_repository($repo_name, $description = '', $private = false, $auto_init = true, $default_branch = '') {
        // If default_branch is empty, use a common default
        $default_branch = !empty($default_branch) ? $default_branch : 'main';
        if (empty($this->token)) {
            wp_github_sync_log("Cannot create repository: No GitHub token available", 'error');
            return new \WP_Error('github_api_not_configured', __('GitHub API token is not configured.', 'wp-github-sync'));
        }
        
        // First verify that authentication is working before trying to create a repo
        $auth_test = $this->test_authentication();
        if ($auth_test !== true) {
            wp_github_sync_log("Cannot create repository: Authentication failed: " . $auth_test, 'error');
            return new \WP_Error('github_auth_failed', sprintf(__('GitHub authentication failed: %s', 'wp-github-sync'), $auth_test));
        }
        
        wp_github_sync_log("Creating new repository: " . $repo_name, 'info');
        
        $data = [
            'name' => $repo_name,
            'description' => empty($description) ? __('WordPress site synced with GitHub', 'wp-github-sync') : $description,
            'private' => (bool) $private,
            'auto_init' => (bool) $auto_init,
        ];
        
        // GitHub no longer accepts default_branch in the initial creation request
        // This must be set separately after creation
        
        try {
            // Make a direct request without requiring owner/repo to be set
            $url = trailingslashit($this->api_base_url) . 'user/repos';
            
            $args = [
                'method' => 'POST',
                'headers' => [
                    'Accept' => 'application/vnd.github+json',
                    'Authorization' => 'Bearer ' . $this->token,
                    'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'),
                    'X-GitHub-Api-Version' => $this->api_version,
                    'Content-Type' => 'application/json',
                ],
                'timeout' => 30,
                'body' => wp_json_encode($data),
            ];
            
            wp_github_sync_log("Making repository creation request to: " . $url, 'debug');
            $response = wp_remote_request($url, $args);
            
            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                wp_github_sync_log('GitHub API request failed: ' . $error_message, 'error');
                return new \WP_Error('github_api_request_failed', $error_message);
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            wp_github_sync_log("Repository creation response code: " . $response_code, 'debug');
            
            // Log the raw response for debugging
            if ($response_code >= 400) {
                wp_github_sync_log("Repository creation error response body: " . $response_body, 'error');
            } else {
                wp_github_sync_log("Repository creation response received", 'debug');
            }
            
            $response_data = json_decode($response_body, true);
            
            // Check for API errors
            if ($response_code >= 400) {
                $error_message = isset($response_data['message']) ? $response_data['message'] : "Unknown API error (HTTP {$response_code})";
                wp_github_sync_log("GitHub API error ({$response_code}): {$error_message}", 'error');
                
                // If there are more error details, log them
                if (isset($response_data['errors']) && is_array($response_data['errors'])) {
                    $error_details = json_encode($response_data['errors']);
                    wp_github_sync_log("Error details: " . $error_details, 'error');
                    $error_message .= ' - ' . $error_details;
                }
                
                return new \WP_Error("github_api_{$response_code}", $error_message);
            }
            
            // Update the owner and repo properties
            if (isset($response_data['owner']['login']) && isset($response_data['name'])) {
                $this->owner = $response_data['owner']['login'];
                $this->repo = $response_data['name'];
                wp_github_sync_log("Repository created successfully: {$this->owner}/{$this->repo}", 'info');
            } else {
                wp_github_sync_log("Repository created but owner/name data missing from response", 'warning');
            }
            
            return $response_data;
            
        } catch (Exception $e) {
            $error_message = "Exception creating repository: " . $e->getMessage();
            wp_github_sync_log($error_message, 'error');
            return new \WP_Error('github_api_exception', $error_message);
        }
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
    
    /**
     * Check if we're close to hitting rate limits and should wait before making more requests.
     * 
     * Following GitHub's best practices for handling rate limits:
     * 1. Check retry-after header
     * 2. Use x-ratelimit-reset for primary rate limit
     * 3. Implement exponential backoff for secondary rate limits
     * 
     * @return bool|int False if rate limits are fine, or seconds to wait if limits are low
     */
    public function check_rate_limits() {
        // First check for secondary rate limit backoff
        $secondary_backoff = get_transient('wp_github_sync_secondary_ratelimit_backoff');
        if ($secondary_backoff !== false) {
            return (int)$secondary_backoff;
        }
        
        // Check for retry-after directive
        $retry_after = get_transient('wp_github_sync_retry_after');
        if ($retry_after !== false && $retry_after > time()) {
            $wait_time = $retry_after - time();
            wp_github_sync_log("Retry-After period active: wait {$wait_time} seconds", 'warning');
            return $wait_time;
        }
        
        // Check primary rate limit info
        $rate_limit_remaining = get_option('wp_github_sync_rate_limit_remaining', false);
        $rate_limit_reset = get_option('wp_github_sync_rate_limit_reset', false);
        
        // If we don't have cached info or we need fresh data
        if ($rate_limit_remaining === false || $rate_limit_remaining < 10) {
            // Make a direct request to get rate limit info
            // We avoid using $this->request to prevent recursion
            $url = trailingslashit($this->api_base_url) . 'rate_limit';
            
            $args = [
                'headers' => [
                    'Accept' => 'application/vnd.github+json',
                    'Authorization' => 'Bearer ' . $this->token,
                    'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'),
                    'X-GitHub-Api-Version' => $this->api_version,
                ],
                'timeout' => 15,
            ];
            
            $response = wp_remote_get($url, $args);
            
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $body = wp_remote_retrieve_body($response);
                $result = json_decode($body, true);
                
                if (isset($result['resources']['core'])) {
                    $rate_limit_remaining = $result['resources']['core']['remaining'];
                    $rate_limit_reset = $result['resources']['core']['reset'];
                    
                    // Update our cached values
                    update_option('wp_github_sync_rate_limit_remaining', $rate_limit_remaining);
                    update_option('wp_github_sync_rate_limit_reset', $rate_limit_reset);
                    
                    wp_github_sync_log("Fresh rate limit data: {$rate_limit_remaining} remaining, resets at " . 
                        date('Y-m-d H:i:s', $rate_limit_reset), 'debug');
                }
            } else {
                // If we can't get fresh data, be conservative
                $rate_limit_remaining = 1;
                wp_github_sync_log("Could not fetch rate limit info, being conservative", 'warning');
            }
        }
        
        // Handle different levels of rate limit remaining
        if ($rate_limit_remaining !== false) {
            if ($rate_limit_remaining < 3) {
                wp_github_sync_log("Rate limit critically low: {$rate_limit_remaining} remaining", 'warning');
                
                // If we have reset time, calculate how long to wait
                if ($rate_limit_reset !== false) {
                    $now = time();
                    $wait_time = $rate_limit_reset - $now;
                    
                    // If reset is in the future, suggest waiting
                    if ($wait_time > 0) {
                        wp_github_sync_log("Rate limit will reset in {$wait_time} seconds", 'warning');
                        return min($wait_time, 60); // Wait at most 60 seconds
                    }
                }
                
                // Default wait time if we can't determine exact time
                return 60;
            } else if ($rate_limit_remaining < 10) {
                // Getting low, add some delay between requests
                wp_github_sync_log("Rate limit getting low: {$rate_limit_remaining} remaining", 'debug');
                return 2; // Short pause between requests
            }
        }
        
        return false;
    }
    
    /**
     * Set a backoff period for secondary rate limits.
     * 
     * This implements exponential backoff as recommended by GitHub.
     * 
     * @param int $attempt The current attempt number (starts at 1)
     */
    private function set_secondary_rate_limit_backoff($attempt = 1) {
        // Exponential backoff: 2^attempt seconds with jitter (random 0-1s)
        $backoff = pow(2, min($attempt, 6)) + mt_rand(0, 1000) / 1000;
        $wait_until = time() + $backoff;
        
        wp_github_sync_log("Setting secondary rate limit backoff: {$backoff} seconds (attempt {$attempt})", 'warning');
        set_transient('wp_github_sync_secondary_ratelimit_backoff', $backoff, $backoff);
        
        // Store the attempt count for next time
        update_option('wp_github_sync_secondary_ratelimit_attempt', $attempt + 1);
        
        return $backoff;
    }
    
    /**
     * Reset secondary rate limit backoff after successful requests.
     */
    private function reset_secondary_rate_limit_backoff() {
        delete_transient('wp_github_sync_secondary_ratelimit_backoff');
        update_option('wp_github_sync_secondary_ratelimit_attempt', 1);
    }
    
    /**
     * Get the default branch for a repository.
     *
     * @return string The default branch (e.g., 'main' or 'master').
     */
    public function get_default_branch() {
        $repo_info = $this->get_repository();
        
        if (is_wp_error($repo_info) || !isset($repo_info['default_branch'])) {
            return 'main'; // Default fallback
        }
        
        return $repo_info['default_branch'];
    }
    
    /**
     * Generate a GitHub App installation token
     * 
     * This follows the GitHub App authentication flow:
     * 1. Generate a JWT using the App's private key
     * 2. Use the JWT to get an installation access token
     * 
     * @return string|WP_Error The installation token or WP_Error on failure
     */
    public function generate_github_app_token() {
        if ($this->auth_method !== 'github_app') {
            return new \WP_Error('invalid_auth_method', __('This method requires GitHub App authentication.', 'wp-github-sync'));
        }
        
        // Get the GitHub App settings
        $app_id = get_option('wp_github_sync_github_app_id', '');
        $installation_id = get_option('wp_github_sync_github_app_installation_id', '');
        $encrypted_key = get_option('wp_github_sync_github_app_key', '');
        
        if (empty($app_id) || empty($installation_id) || empty($encrypted_key)) {
            return new \WP_Error('missing_github_app_config', __('GitHub App configuration is incomplete. Please check your settings.', 'wp-github-sync'));
        }
        
        // First check if we have a cached token
        $cached_token = get_transient('wp_github_sync_github_app_token');
        if (!empty($cached_token)) {
            wp_github_sync_log('Using cached GitHub App token', 'debug');
            return $cached_token;
        }
        
        // Decrypt the private key
        $private_key = wp_github_sync_decrypt($encrypted_key);
        if ($private_key === false) {
            return new \WP_Error('invalid_key', __('Could not decrypt GitHub App private key.', 'wp-github-sync'));
        }
        
        // Generate JWT token
        try {
            // Make sure we have the JWT library
            if (!class_exists('Firebase\JWT\JWT')) {
                // Try to load the library via composer autoload
                if (file_exists(WP_GITHUB_SYNC_PLUGIN_DIR . 'vendor/autoload.php')) {
                    require_once WP_GITHUB_SYNC_PLUGIN_DIR . 'vendor/autoload.php';
                }
                
                // If still not available, return an error
                if (!class_exists('Firebase\JWT\JWT')) {
                    return new \WP_Error('missing_jwt_library', __('JWT library not found. Please run composer install.', 'wp-github-sync'));
                }
            }
            
            // Generate JWT token according to GitHub's documentation
            // See: https://docs.github.com/en/apps/creating-github-apps/authenticating-with-a-github-app/generating-a-json-web-token-jwt-for-a-github-app
            $jwt_payload = [
                // Issued at time (60 seconds in the past to allow for clock drift)
                'iat' => time() - 60,
                // Expiration time (10 minutes in the future)
                // GitHub docs specify maximum expiration time of 10 minutes
                'exp' => time() + 600,
                // GitHub App identifier - required for GitHub to identify which app is making the request
                'iss' => $app_id
            ];
            
            $jwt = \Firebase\JWT\JWT::encode($jwt_payload, $private_key, 'RS256');
            
            // Use JWT to request an installation token
            $url = $this->api_base_url . '/app/installations/' . $installation_id . '/access_tokens';
            
            $args = [
                'method' => 'POST',
                'headers' => [
                    'Accept' => 'application/vnd.github+json',
                    'Authorization' => 'Bearer ' . $jwt,
                    'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'),
                    'X-GitHub-Api-Version' => $this->api_version,
                ],
                'timeout' => 30,
            ];
            
            wp_github_sync_log('Requesting GitHub App installation token', 'debug');
            $response = wp_remote_request($url, $args);
            
            if (is_wp_error($response)) {
                wp_github_sync_log('Error requesting GitHub App token: ' . $response->get_error_message(), 'error');
                return $response;
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            $response_data = json_decode($response_body, true);
            
            if ($response_code !== 201 || !isset($response_data['token'])) {
                $error_message = isset($response_data['message']) ? $response_data['message'] : 'Unknown error';
                wp_github_sync_log('GitHub App token request failed: ' . $error_message, 'error');
                return new \WP_Error('github_app_token_failed', $error_message);
            }
            
            // Cache the token (GitHub App tokens are valid for 1 hour)
            $token = $response_data['token'];
            $expires_at = isset($response_data['expires_at']) ? strtotime($response_data['expires_at']) : (time() + 3600);
            $cache_time = $expires_at - time() - 60; // Subtract 60 seconds for safety
            
            set_transient('wp_github_sync_github_app_token', $token, $cache_time);
            wp_github_sync_log('Successfully generated GitHub App token, valid until ' . date('Y-m-d H:i:s', $expires_at), 'debug');
            
            return $token;
            
        } catch (\Exception $e) {
            wp_github_sync_log('Exception generating GitHub App token: ' . $e->getMessage(), 'error');
            return new \WP_Error('github_app_token_exception', $e->getMessage());
        }
    }
}