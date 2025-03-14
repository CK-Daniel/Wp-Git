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

        // Add more settings registration as needed...
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