<?php
/**
 * Test script for WordPress GitHub Sync rollback functionality
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

if (!function_exists('add_query_arg')) {
    function add_query_arg($args, $url = '') {
        if (is_scalar($args)) {
            $args = array($args => $url);
            $url = '';
        }
        
        if (empty($url)) {
            $url = $_SERVER['REQUEST_URI'] ?? '';
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

if (!function_exists('unzip_file')) {
    function unzip_file($file, $to) {
        // For testing, we'll create a mock structure instead of actually unzipping
        // since we may not have ZipArchive available
        
        // Create mock repository structure
        $repo_dir = $to . '/CK-Daniel-kuper-e887862';
        if (!is_dir($repo_dir)) {
            mkdir($repo_dir, 0777, true);
        }
        
        // Create some mock files
        file_put_contents($repo_dir . '/README.md', '# Test Repository');
        file_put_contents($repo_dir . '/index.php', '<?php // Test index file');
        
        // Create a subdirectory with files
        $assets_dir = $repo_dir . '/assets';
        if (!is_dir($assets_dir)) {
            mkdir($assets_dir, 0777, true);
        }
        file_put_contents($repo_dir . '/assets/style.css', '/* Test CSS */');
        
        wp_github_sync_log("Created mock repository structure at {$repo_dir}", 'debug');
        
        return true;
    }
}

