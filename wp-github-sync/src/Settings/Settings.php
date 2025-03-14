<?php
/**
 * The settings functionality of the plugin.
 *
 * @package WPGitHubSync
 */

namespace WPGitHubSync\Settings;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Settings class.
 */
class Settings {

    /**
     * Register all settings for the plugin.
     */
    public function register_settings() {
        // Register the settings
        register_setting(
            'wp_github_sync_settings',
            'wp_github_sync_repository',
            array(
                'sanitize_callback' => 'sanitize_text_field',
            )
        );

        register_setting(
            'wp_github_sync_settings',
            'wp_github_sync_auth_method',
            array(
                'sanitize_callback' => 'sanitize_text_field',
                'default' => 'pat',
            )
        );
        
        // GitHub App settings
        register_setting(
            'wp_github_sync_settings',
            'wp_github_sync_github_app_id',
            array(
                'sanitize_callback' => 'sanitize_text_field',
            )
        );
        
        register_setting(
            'wp_github_sync_settings',
            'wp_github_sync_github_app_installation_id',
            array(
                'sanitize_callback' => 'sanitize_text_field',
            )
        );
        
        register_setting(
            'wp_github_sync_settings',
            'wp_github_sync_github_app_key',
            array(
                'sanitize_callback' => array($this, 'sanitize_app_key'),
            )
        );

        register_setting(
            'wp_github_sync_settings',
            'wp_github_sync_access_token',
            array(
                'sanitize_callback' => array($this, 'sanitize_token'),
            )
        );

        register_setting(
            'wp_github_sync_settings',
            'wp_github_sync_branch',
            array(
                'sanitize_callback' => 'sanitize_text_field',
                'default' => 'main',
            )
        );

        register_setting(
            'wp_github_sync_settings',
            'wp_github_sync_auto_sync',
            array(
                'sanitize_callback' => 'rest_sanitize_boolean',
                'default' => false,
            )
        );

        register_setting(
            'wp_github_sync_settings',
            'wp_github_sync_auto_sync_interval',
            array(
                'sanitize_callback' => 'absint',
                'default' => 5,
            )
        );

        register_setting(
            'wp_github_sync_settings',
            'wp_github_sync_auto_deploy',
            array(
                'sanitize_callback' => 'rest_sanitize_boolean',
                'default' => false,
            )
        );

        register_setting(
            'wp_github_sync_settings',
            'wp_github_sync_webhook_deploy',
            array(
                'sanitize_callback' => 'rest_sanitize_boolean',
                'default' => true,
            )
        );

        register_setting(
            'wp_github_sync_settings',
            'wp_github_sync_webhook_secret',
            array(
                'sanitize_callback' => 'sanitize_text_field',
            )
        );

        register_setting(
            'wp_github_sync_settings',
            'wp_github_sync_create_backup',
            array(
                'sanitize_callback' => 'rest_sanitize_boolean',
                'default' => true,
            )
        );

        register_setting(
            'wp_github_sync_settings',
            'wp_github_sync_backup_config',
            array(
                'sanitize_callback' => 'rest_sanitize_boolean',
                'default' => false,
            )
        );

        register_setting(
            'wp_github_sync_settings',
            'wp_github_sync_maintenance_mode',
            array(
                'sanitize_callback' => 'rest_sanitize_boolean',
                'default' => true,
            )
        );

        register_setting(
            'wp_github_sync_settings',
            'wp_github_sync_notify_updates',
            array(
                'sanitize_callback' => 'rest_sanitize_boolean',
                'default' => false,
            )
        );

        register_setting(
            'wp_github_sync_settings',
            'wp_github_sync_delete_removed',
            array(
                'sanitize_callback' => 'rest_sanitize_boolean',
                'default' => true,
            )
        );

        // Add settings sections
        add_settings_section(
            'wp_github_sync_repository_section',
            __('Repository Settings', 'wp-github-sync'),
            array($this, 'render_repository_section'),
            'wp_github_sync_settings'
        );

        add_settings_section(
            'wp_github_sync_sync_section',
            __('Sync Settings', 'wp-github-sync'),
            array($this, 'render_sync_section'),
            'wp_github_sync_settings'
        );

        add_settings_section(
            'wp_github_sync_webhook_section',
            __('Webhook Settings', 'wp-github-sync'),
            array($this, 'render_webhook_section'),
            'wp_github_sync_settings'
        );

        add_settings_section(
            'wp_github_sync_deployment_section',
            __('Deployment Settings', 'wp-github-sync'),
            array($this, 'render_deployment_section'),
            'wp_github_sync_settings'
        );

        // Add settings fields
        add_settings_field(
            'wp_github_sync_repository',
            __('GitHub Repository URL', 'wp-github-sync'),
            array($this, 'render_repository_field'),
            'wp_github_sync_settings',
            'wp_github_sync_repository_section'
        );

        add_settings_field(
            'wp_github_sync_auth_method',
            __('Authentication Method', 'wp-github-sync'),
            array($this, 'render_auth_method_field'),
            'wp_github_sync_settings',
            'wp_github_sync_repository_section'
        );

        add_settings_field(
            'wp_github_sync_access_token',
            __('GitHub Access Token', 'wp-github-sync'),
            array($this, 'render_access_token_field'),
            'wp_github_sync_settings',
            'wp_github_sync_repository_section',
            ['class' => 'auth-field auth-field-pat auth-field-oauth']
        );
        
        // GitHub App fields
        add_settings_field(
            'wp_github_sync_github_app_id',
            __('GitHub App ID', 'wp-github-sync'),
            array($this, 'render_github_app_id_field'),
            'wp_github_sync_settings',
            'wp_github_sync_repository_section',
            ['class' => 'auth-field auth-field-github_app']
        );
        
        add_settings_field(
            'wp_github_sync_github_app_installation_id',
            __('Installation ID', 'wp-github-sync'),
            array($this, 'render_github_app_installation_id_field'),
            'wp_github_sync_settings',
            'wp_github_sync_repository_section',
            ['class' => 'auth-field auth-field-github_app']
        );
        
        add_settings_field(
            'wp_github_sync_github_app_key',
            __('Private Key', 'wp-github-sync'),
            array($this, 'render_github_app_key_field'),
            'wp_github_sync_settings',
            'wp_github_sync_repository_section',
            ['class' => 'auth-field auth-field-github_app']
        );

        add_settings_field(
            'wp_github_sync_branch',
            __('Branch', 'wp-github-sync'),
            array($this, 'render_branch_field'),
            'wp_github_sync_settings',
            'wp_github_sync_repository_section'
        );

        add_settings_field(
            'wp_github_sync_auto_sync',
            __('Auto Sync', 'wp-github-sync'),
            array($this, 'render_auto_sync_field'),
            'wp_github_sync_settings',
            'wp_github_sync_sync_section'
        );

        add_settings_field(
            'wp_github_sync_auto_sync_interval',
            __('Auto Sync Interval', 'wp-github-sync'),
            array($this, 'render_auto_sync_interval_field'),
            'wp_github_sync_settings',
            'wp_github_sync_sync_section'
        );

        add_settings_field(
            'wp_github_sync_auto_deploy',
            __('Auto Deploy', 'wp-github-sync'),
            array($this, 'render_auto_deploy_field'),
            'wp_github_sync_settings',
            'wp_github_sync_sync_section'
        );

        add_settings_field(
            'wp_github_sync_webhook_deploy',
            __('Webhook Deploy', 'wp-github-sync'),
            array($this, 'render_webhook_deploy_field'),
            'wp_github_sync_settings',
            'wp_github_sync_webhook_section'
        );

        add_settings_field(
            'wp_github_sync_webhook_secret',
            __('Webhook Secret', 'wp-github-sync'),
            array($this, 'render_webhook_secret_field'),
            'wp_github_sync_settings',
            'wp_github_sync_webhook_section'
        );

        add_settings_field(
            'wp_github_sync_create_backup',
            __('Create Backup', 'wp-github-sync'),
            array($this, 'render_create_backup_field'),
            'wp_github_sync_settings',
            'wp_github_sync_deployment_section'
        );

        add_settings_field(
            'wp_github_sync_backup_config',
            __('Backup wp-config.php', 'wp-github-sync'),
            array($this, 'render_backup_config_field'),
            'wp_github_sync_settings',
            'wp_github_sync_deployment_section'
        );

        add_settings_field(
            'wp_github_sync_maintenance_mode',
            __('Maintenance Mode', 'wp-github-sync'),
            array($this, 'render_maintenance_mode_field'),
            'wp_github_sync_settings',
            'wp_github_sync_deployment_section'
        );

        add_settings_field(
            'wp_github_sync_notify_updates',
            __('Email Notifications', 'wp-github-sync'),
            array($this, 'render_notify_updates_field'),
            'wp_github_sync_settings',
            'wp_github_sync_deployment_section'
        );

        add_settings_field(
            'wp_github_sync_delete_removed',
            __('Delete Removed Files', 'wp-github-sync'),
            array($this, 'render_delete_removed_field'),
            'wp_github_sync_settings',
            'wp_github_sync_deployment_section'
        );
    }

