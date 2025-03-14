<?php
/**
 * GitHub Token and Repository Test Script
 * 
 * Tests a provided GitHub token with a specific repository to verify:
 * 1. Token validity
 * 2. Repository access permissions
 * 3. Branch details
 * 4. Ability to create files in repo
 */

// Token to test - replace with your token
$token = isset($argv[1]) ? $argv[1] : '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';
$repo_url = isset($argv[2]) ? $argv[2] : 'https://github.com/CK-Daniel/kuper';

echo "===== GitHub Token and Repository Test =====\n\n";
echo "Testing with:\n";
echo "- Token: " . substr($token, 0, 4) . "..." . substr($token, -4) . " (length: " . strlen($token) . ")\n";
echo "- Repo: $repo_url\n\n";

// Parse GitHub repository URL
if (preg_match('~github\.com/([^/]+)/([^/]+)~', $repo_url, $matches)) {
    $owner = $matches[1];
    $repo = $matches[2];
    echo "Parsed repository owner: $owner\n";
    echo "Parsed repository name: $repo\n\n";
} else {
    echo "Error: Invalid GitHub repository URL format\n";
    exit(1);
}

// 1. Test basic authentication
echo "STEP 1: Testing authentication...\n";
$ch = curl_init('https://api.github.com/user');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/vnd.github+json',
    'Authorization: Bearer ' . $token,
    'User-Agent: PHP-GitHub-Test',
    'X-GitHub-Api-Version: 2022-11-28'
]);

$response = curl_exec($ch);
$status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($response, true);

if ($status_code === 200) {
    echo "Authentication successful! ✓\n";
    echo "Authenticated as: {$data['login']}\n";
} else {
    echo "Authentication failed (HTTP $status_code) ✗\n";
    if (isset($data['message'])) {
        echo "Error message: {$data['message']}\n";
    }
    echo "Please check your token and try again.\n";
    exit(1);
}

// 2. Test repository access
echo "\nSTEP 2: Testing repository access...\n";
$ch = curl_init("https://api.github.com/repos/$owner/$repo");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/vnd.github+json',
    'Authorization: Bearer ' . $token,
    'User-Agent: PHP-GitHub-Test',
    'X-GitHub-Api-Version: 2022-11-28'
]);

$response = curl_exec($ch);
$status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$repo_data = json_decode($response, true);

if ($status_code === 200) {
    echo "Repository access successful! ✓\n";
    echo "Repository full name: {$repo_data['full_name']}\n";
    echo "Default branch: {$repo_data['default_branch']}\n";
    $default_branch = $repo_data['default_branch'];
} else {
    echo "Repository access failed (HTTP $status_code) ✗\n";
    if (isset($repo_data['message'])) {
        echo "Error message: {$repo_data['message']}\n";
    }
    
    if ($status_code === 404) {
        echo "Repository not found. Check that the repository exists and the token has access to it.\n";
    }
    exit(1);
}

// 3. Test branch access
echo "\nSTEP 3: Testing branch access ($default_branch)...\n";
$ch = curl_init("https://api.github.com/repos/$owner/$repo/branches/$default_branch");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/vnd.github+json',
    'Authorization: Bearer ' . $token,
    'User-Agent: PHP-GitHub-Test',
    'X-GitHub-Api-Version: 2022-11-28'
]);

$response = curl_exec($ch);
$status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$branch_data = json_decode($response, true);

if ($status_code === 200) {
    echo "Branch access successful! ✓\n";
    echo "Latest commit SHA: {$branch_data['commit']['sha']}\n";
} else {
    if ($status_code === 404) {
        echo "Branch not found (HTTP 404). The repo might be empty or the branch doesn't exist. ✓\n";
        echo "This is okay - the plugin should auto-initialize the repo.\n";
    } else {
        echo "Branch access failed (HTTP $status_code) ✗\n";
        if (isset($branch_data['message'])) {
            echo "Error message: {$branch_data['message']}\n";
        }
    }
}

// 4. Test ability to create a test file
echo "\nSTEP 4: Testing file creation capability...\n";
$test_content = "# Test File\n\nThis is a test file created at " . date('Y-m-d H:i:s') . "\n";
$encoded_content = base64_encode($test_content);
$commit_message = "Test commit - verifying token permissions";
$test_file_path = "test_file_" . time() . ".md";

$post_data = json_encode([
    'message' => $commit_message,
    'content' => $encoded_content,
    'branch' => $default_branch
]);

$ch = curl_init("https://api.github.com/repos/$owner/$repo/contents/$test_file_path");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/vnd.github+json',
    'Authorization: Bearer ' . $token,
    'User-Agent: PHP-GitHub-Test',
    'Content-Type: application/json',
    'X-GitHub-Api-Version: 2022-11-28'
]);

$response = curl_exec($ch);
$status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$file_data = json_decode($response, true);

if ($status_code === 201) {
    echo "File creation successful! ✓\n";
    echo "Created file: $test_file_path\n";
    echo "Commit SHA: {$file_data['commit']['sha']}\n";
} else {
    echo "File creation failed (HTTP $status_code) ✗\n";
    if (isset($file_data['message'])) {
        echo "Error message: {$file_data['message']}\n";
    }
    
    // Check for empty repository error - this is okay for our test
    if (strpos($response, 'Git Repository is empty') !== false) {
        echo "Repository is empty. This is okay - the plugin should auto-initialize it. ✓\n";
    } else {
        echo "Make sure the token has write permissions for this repository.\n";
    }
}

// 5. Test repository check for empty repository
echo "\nSTEP 5: Testing for empty repository condition...\n";
$ch = curl_init("https://api.github.com/repos/$owner/$repo/git/refs/heads");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/vnd.github+json',
    'Authorization: Bearer ' . $token,
    'User-Agent: PHP-GitHub-Test',
    'X-GitHub-Api-Version: 2022-11-28'
]);

$response = curl_exec($ch);
$status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($status_code === 404 || empty($response) || $response === '[]') {
    echo "Repository appears to be empty. ✓\n";
    echo "This is okay - the plugin should auto-initialize the repo.\n";
} else {
    echo "Repository contains commits. ✓\n";
    echo "The plugin should use the existing content.\n";
}

echo "\n===== TEST COMPLETE =====\n";
echo "Your token appears to be " . (($status_code === 200 || $status_code === 201) ? "VALID" : "INVALID") . " for use with WP-GitHub-Sync plugin!\n";