<?php
/**
 * Settings manager for the WordPress GitHub Sync plugin.
 *
 * @package WPGitHubSync
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Settings management class.
 */
class WP_GitHub_Sync_Settings {

    /**
     * Register all settings for the plugin.
     */
    public function register_settings() {
        // Register settings
        register_setting(
            'wp_github_sync_settings',
            'wp_github_sync_repository',
            array(
                'sanitize_callback' => array($this, 'sanitize_repository_url'),
                'default' => '',
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
            'wp_github_sync_auth_method',
            array(
                'sanitize_callback' => array($this, 'sanitize_auth_method'),
                'default' => 'pat',
            )
        );
        
        register_setting(
            'wp_github_sync_settings',
            'wp_github_sync_access_token',
            array(
                'sanitize_callback' => array($this, 'encrypt_token'),
                'default' => '',
            )
        );
        
        register_setting(
            'wp_github_sync_settings',
            'wp_github_sync_oauth_token',
            array(
                'sanitize_callback' => array($this, 'encrypt_token'),
                'default' => '',
            )
        );
        
        register_setting(
            'wp_github_sync_settings',
            'wp_github_sync_webhook_secret',
            array(
                'sanitize_callback' => 'sanitize_text_field',
                'default' => wp_github_sync_generate_webhook_secret(),
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
                'sanitize_callback' => array($this, 'sanitize_interval'),
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
            'wp_github_sync_create_backup',
            array(
                'sanitize_callback' => 'rest_sanitize_boolean',
                'default' => true,
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
            'wp_github_sync_backup_config',
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
        
        register_setting(
            'wp_github_sync_settings',
            'wp_github_sync_notify_updates',
            array(
                'sanitize_callback' => 'rest_sanitize_boolean',
                'default' => false,
            )
        );
        
        // Register settings sections
        add_settings_section(
            'wp_github_sync_repository_section',
            __('Repository Settings', 'wp-github-sync'),
            array($this, 'render_repository_section'),
            'wp_github_sync_settings'
        );
        
        add_settings_section(
            'wp_github_sync_auth_section',
            __('GitHub Authentication', 'wp-github-sync'),
            array($this, 'render_auth_section'),
            'wp_github_sync_settings'
        );
        
        add_settings_section(
            'wp_github_sync_deployment_section',
            __('Deployment Settings', 'wp-github-sync'),
            array($this, 'render_deployment_section'),
            'wp_github_sync_settings'
        );
        
        add_settings_section(
            'wp_github_sync_advanced_section',
            __('Advanced Settings', 'wp-github-sync'),
            array($this, 'render_advanced_section'),
            'wp_github_sync_settings'
        );
        
        // Register settings fields
        add_settings_field(
            'wp_github_sync_repository',
            __('GitHub Repository URL', 'wp-github-sync'),
            array($this, 'render_repository_field'),
            'wp_github_sync_settings',
            'wp_github_sync_repository_section'
        );
        
        add_settings_field(
            'wp_github_sync_branch',
            __('Repository Branch', 'wp-github-sync'),
            array($this, 'render_branch_field'),
            'wp_github_sync_settings',
            'wp_github_sync_repository_section'
        );
        
        add_settings_field(
            'wp_github_sync_auth_method',
            __('Authentication Method', 'wp-github-sync'),
            array($this, 'render_auth_method_field'),
            'wp_github_sync_settings',
            'wp_github_sync_auth_section'
        );
        
        add_settings_field(
            'wp_github_sync_access_token',
            __('Personal Access Token', 'wp-github-sync'),
            array($this, 'render_access_token_field'),
            'wp_github_sync_settings',
            'wp_github_sync_auth_section'
        );
        
        add_settings_field(
            'wp_github_sync_webhook_secret',
            __('Webhook Secret', 'wp-github-sync'),
            array($this, 'render_webhook_secret_field'),
            'wp_github_sync_settings',
            'wp_github_sync_auth_section'
        );
        
        add_settings_field(
            'wp_github_sync_auto_sync',
            __('Enable Auto Sync', 'wp-github-sync'),
            array($this, 'render_auto_sync_field'),
            'wp_github_sync_settings',
            'wp_github_sync_deployment_section'
        );
        
        add_settings_field(
            'wp_github_sync_auto_sync_interval',
            __('Auto Sync Interval (minutes)', 'wp-github-sync'),
            array($this, 'render_auto_sync_interval_field'),
            'wp_github_sync_settings',
            'wp_github_sync_deployment_section'
        );
        
        add_settings_field(
            'wp_github_sync_auto_deploy',
            __('Auto Deploy Updates', 'wp-github-sync'),
            array($this, 'render_auto_deploy_field'),
            'wp_github_sync_settings',
            'wp_github_sync_deployment_section'
        );
        
        add_settings_field(
            'wp_github_sync_webhook_deploy',
            __('Enable Webhook Deployment', 'wp-github-sync'),
            array($this, 'render_webhook_deploy_field'),
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
            'wp_github_sync_create_backup',
            __('Create Backup Before Deployment', 'wp-github-sync'),
            array($this, 'render_create_backup_field'),
            'wp_github_sync_settings',
            'wp_github_sync_advanced_section'
        );
        
        add_settings_field(
            'wp_github_sync_maintenance_mode',
            __('Use Maintenance Mode During Deployment', 'wp-github-sync'),
            array($this, 'render_maintenance_mode_field'),
            'wp_github_sync_settings',
            'wp_github_sync_advanced_section'
        );
        
        add_settings_field(
            'wp_github_sync_backup_config',
            __('Include wp-config.php in Backups', 'wp-github-sync'),
            array($this, 'render_backup_config_field'),
            'wp_github_sync_settings',
            'wp_github_sync_advanced_section'
        );
        
        add_settings_field(
            'wp_github_sync_delete_removed',
            __('Delete Files Removed From Repository', 'wp-github-sync'),
            array($this, 'render_delete_removed_field'),
            'wp_github_sync_settings',
            'wp_github_sync_advanced_section'
        );
    }

    /**
     * Renders the repository settings section.
     */
    public function render_repository_section() {
        ?>
        <p><?php _e('Connect your WordPress site to a GitHub repository.', 'wp-github-sync'); ?></p>
        <?php
    }

    /**
     * Renders the auth settings section.
     */
    public function render_auth_section() {
        ?>
        <p><?php _e('Authenticate with GitHub to allow the plugin to access your repository.', 'wp-github-sync'); ?></p>
        <?php
    }

    /**
     * Renders the deployment settings section.
     */
    public function render_deployment_section() {
        ?>
        <p><?php _e('Configure how and when to deploy changes from GitHub to your WordPress site.', 'wp-github-sync'); ?></p>
        <?php
    }

    /**
     * Renders the advanced settings section.
     */
    public function render_advanced_section() {
        ?>
        <p><?php _e('Advanced settings for fine-tuning the sync process.', 'wp-github-sync'); ?></p>
        <?php
    }

    /**
     * Renders the repository URL field.
     */
    public function render_repository_field() {
        $repository = get_option('wp_github_sync_repository', '');
        ?>
        <input type="url" id="wp_github_sync_repository" name="wp_github_sync_repository" value="<?php echo esc_attr($repository); ?>" class="regular-text" placeholder="https://github.com/username/repository" />
        <p class="description"><?php _e('Enter the full URL of your GitHub repository, e.g., https://github.com/username/repository', 'wp-github-sync'); ?></p>
        <?php
    }

    /**
     * Renders the branch field.
     */
    public function render_branch_field() {
        $branch = get_option('wp_github_sync_branch', 'main');
        $github_api = new GitHub_API_Client();
        $branches = array();
        $has_error = false;
        
        // Try to get branches if repository is configured
        if (!empty(get_option('wp_github_sync_repository', ''))) {
            $branches_api = $github_api->get_branches();
            
            if (!is_wp_error($branches_api)) {
                foreach ($branches_api as $branch_data) {
                    if (isset($branch_data['name'])) {
                        $branches[] = $branch_data['name'];
                    }
                }
            } else {
                $has_error = true;
            }
        }
        
        if (!empty($branches)) {
            ?>
            <select id="wp_github_sync_branch" name="wp_github_sync_branch">
                <?php foreach ($branches as $branch_name) : ?>
                    <option value="<?php echo esc_attr($branch_name); ?>" <?php selected($branch, $branch_name); ?>>
                        <?php echo esc_html($branch_name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php
        } else {
            ?>
            <input type="text" id="wp_github_sync_branch" name="wp_github_sync_branch" value="<?php echo esc_attr($branch); ?>" class="regular-text" />
            <?php if ($has_error) : ?>
                <p class="description error"><?php _e('Unable to fetch branches. Please check your repository URL and authentication settings.', 'wp-github-sync'); ?></p>
            <?php else : ?>
                <p class="description"><?php _e('Enter the branch to sync (e.g., main, master, dev).', 'wp-github-sync'); ?></p>
            <?php endif; ?>
            <?php
        }
    }

    /**
     * Renders the auth method field.
     */
    public function render_auth_method_field() {
        $auth_method = get_option('wp_github_sync_auth_method', 'pat');
        ?>
        <div class="wp-github-sync-radio-group">
            <label>
                <input type="radio" name="wp_github_sync_auth_method" value="pat" <?php checked($auth_method, 'pat'); ?> />
                <?php _e('Personal Access Token', 'wp-github-sync'); ?>
            </label>
            <label>
                <input type="radio" name="wp_github_sync_auth_method" value="oauth" <?php checked($auth_method, 'oauth'); ?> />
                <?php _e('OAuth Authentication', 'wp-github-sync'); ?>
            </label>
        </div>
        <p class="description"><?php _e('Choose how to authenticate with GitHub.', 'wp-github-sync'); ?></p>
        <?php
    }

    /**
     * Renders the access token field.
     */
    public function render_access_token_field() {
        $auth_method = get_option('wp_github_sync_auth_method', 'pat');
        $token = get_option('wp_github_sync_access_token', '');
        $has_token = !empty($token);
        
        if ($auth_method === 'pat') {
            ?>
            <div class="wp-github-sync-pat-field">
                <input type="password" id="wp_github_sync_access_token" name="wp_github_sync_access_token" value="<?php echo $has_token ? '********' : ''; ?>" class="regular-text" />
                <button type="button" class="button wp-github-sync-reveal-token"><?php _e('Show', 'wp-github-sync'); ?></button>
                <p class="description">
                    <?php
                    printf(
                        __('Create a <a href="%s" target="_blank">Personal Access Token</a> with "repo" scope permissions.', 'wp-github-sync'),
                        'https://github.com/settings/tokens/new'
                    );
                    ?>
                </p>
            </div>
            <?php
        } else {
            // OAuth flow
            $oauth_token = get_option('wp_github_sync_oauth_token', '');
            $has_oauth_token = !empty($oauth_token);
            
            if ($has_oauth_token) {
                ?>
                <div class="wp-github-sync-oauth-field">
                    <p><?php _e('Your site is connected to GitHub via OAuth.', 'wp-github-sync'); ?></p>
                    <button type="button" class="button wp-github-sync-disconnect-oauth"><?php _e('Disconnect', 'wp-github-sync'); ?></button>
                </div>
                <?php
            } else {
                ?>
                <div class="wp-github-sync-oauth-field">
                    <p><?php _e('Connect your site to GitHub using OAuth.', 'wp-github-sync'); ?></p>
                    <button type="button" class="button wp-github-sync-connect-oauth"><?php _e('Connect to GitHub', 'wp-github-sync'); ?></button>
                </div>
                <?php
            }
        }
    }

    /**
     * Renders the webhook secret field.
     */
    public function render_webhook_secret_field() {
        $webhook_secret = get_option('wp_github_sync_webhook_secret', '');
        ?>
        <div class="wp-github-sync-webhook-field">
            <input type="text" id="wp_github_sync_webhook_secret" name="wp_github_sync_webhook_secret" value="<?php echo esc_attr($webhook_secret); ?>" class="regular-text" readonly />
            <button type="button" class="button wp-github-sync-copy-webhook"><?php _e('Copy', 'wp-github-sync'); ?></button>
            <button type="button" class="button wp-github-sync-regenerate-webhook"><?php _e('Regenerate', 'wp-github-sync'); ?></button>
            
            <p class="description">
                <?php
                $webhook_url = get_rest_url(null, 'wp-github-sync/v1/webhook');
                printf(
                    __('Configure a webhook in GitHub with URL: <code>%s</code> and the secret above. <a href="%s" target="_blank">Learn more</a>', 'wp-github-sync'),
                    esc_url($webhook_url),
                    'https://docs.github.com/en/developers/webhooks-and-events/webhooks/creating-webhooks'
                );
                ?>
            </p>
        </div>
        <?php
    }

    /**
     * Renders the auto sync field.
     */
    public function render_auto_sync_field() {
        $auto_sync = get_option('wp_github_sync_auto_sync', false);
        ?>
        <label for="wp_github_sync_auto_sync">
            <input type="checkbox" id="wp_github_sync_auto_sync" name="wp_github_sync_auto_sync" value="1" <?php checked($auto_sync); ?> />
            <?php _e('Periodically check GitHub for updates', 'wp-github-sync'); ?>
        </label>
        <p class="description"><?php _e('When enabled, the plugin will check for updates on GitHub at the interval specified below.', 'wp-github-sync'); ?></p>
        <?php
    }

    /**
     * Renders the auto sync interval field.
     */
    public function render_auto_sync_interval_field() {
        $interval = get_option('wp_github_sync_auto_sync_interval', 5);
        ?>
        <input type="number" id="wp_github_sync_auto_sync_interval" name="wp_github_sync_auto_sync_interval" value="<?php echo esc_attr($interval); ?>" min="1" max="1440" class="small-text" />
        <p class="description"><?php _e('How often WordPress should check for updates (in minutes).', 'wp-github-sync'); ?></p>
        <?php
    }

    /**
     * Renders the auto deploy field.
     */
    public function render_auto_deploy_field() {
        $auto_deploy = get_option('wp_github_sync_auto_deploy', false);
        ?>
        <label for="wp_github_sync_auto_deploy">
            <input type="checkbox" id="wp_github_sync_auto_deploy" name="wp_github_sync_auto_deploy" value="1" <?php checked($auto_deploy); ?> />
            <?php _e('Automatically deploy new updates', 'wp-github-sync'); ?>
        </label>
        <p class="description"><?php _e('When enabled, the plugin will automatically deploy new commits when they are detected. If disabled, you\'ll need to approve deployments manually.', 'wp-github-sync'); ?></p>
        <?php
    }

    /**
     * Renders the webhook deploy field.
     */
    public function render_webhook_deploy_field() {
        $webhook_deploy = get_option('wp_github_sync_webhook_deploy', true);
        ?>
        <label for="wp_github_sync_webhook_deploy">
            <input type="checkbox" id="wp_github_sync_webhook_deploy" name="wp_github_sync_webhook_deploy" value="1" <?php checked($webhook_deploy); ?> />
            <?php _e('Automatically deploy when GitHub sends a webhook', 'wp-github-sync'); ?>
        </label>
        <p class="description"><?php _e('When enabled, the plugin will deploy updates immediately when GitHub sends a webhook notification.', 'wp-github-sync'); ?></p>
        <?php
    }

    /**
     * Renders the notify updates field.
     */
    public function render_notify_updates_field() {
        $notify_updates = get_option('wp_github_sync_notify_updates', false);
        ?>
        <label for="wp_github_sync_notify_updates">
            <input type="checkbox" id="wp_github_sync_notify_updates" name="wp_github_sync_notify_updates" value="1" <?php checked($notify_updates); ?> />
            <?php _e('Send email notifications when updates are available', 'wp-github-sync'); ?>
        </label>
        <p class="description"><?php _e('When enabled, the plugin will send an email to the admin email when new updates are available.', 'wp-github-sync'); ?></p>
        <?php
    }

    /**
     * Renders the create backup field.
     */
    public function render_create_backup_field() {
        $create_backup = get_option('wp_github_sync_create_backup', true);
        ?>
        <label for="wp_github_sync_create_backup">
            <input type="checkbox" id="wp_github_sync_create_backup" name="wp_github_sync_create_backup" value="1" <?php checked($create_backup); ?> />
            <?php _e('Create a backup before deploying updates', 'wp-github-sync'); ?>
        </label>
        <p class="description"><?php _e('When enabled, the plugin will create a backup of your site\'s plugins and themes before deploying updates.', 'wp-github-sync'); ?></p>
        <?php
    }

    /**
     * Renders the maintenance mode field.
     */
    public function render_maintenance_mode_field() {
        $maintenance_mode = get_option('wp_github_sync_maintenance_mode', true);
        ?>
        <label for="wp_github_sync_maintenance_mode">
            <input type="checkbox" id="wp_github_sync_maintenance_mode" name="wp_github_sync_maintenance_mode" value="1" <?php checked($maintenance_mode); ?> />
            <?php _e('Enable maintenance mode during deployments', 'wp-github-sync'); ?>
        </label>
        <p class="description"><?php _e('When enabled, the plugin will put your site in maintenance mode during deployments to prevent errors for visitors.', 'wp-github-sync'); ?></p>
        <?php
    }

    /**
     * Renders the backup config field.
     */
    public function render_backup_config_field() {
        $backup_config = get_option('wp_github_sync_backup_config', false);
        ?>
        <label for="wp_github_sync_backup_config">
            <input type="checkbox" id="wp_github_sync_backup_config" name="wp_github_sync_backup_config" value="1" <?php checked($backup_config); ?> />
            <?php _e('Include wp-config.php in backups', 'wp-github-sync'); ?>
        </label>
        <p class="description"><?php _e('When enabled, wp-config.php will be included in backups. Note: This file contains sensitive information like database credentials.', 'wp-github-sync'); ?></p>
        <?php
    }

    /**
     * Renders the delete removed field.
     */
    public function render_delete_removed_field() {
        $delete_removed = get_option('wp_github_sync_delete_removed', true);
        ?>
        <label for="wp_github_sync_delete_removed">
            <input type="checkbox" id="wp_github_sync_delete_removed" name="wp_github_sync_delete_removed" value="1" <?php checked($delete_removed); ?> />
            <?php _e('Delete files removed from repository', 'wp-github-sync'); ?>
        </label>
        <p class="description"><?php _e('When enabled, files that don\'t exist in the repository will be removed from your site during deployments.', 'wp-github-sync'); ?></p>
        <?php
    }

    /**
     * Sanitizes the repository URL.
     *
     * @param string $url The repository URL.
     * @return string The sanitized URL.
     */
    public function sanitize_repository_url($url) {
        $url = esc_url_raw(trim($url));
        
        // Check if URL is valid GitHub URL
        $github_api = new GitHub_API_Client();
        $parsed_url = $github_api->parse_github_url($url);
        
        if (!$parsed_url) {
            add_settings_error(
                'wp_github_sync_repository',
                'invalid_github_url',
                __('Please enter a valid GitHub repository URL.', 'wp-github-sync')
            );
            
            return get_option('wp_github_sync_repository', '');
        }
        
        return $url;
    }

    /**
     * Sanitizes the auth method.
     *
     * @param string $method The auth method.
     * @return string The sanitized auth method.
     */
    public function sanitize_auth_method($method) {
        $method = sanitize_text_field($method);
        
        if (!in_array($method, array('pat', 'oauth'), true)) {
            return 'pat';
        }
        
        return $method;
    }

    /**
     * Encrypts the access token.
     *
     * @param string $token The access token.
     * @return string The encrypted token.
     */
    public function encrypt_token($token) {
        if (empty($token) || $token === '********') {
            // If the token is empty or masked, return the existing value
            return get_option('wp_github_sync_access_token', '');
        }
        
        return wp_github_sync_encrypt($token);
    }

    /**
     * Sanitizes the interval.
     *
     * @param int $interval The interval in minutes.
     * @return int The sanitized interval.
     */
    public function sanitize_interval($interval) {
        $interval = absint($interval);
        
        if ($interval < 1) {
            $interval = 1;
        } elseif ($interval > 1440) {
            $interval = 1440; // Max 24 hours (1440 minutes)
        }
        
        return $interval;
    }
}