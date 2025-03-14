<?php
/**
 * Test script for WordPress GitHub Sync initial sync functionality
 * 
 * This is a standalone test script that can be run directly from the command line.
 */

// Define WordPress constants needed by the plugin
define('WPINC', 'wp-includes');
define('WP_CONTENT_DIR', dirname(__DIR__));
define('ABSPATH', dirname(__DIR__) . '/');
define('DAY_IN_SECONDS', 86400);

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

if (!function_exists('wp_tempnam')) {
    function wp_tempnam($filename = '', $dir = '') {
        if (empty($dir)) {
            $dir = sys_get_temp_dir();
        }
        
        if (empty($filename)) {
            $filename = uniqid('file_');
        }
        
        $tempfile = tempnam($dir, $filename);
        return $tempfile;
    }
}

if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p($dir) {
        if (file_exists($dir)) {
            return is_dir($dir);
        }
        
        return mkdir($dir, 0777, true);
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
function run_initial_sync_test() {
    global $wp_options, $wp_transients;
    
    // Reset global variables
    $wp_options = array();
    $wp_transients = array();
    
    // Using the provided token and repo
    $token = ''; // Add your GitHub token here
    // Use repository URL from settings if available
    $repo_url = get_option('wp_github_sync_repository', '');
    
    // Check if token is empty
    if (empty($token)) {
        echo "ERROR: No GitHub token provided. Please add your token to the script.\n";
        return;
    }
    $branch = 'main';
    
    // Setup options
    $wp_options['wp_github_sync_repository'] = $repo_url;
    $wp_options['wp_github_sync_access_token'] = $token;
    $wp_options['wp_github_sync_auth_method'] = 'pat';
    $wp_options['wp_github_sync_branch'] = $branch;
    $wp_options['wp_github_sync_etags'] = [];
    $wp_options['wp_github_sync_branches'] = [];
    $wp_options['wp_github_sync_rate_limit_remaining'] = 5000;
    $wp_options['wp_github_sync_rate_limit_reset'] = time() + 3600;
    
    echo "Testing initial sync with:\n";
    echo "Repository: $repo_url\n";
    echo "Branch: $branch\n";
    echo "-------------------------------------\n";
    
    // Create the test directory structure
    $test_dir = dirname(__DIR__) . '/test/wp-content';
    wp_mkdir_p($test_dir . '/themes/twentytwentythree');
    file_put_contents($test_dir . '/themes/twentytwentythree/style.css', "/*\nTheme Name: Twenty Twenty-Three\nDescription: Test theme\n*/");
    
    wp_mkdir_p($test_dir . '/plugins/test-plugin');
    file_put_contents($test_dir . '/plugins/test-plugin/test-plugin.php', "<?php\n/**\n * Plugin Name: Test Plugin\n * Description: A test plugin\n */\n");
    
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
    
    // Prepare for initial sync
    echo "\nStarting initial sync process...\n";
    
    // Override the WP_CONTENT_DIR constant for this test
    define('TEST_WP_CONTENT_DIR', $test_dir);
    
    // Create test data to sync
    create_test_data();
    
    // Perform the initial sync
    $sync_result = $repository->initial_sync($branch);
    
    if (is_wp_error($sync_result)) {
        echo "Initial sync failed: " . $sync_result->get_error_message() . "\n";
        if (method_exists($sync_result, 'get_error_data')) {
            print_r($sync_result->get_error_data());
        }
    } else {
        echo "Initial sync completed successfully!\n";
        echo "Commit hash: " . $sync_result . "\n";
    }
    
    echo "\nTest completed.\n";
}

function create_test_data() {
    $test_dir = dirname(__DIR__) . '/test/wp-content';
    
    // Create some test data files
    wp_mkdir_p($test_dir . '/themes/twentytwentythree');
    file_put_contents($test_dir . '/themes/twentytwentythree/style.css', "/*\nTheme Name: Twenty Twenty-Three\nDescription: Test theme for GitHub Sync\n*/");
    file_put_contents($test_dir . '/themes/twentytwentythree/index.php', "<?php\n// Test index file\n");
    
    wp_mkdir_p($test_dir . '/plugins/test-plugin');
    file_put_contents($test_dir . '/plugins/test-plugin/test-plugin.php', "<?php\n/**\n * Plugin Name: Test Plugin\n * Description: A test plugin for GitHub Sync\n * Version: 1.0.0\n */\n");
    
    // Create a test functions.php file
    file_put_contents($test_dir . '/themes/twentytwentythree/functions.php', "<?php\n/**\n * Test functions file\n */\n\nfunction test_function() {\n    return 'Hello World';\n}\n");
}

// Adjust API client's parent directories for mock WordPress environment
class_alias('WPGitHubSync\API\Repository', 'Repository');
class_alias('WPGitHubSync\API\Repository_Uploader', 'Repository_Uploader');

// Run the test
run_initial_sync_test();