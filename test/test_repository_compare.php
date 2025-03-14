<?php
/**
 * Test script for WordPress GitHub Sync repository comparison functionality
 * 
 * This is a standalone test script that can be run directly from the command line.
 */

// Define WordPress constants needed by the plugin
define('WPINC', 'wp-includes');
define('WP_CONTENT_DIR', dirname(__DIR__));
define('ABSPATH', dirname(__DIR__) . '/');
define('DAY_IN_SECONDS', 86400);
define('WP_GITHUB_SYNC_TESTING', true);

// Mock WordPress functions that the plugin uses
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

if (!function_exists('delete_option')) {
    function delete_option($option) {
        global $wp_options;
        if (isset($wp_options[$option])) {
            unset($wp_options[$option]);
            return true;
        }
        return false;
    }
}

if (!function_exists('wp_github_sync_log')) {
    function wp_github_sync_log($message, $level = 'info', $force = false) {
        echo "[" . date('Y-m-d H:i:s') . "] [$level] $message\n";
    }
}

if (!function_exists('set_transient')) {
    function set_transient($transient, $value, $expiration = 0) {
        global $wp_transients;
        $wp_transients[$transient] = $value;
        return true;
    }
}

if (!function_exists('get_transient')) {
    function get_transient($transient) {
        global $wp_transients;
        return isset($wp_transients[$transient]) ? $wp_transients[$transient] : false;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient($transient) {
        global $wp_transients;
        if (isset($wp_transients[$transient])) {
            unset($wp_transients[$transient]);
            return true;
        }
        return false;
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
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true); // Follow redirects
        curl_setopt($curl, CURLOPT_MAXREDIRS, 10); // Maximum redirects to follow
        
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
        $effective_url = curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);
        
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
        
        // For debugging
        if ($url !== $effective_url) {
            wp_github_sync_log("Followed redirect from {$url} to {$effective_url}", 'debug');
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
        protected $error_data = array();
        protected $error_messages = array();
        
        public function __construct($code = '', $message = '', $data = '') {
            if (!empty($code)) {
                $this->add($code, $message, $data);
            }
        }
        
        public function add($code, $message, $data = '') {
            $this->errors[$code][] = $message;
            if (!empty($data)) {
                $this->error_data[$code] = $data;
            }
            $this->error_messages[] = $message;
        }
        
        public function get_error_message($code = '') {
            if (empty($code)) {
                return reset($this->error_messages);
            }
            
            if (isset($this->errors[$code]) && isset($this->errors[$code][0])) {
                return $this->errors[$code][0];
            }
            
            return '';
        }
        
        public function get_error_code() {
            $codes = array_keys($this->errors);
            return reset($codes);
        }
        
        public function get_error_data($code = '') {
            if (empty($code)) {
                $code = $this->get_error_code();
            }
            
            if (isset($this->error_data[$code])) {
                return $this->error_data[$code];
            }
            
            return '';
        }
    }
}

if (!function_exists('wp_github_sync_encrypt')) {
    function wp_github_sync_encrypt($data) {
        // This is just a simple mock for testing
        return $data;
    }
}

if (!function_exists('wp_github_sync_decrypt')) {
    function wp_github_sync_decrypt($data) {
        // This is just a simple mock for testing
        return $data;
    }
}

if (!function_exists('wp_normalize_path')) {
    function wp_normalize_path($path) {
        return str_replace('\\', '/', $path);
    }
}

if (!function_exists('trailingslashit')) {
    function trailingslashit($string) {
        return rtrim($string, '/\\') . '/';
    }
}

if (!function_exists('add_query_arg')) {
    function add_query_arg($args, $url = '') {
        if (is_scalar($args)) {
            $args = array($args => $url);
            $url = '';
        }
        
        if (empty($url)) {
            $url = $_SERVER['REQUEST_URI'];
        }
        
        $url_parts = parse_url($url);
        if (!isset($url_parts['query'])) {
            $url_parts['query'] = '';
        }
        
        // Build the query string
        $params = array();
        if (!empty($url_parts['query'])) {
            parse_str($url_parts['query'], $params);
        }
        
        foreach ($args as $key => $value) {
            $params[$key] = $value;
        }
        
        // Build the new URL
        $url_parts['query'] = http_build_query($params);
        
        $url = $url_parts['scheme'] . '://' . $url_parts['host'];
        if (isset($url_parts['port'])) {
            $url .= ':' . $url_parts['port'];
        }
        
        if (isset($url_parts['path'])) {
            $url .= $url_parts['path'];
        }
        
        if (!empty($url_parts['query'])) {
            $url .= '?' . $url_parts['query'];
        }
        
        if (isset($url_parts['fragment'])) {
            $url .= '#' . $url_parts['fragment'];
        }
        
        return $url;
    }
}

