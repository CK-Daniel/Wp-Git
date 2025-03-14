<?php
/**
 * Run all WordPress GitHub Sync tests
 * 
 * This script runs all available test scripts in sequence.
 */

echo "WordPress GitHub Sync Test Runner\n";
echo "================================\n\n";

// Note: These tests require a valid GitHub token to run properly
// Since we're using mocks, we'll get authentication errors, but the tests will still validate
// the plugin's code structure and functionality
$tests = [
    // Core GitHub API functionality tests
    'test_webhook_handler.php',      // Tests webhook signature verification
    'test_enhanced_rollback.php',    // Tests rollback functionality
    
    // Repository interaction tests (these will show auth errors with mock token)
    'test_initial_sync.php',         // Tests initial repository sync
    'test_download_repository.php',  // Tests downloading a repository
    'test_repository_compare.php',   // Tests comparing repository versions
    'test_rollback.php',             // Tests basic rollback functionality
    
    // Added comprehensive test for initial sync with auto-initialization
    'test_comprehensive_sync.php',   // Tests complete flow including auto-init for empty repo
];

foreach ($tests as $index => $test) {
    $number = $index + 1;
    echo "\nTest {$number}/" . count($tests) . ": Running {$test}\n";
    echo "----------------------------------------\n";
    
    $output = [];
    $return_var = 0;
    
    exec("php " . __DIR__ . "/{$test} 2>&1", $output, $return_var);
    
    foreach ($output as $line) {
        echo $line . "\n";
    }
    
    if ($return_var !== 0) {
        echo "\nTest failed with exit code: {$return_var}\n";
    } else {
        echo "\nTest completed successfully.\n";
    }
    
    echo "----------------------------------------\n";
}

echo "\nAll tests completed.\n";