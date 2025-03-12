<?php
/**
 * WordPress GitHub Sync
 *
 * @package           WPGitHubSync
 * @author            Your Name
 * @copyright         2025 Your Name or Company
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       WordPress GitHub Sync
 * Plugin URI:        https://example.com/wp-github-sync
 * Description:       Seamlessly sync your WordPress site with a GitHub repository. Deploy themes, plugins, and configuration files directly from GitHub.
 * Version:           1.0.0
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Author:            Your Name
 * Author URI:        https://example.com
 * Text Domain:       wp-github-sync
 * License:           GPL v2 or later
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Current plugin version.
 */
define('WP_GITHUB_SYNC_VERSION', '1.0.0');

/**
 * Define plugin directory path.
 */
define('WP_GITHUB_SYNC_DIR', plugin_dir_path(__FILE__));

/**
 * Define plugin directory URL.
 */
define('WP_GITHUB_SYNC_URL', plugin_dir_url(__FILE__));

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and sync functionality.
 */
require_once WP_GITHUB_SYNC_DIR . 'includes/class-wp-github-sync.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 */
function run_wp_github_sync() {
    $plugin = new WP_GitHub_Sync();
    $plugin->run();
}

/**
 * Run the plugin.
 */
run_wp_github_sync();