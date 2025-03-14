<?php
/**
 * Constants for WordPress GitHub Sync
 *
 * @package WPGitHubSync
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants
if ( ! defined( 'WP_GITHUB_SYNC_VERSION' ) ) {
    define( 'WP_GITHUB_SYNC_VERSION', '2.0.0' );
}

if ( ! defined( 'WP_GITHUB_SYNC_FILE' ) ) {
    define( 'WP_GITHUB_SYNC_FILE', dirname( dirname( __FILE__ ) ) . '/wp-github-sync.php' );
}

if ( ! defined( 'WP_GITHUB_SYNC_DIR' ) ) {
    define( 'WP_GITHUB_SYNC_DIR', plugin_dir_path( WP_GITHUB_SYNC_FILE ) );
}

if ( ! defined( 'WP_GITHUB_SYNC_URL' ) ) {
    define( 'WP_GITHUB_SYNC_URL', plugin_dir_url( WP_GITHUB_SYNC_FILE ) );
}

// API constants
define( 'WP_GITHUB_SYNC_API_BASE', 'https://api.github.com' );
define( 'WP_GITHUB_SYNC_API_VERSION', 'v3' );