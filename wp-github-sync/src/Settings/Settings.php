<?php
/**
 * The settings functionality of the plugin.
 * Registers settings and sections, delegates rendering to SettingsRenderer.
 *
 * @package WPGitHubSync
 */

namespace WPGitHubSync\Settings;

// Use the new SettingsRenderer class
use WPGitHubSync\Settings\SettingsRenderer;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Settings class.
 */
class Settings {

    /**
     * Settings Renderer instance.
     * @var SettingsRenderer
     */
    private $renderer;

    /**
     * Constructor. Instantiates the renderer.
     */
    public function __construct() {
        $this->renderer = new SettingsRenderer();
    }

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
                'sanitize_callback' => array($this, 'sanitize_app_key'), // Keep sanitize callback here
            )
        );

        register_setting(
            'wp_github_sync_settings',
            'wp_github_sync_access_token',
            array(
                'sanitize_callback' => array($this, 'sanitize_token'), // Keep sanitize callback here
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

        // New setting for file comparison method
        register_setting(
            'wp_github_sync_settings',
            'wp_github_sync_compare_method',
            array(
                'sanitize_callback' => array($this, 'sanitize_compare_method'), // Keep sanitize callback here
                'default' => 'hash', // Default to the most accurate method
            )
        );


        // Add settings sections using the renderer
        add_settings_section(
            'wp_github_sync_repository_section',
            __('Repository Settings', 'wp-github-sync'),
            array($this->renderer, 'render_repository_section'),
            'wp_github_sync_settings'
        );

        add_settings_section(
            'wp_github_sync_sync_section',
            __('Sync Settings', 'wp-github-sync'),
            array($this->renderer, 'render_sync_section'),
            'wp_github_sync_settings'
        );

        add_settings_section(
            'wp_github_sync_webhook_section',
            __('Webhook Settings', 'wp-github-sync'),
            array($this->renderer, 'render_webhook_section'),
            'wp_github_sync_settings'
        );

        add_settings_section(
            'wp_github_sync_deployment_section',
            __('Deployment Settings', 'wp-github-sync'),
            array($this->renderer, 'render_deployment_section'),
            'wp_github_sync_settings'
        );

        // Add settings fields using the renderer
        add_settings_field(
            'wp_github_sync_repository',
            __('GitHub Repository URL', 'wp-github-sync'),
            array($this->renderer, 'render_repository_field'),
            'wp_github_sync_settings',
            'wp_github_sync_repository_section'
        );

        add_settings_field(
            'wp_github_sync_auth_method',
            __('Authentication Method', 'wp-github-sync'),
            array($this->renderer, 'render_auth_method_field'),
            'wp_github_sync_settings',
            'wp_github_sync_repository_section'
        );

        add_settings_field(
            'wp_github_sync_access_token',
            __('GitHub Access Token', 'wp-github-sync'),
            array($this->renderer, 'render_access_token_field'),
            'wp_github_sync_settings',
            'wp_github_sync_repository_section',
            ['class' => 'auth-field auth-field-pat auth-field-oauth']
        );

        // GitHub App fields
        add_settings_field(
            'wp_github_sync_github_app_id',
            __('GitHub App ID', 'wp-github-sync'),
            array($this->renderer, 'render_github_app_id_field'),
            'wp_github_sync_settings',
            'wp_github_sync_repository_section',
            ['class' => 'auth-field auth-field-github_app']
        );

        add_settings_field(
            'wp_github_sync_github_app_installation_id',
            __('Installation ID', 'wp-github-sync'),
            array($this->renderer, 'render_github_app_installation_id_field'),
            'wp_github_sync_settings',
            'wp_github_sync_repository_section',
            ['class' => 'auth-field auth-field-github_app']
        );

        add_settings_field(
            'wp_github_sync_github_app_key',
            __('Private Key', 'wp-github-sync'),
            array($this->renderer, 'render_github_app_key_field'),
            'wp_github_sync_settings',
            'wp_github_sync_repository_section',
            ['class' => 'auth-field auth-field-github_app']
        );

        add_settings_field(
            'wp_github_sync_branch',
            __('Branch', 'wp-github-sync'),
            array($this->renderer, 'render_branch_field'),
            'wp_github_sync_settings',
            'wp_github_sync_repository_section'
        );

        add_settings_field(
            'wp_github_sync_auto_sync',
            __('Auto Sync', 'wp-github-sync'),
            array($this->renderer, 'render_auto_sync_field'),
            'wp_github_sync_settings',
            'wp_github_sync_sync_section'
        );

        add_settings_field(
            'wp_github_sync_auto_sync_interval',
            __('Auto Sync Interval', 'wp-github-sync'),
            array($this->renderer, 'render_auto_sync_interval_field'),
            'wp_github_sync_settings',
            'wp_github_sync_sync_section'
        );

        add_settings_field(
            'wp_github_sync_auto_deploy',
            __('Auto Deploy', 'wp-github-sync'),
            array($this->renderer, 'render_auto_deploy_field'),
            'wp_github_sync_settings',
            'wp_github_sync_sync_section'
        );

        add_settings_field(
            'wp_github_sync_webhook_deploy',
            __('Webhook Deploy', 'wp-github-sync'),
            array($this->renderer, 'render_webhook_deploy_field'),
            'wp_github_sync_settings',
            'wp_github_sync_webhook_section'
        );

        add_settings_field(
            'wp_github_sync_webhook_secret',
            __('Webhook Secret', 'wp-github-sync'),
            array($this->renderer, 'render_webhook_secret_field'),
            'wp_github_sync_settings',
            'wp_github_sync_webhook_section'
        );

        add_settings_field(
            'wp_github_sync_create_backup',
            __('Create Backup', 'wp-github-sync'),
            array($this->renderer, 'render_create_backup_field'),
            'wp_github_sync_settings',
            'wp_github_sync_deployment_section'
        );

        add_settings_field(
            'wp_github_sync_backup_config',
            __('Backup wp-config.php', 'wp-github-sync'),
            array($this->renderer, 'render_backup_config_field'),
            'wp_github_sync_settings',
            'wp_github_sync_deployment_section'
        );

        add_settings_field(
            'wp_github_sync_maintenance_mode',
            __('Maintenance Mode', 'wp-github-sync'),
            array($this->renderer, 'render_maintenance_mode_field'),
            'wp_github_sync_settings',
            'wp_github_sync_deployment_section'
        );

        add_settings_field(
            'wp_github_sync_notify_updates',
            __('Email Notifications', 'wp-github-sync'),
            array($this->renderer, 'render_notify_updates_field'),
            'wp_github_sync_settings',
            'wp_github_sync_deployment_section'
        );

        add_settings_field(
            'wp_github_sync_delete_removed',
            __('Delete Removed Files', 'wp-github-sync'),
            array($this->renderer, 'render_delete_removed_field'),
            'wp_github_sync_settings',
            'wp_github_sync_deployment_section'
        );

        add_settings_field(
            'wp_github_sync_compare_method',
            __('File Comparison Method', 'wp-github-sync'),
            array($this->renderer, 'render_compare_method_field'),
            'wp_github_sync_settings',
            'wp_github_sync_deployment_section'
        );
    }

    /**
     * Sanitize token input. Encrypts the token if it's not the placeholder.
     *
     * @param string $token The token to sanitize.
     * @return string The sanitized and potentially encrypted token.
     */
    public function sanitize_token($token) {
        // If the token is the masked placeholder, get the existing value
        $existing_token = get_option('wp_github_sync_access_token', '');
        if ($token === '********' && !empty($existing_token)) {
            return $existing_token;
        }

        // If it's empty or the placeholder and no existing token, return empty
        if (empty($token) || $token === '********') {
            return '';
        }

        // Encrypt new token
        $encrypted_token = wp_github_sync_encrypt($token);
        return $encrypted_token ?: '';
    }

    /**
     * Sanitize GitHub App private key. Encrypts the key if it's not the placeholder.
     *
     * @param string $key The private key to sanitize.
     * @return string The sanitized and encrypted key.
     */
    public function sanitize_app_key($key) {
        // If the key is the masked placeholder, get the existing value
        $existing_key = get_option('wp_github_sync_github_app_key', '');
        if ($key === '********' && !empty($existing_key)) {
            return $existing_key;
        }

        // If it's empty or the placeholder and no existing key, return empty
        if (empty($key) || $key === '********') {
            return '';
        }

        // Validate the key format (should start with "-----BEGIN")
        if (strpos(trim($key), '-----BEGIN') === false) {
            add_settings_error(
                'wp_github_sync_github_app_key',
                'invalid_key_format',
                __('The GitHub App private key appears to be invalid. It should include the BEGIN and END lines.', 'wp-github-sync')
            );
            return ''; // Return empty string if invalid format
        }

        // Encrypt the private key
        $encrypted_key = wp_github_sync_encrypt($key);
        return $encrypted_key ?: '';
    }

     /**
     * Sanitize compare method input.
     *
     * @param string $method The method value ('hash' or 'metadata').
     * @return string The sanitized method ('hash' or 'metadata').
     */
    public function sanitize_compare_method($method) {
        $valid_methods = array('hash', 'metadata');
        if (in_array($method, $valid_methods, true)) {
            return $method;
        }
        // Default to 'hash' if invalid value provided
        return 'hash';
    }

    // --- Removed render_* methods ---
    // All render_* methods have been moved to SettingsRenderer class.

} // End class Settings
