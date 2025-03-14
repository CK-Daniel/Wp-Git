# GitHub Connection Test Troubleshooting

This document provides information about the GitHub connection test and how to resolve authentication issues.

## Error: "Authentication failed: Invalid GitHub token (Bad credentials)"

If you're seeing this error when testing your GitHub connection, it means:

1. The token format is recognized (it looks like a valid GitHub token)
2. GitHub rejected the token (due to invalid credentials, insufficient permissions, or token expiration)

## Changes Made to Fix the Issue

We've made the following improvements to the connection test:

1. **Token Format Validation**: Better validation of GitHub token formats (github_pat_*, ghp_*, gho_*, ghs_*, and 40-char hex)
2. **Authorization Format Flexibility**: Now tries both 'Bearer' and 'token' authorization formats
3. **Enhanced Error Messages**: More descriptive error messages to help troubleshoot authentication issues
4. **Detailed Debug Logging**: Additional logging to help diagnose connection problems

## How to Fix the Issue

If you're still experiencing the "Bad credentials" error, try these steps:

1. **Create a new token**: 
   - Go to [GitHub Personal Access Tokens](https://github.com/settings/tokens)
   - Create a new "classic" token (with repo scope)
   - Immediately copy the token when it's shown

2. **Check token permissions**:
   - Make sure your token has the "repo" scope
   - For fine-grained PATs, make sure they have required permissions

3. **Test your token**:
   - Run the `github_connection_diagnosis.php` script from the test directory
   - Enter your token when prompted
   - This will test both authorization formats and provide detailed feedback

4. **Common Issues**:
   - Token may be expired
   - Token might be missing necessary permissions
   - Token might have been entered incorrectly (spaces, missing characters)
   - Network/firewall issues preventing GitHub API access

## GitHub Token Formats

GitHub supports several token formats:
- **Fine-grained PAT**: Starts with `github_pat_`
- **Classic PAT (new)**: Starts with `ghp_`
- **Classic PAT (old)**: 40-character hexadecimal string
- **OAuth token**: Starts with `gho_`
- **GitHub App token**: Starts with `ghs_`

For most WordPress sites, a classic PAT with repo scope works best.

## Testing Tools

We've created these diagnostic tools to help troubleshoot:

1. `github_connection_diagnosis.php`: Tests GitHub API connectivity with both authorization formats
2. `simple_token_test.php`: Basic token format validation
3. `test_connection.php`: Tests token validation in a WordPress-like environment

Run these tools from the command line:
```
cd /path/to/wp-github-sync/test
php -f github_connection_diagnosis.php
```

## Still Having Issues?

If you're still experiencing connection problems after following these steps:

1. Check your server's ability to connect to external APIs
2. Verify PHP's curl extension is working properly
3. If you're using a proxy or VPN, make sure it allows GitHub API access
4. Try a different GitHub account or token format