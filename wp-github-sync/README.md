# WordPress GitHub Sync

WordPress GitHub Sync is a plugin that enables full control of your WordPress site's code through GitHub, providing version control and deployment features directly within the WordPress admin.

## Description

This plugin bridges the gap between GitHub and WordPress, allowing you to manage your site's code through a GitHub repository while providing a user-friendly interface that doesn't require Git knowledge.

### Key Features

- **GitHub Integration**: Connect your WordPress site to any GitHub repository.
- **Code Sync**: Automatically or manually sync your site's themes and plugins with your GitHub repository.
- **Branch Switching**: Easily switch between different branches to test new features.
- **One-Click Rollback**: Revert to any previous commit if something goes wrong.
- **Webhook Support**: Set up webhooks to automatically deploy changes when code is pushed to GitHub.
- **Non-Developer Friendly**: Uses plain language and a clear UI instead of Git terminology.
- **Shared Hosting Compatible**: Works on any hosting that can run WordPress, no shell access or Git required.

## Installation

1. Upload the `wp-github-sync` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to the 'GitHub Sync' menu and configure your GitHub repository settings

## Configuration

### Repository Settings

1. Enter your GitHub repository URL (e.g., `https://github.com/username/repository`)
2. Select which branch to use as your primary source

### Authentication

The plugin supports two authentication methods:

#### Personal Access Token (PAT)

1. Go to your GitHub account settings
2. Navigate to Developer Settings > Personal Access Tokens > Tokens (classic)
3. Generate a new token with "repo" permissions
4. Copy the token and paste it in the plugin settings

#### OAuth Authentication

1. Click the "Connect to GitHub" button
2. Authorize the application in the GitHub window that opens
3. You'll be redirected back to your WordPress site

### Deployment Settings

Configure how updates are handled:

- **Auto Sync**: Automatically check for new commits at your specified interval
- **Auto Deploy**: Choose whether new commits should be automatically deployed or require manual approval
- **Email Notifications**: Get notified when new updates are available
- **Webhook Deployment**: Configure your GitHub repository to trigger immediate deployments when code is pushed

## Usage

### Deploying Updates

When new commits are available, you can deploy them from the GitHub Sync dashboard:

1. Go to the GitHub Sync dashboard
2. If updates are available, you'll see a "Deploy Latest Changes" button
3. Click the button to apply the changes to your site

### Switching Branches

To switch to a different branch:

1. Go to the GitHub Sync dashboard
2. Select the desired branch from the dropdown in the "Switch Branch" card
3. Click "Switch Branch"

### Rolling Back Changes

If you need to revert to an earlier version:

1. Go to the GitHub Sync dashboard or Deployment History page
2. Find the commit you want to revert to
3. Click "Roll Back" next to that commit

### Setting Up Webhooks

For automatic deployments when code is pushed to GitHub:

1. Go to your GitHub repository settings
2. Navigate to Webhooks > Add webhook
3. Set the Payload URL to the webhook URL displayed in your GitHub Sync settings
4. Set Content type to `application/json`
5. Enter the Secret shown in your GitHub Sync settings
6. Choose which events should trigger the webhook (Push events are recommended)
7. Save the webhook

## Advanced Configuration

### Encryption Key

For enhanced security, you can define a custom encryption key in your `wp-config.php` file:

```php
define('WP_GITHUB_SYNC_ENCRYPTION_KEY', 'your-secure-random-key');
```

### OAuth Client

If you want to use OAuth authentication, you need to register an OAuth application with GitHub and define the credentials in your `wp-config.php` file:

```php
define('WP_GITHUB_SYNC_OAUTH_CLIENT_ID', 'your-client-id');
define('WP_GITHUB_SYNC_OAUTH_CLIENT_SECRET', 'your-client-secret');
```

## Frequently Asked Questions

### Do I need Git installed on my server?

No. The plugin works without Git installed on your server. It uses the GitHub API to handle all Git operations.

### Can I use this with private repositories?

Yes, as long as your Personal Access Token or OAuth application has access to the private repository.

### Does this plugin modify my database?

No. This plugin only syncs files between your WordPress site and GitHub. It doesn't modify your database content.

### What happens if a deployment fails?

If a deployment fails and you have backups enabled (on by default), the plugin will automatically restore your site to the previous working state.

## Hooks and Filters

The plugin provides several hooks and filters for developers to extend its functionality:

### Actions

- `wp_github_sync_before_deploy`: Runs before deploying a new version
- `wp_github_sync_after_deploy`: Runs after deployment with a success/failure flag

### Filters

- `wp_github_sync_ignore_paths`: Modify which paths to ignore during sync
- `wp_github_sync_commit_message`: Customize how commit messages are displayed in the UI

## License

This WordPress Plugin is licensed under the GPL v2 or later.

A copy of the license is included in the root of the plugin's directory. The file is named LICENSE.