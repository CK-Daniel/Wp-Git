<?php
/**
 * GitHub Connection Diagnostic Tool
 * 
 * This standalone script tests GitHub API connections 
 * using both Bearer and token authorization formats
 */

echo "===== GitHub Connection Diagnostic Tool =====\n\n";

// Function to test a token with both authorization formats
function test_token_with_both_formats($token) {
    if (empty($token)) {
        echo "Error: Token is empty\n";
        return false;
    }
    
    echo "Testing token: " . substr($token, 0, 4) . "..." . substr($token, -4) . " (Length: " . strlen($token) . ")\n";
    
    // Identify token format
    if (strpos($token, 'github_pat_') === 0) {
        echo "Token format: Fine-grained PAT (github_pat_*)\n";
    } else if (strpos($token, 'ghp_') === 0) {
        echo "Token format: Classic PAT (ghp_*)\n";
    } else if (strpos($token, 'gho_') === 0) {
        echo "Token format: OAuth token (gho_*)\n";
    } else if (strpos($token, 'ghs_') === 0) {
        echo "Token format: GitHub App token (ghs_*)\n";
    } else if (strlen($token) === 40 && ctype_xdigit($token)) {
        echo "Token format: Classic PAT (40-char hex)\n";
    } else {
        echo "Warning: Token format not recognized\n";
    }
    
    // Test with Bearer format
    echo "\nTesting with 'Bearer' format...\n";
    $bearer_result = test_github_api($token, 'Bearer');
    
    // Test with token format
    echo "\nTesting with 'token' format...\n";
    $token_result = test_github_api($token, 'token');
    
    // Summary
    echo "\nSummary:\n";
    echo "Bearer format: " . ($bearer_result === true ? "SUCCESSFUL" : "FAILED") . "\n";
    echo "token format:  " . ($token_result === true ? "SUCCESSFUL" : "FAILED") . "\n";
    
    if ($bearer_result === true || $token_result === true) {
        echo "\nAt least one authentication method succeeded!\n";
        
        if ($bearer_result === true && $token_result !== true) {
            echo "-> Use 'Bearer' format for this token\n";
        } else if ($token_result === true && $bearer_result !== true) {
            echo "-> Use 'token' format for this token\n";
        } else {
            echo "-> Both authentication formats work for this token\n";
        }
        
        return true;
    } else {
        echo "\nBoth authentication methods failed.\n";
        echo "This suggests the token is invalid, expired, or lacks required permissions.\n";
        return false;
    }
}

// Function to test GitHub API with specific authorization format
function test_github_api($token, $auth_format) {
    // Setup the request
    $curl = curl_init("https://api.github.com/user");
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    
    // Set the authorization header
    $auth_header = $auth_format . ' ' . $token;
    
    curl_setopt($curl, CURLOPT_HTTPHEADER, [
        "Accept: application/vnd.github+json",
        "Authorization: " . $auth_header,
        "User-Agent: GitHub-Token-Test/1.0",
        "X-GitHub-Api-Version: 2022-11-28" 
    ]);
    
    curl_setopt($curl, CURLOPT_TIMEOUT, 10);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
    
    // Make the request
    $response = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    
    curl_close($curl);
    
    // Check for curl errors
    if ($error) {
        echo "Error: cURL error - " . $error . "\n";
        return false;
    }
    
    echo "HTTP Status: " . $status . "\n";
    
    // Parse the response
    $data = json_decode($response, true);
    
    // Check the response
    if ($status === 200) {
        echo "Success! Authenticated as: " . $data['login'] . "\n";
        
        // Show more information about the user
        if (isset($data['name']) && !empty($data['name'])) {
            echo "User name: " . $data['name'] . "\n";
        }
        
        // Show plan information
        if (isset($data['plan']) && isset($data['plan']['name'])) {
            echo "GitHub plan: " . $data['plan']['name'] . "\n";
        }
        
        return true;
    } else {
        if (isset($data['message'])) {
            echo "Error: " . $data['message'] . "\n";
            
            if ($data['message'] === 'Bad credentials') {
                echo "GitHub rejected the token with this auth format.\n";
            } else if (strpos($data['message'], 'rate limit') !== false) {
                echo "You've hit a rate limit. Wait a while before trying again.\n";
            } else if ($data['message'] === 'Requires authentication') {
                echo "Authentication is required. The token might be empty or malformed.\n";
            }
        } else {
            echo "Unknown error\n";
        }
        
        return false;
    }
}

// Test GitHub API connectivity
echo "Testing basic GitHub API connectivity...\n";
$curl = curl_init("https://api.github.com/zen");
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
curl_setopt($curl, CURLOPT_HTTPHEADER, ["User-Agent: GitHub-Connection-Test/1.0"]);
curl_setopt($curl, CURLOPT_TIMEOUT, 10);

$response = curl_exec($curl);
$status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
$error = curl_error($curl);

curl_close($curl);

if ($error) {
    echo "Error: Could not connect to GitHub API: " . $error . "\n\n";
} else {
    echo "Success! GitHub API is reachable. Status: " . $status . "\n";
    echo "Response: " . $response . "\n\n";
}

// Prompt user for token
echo "Please enter your GitHub token: ";
$handle = fopen("php://stdin", "r");
$token = trim(fgets($handle));

if (!empty($token)) {
    echo "\n";
    test_token_with_both_formats($token);
} else {
    echo "No token provided. Exiting.\n";
}

fclose($handle);

echo "\n===== Troubleshooting Tips =====\n";
echo "If your token failed with both auth formats:\n";
echo "1. Make sure the token is still valid (not expired or revoked)\n";
echo "2. Create a new token with 'repo' scope at https://github.com/settings/tokens\n";
echo "3. Try using a classic PAT rather than a fine-grained PAT\n";
echo "4. Make sure you're copying the entire token without any spaces\n";
echo "5. Check your network/firewall settings if you're in a restricted environment\n\n";

echo "Common token formats:\n";
echo "- Fine-grained PAT: github_pat_*\n";
echo "- Classic PAT (new): ghp_*\n";
echo "- Classic PAT (old): 40-character hexadecimal string\n";
echo "- OAuth token: gho_*\n";
echo "- GitHub App token: ghs_*\n\n";

echo "Test complete!\n";