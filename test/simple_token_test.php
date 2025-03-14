<?php
/**
 * Super simple GitHub token format validator
 * 
 * Tests token formats without any WordPress dependencies
 */

function test_token_format($token) {
    if (empty($token)) {
        echo "Token is empty!\n";
        return false;
    }
    
    // Check token format
    if (strpos($token, 'github_pat_') === 0) {
        echo "Token appears to be a fine-grained PAT format ✓\n";
        return true;
    } else if (strpos($token, 'ghp_') === 0) {
        echo "Token appears to be a classic PAT format ✓\n";
        return true;
    } else if (strpos($token, 'gho_') === 0) {
        echo "Token appears to be an OAuth token format ✓\n";
        return true;
    } else if (strlen($token) === 40 && ctype_xdigit($token)) {
        echo "Token appears to be a classic PAT (40 char hex) format ✓\n";
        return true;
    } else {
        echo "❌ Token format doesn't match known GitHub token patterns\n";
        return false;
    }
}

// Function to simulate GitHub API requests
function test_github_api($token) {
    echo "Token: " . substr($token, 0, 4) . "..." . substr($token, -4) . " (Length: " . strlen($token) . ")\n";
    
    $valid_format = test_token_format($token);
    
    if (!$valid_format) {
        return "Invalid token format. GitHub tokens should start with 'github_pat_', 'ghp_', or be a 40-character hexadecimal string.";
    }
    
    // Create a test curl request
    $curl = curl_init("https://api.github.com/user");
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, [
        "Accept: application/vnd.github+json",
        "Authorization: Bearer " . $token,
        "User-Agent: GitHub-Token-Test/1.0",
        "X-GitHub-Api-Version: 2022-11-28" 
    ]);
    curl_setopt($curl, CURLOPT_TIMEOUT, 10);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
    
    echo "Sending real request to GitHub API...\n";
    $response = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    
    curl_close($curl);
    
    echo "HTTP Status: " . $status . "\n";
    
    if ($error) {
        echo "cURL Error: " . $error . "\n";
        return "Connection error: " . $error;
    }
    
    $data = json_decode($response, true);
    
    if ($status === 200) {
        echo "✅ SUCCESS - Authenticated as: " . $data['login'] . "\n";
        return true;
    } else if ($status === 401) {
        echo "❌ FAILED - Bad credentials\n";
        $message = isset($data['message']) ? $data['message'] : "Unknown error";
        echo "GitHub says: " . $message . "\n";
        return "Invalid GitHub token (Bad credentials). Please check your token and make sure it has the necessary permissions.";
    } else {
        echo "❌ FAILED - Unexpected status code\n";
        $message = isset($data['message']) ? $data['message'] : "Unknown error";
        echo "GitHub says: " . $message . "\n";
        return "GitHub API error: " . $message;
    }
}

// Test cases for different token formats
$test_tokens = array(
    'invalid_token' => 'invalid-token-format-123',
    'empty_token' => '',
    'github_pat_format' => 'github_pat_examplepatternwithalpha1numericchars',
    'ghp_format' => 'ghp_exampleclassicpat123456789012345',
    'classic_hex' => str_repeat('a1b2c3d4', 5) // 40 char hex PAT simulation
);

echo "===== GitHub Token Format Test =====\n\n";

// Run format tests for each token
foreach ($test_tokens as $type => $token) {
    echo "\n----- Testing token type: {$type} -----\n";
    $result = test_token_format($token);
    echo "Format valid: " . ($result ? "Yes" : "No") . "\n";
}

// Ask if the user wants to test with a real token
echo "\n===== GitHub API Live Test =====\n\n";
echo "Would you like to test with a real GitHub token? (y/n): ";
$handle = fopen("php://stdin", "r");
$line = trim(fgets($handle));

if (strtolower($line) == 'y') {
    echo "Enter your GitHub token: ";
    $token = trim(fgets($handle));
    
    echo "\n";
    $result = test_github_api($token);
    echo "\nAPI Test Result: " . ($result === true ? "Token is valid and working" : $result) . "\n\n";
} else {
    echo "Skipping live API test.\n\n";
}

fclose($handle);

// Provide instructions for the UI test
echo "===== UI Testing Instructions =====\n\n";
echo "To test the connection in the WordPress admin UI:\n";
echo "1. Go to GitHub Sync Settings > Authentication tab\n";
echo "2. Enter a GitHub token\n";
echo "3. Click 'Test Connection'\n\n";

echo "If you see 'Authentication failed: Invalid GitHub token (Bad credentials)', it means:\n";
echo "- The token format was recognized (it looks like a valid token)\n";
echo "- GitHub rejected the token (wrong permissions or expired)\n\n";

echo "Solutions to try:\n";
echo "1. Generate a new token at https://github.com/settings/tokens\n";
echo "2. Use a 'classic' token with 'repo' scope checked\n";
echo "3. Make sure you copy the entire token without any spaces\n";
echo "4. Save the token immediately after generating (GitHub only shows it once)\n";

echo "\nTest complete!\n";