    /**
     * Sanitize token input.
     *
     * @param string $token The token to sanitize.
     * @return string The sanitized token.
     */
    public function sanitize_token($token) {
        // If the token is the masked placeholder, get the existing value
        if ($token === '********') {
            return get_option('wp_github_sync_access_token', '');
        }

        // Encrypt new token
        $encrypted_token = wp_github_sync_encrypt($token);
        return $encrypted_token ?: '';
    }
    
    /**
     * Sanitize GitHub App private key.
     *
     * @param string $key The private key to sanitize.
     * @return string The sanitized and encrypted key.
     */
    public function sanitize_app_key($key) {
        // If the key is the masked placeholder, get the existing value
        if ($key === '********') {
            return get_option('wp_github_sync_github_app_key', '');
        }
        
        // Validate the key format (should start with "-----BEGIN RSA PRIVATE KEY-----")
        if (!empty($key) && strpos($key, '-----BEGIN') === false) {
            add_settings_error(
                'wp_github_sync_github_app_key',
                'invalid_key_format',
                __('The GitHub App private key appears to be invalid. It should include the BEGIN and END lines.', 'wp-github-sync')
            );
            return '';
        }

        // Encrypt the private key
        $encrypted_key = wp_github_sync_encrypt($key);
        return $encrypted_key ?: '';
    }

