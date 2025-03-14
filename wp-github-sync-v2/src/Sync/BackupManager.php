<?php
/**
 * Backup Manager
 *
 * @package WPGitHubSync
 */

namespace WPGitHubSync\Sync;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Manages backups of the WordPress installation
 */
class BackupManager {
    /**
     * Directory where backups are stored
     *
     * @var string
     */
    private $backup_dir;
    
    /**
     * Maximum number of backups to keep
     *
     * @var int
     */
    private $max_backups;
    
    /**
     * Initialize the backup manager
     */
    public function __construct() {
        $this->backup_dir = WP_CONTENT_DIR . '/wp-github-sync-backups';
        $this->max_backups = $this->get_max_backups();
        $this->ensure_backup_directory();
    }
    
    /**
     * Get maximum number of backups to keep
     *
     * @return int Maximum number of backups
     */
    private function get_max_backups() {
        $settings = get_option( 'wp_github_sync_settings' );
        return isset( $settings['max_backups'] ) ? (int) $settings['max_backups'] : 10;
    }
    
    /**
     * Ensure backup directory exists and is writable
     *
     * @return bool True if directory is ready
     */
    private function ensure_backup_directory() {
        if ( ! file_exists( $this->backup_dir ) ) {
            wp_mkdir_p( $this->backup_dir );
        }
        
        // Set proper permissions
        $htaccess = $this->backup_dir . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            file_put_contents( $htaccess, 'Deny from all' );
        }
        
        $index_html = $this->backup_dir . '/index.html';
        if ( ! file_exists( $index_html ) ) {
            file_put_contents( $index_html, '<!-- Silence is golden -->' );
        }
        
