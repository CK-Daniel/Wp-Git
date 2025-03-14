<?php
/**
 * GitHub Repository operations for the WordPress GitHub Sync plugin.
 *
 * @package WPGitHubSync
 */

namespace WPGitHubSync\API;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * GitHub Repository operations class.
 */
class Repository {

    /**
     * The version of this plugin.
     *
     * @var string
     */
    private $version;

    /**
     * Initialize the Repository class.
     *
     * @param string $version The version of this plugin.
     */
    public function __construct( $version ) {
        $this->version = $version;
    }

    /**
     * Download a repository archive to a specific directory.
     *
     * @param string $ref        The branch or commit reference.
     * @param string $target_dir The directory to extract to.
     * @return bool|\WP_Error True on success or WP_Error on failure.
     */
    public function download_repository($ref = 'main', $target_dir = '') {
        if (empty($target_dir)) {
            return new \WP_Error('missing_target_dir', __('Target directory not specified.', 'wp-github-sync'));
        }
        
        // Create target directory if it doesn't exist
        if (!file_exists($target_dir)) {
            wp_mkdir_p($target_dir);
        }
        
        // Get the API client
        $api_client = new Client($this->version);
        
        // Get archive URL
        $archive_url = $api_client->get_archive_url($ref);
        
        // Download the zip file to a temporary file
        $temp_file = download_url($archive_url, 300);
        
        if (is_wp_error($temp_file)) {
            wp_github_sync_log('Failed to download repository archive: ' . $temp_file->get_error_message(), 'error');
            return $temp_file;
        }
        
        // Extract the zip file
        $result = $this->extract_zip($temp_file, $target_dir);
        
        // Clean up temp file
        @unlink($temp_file);
        
        return $result;
    }

    /**
     * Extract a zip file to a directory.
     *
     * @param string $file       The path to the zip file.
     * @param string $target_dir The directory to extract to.
     * @return bool|\WP_Error True on success or WP_Error on failure.
     */
    private function extract_zip($file, $target_dir) {
        global $wp_filesystem;
        
        // Initialize the WordPress filesystem
        if (empty($wp_filesystem)) {
            // Check if we're in test mode
            if (defined('WP_GITHUB_SYNC_TESTING') && WP_GITHUB_SYNC_TESTING) {
                WP_Filesystem();
            } else {
                require_once ABSPATH . '/wp-admin/includes/file.php';
                WP_Filesystem();
            }
        }
        
        wp_github_sync_log("Extracting zip file to: {$target_dir}", 'debug');
        
        // Use unzip_file which uses ZipArchive or PclZip
        $result = unzip_file($file, $target_dir);
        
        if (is_wp_error($result)) {
            wp_github_sync_log('Failed to extract zip file: ' . $result->get_error_message(), 'error');
            return $result;
        }
        
        // Move files from the extracted directory (which includes owner/repo-branch/) to target
        $extracted_dirs = glob($target_dir . '/*', GLOB_ONLYDIR);
        
        if (empty($extracted_dirs)) {
            wp_github_sync_log("No directories found after extraction in: {$target_dir}", 'error');
            return new \WP_Error('no_extracted_dirs', __('No directories found after extraction.', 'wp-github-sync'));
        }
        
        $extracted_dir = reset($extracted_dirs);
        $extracted_contents = glob($extracted_dir . '/*');
        
        wp_github_sync_log("Found extracted directory: {$extracted_dir} with " . count($extracted_contents) . " items", 'debug');
        
        if (!empty($extracted_contents)) {
            foreach ($extracted_contents as $item) {
                $basename = basename($item);
                $destination = $target_dir . '/' . $basename;
                
                // If destination exists, remove it first
                if (file_exists($destination)) {
                    if (is_dir($destination)) {
                        wp_github_sync_log("Removing existing directory: {$destination}", 'debug');
                        $wp_filesystem->rmdir($destination, true);
                    } else {
                        wp_github_sync_log("Removing existing file: {$destination}", 'debug');
                        $wp_filesystem->delete($destination);
                    }
                }
                
                // Move the item to the destination
                wp_github_sync_log("Moving {$item} to {$destination}", 'debug');
                if (!rename($item, $destination)) {
                    wp_github_sync_log("Failed to move {$item} to {$destination}", 'error');
                    
                    // Try copying instead of moving if rename fails
                    if (is_dir($item)) {
                        if (!$this->copy_directory($item, $destination)) {
                            wp_github_sync_log("Failed to copy directory {$item} to {$destination}", 'error');
                        } else {
                            $this->recursive_rmdir($item);
                        }
                    } else {
                        if (!copy($item, $destination)) {
                            wp_github_sync_log("Failed to copy file {$item} to {$destination}", 'error');
                        } else {
                            unlink($item);
                        }
                    }
                }
            }
        }
        
        // Remove the extracted dir (now empty)
        wp_github_sync_log("Removing extracted directory: {$extracted_dir}", 'debug');
        $wp_filesystem->rmdir($extracted_dir, true);
        
        // Verify the extraction was successful
        $files_count = count(glob($target_dir . '/*'));
        wp_github_sync_log("Extraction complete. Found {$files_count} files/directories in target directory", 'debug');
        
        if ($files_count === 0) {
            wp_github_sync_log("Extraction failed: no files in target directory", 'error');
            return new \WP_Error('extraction_failed', __('Extraction failed: no files in target directory.', 'wp-github-sync'));
        }
        
        return true;
    }

    /**
     * Compare two references (branches or commits) to get the differences.
     *
     * @param string $base The base reference.
     * @param string $head The head reference.
     * @return array|\WP_Error Comparison data or WP_Error on failure.
     */
    public function compare($base, $head) {
        $api_client = new Client($this->version);
        return $api_client->request(
            "repos/{$api_client->get_owner()}/{$api_client->get_repo()}/compare/{$base}...{$head}"
        );
    }
    
    /**
     * Copy a directory recursively.
     *
     * @param string $source The source directory.
     * @param string $dest   The destination directory.
     * @return bool True on success or false on failure.
     */
    private function copy_directory($source, $dest) {
        // Create destination directory if it doesn't exist
        if (!file_exists($dest)) {
            wp_mkdir_p($dest);
        }
        
        // Get all files and directories
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $item) {
            $target_path = $dest . '/' . $iterator->getSubPathName();
            
            if ($item->isDir()) {
                // Create directory if it doesn't exist
                if (!file_exists($target_path)) {
                    wp_mkdir_p($target_path);
                }
            } else {
                // Copy file
                copy($item, $target_path);
            }
        }
        
        return true;
    }
    
    /**
     * Recursively remove a directory.
     *
     * @param string $dir The directory to remove.
     * @return bool True on success or false on failure.
     */
    private function recursive_rmdir($dir) {
        if (!file_exists($dir)) {
            return true;
        }
        
        if (!is_dir($dir)) {
            return unlink($dir);
        }
        
        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') {
                continue;
            }
            
            $path = $dir . '/' . $item;
            
            if (is_dir($path)) {
                $this->recursive_rmdir($path);
            } else {
                unlink($path);
            }
        }
        
        return rmdir($dir);
    }
}