    /**
     * Render the repository section description.
     */
    public function render_repository_section() {
        echo '<p>' . __('Configure your GitHub repository connection settings.', 'wp-github-sync') . '</p>';
    }

    /**
     * Render the sync section description.
     */
    public function render_sync_section() {
        echo '<p>' . __('Configure how and when your WordPress site checks for updates from GitHub.', 'wp-github-sync') . '</p>';
    }

    /**
     * Render the webhook section description.
     */
    public function render_webhook_section() {
        echo '<p>' . __('Configure GitHub webhook settings to trigger deployments when changes are pushed to your repository.', 'wp-github-sync') . '</p>';
        
        // Generate the webhook URL
        $webhook_url = rest_url('wp-github-sync/v1/webhook');
        echo '<p><strong>' . __('Webhook URL:', 'wp-github-sync') . '</strong> <code>' . esc_html($webhook_url) . '</code></p>';
        
        // Generate the webhook secret if it doesn't exist
        $webhook_secret = get_option('wp_github_sync_webhook_secret', '');
        if (empty($webhook_secret)) {
            $webhook_secret = wp_github_sync_generate_webhook_secret();
            update_option('wp_github_sync_webhook_secret', $webhook_secret);
        }
    }

    /**
     * Render the deployment section description.
     */
    public function render_deployment_section() {
        echo '<p>' . __('Configure how deployments are handled.', 'wp-github-sync') . '</p>';
    }

