<?php
/**
 * The settings functionality of the plugin.
 *
 * @package WPGitHubSync
 */

namespace WPGitHubSync\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Settings class.
 */
class Settings {

    /**
     * The version of this plugin.
     *
     * @var string
     */
    private $version;

    /**
     * Initialize the class and set its properties.
     *
     * @param string $version The version of this plugin.
     */
    public function __construct( $version ) {
        $this->version = $version;
    }

    /**
     * Register all settings for the plugin.
     */
    public function register_settings() {
        // Register the main settings group
        register_setting(
            'wp_github_sync_settings',
            'wp_github_sync_settings',
            array(
                'sanitize_callback' => array($this, 'sanitize_settings'),
                'default' => array(
                    'repo_url' => '',
                    'sync_branch' => 'main',
                    'auth_method' => 'pat',
                    'access_token' => '',
                    'oauth_token' => '',
                    'auto_backup' => false,
                    'backup_themes' => true,
                    'backup_plugins' => true,
                    'backup_uploads' => false,
                    'backup_config' => false,
                    'max_backups' => 5,
                    'maintenance_mode' => false,
                    'delete_removed' => false,
                    'debug_mode' => false,
                    'log_retention' => 30,
                ),
            )
        );
        
        // For backwards compatibility
        register_setting(
            'wp_github_sync_settings',
            'wp_github_sync_repository',
            array(
                'sanitize_callback' => 'sanitize_text_field',
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
            'wp_github_sync_access_token',
            array(
                'sanitize_callback' => array($this, 'sanitize_token'),
            )
        );
    }

    /**
     * Sanitize settings array.
     *
     * @param array $input The settings array to sanitize.
     * @return array The sanitized settings array.
     */
    public function sanitize_settings($input) {
        $sanitized = array();
        $current_settings = get_option('wp_github_sync_settings', array());

        // Repository URL
        $sanitized['repo_url'] = isset($input['repo_url']) ? sanitize_text_field($input['repo_url']) : '';
        
        // Branch
        $sanitized['sync_branch'] = isset($input['sync_branch']) ? sanitize_text_field($input['sync_branch']) : 'main';
        
        // Auth method (include 'github_app' in allowed methods)
        $sanitized['auth_method'] = isset($input['auth_method']) && in_array($input['auth_method'], array('pat', 'oauth', 'github_app')) 
            ? $input['auth_method'] 
            : 'pat';
        
        // Access token (PAT)
        if (isset($input['access_token'])) {
            if ($input['access_token'] === '********' && isset($current_settings['access_token'])) {
                $sanitized['access_token'] = $current_settings['access_token'];
            } else {
                $sanitized['access_token'] = $this->sanitize_token($input['access_token']);
            }
        } else {
            $sanitized['access_token'] = isset($current_settings['access_token']) ? $current_settings['access_token'] : '';
        }
        
        // OAuth token
        if (isset($input['oauth_token'])) {
            if ($input['oauth_token'] === '********' && isset($current_settings['oauth_token'])) {
                $sanitized['oauth_token'] = $current_settings['oauth_token'];
            } else {
                $sanitized['oauth_token'] = $this->sanitize_token($input['oauth_token']);
            }
        } else {
            $sanitized['oauth_token'] = isset($current_settings['oauth_token']) ? $current_settings['oauth_token'] : '';
        }
        
        // GitHub App settings
        $github_app_settings = array(
            'github_app_id', 
            'github_app_installation_id', 
            'github_app_key'
        );
        
        foreach ($github_app_settings as $key) {
            if (isset($input[$key])) {
                if ($key === 'github_app_key') {
                    $sanitized[$key] = $input[$key]; // Don't sanitize private key content
                } else {
                    $sanitized[$key] = sanitize_text_field($input[$key]);
                }
            } else {
                $sanitized[$key] = isset($current_settings[$key]) ? $current_settings[$key] : '';
            }
        }
        
        // Boolean settings
        $boolean_settings = array(
            'auto_sync',
            'auto_deploy',
            'auto_backup',
            'backup_themes',
            'backup_plugins',
            'backup_uploads',
            'backup_config',
            'maintenance_mode',
            'auto_rollback',
            'notify_updates',
            'webhook_sync',
            'webhook_auto_deploy',
            'webhook_specific_branch',
            'delete_removed',
            'debug_mode'
        );
        
        foreach ($boolean_settings as $key) {
            $sanitized[$key] = isset($input[$key]) && $input[$key] ? true : false;
        }
        
        // Numeric settings
        $numeric_settings = array(
            'max_backups' => 5,    // Default: 5
            'log_retention' => 30  // Default: 30 days
        );
        
        foreach ($numeric_settings as $key => $default) {
            $sanitized[$key] = isset($input[$key]) ? absint($input[$key]) : $default;
        }
        
        // Dropdown settings
        if (isset($input['sync_interval'])) {
            $allowed_intervals = array('hourly', 'twicedaily', 'daily', 'weekly');
            $sanitized['sync_interval'] = in_array($input['sync_interval'], $allowed_intervals) ? $input['sync_interval'] : 'daily';
        }
        
        // Webhook secret
        if (isset($input['webhook_secret'])) {
            $sanitized['webhook_secret'] = sanitize_text_field($input['webhook_secret']);
        } else {
            $sanitized['webhook_secret'] = isset($current_settings['webhook_secret']) ? $current_settings['webhook_secret'] : '';
        }
        
        // For backward compatibility, also update individual options
        if (isset($sanitized['repo_url'])) {
            update_option('wp_github_sync_repository', $sanitized['repo_url']);
        }
        
        if (isset($sanitized['sync_branch'])) {
            update_option('wp_github_sync_branch', $sanitized['sync_branch']);
        }
        
        if (isset($sanitized['access_token']) && $sanitized['access_token']) {
            update_option('wp_github_sync_access_token', $sanitized['access_token']);
        }
        
        return $sanitized;
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

        // Encrypt token if a function exists for it
        if (function_exists('wp_github_sync_encrypt')) {
            $encrypted_token = wp_github_sync_encrypt($token);
            return $encrypted_token ?: '';
        }
        
        return $token;
    }
}