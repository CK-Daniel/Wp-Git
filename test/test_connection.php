<?php
/**
 * Test script for GitHub connection validation
 * 
 * This script tests the GitHub API connection functionality
 * to ensure our fixes work properly
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

// Check if the plugin is active
if (!function_exists('wp_github_sync_test_authentication')) {
    // Load the plugin manually if needed
    if (file_exists(dirname(dirname(__FILE__)) . '/wp-github-sync/wp-github-sync.php')) {
        include_once dirname(dirname(__FILE__)) . '/wp-github-sync/wp-github-sync.php';
    } else {
        die('WordPress GitHub Sync plugin not found or not active.');
    }
}

// Enable debug logging
if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', true);
}

// Set up our test
echo "===== GitHub API Connection Test =====\n\n";

// Create an instance of the GitHub API client
$api_client = new \WPGitHubSync\API\API_Client();

// Test cases for different token formats
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
    
    // Set the temporary token
    $api_client->set_temporary_token($token);
    
    // Test authentication
    $result = $api_client->test_authentication();
    
    echo "Result: " . ($result === true ? "SUCCESS" : "FAILED - " . $result) . "\n\n";
}

// Manual test instructions
echo "===== Manual Test Instructions =====\n\n";
echo "To test with a real token in the WordPress admin:\n";
echo "1. Go to GitHub Sync Settings > Authentication tab\n";
echo "2. Enter a GitHub token\n";
echo "3. Click 'Test Connection'\n";
echo "4. Verify that you receive a proper validation result\n\n";
echo "For invalid tokens, you should see a helpful error message\n";
echo "For valid tokens, it should authenticate successfully\n\n";

echo "Test complete!\n";