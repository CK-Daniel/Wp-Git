<?php
/**
 * Autoloader for WordPress GitHub Sync plugin.
 *
 * @package WPGitHubSync
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * PSR-4 compliant autoloader for WordPress GitHub Sync classes.
 *
 * @param string $class The fully-qualified class name.
 */
spl_autoload_register(function ($class) {
    // Project-specific namespace prefix
    $prefix = 'WPGitHubSync\\';

    // Base directory for the namespace prefix
    $base_dir = WP_GITHUB_SYNC_DIR . 'src/';

    // Does the class use the namespace prefix?
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        // No, move to the next registered autoloader
        return;
    }

    // Get the relative class name
    $relative_class = substr($class, $len);

    // Replace namespace separators with directory separators
    // and append with .php
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    // If the file exists, require it
    if (file_exists($file)) {
        require $file;
    }
});
