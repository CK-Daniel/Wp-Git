<?php
/**
 * GitHub API integration for the WordPress GitHub Sync plugin.
 * Orchestrates API requests using helper classes for authentication, rate limiting, and execution.
 *
 * @package WPGitHubSync\API
 */

namespace WPGitHubSync\API;

// Use helper classes
use WPGitHubSync\API\AuthManager;
use WPGitHubSync\API\RateLimitHandler;
use WPGitHubSync\API\RequestHandler;
use WPGitHubSync\API\RequestRetryHandler; // Trait

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * GitHub API integration class.
 */
class API_Client {

    use RequestRetryHandler; // Use the retry trait

    /**
     * GitHub API base URL.
     * @var string
     */
    private $api_base_url = 'https://api.github.com';

    /**
     * GitHub API version to use.
     * @var string
     */
    private $api_version = '2022-11-28';

    /**
     * GitHub repository owner.
     * @var string|null
     */
    private $owner = null;

    /**
     * GitHub repository name.
     * @var string|null
     */
    private $repo = null;

    /**
     * Authentication Manager instance.
     * @var AuthManager
     */
    private $auth_manager;

    /**
     * Rate Limit Handler instance.
     * @var RateLimitHandler
     */
    private $rate_limit_handler;

    /**
     * Request Handler instance.
     * @var RequestHandler
     */
    private $request_handler;

    /**
     * Cached ETags for conditional requests.
     * @var array
     */
    private $etags = [];

    /**
     * Initialize the GitHub API client.
     */
    public function __construct() {
        // Instantiate helpers first
        $this->auth_manager = new AuthManager();
        $this->rate_limit_handler = new RateLimitHandler($this); // Pass self for rate_limit requests
        $this->request_handler = new RequestHandler();

        $this->initialize();
    }

    /**
     * Initialize the GitHub API client with repository and authentication information.
     * Loads repository URL, initializes AuthManager, and loads ETags.
     */
    public function initialize() {
        $repo_url = get_option('wp_github_sync_repository', '');
        wp_github_sync_log("API Client: Initializing...", 'debug');

        // Parse repository URL
        if (!empty($repo_url)) {
            $parsed_url = $this->parse_github_url($repo_url);
            if ($parsed_url) {
                $this->owner = $parsed_url['owner'];
                $this->repo = $parsed_url['repo'];
                wp_github_sync_log("API Client: Repository set to {$this->owner}/{$this->repo}", 'debug');
            } else {
                wp_github_sync_log("API Client: Failed to parse repository URL: '{$repo_url}'", 'error');
            }
        } else {
            wp_github_sync_log("API Client: Repository URL is empty in settings.", 'warning');
        }

        // Load authentication settings via AuthManager
        $this->auth_manager->load_auth_settings();

        // Load cached ETags
        $this->etags = get_option('wp_github_sync_etags', array());
        wp_github_sync_log("API Client: Initialization complete.", 'debug');
    }

    /**
     * Parse a GitHub URL to extract owner and repository name.
     *
     * @param string $url The GitHub repository URL.
     * @return array|false Array with owner and repo keys, or false if invalid.
     */
    public function parse_github_url($url) {
        $url = trim($url);
        if (empty($url)) return false;

        // Handle HTTPS, SSH, API, and owner/repo formats
        if (preg_match('#^https?://(?:www\.)?github\.com/([^/]+)/([^/]+?)(?:\.git)?/?$#i', $url, $matches) ||
            preg_match('#^git@github\.com:([^/]+)/([^/]+?)(?:\.git)?$#i', $url, $matches) ||
            preg_match('#^https?://api\.github\.com/repos/([^/]+)/([^/]+)(?:/.*)?$#i', $url, $matches) ||
            preg_match('#^([^/]+)/([^/]+?)(?:\.git)?$#', $url, $matches))
        {
            return ['owner' => $matches[1], 'repo' => rtrim($matches[2], '.git')];
        }
        return false;
    }

