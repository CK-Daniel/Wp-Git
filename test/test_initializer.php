<?php
/**
 * Simple test script for GitHub API client repository initializer
 */

// Define WordPress constants needed by the plugin
define('WPINC', 'wp-includes');
define('WP_CONTENT_DIR', dirname(__DIR__));
define('ABSPATH', dirname(__DIR__) . '/');
define('DAY_IN_SECONDS', 86400);

// Mocked functions
if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        global $wp_options;
        return isset($wp_options[$option]) ? $wp_options[$option] : $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value, $autoload = null) {
        global $wp_options;
        $wp_options[$option] = $value;
        return true;
    }
}

if (!function_exists('wp_github_sync_log')) {
    function wp_github_sync_log($message, $level = 'info', $force = false) {
        echo "[" . date('Y-m-d H:i:s') . "] [$level] $message\n";
    }
}

if (!function_exists('get_bloginfo')) {
    function get_bloginfo($show = '') {
        switch ($show) {
            case 'name':
                return 'Test WordPress Site';
            case 'url':
                return 'https://example.com';
            case 'version':
                return '6.3';
            default:
                return '';
        }
    }
}

if (!function_exists('wp_remote_get')) {
    function wp_remote_get($url, $args = array()) {
        return wp_remote_request($url, $args);
    }
}

if (!function_exists('wp_remote_post')) {
    function wp_remote_post($url, $args = array()) {
        $args['method'] = 'POST';
        return wp_remote_request($url, $args);
    }
}

if (!function_exists('wp_remote_request')) {
    function wp_remote_request($url, $args = array()) {
        $method = isset($args['method']) ? $args['method'] : 'GET';
        $headers = isset($args['headers']) ? $args['headers'] : array();
        $body = isset($args['body']) ? $args['body'] : null;
        
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, true);
        
        // Set method
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        
        // Set headers
        $header_array = array();
        foreach ($headers as $key => $value) {
            $header_array[] = "$key: $value";
        }
        
        if (!empty($header_array)) {
            curl_setopt($curl, CURLOPT_HTTPHEADER, $header_array);
        }
        
        // Set body data for POST, PUT, etc.
        if ($body && in_array($method, array('POST', 'PUT', 'PATCH'))) {
            if (is_array($body)) {
                $body = http_build_query($body);
            }
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        
        $response = curl_exec($curl);
        $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        
        curl_close($curl);
        
        if ($error) {
            return new WP_Error('http_request_failed', $error);
        }
        
        $headers_string = substr($response, 0, $header_size);
        $body = substr($response, $header_size);
        
        $headers = array();
        foreach (explode("\r\n", $headers_string) as $header_line) {
            if (strpos($header_line, ':') !== false) {
                list($key, $value) = explode(':', $header_line, 2);
                $headers[strtolower(trim($key))] = trim($value);
            }
        }
        
        return array(
            'headers' => $headers,
            'body' => $body,
            'response' => array(
                'code' => $status_code,
                'message' => ''
            ),
            'cookies' => array(),
            'http_response' => null
        );
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response) {
        if (is_wp_error($response) || !isset($response['body'])) {
            return '';
        }
        return $response['body'];
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response) {
        if (is_wp_error($response) || !isset($response['response']['code'])) {
            return 0;
        }
        return $response['response']['code'];
    }
}

if (!function_exists('wp_remote_retrieve_header')) {
    function wp_remote_retrieve_header($response, $header) {
        if (is_wp_error($response) || !isset($response['headers'][strtolower($header)])) {
            return '';
        }
        return $response['headers'][strtolower($header)];
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return $thing instanceof WP_Error;
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error {
        protected $errors = array();
        protected $error_messages = array();
        
        public function __construct($code = '', $message = '', $data = '') {
            if (!empty($code)) {
                $this->add($code, $message, $data);
            }
        }
        
        public function add($code, $message, $data = '') {
            $this->errors[$code] = $message;
            $this->error_messages[] = $message;
        }
        
        public function get_error_message() {
            return reset($this->error_messages);
        }
    }
}

if (!function_exists('wp_github_sync_encrypt')) {
    function wp_github_sync_encrypt($data) {
        return $data;
    }
}

if (!function_exists('wp_github_sync_decrypt')) {
    function wp_github_sync_decrypt($data) {
        return $data;
    }
}

if (!function_exists('trailingslashit')) {
    function trailingslashit($string) {
        return rtrim($string, '/\\') . '/';
    }
}

// Define WP Transients for namespaces
namespace WPGitHubSync\API {
    function get_transient($key) {
        return \get_transient($key);
    }
    
    function set_transient($key, $value, $expiration = 0) {
        return \set_transient($key, $value, $expiration);
    }
    
    function delete_transient($key) {
        return \delete_transient($key);
    }
}

// Define constants
namespace {
    if (!defined('WP_GITHUB_SYNC_URL')) {
        define('WP_GITHUB_SYNC_URL', '');
    }
    if (!defined('WP_GITHUB_SYNC_DIR')) {
        define('WP_GITHUB_SYNC_DIR', dirname(__DIR__) . '/wp-github-sync/');
    }
}

// Include API client
require_once dirname(__DIR__) . '/wp-github-sync/src/API/API_Client.php';

// Run the test
run_test();

function run_test() {
    global $wp_options;
    
    // Reset global variables
    $wp_options = array();
    
    // Set test configuration
    $token = ''; // Add your GitHub token here
    // Use repository URL from settings if available
    $repo_url = get_option('wp_github_sync_repository', '');
    
    // Check if token is empty
    if (empty($token)) {
        echo "ERROR: No GitHub token provided. Please add your token to the script.\n";
        return;
    }
    
    // Setup options
    $wp_options['wp_github_sync_repository'] = $repo_url;
    $wp_options['wp_github_sync_access_token'] = $token;
    $wp_options['wp_github_sync_auth_method'] = 'pat';
    $wp_options['wp_github_sync_etags'] = [];
    $wp_options['wp_github_sync_branches'] = [];
    $wp_options['wp_github_sync_rate_limit_remaining'] = 5000;
    $wp_options['wp_github_sync_rate_limit_reset'] = time() + 3600;
    
    echo "Testing repository initialization with:\n";
    echo "Repository: $repo_url\n";
    echo "-------------------------------------\n";
    
    // Initialize API client
    $api_client = new WPGitHubSync\API\API_Client();
    
    // Test authentication
    echo "\nTesting authentication...\n";
    $auth_test = $api_client->test_authentication();
    if ($auth_test === true) {
        echo "Authentication successful!\n";
    } else {
        echo "Authentication failed: " . $auth_test . "\n";
        return;
    }
    
    // Initialize repository
    echo "\nInitializing repository...\n";
    $result = $api_client->initialize_repository('main');
    
    if (is_wp_error($result)) {
        echo "Initialization failed: " . $result->get_error_message() . "\n";
    } else {
        echo "Repository initialized successfully!\n";
        
        // Get the default branch to verify it works
        $default_branch = $api_client->get_default_branch();
        echo "Default branch: " . (is_wp_error($default_branch) ? $default_branch->get_error_message() : $default_branch) . "\n";
    }
    
    echo "\nTest completed.\n";
}