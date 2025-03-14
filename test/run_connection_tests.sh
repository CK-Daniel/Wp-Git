#!/bin/bash
# Script to run GitHub connection tests

echo "===== Running GitHub Connection Tests ====="
echo

# Find WordPress installation path
WP_PATH=""
for path in "../wp-load.php" "../../wp-load.php" "../../../wp-load.php" "../../../../wp-load.php"; do
    if [ -f "$path" ]; then
        WP_PATH=$(dirname $(realpath $path))
        break
    fi
done

if [ -z "$WP_PATH" ]; then
    echo "WordPress installation not found. This test needs to be run from within a WordPress installation."
    exit 1
fi

echo "WordPress found at: $WP_PATH"
echo

echo "Running basic token validation tests..."
php -f test_connection.php
echo

echo "Running AJAX handler tests..."
php -f test_ajax_connection.php
echo

echo "===== All tests complete ====="
echo
echo "Now you should also test in the WordPress admin:"
echo "1. Go to GitHub Sync Settings > Authentication tab"
echo "2. Enter different formats of GitHub tokens"
echo "3. Click 'Test Connection' after each entry to verify the UI feedback"
echo 
echo "Remember to test these scenarios:"
echo "- Empty token"
echo "- Invalid format token (e.g., 'test-token')"
echo "- Valid format but invalid token (e.g., 'github_pat_123456abc')"
echo "- Valid token (to confirm successful authentication)"
echo
echo "For valid tokens, you should receive a success message."
echo "For invalid tokens, you should receive a helpful error message."