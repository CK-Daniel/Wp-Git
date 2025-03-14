<?php
/**
 * GitHub Fine-Grained Token Checker
 * This script tests if a fine-grained token has the necessary permissions for WP-GitHub-Sync
 */

// Check if token was provided as a command line argument
$token = isset($argv[1]) ? $argv[1] : '';
$repo_url = isset($argv[2]) ? $argv[2] : 'https://github.com/CK-Daniel/kuper';

// Validate token input
if (empty($token)) {
    echo "ERROR: No GitHub token provided. Please provide a token as the first command line argument.\n";
    echo "Usage: php fine_grained_token_check.php YOUR_GITHUB_TOKEN [REPOSITORY_URL]\n";
    exit(1);
}

echo "===== GitHub Fine-Grained Token Checker =====\n\n";

// Verify token format
if (strpos($token, 'github_pat_') === 0) {
    echo "✅ Token identified as a fine-grained personal access token (github_pat_)\n";
} else if (strpos($token, 'ghp_') === 0) {
    echo "ℹ️ Token identified as a classic personal access token (ghp_)\n";
    echo "This script is designed for checking fine-grained tokens, but will run checks anyway.\n";
} else if (strlen($token) === 40 && ctype_xdigit($token)) {
    echo "ℹ️ Token identified as a classic personal access token (40-char hex)\n";
    echo "This script is designed for checking fine-grained tokens, but will run checks anyway.\n";
} else {
    echo "⚠️ Token format not recognized. This script expects a fine-grained token starting with 'github_pat_'\n";
    echo "Will continue with tests but results may not be reliable.\n";
}

echo "\nToken: " . substr($token, 0, 4) . "..." . substr($token, -4) . " (length: " . strlen($token) . ")\n";
echo "Repository: $repo_url\n\n";

// Parse repository URL
if (preg_match('~github\.com/([^/]+)/([^/]+)~', $repo_url, $matches)) {
    $owner = $matches[1];
    $repo = $matches[2];
    echo "Parsed repository owner: $owner\n";
    echo "Parsed repository name: $repo\n\n";
} else {
    echo "Error: Invalid GitHub repository URL format\n";
    exit(1);
}

// Function to make GitHub API requests
function github_request($url, $method = 'GET', $data = null) {
    global $token;
    $ch = curl_init($url);
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    
    $headers = [
        'Accept: application/vnd.github+json',
        'Authorization: Bearer ' . $token,
        'User-Agent: PHP-GitHub-Test',
        'X-GitHub-Api-Version: 2022-11-28'
    ];
    
    if ($data && ($method === 'POST' || $method === 'PUT' || $method === 'PATCH')) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    $response = curl_exec($ch);
    $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    curl_close($ch);
    
    return [
        'status' => $status_code,
        'body' => json_decode($response, true),
        'error' => $error
    ];
}

// Test authentication
echo "TEST 1: Basic Authentication\n";
echo "---------------------------------------------\n";
$auth_response = github_request('https://api.github.com/user');

if ($auth_response['status'] == 200 && isset($auth_response['body']['login'])) {
    echo "✅ Authentication successful\n";
    echo "Authenticated as: " . $auth_response['body']['login'] . "\n";
} else {
    echo "❌ Authentication failed (HTTP " . $auth_response['status'] . ")\n";
    if (isset($auth_response['body']['message'])) {
        echo "Error message: " . $auth_response['body']['message'] . "\n";
    }
    echo "Your token might not be valid. Please check it and try again.\n";
    exit(1);
}

// Test repository access
echo "\nTEST 2: Repository Access\n";
echo "---------------------------------------------\n";
$repo_response = github_request("https://api.github.com/repos/$owner/$repo");

if ($repo_response['status'] == 200) {
    echo "✅ Repository access successful\n";
    echo "Repository full name: " . $repo_response['body']['full_name'] . "\n";
    $default_branch = $repo_response['body']['default_branch'];
    echo "Default branch: $default_branch\n";
} else {
    echo "❌ Repository access failed (HTTP " . $repo_response['status'] . ")\n";
    if (isset($repo_response['body']['message'])) {
        echo "Error message: " . $repo_response['body']['message'] . "\n";
    }
    echo "Your token may not have access to this repository. For fine-grained tokens, make sure you've explicitly granted access to this repository.\n";
    
    // If 404, check if the repository exists or the token lacks access
    if ($repo_response['status'] == 404) {
        echo "Either the repository doesn't exist, or your token doesn't have access to it.\n";
    }
    // Continue anyway to check token permissions
}

// Test blob creation ability (critical for initial sync)
echo "\nTEST 3: Blob Creation Test (Critical for Initial Sync)\n";
echo "---------------------------------------------\n";
$test_content = "Test content created at " . date('Y-m-d H:i:s');
$blob_data = [
    'content' => base64_encode($test_content),
    'encoding' => 'base64'
];

$blob_response = github_request("https://api.github.com/repos/$owner/$repo/git/blobs", 'POST', $blob_data);

if ($blob_response['status'] == 201 && isset($blob_response['body']['sha'])) {
    echo "✅ Blob creation successful\n";
    echo "This means your token has write access to repository content.\n";
    echo "Blob SHA: " . $blob_response['body']['sha'] . "\n";
} else {
    echo "❌ Blob creation failed (HTTP " . $blob_response['status'] . ")\n";
    if (isset($blob_response['body']['message'])) {
        echo "Error message: " . $blob_response['body']['message'] . "\n";
    }
    
    // Check for empty repository
    if (strpos(json_encode($blob_response['body']), 'Git Repository is empty') !== false) {
        echo "ℹ️ Repository appears to be empty. This is normal for new repositories.\n";
        echo "⚠️ However, your token still failed to create content. This could be a permission issue.\n";
    }
    
    echo "\n⚠️ IMPORTANT: Your fine-grained token is missing critical permissions!\n";
    echo "For WP-GitHub-Sync to work, your token needs:\n";
    echo "- Contents permission: READ AND WRITE\n";
    echo "Please update your token permissions in GitHub and try again.\n";
}

// Display permissions for fine-grained tokens
if (strpos($token, 'github_pat_') === 0) {
    echo "\nRequired Permission Summary for Fine-Grained Tokens:\n";
    echo "---------------------------------------------\n";
    echo "✅ Repository permissions > Contents: Read and write\n";
    echo "   This allows reading and writing files, creating initial blobs, and handling sync operations\n";
    echo "\nOptional but recommended permissions:\n";
    echo "- Repository permissions > Metadata: Read-only\n";
    echo "- Repository permissions > Pull requests: Read and write (if you want to create PRs)\n";
}

echo "\n===== TEST COMPLETE =====\n";
echo "If you're using a fine-grained token and seeing errors, please review the token permissions in GitHub settings.\n";
echo "Go to https://github.com/settings/tokens, edit your token, and ensure it has the permissions listed above.\n";