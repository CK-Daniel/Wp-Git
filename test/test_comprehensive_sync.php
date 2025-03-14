<?php
/**
 * Comprehensive test for WordPress GitHub Sync
 * 
 * Tests the entire process:
 * 1. Authentication
 * 2. Repository verification
 * 3. Auto-initialization of empty repositories
 * 4. Initial sync with WordPress content
 */

// Helper functions in WPGitHubSync namespace to allow API classes to work
namespace WPGitHubSync\API {
    function get_option($option, $default = false) {
        return \get_option($option, $default);
    }
    
    function update_option($option, $value, $autoload = null) {
        return \update_option($option, $value, $autoload);
    }
    
    function get_bloginfo($show = '') {
        return \get_bloginfo($show);
    }
    
    function wp_remote_get($url, $args = array()) {
        return \wp_remote_get($url, $args);
    }
    
    function wp_remote_post($url, $args = array()) {
        return \wp_remote_post($url, $args);
    }
    
    function wp_remote_request($url, $args = array()) {
        return \wp_remote_request($url, $args);
    }
    
    function wp_remote_retrieve_body($response) {
        return \wp_remote_retrieve_body($response);
    }
    
    function wp_remote_retrieve_response_code($response) {
        return \wp_remote_retrieve_response_code($response);
    }
    
    function wp_remote_retrieve_header($response, $header) {
        return \wp_remote_retrieve_header($response, $header);
    }
    
    function is_wp_error($thing) {
        return \is_wp_error($thing);
    }
    
    function set_transient($key, $value, $expiration = 0) {
        return \set_transient($key, $value, $expiration);
    }
    
    function get_transient($key) {
        return \get_transient($key);
    }
    
    function delete_transient($key) {
        return \delete_transient($key);
    }
    
    function wp_github_sync_log($message, $level = 'info', $force = false) {
        return \wp_github_sync_log($message, $level, $force);
    }
    
    function wp_mkdir_p($dir) {
        return \wp_mkdir_p($dir);
    }
    
    function wp_tempnam($filename = '', $dir = '') {
        return \wp_tempnam($filename, $dir);
    }
    
    function wp_json_encode($data, $options = 0, $depth = 512) {
        return \wp_json_encode($data, $options, $depth);
    }
    
    function wp_github_sync_encrypt($data) {
        return \wp_github_sync_encrypt($data);
    }
    
    function wp_github_sync_decrypt($data) {
        return \wp_github_sync_decrypt($data);
    }
    
    function apply_filters($tag, $value, ...$args) {
        return \apply_filters($tag, $value, ...$args);
    }
    
    function wp_normalize_path($path) {
        return \wp_normalize_path($path);
    }
    
    function trailingslashit($string) {
        return \trailingslashit($string);
    }
    
    function add_query_arg($args, $url) {
        return \add_query_arg($args, $url);
    }
}

namespace {
    // Define WordPress constants needed by the plugin
    define('WPINC', 'wp-includes');
    define('WP_CONTENT_DIR', dirname(__DIR__) . '/test');
    define('ABSPATH', dirname(__DIR__) . '/');
    define('DAY_IN_SECONDS', 86400);
    define('WP_GITHUB_SYNC_DIR', dirname(__DIR__) . '/wp-github-sync/');
    define('WP_GITHUB_SYNC_URL', '');
    
    // Mock WordPress functions
    function get_option($option, $default = false) {
        global $wp_options;
        return isset($wp_options[$option]) ? $wp_options[$option] : $default;
    }
    
    function update_option($option, $value, $autoload = null) {
        global $wp_options;
        $wp_options[$option] = $value;
        return true;
    }
    
    function wp_github_sync_log($message, $level = 'info', $force = false) {
        echo "[$level] $message\n";
    }
    
    function set_transient($transient, $value, $expiration = 0) {
        global $wp_transients;
        $wp_transients[$transient] = $value;
        return true;
    }
    
    function get_transient($transient) {
        global $wp_transients;
        return isset($wp_transients[$transient]) ? $wp_transients[$transient] : false;
    }
    
