# WordPress GitHub Sync Test Suite

This directory contains standalone test scripts that can be used to test the functionality of the WordPress GitHub Sync plugin outside of a WordPress environment.

## Available Tests

1. **Initial Sync Test** (`test_initial_sync.php`) - Tests the initial sync functionality that creates a new GitHub repository with WordPress content.

2. **Download Repository Test** (`test_download_repository.php`) - Tests downloading a GitHub repository and extracting it to a local directory.

3. **Repository Compare Test** (`test_repository_compare.php`) - Tests comparing different branches or commits in the GitHub repository.

4. **Rollback Test** (`test_rollback.php`) - Tests the rollback functionality to revert to a previous commit.

## Running Tests

Each test script can be run directly using PHP from the command line:

```bash
php test_initial_sync.php
php test_download_repository.php
php test_repository_compare.php
php test_rollback.php
```

Alternatively, you can run all tests at once using the test runner:

```bash
php run_all_tests.php
```

This will execute all tests in sequence and provide a summary of the results.

## Test Configuration

The tests use a GitHub personal access token and repository URL that should be updated in each test file. Look for these lines in each test file:

```php
// Replace with your token and repo
$token = 'ghp_your_token_here'; 
$repo_url = 'https://github.com/username/repo';
$branch = 'main';
```

## Test Results

Each test will output detailed information about the operations being performed and their success or failure. This information is useful for debugging any issues with the plugin.

## Mocked WordPress Functions

These test scripts mock the necessary WordPress functions and classes needed by the plugin. This allows testing the core functionality without requiring a full WordPress installation.

## Important Notes

- These tests perform real operations on your GitHub repositories. Be careful when using them with production repositories.
- The token used should have appropriate permissions for the operations being tested.
- Some tests may create temporary files or directories that should be cleaned up after testing.