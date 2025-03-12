<?php
/**
 * Helper functions for the WordPress GitHub Sync plugin.
 *
 * @package WPGitHubSync
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Securely encrypt sensitive data like GitHub tokens.
 *
 * @param string $data The data to encrypt.
 * @return string|bool The encrypted data or false on failure.
 */
function wp_github_sync_encrypt($data) {
    if (empty($data)) {
        return false;
    }

    // Check if we have a predefined encryption key
    $encryption_key = defined('WP_GITHUB_SYNC_ENCRYPTION_KEY') ? WP_GITHUB_SYNC_ENCRYPTION_KEY : false;
    
    // If no predefined key, generate and store one
    if (!$encryption_key) {
        $encryption_key = get_option('wp_github_sync_encryption_key');
        if (!$encryption_key) {
            $encryption_key = wp_generate_password(64, true, true);
            update_option('wp_github_sync_encryption_key', $encryption_key);
        }
    }

    // Check if OpenSSL is available
    if (function_exists('openssl_encrypt')) {
        $method = 'aes-256-cbc';
        $iv_length = openssl_cipher_iv_length($method);
        $iv = openssl_random_pseudo_bytes($iv_length);
        
        $encrypted = openssl_encrypt($data, $method, $encryption_key, 0, $iv);
        if ($encrypted === false) {
            return false;
        }
        
        // Return iv+encrypted data in base64
        return base64_encode($iv . $encrypted);
    }
    
    // Fallback if OpenSSL is not available - less secure but better than plaintext
    return base64_encode($data);
}

/**
 * Decrypt sensitive data.
 *
 * @param string $encrypted_data The encrypted data to decrypt.
 * @return string|bool The decrypted data or false on failure.
 */
function wp_github_sync_decrypt($encrypted_data) {
    if (empty($encrypted_data)) {
        return false;
    }

    // Check if we have a predefined encryption key
    $encryption_key = defined('WP_GITHUB_SYNC_ENCRYPTION_KEY') ? WP_GITHUB_SYNC_ENCRYPTION_KEY : false;
    
    // If no predefined key, get from options
    if (!$encryption_key) {
        $encryption_key = get_option('wp_github_sync_encryption_key');
        if (!$encryption_key) {
            return false; // Can't decrypt without the key
        }
    }

    // Check if OpenSSL is available
    if (function_exists('openssl_decrypt')) {
        $method = 'aes-256-cbc';
        $iv_length = openssl_cipher_iv_length($method);
        
        $decoded = base64_decode($encrypted_data);
        if ($decoded === false) {
            return false;
        }
        
        // Extract iv and ciphertext
        $iv = substr($decoded, 0, $iv_length);
        $ciphertext = substr($decoded, $iv_length);
        
        return openssl_decrypt($ciphertext, $method, $encryption_key, 0, $iv);
    }
    
    // Fallback if OpenSSL is not available - this would mean data was not properly encrypted
    return base64_decode($encrypted_data);
}

/**
 * Log messages to a debug file if debugging is enabled.
 *
 * @param string $message The message to log.
 * @param string $level   The log level (debug, info, warning, error).
 */
function wp_github_sync_log($message, $level = 'info') {
    if (!defined('WP_DEBUG') || !WP_DEBUG) {
        return;
    }
    
    $log_file = WP_CONTENT_DIR . '/wp-github-sync-debug.log';
    $timestamp = date('Y-m-d H:i:s');
    $formatted_message = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
    
    // Write to log file
    error_log($formatted_message, 3, $log_file);
}

/**
 * Format a commit message for UI display.
 *
 * @param string $commit_message The raw commit message.
 * @param int    $max_length     The maximum length before truncating.
 * @return string The formatted commit message.
 */
function wp_github_sync_format_commit_message($commit_message, $max_length = 50) {
    // Strip newlines
    $message = str_replace(array("\r", "\n"), ' ', $commit_message);
    
    // Truncate if too long
    if (strlen($message) > $max_length) {
        $message = substr($message, 0, $max_length - 3) . '...';
    }
    
    return esc_html($message);
}