    /**
     * Render the repository field.
     */
    public function render_repository_field() {
        $repository = get_option('wp_github_sync_repository', '');
        ?>
        <input type="text" name="wp_github_sync_repository" value="<?php echo esc_attr($repository); ?>" class="regular-text">
        <p class="description"><?php _e('Enter the GitHub repository URL (e.g., https://github.com/username/repository).', 'wp-github-sync'); ?></p>
        <?php
    }

    /**
     * Render the authentication method field.
     */
    public function render_auth_method_field() {
        $auth_method = get_option('wp_github_sync_auth_method', 'pat');
        ?>
        <select name="wp_github_sync_auth_method" id="wp_github_sync_auth_method">
            <option value="pat" <?php selected($auth_method, 'pat'); ?>><?php _e('Personal Access Token', 'wp-github-sync'); ?></option>
            <option value="oauth" <?php selected($auth_method, 'oauth'); ?>><?php _e('OAuth Token', 'wp-github-sync'); ?></option>
            <option value="github_app" <?php selected($auth_method, 'github_app'); ?>><?php _e('GitHub App (Recommended)', 'wp-github-sync'); ?></option>
        </select>
        <p class="description"><?php _e('Select the authentication method to use with GitHub.', 'wp-github-sync'); ?></p>
        <div class="auth-description-pat auth-description" <?php echo $auth_method !== 'pat' ? 'style="display:none;"' : ''; ?>>
            <p class="auth-info">
                <?php _e('Personal Access Tokens are the simplest way to authenticate. GitHub now offers two types:', 'wp-github-sync'); ?>
                <ul style="margin-left: 20px; list-style-type: disc;">
                    <li><?php _e('<strong>Fine-grained tokens</strong> (recommended): More secure with precise permissions. <a href="https://github.com/settings/tokens?type=beta" target="_blank">Create one here</a> with <code>Contents</code> read/write access.', 'wp-github-sync'); ?></li>
                    <li><?php _e('<strong>Classic tokens</strong>: Compatible with older integrations. <a href="https://github.com/settings/tokens" target="_blank">Create one here</a> with the <code>repo</code> scope.', 'wp-github-sync'); ?></li>
                </ul>
            </p>
        </div>
        <div class="auth-description-oauth auth-description" <?php echo $auth_method !== 'oauth' ? 'style="display:none;"' : ''; ?>>
            <p class="auth-info">
                <?php _e('OAuth authentication requires creating an OAuth App in GitHub and configuring redirect URLs. This is more complex but provides a better user experience.', 'wp-github-sync'); ?>
            </p>
        </div>
        <div class="auth-description-github_app auth-description" <?php echo $auth_method !== 'github_app' ? 'style="display:none;"' : ''; ?>>
            <p class="auth-info">
                <?php _e('GitHub Apps are the officially recommended way to integrate with GitHub. Benefits include:', 'wp-github-sync'); ?>
                <ul style="margin-left: 20px; list-style-type: disc;">
                    <li><?php _e('<strong>Higher rate limits</strong>: 5,000 requests per hour vs 1,000 for OAuth/PAT', 'wp-github-sync'); ?></li>
                    <li><?php _e('<strong>Fine-grained permissions</strong>: Request only what you need', 'wp-github-sync'); ?></li>
                    <li><?php _e('<strong>Installation-based</strong>: Users control which repositories you can access', 'wp-github-sync'); ?></li>
                </ul>
                <a href="https://docs.github.com/en/apps/creating-github-apps/creating-github-apps/creating-a-github-app" target="_blank" style="margin-top: 10px; display: inline-block;"><?php _e('Learn how to create a GitHub App', 'wp-github-sync'); ?></a>
            </p>
        </div>
        <script>
        jQuery(document).ready(function($) {
            // Show/hide appropriate description based on selection
            $('#wp_github_sync_auth_method').on('change', function() {
                $('.auth-description').hide();
                $('.auth-description-' + $(this).val()).show();
            });
        });
        </script>
        <?php
    }

