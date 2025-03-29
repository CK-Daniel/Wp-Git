<?php
/**
 * Filesystem utility functions for the WordPress GitHub Sync plugin.
 *
 * @package WPGitHubSync\Utils
 */

namespace WPGitHubSync\Utils;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Filesystem Helper class.
 */
class FilesystemHelper {

    /**
     * Initialize and return the WP_Filesystem object.
     *
     * @return \WP_Filesystem_Base|false The WP_Filesystem object or false on failure.
     */
    public static function get_wp_filesystem() {
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            if (!function_exists('WP_Filesystem')) {
                require_once ABSPATH . '/wp-admin/includes/file.php';
            }
            if (!WP_Filesystem()) {
                wp_github_sync_log('Failed to initialize WP_Filesystem', 'error');
                return false;
            }
        }
        return $wp_filesystem;
    }

    /**
     * Extract a zip file to a directory using WP_Filesystem.
     * Handles moving contents out of the wrapper directory created by GitHub.
     *
     * @param string $file       The path to the zip file.
     * @param string $target_dir The directory to extract to.
     * @return bool|\WP_Error True on success or WP_Error on failure.
     */
    public static function extract_zip($file, $target_dir) {
        $wp_filesystem = self::get_wp_filesystem();
        if (!$wp_filesystem) {
             return new \WP_Error('filesystem_error', __('Could not initialize WordPress filesystem.', 'wp-github-sync'));
        }
        // Global needed for unzip_file
        global $wp_filesystem;

        wp_github_sync_log("Extracting zip file '{$file}' to: {$target_dir}", 'debug');

        // Use unzip_file which uses ZipArchive or PclZip via WP_Filesystem
        $result = unzip_file($file, $target_dir);

        if (is_wp_error($result)) {
            wp_github_sync_log('Failed to extract zip file: ' . $result->get_error_message(), 'error');
            return $result;
        }

        // Move files from the extracted directory (which includes owner/repo-branch/) to target
        $dir_contents = $wp_filesystem->dirlist($target_dir);
        $extracted_dir_path = '';

        // Find the first directory inside the target_dir
        if (!empty($dir_contents)) {
            foreach ($dir_contents as $item_name => $item_details) {
                if ($item_details['type'] === 'd') { // 'd' indicates directory
                    $extracted_dir_path = trailingslashit($target_dir) . $item_name;
                    break;
                }
            }
        }

        if (empty($extracted_dir_path) || !$wp_filesystem->is_dir($extracted_dir_path)) {
            wp_github_sync_log("Could not find the main extracted directory inside: {$target_dir}", 'error');
            return new \WP_Error('no_extracted_dir', __('Could not find the main extracted directory after unzipping.', 'wp-github-sync'));
        }

        $extracted_contents = $wp_filesystem->dirlist($extracted_dir_path);
        wp_github_sync_log("Found extracted directory: {$extracted_dir_path} with " . count($extracted_contents) . " items", 'debug');

        if (!empty($extracted_contents)) {
            foreach ($extracted_contents as $item_name => $item_details) {
                $source_item_path = trailingslashit($extracted_dir_path) . $item_name;
                $destination_path = trailingslashit($target_dir) . $item_name;

                // If destination exists, remove it first
                if ($wp_filesystem->exists($destination_path)) {
                    wp_github_sync_log("Removing existing item: {$destination_path}", 'debug');
                    if (!$wp_filesystem->delete($destination_path, true)) { // Recursive delete
                         wp_github_sync_log("Failed to remove existing item: {$destination_path}", 'warning');
                    }
                }

                // Move the item
                wp_github_sync_log("Moving {$source_item_path} to {$destination_path}", 'debug');
                if (!$wp_filesystem->move($source_item_path, $destination_path, true)) { // Overwrite = true
                    $error_message = sprintf(__('Failed to move extracted item: %s to %s', 'wp-github-sync'), $source_item_path, $destination_path);
                    wp_github_sync_log($error_message, 'error');
                    // Clean up the temp file before returning error
                    if ($wp_filesystem->exists($file)) {
                        $wp_filesystem->delete($file);
                    }
                    return new \WP_Error('move_failed', $error_message);
                }
            }
        }

        // Remove the now-empty extracted source directory
        wp_github_sync_log("Removing original extracted directory: {$extracted_dir_path}", 'debug');
        $wp_filesystem->delete($extracted_dir_path, true); // Use delete which handles rmdir

        // Verify the extraction was successful
        $final_contents = $wp_filesystem->dirlist($target_dir);
        $files_count = is_array($final_contents) ? count($final_contents) : 0;
        wp_github_sync_log("Extraction complete. Found {$files_count} files/directories in target directory", 'debug');

        if ($files_count === 0) {
            wp_github_sync_log("Extraction failed: no files in target directory", 'error');
            return new \WP_Error('extraction_failed', __('Extraction failed: no files in target directory.', 'wp-github-sync'));
        }

        return true;
    }

    /**
     * Check if a path is valid for use as a sync path key.
     *
     * @param string $path The path to check.
     * @return bool True if the path is valid.
     */
    public static function is_valid_path_key($path) {
        // Only allow alphanumeric characters, slashes, hyphens, underscores, dots
        return (bool) preg_match('/^[a-zA-Z0-9\/_\-\.]+$/', $path);
    }

    /**
     * Check if a path is safe (no directory traversal, null bytes).
     *
     * @param string $path The path to check.
     * @return bool True if the path is safe.
     */
    public static function is_safe_path($path) {
        // Check for directory traversal attempts
        if (strpos($path, '..') !== false) {
            return false;
        }
        // No null bytes
        if (strpos($path, "\0") !== false) {
            return false;
        }
        // Check normalized path as well
        $normalized = self::normalize_path($path);
        return (strpos($normalized, '../') === false);
    }

    /**
     * Normalize a path (replace backslashes, remove //, /./, trailing /).
     *
     * @param string $path The path to normalize.
     * @return string The normalized path.
     */
    public static function normalize_path($path) {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('|/+|', '/', $path); // Replace multiple slashes
        $path = preg_replace('|/\./|', '/', $path); // Remove /./
        $path = rtrim($path, '/');
        return $path;
    }

    /**
     * Check if a path is within the WordPress content directory.
     * Uses realpath for better security against symlinks etc.
     *
     * @param string $path The path to check.
     * @return bool True if the path is within WP_CONTENT_DIR.
     */
    public static function is_within_wordpress($path) {
        // Ensure WP_CONTENT_DIR is defined and not empty
        if (!defined('WP_CONTENT_DIR') || empty(WP_CONTENT_DIR)) {
            return false;
        }

        // Normalize paths and use realpath
        $wp_content_real = realpath(WP_CONTENT_DIR);
        $real_path = realpath($path);

        // If realpath fails (e.g., path doesn't exist), it's not safe/valid
        if ($real_path === false || $wp_content_real === false) {
            return false;
        }

        // Check if the real path starts with the real WP_CONTENT_DIR path
        return strpos($real_path, $wp_content_real) === 0;
    }

    /**
     * List files in a directory recursively for debugging.
     * Uses WP_Filesystem.
     *
     * @param string $dir The directory to list.
     * @param string $prefix Prefix for indentation in recursive calls.
     */
    public static function list_directory_recursive($dir, $prefix = '') {
        $wp_filesystem = self::get_wp_filesystem();
        if (!$wp_filesystem || !$wp_filesystem->is_dir($dir)) {
            return;
        }

        $files = $wp_filesystem->dirlist($dir);
        if ($files === false) {
             wp_github_sync_log("{$prefix}Failed to list directory: {$dir}", 'error');
             return;
        }

        foreach ($files as $name => $details) {
            $path = trailingslashit($dir) . $name;
            if ($details['type'] === 'd') { // Directory
                wp_github_sync_log("{$prefix}[DIR] {$name}/", 'debug');
                self::list_directory_recursive($path, $prefix . '  ');
            } else { // File
                wp_github_sync_log("{$prefix}[FILE] {$name} (" . $details['size'] . " bytes)", 'debug');
            }
        }
    }

    /**
     * Copy a directory recursively using WP_Filesystem.
     *
     * @param string $source The source directory.
     * @param string $dest   The destination directory.
     * @return bool|\WP_Error True on success or WP_Error on failure.
     */
    public static function copy_directory($source, $dest) {
        $wp_filesystem = self::get_wp_filesystem();
        if (!$wp_filesystem) {
             return new \WP_Error('filesystem_error', __('Could not initialize WordPress filesystem.', 'wp-github-sync'));
        }

        // Validate source and destination for security
        if (!self::is_safe_path($source) || !self::is_safe_path($dest)) {
            wp_github_sync_log("Security check failed: Unsafe path detected in copy operation", 'error');
            return new \WP_Error('unsafe_path', __('Unsafe path detected during copy.', 'wp-github-sync'));
        }

        // Use WP_Filesystem's copy_dir function
        $result = copy_dir($source, $dest);

        if (is_wp_error($result)) {
            wp_github_sync_log("Failed to copy directory from {$source} to {$dest}: " . $result->get_error_message(), 'error');
            return $result;
        }

        wp_github_sync_log("Successfully copied directory from {$source} to {$dest}", 'info');
        return true;
    }

    /**
     * Recursively remove a directory using WP_Filesystem.
     *
     * @param string $dir The directory to remove.
     * @return bool True on success or false on failure.
     */
    public static function recursive_rmdir($dir) {
        $wp_filesystem = self::get_wp_filesystem();
        if (!$wp_filesystem) {
            wp_github_sync_log('Filesystem not initialized for rmdir', 'error');
            return false;
        }

        if (!$wp_filesystem->exists($dir)) {
            return true; // Already gone
        }

        // Use WP_Filesystem's delete method with recursive flag
        $result = $wp_filesystem->delete($dir, true);

        if (!$result) {
            wp_github_sync_log("Failed to recursively remove directory: {$dir}", 'error');
        } else {
            wp_github_sync_log("Successfully removed directory: {$dir}", 'debug');
        }
        return $result;
    }
}
