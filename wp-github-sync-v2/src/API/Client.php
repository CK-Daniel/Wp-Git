<?php
/**
 * GitHub API Client
 *
 * @package WPGitHubSync
 */

namespace WPGitHubSync\API;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * GitHub API Client
 */
class Client implements ClientInterface {
    /**
     * Plugin version
     *
     * @var string
     */
    private $version;

    /**
     * GitHub API base URL
     *
     * @var string
     */
    private $api_base = 'https://api.github.com';

    /**
     * GitHub repository owner
     *
     * @var string
     */
    private $owner;

    /**
     * GitHub repository name
     *
     * @var string
     */
    private $repo;

    /**
     * Authentication token
     *
     * @var string
     */
    private $token;

    /**
     * Authentication method
     *
     * @var string
     */
    private $auth_method;

    /**
     * Constructor
     *
     * @param string $version  Plugin version.
     * @param array  $settings Optional custom settings to override defaults.
     */
    public function __construct( $version, $settings = null ) {
        $this->version = $version;
        
        if ($settings !== null) {
            $this->apply_settings($settings);
        } else {
            $this->load_settings();
        }
    }
    
    /**
     * Apply custom settings
     *
     * @param array $settings Custom settings.
     */
    protected function apply_settings( $settings ) {
        // Parse repository URL if provided
        if (!empty($settings['repo_url'])) {
            $repo_parts = $this->parse_repo_url($settings['repo_url']);
            $this->owner = $repo_parts['owner'];
            $this->repo = $repo_parts['repo'];
        }
        
        // Set authentication method
        $this->auth_method = !empty($settings['auth_method']) ? $settings['auth_method'] : 'pat';
        
        // Set token based on auth method
        if ($this->auth_method === 'pat' && !empty($settings['access_token'])) {
            $this->token = $settings['access_token'];
        } elseif ($this->auth_method === 'oauth' && !empty($settings['oauth_token'])) {
            $this->token = $settings['oauth_token'];
        } elseif ($this->auth_method === 'github_app') {
            // GitHub App authentication is more complex and would need additional code
            // For now, we'll just handle the basic token-based methods
        }
    }

    /**
     * Load settings from WordPress options
     */
    protected function load_settings() {
        $settings = get_option( 'wp_github_sync_settings' );
        
        if ( ! $settings ) {
            return;
        }
        
        if ( ! empty( $settings['repo_url'] ) ) {
            $repo_parts = $this->parse_repo_url( $settings['repo_url'] );
            $this->owner = $repo_parts['owner'];
            $this->repo = $repo_parts['repo'];
        }
        
        $this->token = ! empty( $settings['access_token'] ) ? $settings['access_token'] : '';
        $this->auth_method = ! empty( $settings['auth_method'] ) ? $settings['auth_method'] : 'pat';
    }

