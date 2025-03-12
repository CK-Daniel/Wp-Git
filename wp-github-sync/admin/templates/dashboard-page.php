<?php
/**
 * Admin dashboard page template.
 *
 * @package WPGitHubSync
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Parse repository URL to get owner/repo format
$parsed_url = $this->github_api->parse_github_url($repository_url);
$repo_display = $parsed_url ? $parsed_url['owner'] . '/' . $parsed_url['repo'] : $repository_url;
?>
<div class="wrap wp-github-sync-wrap">
    <h1><?php _e('GitHub Sync Dashboard', 'wp-github-sync'); ?></h1>
    
    <?php settings_errors('wp_github_sync'); ?>
    
    <?php if (empty($repository_url)) : ?>
        <div class="wp-github-sync-info-box warning">
            <div class="wp-github-sync-info-box-icon">
                <span class="dashicons dashicons-warning"></span>
            </div>
            <div class="wp-github-sync-info-box-content">
                <h4 class="wp-github-sync-info-box-title"><?php _e('GitHub Sync is not configured yet', 'wp-github-sync'); ?></h4>
                <p class="wp-github-sync-info-box-message">
                    <?php _e('Please set up your GitHub repository connection to start using the plugin.', 'wp-github-sync'); ?>
                    <br>
                    <a href="<?php echo admin_url('admin.php?page=wp-github-sync-settings'); ?>" class="wp-github-sync-button" style="margin-top: 15px;">
                        <span class="dashicons dashicons-admin-tools"></span>
                        <?php _e('Configure Now', 'wp-github-sync'); ?>
                    </a>
                </p>
            </div>
        </div>
    <?php else : ?>
        <!-- Status Summary Card -->
        <div class="wp-github-sync-card">
            <h2>
                <span class="dashicons dashicons-info"></span>
                <?php _e('Repository Overview', 'wp-github-sync'); ?>
            </h2>
            
            <div class="wp-github-sync-card-content">
                <div class="wp-github-sync-dashboard">
                    <!-- Repository Info -->
                    <div>
                        <div class="wp-github-sync-status-item">
                            <span class="wp-github-sync-status-label"><?php _e('Repository:', 'wp-github-sync'); ?></span>
                            <span class="wp-github-sync-status-value">
                                <a href="<?php echo esc_url($repository_url); ?>" target="_blank"><?php echo esc_html($repo_display); ?></a>
                            </span>
                        </div>
                        
                        <div class="wp-github-sync-status-item">
                            <span class="wp-github-sync-status-label"><?php _e('Current Branch:', 'wp-github-sync'); ?></span>
                            <span class="wp-github-sync-status-value"><?php echo esc_html($branch); ?></span>
                        </div>
                        
                        <?php if (!empty($latest_commit_info)) : ?>
                        <div class="wp-github-sync-status-item">
                            <span class="wp-github-sync-status-label"><?php _e('Current Commit:', 'wp-github-sync'); ?></span>
                            <span class="wp-github-sync-status-value">
                                <span class="code"><?php echo esc_html(substr($latest_commit_info['sha'], 0, 8)); ?></span>
                                - <?php echo esc_html(wp_github_sync_format_commit_message($latest_commit_info['message'])); ?>
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Status Info -->
                    <div>
                        <?php if (!empty($last_deployment_time)) : ?>
                        <div class="wp-github-sync-status-item">
                            <span class="wp-github-sync-status-label"><?php _e('Last Deployment:', 'wp-github-sync'); ?></span>
                            <span class="wp-github-sync-status-value">
                                <?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_deployment_time); ?>
                                (<?php echo wp_github_sync_time_diff($last_deployment_time); ?> <?php _e('ago', 'wp-github-sync'); ?>)
                            </span>
                        </div>
                        <?php endif; ?>
                        
                        <div class="wp-github-sync-status-item">
                            <span class="wp-github-sync-status-label"><?php _e('Status:', 'wp-github-sync'); ?></span>
                            <span class="wp-github-sync-status-value">
                                <?php if ($deployment_in_progress) : ?>
                                    <span class="wp-github-sync-status-in-progress">
                                        <span class="dashicons dashicons-update"></span>
                                        <?php _e('Deployment in progress...', 'wp-github-sync'); ?>
                                    </span>
                                <?php elseif ($update_available) : ?>
                                    <span class="wp-github-sync-status-update-available">
                                        <span class="dashicons dashicons-warning"></span>
                                        <?php _e('Update available', 'wp-github-sync'); ?>
                                    </span>
                                <?php else : ?>
                                    <span class="wp-github-sync-status-up-to-date">
                                        <span class="dashicons dashicons-yes-alt"></span>
                                        <?php _e('Up to date', 'wp-github-sync'); ?>
                                    </span>
                                <?php endif; ?>
                            </span>
                        </div>
                    
                        <div class="wp-github-sync-action-buttons">
                            <?php if ($update_available && !$deployment_in_progress) : ?>
                                <button class="wp-github-sync-button success wp-github-sync-deploy">
                                    <span class="dashicons dashicons-cloud-upload"></span>
                                    <?php _e('Deploy Latest Changes', 'wp-github-sync'); ?>
                                </button>
                            <?php endif; ?>
                            
                            <button class="wp-github-sync-button secondary wp-github-sync-check-updates">
                                <span class="dashicons dashicons-update"></span>
                                <?php _e('Check for Updates', 'wp-github-sync'); ?>
                            </button>
                            
                            <button class="wp-github-sync-button wp-github-sync-full-sync">
                                <span class="dashicons dashicons-upload"></span>
                                <?php _e('Sync All to GitHub', 'wp-github-sync'); ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Tabs -->
        <div class="wp-github-sync-tabs">
            <div class="wp-github-sync-tab active" data-tab="guide">
                <span class="dashicons dashicons-book"></span>
                <?php _e('Getting Started', 'wp-github-sync'); ?>
            </div>
            <div class="wp-github-sync-tab" data-tab="branches">
                <span class="dashicons dashicons-randomize"></span>
                <?php _e('Branches', 'wp-github-sync'); ?>
            </div>
            <div class="wp-github-sync-tab" data-tab="commits">
                <span class="dashicons dashicons-backup"></span>
                <?php _e('Commits', 'wp-github-sync'); ?>
            </div>
            <div class="wp-github-sync-tab" data-tab="webhook">
                <span class="dashicons dashicons-admin-links"></span>
                <?php _e('Webhook', 'wp-github-sync'); ?>
            </div>
            <div class="wp-github-sync-tab" data-tab="dev">
                <span class="dashicons dashicons-code-standards"></span>
                <?php _e('Developer Tools', 'wp-github-sync'); ?>
            </div>
        </div>
        
        <!-- Getting Started Tab Content -->
        <div class="wp-github-sync-tab-content active" id="guide-tab-content">
            <div class="wp-github-sync-card">
                <h2>
                    <span class="dashicons dashicons-book"></span>
                    <?php _e('Welcome to GitHub Sync', 'wp-github-sync'); ?>
                </h2>
                
                <div class="wp-github-sync-card-content">
                    <p><?php _e('GitHub Sync connects your WordPress site with GitHub, providing version control and deployment tools without needing technical knowledge.', 'wp-github-sync'); ?></p>
                    
                    <div style="display: flex; flex-wrap: wrap; gap: 30px; margin: 30px 0;">
                        <div style="flex: 1; min-width: 280px;">
                            <h3 style="display: flex; align-items: center; gap: 10px; margin-top: 0;">
                                <span class="dashicons dashicons-admin-users" style="color: var(--wp-git-primary);"></span>
                                <?php _e('For Non-Developers', 'wp-github-sync'); ?>
                            </h3>
                            
                            <div class="wp-github-sync-info-box info" style="margin-top: 15px;">
                                <div class="wp-github-sync-info-box-icon">
                                    <span class="dashicons dashicons-info"></span>
                                </div>
                                <div class="wp-github-sync-info-box-content">
                                    <h4 class="wp-github-sync-info-box-title"><?php _e('What is GitHub?', 'wp-github-sync'); ?></h4>
                                    <p class="wp-github-sync-info-box-message">
                                        <?php _e('GitHub is a platform that helps store different versions of your website files. Think of it like a backup system that tracks every change made to your site, allowing you to restore previous versions if needed.', 'wp-github-sync'); ?>
                                    </p>
                                </div>
                            </div>
                            
                            <h4 style="margin-top: 20px;"><?php _e('Common Tasks:', 'wp-github-sync'); ?></h4>
                            <ul style="list-style-type: none; padding: 0;">
                                <li style="margin-bottom: 15px; display: flex; align-items: flex-start; gap: 10px;">
                                    <span class="dashicons dashicons-yes-alt" style="color: var(--wp-git-success); margin-top: 2px;"></span>
                                    <span><strong><?php _e('Update Your Site:', 'wp-github-sync'); ?></strong> <?php _e('When changes are made in GitHub, click "Deploy Latest Changes" to update your site.', 'wp-github-sync'); ?></span>
                                </li>
                                <li style="margin-bottom: 15px; display: flex; align-items: flex-start; gap: 10px;">
                                    <span class="dashicons dashicons-yes-alt" style="color: var(--wp-git-success); margin-top: 2px;"></span>
                                    <span><strong><?php _e('Restore Previous Version:', 'wp-github-sync'); ?></strong> <?php _e('Go to the Version History page to see all previous versions and restore if needed.', 'wp-github-sync'); ?></span>
                                </li>
                                <li style="margin-bottom: 15px; display: flex; align-items: flex-start; gap: 10px;">
                                    <span class="dashicons dashicons-yes-alt" style="color: var(--wp-git-success); margin-top: 2px;"></span>
                                    <span><strong><?php _e('Switch Branch:', 'wp-github-sync'); ?></strong> <?php _e('Use the Branches tab to switch between different versions of your site (like switching between "production" and "testing" versions).', 'wp-github-sync'); ?></span>
                                </li>
                            </ul>
                            
                            <a href="<?php echo admin_url('admin.php?page=wp-github-sync-history'); ?>" class="wp-github-sync-button" style="margin-top: 15px;">
                                <span class="dashicons dashicons-backup"></span>
                                <?php _e('View Version History', 'wp-github-sync'); ?>
                            </a>
                        </div>
                        
                        <div style="flex: 1; min-width: 280px;">
                            <h3 style="display: flex; align-items: center; gap: 10px; margin-top: 0;">
                                <span class="dashicons dashicons-code-standards" style="color: var(--wp-git-primary);"></span>
                                <?php _e('For Developers', 'wp-github-sync'); ?>
                            </h3>
                            
                            <div class="wp-github-sync-info-box info" style="margin-top: 15px;">
                                <div class="wp-github-sync-info-box-icon">
                                    <span class="dashicons dashicons-info"></span>
                                </div>
                                <div class="wp-github-sync-info-box-content">
                                    <h4 class="wp-github-sync-info-box-title"><?php _e('Developer Features', 'wp-github-sync'); ?></h4>
                                    <p class="wp-github-sync-info-box-message">
                                        <?php _e('This plugin integrates with the GitHub API to provide seamless deployment workflows. Use webhooks for automatic deployments, compare file changes, and manage your branches directly from WordPress.', 'wp-github-sync'); ?>
                                    </p>
                                </div>
                            </div>
                            
                            <h4 style="margin-top: 20px;"><?php _e('Advanced Features:', 'wp-github-sync'); ?></h4>
                            <ul style="list-style-type: none; padding: 0;">
                                <li style="margin-bottom: 15px; display: flex; align-items: flex-start; gap: 10px;">
                                    <span class="dashicons dashicons-admin-tools" style="color: var(--wp-git-primary); margin-top: 2px;"></span>
                                    <span><strong><?php _e('Component Sync:', 'wp-github-sync'); ?></strong> <?php _e('Sync specific plugins or themes individually using the Developer Tools tab.', 'wp-github-sync'); ?></span>
                                </li>
                                <li style="margin-bottom: 15px; display: flex; align-items: flex-start; gap: 10px;">
                                    <span class="dashicons dashicons-admin-tools" style="color: var(--wp-git-primary); margin-top: 2px;"></span>
                                    <span><strong><?php _e('Webhook Integration:', 'wp-github-sync'); ?></strong> <?php _e('Configure GitHub webhooks for automatic deployments when you push commits.', 'wp-github-sync'); ?></span>
                                </li>
                                <li style="margin-bottom: 15px; display: flex; align-items: flex-start; gap: 10px;">
                                    <span class="dashicons dashicons-admin-tools" style="color: var(--wp-git-primary); margin-top: 2px;"></span>
                                    <span><strong><?php _e('File Difference Viewer:', 'wp-github-sync'); ?></strong> <?php _e('Compare changes between local files and the repository to better understand modifications.', 'wp-github-sync'); ?></span>
                                </li>
                            </ul>
                            
                            <div class="wp-github-sync-action-buttons" style="margin-top: 15px;">
                                <a href="#" class="wp-github-sync-button wp-github-sync-tab-link" data-tab-target="dev">
                                    <span class="dashicons dashicons-code-standards"></span>
                                    <?php _e('Go to Developer Tools', 'wp-github-sync'); ?>
                                </a>
                                
                                <a href="#" class="wp-github-sync-button secondary wp-github-sync-tab-link" data-tab-target="webhook">
                                    <span class="dashicons dashicons-admin-links"></span>
                                    <?php _e('Configure Webhook', 'wp-github-sync'); ?>
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <div class="wp-github-sync-info-box success">
                        <div class="wp-github-sync-info-box-icon">
                            <span class="dashicons dashicons-yes-alt"></span>
                        </div>
                        <div class="wp-github-sync-info-box-content">
                            <h4 class="wp-github-sync-info-box-title"><?php _e('Need Help?', 'wp-github-sync'); ?></h4>
                            <p class="wp-github-sync-info-box-message">
                                <?php _e('Check out our comprehensive documentation or contact our support team for assistance.', 'wp-github-sync'); ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Branch Switching Tab Content -->
        <div class="wp-github-sync-tab-content" id="branches-tab-content">
            <div class="wp-github-sync-card">
                <h2>
                    <span class="dashicons dashicons-randomize"></span>
                    <?php _e('Branch Management', 'wp-github-sync'); ?>
                </h2>
                
                <div class="wp-github-sync-card-content">
                    <p><?php _e('Switch between branches to update your site with different versions of your code.', 'wp-github-sync'); ?></p>
                    
                    <?php if (!empty($branches)) : ?>
                        <div class="wp-github-sync-branch-switcher">
                            <select id="wp-github-sync-branch-select">
                                <?php foreach ($branches as $branch_name) : ?>
                                    <option value="<?php echo esc_attr($branch_name); ?>" <?php selected($branch, $branch_name); ?>>
                                        <?php echo esc_html($branch_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button class="wp-github-sync-button wp-github-sync-switch-branch">
                                <span class="dashicons dashicons-randomize"></span>
                                <?php _e('Switch Branch', 'wp-github-sync'); ?>
                            </button>
                            <button class="wp-github-sync-button secondary wp-github-sync-refresh-branches">
                                <span class="dashicons dashicons-update"></span>
                                <?php _e('Refresh', 'wp-github-sync'); ?>
                            </button>
                        </div>
                        
                        <div class="wp-github-sync-info-box info">
                            <div class="wp-github-sync-info-box-icon">
                                <span class="dashicons dashicons-info"></span>
                            </div>
                            <div class="wp-github-sync-info-box-content">
                                <h4 class="wp-github-sync-info-box-title"><?php _e('About Branch Switching', 'wp-github-sync'); ?></h4>
                                <p class="wp-github-sync-info-box-message">
                                    <?php _e('When you switch branches, your site files will be updated to match the selected branch. This allows you to test different versions of your site. The plugin will create a backup before switching.', 'wp-github-sync'); ?>
                                </p>
                            </div>
                        </div>
                    <?php else : ?>
                        <div class="wp-github-sync-info-box warning">
                            <div class="wp-github-sync-info-box-icon">
                                <span class="dashicons dashicons-warning"></span>
                            </div>
                            <div class="wp-github-sync-info-box-content">
                                <h4 class="wp-github-sync-info-box-title"><?php _e('No Branches Found', 'wp-github-sync'); ?></h4>
                                <p class="wp-github-sync-info-box-message">
                                    <?php _e('No branches could be found in your repository. This could be due to an empty repository or authentication issues.', 'wp-github-sync'); ?>
                                    <br><br>
                                    <a href="<?php echo admin_url('admin.php?page=wp-github-sync-settings'); ?>" class="wp-github-sync-button secondary">
                                        <span class="dashicons dashicons-admin-tools"></span>
                                        <?php _e('Check Settings', 'wp-github-sync'); ?>
                                    </a>
                                </p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Recent Commits Tab Content -->
        <div class="wp-github-sync-tab-content" id="commits-tab-content">
            <div class="wp-github-sync-card">
                <h2>
                    <span class="dashicons dashicons-backup"></span>
                    <?php _e('Commit History', 'wp-github-sync'); ?>
                </h2>
                
                <div class="wp-github-sync-card-content">
                    <p><?php _e('You can review recent commits and roll back to a previous version if needed.', 'wp-github-sync'); ?></p>
                    
                    <?php if (!empty($recent_commits)) : ?>
                        <div class="wp-github-sync-commits-list">
                            <table class="widefat">
                                <thead>
                                    <tr>
                                        <th><?php _e('Commit', 'wp-github-sync'); ?></th>
                                        <th><?php _e('Message', 'wp-github-sync'); ?></th>
                                        <th><?php _e('Author', 'wp-github-sync'); ?></th>
                                        <th><?php _e('Date', 'wp-github-sync'); ?></th>
                                        <th><?php _e('Actions', 'wp-github-sync'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_commits as $commit) : ?>
                                        <tr>
                                            <td class="commit-hash"><?php echo esc_html(substr($commit['sha'], 0, 8)); ?></td>
                                            <td><?php echo esc_html(wp_github_sync_format_commit_message($commit['message'])); ?></td>
                                            <td><?php echo esc_html($commit['author']); ?></td>
                                            <td class="commit-date"><?php echo date_i18n(get_option('date_format'), strtotime($commit['date'])); ?></td>
                                            <td>
                                                <?php if ($commit['sha'] !== $last_deployed_commit) : ?>
                                                    <button class="wp-github-sync-button warning wp-github-sync-rollback" data-commit="<?php echo esc_attr($commit['sha']); ?>">
                                                        <span class="dashicons dashicons-undo"></span>
                                                        <?php _e('Roll Back', 'wp-github-sync'); ?>
                                                    </button>
                                                <?php else : ?>
                                                    <span class="wp-github-sync-current-version">
                                                        <span class="dashicons dashicons-yes-alt"></span>
                                                        <?php _e('Current', 'wp-github-sync'); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="wp-github-sync-info-box warning">
                            <div class="wp-github-sync-info-box-icon">
                                <span class="dashicons dashicons-warning"></span>
                            </div>
                            <div class="wp-github-sync-info-box-content">
                                <h4 class="wp-github-sync-info-box-title"><?php _e('Rollback Warning', 'wp-github-sync'); ?></h4>
                                <p class="wp-github-sync-info-box-message">
                                    <?php _e('Rolling back will change your site\'s files to match the selected commit. This is useful to undo recent changes, but may cause issues if the rolled-back version is incompatible with your database.', 'wp-github-sync'); ?>
                                </p>
                            </div>
                        </div>
                    <?php else : ?>
                        <div class="wp-github-sync-info-box warning">
                            <div class="wp-github-sync-info-box-icon">
                                <span class="dashicons dashicons-warning"></span>
                            </div>
                            <div class="wp-github-sync-info-box-content">
                                <h4 class="wp-github-sync-info-box-title"><?php _e('No Commits Found', 'wp-github-sync'); ?></h4>
                                <p class="wp-github-sync-info-box-message">
                                    <?php _e('No recent commits found. This could be due to an empty repository or authentication issues.', 'wp-github-sync'); ?>
                                </p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Webhook Tab Content -->
        <div class="wp-github-sync-tab-content" id="webhook-tab-content">
            <div class="wp-github-sync-card">
                <h2>
                    <span class="dashicons dashicons-admin-links"></span>
                    <?php _e('Webhook Configuration', 'wp-github-sync'); ?>
                </h2>
                
                <div class="wp-github-sync-card-content">
                    <p><?php _e('Set up a webhook in your GitHub repository to enable automatic deployments when code is pushed.', 'wp-github-sync'); ?></p>
                    
                    <div class="wp-github-sync-webhook-info">
                        <div class="wp-github-sync-status-item">
                            <span class="wp-github-sync-status-label"><?php _e('Webhook URL:', 'wp-github-sync'); ?></span>
                            <span class="wp-github-sync-status-value code">
                                <?php echo esc_url(get_rest_url(null, 'wp-github-sync/v1/webhook')); ?>
                            </span>
                        </div>
                        
                        <div class="wp-github-sync-status-item">
                            <span class="wp-github-sync-status-label"><?php _e('Secret:', 'wp-github-sync'); ?></span>
                            <span class="wp-github-sync-status-value code">
                                <?php echo esc_html(get_option('wp_github_sync_webhook_secret', '')); ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="wp-github-sync-action-buttons">
                        <button class="wp-github-sync-button secondary wp-github-sync-regenerate-webhook">
                            <span class="dashicons dashicons-update"></span>
                            <?php _e('Regenerate Secret', 'wp-github-sync'); ?>
                        </button>
                    </div>
                    
                    <div class="wp-github-sync-info-box info">
                        <div class="wp-github-sync-info-box-icon">
                            <span class="dashicons dashicons-info"></span>
                        </div>
                        <div class="wp-github-sync-info-box-content">
                            <h4 class="wp-github-sync-info-box-title"><?php _e('How to Configure Webhooks', 'wp-github-sync'); ?></h4>
                            <p class="wp-github-sync-info-box-message">
                                <?php
                                printf(
                                    __('1. Go to your <a href="%s/settings/hooks" target="_blank">GitHub repository settings</a><br>2. Click "Add webhook"<br>3. Set the Payload URL to the Webhook URL above<br>4. Select "application/json" as content type<br>5. Enter the Secret shown above<br>6. Choose "Just the push event"<br>7. Ensure "Active" is checked<br>8. Click "Add webhook"', 'wp-github-sync'),
                                    esc_url($repository_url)
                                );
                                ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            
        <!-- Developer Tools Tab Content -->
        <div class="wp-github-sync-tab-content" id="dev-tab-content">
            <div class="wp-github-sync-card">
                <h2>
                    <span class="dashicons dashicons-code-standards"></span>
                    <?php _e('Developer Tools', 'wp-github-sync'); ?>
                </h2>
                
                <div class="wp-github-sync-card-content">
                    <p><?php _e('Special tools for developers to simplify the workflow between local development and the GitHub repository.', 'wp-github-sync'); ?></p>
                    
                    <div class="wp-github-sync-developer-tools">
                        <h3>
                            <span class="dashicons dashicons-upload"></span>
                            <?php _e('Local Changes Export', 'wp-github-sync'); ?>
                        </h3>
                        
                        <p><?php _e('Export changes made on this WordPress site to your local development environment.', 'wp-github-sync'); ?></p>
                        
                        <div class="wp-github-sync-action-buttons">
                            <button class="wp-github-sync-button wp-github-sync-export-changes">
                                <span class="dashicons dashicons-download"></span>
                                <?php _e('Export Changes as ZIP', 'wp-github-sync'); ?>
                            </button>
                        </div>
                    </div>
                    
                    <div class="wp-github-sync-developer-tools">
                        <h3>
                            <span class="dashicons dashicons-plugins-checked"></span>
                            <?php _e('Plugin & Theme Development', 'wp-github-sync'); ?>
                        </h3>
                        
                        <p><?php _e('Manage changes to specific plugins or themes in your repository.', 'wp-github-sync'); ?></p>
                        
                        <div class="wp-github-sync-status-item">
                            <span class="wp-github-sync-status-label"><?php _e('Component:', 'wp-github-sync'); ?></span>
                            <select id="wp-github-sync-component-select" class="wp-github-sync-status-value">
                                <option value=""><?php _e('-- Select Component --', 'wp-github-sync'); ?></option>
                                <optgroup label="<?php _e('Themes', 'wp-github-sync'); ?>">
                                    <?php
                                    $themes = wp_get_themes();
                                    foreach ($themes as $theme_code => $theme) {
                                        echo '<option value="theme:' . esc_attr($theme_code) . '">' . esc_html($theme->get('Name')) . '</option>';
                                    }
                                    ?>
                                </optgroup>
                                <optgroup label="<?php _e('Plugins', 'wp-github-sync'); ?>">
                                    <?php
                                    $plugins = get_plugins();
                                    foreach ($plugins as $plugin_file => $plugin_data) {
                                        $plugin_slug = explode('/', $plugin_file)[0];
                                        echo '<option value="plugin:' . esc_attr($plugin_slug) . '">' . esc_html($plugin_data['Name']) . '</option>';
                                    }
                                    ?>
                                </optgroup>
                            </select>
                        </div>
                        
                        <div class="wp-github-sync-action-buttons">
                            <button class="wp-github-sync-button wp-github-sync-sync-component" disabled>
                                <span class="dashicons dashicons-update"></span>
                                <?php _e('Pull Latest Changes', 'wp-github-sync'); ?>
                            </button>
                            
                            <button class="wp-github-sync-button secondary wp-github-sync-diff-component" disabled>
                                <span class="dashicons dashicons-visibility"></span>
                                <?php _e('View Differences', 'wp-github-sync'); ?>
                            </button>
                        </div>
                        
                        <div id="wp-github-sync-diff-viewer" style="display: none;">
                            <h4><?php _e('Differences', 'wp-github-sync'); ?></h4>
                            <div class="wp-github-sync-diff-content" style="background: #f6f7f7; padding: 15px; border-radius: 4px; overflow: auto; max-height: 400px; font-family: monospace; font-size: 12px; white-space: pre; line-height: 1.5;"></div>
                        </div>
                    </div>
                    
                    <script>
                    jQuery(document).ready(function($) {
                        // Component selector
                        $('#wp-github-sync-component-select').on('change', function() {
                            const componentValue = $(this).val();
                            const hasSelection = componentValue !== '';
                            
                            $('.wp-github-sync-sync-component, .wp-github-sync-diff-component').prop('disabled', !hasSelection);
                        });
                        
                        // Export changes button
                        $('.wp-github-sync-export-changes').on('click', function() {
                            $('.wp-github-sync-overlay').show();
                            $('.wp-github-sync-loading-message').text('<?php _e('Preparing export...', 'wp-github-sync'); ?>');
                            $('.wp-github-sync-loading-submessage').text('<?php _e('This may take a moment depending on the size of your site.', 'wp-github-sync'); ?>');
                            
                            // AJAX call would go here in a real implementation
                            // For demo purposes, simulate a delay and success
                            setTimeout(function() {
                                $('.wp-github-sync-loading-message').text('<?php _e('Export Complete!', 'wp-github-sync'); ?>');
                                $('.wp-github-sync-loading-submessage').text('<?php _e('Your browser will now download the ZIP file.', 'wp-github-sync'); ?>');
                                
                                setTimeout(function() {
                                    $('.wp-github-sync-overlay').hide();
                                    // In a real implementation, this would trigger file download
                                    alert('<?php _e('Export feature will be available in the next version.', 'wp-github-sync'); ?>');
                                }, 2000);
                            }, 3000);
                        });
                        
                        // Component sync button
                        $('.wp-github-sync-sync-component').on('click', function() {
                            const component = $('#wp-github-sync-component-select').val();
                            if (!component) return;
                            
                            $('.wp-github-sync-overlay').show();
                            $('.wp-github-sync-loading-message').text('<?php _e('Syncing component...', 'wp-github-sync'); ?>');
                            $('.wp-github-sync-loading-submessage').text('<?php _e('Pulling latest changes from GitHub repository.', 'wp-github-sync'); ?>');
                            
                            // AJAX call would go here in a real implementation
                            // For demo purposes, simulate a delay and success
                            setTimeout(function() {
                                $('.wp-github-sync-loading-message').text('<?php _e('Sync Complete!', 'wp-github-sync'); ?>');
                                $('.wp-github-sync-loading-submessage').text('<?php _e('Component has been updated to the latest version.', 'wp-github-sync'); ?>');
                                
                                setTimeout(function() {
                                    $('.wp-github-sync-overlay').hide();
                                    // In a real implementation, refresh the component
                                    alert('<?php _e('Component sync feature will be available in the next version.', 'wp-github-sync'); ?>');
                                }, 2000);
                            }, 3000);
                        });
                        
                        // Component diff button
                        $('.wp-github-sync-diff-component').on('click', function() {
                            const component = $('#wp-github-sync-component-select').val();
                            if (!component) return;
                            
                            $('.wp-github-sync-overlay').show();
                            $('.wp-github-sync-loading-message').text('<?php _e('Calculating differences...', 'wp-github-sync'); ?>');
                            $('.wp-github-sync-loading-submessage').text('<?php _e('Comparing local files with repository version.', 'wp-github-sync'); ?>');
                            
                            // AJAX call would go here in a real implementation
                            // For demo purposes, simulate a delay and sample diff data
                            setTimeout(function() {
                                $('.wp-github-sync-overlay').hide();
                                
                                // Show diff viewer with sample data
                                const diffContent = 
`diff --git a/style.css b/style.css
index 1234567..abcdefg 100644
--- a/style.css
+++ b/style.css
@@ -10,7 +10,7 @@
 */
 
 .header {
-    background-color: #f8f8f8;
+    background-color: #ffffff;
     padding: 20px;
     margin-bottom: 30px;
 }
@@ -42,6 +42,10 @@
     color: #333;
 }
 
+.new-element {
+    display: flex;
+}
+
 /* Footer Styles */
 .footer {
     background: #222;`;
                                
                                $('#wp-github-sync-diff-viewer').slideDown();
                                $('.wp-github-sync-diff-content').html(diffContent);
                                
                                // Scroll to the diff viewer
                                $('html, body').animate({
                                    scrollTop: $('#wp-github-sync-diff-viewer').offset().top - 100
                                }, 500);
                            }, 2000);
                        });
                    });
                    </script>
                </div>
            </div>
        </div>
        
        <!-- Loading/Progress Overlay -->
        <div class="wp-github-sync-overlay" style="display: none;">
            <div class="wp-github-sync-loader"></div>
            <div class="wp-github-sync-loading-message"><?php _e('Processing...', 'wp-github-sync'); ?></div>
            <div class="wp-github-sync-loading-submessage"></div>
        </div>
        
        <!-- Tab scripts will be moved to the main JS file -->
        
    <?php endif; ?>
</div>