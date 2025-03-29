<?php
/**
 * Backup Manager for the WordPress GitHub Sync plugin.
 *
 * @package WPGitHubSync
 */

namespace WPGitHubSync\Sync;

// Add use statement for injected class
use WPGitHubSync\Sync\File_Sync;
// Use FilesystemHelper to get WP_Filesystem instance
use WPGitHubSync\Utils\FilesystemHelper;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Backup Manager class.
 */
class Backup_Manager {

    /**
     * File Sync instance.
     *
     * @var File_Sync
     */
    private $file_sync;

    /**
     * WordPress Filesystem instance.
     * @var \WP_Filesystem_Base|null
     */
    private $wp_filesystem;

    /**
     * Constructor.
     *
     * @param File_Sync $file_sync The File Sync instance.
     */
    public function __construct(File_Sync $file_sync) {
        $this->file_sync = $file_sync;
        $this->wp_filesystem = FilesystemHelper::get_wp_filesystem(); // Initialize filesystem
    }

    /**
     * Create a backup of the current state.
     *
     * @return string|\WP_Error The backup path or WP_Error on failure.
     */
    public function create_backup() {
        // Use the initialized filesystem property
        if (!$this->wp_filesystem) {
            return new \WP_Error('filesystem_error', __('Could not initialize WordPress filesystem.', 'wp-github-sync'));
        }
        // Ensure global is set for functions like copy_dir if they rely on it
        global $wp_filesystem;
        $wp_filesystem = $this->wp_filesystem;

        // Create backup directory using WP_Filesystem if it doesn't exist
        $backup_dir = WP_CONTENT_DIR . '/wp-github-sync-backups';
        if (!$this->wp_filesystem->is_dir($backup_dir)) {
            if (!$this->wp_filesystem->mkdir($backup_dir, FS_CHMOD_DIR)) {
                 // Log error, but maybe continue? Or return error?
                 wp_github_sync_log("Failed to create main backup directory: {$backup_dir}", 'error');
                 // For now, let's try to continue, the specific backup dir creation will fail later if this failed.
            }
        }
        
        // Create a unique backup name
        $backup_name = date('Y-m-d-H-i-s') . '-' . substr(md5(mt_rand()), 0, 10);
        $backup_path = $backup_dir . '/' . $backup_name;
        
        // Create specific backup directory using WP_Filesystem
        if (!$this->wp_filesystem->mkdir($backup_path, FS_CHMOD_DIR)) {
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
            // Note: wp-config.php is outside WP_CONTENT_DIR
            $paths_to_backup[] = ABSPATH . 'wp-config.php';
        }

        // Copy files to backup using WP_Filesystem methods
        foreach ($paths_to_backup as $source_path) {
            if (!$this->wp_filesystem->exists($source_path)) {
                wp_github_sync_log("Backup source path not found: {$source_path}", 'warning');
                continue;
            }

            // Determine target path within the backup directory
            if (strpos($source_path, WP_CONTENT_DIR) === 0) {
                $relative_path = str_replace(WP_CONTENT_DIR, '', $source_path);
                $target_path = trailingslashit($backup_path) . ltrim($relative_path, '/');
            } elseif ($source_path === ABSPATH . 'wp-config.php') {
                $target_path = trailingslashit($backup_path) . 'wp-config.php';
            } else {
                 wp_github_sync_log("Skipping backup for path outside wp-content (except wp-config): {$source_path}", 'warning');
                 continue;
            }

            // Create parent directory for the target if it doesn't exist
            $target_parent_dir = dirname($target_path);
            if (!$this->wp_filesystem->is_dir($target_parent_dir)) {
                if (!$this->wp_filesystem->mkdir($target_parent_dir, FS_CHMOD_DIR)) {
                    wp_github_sync_log("Failed to create target parent directory for backup: {$target_parent_dir}", 'error');
                    continue; // Skip this item if parent dir creation fails
                }
            }

            if ($this->wp_filesystem->is_dir($source_path)) {
                // Use WordPress copy_dir function which utilizes WP_Filesystem
                // Note: copy_dir doesn't easily support ignore patterns.
                // If ignores are critical, a custom recursive copy using $wp_filesystem->copy() is needed.
                // For now, we omit the ignore patterns for simplicity when using copy_dir.
                wp_github_sync_log("Backing up directory: {$source_path} to {$target_path}", 'debug');
                $copy_result = copy_dir($source_path, $target_path);
                if (is_wp_error($copy_result)) {
                     wp_github_sync_log("Failed to backup directory {$source_path}: " . $copy_result->get_error_message(), 'error');
                }
            } else {
                // Copy individual file using WP_Filesystem
                wp_github_sync_log("Backing up file: {$source_path} to {$target_path}", 'debug');
                if (!$this->wp_filesystem->copy($source_path, $target_path, true, FS_CHMOD_FILE)) {
                     wp_github_sync_log("Failed to backup file: {$source_path}", 'error');
                }
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
     * @return true|\WP_Error True on success or WP_Error on failure.
     */
    public function restore_from_backup($backup_path) {
        if (!$this->wp_filesystem) {
            return new \WP_Error('filesystem_error', __('Could not initialize WordPress filesystem.', 'wp-github-sync'));
        }

        if (!$this->wp_filesystem->is_dir($backup_path)) {
            return new \WP_Error('backup_not_found', __('Backup directory not found', 'wp-github-sync'));
        }
        
        wp_github_sync_log("Restoring from backup: {$backup_path}", 'info');

        // Use the injected file_sync instance
        // NOTE: We will address the potential issue of deleting files during restore in Phase 2

        // Determine what to restore based on what exists in the backup
        if ($this->wp_filesystem->exists($backup_path . '/themes')) {
            // Pass true for the $is_restore parameter
            $this->file_sync->sync_directory($backup_path . '/themes', WP_CONTENT_DIR . '/themes', array(), true);
        }

        if ($this->wp_filesystem->exists($backup_path . '/plugins')) {
            // Pass true for the $is_restore parameter
            $this->file_sync->sync_directory($backup_path . '/plugins', WP_CONTENT_DIR . '/plugins', array(), true);
        }

        // Restore wp-config.php if it exists in the backup using WP_Filesystem
        $wp_config_backup = trailingslashit($backup_path) . 'wp-config.php';
        if ($this->wp_filesystem->exists($wp_config_backup)) {
            if (!$this->wp_filesystem->copy($wp_config_backup, ABSPATH . 'wp-config.php', true, FS_CHMOD_FILE)) {
                 wp_github_sync_log("Failed to restore wp-config.php from backup", 'error');
                 // Decide if this is a fatal error for the restore process
            } else {
                 wp_github_sync_log("Restored wp-config.php from backup", 'info');
            }
        }
        
        return true;
    }

    // Removed internal copy_dir method, using WordPress copy_dir() or $wp_filesystem->copy() instead.
}
