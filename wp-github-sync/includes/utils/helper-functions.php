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
        wp_github_sync_log("Cannot encrypt empty data", 'error');
        return false;
    }

    // Check if token is already a masked placeholder (don't re-encrypt)
    if ($data === '********') {
        wp_github_sync_log("Skipping encryption for masked placeholder", 'debug');
        return false;
    }
    
    // Validate GitHub token format
    if (strpos($data, 'github_pat_') === 0 || strpos($data, 'ghp_') === 0 || 
        strpos($data, 'gho_') === 0 || strpos($data, 'ghs_') === 0 ||
        (strlen($data) === 40 && ctype_xdigit($data))) {
        // Token format is valid
        wp_github_sync_log("Token format validation passed", 'debug');
        
        // Determine token type and log permissions advice
        if (strpos($data, 'github_pat_') === 0) {
            wp_github_sync_log("Fine-grained PAT detected. Ensure it has the 'Contents' permission with read/write access for repo initialization.", 'info');
        } else if (strpos($data, 'ghp_') === 0) {
            wp_github_sync_log("Classic PAT detected. Should have 'repo' scope for full repository access.", 'debug');
        }
    } else {
        wp_github_sync_log("Invalid token format detected", 'warning');
    }

    // Check if we have a predefined encryption key (recommended)
    $encryption_key = defined('WP_GITHUB_SYNC_ENCRYPTION_KEY') ? WP_GITHUB_SYNC_ENCRYPTION_KEY : false;
    
    // If no predefined key, use the database option (less secure)
    if (!$encryption_key) {
        wp_github_sync_log("SECURITY WARNING: WP_GITHUB_SYNC_ENCRYPTION_KEY constant not defined. Using less secure database option for encryption key. Define the constant in wp-config.php for better security.", 'warning');
        $encryption_key = get_option('wp_github_sync_encryption_key');
        if (!$encryption_key) {
            // Generate and store one if it doesn't exist at all
            $encryption_key = wp_generate_password(64, true, true);
            update_option('wp_github_sync_encryption_key', $encryption_key);
            wp_github_sync_log("Generated new encryption key and stored in database (less secure).", 'warning');
        } else {
             wp_github_sync_log("Using existing encryption key from database (less secure).", 'warning');
        }
    } else {
         wp_github_sync_log("Using secure encryption key from WP_GITHUB_SYNC_ENCRYPTION_KEY constant.", 'debug');
    }

    // Check if OpenSSL is available
    if (function_exists('openssl_encrypt')) {
        $method = 'aes-256-cbc';
        $iv_length = openssl_cipher_iv_length($method);
        $iv = openssl_random_pseudo_bytes($iv_length);
        
        $encrypted = openssl_encrypt($data, $method, $encryption_key, 0, $iv);
        if ($encrypted === false) {
            wp_github_sync_log("OpenSSL encryption failed", 'error');
            return false;
        }
        
        // Return iv+encrypted data in base64
        $result = base64_encode($iv . $encrypted);
        wp_github_sync_log("Successfully encrypted data using OpenSSL", 'debug');
        return $result;
    }

    // If OpenSSL is not available, fail encryption
    wp_github_sync_log("OpenSSL extension is not available. Cannot encrypt token securely.", 'error');
    return false;
}

/**
 * Decrypt sensitive data.
 *
 * @param string $encrypted_data The encrypted data to decrypt.
 * @return string|bool The decrypted data or false on failure.
 */
function wp_github_sync_decrypt($encrypted_data) {
    if (empty($encrypted_data)) {
        wp_github_sync_log("Cannot decrypt empty data", 'error');
        return false;
    }

    // Check if we have a predefined encryption key (recommended)
    $encryption_key = defined('WP_GITHUB_SYNC_ENCRYPTION_KEY') ? WP_GITHUB_SYNC_ENCRYPTION_KEY : false;
    
    // If no predefined key, get from options (less secure)
    if (!$encryption_key) {
         wp_github_sync_log("SECURITY WARNING: WP_GITHUB_SYNC_ENCRYPTION_KEY constant not defined. Using less secure database option for encryption key.", 'warning');
        $encryption_key = get_option('wp_github_sync_encryption_key');
        if (!$encryption_key) {
            wp_github_sync_log("No encryption key found (constant or option), cannot decrypt", 'error');
            return false; // Can't decrypt without the key
        }
    } else {
         wp_github_sync_log("Using secure encryption key from WP_GITHUB_SYNC_ENCRYPTION_KEY constant.", 'debug');
    }

    // Check if OpenSSL is available
    if (function_exists('openssl_decrypt')) {
        $method = 'aes-256-cbc';
        $iv_length = openssl_cipher_iv_length($method);
        
        $decoded = base64_decode($encrypted_data);
        if ($decoded === false) {
            wp_github_sync_log("Base64 decoding of encrypted data failed", 'error');
            return false;
        }
        
        // Make sure decoded data is long enough to contain IV
        if (strlen($decoded) <= $iv_length) {
            wp_github_sync_log("Decoded data too short to contain valid IV", 'error');
            return false;
        }
        
        // Extract iv and ciphertext
        $iv = substr($decoded, 0, $iv_length);
        $ciphertext = substr($decoded, $iv_length);
        
        $result = openssl_decrypt($ciphertext, $method, $encryption_key, 0, $iv);
        if ($result === false) {
            wp_github_sync_log("OpenSSL decryption failed", 'error');
            return false;
        }

        wp_github_sync_log("Successfully decrypted using OpenSSL", 'debug');
        return $result;
    }

    // If OpenSSL is not available, fail decryption
    wp_github_sync_log("OpenSSL extension is not available. Cannot decrypt token securely.", 'error');
    return false;
}

