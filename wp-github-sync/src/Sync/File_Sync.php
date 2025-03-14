<?php
/**
 * File Sync for the WordPress GitHub Sync plugin.
 *
 * @package WPGitHubSync
 */

namespace WPGitHubSync\Sync;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * File Sync class.
 */
class File_Sync {

    /**
     * Sync files from source to target directory.
     *
     * @param string $source_dir The source directory.
     * @param string $target_dir The target directory.
     * @return bool|\WP_Error True on success or WP_Error on failure.
     */
    public function sync_files($source_dir, $target_dir) {
        if (!is_dir($source_dir)) {
            return new \WP_Error('source_not_found', __('Source directory not found', 'wp-github-sync'));
        }
        
        if (!is_dir($target_dir)) {
            if (!wp_mkdir_p($target_dir)) {
                return new \WP_Error('target_creation_failed', __('Failed to create target directory', 'wp-github-sync'));
            }
        }
        
        // Get patterns to ignore
        $ignore_patterns = wp_github_sync_get_default_ignores();
        
        // Get directories to sync
        $sync_dirs = array();
        
        // Check what's in the source that we want to sync
        foreach (scandir($source_dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            
            $src_path = $source_dir . '/' . $item;
            
            // Only sync directories (plugins, themes, etc.)
            if (is_dir($src_path)) {
                // Skip if matches an ignore pattern
                $relative_path = str_replace(WP_CONTENT_DIR . '/', '', $src_path);
                $should_ignore = false;
                
                foreach ($ignore_patterns as $pattern) {
                    if (fnmatch($pattern, $relative_path) || fnmatch($pattern, $item)) {
                        $should_ignore = true;
                        break;
                    }
                }
                
                if (!$should_ignore) {
                    $sync_dirs[] = array(
                        'source' => $src_path,
                        'target' => $target_dir . '/' . $item,
                    );
                }
            } elseif (is_file($src_path)) {
                // Also sync individual files at the root level
                $relative_path = str_replace(WP_CONTENT_DIR . '/', '', $src_path);
                $should_ignore = false;
                
                foreach ($ignore_patterns as $pattern) {
                    if (fnmatch($pattern, $relative_path) || fnmatch($pattern, $item)) {
                        $should_ignore = true;
                        break;
                    }
                }
                
                if (!$should_ignore) {
                    // Copy the file
                    if (!copy($src_path, $target_dir . '/' . $item)) {
                        wp_github_sync_log("Failed to copy file: {$src_path} to {$target_dir}/{$item}", 'error');
                    }
                }
            }
        }
        
        // Sync each directory
        foreach ($sync_dirs as $dir) {
            $this->sync_directory($dir['source'], $dir['target'], $ignore_patterns);
        }
        
        return true;
    }

    /**
     * Sync a directory recursively.
     *
     * @param string $source_dir      The source directory.
     * @param string $target_dir      The target directory.
     * @param array  $ignore_patterns Patterns to ignore.
     * @return bool True on success, false on failure.
     */
    public function sync_directory($source_dir, $target_dir, $ignore_patterns = array()) {
        // Create target directory if it doesn't exist
        if (!is_dir($target_dir)) {
            wp_mkdir_p($target_dir);
        }
        
        $source_items = array_diff(scandir($source_dir), array('.', '..'));
        $target_items = is_dir($target_dir) ? array_diff(scandir($target_dir), array('.', '..')) : array();
        
        // Copy new and updated files from source to target
        foreach ($source_items as $item) {
            $source_path = $source_dir . '/' . $item;
            $target_path = $target_dir . '/' . $item;
            
            // Skip if matches an ignore pattern
            $relative_path = str_replace(WP_CONTENT_DIR . '/', '', $source_path);
            $should_ignore = false;
            
            foreach ($ignore_patterns as $pattern) {
                if (fnmatch($pattern, $relative_path) || fnmatch($pattern, $item)) {
                    $should_ignore = true;
                    break;
                }
            }
            
            if ($should_ignore) {
                continue;
            }
            
            if (is_dir($source_path)) {
                // Recurse into directories
                $this->sync_directory($source_path, $target_path, $ignore_patterns);
            } else {
                // Copy file if it doesn't exist or is different
                if (!file_exists($target_path) || md5_file($source_path) !== md5_file($target_path)) {
                    copy($source_path, $target_path);
                }
            }
        }
        
        // Optional: Remove files in target that don't exist in source
        $delete_removed_files = get_option('wp_github_sync_delete_removed', true);
        
        if ($delete_removed_files) {
            foreach ($target_items as $item) {
                $target_path = $target_dir . '/' . $item;
                $source_path = $source_dir . '/' . $item;
                
                // Skip if matches an ignore pattern
                $relative_path = str_replace(WP_CONTENT_DIR . '/', '', $target_path);
                $should_ignore = false;
                
                foreach ($ignore_patterns as $pattern) {
                    if (fnmatch($pattern, $relative_path) || fnmatch($pattern, $item)) {
                        $should_ignore = true;
                        break;
                    }
                }
                
                if ($should_ignore) {
                    continue;
                }
                
                if (!file_exists($source_path)) {
                    if (is_dir($target_path)) {
                        $this->recursive_rmdir($target_path);
                    } else {
                        unlink($target_path);
                    }
                }
            }
        }
        
        return true;
    }

    /**
     * Remove a directory recursively.
     *
     * @param string $dir The directory to remove.
     * @return bool True on success, false on failure.
     */
    private function recursive_rmdir($dir) {
        if (!is_dir($dir)) {
            return false;
        }
        
        $objects = scandir($dir);
        
        foreach ($objects as $object) {
            if ($object === '.' || $object === '..') {
                continue;
            }
            
            $path = $dir . '/' . $object;
            
            if (is_dir($path)) {
                $this->recursive_rmdir($path);
            } else {
                unlink($path);
            }
        }
        
        return rmdir($dir);
    }
}