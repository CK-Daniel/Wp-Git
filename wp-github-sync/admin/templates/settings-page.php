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
            <div class="wp-github-sync-toggle-option">
                <input type="checkbox" id="create_new_repo" name="create_new_repo" value="1" class="wp-github-sync-toggle"/>
                <label for="create_new_repo"><?php _e('Create a new GitHub repository for this WordPress site', 'wp-github-sync'); ?></label>
                <div class="wp-github-sync-toggle-slider"></div>
            </div>
            
            <div id="new_repo_options" class="wp-github-sync-option-group" style="display: none;">
                <label for="new_repo_name"><?php _e('Repository Name', 'wp-github-sync'); ?></label>
                <input type="text" id="new_repo_name" name="new_repo_name" class="wp-github-sync-text-input" placeholder="<?php echo esc_attr($default_repo_name); ?>" />
            </div>
        </div>
        
        <div class="wp-github-sync-toggle-option">
            <input type="checkbox" id="run_in_background" name="run_in_background" value="1" class="wp-github-sync-toggle"/>
            <label for="run_in_background"><?php _e('Run sync in background (bypasses PHP timeout limits)', 'wp-github-sync'); ?></label>
            <div class="wp-github-sync-toggle-slider"></div>
            <p class="description" style="margin-top: 5px;"><?php _e('Enable this option for large repositories or if you experience timeout errors', 'wp-github-sync'); ?></p>
        </div>
        
        <button type="button" id="initial_sync_button" class="wp-github-sync-button primary wp-github-sync-initial-sync-button">
            <span class="dashicons dashicons-update"></span>
            <?php _e('Start Initial Sync', 'wp-github-sync'); ?>
        </button>
    </div>
    <?php endif; ?>
    
    <form method="post" action="options.php" class="wp-github-sync-settings-form">
        <?php settings_fields('wp_github_sync_settings'); // Call ONCE here for the form ?>
        
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
                <!-- Tab content container -->
                <div id="wp-github-sync-tab-content-container">
                    <!-- General tab content -->
                    <div id="general-tab-content" class="wp-github-sync-tab-content active" data-tab="general">
                        <h3><?php _e('Repository Settings', 'wp-github-sync'); ?></h3>
                        <p><?php _e('Configure your GitHub repository connection settings.', 'wp-github-sync'); ?></p>
                        
                        <?php // settings_fields() called once at the top of the form ?>
                        
                        <table class="form-table" role="presentation">
                            <tbody>
                                <tr>
                                    <th scope="row"><?php _e('GitHub Repository URL', 'wp-github-sync'); ?></th>
                                    <td>
                                        <?php $repository = get_option('wp_github_sync_repository', ''); ?>
                                        <input type="text" name="wp_github_sync_repository" value="<?php echo esc_attr($repository); ?>" class="regular-text">
                                        <p class="description"><?php _e('Enter the GitHub repository URL (e.g., https://github.com/username/repository).', 'wp-github-sync'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php _e('Branch', 'wp-github-sync'); ?></th>
                                    <td>
                                        <?php $branch = get_option('wp_github_sync_branch', 'main'); ?>
                                        <input type="text" name="wp_github_sync_branch" value="<?php echo esc_attr($branch); ?>" class="regular-text">
                                        <p class="description"><?php _e('Enter the branch to sync with (e.g., main).', 'wp-github-sync'); ?></p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Authentication tab content -->
                    <div id="authentication-tab-content" class="wp-github-sync-tab-content" data-tab="authentication">
                        <h3><?php _e('Authentication Settings', 'wp-github-sync'); ?></h3>
                        <p><?php _e('Configure your GitHub authentication credentials.', 'wp-github-sync'); ?></p>
                        
                        <?php
                        // Add a security warning if the encryption key constant is not defined but the option is being used
                        if (!defined('WP_GITHUB_SYNC_ENCRYPTION_KEY') && get_option('wp_github_sync_encryption_key')) {
                            echo '<div class="notice notice-warning inline" style="margin-bottom: 15px;"><p>';
                            echo '<strong>' . __('Security Recommendation:', 'wp-github-sync') . '</strong> ';
                            echo sprintf(
                                __('For enhanced security, define the %s constant in your %s file instead of relying on the database key. %sLearn more%s', 'wp-github-sync'),
                                '<code>WP_GITHUB_SYNC_ENCRYPTION_KEY</code>',
                                '<code>wp-config.php</code>',
                                '<a href="https://docs.example.com/security#encryption-key" target="_blank">', // Replace with actual docs link later
                                '</a>'
                            );
                            echo '</p></div>';
                        }
                        ?>
                        
                        <?php // settings_fields() called once at the top of the form ?>
                        
                        <table class="form-table" role="presentation">
                            <tbody>
                                <tr>
                                    <th scope="row"><?php _e('Authentication Method', 'wp-github-sync'); ?></th>
                                    <td>
                                        <?php 
                                        $auth_method = get_option('wp_github_sync_auth_method', 'pat');
                                        ?>
                                        <select name="wp_github_sync_auth_method" id="wp_github_sync_auth_method">
                                            <option value="pat" <?php selected($auth_method, 'pat'); ?>><?php _e('Personal Access Token (PAT)', 'wp-github-sync'); ?></option>
                                            <option value="oauth" <?php selected($auth_method, 'oauth'); ?>><?php _e('OAuth Token', 'wp-github-sync'); ?></option>
                                            <option value="github_app" <?php selected($auth_method, 'github_app'); ?>><?php _e('GitHub App (Recommended)', 'wp-github-sync'); ?></option>
                                        </select>
                                        <p class="description"><?php _e('Select the authentication method to use with GitHub.', 'wp-github-sync'); ?></p>
                                        <?php // Descriptions moved to Settings.php render callback ?>
                                    </td>
                                </tr>
                                <tr class="auth-field auth-field-pat" <?php echo $auth_method !== 'pat' ? 'style="display:none;"' : ''; ?>>
                                    <th scope="row"><?php _e('Personal Access Token', 'wp-github-sync'); ?></th>
                                    <td>
                                        <?php
                                        $token = get_option('wp_github_sync_access_token', '');
                                        $display_value = !empty($token) ? '********' : ''; // Mask if set
                                        ?>
                                        <input type="password" name="wp_github_sync_access_token" id="wp_github_sync_access_token" value="<?php echo esc_attr($display_value); ?>" class="regular-text" placeholder="<?php esc_attr_e('Enter PAT (e.g., ghp_... or github_pat_...)', 'wp-github-sync'); ?>">
                                        <button type="button" class="button wp-github-sync-test-connection"><?php _e('Test PAT Connection', 'wp-github-sync'); ?></button>
                                        <div id="github-pat-connection-status"></div>
                                        <p class="description"><?php _e('Enter your GitHub Personal Access Token. Use a fine-grained token with Contents read/write access if possible.', 'wp-github-sync'); ?></p>
                                    </td>
                                </tr>
                                <tr class="auth-field auth-field-oauth" <?php echo $auth_method !== 'oauth' ? 'style="display:none;"' : ''; ?>>
                                     <th scope="row"><?php _e('OAuth Connection', 'wp-github-sync'); ?></th>
                                     <td>
                                         <?php
                                         $oauth_token = get_option('wp_github_sync_oauth_token', '');
                                         if (!empty($oauth_token)) :
                                             // Ideally, fetch and display the connected user here via an API call if needed
                                             ?>
                                             <p style="color: green; font-weight: bold;"><?php _e('Connected to GitHub via OAuth.', 'wp-github-sync'); ?></p>
                                             <button type="button" class="button wp-github-sync-oauth-disconnect"><?php _e('Disconnect', 'wp-github-sync'); ?></button>
                                         <?php else : ?>
                                             <p><?php _e('Not connected. Connect your GitHub account to authenticate using OAuth.', 'wp-github-sync'); ?></p>
                                             <button type="button" class="button primary wp-github-sync-oauth-connect"><?php _e('Connect to GitHub', 'wp-github-sync'); ?></button>
                                             <p class="description"><?php _e('Requires WP_GITHUB_SYNC_OAUTH_CLIENT_ID and WP_GITHUB_SYNC_OAUTH_CLIENT_SECRET constants defined in wp-config.php.', 'wp-github-sync'); ?></p>
                                         <?php endif; ?>
                                         <div id="github-oauth-connection-status"></div>
                                     </td>
                                </tr>
                                <tr class="auth-field auth-field-github_app" <?php echo $auth_method !== 'github_app' ? 'style="display:none;"' : ''; ?>>
                                    <th scope="row"><?php _e('GitHub App ID', 'wp-github-sync'); ?></th>
                                    <td>
                                        <?php $app_id = get_option('wp_github_sync_github_app_id', ''); ?>
                                        <input type="text" name="wp_github_sync_github_app_id" id="wp_github_sync_github_app_id" value="<?php echo esc_attr($app_id); ?>" class="regular-text">
                                        <p class="description"><?php _e('The GitHub App ID from your GitHub App settings.', 'wp-github-sync'); ?></p>
                                    </td>
                                </tr>
                                 <tr class="auth-field auth-field-github_app" <?php echo $auth_method !== 'github_app' ? 'style="display:none;"' : ''; ?>>
                                    <th scope="row"><?php _e('Installation ID', 'wp-github-sync'); ?></th>
                                    <td>
                                        <?php $installation_id = get_option('wp_github_sync_github_app_installation_id', ''); ?>
                                        <input type="text" name="wp_github_sync_github_app_installation_id" id="wp_github_sync_github_app_installation_id" value="<?php echo esc_attr($installation_id); ?>" class="regular-text">
                                        <p class="description"><?php _e('The Installation ID for this repository. Found in the URL when viewing the installation.', 'wp-github-sync'); ?></p>
                                    </td>
                                </tr>
                                 <tr class="auth-field auth-field-github_app" <?php echo $auth_method !== 'github_app' ? 'style="display:none;"' : ''; ?>>
                                    <th scope="row"><?php _e('Private Key', 'wp-github-sync'); ?></th>
                                    <td>
                                        <?php
                                        $key = get_option('wp_github_sync_github_app_key', '');
                                        $display_value = !empty($key) ? '********' : ''; // Mask if set
                                        ?>
                                        <textarea name="wp_github_sync_github_app_key" id="wp_github_sync_github_app_key" rows="6" cols="50" class="code" placeholder="<?php esc_attr_e('Paste your private key here (including -----BEGIN... and -----END...)', 'wp-github-sync'); ?>"><?php echo esc_textarea($display_value === '********' ? '' : $key); // Show empty if masked, otherwise show raw key for editing? Or always mask? Let's show empty if masked. ?></textarea>
                                        <p class="description"><?php _e('The private key file content (.pem) from your GitHub App. Include the BEGIN and END lines.', 'wp-github-sync'); ?></p>
                                        <button type="button" class="button wp-github-sync-test-github-app"><?php _e('Test GitHub App Connection', 'wp-github-sync'); ?></button>
                                        <div id="github-app-connection-status"></div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Sync tab content -->
                    <div id="sync-tab-content" class="wp-github-sync-tab-content" data-tab="sync">
                        <h3><?php _e('Synchronization Settings', 'wp-github-sync'); ?></h3>
                        <p><?php _e('Configure how and when your WordPress site checks for updates from GitHub.', 'wp-github-sync'); ?></p>
                        
                        <?php // settings_fields() called once at the top of the form ?>
                        
                        <table class="form-table" role="presentation">
                            <tbody>
                                <tr>
                                    <th scope="row"><?php _e('Auto Sync', 'wp-github-sync'); ?></th>
                                    <td>
                                        <?php $auto_sync = get_option('wp_github_sync_auto_sync', false); ?>
                                        <label>
                                            <input type="checkbox" name="wp_github_sync_auto_sync" value="1" <?php checked($auto_sync); ?>>
                                            <?php _e('Enable automatic checking for updates from GitHub', 'wp-github-sync'); ?>
                                        </label>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php _e('Auto Sync Interval', 'wp-github-sync'); ?></th>
                                    <td>
                                        <?php $interval = get_option('wp_github_sync_auto_sync_interval', 5); ?>
                                        <input type="number" name="wp_github_sync_auto_sync_interval" value="<?php echo esc_attr($interval); ?>" min="1" max="1440" step="1" class="small-text">
                                        <p class="description"><?php _e('How often to check for updates (in minutes).', 'wp-github-sync'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php _e('Auto Deploy', 'wp-github-sync'); ?></th>
                                    <td>
                                        <?php $auto_deploy = get_option('wp_github_sync_auto_deploy', false); ?>
                                        <label>
                                            <input type="checkbox" name="wp_github_sync_auto_deploy" value="1" <?php checked($auto_deploy); ?>>
                                            <?php _e('Automatically deploy updates when they are found', 'wp-github-sync'); ?>
                                        </label>
                                        <p class="description"><?php _e('Warning: This will deploy changes without requiring manual approval.', 'wp-github-sync'); ?></p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        
                        <h3><?php _e('Webhook Settings', 'wp-github-sync'); ?></h3>
                        <p><?php _e('Configure GitHub webhook settings to trigger deployments when changes are pushed to your repository.', 'wp-github-sync'); ?></p>
                        
                        <?php 
                        // Generate the webhook URL
                        $webhook_url = rest_url('wp-github-sync/v1/webhook');
                        echo '<p><strong>' . __('Webhook URL:', 'wp-github-sync') . '</strong> <code>' . esc_html($webhook_url) . '</code></p>';
                        
                        // Generate the webhook secret if it doesn't exist
                        $webhook_secret = get_option('wp_github_sync_webhook_secret', '');
                        if (empty($webhook_secret)) {
                            $webhook_secret = wp_github_sync_generate_webhook_secret();
                            update_option('wp_github_sync_webhook_secret', $webhook_secret);
                        }
                        ?>
                        
                        <table class="form-table" role="presentation">
                            <tbody>
                                <tr>
                                    <th scope="row"><?php _e('Webhook Deploy', 'wp-github-sync'); ?></th>
                                    <td>
                                        <?php $webhook_deploy = get_option('wp_github_sync_webhook_deploy', true); ?>
                                        <label>
                                            <input type="checkbox" name="wp_github_sync_webhook_deploy" value="1" <?php checked($webhook_deploy); ?>>
                                            <?php _e('Enable deployments triggered by GitHub webhooks', 'wp-github-sync'); ?>
                                        </label>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php _e('Webhook Secret', 'wp-github-sync'); ?></th>
                                    <td>
                                        <input type="text" name="wp_github_sync_webhook_secret" id="wp_github_sync_webhook_secret" value="<?php echo esc_attr($webhook_secret); ?>" class="regular-text">
                                        <button type="button" class="button wp-github-sync-copy-webhook"><?php _e('Copy', 'wp-github-sync'); ?></button>
                                        <button type="button" class="button wp-github-sync-regenerate-webhook"><?php _e('Regenerate', 'wp-github-sync'); ?></button>
                                        <p class="description"><?php _e('Secret token to validate webhook requests from GitHub.', 'wp-github-sync'); ?></p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Advanced tab content -->
                    <div id="advanced-tab-content" class="wp-github-sync-tab-content" data-tab="advanced">
                        <h3><?php _e('Advanced Settings', 'wp-github-sync'); ?></h3>
                        <p><?php _e('Configure advanced deployment options.', 'wp-github-sync'); ?></p>
                        
                        <?php // settings_fields() called once at the top of the form ?>
                        
                        <table class="form-table" role="presentation">
                            <tbody>
                                <tr>
                                    <th scope="row"><?php _e('Create Backup', 'wp-github-sync'); ?></th>
                                    <td>
                                        <?php $create_backup = get_option('wp_github_sync_create_backup', true); ?>
                                        <label>
                                            <input type="checkbox" name="wp_github_sync_create_backup" value="1" <?php checked($create_backup); ?>>
                                            <?php _e('Create a backup before deploying updates', 'wp-github-sync'); ?>
                                        </label>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php _e('Backup wp-config.php', 'wp-github-sync'); ?></th>
                                    <td>
                                        <?php $backup_config = get_option('wp_github_sync_backup_config', false); ?>
                                        <label>
                                            <input type="checkbox" name="wp_github_sync_backup_config" value="1" <?php checked($backup_config); ?>>
                                            <?php _e('Include wp-config.php in backups', 'wp-github-sync'); ?>
                                        </label>
                                        <p class="description"><?php _e('Warning: wp-config.php contains sensitive information.', 'wp-github-sync'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php _e('Maintenance Mode', 'wp-github-sync'); ?></th>
                                    <td>
                                        <?php $maintenance_mode = get_option('wp_github_sync_maintenance_mode', true); ?>
                                        <label>
                                            <input type="checkbox" name="wp_github_sync_maintenance_mode" value="1" <?php checked($maintenance_mode); ?>>
                                            <?php _e('Enable maintenance mode during deployments', 'wp-github-sync'); ?>
                                        </label>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php _e('Email Notifications', 'wp-github-sync'); ?></th>
                                    <td>
                                        <?php $notify_updates = get_option('wp_github_sync_notify_updates', false); ?>
                                        <label>
                                            <input type="checkbox" name="wp_github_sync_notify_updates" value="1" <?php checked($notify_updates); ?>>
                                            <?php _e('Send email notifications when updates are available', 'wp-github-sync'); ?>
                                        </label>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php _e('Delete Removed Files', 'wp-github-sync'); ?></th>
                                    <td>
                                        <?php $delete_removed = get_option('wp_github_sync_delete_removed', true); ?>
                                        <label>
                                            <input type="checkbox" name="wp_github_sync_delete_removed" value="1" <?php checked($delete_removed); ?>>
                                            <?php _e('Delete files that were removed from the repository', 'wp-github-sync'); ?>
                                        </label>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
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

    <?php // Inline script removed, will be enqueued separately ?>
</div>
