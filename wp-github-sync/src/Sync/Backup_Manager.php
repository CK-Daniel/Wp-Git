<?php
/**
 * Backup Manager for the WordPress GitHub Sync plugin.
 *
 * @package WPGitHubSync
 */

namespace WPGitHubSync\Sync;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Backup Manager class.
 */
class Backup_Manager {

    /**
     * Create a backup of the current state.
     *
     * @return string|\WP_Error The backup path or WP_Error on failure.
     */
    public function create_backup() {
        global $wp_filesystem;
        
        // Initialize WP Filesystem
        if (empty($wp_filesystem)) {
            require_once ABSPATH . '/wp-admin/includes/file.php';
            WP_Filesystem();
        }
        
        // Create backup directory
        $backup_dir = WP_CONTENT_DIR . '/wp-github-sync-backups';
        if (!file_exists($backup_dir)) {
            wp_mkdir_p($backup_dir);
        }
        
        // Create a unique backup name
        $backup_name = date('Y-m-d-H-i-s') . '-' . substr(md5(mt_rand()), 0, 10);
        $backup_path = $backup_dir . '/' . $backup_name;
        
        // Create backup directory
        if (!wp_mkdir_p($backup_path)) {
            return new \WP_Error('backup_failed', __('Failed to create backup directory', 'wp-github-sync'));
        }
        
        // Determine what to backup
        $paths_to_backup = array();
        
        // Always backup themes and plugins
        $paths_to_backup[] = WP_CONTENT_DIR . '/themes';
        $paths_to_backup[] = WP_CONTENT_DIR . '/plugins';
        
        // Backup wp-config.php if enabled
        $backup_config = get_option('wp_github_sync_backup_config', false);
        if ($backup_config) {
            $paths_to_backup[] = ABSPATH . 'wp-config.php';
        }
        
        // Get ignore patterns
        $ignore_patterns = wp_github_sync_get_default_ignores();
        
        // Copy files to backup
        foreach ($paths_to_backup as $source_path) {
            if (!file_exists($source_path)) {
                continue;
            }
            
            $target_path = $backup_path . str_replace(WP_CONTENT_DIR, '', $source_path);
            
            // Create target directory
            if (!wp_mkdir_p(dirname($target_path))) {
                continue;
            }
            
            if (is_dir($source_path)) {
                $this->copy_dir($source_path, $target_path, $ignore_patterns);
            } else {
                copy($source_path, $target_path);
            }
        }
        
        // Store backup info
        $backup_info = array(
            'path' => $backup_path,
            'date' => date('Y-m-d H:i:s'),
            'commit' => get_option('wp_github_sync_last_deployed_commit', ''),
            'branch' => wp_github_sync_get_current_branch(),
        );
        
        update_option('wp_github_sync_last_backup', $backup_info);
        
        return $backup_path;
    }

    /**
     * Restore from a backup.
     *
     * @param string $backup_path The path to the backup directory.
     * @return bool|\WP_Error True on success or WP_Error on failure.
     */
    public function restore_from_backup($backup_path) {
        if (!file_exists($backup_path)) {
            return new \WP_Error('backup_not_found', __('Backup directory not found', 'wp-github-sync'));
        }
        
        wp_github_sync_log("Restoring from backup: {$backup_path}", 'info');
        
        $file_sync = new File_Sync();
        
        // Determine what to restore based on what exists in the backup
        if (file_exists($backup_path . '/themes')) {
            $file_sync->sync_directory($backup_path . '/themes', WP_CONTENT_DIR . '/themes');
        }
        
        if (file_exists($backup_path . '/plugins')) {
            $file_sync->sync_directory($backup_path . '/plugins', WP_CONTENT_DIR . '/plugins');
        }
        
        // Restore wp-config.php if it exists in the backup
        $wp_config_backup = $backup_path . '/wp-config.php';
        if (file_exists($wp_config_backup)) {
            copy($wp_config_backup, ABSPATH . 'wp-config.php');
        }
        
        return true;
    }

    /**
     * Copy a directory recursively.
     *
     * @param string $source      The source directory.
     * @param string $destination The destination directory.
     * @param array  $ignore      Patterns to ignore.
     * @return bool True on success, false on failure.
     */
    private function copy_dir($source, $destination, $ignore = array()) {
        if (!is_dir($source)) {
            return false;
        }
        
        if (!is_dir($destination)) {
            wp_mkdir_p($destination);
        }
        
        $dir = opendir($source);
        
        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            
            $src_path = $source . '/' . $file;
            $dst_path = $destination . '/' . $file;
            
            // Check if this file/dir should be ignored
            $relative_path = str_replace(WP_CONTENT_DIR . '/', '', $src_path);
            $should_ignore = false;
            
            foreach ($ignore as $pattern) {
                if (fnmatch($pattern, $relative_path) || fnmatch($pattern, $file)) {
                    $should_ignore = true;
                    break;
                }
            }
            
            if ($should_ignore) {
                continue;
            }
            
            if (is_dir($src_path)) {
                $this->copy_dir($src_path, $dst_path, $ignore);
            } else {
                copy($src_path, $dst_path);
            }
        }
        
        closedir($dir);
        return true;
    }
}