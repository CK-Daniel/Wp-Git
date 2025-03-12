<?php
/**
 * Admin settings page template.
 *
 * @package WPGitHubSync
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}
?>
<div class="wrap">
    <h1><?php _e('GitHub Sync Settings', 'wp-github-sync'); ?></h1>
    
    <?php settings_errors(); ?>
    
    <form method="post" action="options.php" class="wp-github-sync-settings-form">
        <?php
        settings_fields('wp_github_sync_settings');
        ?>
        
        <div class="wp-github-sync-settings-content">
            <div class="wp-github-sync-card">
                <?php do_settings_sections('wp_github_sync_settings'); ?>
            </div>
            
            <?php submit_button(__('Save Settings', 'wp-github-sync'), 'primary', 'submit', true); ?>
        </div>
    </form>
    
    <!-- Loading/Progress Overlay -->
    <div class="wp-github-sync-overlay" style="display: none;">
        <div class="wp-github-sync-loader"></div>
        <div class="wp-github-sync-loading-message"><?php _e('Processing...', 'wp-github-sync'); ?></div>
    </div>
</div>