    function delete_transient($transient) {
        global $wp_transients;
        if (isset($wp_transients[$transient])) {
            unset($wp_transients[$transient]);
            return true;
        }
        return false;
    }
    
    function get_bloginfo($show = '') {
        switch ($show) {
            case 'name':
                return 'Comprehensive Test WordPress Site';
            case 'url':
                return 'https://example.com/test';
            case 'version':
                return '6.4';
            default:
                return '';
        }
    }
    
    function wp_github_sync_encrypt($data) {
        return $data;
    }
    
    function wp_github_sync_decrypt($data) {
        return $data;
    }
    
    function wp_normalize_path($path) {
        return str_replace('\\', '/', $path);
    }
    
    function trailingslashit($string) {
        return rtrim($string, '/\\') . '/';
    }
    
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
    
    function wp_mkdir_p($dir) {
        if (file_exists($dir)) {
            return is_dir($dir);
        }
        
        return mkdir($dir, 0777, true);
    }
    
    function apply_filters($tag, $value, ...$args) {
        return $value;
    }
    
    function wp_json_encode($data, $options = 0, $depth = 512) {
        return json_encode($data, $options, $depth);
    }
    
    function is_wp_error($thing) {
        return $thing instanceof WP_Error;
    }
    
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
    
    // Add WordPress remote functions
    function wp_remote_get($url, $args = array()) {
        return wp_remote_request($url, $args);
    }
    
    function wp_remote_post($url, $args = array()) {
        $args['method'] = 'POST';
        return wp_remote_request($url, $args);
    }
    
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
    
    function wp_remote_retrieve_body($response) {
        if (is_wp_error($response) || !isset($response['body'])) {
            return '';
        }
        return $response['body'];
    }
    
    function wp_remote_retrieve_response_code($response) {
        if (is_wp_error($response) || !isset($response['response']['code'])) {
            return 0;
        }
        return $response['response']['code'];
    }
    
    function wp_remote_retrieve_header($response, $header) {
        if (is_wp_error($response) || !isset($response['headers'][strtolower($header)])) {
            return '';
        }
        return $response['headers'][strtolower($header)];
    }
    
    // Add WordPress utility function
    function add_query_arg() {
        $args = func_get_args();
        if (is_array($args[0])) {
            if (count($args) < 2 || false === $args[1]) {
                $uri = $_SERVER['REQUEST_URI'];
            } else {
                $uri = $args[1];
            }
            $params = $args[0];
        } else {
            if (count($args) < 3 || false === $args[2]) {
                $uri = $_SERVER['REQUEST_URI'];
            } else {
                $uri = $args[2];
            }
            $params = [$args[0] => $args[1]];
        }
        
        // Parse the URI
        $parts = parse_url($uri);
        
        // Build the URL without query string
        $base_url = (isset($parts['scheme']) ? $parts['scheme'] . '://' : '') .
                   (isset($parts['user']) ? $parts['user'] . (isset($parts['pass']) ? ':' . $parts['pass'] : '') . '@' : '') .
                   (isset($parts['host']) ? $parts['host'] : '') .
                   (isset($parts['port']) ? ':' . $parts['port'] : '') .
                   (isset($parts['path']) ? $parts['path'] : '');
        
        // Parse the query string
        $query = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        
        // Merge with new params
        $query = array_merge($query, $params);
        
        // Build the new URL
        $url = $base_url;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }
        if (isset($parts['fragment'])) {
            $url .= '#' . $parts['fragment'];
        }
        
