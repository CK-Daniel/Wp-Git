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
?>
<div class="wrap">
    <h1><?php _e('GitHub Sync Dashboard', 'wp-github-sync'); ?></h1>
    
    <?php settings_errors('wp_github_sync'); ?>
    
    <?php if (empty($repository_url)) : ?>
        <div class="notice notice-warning">
            <p>
                <?php _e('GitHub Sync is not configured yet.', 'wp-github-sync'); ?>
                <a href="<?php echo admin_url('admin.php?page=wp-github-sync-settings'); ?>" class="button"><?php _e('Configure Now', 'wp-github-sync'); ?></a>
            </p>
        </div>
    <?php else : ?>
        <div class="wp-github-sync-dashboard">
            <!-- Repository Status Card -->
            <div class="wp-github-sync-card">
                <h2><?php _e('Repository Status', 'wp-github-sync'); ?></h2>
                
                <?php
                // Parse repository URL to get owner/repo format
                $parsed_url = $this->github_api->parse_github_url($repository_url);
                $repo_display = $parsed_url ? $parsed_url['owner'] . '/' . $parsed_url['repo'] : $repository_url;
                ?>
                
                <div class="wp-github-sync-card-content">
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
                                <?php echo esc_html(substr($latest_commit_info['sha'], 0, 8)); ?>
                                - <?php echo esc_html(wp_github_sync_format_commit_message($latest_commit_info['message'])); ?>
                            </span>
                        </div>
                        
                        <div class="wp-github-sync-status-item">
                            <span class="wp-github-sync-status-label"><?php _e('Author:', 'wp-github-sync'); ?></span>
                            <span class="wp-github-sync-status-value"><?php echo esc_html($latest_commit_info['author']); ?></span>
                        </div>
                        
                        <div class="wp-github-sync-status-item">
                            <span class="wp-github-sync-status-label"><?php _e('Date:', 'wp-github-sync'); ?></span>
                            <span class="wp-github-sync-status-value">
                                <?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($latest_commit_info['date'])); ?>
                            </span>
                        </div>
                    <?php endif; ?>
                    
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
                                <span class="wp-github-sync-status-in-progress"><?php _e('Deployment in progress...', 'wp-github-sync'); ?></span>
                            <?php elseif ($update_available) : ?>
                                <span class="wp-github-sync-status-update-available"><?php _e('Update available', 'wp-github-sync'); ?></span>
                            <?php else : ?>
                                <span class="wp-github-sync-status-up-to-date"><?php _e('Up to date', 'wp-github-sync'); ?></span>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
                
                <div class="wp-github-sync-card-actions">
                    <?php if ($update_available && !$deployment_in_progress) : ?>
                        <button class="button button-primary wp-github-sync-deploy"><?php _e('Deploy Latest Changes', 'wp-github-sync'); ?></button>
                    <?php endif; ?>
                    
                    <button class="button wp-github-sync-check-updates"><?php _e('Check for Updates', 'wp-github-sync'); ?></button>
                </div>
            </div>
            
            <!-- Branch Switching Card -->
            <div class="wp-github-sync-card">
                <h2><?php _e('Switch Branch', 'wp-github-sync'); ?></h2>
                
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
                            <button class="button wp-github-sync-switch-branch"><?php _e('Switch Branch', 'wp-github-sync'); ?></button>
                            <button class="button wp-github-sync-refresh-branches"><?php _e('Refresh Branches', 'wp-github-sync'); ?></button>
                        </div>
                    <?php else : ?>
                        <p><?php _e('No branches found. Please check your repository and authentication settings.', 'wp-github-sync'); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Recent Commits / Rollback Card -->
            <div class="wp-github-sync-card">
                <h2><?php _e('Recent Commits', 'wp-github-sync'); ?></h2>
                
                <div class="wp-github-sync-card-content">
                    <p><?php _e('You can roll back to a previous commit if needed.', 'wp-github-sync'); ?></p>
                    
                    <?php if (!empty($recent_commits)) : ?>
                        <div class="wp-github-sync-commits-list">
                            <table class="widefat striped">
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
                                            <td><?php echo esc_html(substr($commit['sha'], 0, 8)); ?></td>
                                            <td><?php echo esc_html(wp_github_sync_format_commit_message($commit['message'])); ?></td>
                                            <td><?php echo esc_html($commit['author']); ?></td>
                                            <td><?php echo date_i18n(get_option('date_format'), strtotime($commit['date'])); ?></td>
                                            <td>
                                                <?php if ($commit['sha'] !== $last_deployed_commit) : ?>
                                                    <button class="button wp-github-sync-rollback" data-commit="<?php echo esc_attr($commit['sha']); ?>">
                                                        <?php _e('Roll Back', 'wp-github-sync'); ?>
                                                    </button>
                                                <?php else : ?>
                                                    <span class="wp-github-sync-current-version"><?php _e('Current Version', 'wp-github-sync'); ?></span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <p class="description"><?php _e('Note: Rolling back will change your site\'s files to match the selected commit.', 'wp-github-sync'); ?></p>
                    <?php else : ?>
                        <p><?php _e('No recent commits found. This could be due to an empty repository or authentication issues.', 'wp-github-sync'); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Additional Information Card -->
            <div class="wp-github-sync-card">
                <h2><?php _e('Webhook Information', 'wp-github-sync'); ?></h2>
                
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
                        
                        <p class="description">
                            <?php
                            printf(
                                __('Configure this webhook in your <a href="%s/settings/hooks" target="_blank">GitHub repository settings</a>. Select "application/json" as the content type.', 'wp-github-sync'),
                                esc_url($repository_url)
                            );
                            ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Loading/Progress Overlay -->
        <div class="wp-github-sync-overlay" style="display: none;">
            <div class="wp-github-sync-loader"></div>
            <div class="wp-github-sync-loading-message"><?php _e('Processing...', 'wp-github-sync'); ?></div>
        </div>
    <?php endif; ?>
</div>