    /**
     * Render the access token field.
     */
    public function render_access_token_field() {
        $token = get_option('wp_github_sync_access_token', '');
        $display_value = !empty($token) ? '********' : '';
        ?>
        <input type="password" name="wp_github_sync_access_token" id="wp_github_sync_access_token" value="<?php echo esc_attr($display_value); ?>" class="regular-text">
        <button type="button" class="button wp-github-sync-test-connection"><?php _e('Test Connection', 'wp-github-sync'); ?></button>
        <div id="github-connection-status"></div>
        <p class="description"><?php _e('Enter your GitHub access token with repo scope permissions.', 'wp-github-sync'); ?></p>
        <?php
    }
    
    /**
     * Render the GitHub App ID field.
     */
    public function render_github_app_id_field() {
        $app_id = get_option('wp_github_sync_github_app_id', '');
        ?>
        <input type="text" name="wp_github_sync_github_app_id" id="wp_github_sync_github_app_id" value="<?php echo esc_attr($app_id); ?>" class="regular-text">
        <p class="description"><?php _e('The GitHub App ID from your GitHub App settings.', 'wp-github-sync'); ?></p>
        <?php
    }
    
    /**
     * Render the GitHub App Installation ID field.
     */
    public function render_github_app_installation_id_field() {
        $installation_id = get_option('wp_github_sync_github_app_installation_id', '');
        ?>
        <input type="text" name="wp_github_sync_github_app_installation_id" id="wp_github_sync_github_app_installation_id" value="<?php echo esc_attr($installation_id); ?>" class="regular-text">
        <p class="description"><?php _e('The Installation ID for this repository. Found in the URL when viewing the installation.', 'wp-github-sync'); ?></p>
        <?php
    }
    
    /**
     * Render the GitHub App Private Key field.
     */
    public function render_github_app_key_field() {
        $key = get_option('wp_github_sync_github_app_key', '');
        $display_value = !empty($key) ? '********' : '';
        ?>
        <textarea name="wp_github_sync_github_app_key" id="wp_github_sync_github_app_key" rows="6" cols="50" class="code"><?php echo esc_textarea($display_value === '********' ? $display_value : ''); ?></textarea>
        <p class="description"><?php _e('The private key file content from your GitHub App. Include the BEGIN and END lines.', 'wp-github-sync'); ?></p>
        <button type="button" class="button wp-github-sync-test-github-app"><?php _e('Test GitHub App', 'wp-github-sync'); ?></button>
        <div id="github-app-connection-status"></div>
        <?php
    }

    /**
     * Render the branch field.
     */
    public function render_branch_field() {
        $branch = get_option('wp_github_sync_branch', 'main');
        ?>
        <input type="text" name="wp_github_sync_branch" value="<?php echo esc_attr($branch); ?>" class="regular-text">
        <p class="description"><?php _e('Enter the branch to sync with (e.g., main).', 'wp-github-sync'); ?></p>
        <?php
    }

    /**
     * Render the auto sync field.
     */
    public function render_auto_sync_field() {
        $auto_sync = get_option('wp_github_sync_auto_sync', false);
        ?>
        <label>
            <input type="checkbox" name="wp_github_sync_auto_sync" value="1" <?php checked($auto_sync); ?>>
            <?php _e('Enable automatic checking for updates from GitHub', 'wp-github-sync'); ?>
        </label>
        <?php
    }

    /**
     * Render the auto sync interval field.
     */
    public function render_auto_sync_interval_field() {
        $interval = get_option('wp_github_sync_auto_sync_interval', 5);
        ?>
        <input type="number" name="wp_github_sync_auto_sync_interval" value="<?php echo esc_attr($interval); ?>" min="1" max="1440" step="1" class="small-text">
        <p class="description"><?php _e('How often to check for updates (in minutes).', 'wp-github-sync'); ?></p>
        <?php
    }

