<?php
/**
 * Template part for displaying the 'Getting Started' tab content on the dashboard.
 *
 * @package WPGitHubSync
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}
?>
<div class="wp-github-sync-tab-content active" id="guide-tab-content" data-tab="guide">
    <div class="wp-github-sync-card">
        <h2>
            <span class="dashicons dashicons-book"></span>
            <?php _e('Welcome to GitHub Sync', 'wp-github-sync'); ?>
        </h2>

        <div class="wp-github-sync-card-content">
            <p><?php _e('GitHub Sync connects your WordPress site with GitHub, providing version control and deployment tools without needing technical knowledge.', 'wp-github-sync'); ?></p>

            <div class="wp-github-sync-getting-started-columns">
                <div class="wp-github-sync-getting-started-column">
                    <h3 class="wp-github-sync-getting-started-heading">
                        <span class="dashicons dashicons-admin-users"></span>
                        <?php _e('For Non-Developers', 'wp-github-sync'); ?>
                    </h3>

                    <div class="wp-github-sync-info-box info">
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

                    <h4 class="wp-github-sync-getting-started-subheading"><?php _e('Common Tasks:', 'wp-github-sync'); ?></h4>
                    <ul class="wp-github-sync-task-list">
                        <li>
                            <span class="dashicons dashicons-yes-alt"></span>
                            <span><strong><?php _e('Update Your Site:', 'wp-github-sync'); ?></strong> <?php _e('When changes are made in GitHub, click "Deploy Latest Changes" to update your site.', 'wp-github-sync'); ?></span>
                        </li>
                        <li>
                            <span class="dashicons dashicons-yes-alt"></span>
                            <span><strong><?php _e('Restore Previous Version:', 'wp-github-sync'); ?></strong> <?php _e('Go to the Version History page to see all previous versions and restore if needed.', 'wp-github-sync'); ?></span>
                        </li>
                        <li>
                            <span class="dashicons dashicons-yes-alt"></span>
                            <span><strong><?php _e('Switch Branch:', 'wp-github-sync'); ?></strong> <?php _e('Use the Branches tab to switch between different versions of your site (like switching between "production" and "testing" versions).', 'wp-github-sync'); ?></span>
                        </li>
                    </ul>

                    <a href="<?php echo esc_url(admin_url('admin.php?page=wp-github-sync-history')); ?>" class="wp-github-sync-button">
                        <span class="dashicons dashicons-backup"></span>
                        <?php _e('View Version History', 'wp-github-sync'); ?>
                    </a>
                </div>

                <div class="wp-github-sync-getting-started-column">
                    <h3 class="wp-github-sync-getting-started-heading">
                        <span class="dashicons dashicons-code-standards"></span>
                        <?php _e('For Developers', 'wp-github-sync'); ?>
                    </h3>

                    <div class="wp-github-sync-info-box info">
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

                    <h4 class="wp-github-sync-getting-started-subheading"><?php _e('Advanced Features:', 'wp-github-sync'); ?></h4>
                    <ul class="wp-github-sync-task-list">
                        <li>
                            <span class="dashicons dashicons-admin-tools"></span>
                            <span><strong><?php _e('Component Sync:', 'wp-github-sync'); ?></strong> <?php _e('Sync specific plugins or themes individually using the Developer Tools tab.', 'wp-github-sync'); ?></span>
                        </li>
                        <li>
                            <span class="dashicons dashicons-admin-tools"></span>
                            <span><strong><?php _e('Webhook Integration:', 'wp-github-sync'); ?></strong> <?php _e('Configure GitHub webhooks for automatic deployments when you push commits.', 'wp-github-sync'); ?></span>
                        </li>
                        <li>
                            <span class="dashicons dashicons-admin-tools"></span>
                            <span><strong><?php _e('File Difference Viewer:', 'wp-github-sync'); ?></strong> <?php _e('Compare changes between local files and the repository to better understand modifications.', 'wp-github-sync'); ?></span>
                        </li>
                    </ul>

                    <div class="wp-github-sync-action-buttons">
                        <?php /* Removed link to non-existent Developer Tools tab
                        <a href="#" class="wp-github-sync-button wp-github-sync-tab-link" data-tab-target="dev">
                            <span class="dashicons dashicons-code-standards"></span>
                            <?php _e('Go to Developer Tools', 'wp-github-sync'); ?>
                        </a>
                        */ ?>
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
