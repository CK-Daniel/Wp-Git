<?php
/**
 * Template part for displaying the repository overview card on the dashboard.
 *
 * @package WPGitHubSync
 *
 * Available variables:
 * $repository_url, $branch, $last_deployed_commit, $latest_commit_info,
 * $update_available, $is_deployment_locked, $last_deployment_time, $repo_display
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}
?>
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

                <?php if (!empty($latest_commit_info) && isset($latest_commit_info['sha'])) : ?>
                <div class="wp-github-sync-status-item">
                    <span class="wp-github-sync-status-label"><?php _e('Current Commit:', 'wp-github-sync'); ?></span>
                    <span class="wp-github-sync-status-value">
                        <span class="code"><?php echo esc_html(substr($latest_commit_info['sha'], 0, 8)); ?></span>
                        - <?php echo esc_html(wp_github_sync_format_commit_message($latest_commit_info['message'] ?? '')); ?>
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
                        <?php if ($is_deployment_locked) : ?>
                            <span class="wp-github-sync-status-in-progress">
                                <span class="dashicons dashicons-update wp-github-sync-spin"></span>
                                <?php _e('Operation in progress...', 'wp-github-sync'); ?>
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
                    <?php if ($update_available && !$is_deployment_locked) : ?>
                        <div class="wp-github-sync-button-group">
                            <button class="wp-github-sync-button success wp-github-sync-deploy">
                                <span class="dashicons dashicons-cloud-upload"></span>
                        <?php _e('Deploy Latest Changes', 'wp-github-sync'); ?>
                            </button>
                            <button class="wp-github-sync-button success wp-github-sync-dropdown-toggle">
                                <span class="dashicons dashicons-arrow-down-alt2"></span>
                            </button>
                            <div class="wp-github-sync-dropdown-menu">
                                <a href="#" class="wp-github-sync-background-deploy">
                                    <span class="dashicons dashicons-backup"></span>
                                    <?php _e('Run in Background', 'wp-github-sync'); ?>
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>

                    <button class="wp-github-sync-button secondary wp-github-sync-check-updates" <?php disabled($is_deployment_locked); ?>>
                        <span class="dashicons dashicons-update"></span>
                        <?php _e('Check for Updates', 'wp-github-sync'); ?>
                    </button>

                    <div class="wp-github-sync-button-group">
                        <button class="wp-github-sync-button wp-github-sync-full-sync" <?php disabled($is_deployment_locked); ?>>
                            <span class="dashicons dashicons-upload"></span>
                            <?php _e('Sync All to GitHub', 'wp-github-sync'); ?>
                        </button>
                        <button class="wp-github-sync-button wp-github-sync-dropdown-toggle" <?php disabled($is_deployment_locked); ?>>
                            <span class="dashicons dashicons-arrow-down-alt2"></span>
                        </button>
                        <div class="wp-github-sync-dropdown-menu">
                            <a href="#" class="wp-github-sync-background-full-sync">
                                <span class="dashicons dashicons-backup"></span>
                                <?php _e('Run in Background', 'wp-github-sync'); ?>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