    /**
     * Render the auto deploy field.
     */
    public function render_auto_deploy_field() {
        $auto_deploy = get_option('wp_github_sync_auto_deploy', false);
        ?>
        <label>
            <input type="checkbox" name="wp_github_sync_auto_deploy" value="1" <?php checked($auto_deploy); ?>>
            <?php _e('Automatically deploy updates when they are found', 'wp-github-sync'); ?>
        </label>
        <p class="description"><?php _e('Warning: This will deploy changes without requiring manual approval.', 'wp-github-sync'); ?></p>
        <?php
    }

    /**
     * Render the webhook deploy field.
     */
    public function render_webhook_deploy_field() {
        $webhook_deploy = get_option('wp_github_sync_webhook_deploy', true);
        ?>
        <label>
            <input type="checkbox" name="wp_github_sync_webhook_deploy" value="1" <?php checked($webhook_deploy); ?>>
            <?php _e('Enable deployments triggered by GitHub webhooks', 'wp-github-sync'); ?>
        </label>
        <?php
    }

    /**
     * Render the webhook secret field.
     */
    public function render_webhook_secret_field() {
        $webhook_secret = get_option('wp_github_sync_webhook_secret', '');
        ?>
        <input type="text" name="wp_github_sync_webhook_secret" value="<?php echo esc_attr($webhook_secret); ?>" class="regular-text">
        <p class="description"><?php _e('Secret token to validate webhook requests from GitHub.', 'wp-github-sync'); ?></p>
        <?php
    }

    /**
     * Render the create backup field.
     */
    public function render_create_backup_field() {
        $create_backup = get_option('wp_github_sync_create_backup', true);
        ?>
        <label>
            <input type="checkbox" name="wp_github_sync_create_backup" value="1" <?php checked($create_backup); ?>>
            <?php _e('Create a backup before deploying updates', 'wp-github-sync'); ?>
        </label>
        <?php
    }

    /**
     * Render the backup config field.
     */
    public function render_backup_config_field() {
        $backup_config = get_option('wp_github_sync_backup_config', false);
        ?>
        <label>
            <input type="checkbox" name="wp_github_sync_backup_config" value="1" <?php checked($backup_config); ?>>
            <?php _e('Include wp-config.php in backups', 'wp-github-sync'); ?>
        </label>
        <p class="description"><?php _e('Warning: wp-config.php contains sensitive information.', 'wp-github-sync'); ?></p>
        <?php
    }

    /**
     * Render the maintenance mode field.
     */
    public function render_maintenance_mode_field() {
        $maintenance_mode = get_option('wp_github_sync_maintenance_mode', true);
        ?>
        <label>
            <input type="checkbox" name="wp_github_sync_maintenance_mode" value="1" <?php checked($maintenance_mode); ?>>
            <?php _e('Enable maintenance mode during deployments', 'wp-github-sync'); ?>
        </label>
        <?php
    }

    /**
     * Render the notify updates field.
     */
    public function render_notify_updates_field() {
        $notify_updates = get_option('wp_github_sync_notify_updates', false);
        ?>
        <label>
            <input type="checkbox" name="wp_github_sync_notify_updates" value="1" <?php checked($notify_updates); ?>>
            <?php _e('Send email notifications when updates are available', 'wp-github-sync'); ?>
        </label>
        <?php
    }

    /**
     * Render the delete removed field.
     */
    public function render_delete_removed_field() {
        $delete_removed = get_option('wp_github_sync_delete_removed', true);
        ?>
        <label>
            <input type="checkbox" name="wp_github_sync_delete_removed" value="1" <?php checked($delete_removed); ?>>
            <?php _e('Delete files that were removed from the repository', 'wp-github-sync'); ?>
        </label>
        <?php
    }
}