if (!function_exists('wp_get_upload_dir')) {
    function wp_get_upload_dir() {
        $dir = sys_get_temp_dir() . '/wp-uploads';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        
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

// Include necessary files for the test
require_once dirname(__DIR__) . '/wp-github-sync/src/API/API_Client.php';
require_once dirname(__DIR__) . '/wp-github-sync/src/API/Repository.php';
require_once dirname(__DIR__) . '/wp-github-sync/src/API/Repository_Uploader.php';

// Mock the Sync_Manager class for testing rollback functionality
class MockSyncManager {
    private $api_client;
    private $repository;
    
    public function __construct() {
        $this->api_client = new WPGitHubSync\API\API_Client();
        $this->repository = new WPGitHubSync\API\Repository($this->api_client);
    }
    
    public function rollback($commit_sha) {
        global $wp_options;
        
        wp_github_sync_log("Attempting to rollback to commit: {$commit_sha}", 'info');
        
        // Get current deployed commit
        $current_commit = $wp_options['wp_github_sync_last_deployed_commit'] ?? '';
        wp_github_sync_log("Current deployed commit: {$current_commit}", 'debug');
        
        // Check if we're already at the target commit
        if ($current_commit === $commit_sha) {
            wp_github_sync_log("Already at target commit, nothing to do.", 'info');
            return true;
        }
        
        wp_github_sync_log("Rolling back from {$current_commit} to {$commit_sha}", 'info');
        
        // Perform the rollback by "deploying" the specific commit
        $result = $this->deploy($commit_sha);
        
        if (is_wp_error($result)) {
            wp_github_sync_log("Rollback failed: " . $result->get_error_message(), 'error');
            return $result;
        }
        
        wp_github_sync_log("Rollback completed successfully", 'info');
        return true;
    }
    
    public function deploy($ref) {
        global $wp_options;
        
        wp_github_sync_log("Starting deployment of ref: {$ref}", 'info');
        
        // Create a test directory for download
        $target_dir = dirname(__DIR__) . '/test/deploy-test';
        wp_github_sync_log("Preparing target directory: {$target_dir}", 'debug');
        
        // Clean up any previous test directory
        if (is_dir($target_dir)) {
            $this->cleanup_directory($target_dir);
        }
        
        // Create directory
        if (!is_dir($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        
        // Perform the download repository test
        wp_github_sync_log("Downloading repository for ref: {$ref}", 'debug');
        $download_result = $this->repository->download_repository($ref, $target_dir);
        
        if (is_wp_error($download_result)) {
            wp_github_sync_log("Repository download failed: " . $download_result->get_error_message(), 'error');
            return $download_result;
        }
        
        wp_github_sync_log("Repository downloaded successfully", 'info');
        
        // List the downloaded files
        $this->list_directory_contents($target_dir);
        
        // Update last deployed commit
        wp_github_sync_log("Updating last deployed commit to: {$ref}", 'debug');
        $wp_options['wp_github_sync_last_deployed_commit'] = $ref;
        
        // Add to deployment history
        $history = $wp_options['wp_github_sync_deployment_history'] ?? array();
        $history[] = array(
            'commit' => $ref,
            'timestamp' => time()
        );
        
        // Limit history to 20 entries
        if (count($history) > 20) {
            $history = array_slice($history, -20);
        }
        
        $wp_options['wp_github_sync_deployment_history'] = $history;
        
        wp_github_sync_log("Deployment completed successfully", 'info');
        return true;
    }
    
    // Helper functions
    private function cleanup_directory($dir) {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), array('.', '..'));
        
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            
            if (is_dir($path)) {
                $this->cleanup_directory($path);
            } else {
                unlink($path);
            }
        }
        
        rmdir($dir);
    }
    
    private function list_directory_contents($dir, $indent = 0) {
        $files = scandir($dir);
        
        foreach ($files as $file) {
            if ($file != '.' && $file != '..') {
                $path = $dir . '/' . $file;
                echo str_repeat(' ', $indent) . ($indent > 0 ? '└── ' : '') . $file . "\n";
                
                if (is_dir($path)) {
                    $this->list_directory_contents($path, $indent + 4);
                }
            }
        }
    }
}

// Define any missing constants needed by the plugin
if (!defined('WP_GITHUB_SYNC_URL')) {
    define('WP_GITHUB_SYNC_URL', '');
}
if (!defined('WP_GITHUB_SYNC_DIR')) {
    define('WP_GITHUB_SYNC_DIR', dirname(__DIR__) . '/wp-github-sync/');
}

// Main test function
function run_rollback_test() {
    global $wp_options, $wp_transients;
    
    // Reset global variables
    $wp_options = array();
    $wp_transients = array();
    
    // Replace with your token and repo
    $token = 'ghp_MOCK_TOKEN_FOR_TESTING_PURPOSES_ONLY_1234'; 
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
    
    echo "Testing rollback functionality with:\n";
    echo "Repository: $repo_url\n";
    echo "Branch: $branch\n";
    echo "-------------------------------------\n";
    
    // Initialize API client
    $api_client = new WPGitHubSync\API\API_Client();
    
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
    $repository = new WPGitHubSync\API\Repository($api_client);
    
    // Create our mock Sync Manager
    $sync_manager = new MockSyncManager();
    
    // Get list of commits
    echo "\nFetching recent commits...\n";
    $commits = $api_client->list_commits($branch, 3);
    
    if (is_wp_error($commits)) {
        echo "Failed to get commits: " . $commits->get_error_message() . "\n";
        return;
    }
    
    if (count($commits) < 2) {
        echo "Not enough commits to test rollback. Need at least 2 commits.\n";
        return;
    }
    
    // Get the latest and previous commit
    $latest_commit = $commits[0]['sha'];
    $previous_commit = $commits[1]['sha'];
    
    echo "Latest commit: {$latest_commit}\n";
    echo "Previous commit: {$previous_commit}\n";
    
    // First deploy the latest commit
    echo "\nDeploying latest commit ({$latest_commit})...\n";
    $deploy_result = $sync_manager->deploy($latest_commit);
    
    if (is_wp_error($deploy_result)) {
        echo "Deployment failed: " . $deploy_result->get_error_message() . "\n";
        return;
    }
    
    echo "Deployment successful!\n";
    
    // Now rollback to the previous commit
    echo "\nRolling back to previous commit ({$previous_commit})...\n";
    $rollback_result = $sync_manager->rollback($previous_commit);
    
    if (is_wp_error($rollback_result)) {
        echo "Rollback failed: " . $rollback_result->get_error_message() . "\n";
        return;
    }
    
    echo "Rollback successful!\n";
    
    // Verify the rollback worked
    echo "\nVerifying rollback...\n";
    if ($wp_options['wp_github_sync_last_deployed_commit'] === $previous_commit) {
        echo "Verification successful: Current deployed commit is {$previous_commit}\n";
    } else {
        echo "Verification failed: Current deployed commit is " . $wp_options['wp_github_sync_last_deployed_commit'] . " instead of {$previous_commit}\n";
    }
    
    // Check deployment history
    echo "\nDeployment history:\n";
    $history = $wp_options['wp_github_sync_deployment_history'] ?? array();
    foreach ($history as $index => $entry) {
        $date = date('Y-m-d H:i:s', $entry['timestamp']);
        echo ($index + 1) . ". {$entry['commit']} - {$date}\n";
    }
    
    echo "\nTest completed.\n";
}

// Run the test
run_rollback_test();