/**
 * Check if a path is inside the wp-content directory.
 *
 * @param string $path The path to check.
 * @return bool True if the path is inside wp-content, false otherwise.
 */
function wp_github_sync_is_path_safe($path) {
    $wp_content_dir = WP_CONTENT_DIR;
    $real_content_dir = realpath($wp_content_dir);
    $real_path = realpath($path);
    
    // If realpath fails, the path doesn't exist or is invalid
    if (!$real_path) {
        $real_path = $path;
    }
    
    // Check if path is inside wp-content
    return strpos($real_path, $real_content_dir) === 0;
}

/**
 * Get human-readable time difference between two timestamps.
 *
 * @param int $from The timestamp to start from.
 * @param int $to   The timestamp to end at. Default is current time.
 * @return string Human-readable time difference.
 */
function wp_github_sync_time_diff($from, $to = '') {
    if (empty($to)) {
        $to = time();
    }
    
    return human_time_diff($from, $to);
}

/**
 * Get a list of files to ignore during sync.
 *
 * @return array List of files and patterns to ignore.
 */
function wp_github_sync_get_default_ignores() {
    $default_ignores = array(
        '.git',
        'node_modules',
        'wp-content/uploads',
        'wp-content/cache',
        '*.log',
        '.env',
        'wp-config.php',
    );
    
    return apply_filters('wp_github_sync_ignore_paths', $default_ignores);
}

/**
 * Check if current user has permission to perform GitHub Sync actions.
 *
 * @return bool True if user has permission, false otherwise.
 */
function wp_github_sync_current_user_can() {
    return current_user_can('manage_options');
}

/**
 * Generate a webhook secret.
 *
 * @return string A random secret for use with GitHub webhooks.
 */
function wp_github_sync_generate_webhook_secret() {
    return wp_generate_password(32, false);
}

/**
 * Verify GitHub webhook signature.
 *
 * @param string $payload    The raw webhook payload.
 * @param string $signature  The signature from the X-Hub-Signature or X-Hub-Signature-256 header.
 * @param string $secret     The webhook secret.
 * @return bool True if signature is valid, false otherwise.
 */
function wp_github_sync_verify_webhook_signature($payload, $signature, $secret) {
    if (empty($payload) || empty($signature) || empty($secret)) {
        return false;
    }
    
    // Check which signature type we received
    if (strpos($signature, 'sha256=') === 0) {
        $hash = 'sha256=' . hash_hmac('sha256', $payload, $secret);
        return hash_equals($hash, $signature);
    } elseif (strpos($signature, 'sha1=') === 0) {
        $hash = 'sha1=' . hash_hmac('sha1', $payload, $secret);
        return hash_equals($hash, $signature);
    }
    
    return false;
}

/**
 * Put site in maintenance mode during deployments.
 *
 * @param bool $enable Whether to enable or disable maintenance mode.
 */
function wp_github_sync_maintenance_mode($enable = true) {
    $file = ABSPATH . '.maintenance';
    
    if ($enable) {
        // Create the maintenance file
        $maintenance_message = '<?php $upgrading = ' . time() . '; ?>';
        @file_put_contents($file, $maintenance_message);
    } else {
        // Remove the maintenance file
        if (file_exists($file)) {
            @unlink($file);
        }
    }
}

/**
 * Get the current active branch from the plugin settings.
 *
 * @return string The current active branch.
 */
function wp_github_sync_get_current_branch() {
    $branch = get_option('wp_github_sync_branch', 'main');
    return $branch ?: 'main'; // Default to main if empty
}

/**
 * Get the GitHub repository URL from the plugin settings.
 *
 * @return string The GitHub repository URL.
 */
function wp_github_sync_get_repository_url() {
    return get_option('wp_github_sync_repository', '');
}