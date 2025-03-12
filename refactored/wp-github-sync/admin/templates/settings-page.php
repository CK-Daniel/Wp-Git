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

// Check user capability
if (!wp_github_sync_current_user_can()) {
    wp_die(__('You do not have sufficient permissions to access this page.', 'wp-github-sync'));
}

// Get settings and status
$settings = get_option('wp_github_sync_settings', array());
$repository_url = get_option('wp_github_sync_repository', '');
$is_configured = !empty($repository_url) && (!empty(get_option('wp_github_sync_access_token', '')) || !empty(get_option('wp_github_sync_oauth_token', '')));
$has_synced = !empty(get_option('wp_github_sync_last_deployed_commit', ''));

// Create default repo name suggestion based on site URL
$site_url = parse_url(get_site_url(), PHP_URL_HOST);
$default_repo_name = sanitize_title(str_replace('.', '-', $site_url));
?>
<div class="wrap wp-github-sync-wrap">
    <h1><?php _e('GitHub Sync Settings', 'wp-github-sync'); ?></h1>
    
    <?php settings_errors(); ?>
    
    <?php if (!$has_synced && $is_configured): ?>
    <div class="wp-github-sync-initial-sync-card">
        <h2>
            <span class="dashicons dashicons-update"></span>
            <?php _e('Initial Sync Setup', 'wp-github-sync'); ?>
        </h2>
        <p><?php _e('You\'ve configured GitHub Sync, but haven\'t performed your first synchronization yet. You can either connect to an existing repository or create a new one.', 'wp-github-sync'); ?></p>
        
        <div class="wp-github-sync-repo-creation">
            <div>
                <label for="create_new_repo"><?php _e('Create a new GitHub repository for this WordPress site', 'wp-github-sync'); ?></label>
                <input type="checkbox" id="create_new_repo" name="create_new_repo" value="1"/>
            </div>
            
            <div id="new_repo_options" style="display: none;">
                <label for="new_repo_name"><?php _e('Repository Name', 'wp-github-sync'); ?></label>
                <input type="text" id="new_repo_name" name="new_repo_name" placeholder="<?php echo esc_attr($default_repo_name); ?>" />
            </div>
        </div>
        
        <button type="button" id="initial_sync_button" class="wp-github-sync-initial-sync-button">
            <span class="dashicons dashicons-update"></span>
            <?php _e('Start Initial Sync', 'wp-github-sync'); ?>
        </button>
    </div>
    <?php endif; ?>
    
    <form method="post" action="options.php" class="wp-github-sync-settings-form">
        <?php settings_fields('wp_github_sync_settings'); ?>
        
        <div class="wp-github-sync-settings-content">
            <div class="wp-github-sync-tabs">
                <div class="wp-github-sync-tab active" data-tab="general">
                    <span class="dashicons dashicons-admin-settings"></span>
                    <?php _e('General', 'wp-github-sync'); ?>
                </div>
                <div class="wp-github-sync-tab" data-tab="authentication">
                    <span class="dashicons dashicons-lock"></span>
                    <?php _e('Authentication', 'wp-github-sync'); ?>
                </div>
                <div class="wp-github-sync-tab" data-tab="sync">
                    <span class="dashicons dashicons-update"></span>
                    <?php _e('Sync Options', 'wp-github-sync'); ?>
                </div>
                <div class="wp-github-sync-tab" data-tab="advanced">
                    <span class="dashicons dashicons-admin-tools"></span>
                    <?php _e('Advanced', 'wp-github-sync'); ?>
                </div>
            </div>
            
            <div class="wp-github-sync-card">
                <!-- Tab content will be dynamically loaded here -->
                <div id="wp-github-sync-tab-content-container">
                    <?php do_settings_sections('wp_github_sync_settings'); ?>
                </div>
                
                <div class="wp-github-sync-card-actions">
                    <?php submit_button(__('Save Settings', 'wp-github-sync'), 'primary wp-github-sync-button', 'submit', false); ?>
                </div>
            </div>
            
            <?php if ($has_synced): ?>
            <div class="wp-github-sync-info-box info">
                <div class="wp-github-sync-info-box-icon">
                    <span class="dashicons dashicons-info"></span>
                </div>
                <div class="wp-github-sync-info-box-content">
                    <h4 class="wp-github-sync-info-box-title"><?php _e('Need to update files from GitHub?', 'wp-github-sync'); ?></h4>
                    <p class="wp-github-sync-info-box-message">
                        <?php _e('Visit the GitHub Sync Dashboard to check for updates, deploy changes, or switch branches.', 'wp-github-sync'); ?>
                        <br>
                        <a href="<?php echo admin_url('admin.php?page=wp-github-sync'); ?>" class="wp-github-sync-button secondary" style="margin-top: 10px;">
                            <span class="dashicons dashicons-dashboard"></span>
                            <?php _e('Go to Dashboard', 'wp-github-sync'); ?>
                        </a>
                    </p>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Connection testing area -->
            <div id="github-connection-status"></div>
        </div>
    </form>
    
    <!-- Loading/Progress Overlay -->
    <div class="wp-github-sync-overlay" style="display: none;">
        <div class="wp-github-sync-loader"></div>
        <div class="wp-github-sync-loading-message"><?php _e('Processing...', 'wp-github-sync'); ?></div>
        <div class="wp-github-sync-loading-submessage"></div>
    </div>
</div>