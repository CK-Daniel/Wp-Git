<?php
/**
 * WordPress GitHub Sync
 *
 * @package WPGitHubSync
 * @author  Your Name
 * @license GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name: WordPress GitHub Sync
 * Plugin URI: https://github.com/username/wp-github-sync
 * Description: Synchronize your WordPress site content with a GitHub repository.
 * Version: 2.0.0
 * Author: Your Name
 * Author URI: https://your-website.com
 * Text Domain: wp-github-sync
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.6
 * Requires PHP: 7.2
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants
define( 'WP_GITHUB_SYNC_VERSION', '2.0.0' );
define( 'WP_GITHUB_SYNC_FILE', __FILE__ );
define( 'WP_GITHUB_SYNC_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_GITHUB_SYNC_URL', plugin_dir_url( __FILE__ ) );

/**
 * The code that runs during plugin activation
 */
function wp_github_sync_activate() {
    // Activation code here
}
register_activation_hook( __FILE__, 'wp_github_sync_activate' );

/**
 * The code that runs during plugin deactivation
 */
function wp_github_sync_deactivate() {
    // Deactivation code here
}
register_deactivation_hook( __FILE__, 'wp_github_sync_deactivate' );

/**
 * Load the plugin's translations
 */
function wp_github_sync_load_textdomain() {
    load_plugin_textdomain( 'wp-github-sync', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'wp_github_sync_load_textdomain' );

/**
 * Autoload classes
 */
function wp_github_sync_autoload() {
    require_once WP_GITHUB_SYNC_DIR . 'includes/autoload.php';
}
wp_github_sync_autoload();

/**
 * Initialize the plugin
 */
function wp_github_sync_init() {
    // Initialize the plugin
    $plugin = WPGitHubSync\Core\Plugin::get_instance();
    $plugin->run();
}
add_action( 'plugins_loaded', 'wp_github_sync_init', 20 );

/**
 * Log a message to the plugin's log file
 *
 * @param string $message The message to log.
 * @param string $level   The log level (debug, info, warning, error).
 */
function wp_github_sync_log( $message, $level = 'info' ) {
    if ( ! in_array( $level, array( 'debug', 'info', 'warning', 'error' ), true ) ) {
        $level = 'info';
    }
    
    $settings = get_option( 'wp_github_sync_settings', array() );
    
    // Only log debug messages if debug mode is enabled
    if ( 'debug' === $level && empty( $settings['debug_mode'] ) ) {
        return;
    }
    
    $log_file = WP_CONTENT_DIR . '/wp-github-sync-logs/wp-github-sync-' . date( 'Y-m-d' ) . '.log';
    $log_dir = dirname( $log_file );
    
    if ( ! file_exists( $log_dir ) ) {
        wp_mkdir_p( $log_dir );
        
        // Add .htaccess to protect logs
        $htaccess = $log_dir . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            file_put_contents( $htaccess, 'Deny from all' );
        }
    }
    
    $timestamp = date( 'Y-m-d H:i:s' );
    $log_level = strtoupper( $level );
    
    $log_message = "[{$timestamp}] [{$log_level}] {$message}" . PHP_EOL;
    
    error_log( $log_message, 3, $log_file );
}

/**
 * Enable maintenance mode
 *
 * @param bool $enable Whether to enable or disable maintenance mode.
 */
function wp_github_sync_maintenance_mode( $enable = true ) {
    $file = ABSPATH . '.maintenance';
    
    if ( $enable ) {
        $maintenance = '<?php $upgrading = ' . time() . '; ?>';
        file_put_contents( $file, $maintenance );
    } else {
        if ( file_exists( $file ) ) {
            unlink( $file );
        }
    }
}