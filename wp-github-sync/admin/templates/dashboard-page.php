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

// Check user capability
if (!wp_github_sync_current_user_can()) {
    wp_die(__('You do not have sufficient permissions to access this page.', 'wp-github-sync'));
}

// Data is now prepared in Admin_Pages.php and passed via variables.
// Assume the following variables are available here:
// $repository_url, $branch, $last_deployed_commit, $latest_commit_info,
// $update_available, $is_deployment_locked, $deployment_history,
// $last_deployment_time, $branches, $recent_commits, $parsed_url

// Use the parsed URL passed from Admin_Pages
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
        <?php include plugin_dir_path(__FILE__) . 'dashboard-parts/overview-card.php'; ?>
        <?php include plugin_dir_path(__FILE__) . 'dashboard-parts/tabs.php'; ?>
        <?php include plugin_dir_path(__FILE__) . 'dashboard-parts/tab-getting-started.php'; ?>
        <?php include plugin_dir_path(__FILE__) . 'dashboard-parts/tab-branches.php'; ?>
        <?php include plugin_dir_path(__FILE__) . 'dashboard-parts/tab-commits.php'; ?>
        <?php include plugin_dir_path(__FILE__) . 'dashboard-parts/tab-webhook.php'; ?>
        <?php // Removed Developer Tools Tab Content ?>
        <?php include plugin_dir_path(__FILE__) . 'dashboard-parts/loading-overlay.php'; ?>
    <?php endif; ?>
</div>
