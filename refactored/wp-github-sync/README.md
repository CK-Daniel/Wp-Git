# WordPress GitHub Sync

A WordPress plugin that seamlessly syncs your WordPress site with a GitHub repository.

## Features

- Sync WordPress themes, plugins, and configuration files with GitHub
- Automatic or manual deployments from GitHub to WordPress
- Webhook support for automatic deployments when changes are pushed to GitHub
- Branch switching and commit rollback capabilities
- Backup and restore functionality to safely manage deployments
- Detailed deployment history

## Installation

1. Download the plugin ZIP file
2. Install via WordPress admin panel (Plugins > Add New > Upload Plugin)
3. Activate the plugin
4. Configure the plugin settings (GitHub repository URL and authentication)

## Directory Structure

The plugin follows a modular structure for better organization and maintainability:

```
wp-github-sync/
├── admin/
│   ├── assets/
│   │   ├── css/
│   │   │   └── admin.css
│   │   └── js/
│   │       └── admin.js
│   └── templates/
│       ├── dashboard-page.php
│       ├── history-page.php
│       └── settings-page.php
├── includes/
│   ├── autoload.php
│   └── utils/
│       └── helper-functions.php
├── src/
│   ├── Admin/
│   │   └── Admin.php
│   ├── API/
│   │   ├── API_Client.php
│   │   ├── Repository.php
│   │   └── Repository_Uploader.php
│   ├── Core/
│   │   ├── I18n.php
│   │   └── Loader.php
│   ├── Settings/
│   │   └── Settings.php
│   ├── Sync/
│   │   ├── Backup_Manager.php
│   │   ├── File_Sync.php
│   │   └── Sync_Manager.php
│   └── WP_GitHub_Sync.php
└── wp-github-sync.php
```

## Configuration

1. Go to WordPress admin > GitHub Sync > Settings
2. Enter your GitHub repository URL (e.g., https://github.com/username/repository)
3. Choose an authentication method and enter your GitHub token
4. Select the branch to sync with (defaults to "main")
5. Configure sync and deployment options

### GitHub Token Setup

For the plugin to communicate with GitHub, you need to create a Personal Access Token (PAT):

1. Go to GitHub > Settings > Developer settings > Personal access tokens
2. Generate a new token with the "repo" scope
3. Enter this token in the plugin settings

### Webhook Setup

To enable automatic deployments when pushing to GitHub:

1. Go to your GitHub repository > Settings > Webhooks > Add webhook
2. Set the Payload URL to your webhook URL (shown in plugin settings)
3. Set Content type to "application/json"
4. Set the Secret to the webhook secret (shown in plugin settings)
5. Select "Just the push event"
6. Enable the webhook

## Usage

### Manual Deployment

1. Go to WordPress admin > GitHub Sync
2. Click "Check for Updates" to check for new commits
3. When an update is available, click "Deploy Now"

### Automatic Deployment

1. Enable auto-sync in the plugin settings
2. Set the auto-sync interval (e.g., every 5 minutes)
3. Optionally enable auto-deploy to automatically deploy new commits

### Branch Switching

1. Go to WordPress admin > GitHub Sync
2. Enter the branch name in the "Switch Branch" field
3. Click "Switch Branch" to deploy the latest commit from that branch

### Rollback

1. Go to WordPress admin > GitHub Sync > Deployment History
2. Find the commit you want to roll back to
3. Click "Rollback" next to that commit

## Development

### File Structure Explanation

- `admin/`: Admin UI files
- `includes/`: Plugin includes and helper functions
- `src/`: Main plugin classes, organized by functionality
- `wp-github-sync.php`: Main plugin file and entry point

### Class Overview

- `WP_GitHub_Sync`: Main plugin class
- `API_Client`: GitHub API communication
- `Repository`: Repository operations
- `Repository_Uploader`: File upload to GitHub
- `Sync_Manager`: Manages synchronization between WordPress and GitHub
- `File_Sync`: Handles file synchronization
- `Backup_Manager`: Creates and restores backups
- `Settings`: Plugin settings management
- `Admin`: Admin UI functionality
- `Loader`: Hooks and filters registration

## License

GPL v2 or later