<?php
/**
 * Test script for GitHub connection AJAX functionality
 * 
 * This script simulates the AJAX request triggered by the 
 * "Test Connection" button in the admin UI
 */

// Load WordPress
if (!defined('ABSPATH')) {
    // Find the WordPress installation
    $wp_load_paths = array(
        dirname(dirname(__FILE__)) . '/wp-load.php',
        dirname(dirname(dirname(__FILE__))) . '/wp-load.php',
        dirname(dirname(dirname(dirname(__FILE__)))) . '/wp-load.php',
        dirname(dirname(dirname(dirname(dirname(__FILE__))))) . '/wp-load.php',
    );

    foreach ($wp_load_paths as $wp_load_path) {
        if (file_exists($wp_load_path)) {
            require_once $wp_load_path;
            break;
        }
    }

    if (!defined('ABSPATH')) {
        die('WordPress not found. Please run this test from a WordPress installation.');
    }
}

// Enable debug logging
if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', true);
}

// Common function to simulate AJAX request
function simulate_ajax_test_connection($token, $repo_url = '') {
    // Create a nonce for the request
    $nonce = wp_create_nonce('wp_github_sync_nonce');
    
    // Set up the request
    $_POST = array(
        'action' => 'wp_github_sync_test_connection',
        'token' => $token,
        'repo_url' => $repo_url,
        'nonce' => $nonce
    );
    
    // Get the admin instance
    global $wp_github_sync_admin;
    if (!isset($wp_github_sync_admin) || !is_object($wp_github_sync_admin)) {
        // Try to get it from WordPress
        global $wp_filter;
        $found = false;
        
        if (isset($wp_filter['wp_ajax_wp_github_sync_test_connection'])) {
            foreach ($wp_filter['wp_ajax_wp_github_sync_test_connection']->callbacks as $priority => $callbacks) {
                foreach ($callbacks as $callback) {
                    if (is_array($callback['function']) && is_object($callback['function'][0])) {
                        $wp_github_sync_admin = $callback['function'][0];
                        $found = true;
                        break 2;
                    }
                }
            }
        }
        
        if (!$found) {
            echo "Could not find the Admin class. Make sure the plugin is activated.\n";
            return false;
        }
    }
    
    // Start output buffering to capture the JSON response
    ob_start();
    
    // Call the AJAX handler directly
    call_user_func(array($wp_github_sync_admin, 'handle_ajax_test_connection'));
    
    // Get the output and decode it
    $output = ob_get_clean();
    return json_decode($output, true);
}

// Test cases for different token formats
echo "===== GitHub Connection AJAX Test =====\n\n";

$test_tokens = array(
    'invalid_token' => 'invalid-token-format-123',
    'empty_token' => '',
    // Use example formats - these won't authenticate but will test format validation
    'github_pat_format' => 'github_pat_examplepatternwithalpha1numericchars',
    'ghp_format' => 'ghp_exampleclassicpat123456789012345',
    'classic_hex' => str_repeat('a1b2c3d4', 5) // 40 char hex PAT simulation
);

// Run tests for each token
foreach ($test_tokens as $type => $token) {
    echo "Testing token type: {$type}\n";
    echo "Token length: " . strlen($token) . "\n";
    
    $result = simulate_ajax_test_connection($token);
    
    if ($result) {
        echo "AJAX Result: " . ($result['success'] ? "SUCCESS" : "FAILED") . "\n";
        echo "Message: " . (isset($result['data']['message']) ? $result['data']['message'] : "No message") . "\n\n";
    } else {
        echo "AJAX test failed - could not simulate AJAX request\n\n";
    }
}

// Test with both token and repo URL
echo "Testing with token and repository URL\n";
$result = simulate_ajax_test_connection('github_pat_examplepatternwithalpha1numericchars', 'https://github.com/example/repository');

if ($result) {
    echo "AJAX Result: " . ($result['success'] ? "SUCCESS" : "FAILED") . "\n";
    echo "Message: " . (isset($result['data']['message']) ? $result['data']['message'] : "No message") . "\n\n";
} else {
    echo "AJAX test failed - could not simulate AJAX request\n\n";
}

echo "===== Manual UI Test Instructions =====\n\n";
echo "To test in the WordPress admin UI:\n";
echo "1. Go to GitHub Sync Settings > Authentication tab\n";
echo "2. Enter different formats of GitHub tokens:\n";
echo "   - Invalid format (e.g., 'invalid-token')\n";
echo "   - Valid format but wrong token (e.g., 'github_pat_123456789abcdef')\n";
echo "   - Actual valid token\n";
echo "3. Click 'Test Connection' after each entry\n";
echo "4. Verify that you receive appropriate error messages for invalid tokens\n";
echo "5. Verify that valid tokens authenticate successfully\n\n";

echo "Test complete!\n";