<?php
/**
 * Run all WordPress GitHub Sync tests
 * 
 * This script runs all available test scripts in sequence.
 */

echo "WordPress GitHub Sync Test Runner\n";
echo "================================\n\n";

$tests = [
    // Original tests
    'test_initial_sync.php',
    'test_download_repository.php',
    'test_repository_compare.php',
    'test_rollback.php',
    
    // New tests for v2
    'test_webhook_handler.php',
    'test_ui_components.php',
    'test_enhanced_rollback.php'
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