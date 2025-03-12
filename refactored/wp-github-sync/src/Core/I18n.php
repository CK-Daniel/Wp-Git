<?php
/**
 * Internationalization functionality.
 *
 * @package WPGitHubSync
 */

namespace WPGitHubSync\Core;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Internationalization class.
 */
class I18n {

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