# WordPress GitHub Sync

A modern WordPress plugin that synchronizes your WordPress site content with a GitHub repository.

## Features

- Connect your WordPress site to a GitHub repository
- Automatically sync changes between WordPress and GitHub
- Create and restore backups of your WordPress site
- View detailed sync and deployment history
- Roll back to previous commits with one click
- Modern, intuitive user interface
- Comprehensive error handling and logging

## Requirements

- WordPress 5.6 or higher
- PHP 7.2 or higher
- GitHub repository with appropriate permissions
- GitHub Personal Access Token or OAuth Token

## Installation

1. Download the plugin from the WordPress plugin repository or upload it manually.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to GitHub Sync > Settings to configure your GitHub repository and authentication.

## Configuration

1. Go to GitHub Sync > Settings.
2. Enter your GitHub repository URL (e.g., https://github.com/username/repository).
3. Choose your authentication method (Personal Access Token or OAuth Token).
4. Enter your access token.
5. Click "Test Connection" to verify your settings.
6. Configure additional settings as needed.

## Usage

### Dashboard

The main dashboard provides an overview of your GitHub connection status, recent commits, and quick actions.

### Syncing

To sync your WordPress site with GitHub:

1. Go to GitHub Sync > Dashboard.
2. Click the "Sync Now" button.

### Backups

To create a backup of your WordPress site:

1. Go to GitHub Sync > Backups.
2. Click the "Create Backup" button.

To restore a backup:

1. Go to GitHub Sync > Backups.
2. Find the backup you want to restore.
3. Click the "Restore" button.

### History

To view your sync and deployment history:

1. Go to GitHub Sync > History.
2. Use the tabs to switch between sync history and deployment history.

To roll back to a previous commit:

1. Go to GitHub Sync > History.
2. Find the commit you want to roll back to.
3. Click the "Rollback to this commit" button.

## Hooks and Filters

The plugin provides various hooks and filters to customize its behavior:

### Actions

- `wp_github_sync_before_sync`: Fires before a sync operation starts.
- `wp_github_sync_after_sync`: Fires after a sync operation completes.
- `wp_github_sync_before_deploy`: Fires before a deploy operation starts.
- `wp_github_sync_after_deploy`: Fires after a deploy operation completes.
- `wp_github_sync_before_backup`: Fires before a backup is created.
- `wp_github_sync_after_backup`: Fires after a backup is created.
- `wp_github_sync_before_restore`: Fires before a backup is restored.
- `wp_github_sync_after_restore`: Fires after a backup is restored.

### Filters

- `wp_github_sync_backup_paths`: Filters the paths to include in a backup.
- `wp_github_sync_api_request_args`: Filters the arguments for API requests.
- `wp_github_sync_api_response`: Filters the response from API requests.
- `wp_github_sync_sync_interval`: Filters the automatic sync interval.

## Frequently Asked Questions

### How do I set up a GitHub webhook?

1. Go to GitHub Sync > Settings > Advanced.
2. Copy the Webhook URL.
3. Go to your GitHub repository settings.
4. Click on "Webhooks" and then "Add webhook".
5. Paste the Webhook URL and set the content type to "application/json".
6. Enter the webhook secret if you provided one in the plugin settings.
7. Choose which events should trigger the webhook (usually "Push").
8. Click "Add webhook".

### Does this plugin support private repositories?

Yes, the plugin supports private repositories. You will need to provide a GitHub Personal Access Token or OAuth Token with appropriate permissions.

### Can I sync only specific content?

Yes, you can configure which directories to sync in the plugin settings. By default, the plugin syncs themes and plugins.

## Changelog

### 2.0.0
- Complete rewrite with modern architecture
- New user interface with clear status indicators
- Enhanced backup and restore functionality
- Improved error handling and logging
- Added rollback capability
- Added webhook support
- Added debug mode

## Credits

Developed by [Your Name](https://your-website.com)

## License

This plugin is licensed under the [GPL v2 or later](https://www.gnu.org/licenses/gpl-2.0.html).