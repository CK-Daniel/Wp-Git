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
                    <!-- General tab content -->
                    <div id="general-tab-content" class="wp-github-sync-tab-content active" data-tab="general">
                        <h3><?php _e('Repository Settings', 'wp-github-sync'); ?></h3>
                        <p><?php _e('Configure your GitHub repository connection settings.', 'wp-github-sync'); ?></p>
                        
                        <?php settings_fields('wp_github_sync_settings'); ?>
                        
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
                        
                        <?php settings_fields('wp_github_sync_settings'); ?>
                        
                        <table class="form-table" role="presentation">
                            <tbody>
                                <tr>
                                    <th scope="row"><?php _e('Authentication Method', 'wp-github-sync'); ?></th>
                                    <td>
                                        <?php 
                                        $auth_method = get_option('wp_github_sync_auth_method', 'pat');
                                        ?>
                                        <select name="wp_github_sync_auth_method">
                                            <option value="pat" <?php selected($auth_method, 'pat'); ?>><?php _e('Personal Access Token', 'wp-github-sync'); ?></option>
                                            <option value="oauth" <?php selected($auth_method, 'oauth'); ?>><?php _e('OAuth Token', 'wp-github-sync'); ?></option>
                                        </select>
                                        <p class="description"><?php _e('Select the authentication method to use with GitHub.', 'wp-github-sync'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php _e('GitHub Access Token', 'wp-github-sync'); ?></th>
                                    <td>
                                        <?php
                                        $token = get_option('wp_github_sync_access_token', '');
                                        $display_value = !empty($token) ? '********' : '';
                                        ?>
                                        <input type="password" name="wp_github_sync_access_token" id="wp_github_sync_access_token" value="<?php echo esc_attr($display_value); ?>" class="regular-text">
                                        <button type="button" class="button wp-github-sync-test-connection"><?php _e('Test Connection', 'wp-github-sync'); ?></button>
                                        <div id="github-connection-status"></div>
                                        <p class="description"><?php _e('Enter your GitHub access token with repo scope permissions.', 'wp-github-sync'); ?></p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Sync tab content -->
                    <div id="sync-tab-content" class="wp-github-sync-tab-content" data-tab="sync">
                        <h3><?php _e('Synchronization Settings', 'wp-github-sync'); ?></h3>
                        <p><?php _e('Configure how and when your WordPress site checks for updates from GitHub.', 'wp-github-sync'); ?></p>
                        
                        <?php settings_fields('wp_github_sync_settings'); ?>
                        
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
                        
                        <?php settings_fields('wp_github_sync_settings'); ?>
                        
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
    
    <script>
    jQuery(document).ready(function($) {
        // Toggle authentication fields based on auth method
        function toggleAuthFields() {
            var authMethod = $('#wp_github_sync_auth_method').val();
            $('.auth-field').hide();
            $('.auth-field-' + authMethod).show();
        }
        
        // Initialize auth fields
        toggleAuthFields();
        
        // Listen for auth method changes
        $('#wp_github_sync_auth_method').on('change', toggleAuthFields);
        
        // Tab switching functionality
        $('.wp-github-sync-tab').on('click', function() {
            // Remove active class from all tabs
            $('.wp-github-sync-tab').removeClass('active');
            
            // Add active class to clicked tab
            $(this).addClass('active');
            
            // Get the tab ID
            const tabId = $(this).data('tab');
            
            // Hide all tab content
            $('.wp-github-sync-tab-content').removeClass('active');
            
            // Show the content for the active tab
            $('#' + tabId + '-tab-content').addClass('active');

            // Store active tab in localStorage for persistence
            localStorage.setItem('wpGitHubSyncActiveTab', tabId);
        });
        
        // Set the initial active tab
        function setInitialActiveTab() {
            // First check URL hash
            const hash = window.location.hash.substr(1);
            if (hash && $('.wp-github-sync-tab[data-tab="' + hash + '"]').length) {
                $('.wp-github-sync-tab[data-tab="' + hash + '"]').click();
                return;
            }
            
            // Then check localStorage
            const savedTab = localStorage.getItem('wpGitHubSyncActiveTab');
            if (savedTab && $('.wp-github-sync-tab[data-tab="' + savedTab + '"]').length) {
                $('.wp-github-sync-tab[data-tab="' + savedTab + '"]').click();
                return;
            }
            
            // Default to first tab if nothing else
            $('.wp-github-sync-tab').first().click();
        }
        
        // Initialize tabs on page load
        setInitialActiveTab();
        
        // Initial sync button click handler
        $('#initial_sync_button').on('click', function() {
            const createNewRepo = $('#create_new_repo').is(':checked');
            let repoName = '';
            
            if (createNewRepo) {
                // Use the entered value or the placeholder as fallback
                const inputField = $('#new_repo_name');
                repoName = inputField.val();
                
                // If empty, use the placeholder value
                if (!repoName || repoName.trim() === '') {
                    repoName = inputField.attr('placeholder') || '<?php echo esc_js($default_repo_name); ?>';
                    console.log("Using placeholder as repo name:", repoName);
                }
            }
            
            // Show loading overlay
            $('.wp-github-sync-overlay').show();
            $('.wp-github-sync-loading-message').text('<?php _e('Setting up GitHub Sync...', 'wp-github-sync'); ?>');
            
            if (createNewRepo) {
                $('.wp-github-sync-loading-submessage').text('<?php _e('Creating new repository...', 'wp-github-sync'); ?>');
            } else {
                $('.wp-github-sync-loading-submessage').text('<?php _e('Connecting to existing repository...', 'wp-github-sync'); ?>');
            }
            
            // AJAX call to handle initial sync
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'wp_github_sync_initial_sync',
                    create_new_repo: createNewRepo ? 1 : 0,
                    repo_name: repoName,
                    nonce: wpGitHubSync.initialSyncNonce // Using the specific nonce provided by the Admin class
                },
                success: function(response) {
                    if (response.success) {
                        $('.wp-github-sync-loading-message').text('<?php _e('Success!', 'wp-github-sync'); ?>');
                        $('.wp-github-sync-loading-submessage').text(response.data.message);
                        
                        // Redirect to dashboard after 2 seconds
                        setTimeout(function() {
                            window.location.href = '<?php echo admin_url('admin.php?page=wp-github-sync'); ?>';
                        }, 2000);
                    } else {
                        $('.wp-github-sync-loading-message').text('<?php _e('Error', 'wp-github-sync'); ?>');
                        $('.wp-github-sync-loading-submessage').text(response.data.message);
                        
                        // Hide overlay after 3 seconds
                        setTimeout(function() {
                            $('.wp-github-sync-overlay').hide();
                        }, 3000);
                    }
                },
                error: function(xhr, status, error) {
                    console.error("AJAX Error:", status, error);
                    if (xhr.responseText) {
                        console.error("Response:", xhr.responseText);
                        
                        // Try to parse response for more details
                        try {
                            var responseObj = JSON.parse(xhr.responseText);
                            if (responseObj && responseObj.data && responseObj.data.message) {
                                $('.wp-github-sync-loading-message').text('<?php _e('Error', 'wp-github-sync'); ?>');
                                $('.wp-github-sync-loading-submessage').text(responseObj.data.message);
                            } else {
                                // Look for WordPress critical error
                                if (xhr.responseText.indexOf('<p>There has been a critical error') !== -1) {
                                    $('.wp-github-sync-loading-message').text('<?php _e('Error', 'wp-github-sync'); ?>');
                                    $('.wp-github-sync-loading-submessage').text('<?php _e('WordPress encountered a critical error. Check server logs for details.', 'wp-github-sync'); ?>');
                                } else {
                                    $('.wp-github-sync-loading-message').text('<?php _e('Error', 'wp-github-sync'); ?>');
                                    $('.wp-github-sync-loading-submessage').text('<?php _e('An unexpected error occurred. Please check server logs for details.', 'wp-github-sync'); ?>');
                                }
                            }
                        } catch (e) {
                            // If we can't parse JSON, check for WordPress error page
                            if (xhr.responseText.indexOf('<p>There has been a critical error') !== -1) {
                                $('.wp-github-sync-loading-message').text('<?php _e('Error', 'wp-github-sync'); ?>');
                                $('.wp-github-sync-loading-submessage').text('<?php _e('WordPress encountered a critical error. Check server logs for details.', 'wp-github-sync'); ?>');
                            } else {
                                $('.wp-github-sync-loading-message').text('<?php _e('Error', 'wp-github-sync'); ?>');
                                $('.wp-github-sync-loading-submessage').text('<?php _e('An unexpected error occurred. Please check server logs for details.', 'wp-github-sync'); ?>');
                            }
                        }
                    } else {
                        $('.wp-github-sync-loading-message').text('<?php _e('Error', 'wp-github-sync'); ?>');
                        $('.wp-github-sync-loading-submessage').text('<?php _e('An unexpected error occurred. Please try again.', 'wp-github-sync'); ?>');
                    }
                    
                    // Hide overlay after a longer time so user can read message
                    setTimeout(function() {
                        $('.wp-github-sync-overlay').hide();
                    }, 5000);
                    
                    // Log error to plugin's log file if possible
                    if (typeof wpGitHubSync !== 'undefined' && wpGitHubSync.ajaxUrl) {
                        $.post(wpGitHubSync.ajaxUrl, {
                            action: 'wp_github_sync_log_error',
                            nonce: wpGitHubSync.nonce,
                            error_context: 'Initial sync AJAX error',
                            error_status: status,
                            error_message: error
                        });
                    }
                }
            });
        });
        
        // GitHub App Connection testing
        $('.wp-github-sync-test-github-app').on('click', function() {
            const $statusArea = $('#github-app-connection-status');
            const appId = $('#wp_github_sync_github_app_id').val();
            const installationId = $('#wp_github_sync_github_app_installation_id').val();
            const privateKey = $('#wp_github_sync_github_app_key').val();
            const repoUrl = $('#wp_github_sync_repository').val();
            
            // Don't test with masked key
            if (privateKey === '********') {
                $statusArea.html(
                    '<div class="wp-github-sync-info-box warning" style="margin-top: 10px;">' +
                    '<div class="wp-github-sync-info-box-icon"><span class="dashicons dashicons-warning"></span></div>' +
                    '<div class="wp-github-sync-info-box-content">' +
                    '<p>Please enter your private key. The masked key cannot be used for testing.</p>' +
                    '</div></div>'
                );
                return;
            }
            
            // Check required fields
            if (!appId || !installationId || !privateKey) {
                $statusArea.html(
                    '<div class="wp-github-sync-info-box error" style="margin-top: 10px;">' +
                    '<div class="wp-github-sync-info-box-icon"><span class="dashicons dashicons-no"></span></div>' +
                    '<div class="wp-github-sync-info-box-content">' +
                    '<p>Please fill in all GitHub App fields (App ID, Installation ID, and Private Key).</p>' +
                    '</div></div>'
                );
                return;
            }
            
            // Show testing indicator
            $statusArea.html(
                '<div class="wp-github-sync-info-box info" style="margin-top: 10px;">' +
                '<div class="wp-github-sync-info-box-icon"><span class="dashicons dashicons-update wp-github-sync-spin"></span></div>' +
                '<div class="wp-github-sync-info-box-content">' +
                '<p>Testing GitHub App connection...</p>' +
                '</div></div>'
            );
            
            // Send the AJAX request to test connection
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'wp_github_sync_test_github_app',
                    app_id: appId,
                    installation_id: installationId,
                    private_key: privateKey,
                    repo_url: repoUrl,
                    nonce: '<?php echo wp_create_nonce('wp_github_sync_nonce'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        // Success - GitHub App is valid
                        let message = response.data.message;
                        
                        if (response.data.app_name) {
                            message += ' App name: <strong>' + response.data.app_name + '</strong>.';
                        }
                        
                        if (response.data.repo_info) {
                            message += ' Repository: <strong>' + response.data.repo_info.owner + '/' + response.data.repo_info.repo + '</strong>';
                        }
                        
                        $statusArea.html(
                            '<div class="wp-github-sync-info-box success" style="margin-top: 10px;">' +
                            '<div class="wp-github-sync-info-box-icon"><span class="dashicons dashicons-yes-alt"></span></div>' +
                            '<div class="wp-github-sync-info-box-content">' +
                            '<p>' + message + '</p>' +
                            '</div></div>'
                        );
                    } else {
                        // Error - display the error message
                        $statusArea.html(
                            '<div class="wp-github-sync-info-box error" style="margin-top: 10px;">' +
                            '<div class="wp-github-sync-info-box-icon"><span class="dashicons dashicons-no"></span></div>' +
                            '<div class="wp-github-sync-info-box-content">' +
                            '<p>' + response.data.message + '</p>' +
                            '</div></div>'
                        );
                    }
                },
                error: function() {
                    // AJAX request failed
                    $statusArea.html(
                        '<div class="wp-github-sync-info-box error" style="margin-top: 10px;">' +
                        '<div class="wp-github-sync-info-box-icon"><span class="dashicons dashicons-no"></span></div>' +
                        '<div class="wp-github-sync-info-box-content">' +
                        '<p>Connection test failed. Please try again.</p>' +
                        '</div></div>'
                    );
                }
            });
        });

        // PAT/OAuth Connection testing
        $('.wp-github-sync-test-connection').on('click', function() {
            const $statusArea = $('#github-connection-status');
            const token = $('#wp_github_sync_access_token').val();
            const repoUrl = $('#wp_github_sync_repository').val();
            
            // Don't test with masked token
            if (token === '********') {
                $statusArea.html(
                    '<div class="wp-github-sync-info-box warning" style="margin-top: 10px;">' +
                    '<div class="wp-github-sync-info-box-icon"><span class="dashicons dashicons-warning"></span></div>' +
                    '<div class="wp-github-sync-info-box-content">' +
                    '<p>Please enter your token first. The masked token cannot be used for testing.</p>' +
                    '</div></div>'
                );
                return;
            }
            
            // Show testing indicator
            $statusArea.html(
                '<div class="wp-github-sync-info-box info" style="margin-top: 10px;">' +
                '<div class="wp-github-sync-info-box-icon"><span class="dashicons dashicons-update wp-github-sync-spin"></span></div>' +
                '<div class="wp-github-sync-info-box-content">' +
                '<p>Testing connection to GitHub...</p>' +
                '</div></div>'
            );
            
            // Send the AJAX request to test connection
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'wp_github_sync_test_connection',
                    token: token,
                    repo_url: repoUrl,
                    nonce: '<?php echo wp_create_nonce('wp_github_sync_nonce'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        // Success - credentials and possibly repo are valid
                        let message = response.data.message;
                        
                        if (response.data.username) {
                            message += ' Authenticated as <strong>' + response.data.username + '</strong>.';
                        }
                        
                        if (response.data.repo_info) {
                            message += ' Repository: <strong>' + response.data.repo_info.owner + '/' + response.data.repo_info.repo + '</strong>';
                        }
                        
                        $statusArea.html(
                            '<div class="wp-github-sync-info-box success" style="margin-top: 10px;">' +
                            '<div class="wp-github-sync-info-box-icon"><span class="dashicons dashicons-yes-alt"></span></div>' +
                            '<div class="wp-github-sync-info-box-content">' +
                            '<p>' + message + '</p>' +
                            '</div></div>'
                        );
                    } else {
                        // Error - display the error message
                        $statusArea.html(
                            '<div class="wp-github-sync-info-box error" style="margin-top: 10px;">' +
                            '<div class="wp-github-sync-info-box-icon"><span class="dashicons dashicons-no"></span></div>' +
                            '<div class="wp-github-sync-info-box-content">' +
                            '<p>' + response.data.message + '</p>' +
                            '</div></div>'
                        );
                    }
                },
                error: function() {
                    // AJAX request failed
                    $statusArea.html(
                        '<div class="wp-github-sync-info-box error" style="margin-top: 10px;">' +
                        '<div class="wp-github-sync-info-box-icon"><span class="dashicons dashicons-no"></span></div>' +
                        '<div class="wp-github-sync-info-box-content">' +
                        '<p>Connection test failed. Please try again.</p>' +
                        '</div></div>'
                    );
                }
            });
        });
        
        // Repository creation toggle
        $('#create_new_repo').on('change', function() {
            if ($(this).is(':checked')) {
                $('#new_repo_options').slideDown();
            } else {
                $('#new_repo_options').slideUp();
            }
        });
        
        // Toggle slider click handler
        $('.wp-github-sync-toggle-slider').on('click', function() {
            const checkbox = $(this).siblings('input[type="checkbox"]');
            checkbox.prop('checked', !checkbox.prop('checked')).trigger('change');
        });
        
        // Handle hash changes
        $(window).on('hashchange', function() {
            const hash = window.location.hash.substr(1);
            if (hash && $('.wp-github-sync-tab[data-tab="' + hash + '"]').length) {
                $('.wp-github-sync-tab[data-tab="' + hash + '"]').click();
            }
        });
    });
    </script>
</div>