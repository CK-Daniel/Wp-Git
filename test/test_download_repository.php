<?php
/**
 * Test script for WordPress GitHub Sync repository download functionality
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

if (!function_exists('unzip_file')) {
    function unzip_file($file, $to) {
        // For testing, we'll create a mock structure instead of actually unzipping
        // since we may not have ZipArchive available
        
        // Create mock repository structure
        $repo_dir = $to . '/CK-Daniel-kuper-e887862';
        wp_mkdir_p($repo_dir);
        
        // Create some mock files
        file_put_contents($repo_dir . '/README.md', '# Test Repository');
        file_put_contents($repo_dir . '/index.php', '<?php // Test index file');
        
        // Create a subdirectory with files
        wp_mkdir_p($repo_dir . '/assets');
        file_put_contents($repo_dir . '/assets/style.css', '/* Test CSS */');
        
        wp_github_sync_log("Created mock repository structure at {$repo_dir}", 'debug');
        
        return true;
    }
}

if (!function_exists('wp_get_upload_dir')) {
    function wp_get_upload_dir() {
        $dir = sys_get_temp_dir() . '/wp-uploads';
        wp_mkdir_p($dir);
        
        return array(
            'path' => $dir,
            'url' => 'https://example.com/wp-content/uploads',
            'subdir' => '',
            'basedir' => $dir,
            'baseurl' => 'https://example.com/wp-content/uploads',
            'error' => false,
        );
    }
}

if (!function_exists('sanitize_file_name')) {
    function sanitize_file_name($filename) {
        $filename = preg_replace('|[^a-zA-Z0-9_.-]|', '', $filename);
        $filename = preg_replace('|[.]+|', '.', $filename);
        $filename = trim($filename, '.-_');
        return $filename;
    }
}

if (!function_exists('__')) {
    function __($text, $domain = 'default') {
        return $text;
    }
}

if (!function_exists('download_url')) {
    function download_url($url, $timeout = 300) {
        global $wp_options;
        
        $temp_file = wp_tempnam(basename($url));
        if (!$temp_file) {
            return new WP_Error('http_no_file', 'Could not create temporary file.');
        }
        
        // Add authentication to GitHub API requests
        $args = array('timeout' => $timeout);
        if (strpos($url, 'api.github.com') !== false && isset($wp_options['wp_github_sync_access_token'])) {
            $token = $wp_options['wp_github_sync_access_token'];
            $args['headers'] = array(
                'Authorization' => 'token ' . $token,
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/GitHub-Sync'
            );
            wp_github_sync_log("Adding authentication headers to download request", 'debug');
        }
        
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            @unlink($temp_file);
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            @unlink($temp_file);
            return new WP_Error(
                'http_404',
                sprintf(__('The resource at %s returned a %d error.', 'wp-github-sync'), $url, $response_code)
            );
        }
        
        $content = wp_remote_retrieve_body($response);
        file_put_contents($temp_file, $content);
        
        wp_github_sync_log("Successfully downloaded file to {$temp_file} (size: " . strlen($content) . " bytes)", 'debug');
        
        return $temp_file;
    }
}

if (!function_exists('WP_Filesystem')) {
    function WP_Filesystem() {
        global $wp_filesystem;
        $wp_filesystem = new MockWPFilesystem();
        return true;
    }
}

// Mock WP_Filesystem class for testing
if (!class_exists('MockWPFilesystem')) {
    class MockWPFilesystem {
        public function rmdir($dir, $recursive = false) {
            if ($recursive) {
                if (is_dir($dir)) {
                    $files = array_diff(scandir($dir), array('.', '..'));
                    foreach ($files as $file) {
                        $path = $dir . '/' . $file;
                        if (is_dir($path)) {
                            $this->rmdir($path, true);
                        } else {
                            unlink($path);
                        }
                    }
                    return rmdir($dir);
                }
            } else {
                return rmdir($dir);
            }
            return false;
        }
        
        public function delete($file) {
            if (file_exists($file)) {
                return unlink($file);
            }
            return false;
        }
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
function run_download_repository_test() {
    global $wp_options, $wp_transients;
    
    // Reset global variables
    $wp_options = array();
    $wp_transients = array();
    
    // Replace with your token and repo
    $token = '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'; 
    $repo_url = 'https://github.com/CK-Daniel/kuper';
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
    
    echo "Testing repository download with:\n";
    echo "Repository: $repo_url\n";
    echo "Branch: $branch\n";
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
    
    // Create a test directory for download
    $target_dir = dirname(__DIR__) . '/test/download-test';
    echo "\nPreparing target directory: $target_dir\n";
    
    // Clean up any previous test directory
    if (is_dir($target_dir)) {
        cleanup_directory($target_dir);
    }
    
    wp_mkdir_p($target_dir);
    
    // Perform the download repository test
    echo "\nStarting repository download...\n";
    $download_result = $repository->download_repository($branch, $target_dir);
    
    if (is_wp_error($download_result)) {
        echo "Repository download failed: " . $download_result->get_error_message() . "\n";
        if (method_exists($download_result, 'get_error_data')) {
            print_r($download_result->get_error_data());
        }
    } else {
        echo "Repository download completed successfully!\n";
        
        // List the downloaded files
        echo "\nDownloaded files:\n";
        list_directory_contents($target_dir);
    }
    
    echo "\nTest completed.\n";
}

// Helper function to recursively list directory contents
function list_directory_contents($dir, $indent = 0) {
    $files = scandir($dir);
    
    foreach ($files as $file) {
        if ($file != '.' && $file != '..') {
            $path = $dir . '/' . $file;
            echo str_repeat(' ', $indent) . ($indent > 0 ? '└── ' : '') . $file . "\n";
            
            if (is_dir($path)) {
                list_directory_contents($path, $indent + 4);
            }
        }
    }
}

// Helper function to recursively remove a directory
function cleanup_directory($dir) {
    if (!is_dir($dir)) {
        return;
    }
    
    $files = array_diff(scandir($dir), array('.', '..'));
    
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        
        if (is_dir($path)) {
            cleanup_directory($path);
        } else {
            unlink($path);
        }
    }
    
    rmdir($dir);
}

// Helper function to recursively copy a directory
function copy_directory($source, $dest) {
    if (!is_dir($dest)) {
        mkdir($dest, 0777, true);
    }
    
    $items = scandir($source);
    
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        
        $src_path = $source . '/' . $item;
        $dst_path = $dest . '/' . $item;
        
        if (is_dir($src_path)) {
            if (!is_dir($dst_path)) {
                mkdir($dst_path, 0777, true);
            }
            copy_directory($src_path, $dst_path);
        } else {
            copy($src_path, $dst_path);
        }
    }
    
    return true;
}

// Helper function to recursively remove a directory
function recursive_rmdir($path) {
    if (!file_exists($path)) {
        return true;
    }
    
    if (is_file($path)) {
        return unlink($path);
    }
    
    if (is_dir($path)) {
        $files = array_diff(scandir($path), array('.', '..'));
        
        foreach ($files as $file) {
            $filepath = $path . '/' . $file;
            
            if (is_dir($filepath)) {
                recursive_rmdir($filepath);
            } else {
                unlink($filepath);
            }
        }
        
        return rmdir($path);
    }
    
    return false;
}

// Run the test
run_download_repository_test();