    /**
     * Make a request to the GitHub API. Orchestrates the request lifecycle.
     *
     * @param string $endpoint                The API endpoint (without the base URL).
     * @param string $method                  The HTTP method (GET, POST, etc.).
     * @param array  $data                    The data to send with the request.
     * @param bool   $handle_empty_repo_error Whether to handle empty repository error specially.
     * @param int    $timeout                 Optional request timeout in seconds.
     * @param bool   $auto_retry              Whether to automatically retry on failure.
     * @param int    $max_retries             Maximum number of retries on failure.
     * @return array|\WP_Error The response body array or WP_Error on failure.
     */
    public function request($endpoint, $method = 'GET', $data = [], $handle_empty_repo_error = false, $timeout = 30, $auto_retry = true, $max_retries = 2) {
        try {
            // 1. Check Rate Limits (unless checking rate limit itself)
            if ($endpoint !== 'rate_limit') {
                $wait_seconds = $this->rate_limit_handler->check_and_wait();
                if ($wait_seconds > 60) { // Hard fail if wait is too long
                    return new \WP_Error('github_api_rate_limit', sprintf(__('GitHub API rate limit reached. Please try again in %d seconds.', 'wp-github-sync'), $wait_seconds));
                } elseif ($wait_seconds > 0) {
                    sleep($wait_seconds); // Wait if needed
                }
            }

            // 2. Check Repository Info if required by endpoint
            if (strpos($endpoint, 'repos/') === 0 && (empty($this->owner) || empty($this->repo))) {
                throw new \Exception(__('Repository owner or name is missing. Please check your repository URL.', 'wp-github-sync'));
            }

            // 3. Prepare Request Args
            $url = trailingslashit($this->api_base_url) . ltrim($endpoint, '/');
            $auth_header = $this->auth_manager->get_auth_header();
            if (is_wp_error($auth_header)) return $auth_header;

            $headers = [
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'WordPress-GitHub-Sync/' . WP_GITHUB_SYNC_VERSION . '; WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'),
                'X-GitHub-Api-Version' => $this->api_version,
                'Authorization' => $auth_header,
            ];

            // Add ETag for conditional GET requests
            $resource_key = md5($endpoint . json_encode($data)); // Use data in key for GET requests with params
            if ($method === 'GET' && isset($this->etags[$resource_key])) {
                $headers['If-None-Match'] = $this->etags[$resource_key];
            }

            $args = ['method' => $method, 'headers' => $headers, 'timeout' => $timeout, 'sslverify' => true];
            if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
                $args['headers']['Content-Type'] = 'application/json';
                $args['body'] = wp_json_encode($data);
            } elseif (!empty($data) && $method === 'GET') {
                $url = add_query_arg($data, $url);
            }

            // 4. Execute Request with Retries
            $request_callable = [$this->request_handler, 'execute'];
            $response = $this->execute_with_retries($request_callable, $url, $args, $max_retries, $auto_retry);

