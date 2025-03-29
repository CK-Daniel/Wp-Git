<?php
/**
 * File Sync for the WordPress GitHub Sync plugin.
 *
 * @package WPGitHubSync
 */

namespace WPGitHubSync\Sync;

// Use FilesystemHelper
use WPGitHubSync\Utils\FilesystemHelper;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * File Sync class.
 */
class File_Sync {

    /**
     * WordPress Filesystem instance.
     * @var \WP_Filesystem_Base|null
     */
    private $wp_filesystem;

    /**
     * Constructor. Initializes WP_Filesystem.
     */
    public function __construct() {
        $this->wp_filesystem = FilesystemHelper::get_wp_filesystem();
    }

    /**
     * Sync files from source to target directory.
     *
     * @param string $source_dir The source directory path.
     * @param string $target_dir The target directory path.
     * @return true|\WP_Error True on success or WP_Error on failure.
     */
    public function sync_files($source_dir, $target_dir) {
        if (!$this->wp_filesystem) {
            return new \WP_Error('filesystem_error', __('Could not initialize WordPress filesystem.', 'wp-github-sync'));
        }

        if (!$this->wp_filesystem->is_dir($source_dir)) {
            return new \WP_Error('source_not_found', __('Source directory not found', 'wp-github-sync'));
        }

        if (!$this->wp_filesystem->is_dir($target_dir)) {
            if (!$this->wp_filesystem->mkdir($target_dir, FS_CHMOD_DIR)) {
                return new \WP_Error('target_creation_failed', __('Failed to create target directory', 'wp-github-sync'));
            }
        }

        // Get patterns to ignore
        $ignore_patterns = wp_github_sync_get_default_ignores();
        
        // Get directories to sync
        $sync_dirs = array();
        $source_items = $this->wp_filesystem->dirlist($source_dir);

        if ($source_items === false) {
            return new \WP_Error('source_read_failed', __('Could not read source directory.', 'wp-github-sync'));
        }

        // Check what's in the source that we want to sync
        foreach ($source_items as $item => $details) {
            $src_path = trailingslashit($source_dir) . $item;

            // Only sync directories (plugins, themes, etc.)
            if ($details['type'] === 'd') { // Directory
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
                        'target' => trailingslashit($target_dir) . $item,
                    );
                }
            } elseif ($details['type'] === 'f') { // File
                // Also sync individual files at the root level
                $relative_path = str_replace(WP_CONTENT_DIR . '/', '', $src_path); // Assuming source is within WP_CONTENT_DIR
                $should_ignore = false;

                foreach ($ignore_patterns as $pattern) {
                    if (fnmatch($pattern, $relative_path) || fnmatch($pattern, $item)) {
                        $should_ignore = true;
                        break;
                    }
                }

                if (!$should_ignore) {
                    // Copy the file using WP_Filesystem
                    $target_file_path = trailingslashit($target_dir) . $item;
                    if (!$this->wp_filesystem->copy($src_path, $target_file_path, true, FS_CHMOD_FILE)) {
                        wp_github_sync_log("Failed to copy file: {$src_path} to {$target_file_path}", 'error');
                        // Optionally return an error here if root file copy fails
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
     * @param string $source_dir      The source directory path.
     * @param string $target_dir      The target directory path.
     * @param array  $ignore_patterns Patterns to ignore.
     * @param bool   $is_restore      Indicates if this sync is part of a restore operation.
     * @return true|\WP_Error True on success, WP_Error on failure.
     */
    public function sync_directory($source_dir, $target_dir, $ignore_patterns = array(), $is_restore = false) {
        if (!$this->wp_filesystem) {
            return new \WP_Error('filesystem_error', __('Could not initialize WordPress filesystem.', 'wp-github-sync'));
        }

        // Create target directory if it doesn't exist
        if (!$this->wp_filesystem->is_dir($target_dir)) {
            if (!$this->wp_filesystem->mkdir($target_dir, FS_CHMOD_DIR)) {
                return new \WP_Error('target_creation_failed', __('Failed to create target directory', 'wp-github-sync') . ': ' . $target_dir);
            }
        }

        // Get the comparison method setting
        $compare_method = get_option('wp_github_sync_compare_method', 'hash'); // Default to 'hash'

        $source_items_list = $this->wp_filesystem->dirlist($source_dir);
        $target_items_list = $this->wp_filesystem->dirlist($target_dir);

        if ($source_items_list === false) {
            return new \WP_Error('source_read_failed', __('Could not read source directory.', 'wp-github-sync') . ': ' . $source_dir);
        }
        // Target might not exist initially, which is okay
        if ($target_items_list === false) $target_items_list = [];

        // Copy new and updated files from source to target
        foreach ($source_items_list as $item => $details) {
            $source_path = trailingslashit($source_dir) . $item;
            $target_path = trailingslashit($target_dir) . $item;

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
            } // <-- Fixed missing brace was here, now removed comment as brace is correct

            if ($details['type'] === 'd') { // Directory
                // Recurse into directories, passing the $is_restore flag
                $result = $this->sync_directory($source_path, $target_path, $ignore_patterns, $is_restore);
                if (is_wp_error($result)) {
                    // Propagate the error up
                    return $result;
                }
            } elseif ($details['type'] === 'f') { // File
                // Determine if the file needs copying based on the chosen method
                $needs_copy = false;
                if (!$this->wp_filesystem->exists($target_path)) {
                    $needs_copy = true;
                } else {
                    if ($compare_method === 'metadata') {
                        // Compare size and modification time using WP_Filesystem
                        $source_mtime = $this->wp_filesystem->mtime($source_path);
                        $target_mtime = $this->wp_filesystem->mtime($target_path);
                        if ($this->wp_filesystem->size($source_path) !== $this->wp_filesystem->size($target_path) ||
                            ($source_mtime && $target_mtime && $source_mtime > $target_mtime)) {
                            $needs_copy = true;
                        }
                    } else {
                        // Default to MD5 hash comparison, but check metadata first for optimization
                        $source_mtime = $this->wp_filesystem->mtime($source_path);
                        $target_mtime = $this->wp_filesystem->mtime($target_path);
                        $source_size = $this->wp_filesystem->size($source_path);
                        $target_size = $this->wp_filesystem->size($target_path);

                        // If metadata matches (and target exists), assume file is the same and skip hash check
                        // The outer check already confirmed $this->wp_filesystem->exists($target_path)
                        if ($source_size === $target_size && $source_mtime && $target_mtime && $source_mtime <= $target_mtime) {
                            // $needs_copy remains false from the initial check when target exists
                        } else {
                            // Metadata differs or couldn't be read reliably, proceed with hash comparison
                            $source_content = $this->wp_filesystem->get_contents($source_path);
                            $target_content = $this->wp_filesystem->get_contents($target_path);
                            // Check if content retrieval failed before hashing
                            if ($source_content === false || $target_content === false || md5($source_content) !== md5($target_content)) {
                                $needs_copy = true;
                            }
                            // Note: If metadata matched, $needs_copy remains false from the outer check
                        }
                    }
                }

                // Copy file if needed using WP_Filesystem
                if ($needs_copy) {
                    if (!$this->wp_filesystem->copy($source_path, $target_path, true, FS_CHMOD_FILE)) {
                        $error_message = sprintf(__('Failed to copy file: %s to %s', 'wp-github-sync'), $source_path, $target_path);
                        wp_github_sync_log($error_message, 'error');
                        return new \WP_Error('file_copy_failed', $error_message);
                    }
                }
            }
        }

        // Optional: Remove files/dirs in target that don't exist in source
        // IMPORTANT: Skip deletion if this is part of a restore operation
        $delete_removed_files = get_option('wp_github_sync_delete_removed', true);

        if ($delete_removed_files && !$is_restore) {
            foreach ($target_items_list as $item => $details) {
                $target_path = trailingslashit($target_dir) . $item;
                $source_path = trailingslashit($source_dir) . $item;

                // Skip if matches an ignore pattern - Calculate relative path based on TARGET dir
                $relative_path_from_target = str_replace(trailingslashit(WP_CONTENT_DIR), '', $target_path);
                $should_ignore = false;

                foreach ($ignore_patterns as $pattern) {
                    // Check against relative path from wp-content and the item name itself
                    if (fnmatch($pattern, $relative_path_from_target) || fnmatch($pattern, $item)) {
                        $should_ignore = true;
                        break;
                    }
                }
                
                if ($should_ignore) {
                    continue;
                }

                if (!$this->wp_filesystem->exists($source_path)) {
                    // Use WP_Filesystem delete (recursive for directories)
                    if (!$this->wp_filesystem->delete($target_path, true)) {
                        $error_message = sprintf(__('Failed to delete removed item: %s', 'wp-github-sync'), $target_path);
                        wp_github_sync_log($error_message, 'error');
                        return new \WP_Error('file_delete_failed', $error_message);
                    } else {
                         wp_github_sync_log("Deleted removed item: {$target_path}", 'debug');
                    }
                }
            }
        }

        return true;
    }

    // Removed recursive_rmdir method as FilesystemHelper::recursive_rmdir or $wp_filesystem->delete(..., true) should be used.
}