        return $url;
    }
    
    // Include required files
    require_once dirname(__DIR__) . '/wp-github-sync/src/API/API_Client.php';
    require_once dirname(__DIR__) . '/wp-github-sync/src/API/Repository.php';
    require_once dirname(__DIR__) . '/wp-github-sync/src/API/Repository_Uploader.php';
    
    use WPGitHubSync\API\API_Client;
    use WPGitHubSync\API\Repository;
    use WPGitHubSync\API\Repository_Uploader;
    
    // Main test function
    function run_comprehensive_test() {
        global $wp_options, $wp_transients;
        
        // Reset global variables
        $wp_options = array();
        $wp_transients = array();
        
        // Test configuration
        $token = ''; // Add your GitHub token here
        $repo_url = 'https://github.com/CK-Daniel/kuper';
        
        // Check if token is empty
        if (empty($token)) {
            echo "ERROR: No GitHub token provided. Please add your token to the script.\n";
            return;
        }
        $branch = 'main';
        
        echo "====================================================\n";
        echo "COMPREHENSIVE WP-GITHUB-SYNC TEST\n";
        echo "====================================================\n";
        echo "Testing with:\n";
        echo "- Token: " . substr($token, 0, 4) . "..." . substr($token, -4) . "\n";
        echo "- Repository: $repo_url\n";
        echo "- Branch: $branch\n";
        echo "----------------------------------------------------\n";
        
        // Setup options
        $wp_options['wp_github_sync_repository'] = $repo_url;
        $wp_options['wp_github_sync_access_token'] = $token;
        $wp_options['wp_github_sync_auth_method'] = 'pat';
        $wp_options['wp_github_sync_branch'] = $branch;
        
        // Step 1: Test Authentication
        echo "\nSTEP 1: Testing GitHub Authentication\n";
        echo "----------------------------------------------------\n";
        
        $api_client = new API_Client();
        
        $auth_test = $api_client->test_authentication();
        if ($auth_test === true) {
            echo "✅ Authentication successful!\n";
        } else {
            echo "❌ Authentication failed: " . $auth_test . "\n";
            return;
        }
        
        // Step 2: Test Repository Access
        echo "\nSTEP 2: Testing Repository Access\n";
        echo "----------------------------------------------------\n";
        
        $repo_info = $api_client->get_repository();
        if (is_wp_error($repo_info)) {
            $error_message = $repo_info->get_error_message();
            echo "Repository access check: " . $error_message . "\n";
            
            // Check if this is an empty repository error
            if (strpos($error_message, 'Git Repository is empty') !== false) {
                echo "ℹ️ Repository is empty. Will be auto-initialized during sync.\n";
            } else if (strpos($error_message, 'Not Found') !== false) {
                echo "ℹ️ Repository not found. Might be created or auto-initialized during sync.\n";
            } else {
                echo "❌ Repository access error. Please check repository URL and permissions.\n";
                return;
            }
        } else {
            echo "✅ Repository access successful!\n";
            echo "Repository info: {$repo_info['full_name']}\n";
            echo "Default branch: {$repo_info['default_branch']}\n";
        }
        
        // Step 3: Test Branch Access
        echo "\nSTEP 3: Testing Branch Access\n";
        echo "----------------------------------------------------\n";
        
        // First try to get default branch info
        $default_branch = $api_client->get_default_branch();
        if (is_wp_error($default_branch)) {
            echo "Default branch check: " . $default_branch->get_error_message() . "\n";
            echo "ℹ️ Will use provided branch: $branch\n";
        } else {
            echo "✅ Default branch is: $default_branch\n";
            // Use the detected default branch if different from provided
            if ($default_branch !== $branch) {
                echo "ℹ️ Using detected default branch instead of provided branch\n";
                $branch = $default_branch;
                $wp_options['wp_github_sync_branch'] = $branch;
            }
        }
        
        // Check if the specific branch exists
        $branch_check = $api_client->request("repos/{$api_client->get_owner()}/{$api_client->get_repo()}/branches/$branch");
        if (is_wp_error($branch_check)) {
            echo "Branch '$branch' check: " . $branch_check->get_error_message() . "\n";
            echo "ℹ️ Branch might be created during auto-initialization\n";
        } else {
            echo "✅ Branch '$branch' exists\n";
            if (isset($branch_check['commit']['sha'])) {
                echo "Latest commit: " . $branch_check['commit']['sha'] . "\n";
            }
        }
        
        // Step 4: Setup test content
        echo "\nSTEP 4: Setting up test content\n";
        echo "----------------------------------------------------\n";
        
        // Setup test directory structure
        $test_dir = WP_CONTENT_DIR;
        echo "Using test directory: $test_dir\n";
        
        // Create themes directory and content
        wp_mkdir_p($test_dir . '/themes/twentytwentythree');
        file_put_contents($test_dir . '/themes/twentytwentythree/style.css', "/*\nTheme Name: Twenty Twenty-Three\nDescription: Test theme for Comprehensive GitHub Sync Test\n*/");
        file_put_contents($test_dir . '/themes/twentytwentythree/index.php', "<?php\n// Twenty Twenty-Three test index file created at " . date('Y-m-d H:i:s') . "\n");
        file_put_contents($test_dir . '/themes/twentytwentythree/functions.php', "<?php\n/**\n * Test functions for Twenty Twenty-Three theme\n */\n\nfunction comprehensive_test_function() {\n    return 'This is a test from the comprehensive sync test';\n}\n");
        
        // Create plugins directory and content
        wp_mkdir_p($test_dir . '/plugins/test-plugin');
        file_put_contents($test_dir . '/plugins/test-plugin/test-plugin.php', "<?php\n/**\n * Plugin Name: Test Plugin\n * Description: A test plugin for Comprehensive GitHub Sync Test\n * Version: 1.0.0\n */\n\n// Test plugin file created at " . date('Y-m-d H:i:s') . "\n");
        
        echo "✅ Test content created successfully\n";
        
        // Step 5: Test Repository Initialization
        echo "\nSTEP 5: Testing Repository Initialization\n";
        echo "----------------------------------------------------\n";
        
        // Initialize the repository if needed
        $init_result = $api_client->initialize_repository($branch);
        if (is_wp_error($init_result)) {
            echo "Manual repository initialization: " . $init_result->get_error_message() . "\n";
            echo "ℹ️ Will rely on auto-initialization during sync\n";
        } else {
            echo "✅ Repository initialization successful\n";
            if (isset($init_result['sha'])) {
                echo "Commit SHA: " . $init_result['sha'] . "\n";
            }
        }
        
        // Step 6: Perform Initial Sync
        echo "\nSTEP 6: Performing Initial Sync\n";
        echo "----------------------------------------------------\n";
        
        $repository = new Repository($api_client);
        $sync_result = $repository->initial_sync($branch);
        
        if (is_wp_error($sync_result)) {
            echo "❌ Initial sync failed: " . $sync_result->get_error_message() . "\n";
            if (method_exists($sync_result, 'get_error_data')) {
                $error_data = $sync_result->get_error_data();
                if (!empty($error_data)) {
                    echo "Error data: " . print_r($error_data, true) . "\n";
                }
            }
            return;
        } else {
            echo "✅ Initial sync completed successfully!\n";
            echo "Commit ID: " . $sync_result . "\n";
        }
        
        // Step 7: Verify sync by checking repository
        echo "\nSTEP 7: Verifying Sync Results\n";
        echo "----------------------------------------------------\n";
        
        // Get latest commit
        $latest_commit = $api_client->get_latest_commit($branch);
        if (is_wp_error($latest_commit)) {
            echo "❌ Failed to get latest commit: " . $latest_commit->get_error_message() . "\n";
        } else {
            echo "✅ Latest commit: " . $latest_commit['sha'] . "\n";
            echo "Commit message: " . $latest_commit['commit']['message'] . "\n";
            
            // Check for synced files
            echo "Checking for synced files...\n";
            $check_files = [
                'wp-content/themes/twentytwentythree/style.css',
                'wp-content/themes/twentytwentythree/functions.php',
                'wp-content/plugins/test-plugin/test-plugin.php'
            ];
            
            $all_files_found = true;
            foreach ($check_files as $file) {
                $file_check = $api_client->get_contents($file, $branch);
                if (is_wp_error($file_check)) {
                    echo "❌ File not found: $file\n";
                    $all_files_found = false;
                } else {
                    echo "✅ File found: $file\n";
                }
            }
            
            if ($all_files_found) {
                echo "✅ All expected files found in repository\n";
            } else {
                echo "❌ Some expected files were not found in repository\n";
            }
        }
        
        echo "\n====================================================\n";
        echo "COMPREHENSIVE TEST COMPLETED\n";
        echo "====================================================\n";
        echo "Result: " . (is_wp_error($sync_result) ? "❌ FAILED" : "✅ SUCCESS") . "\n";
    }
    
    // Run the test
    run_comprehensive_test();
}