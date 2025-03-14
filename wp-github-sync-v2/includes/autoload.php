<?php
/**
 * Autoloader for WordPress GitHub Sync classes
 *
 * @package WPGitHubSync
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Autoload classes based on namespace
 *
 * @param string $class The class name to autoload.
 * @return void
 */
function wp_github_sync_autoloader( $class ) {
    // If the specified class doesn't use our namespace, bail.
    if ( 0 !== strpos( $class, 'WPGitHubSync\\' ) ) {
        return;
    }
    
    // Get the class name without namespace.
    $class_name = str_replace( 'WPGitHubSync\\', '', $class );
    
    // Convert namespace separators to directory separators.
    $file_path = str_replace( '\\', '/', $class_name );
    
    // Build the file path.
    $file = WP_GITHUB_SYNC_DIR . 'src/' . $file_path . '.php';
    
    // If the file exists, require it.
    if ( file_exists( $file ) ) {
        require_once $file;
    }
}

// Register the autoloader.
spl_autoload_register( 'wp_github_sync_autoloader' );

/**
 * Include required files
 */
function wp_github_sync_include_files() {
    // Include any non-class files here.
    require_once WP_GITHUB_SYNC_DIR . 'includes/constants.php';
}

wp_github_sync_include_files();