if (!function_exists('__')) {
    function __($text, $domain = 'default') {
        return $text;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value, ...$args) {
        // Just return the value without filtering for the test
        return $value;
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) {
        return json_encode($data, $options, $depth);
    }
}

// Include the necessary files from the plugin
require_once dirname(__DIR__) . '/wp-github-sync/src/API/API_Client.php';
require_once dirname(__DIR__) . '/wp-github-sync/src/API/Repository.php';
require_once dirname(__DIR__) . '/wp-github-sync/src/API/Repository_Uploader.php';

// Define any missing constants needed by the plugin
if (!defined('WP_GITHUB_SYNC_URL')) {
    define('WP_GITHUB_SYNC_URL', '');
}
if (!defined('WP_GITHUB_SYNC_DIR')) {
    define('WP_GITHUB_SYNC_DIR', dirname(__DIR__) . '/wp-github-sync/');
}

use WPGitHubSync\API\API_Client;
use WPGitHubSync\API\Repository;
use WPGitHubSync\API\Repository_Uploader;

// Main test function
function run_compare_test() {
    global $wp_options, $wp_transients;
    
    // Reset global variables
    $wp_options = array();
    $wp_transients = array();
    
    // Replace with your token and repo
    $token = 'ghp_MOCK_TOKEN_FOR_TESTING_PURPOSES_ONLY_1234'; 
    $repo_url = 'https://github.com/CK-Daniel/kuper';
    $base_branch = 'main';
    
    // Setup options
    $wp_options['wp_github_sync_repository'] = $repo_url;
    $wp_options['wp_github_sync_access_token'] = $token;
    $wp_options['wp_github_sync_auth_method'] = 'pat';
    $wp_options['wp_github_sync_branch'] = $base_branch;
    $wp_options['wp_github_sync_etags'] = [];
    $wp_options['wp_github_sync_branches'] = [];
    $wp_options['wp_github_sync_rate_limit_remaining'] = 5000;
    $wp_options['wp_github_sync_rate_limit_reset'] = time() + 3600;
    
    echo "Testing repository comparison with:\n";
    echo "Repository: $repo_url\n";
    echo "Base Branch: $base_branch\n";
    echo "-------------------------------------\n";
    
    // Initialize API client
    $api_client = new API_Client();
    
    // Test authentication first
    echo "\nTesting authentication...\n";
    $auth_test = $api_client->test_authentication();
    if ($auth_test === true) {
        echo "Authentication successful!\n";
    } else {
        echo "Authentication failed: " . $auth_test . "\n";
        return;
    }
    
    // Create Repository instance
    $repository = new Repository($api_client);
    
    // Get the latest commit SHA of the base branch
    $base_sha = $api_client->get_branch_sha($base_branch);
    
    if (is_wp_error($base_sha)) {
        echo "Failed to get base branch SHA: " . $base_sha->get_error_message() . "\n";
        return;
    }
    
    echo "\nBase branch SHA: $base_sha\n";
    
    // Compare with the same branch (should have no differences)
    echo "\nComparing with the same branch (should have no differences)...\n";
    $comparison_result = $repository->compare($base_branch, $base_branch);
    
    if (is_wp_error($comparison_result)) {
        echo "Comparison failed: " . $comparison_result->get_error_message() . "\n";
        return;
    }
    
    echo "Comparison status: " . $comparison_result['status'] . "\n";
    echo "Total commits: " . count($comparison_result['commits']) . "\n";
    echo "Files changed: " . count($comparison_result['files']) . "\n";
    
    // Mock a second comparison with different refs (this might not work in real repositories)
    // But for testing, we're just checking if the compare functionality works
    echo "\nTesting another comparison with different refs...\n";
    
    // Get list of commits and use an older one
    $commits = $api_client->list_commits($base_branch, 10);
    
    if (is_wp_error($commits) || count($commits) < 2) {
        echo "Failed to get commits or not enough commits to compare.\n";
        return;
    }
    
    $older_commit = $commits[1]['sha']; // Take the second commit (older than the latest)
    
    echo "Comparing $older_commit with $base_branch...\n";
    $comparison_result2 = $repository->compare($older_commit, $base_branch);
    
    if (is_wp_error($comparison_result2)) {
        echo "Comparison failed: " . $comparison_result2->get_error_message() . "\n";
        return;
    }
    
    echo "Comparison status: " . $comparison_result2['status'] . "\n";
    echo "Total commits: " . count($comparison_result2['commits']) . "\n";
    echo "Files changed: " . count($comparison_result2['files']) . "\n";
    
    echo "\nTest completed.\n";
}

// Run the test
run_compare_test();