        return is_writable( $this->backup_dir );
    }
    
    /**
     * Create a backup before syncing
     *
     * @param array $paths Paths to backup (relative to ABSPATH).
     * @return string|bool Backup ID on success, false on failure
     */
    public function create_backup( $paths = array() ) {
        // Create unique backup ID
        $backup_id = 'backup-' . date( 'YmdHis' ) . '-' . substr( md5( uniqid( '', true ) ), 0, 8 );
        $backup_path = $this->backup_dir . '/' . $backup_id;
        
        if ( ! wp_mkdir_p( $backup_path ) ) {
            $this->log_error( 'Failed to create backup directory: ' . $backup_path );
            return false;
        }
        
        // Default paths if none provided
        if ( empty( $paths ) ) {
            $paths = array(
                'wp-content/themes',
                'wp-content/plugins',
                'wp-content/uploads',
            );
            
            // Optionally backup wp-config.php
            $settings = get_option( 'wp_github_sync_settings' );
            if ( ! empty( $settings['backup_config'] ) ) {
                $paths[] = 'wp-config.php';
            }
        }
        
        // Copy files to backup directory
        $successful_paths = array();
        foreach ( $paths as $path ) {
            $source = untrailingslashit( ABSPATH . $path );
            $destination = untrailingslashit( $backup_path . '/' . $path );
            
            if ( file_exists( $source ) ) {
                // Create parent directories if needed
                wp_mkdir_p( dirname( $destination ) );
                
                if ( is_dir( $source ) ) {
                    if ( $this->copy_directory( $source, $destination ) ) {
                        $successful_paths[] = $path;
                    } else {
                        $this->log_error( 'Failed to backup directory: ' . $path );
                    }
                } else {
                    if ( copy( $source, $destination ) ) {
                        $successful_paths[] = $path;
                    } else {
                        $this->log_error( 'Failed to backup file: ' . $path );
                    }
                }
            } else {
                $this->log_warning( 'Path does not exist, skipping: ' . $path );
            }
        }
        
        // If no paths were successfully backed up, clean up and return false
        if ( empty( $successful_paths ) ) {
            $this->delete_backup( $backup_id );
            return false;
        }
        
        // Save backup metadata
        $metadata = array(
            'id'           => $backup_id,
            'date'         => current_time( 'mysql' ),
            'timestamp'    => time(),
            'user_id'      => get_current_user_id(),
            'user_login'   => wp_get_current_user()->user_login,
            'paths'        => $successful_paths,
            'wp_version'   => get_bloginfo( 'version' ),
            'plugin_version' => defined( 'WP_GITHUB_SYNC_VERSION' ) ? WP_GITHUB_SYNC_VERSION : '2.0.0',
            'site_url'     => get_site_url(),
            'description'  => '',
        );
        
        file_put_contents( $backup_path . '/metadata.json', wp_json_encode( $metadata, JSON_PRETTY_PRINT ) );
        
        // Rotate old backups
        $this->rotate_backups();
        
        // Save backup history
        $this->add_to_backup_history( $backup_id, $metadata );
        
        return $backup_id;
    }
    
    /**
     * Copy directory recursively
     *
     * @param string $source      Source directory.
     * @param string $destination Destination directory.
     * @return bool True on success, false on failure
     */
    private function copy_directory( $source, $destination ) {
        // Create destination if it doesn't exist
        if ( ! is_dir( $destination ) ) {
            wp_mkdir_p( $destination );
        }
        
        // If source is not a directory, return false
        if ( ! is_dir( $source ) || ! is_readable( $source ) ) {
            return false;
        }
        
        $dir = opendir( $source );
        
        while ( false !== ( $file = readdir( $dir ) ) ) {
            if ( '.' === $file || '..' === $file ) {
                continue;
            }
            
            $src_file = $source . '/' . $file;
            $dst_file = $destination . '/' . $file;
            
            if ( is_dir( $src_file ) ) {
                if ( ! $this->copy_directory( $src_file, $dst_file ) ) {
                    closedir( $dir );
                    return false;
                }
            } else {
                if ( ! copy( $src_file, $dst_file ) ) {
                    closedir( $dir );
                    return false;
                }
            }
        }
        
        closedir( $dir );
        return true;
    }
    
    /**
     * List all available backups
     *
     * @return array List of backups with metadata
     */
    public function list_backups() {
        $backups = array();
        $backup_dirs = glob( $this->backup_dir . '/backup-*', GLOB_ONLYDIR );
        
        if ( ! is_array( $backup_dirs ) ) {
            return $backups;
        }
        
        foreach ( $backup_dirs as $dir ) {
            $backup_id = basename( $dir );
            $metadata_file = $dir . '/metadata.json';
            
            if ( file_exists( $metadata_file ) ) {
                $metadata = json_decode( file_get_contents( $metadata_file ), true );
                if ( is_array( $metadata ) ) {
                    $backups[ $backup_id ] = $metadata;
                }
            }
        }
        
        // Sort by timestamp in descending order
        uasort( $backups, function( $a, $b ) {
            return $b['timestamp'] - $a['timestamp'];
        } );
        
        return $backups;
    }
    
    /**
     * Restore from a backup
     *
     * @param string $backup_id The backup ID to restore.
     * @return bool Success or failure
     */
    public function restore_backup( $backup_id ) {
        $backup_path = $this->backup_dir . '/' . $backup_id;
        
        if ( ! file_exists( $backup_path ) || ! file_exists( $backup_path . '/metadata.json' ) ) {
            $this->log_error( 'Backup not found: ' . $backup_id );
            return false;
        }
        
        $metadata = json_decode( file_get_contents( $backup_path . '/metadata.json' ), true );
        
        if ( ! is_array( $metadata ) || empty( $metadata['paths'] ) ) {
            $this->log_error( 'Invalid backup metadata for: ' . $backup_id );
            return false;
        }
        
        // Create a backup of current state before restoring
        $current_backup_id = $this->create_backup( $metadata['paths'] );
        
        if ( ! $current_backup_id ) {
            $this->log_error( 'Failed to create pre-restore backup' );
            return false;
        }
        
        // Enable maintenance mode
        $this->enable_maintenance_mode();
        
        try {
            // Restore each path
            foreach ( $metadata['paths'] as $path ) {
                $source_path = untrailingslashit( $backup_path . '/' . $path );
                $dest_path = untrailingslashit( ABSPATH . $path );
                
                if ( ! file_exists( $source_path ) ) {
                    $this->log_warning( 'Source path does not exist in backup: ' . $path );
                    continue;
                }
                
                // If destination exists, remove it first
                if ( file_exists( $dest_path ) ) {
                    if ( is_dir( $dest_path ) ) {
                        // Recursively delete directory
                        $this->recursive_delete( $dest_path );
                    } else {
                        // Delete file
                        unlink( $dest_path );
                    }
                }
                
                // Create parent directories if needed
                wp_mkdir_p( dirname( $dest_path ) );
                
                if ( is_dir( $source_path ) ) {
                    if ( ! $this->copy_directory( $source_path, $dest_path ) ) {
                        throw new \Exception( 'Failed to restore directory: ' . $path );
                    }
                } else {
                    if ( ! copy( $source_path, $dest_path ) ) {
                        throw new \Exception( 'Failed to restore file: ' . $path );
                    }
                }
            }
            
            // Log successful restore
            $this->log_info( 'Successfully restored backup: ' . $backup_id );
            
            // Add to restore history
            $this->add_to_restore_history( $backup_id, $metadata );
            
            return true;
        } catch ( \Exception $e ) {
            $this->log_error( 'Restore failed: ' . $e->getMessage() );
            
            // Try to restore the pre-restore backup
            $this->log_info( 'Attempting to restore pre-restore backup: ' . $current_backup_id );
            $this->restore_backup( $current_backup_id );
            
            return false;
        } finally {
            // Disable maintenance mode
            $this->disable_maintenance_mode();
        }
    }
    
    /**
     * Delete a backup
     *
     * @param string $backup_id The backup ID to delete.
     * @return bool Success or failure
     */
    public function delete_backup( $backup_id ) {
        $backup_path = $this->backup_dir . '/' . $backup_id;
        
        if ( ! file_exists( $backup_path ) ) {
            $this->log_warning( 'Backup not found: ' . $backup_id );
            return false;
        }
        
        // Recursively delete directory
        return $this->recursive_delete( $backup_path );
    }
    
    /**
     * Recursively delete a directory
     *
     * @param string $dir Directory to delete.
     * @return bool Success or failure
     */
    private function recursive_delete( $dir ) {
        if ( ! is_dir( $dir ) ) {
            return false;
        }
        
        $files = array_diff( scandir( $dir ), array( '.', '..' ) );
        
        foreach ( $files as $file ) {
            $path = $dir . '/' . $file;
            
            if ( is_dir( $path ) ) {
                $this->recursive_delete( $path );
            } else {
                unlink( $path );
            }
        }
        
        return rmdir( $dir );
    }
    
    /**
     * Rotate old backups
     */
    private function rotate_backups() {
        if ( $this->max_backups <= 0 ) {
            return;
        }
        
        $backups = $this->list_backups();
        
        if ( count( $backups ) <= $this->max_backups ) {
            return;
        }
        
        // Sort by timestamp (oldest first)
        uasort( $backups, function( $a, $b ) {
            return $a['timestamp'] - $b['timestamp'];
        } );
        
        // Delete oldest backups
        $to_delete = array_slice( $backups, 0, count( $backups ) - $this->max_backups, true );
        
        foreach ( array_keys( $to_delete ) as $backup_id ) {
            $this->log_info( 'Rotating old backup: ' . $backup_id );
            $this->delete_backup( $backup_id );
        }
    }
    
    /**
     * Add backup to history
     *
     * @param string $backup_id Backup ID.
     * @param array  $metadata  Backup metadata.
     */
    private function add_to_backup_history( $backup_id, $metadata ) {
        $history = get_option( 'wp_github_sync_backup_history', array() );
        
        // Add to history (limit to last 100 entries)
        $history[ $backup_id ] = array(
            'id'        => $backup_id,
            'timestamp' => $metadata['timestamp'],
            'date'      => $metadata['date'],
            'user_id'   => $metadata['user_id'],
            'user_login' => $metadata['user_login'],
            'paths'     => $metadata['paths'],
        );
        
        // Sort by timestamp (newest first)
        uasort( $history, function( $a, $b ) {
            return $b['timestamp'] - $a['timestamp'];
        } );
        
        // Limit to 100 entries
        if ( count( $history ) > 100 ) {
            $history = array_slice( $history, 0, 100, true );
        }
        
        update_option( 'wp_github_sync_backup_history', $history, false );
    }
    
    /**
     * Add restore to history
     *
     * @param string $backup_id Backup ID.
     * @param array  $metadata  Backup metadata.
     */
    private function add_to_restore_history( $backup_id, $metadata ) {
        $history = get_option( 'wp_github_sync_restore_history', array() );
        
        // Add to history
        $history[] = array(
            'backup_id'  => $backup_id,
            'timestamp'  => time(),
            'date'       => current_time( 'mysql' ),
            'user_id'    => get_current_user_id(),
            'user_login' => wp_get_current_user()->user_login,
            'backup_date' => isset( $metadata['date'] ) ? $metadata['date'] : '',
        );
        
        // Limit to 100 entries
        if ( count( $history ) > 100 ) {
            $history = array_slice( $history, -100 );
        }
        
        update_option( 'wp_github_sync_restore_history', $history, false );
    }
    
    /**
     * Enable maintenance mode
     */
    private function enable_maintenance_mode() {
        $file = ABSPATH . '.maintenance';
        $maintenance = '<?php $upgrading = ' . time() . '; ?>';
        file_put_contents( $file, $maintenance );
    }
    
    /**
     * Disable maintenance mode
     */
    private function disable_maintenance_mode() {
        $file = ABSPATH . '.maintenance';
        if ( file_exists( $file ) ) {
            unlink( $file );
        }
    }
    
    /**
     * Log error message
     *
     * @param string $message Error message.
     */
    private function log_error( $message ) {
        $this->log_message( 'error', $message );
    }
    
    /**
     * Log warning message
     *
     * @param string $message Warning message.
     */
    private function log_warning( $message ) {
        $this->log_message( 'warning', $message );
    }
    
    /**
     * Log info message
     *
     * @param string $message Info message.
     */
    private function log_info( $message ) {
        $this->log_message( 'info', $message );
    }
    
    /**
     * Log message
     *
     * @param string $level   Log level.
     * @param string $message Log message.
     */
    private function log_message( $level, $message ) {
        if ( function_exists( 'wp_github_sync_log' ) ) {
            wp_github_sync_log( $message, $level );
        }
    }
}