            // 5. Process Response
            if (is_wp_error($response)) {
                 // Handle specific WP_Http errors like SSL issues
                 if (strpos($response->get_error_message(), 'cURL error 60') !== false) {
                     return new \WP_Error('github_api_ssl_error', __('Failed to connect due to SSL certificate issues. Check server CA bundle.', 'wp-github-sync'));
                 }
                 return $response; // Return other WP_Errors directly
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $response_headers = wp_remote_retrieve_headers($response); // Get headers object/array
            $response_body = wp_remote_retrieve_body($response);

            // Update rate limit cache from headers
            if (is_array($response_headers) || is_object($response_headers)) { // Check if headers are accessible
                 $this->rate_limit_handler->update_rate_limit_cache((array) $response_headers);
                 if ($response_code < 400) { // Reset secondary limit only on success
                     $this->rate_limit_handler->reset_secondary_rate_limit_backoff();
                 }
            }

            // Handle 304 Not Modified
            if ($response_code === 304) {
                wp_github_sync_log("Resource not modified (304) for {$endpoint}", 'debug');
                $cache_key = 'wp_github_sync_cache_' . $resource_key;
                $cached_data = get_transient($cache_key);
                return ($cached_data !== false) ? $cached_data : []; // Return cached data or empty array
            }

            // Handle Errors (4xx, 5xx)
            if ($response_code >= 400) {
                $error_data = json_decode($response_body, true);
                $error_message = $error_data['message'] ?? "Unknown API error (HTTP {$response_code})";

                // Handle specific errors like rate limits or auth failures
                if ($response_code === 403 && (strpos($error_message, 'rate limit') !== false || strpos($error_message, 'secondary') !== false)) {
                    $this->rate_limit_handler->handle_secondary_rate_limit((array) $response_headers);
                    $wait_time = $this->rate_limit_handler->check_and_wait(); // Check again after handling
                    return new \WP_Error("github_api_rate_limit", sprintf(__('GitHub API rate limit exceeded. Please wait %d seconds.', 'wp-github-sync'), $wait_time ?: 60));
                } elseif ($response_code === 429) {
                     $retry_after = $response_headers['retry-after'] ?? 30; // Default wait 30s if header missing
                     set_transient('wp_github_sync_retry_after', time() + $retry_after, $retry_after + 5);
                     return new \WP_Error("github_api_rate_limit", sprintf(__('GitHub API rate limit exceeded (429). Please wait %d seconds.', 'wp-github-sync'), $retry_after));
                } elseif ($response_code === 401) {
                     // Attempt retry with alternate auth format if applicable (e.g., classic PAT)
                     $token_type = $this->auth_manager->get_token_type();
                     $current_format = $this->auth_manager->get_auth_header() ? (strpos($this->auth_manager->get_auth_header(), 'Bearer') === 0 ? 'bearer' : 'token') : 'unknown';

                     if (($token_type === 'classic_pat' || $token_type === 'classic_pat_hex' || $token_type === 'unknown')) {
                         $alt_format = ($current_format === 'bearer') ? 'token' : 'bearer';
                         $alt_auth_header = $this->auth_manager->get_auth_header($alt_format);
                         if (!is_wp_error($alt_auth_header)) {
                             $args['headers']['Authorization'] = $alt_auth_header;
                             wp_github_sync_log("Retrying request with '{$alt_format}' authorization format after 401.", 'info');
                             $retry_response = $this->execute_with_retries($request_callable, $url, $args, 1, false); // Only 1 attempt

                             if (!is_wp_error($retry_response) && wp_remote_retrieve_response_code($retry_response) < 400) {
                                 wp_github_sync_log("Request succeeded with '{$alt_format}' format - setting preference.", 'info');
                                 $this->auth_manager->set_preferred_auth_format($alt_format);
                                 $response = $retry_response; // Use the successful response
                                 // Update local vars for processing
                                 $response_code = wp_remote_retrieve_response_code($response);
                                 $response_body = wp_remote_retrieve_body($response);
                                 $response_headers = wp_remote_retrieve_headers($response); // Re-fetch headers
                                 goto process_successful_response; // Jump to success processing
                             } else {
                                 wp_github_sync_log("Request also failed with '{$alt_format}' format.", 'error');
                             }
                         }
                     }
                     // If retry didn't work or wasn't applicable, return 401 error
                     return new \WP_Error("github_api_unauthorized", __('GitHub API authentication failed (401). Check token validity and permissions.', 'wp-github-sync'));

                } elseif ($response_code === 404 && $handle_empty_repo_error && (strpos($error_message, 'Git Repository is empty') !== false || strpos($error_message, 'Not Found') !== false)) {
                     return new \WP_Error('github_empty_repository', 'Repository appears to be empty or not initialized');
                }

                // General API error
                $error_details = isset($error_data['errors']) ? wp_json_encode($error_data['errors']) : '';
                return new \WP_Error("github_api_{$response_code}", $error_message . ($error_details ? ' Details: ' . $error_details : ''), $error_data);
            }

            // Handle Success (2xx)
            // Create a label for jump point (used if auth retry succeeds)
            process_successful_response:

            // Cache ETag if present
            $etag = wp_remote_retrieve_header($response, 'etag');
            if (!empty($etag)) {
                $this->etags[$resource_key] = $etag;
                update_option('wp_github_sync_etags', $this->etags);
            }

            // Parse JSON body
            if (empty($response_body)) {
                return []; // Handle 204 No Content or other empty success responses
            }
            $response_data = json_decode($response_body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return new \WP_Error('github_api_json_error', __('Failed to parse GitHub API response.', 'wp-github-sync'));
            }

            // Cache successful response data if ETag was present
            if (!empty($etag)) {
                 $cache_key = 'wp_github_sync_cache_' . $resource_key;
                 set_transient($cache_key, $response_data, DAY_IN_SECONDS);
            }

            return $response_data;

        } catch (\Exception $e) {
            $error_message = "Exception during API request: " . $e->getMessage();
            wp_github_sync_log($error_message, 'error');
            return new \WP_Error('github_api_exception', $error_message);
        }
    }

    // --- Specific Endpoint Methods ---

    public function get_repository() {
        return $this->request("repos/{$this->owner}/{$this->repo}");
    }

    public function get_branches() {
        return $this->request("repos/{$this->owner}/{$this->repo}/branches");
    }

    public function get_commits($branch = '', $count = 10) {
        $branchToUse = !empty($branch) ? $branch : $this->get_default_branch();
        if (is_wp_error($branchToUse)) return $branchToUse; // Handle error getting default branch
        return $this->request("repos/{$this->owner}/{$this->repo}/commits", 'GET', ['sha' => $branchToUse, 'per_page' => $count]);
    }

    public function get_latest_commit($branch = '') {
        $commits = $this->get_commits($branch, 1);
        if (is_wp_error($commits)) return $commits;
        if (empty($commits) || !isset($commits[0])) return new \WP_Error('no_commits', __('No commits found for this branch.', 'wp-github-sync'));
        return $commits[0];
    }

    public function get_branch_sha($branch = '') {
        $branchToUse = !empty($branch) ? $branch : $this->get_default_branch();
        if (is_wp_error($branchToUse)) return $branchToUse;
        $reference = $this->request("repos/{$this->owner}/{$this->repo}/git/refs/heads/{$branchToUse}");
        if (is_wp_error($reference)) return $reference;
        if (!isset($reference['object']['sha'])) return new \WP_Error('missing_sha', __('Branch reference does not contain a SHA.', 'wp-github-sync'));
        return $reference['object']['sha'];
    }

    public function list_commits($branch = '', $count = 10) { // Alias
        return $this->get_commits($branch, $count);
    }

    public function get_contents($path, $ref = '') {
        $refToUse = !empty($ref) ? $ref : $this->get_default_branch();
        if (is_wp_error($refToUse)) return $refToUse;
        return $this->request("repos/{$this->owner}/{$this->repo}/contents/{$path}", 'GET', ['ref' => $refToUse]);
    }

    public function get_archive_url($ref = '') {
        $refToUse = !empty($ref) ? $ref : $this->get_default_branch();
        if (is_wp_error($refToUse)) return ''; // Return empty string on error
        // Ensure owner and repo are available
        if (empty($this->owner) || empty($this->repo)) return '';
        return "{$this->api_base_url}/repos/{$this->owner}/{$this->repo}/zipball/{$refToUse}";
    }

    public function is_token_valid() {
        return !is_wp_error($this->request('user'));
    }

    public function set_temporary_token($token) {
        $this->auth_manager->set_temporary_token($token);
        // Optionally re-test authentication immediately
        // $this->test_authentication();
    }

    public function get_user_login() {
        $user = $this->request('user');
        return (!is_wp_error($user) && isset($user['login'])) ? $user['login'] : null;
    }

    public function test_authentication() {
        // Delegate to AuthManager or keep simplified version here?
        // For now, keep simple check
        $response = $this->request('user');
        if (is_wp_error($response)) {
            return $response->get_error_message();
        }
        if (isset($response['login'])) {
             // Optionally perform permission checks here if needed
             return true;
        }
        return 'Authentication successful, but user data missing.';
    }

    public function repository_exists($owner = '', $repo = '') {
        $check_owner = !empty($owner) ? $owner : $this->owner;
        $check_repo = !empty($repo) ? $repo : $this->repo;
        if (empty($check_owner) || empty($check_repo)) return false;
        // Temporarily set owner/repo for the request if different
        $original_owner = $this->owner;
        $original_repo = $this->repo;
        $this->owner = $check_owner;
        $this->repo = $check_repo;
        $response = $this->request("repos/{$check_owner}/{$check_repo}");
        // Restore original owner/repo
        $this->owner = $original_owner;
        $this->repo = $original_repo;
        return !is_wp_error($response);
    }

    public function create_repository($repo_name, $description = '', $private = false, $auto_init = true) {
        // Auth check happens within request method now
        $data = ['name' => $repo_name, 'description' => $description, 'private' => (bool)$private, 'auto_init' => (bool)$auto_init];
        $response = $this->request('user/repos', 'POST', $data);
        // Update internal owner/repo if successful and info available
        if (!is_wp_error($response) && isset($response['owner']['login'], $response['name'])) {
            $this->owner = $response['owner']['login'];
            $this->repo = $response['name'];
        }
        return $response;
    }

    public function initialize_repository($branch = 'main') {
        // This logic is complex and involves multiple steps, potentially better suited
        // for BranchManager or a dedicated initialization service.
        // Keeping it simple here for now, assuming it creates a README via Contents API.
        wp_github_sync_log("Initializing empty repository with README.md on branch: {$branch}", 'info');
        $readme_content = "# {$this->repo}\n\nInitialized by WP GitHub Sync.";
        return $this->request(
            "repos/{$this->owner}/{$this->repo}/contents/README.md",
            'PUT',
            [
                'message' => 'Initial commit',
                'content' => base64_encode($readme_content),
                'branch' => $branch
            ]
        );
    }

    public function get_default_branch() {
        $repo_info = $this->get_repository();
        if (is_wp_error($repo_info)) {
             // Handle empty repo case specifically if needed, maybe return 'main' as default
             if ($repo_info->get_error_code() === 'github_empty_repository') {
                 return 'main'; // Default for empty repo
             }
             return $repo_info; // Propagate other errors
        }
        return $repo_info['default_branch'] ?? 'main'; // Default fallback
    }

    // --- Getters for owner/repo ---
    public function get_owner() { return $this->owner; }
    public function get_repo() { return $this->repo; }

    // --- Deprecated Methods (Moved to Helpers) ---
    // Removed: generate_github_app_token() -> Moved to AuthManager
    // Removed: check_rate_limits() -> Moved to RateLimitHandler
    // Removed: set_secondary_rate_limit_backoff() -> Moved to RateLimitHandler
    // Removed: reset_secondary_rate_limit_backoff() -> Moved to RateLimitHandler

} // End class API_Client
