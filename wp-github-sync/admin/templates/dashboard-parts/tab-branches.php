<?php
/**
 * Template part for displaying the 'Branches' tab content on the dashboard.
 *
 * @package WPGitHubSync
 *
 * Available variables:
 * $branches, $branch
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}
?>
<div class="wp-github-sync-tab-content" id="branches-tab-content" data-tab="branches">
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