/**
 * Log messages to a debug file if debugging is enabled.
 *
 * @param string $message The message to log.
 * @param string $level   The log level (debug, info, warning, error).
 * @param bool   $force   Whether to log even if WP_DEBUG is not enabled.
 */
function wp_github_sync_log($message, $level = 'info', $force = false) {
    // Check if we should log - either debug is enabled or force is true 
    // or a specific filter for GitHub Sync logging is enabled
    $should_log = (defined('WP_DEBUG') && WP_DEBUG) || 
                 $force || 
                 apply_filters('wp_github_sync_enable_logging', false);
    
    if (!$should_log) {
        return;
    }
    
    // Normalize log level
    $level = strtolower($level);
    $valid_levels = array('debug', 'info', 'warning', 'error');
    
    if (!in_array($level, $valid_levels)) {
        $level = 'info';
    }
    
    // Get log file path
    $log_file = WP_CONTENT_DIR . '/wp-github-sync-debug.log';
    
    // Get timestamp with microseconds for precise logging
    $timestamp = microtime(true);
    $date = new \DateTime(date('Y-m-d H:i:s', $timestamp));
    $date->modify('+' . (int)(($timestamp - floor($timestamp)) * 1000000) . ' microseconds');
    $formatted_timestamp = $date->format('Y-m-d H:i:s.u');
    
    // Format the log message
    $formatted_message = "[{$formatted_timestamp}] [{$level}] {$message}" . PHP_EOL;
    
    // Get backtrace information (always for debugging this issue)
    $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
    if (isset($backtrace[1])) {
        $caller = $backtrace[1];
        $caller_info = '';
        
        if (isset($caller['class'])) {
            $caller_info .= $caller['class'] . $caller['type'];
        }
        
        if (isset($caller['function'])) {
            $caller_info .= $caller['function'] . '()';
        }
        
        if (isset($caller['file']) && isset($caller['line'])) {
            $file = basename($caller['file']);
            $line = $caller['line'];
            $caller_info .= " | {$file}:{$line}";
        }
        
        if (!empty($caller_info)) {
            $formatted_message = "[{$formatted_timestamp}] [{$level}] [{$caller_info}] {$message}" . PHP_EOL;
        }
    }
    
    // Write to log file
    error_log($formatted_message, 3, $log_file);
    
    // For severe errors, also log to PHP error log to ensure visibility
    if ($level === 'error') {
        error_log("WP GitHub Sync Error: {$message}");
    }
    
    // Rotate log file if it gets too large (over 5MB by default)
    $max_size = apply_filters('wp_github_sync_log_max_size', 5 * 1024 * 1024); // 5MB
    
    if (file_exists($log_file) && filesize($log_file) > $max_size) {
        wp_github_sync_rotate_logs($log_file);
    }
}

/**
 * Rotate log files when they get too large.
 *
 * @param string $log_file The path to the log file to rotate.
 * @return bool True on success, false on failure.
 */
function wp_github_sync_rotate_logs($log_file) {
    if (!file_exists($log_file)) {
        return false;
    }
    
    // Create a backup log file with timestamp
    $backup_file = $log_file . '.' . date('Y-m-d-H-i-s') . '.bak';
    
    // Try to rename the old log file
    if (!@rename($log_file, $backup_file)) {
        // If renaming fails, try to copy and then delete
        if (!@copy($log_file, $backup_file)) {
            return false;
        }
        
        // Clear the contents of the original log file
        @file_put_contents($log_file, '');
    }
    
    // Limit the number of backup log files to keep
    $max_backups = apply_filters('wp_github_sync_max_log_backups', 5);
    $backup_pattern = $log_file . '.*.bak';
    $backup_files = glob($backup_pattern);
    
    if (count($backup_files) > $max_backups) {
        // Sort backups by name (oldest first)
        sort($backup_files);
        
        // Remove the oldest backups
        $backups_to_remove = count($backup_files) - $max_backups;
        for ($i = 0; $i < $backups_to_remove; $i++) {
            @unlink($backup_files[$i]);
        }
    }
    
    return true;
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
    
    // Normalize path by removing any ".." or "." components
    $normalized_path = wp_normalize_path($path);
    $normalized_content_dir = wp_normalize_path($real_content_dir);
    
    // Check for any directory traversal sequences even after normalization
    if (strpos($normalized_path, '../') !== false || strpos($normalized_path, '..\\') !== false) {
        wp_github_sync_log("Path safety check failed: Directory traversal detected in {$path}", 'error');
        return false;
    }
    
    // Check if the normalized path starts with the normalized wp-content directory
    if (strpos($normalized_path, $normalized_content_dir) !== 0) {
        wp_github_sync_log("Path safety check failed: Path {$path} is outside of {$real_content_dir}", 'error');
        return false;
    }
    
    // Check if the real path exists, and if so, double-check it
    if (file_exists($path)) {
        $real_path = realpath($path);
        if ($real_path && strpos($real_path, $real_content_dir) !== 0) {
            wp_github_sync_log("Path safety check failed: Resolved path {$real_path} is outside of {$real_content_dir}", 'error');
            return false;
        }
    }
    
    return true;
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