    /**
     * Register REST routes
     */
    public function register_routes() {
        register_rest_route(
            'wp-github-sync/v1',
            '/sync',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'handle_sync_request' ),
                'permission_callback' => array( $this, 'check_admin_permission' ),
            )
        );
        
        register_rest_route(
            'wp-github-sync/v1',
            '/test-connection',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'handle_test_connection' ),
                'permission_callback' => array( $this, 'check_admin_permission' ),
            )
        );
        
        register_rest_route(
            'wp-github-sync/v1',
            '/backup',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'handle_backup_request' ),
                'permission_callback' => array( $this, 'check_admin_permission' ),
            )
        );
        
        register_rest_route(
            'wp-github-sync/v1',
            '/restore/(?P<id>[a-zA-Z0-9-]+)',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'handle_restore_request' ),
                'permission_callback' => array( $this, 'check_admin_permission' ),
                'args'                => array(
                    'id' => array(
                        'required'          => true,
                        'validate_callback' => function( $param ) {
                            return is_string( $param ) && ! empty( $param );
                        },
                    ),
                ),
            )
        );
        
        register_rest_route(
            'wp-github-sync/v1',
            '/compare',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'handle_compare_request' ),
                'permission_callback' => array( $this, 'check_admin_permission' ),
            )
        );
    }

    /**
     * Check if user has admin permission
     *
     * @return bool
     */
    public function check_admin_permission() {
        return current_user_can( 'manage_options' );
    }

    /**
     * Handle sync request
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response|\WP_Error
     */
    public function handle_sync_request( $request ) {
        $sync_manager = new \WPGitHubSync\Sync\Manager( $this->version );
        $result = $sync_manager->sync();
        
        if ( is_wp_error( $result ) ) {
            return rest_ensure_response( array(
                'success' => false,
                'message' => $result->get_error_message(),
            ) );
        }
        
        return rest_ensure_response( array(
            'success' => true,
            'message' => __( 'Sync completed successfully', 'wp-github-sync' ),
            'data'    => $result,
        ) );
    }

    /**
     * Handle test connection request
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response|\WP_Error
     */
    public function handle_test_connection( $request ) {
        $repo_url = $request->get_param( 'repo_url' );
        $auth_method = $request->get_param( 'auth_method' );
        $access_token = $request->get_param( 'access_token' );
        
        // Temporarily set client properties
        $repo_parts = $this->parse_repo_url( $repo_url );
        $this->owner = $repo_parts['owner'];
        $this->repo = $repo_parts['repo'];
        $this->token = $access_token;
        $this->auth_method = $auth_method;
        
        $result = $this->test_authentication();
        
        // Reset to saved settings
        $this->load_settings();
        
        if ( is_wp_error( $result ) ) {
            return rest_ensure_response( array(
                'success' => false,
                'message' => $result->get_error_message(),
            ) );
        }
        
        return rest_ensure_response( array(
            'success' => true,
            'message' => __( 'Connection successful', 'wp-github-sync' ),
            'data'    => $result,
        ) );
    }

    /**
     * Handle backup request
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response|\WP_Error
     */
    public function handle_backup_request( $request ) {
        $backup_manager = new \WPGitHubSync\Sync\BackupManager();
        $result = $backup_manager->create_backup();
        
        if ( ! $result ) {
            return rest_ensure_response( array(
                'success' => false,
                'message' => __( 'Failed to create backup', 'wp-github-sync' ),
            ) );
        }
        
        return rest_ensure_response( array(
            'success' => true,
            'message' => __( 'Backup created successfully', 'wp-github-sync' ),
            'data'    => array(
                'backup_id' => $result,
            ),
        ) );
    }

    /**
     * Handle restore request
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response|\WP_Error
     */
    public function handle_restore_request( $request ) {
        $backup_id = $request->get_param( 'id' );
        $backup_manager = new \WPGitHubSync\Sync\BackupManager();
        $result = $backup_manager->restore_backup( $backup_id );
        
        if ( ! $result ) {
            return rest_ensure_response( array(
                'success' => false,
                'message' => __( 'Failed to restore backup', 'wp-github-sync' ),
            ) );
        }
        
        return rest_ensure_response( array(
            'success' => true,
            'message' => __( 'Backup restored successfully', 'wp-github-sync' ),
        ) );
    }

    /**
     * Handle compare request
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response|\WP_Error
     */
    public function handle_compare_request( $request ) {
        $source = $request->get_param( 'source' );
        $target = $request->get_param( 'target' );
        
        $result = $this->compare( $source, $target );
        
        if ( is_wp_error( $result ) ) {
            return rest_ensure_response( array(
                'success' => false,
                'message' => $result->get_error_message(),
            ) );
        }
        
        return rest_ensure_response( array(
            'success' => true,
            'message' => __( 'Comparison completed', 'wp-github-sync' ),
            'data'    => $result,
        ) );
    }

    /**
     * Parse repository URL into owner and repo components
     *
     * @param string $url Repository URL.
     * @return array Array with owner and repo keys.
     */
    protected function parse_repo_url( $url ) {
        $result = array(
            'owner' => '',
            'repo'  => '',
        );
        
        // Remove trailing slash if exists
        $url = rtrim( $url, '/' );
        
        // Extract from GitHub URL format
        if ( preg_match( '#^https?://github\.com/([^/]+)/([^/]+)/?$#', $url, $matches ) ) {
            $result['owner'] = $matches[1];
            $result['repo'] = $matches[2];
            
            // Remove .git suffix if exists
            $result['repo'] = preg_replace( '/\.git$/', '', $result['repo'] );
        }
        
        return $result;
    }

    /**
     * Make a request to the GitHub API
     *
     * @param string $endpoint The API endpoint.
     * @param string $method   HTTP method (GET, POST, etc.).
     * @param array  $data     Request data.
     * @return mixed Response data or WP_Error.
     */
    public function request( $endpoint, $method = 'GET', $data = [] ) {
        if ( empty( $this->owner ) || empty( $this->repo ) ) {
            return new \WP_Error( 'github_api_config', __( 'GitHub repository not configured', 'wp-github-sync' ) );
        }
        
        // Build request URL
        $url = $this->api_base . $endpoint;
        
        // Replace placeholder values in the URL
        $url = str_replace( ':owner', $this->owner, $url );
        $url = str_replace( ':repo', $this->repo, $url );
        
        // Setup request arguments
        $args = array(
            'method'     => $method,
            'timeout'    => 30,
            'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ) . '; WP-GitHub-Sync/' . $this->version,
            'headers'    => array(
                'Accept' => 'application/vnd.github.v3+json',
            ),
        );
        
        // Add authentication headers
        $this->add_auth_header( $args );
        
        // Add body data for POST, PUT, PATCH
        if ( in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) && ! empty( $data ) ) {
            $args['body'] = wp_json_encode( $data );
            $args['headers']['Content-Type'] = 'application/json';
        }
        
        // Log API request (debug mode only)
        $this->log_request( $url, $method, $data );
        
        // Make the request
        $response = wp_remote_request( $url, $args );
        
        // Check for errors
        if ( is_wp_error( $response ) ) {
            $this->log_error( 'API Request Error', $response->get_error_message() );
            return $response;
        }
        
        // Get response code
        $response_code = wp_remote_retrieve_response_code( $response );
        
        // Check for rate limiting
        if ( 403 === $response_code && strpos( wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' ), '0' ) === 0 ) {
            $rate_reset = wp_remote_retrieve_header( $response, 'x-ratelimit-reset' );
            $wait_time = $rate_reset - time();
            
            return new \WP_Error(
                'github_api_rate_limit',
                sprintf(
                    /* translators: %d: Number of minutes to wait */
                    __( 'GitHub API rate limit exceeded. Please wait %d minutes before trying again.', 'wp-github-sync' ),
                    ceil( $wait_time / 60 )
                )
            );
        }
        
        // Get response body
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        
        // Handle error responses
        if ( $response_code < 200 || $response_code >= 300 ) {
            $message = isset( $data['message'] ) ? $data['message'] : __( 'Unknown API error', 'wp-github-sync' );
            
            $this->log_error( 'API Response Error', $message, $response_code );
            
            return new \WP_Error(
                'github_api_' . $response_code,
                $message
            );
        }
        
        // Return response data
        return $data;
    }

    /**
     * Make a request with retry mechanism
     *
     * @param string $endpoint The API endpoint.
     * @param string $method   HTTP method (GET, POST, etc.).
     * @param array  $data     Request data.
     * @param int    $retries  Number of retries.
     * @return mixed Response data or WP_Error.
     */
    public function request_with_retry( $endpoint, $method = 'GET', $data = [], $retries = 3 ) {
        $attempt = 0;
        
        while ( $attempt < $retries ) {
            $response = $this->request( $endpoint, $method, $data );
            
            if ( ! is_wp_error( $response ) ) {
                return $response;
            }
            
            $error_code = $response->get_error_code();
            
            // Only retry on transient errors like rate limits or network issues
            if ( ! in_array( $error_code, array( 'github_api_rate_limit', 'github_api_network_error' ), true ) ) {
                return $response;
            }
            
            $attempt++;
            $wait_seconds = pow( 2, $attempt ); // Exponential backoff: 2, 4, 8 seconds
            
            $this->log_message( 'info', sprintf(
                /* translators: 1: Attempt number, 2: Max retries, 3: Wait seconds */
                __( 'API request failed, retrying in %3$d seconds (attempt %1$d/%2$d)', 'wp-github-sync' ),
                $attempt,
                $retries,
                $wait_seconds
            ) );
            
            sleep( $wait_seconds );
        }
        
        return $response;
    }

    /**
     * Add authentication header based on the configured method
     *
     * @param array $args Request arguments.
     */
    protected function add_auth_header( &$args ) {
        if ( empty( $this->token ) ) {
            return;
        }
        
        switch ( $this->auth_method ) {
            case 'pat':
            case 'oauth':
                $args['headers']['Authorization'] = 'Bearer ' . $this->token;
                break;
                
            case 'basic':
                $args['headers']['Authorization'] = 'Basic ' . base64_encode( $this->token );
                break;
                
            default:
                // No authentication
                break;
        }
    }

    /**
     * Test authentication with GitHub
     *
     * @return bool|\WP_Error True if authenticated, WP_Error otherwise.
     */
    public function test_authentication() {
        $response = $this->request( '/repos/:owner/:repo' );
        
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        
        return true;
    }

    /**
     * Get repository details
     *
     * @return mixed Repository data or WP_Error.
     */
    public function get_repository() {
        return $this->request( '/repos/:owner/:repo' );
    }

    /**
     * Get branches for the repository
     *
     * @return array|\WP_Error List of branches or WP_Error.
     */
    public function get_branches() {
        return $this->request( '/repos/:owner/:repo/branches' );
    }

    /**
     * Get commits for a branch
     *
     * @param string $branch   Branch name.
     * @param int    $per_page Number of commits to fetch.
     * @return array|\WP_Error List of commits or WP_Error.
     */
    public function get_commits( $branch = '', $per_page = 10 ) {
        $endpoint = '/repos/:owner/:repo/commits';
        $params = array();
        
        if ( ! empty( $branch ) ) {
            $params['sha'] = $branch;
        }
        
        if ( $per_page !== 10 ) {
            $params['per_page'] = $per_page;
        }
        
        if ( ! empty( $params ) ) {
            $endpoint .= '?' . http_build_query( $params );
        }
        
        return $this->request( $endpoint );
    }

    /**
     * Get a specific commit
     *
     * @param string $sha Commit SHA.
     * @return array|\WP_Error Commit data or WP_Error.
     */
    public function get_commit( $sha ) {
        return $this->request( '/repos/:owner/:repo/commits/' . urlencode( $sha ) );
    }

    /**
     * Get the contents of a file
     *
     * @param string $path File path.
     * @param string $ref  Branch or commit SHA.
     * @return mixed File contents or WP_Error.
     */
    public function get_contents( $path, $ref = '' ) {
        $endpoint = '/repos/:owner/:repo/contents/' . urlencode( $path );
        
        if ( ! empty( $ref ) ) {
            $endpoint .= '?ref=' . urlencode( $ref );
        }
        
        $response = $this->request( $endpoint );
        
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        
        // If the response is an array, it's a directory listing
        if ( is_array( $response ) && ! isset( $response['content'] ) ) {
            return $response;
        }
        
        // Decode content if present
        if ( isset( $response['content'] ) && isset( $response['encoding'] ) && 'base64' === $response['encoding'] ) {
            $response['content'] = base64_decode( $response['content'] );
        }
        
        return $response;
    }

    /**
     * Create or update a file
     *
     * @param string $path    File path.
     * @param string $content File content.
     * @param string $message Commit message.
     * @param string $branch  Branch name.
     * @param string $sha     File SHA (only for updates).
     * @return mixed Response data or WP_Error.
     */
    public function create_or_update_file( $path, $content, $message, $branch = '', $sha = '' ) {
        $endpoint = '/repos/:owner/:repo/contents/' . urlencode( $path );
        
        $data = array(
            'message' => $message,
            'content' => base64_encode( $content ),
        );
        
        if ( ! empty( $branch ) ) {
            $data['branch'] = $branch;
        }
        
        if ( ! empty( $sha ) ) {
            $data['sha'] = $sha;
        }
        
        return $this->request( $endpoint, 'PUT', $data );
    }

    /**
     * Download repository as zip
     *
     * @param string $ref Branch or commit SHA.
     * @return string|\WP_Error Path to downloaded zip file or WP_Error.
     */
    public function download_archive( $ref = '' ) {
        if ( empty( $ref ) ) {
            $repo = $this->get_repository();
            
            if ( is_wp_error( $repo ) ) {
                return $repo;
            }
            
            $ref = $repo['default_branch'];
        }
        
        $url = sprintf(
            'https://github.com/%s/%s/archive/%s.zip',
            urlencode( $this->owner ),
            urlencode( $this->repo ),
            urlencode( $ref )
        );
        
        $temp_file = wp_tempnam( $ref . '.zip' );
        
        if ( ! $temp_file ) {
            return new \WP_Error( 'github_download_temp_file', __( 'Could not create temporary file', 'wp-github-sync' ) );
        }
        
        $headers = array(
            'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ) . '; WP-GitHub-Sync/' . $this->version,
        );
        
        if ( ! empty( $this->token ) ) {
            $headers['Authorization'] = 'Bearer ' . $this->token;
        }
        
        $response = wp_remote_get( $url, array(
            'timeout'  => 60,
            'headers'  => $headers,
            'stream'   => true,
            'filename' => $temp_file,
        ) );
        
        if ( is_wp_error( $response ) ) {
            @unlink( $temp_file );
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code( $response );
        
        if ( 200 !== $response_code ) {
            @unlink( $temp_file );
            return new \WP_Error( 'github_download_failed', sprintf(
                /* translators: %d: HTTP response code */
                __( 'Failed to download archive. Server responded with code %d', 'wp-github-sync' ),
                $response_code
            ) );
        }
        
        return $temp_file;
    }

    /**
     * Compare two commits
     *
     * @param string $base Base commit SHA or branch.
     * @param string $head Head commit SHA or branch.
     * @return array|\WP_Error Comparison data or WP_Error.
     */
    public function compare( $base, $head ) {
        $endpoint = sprintf(
            '/repos/:owner/:repo/compare/%s...%s',
            urlencode( $base ),
            urlencode( $head )
        );
        
        return $this->request( $endpoint );
    }

    /**
     * Get a specific branch
     *
     * @param string $branch Branch name.
     * @return array|\WP_Error Branch data or WP_Error.
     */
    public function get_branch( $branch ) {
        $endpoint = '/repos/:owner/:repo/branches/' . urlencode( $branch );
        return $this->request( $endpoint );
    }

    /**
     * Create a new branch
     *
     * @param string $branch Branch name.
     * @param string $sha    SHA to create branch from.
     * @return array|\WP_Error Response data or WP_Error.
     */
    public function create_branch( $branch, $sha ) {
        $endpoint = '/repos/:owner/:repo/git/refs';
        
        $data = array(
            'ref' => 'refs/heads/' . $branch,
            'sha' => $sha,
        );
        
        return $this->request( $endpoint, 'POST', $data );
    }

    /**
     * Get branch SHA
     *
     * @param string $branch Branch name.
     * @return string|\WP_Error SHA or WP_Error.
     */
    public function get_branch_sha( $branch ) {
        $branch_data = $this->get_branch( $branch );
        
        if ( is_wp_error( $branch_data ) ) {
            return $branch_data;
        }
        
        if ( ! isset( $branch_data['commit']['sha'] ) ) {
            return new \WP_Error( 'github_invalid_branch', __( 'Invalid branch data', 'wp-github-sync' ) );
        }
        
        return $branch_data['commit']['sha'];
    }

    /**
     * Log API request (debug mode only)
     *
     * @param string $url    Request URL.
     * @param string $method HTTP method.
     * @param array  $data   Request data.
     */
    protected function log_request( $url, $method, $data ) {
        if ( ! $this->is_debug_mode() ) {
            return;
        }
        
        $message = sprintf(
            /* translators: 1: HTTP method, 2: URL */
            __( 'GitHub API Request: %1$s %2$s', 'wp-github-sync' ),
            $method,
            $url
        );
        
        if ( ! empty( $data ) ) {
            $message .= PHP_EOL . 'Data: ' . wp_json_encode( $data );
        }
        
        $this->log_message( 'debug', $message );
    }

    /**
     * Log API error
     *
     * @param string $title   Error title.
     * @param string $message Error message.
     * @param int    $code    Error code.
     */
    protected function log_error( $title, $message, $code = 0 ) {
        $log_message = $title;
        
        if ( $code > 0 ) {
            $log_message .= ' (' . $code . ')';
        }
        
        $log_message .= ': ' . $message;
        
        $this->log_message( 'error', $log_message );
    }

    /**
     * Log a message to the plugin log
     *
     * @param string $level   Log level (debug, info, warning, error).
     * @param string $message Log message.
     */
    protected function log_message( $level, $message ) {
        if ( function_exists( 'wp_github_sync_log' ) ) {
            wp_github_sync_log( $message, $level );
        }
    }

    /**
     * Check if debug mode is enabled
     *
     * @return bool
     */
    protected function is_debug_mode() {
        $settings = get_option( 'wp_github_sync_settings' );
        return ! empty( $settings['debug_mode'] );
    }
}