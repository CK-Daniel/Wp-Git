<?php
/**
 * Define the internationalization functionality.
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @package WPGitHubSync
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Define the internationalization functionality.
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 */
class WP_GitHub_Sync_i18n {

    /**
     * Load the plugin text domain for translation.
     */
    public function load_plugin_textdomain() {
        load_plugin_textdomain(
            'wp-github-sync',
            false,
            dirname(dirname(plugin_basename(__FILE__))) . '/languages/'
        );
    }
}