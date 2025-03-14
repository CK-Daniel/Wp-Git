<?php
/**
 * Test script for WordPress GitHub Sync webhook handler functionality
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

if (!function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event($timestamp, $hook, $args = array()) {
        global $wp_scheduled_events;
        $wp_scheduled_events[] = array(
            'timestamp' => $timestamp,
            'hook' => $hook,
            'args' => $args
        );
        return true;
    }
}

if (!function_exists('status_header')) {
    function status_header($code) {
        echo "HTTP Status: $code\n";
    }
}

if (!function_exists('header')) {
    function header($header) {
        echo "Setting header: $header\n";
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return trim(strip_tags($str));
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value) {
        return stripslashes_deep($value);
    }
}

if (!function_exists('stripslashes_deep')) {
    function stripslashes_deep($value) {
        if (is_array($value)) {
            $value = array_map('stripslashes_deep', $value);
        } elseif (is_object($value)) {
            $vars = get_object_vars($value);
            foreach ($vars as $key => $data) {
                $value->{$key} = stripslashes_deep($data);
            }
        } elseif (is_string($value)) {
            $value = stripslashes($value);
        }
        return $value;
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) {
        return json_encode($data, $options, $depth);
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
    }
}

// Include the WebhookHandler from the new v2 plugin
require_once dirname(__DIR__) . '/wp-github-sync-v2/src/API/WebhookHandler.php';

// Define any missing constants needed by the plugin
if (!defined('WP_GITHUB_SYNC_VERSION')) {
    define('WP_GITHUB_SYNC_VERSION', '2.0.0');
}

// Main test function
function run_webhook_test() {
    global $wp_options, $wp_scheduled_events;
    
    // Reset global variables
    $wp_options = array();
    $wp_scheduled_events = array();
    
    // Setup repository and webhook settings
    $token = 'test_token123';
    $repo_url = 'https://github.com/test-user/test-repo';
    $webhook_secret = 'test_webhook_secret';
    
    // Setup options
    $wp_options['wp_github_sync_settings'] = array(
        'repo_url' => $repo_url,
        'access_token' => $token,
        'auth_method' => 'pat',
        'webhook_sync' => true,
        'webhook_secret' => $webhook_secret
    );
    
    // Setup environments
    $wp_options['wp_github_sync_environments'] = array(
        'development' => array(
            'branch' => 'develop',
            'enabled' => true,
            'auto_deploy' => true
        ),
        'staging' => array(
            'branch' => 'staging',
            'enabled' => true,
            'auto_deploy' => true
        ),
        'production' => array(
            'branch' => 'main',
            'enabled' => true,
            'auto_deploy' => false
        )
    );
    
    echo "Testing WebhookHandler functionality:\n";
    echo "Repository: $repo_url\n";
    echo "-------------------------------------\n";
    
    // Create webhook handler instance
    $webhook_handler = new \WPGitHubSync\API\WebhookHandler(WP_GITHUB_SYNC_VERSION);
    
    // Test push event
    echo "\nTesting push event handling...\n";
    test_push_event($webhook_handler);
    
    // Test pull request event
    echo "\nTesting pull request event handling...\n";
    test_pull_request_event($webhook_handler);
    
    // Test ping event
    echo "\nTesting ping event handling...\n";
    test_ping_event($webhook_handler);
    
    // Test repository verification
    echo "\nTesting repository verification...\n";
    test_repository_verification($webhook_handler);
    
    // Test webhook signature verification
    echo "\nTesting webhook signature verification...\n";
    test_signature_verification($webhook_handler);
    
    echo "\nTest completed.\n";
}

// Test push event handling
function test_push_event($webhook_handler) {
    global $wp_scheduled_events;
    $wp_scheduled_events = array();
    
    // Create mock push event payload
    $payload = array(
        'ref' => 'refs/heads/develop',
        'after' => '1234567890abcdef',
        'repository' => array(
            'full_name' => 'test-user/test-repo'
        ),
        'commits' => array(
            array(
                'id' => '1234567890abcdef',
                'message' => 'Test commit message',
                'author' => array(
                    'name' => 'Test User',
                    'email' => 'test@example.com'
                )
            )
        )
    );
    
    // Set HTTP headers
    $_SERVER['HTTP_X_GITHUB_EVENT'] = 'push';
    
    // Call method to process webhook directly
    $reflection = new ReflectionObject($webhook_handler);
    $method = $reflection->getMethod('process_webhook');
    $method->setAccessible(true);
    
    $result = $method->invoke($webhook_handler, $payload);
    
    // Check if event was scheduled properly
    if (count($wp_scheduled_events) > 0) {
        echo "Push event handled successfully - scheduled deployment\n";
        echo "Scheduled Event: " . $wp_scheduled_events[0]['hook'] . "\n";
        echo "Branch: " . $wp_scheduled_events[0]['args']['branch'] . "\n";
        echo "Environment: " . $wp_scheduled_events[0]['args']['environment'] . "\n";
    } else {
        echo "Push event failed to schedule deployment\n";
    }
}

// Test pull request event handling
function test_pull_request_event($webhook_handler) {
    global $wp_scheduled_events;
    $wp_scheduled_events = array();
    
    // Create mock pull request event payload
    $payload = array(
        'action' => 'closed',
        'pull_request' => array(
            'merged' => true,
            'merge_commit_sha' => '0987654321fedcba',
            'base' => array(
                'ref' => 'staging',
                'repo' => array(
                    'full_name' => 'test-user/test-repo'
                )
            )
        ),
        'number' => 42
    );
    
    // Set HTTP headers
    $_SERVER['HTTP_X_GITHUB_EVENT'] = 'pull_request';
    
    // Call method to process webhook directly
    $reflection = new ReflectionObject($webhook_handler);
    $method = $reflection->getMethod('process_webhook');
    $method->setAccessible(true);
    
    $result = $method->invoke($webhook_handler, $payload);
    
    // Check if event was scheduled properly
    if (count($wp_scheduled_events) > 0) {
        echo "Pull request merge event handled successfully - scheduled deployment\n";
        echo "Scheduled Event: " . $wp_scheduled_events[0]['hook'] . "\n";
        echo "Branch: " . $wp_scheduled_events[0]['args']['branch'] . "\n";
        echo "Environment: " . $wp_scheduled_events[0]['args']['environment'] . "\n";
    } else {
        echo "Pull request event failed to schedule deployment\n";
    }
}

// Test ping event handling
function test_ping_event($webhook_handler) {
    // Create mock ping event payload
    $payload = array(
        'hook_id' => 12345,
        'repository' => array(
            'full_name' => 'test-user/test-repo'
        )
    );
    
    // Set HTTP headers
    $_SERVER['HTTP_X_GITHUB_EVENT'] = 'ping';
    
    // Call method to process webhook directly
    $reflection = new ReflectionObject($webhook_handler);
    $method = $reflection->getMethod('process_webhook');
    $method->setAccessible(true);
    
    $result = $method->invoke($webhook_handler, $payload);
    
    echo "Ping event processed with result: " . ($result === true ? "Success" : "Failure") . "\n";
}

// Test repository verification
function test_repository_verification($webhook_handler) {
    // Test matching repository
    $payload = array(
        'repository' => array(
            'full_name' => 'test-user/test-repo'
        )
    );
    
    $reflection = new ReflectionObject($webhook_handler);
    $method = $reflection->getMethod('is_configured_repository');
    $method->setAccessible(true);
    
    $result = $method->invoke($webhook_handler, $payload);
    echo "Repository verification for matching repo: " . ($result ? "Success" : "Failure") . "\n";
    
    // Test non-matching repository
    $payload = array(
        'repository' => array(
            'full_name' => 'wrong-user/wrong-repo'
        )
    );
    
    $result = $method->invoke($webhook_handler, $payload);
    echo "Repository verification for non-matching repo: " . (!$result ? "Success" : "Failure") . "\n";
}

// Test webhook signature verification
function test_signature_verification($webhook_handler) {
    $secret = 'test_webhook_secret';
    $payload = '{"test":"data"}';
    
    // Generate a valid signature
    $signature = 'sha256=' . hash_hmac('sha256', $payload, $secret);
    $_SERVER['HTTP_X_HUB_SIGNATURE_256'] = $signature;
    
    $reflection = new ReflectionObject($webhook_handler);
    $method = $reflection->getMethod('verify_webhook_signature');
    $method->setAccessible(true);
    
    $result = $method->invoke($webhook_handler, $payload, $secret);
    echo "Signature verification with valid signature: " . ($result ? "Success" : "Failure") . "\n";
    
    // Test with invalid signature
    $_SERVER['HTTP_X_HUB_SIGNATURE_256'] = 'sha256=invalid_signature';
    $result = $method->invoke($webhook_handler, $payload, $secret);
    echo "Signature verification with invalid signature: " . (!$result ? "Success" : "Failure") . "\n";
}

// Run the test
run_webhook_test();