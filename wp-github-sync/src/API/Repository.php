<?php
/**
 * GitHub Repository operations for the WordPress GitHub Sync plugin.
 *
 * @package WPGitHubSync
 */

namespace WPGitHubSync\API;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * GitHub Repository operations class.
 */
class Repository {

    /**
     * API Client instance.
     *
     * @var API_Client
     */
    private $api_client;
    
    /**
     * Progress callback function
     * 
     * @var callable|null
     */
    private $progress_callback = null;
    
    /**
     * Repository uploader instance
     * 
     * @var Repository_Uploader|null
     */
    private $uploader = null;
    
    /**
     * Statistics and reporting data
     *
     * @var array
     */
    private $stats = [];

    /**
     * Initialize the Repository class.
     *
     * @param API_Client $api_client The API client instance.
     */
    public function __construct($api_client) {
        $this->api_client = $api_client;
        $this->uploader = new Repository_Uploader($api_client);
    }
    
    /**
     * Set a progress callback function
     * 
     * @param callable $callback Function that takes ($subStep, $detail, $stats)
     * @return void
     */
    public function set_progress_callback($callback) {
        $this->progress_callback = $callback;
        if ($this->uploader) {
            $this->uploader->set_progress_callback($callback);
        }
    }
    
    /**
     * Update progress via callback
     * 
     * @param int $subStep Sub-step number
     * @param string $detail Progress detail message
     * @param array $stats Optional stats array
     */
    private function update_progress($subStep, $detail, $stats = []) {
        if (is_callable($this->progress_callback)) {
            call_user_func($this->progress_callback, $subStep, $detail, $stats);
        }
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
        
        // Get archive URL
        $archive_url = $this->api_client->get_archive_url($ref);
        
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
                        if (!@copy($item, $destination)) {
                            wp_github_sync_log("Failed to copy file {$item} to {$destination}", 'error');
                        } else {
                            @unlink($item);
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
        return $this->api_client->request(
            "repos/{$this->api_client->get_owner()}/{$this->api_client->get_repo()}/compare/{$base}...{$head}"
        );
    }
    
    /**
     * Create an initial commit with WordPress files to a new repository.
     * This implementation uses a chunked processing approach to avoid timeouts.
     * 
     * @param string $branch The branch name to commit to.
     * @return bool|\WP_Error True on success or WP_Error on failure.
     */
    public function initial_sync($branch = 'main') {
        // Check if we're in a chunked sync process already
        $sync_state = get_option('wp_github_sync_chunked_sync_state', null);
        if ($sync_state) {
            return $this->continue_chunked_sync($sync_state, $branch);
        }
        
        // Enforce reasonable timeout limit without being too aggressive
        $current_timeout = ini_get('max_execution_time');
        if ($current_timeout > 0 && $current_timeout < 120) {
            set_time_limit(120); // Just 2 minutes per chunk is reasonable
        }
        
        // Also increase memory limit if possible
        $current_memory_limit = ini_get('memory_limit');
        $current_memory_bytes = wp_convert_hr_to_bytes($current_memory_limit);
        $desired_memory_bytes = wp_convert_hr_to_bytes('256M');
        
        if ($current_memory_bytes < $desired_memory_bytes) {
            // Try to increase memory limit to handle large repositories
            wp_github_sync_log("Current memory limit: {$current_memory_limit}, attempting to increase to 256M", 'debug');
            
            try {
                ini_set('memory_limit', '256M');
                $new_limit = ini_get('memory_limit');
                wp_github_sync_log("Memory limit now: {$new_limit}", 'debug');
            } catch (\Exception $e) {
                wp_github_sync_log("Could not increase memory limit: " . $e->getMessage(), 'warning');
            }
        }
        
        // Reset statistics
        $this->stats = [
            'start_time' => microtime(true),
            'files_scanned' => 0,
            'files_skipped' => 0,
            'files_included' => 0,
            'total_size' => 0,
            'large_files_found' => 0,
            'errors' => [],
            'warnings' => [],
            'skipped_files' => []
        ];
        
        // Setup chunked sync state
        $chunked_sync_state = [
            'timestamp' => time(),
            'branch' => $branch,
            'stage' => 'authentication',
            'progress_step' => 0
        ];
        
        update_option('wp_github_sync_chunked_sync_state', $chunked_sync_state);
        
        // Register a shutdown function to catch fatal errors
        register_shutdown_function(function() {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
                // Log the fatal error
                wp_github_sync_log("Fatal error during sync: " . $error['message'] . " in " . $error['file'] . " on line " . $error['line'], 'error');
                
                // Update chunked sync state with error
                $sync_state = get_option('wp_github_sync_chunked_sync_state', []);
                $sync_state['fatal_error'] = $error;
                $sync_state['status'] = 'failed';
                update_option('wp_github_sync_chunked_sync_state', $sync_state);
                
                // Schedule a recovery attempt
                wp_schedule_single_event(time() + 30, 'wp_github_sync_process_chunk');
            }
        });
        // First verify authentication is working
        $this->update_progress(0, "Verifying authentication");
        $auth_test = $this->api_client->test_authentication();
        if ($auth_test !== true) {
            wp_github_sync_log("Initial sync authentication test failed: " . $auth_test, 'error');
            $this->update_progress(0, "Authentication failed: " . $auth_test);
            delete_option('wp_github_sync_chunked_sync_state');
            return new \WP_Error('github_auth_failed', sprintf(__('GitHub authentication failed: %s', 'wp-github-sync'), $auth_test));
        }
        
        $chunked_sync_state = get_option('wp_github_sync_chunked_sync_state');
        $chunked_sync_state['stage'] = 'repository_check';
        $chunked_sync_state['progress_step'] = 1;
        update_option('wp_github_sync_chunked_sync_state', $chunked_sync_state);
        
        $this->update_progress(1, "Authentication verified successfully");
        
        // Verify repository exists and initialize it if needed
        $this->update_progress(2, "Verifying repository access");
        $repo_info = $this->api_client->get_repository();
        if (is_wp_error($repo_info)) {
            $error_message = $repo_info->get_error_message();
            
            // Check if this is an empty repository error or any 404 error
            if (strpos($error_message, 'Git Repository is empty') !== false || 
                strpos($error_message, 'Not Found') !== false ||
                strpos($error_message, '404') !== false) {
                
                wp_github_sync_log("Repository is empty or not initialized, initializing it before proceeding", 'info');
                $this->update_progress(2, "Repository is empty, performing initialization");
                
                // Try to initialize the repository
                $this->update_progress(3, "Creating initial repository structure");
                $init_result = $this->api_client->initialize_repository($branch);
                
                if (is_wp_error($init_result)) {
                    wp_github_sync_log("Failed to initialize repository through API client: " . $init_result->get_error_message(), 'warning');
                    
                    // Try a different approach - direct initialization through repository uploader
                    wp_github_sync_log("Attempting alternative initialization approach", 'info');
                    $uploader = new Repository_Uploader($this->api_client);
                    
                    // Create test content
                    $temp_dir = wp_tempnam('wp-github-sync-init-');
                    @unlink($temp_dir); // Remove the file so we can create a directory
                    
                    if (wp_mkdir_p($temp_dir)) {
                        // Create a basic README file
                        $site_name = get_bloginfo('name');
                        $site_url = get_bloginfo('url');
                        $readme_content = "# {$site_name}\n\nWordPress site synced with GitHub.\n\nSite URL: {$site_url}\n\nInitialized by WordPress GitHub Sync plugin.\n";
                        
                        file_put_contents($temp_dir . '/README.md', $readme_content);
                        
                        // Upload this file to initialize the repo
                        $alt_init_result = $uploader->upload_files_to_github($temp_dir, $branch, "Initialize repository for WordPress GitHub Sync");
                        
                        // Clean up temporary directory
                        $this->recursive_rmdir($temp_dir);
                        
                        if (is_wp_error($alt_init_result)) {
                            wp_github_sync_log("Alternative initialization also failed: " . $alt_init_result->get_error_message(), 'error');
                            delete_option('wp_github_sync_chunked_sync_state');
                            return new \WP_Error('repo_init_failed', sprintf(__('Failed to initialize repository: %s', 'wp-github-sync'), $alt_init_result->get_error_message()));
                        }
                        
                        wp_github_sync_log("Repository initialized successfully through alternative method, continuing with sync", 'info');
                    } else {
                        wp_github_sync_log("Failed to create temporary directory for alternative initialization", 'error');
                        delete_option('wp_github_sync_chunked_sync_state');
                        return new \WP_Error('repo_init_failed', sprintf(__('Failed to initialize repository: %s. Could not create temporary directory.', 'wp-github-sync'), $init_result->get_error_message()));
                    }
                } else {
                    wp_github_sync_log("Repository initialized successfully, continuing with sync", 'info');
                }
            } else {
                wp_github_sync_log("Failed to access repository: " . $error_message, 'error');
                delete_option('wp_github_sync_chunked_sync_state');
                return new \WP_Error('repo_access_failed', sprintf(__('Failed to access repository: %s', 'wp-github-sync'), $error_message));
            }
        }
        
        // Update chunked sync state to move to the next phase
        $chunked_sync_state = get_option('wp_github_sync_chunked_sync_state');
        $chunked_sync_state['stage'] = 'prepare_temp_directory';
        $chunked_sync_state['progress_step'] = 3;
        update_option('wp_github_sync_chunked_sync_state', $chunked_sync_state);
        
        // Set up basic commit information
        try {
            $user = $this->api_client->get_user();
            
            if (is_wp_error($user)) {
                $error_message = $user->get_error_message();
                wp_github_sync_log("Failed to get user info: " . $error_message, 'error');
                delete_option('wp_github_sync_chunked_sync_state');
                return new \WP_Error('github_api_user_error', sprintf(__('Failed to get GitHub user info: %s', 'wp-github-sync'), $error_message));
            }
            
            // Log user info for debugging
            if (isset($user['login'])) {
                wp_github_sync_log("Performing initial sync as GitHub user: " . $user['login'], 'info');
            }
            
            // Get site info for commit message
            $site_url = get_bloginfo('url');
            $site_name = get_bloginfo('name');
            
            wp_github_sync_log("Creating initial sync for site: {$site_name} ({$site_url})", 'info');
            
            // Create temporary directory
            try {
                $temp_dir = wp_tempnam('wp-github-sync-');
                if (empty($temp_dir)) {
                    wp_github_sync_log("Failed to create temporary filename with wp_tempnam", 'error');
                    delete_option('wp_github_sync_chunked_sync_state');
                    return new \WP_Error('temp_name_failed', __('Failed to create temporary filename for sync', 'wp-github-sync'));
                }
                
                @unlink($temp_dir); // Remove the file so we can create a directory
                wp_github_sync_log("Creating temporary directory for initial sync: {$temp_dir}", 'debug');
                
                if (!wp_mkdir_p($temp_dir)) {
                    wp_github_sync_log("Failed to create temporary directory: {$temp_dir}", 'error');
                    delete_option('wp_github_sync_chunked_sync_state');
                    return new \WP_Error('temp_dir_creation_failed', sprintf(__('Failed to create temporary directory for sync: %s', 'wp-github-sync'), $temp_dir));
                }
                
                if (!is_dir($temp_dir) || !is_writable($temp_dir)) {
                    wp_github_sync_log("Temporary directory is not accessible or writable: {$temp_dir}", 'error');
                    delete_option('wp_github_sync_chunked_sync_state');
                    return new \WP_Error('temp_dir_not_writable', sprintf(__('Temporary directory not accessible or writable: %s', 'wp-github-sync'), $temp_dir));
                }
                
                // Update sync state with temporary directory location
                $chunked_sync_state = get_option('wp_github_sync_chunked_sync_state');
                $chunked_sync_state['temp_dir'] = $temp_dir;
                $chunked_sync_state['site_name'] = $site_name;
                $chunked_sync_state['site_url'] = $site_url;
                
                // Define paths to sync
                $paths_to_sync = apply_filters('wp_github_sync_paths', [
                    'wp-content/themes' => true,
                    'wp-content/plugins' => true,
                    'wp-content/uploads' => false, // Default to not sync media
                ]);
                
                // Store paths in sync state
                $chunked_sync_state['paths_to_sync'] = $paths_to_sync;
                $chunked_sync_state['stage'] = 'collecting_files';
                $chunked_sync_state['progress_step'] = 4;
                $chunked_sync_state['current_path_index'] = 0;
                update_option('wp_github_sync_chunked_sync_state', $chunked_sync_state);
                
                wp_github_sync_log("Starting chunked file processing", 'info');
                $this->update_progress(4, "Starting chunked file collection");
                
                // Schedule the first chunk
                wp_schedule_single_event(time(), 'wp_github_sync_process_chunk');
                
                // Return a success message but note that processing will continue in the background
                return new \WP_Error(
                    'sync_in_progress', 
                    __('The initial sync has started and will continue in the background. This may take several minutes depending on the size of your site.', 'wp-github-sync')
                );
                
            } catch (\Exception $temp_dir_exception) {
                // Catch any exceptions during temporary directory setup
                $error_message = $temp_dir_exception->getMessage();
                wp_github_sync_log("Exception during temporary directory setup: " . $error_message, 'error');
                
                // Try to clean up if temp_dir is set
                if (!empty($temp_dir) && is_dir($temp_dir)) {
                    $this->recursive_rmdir($temp_dir);
                }
                
                delete_option('wp_github_sync_chunked_sync_state');
                return new \WP_Error('temp_dir_exception', sprintf(__('Exception during temporary directory setup: %s', 'wp-github-sync'), $error_message));
            }
        } catch (\Exception $e) {
            // Catch any exceptions during the entire process
            $error_message = $e->getMessage();
            wp_github_sync_log("Critical exception during initial sync setup: " . $error_message, 'error');
            delete_option('wp_github_sync_chunked_sync_state');
            return new \WP_Error('critical_sync_exception', sprintf(__('Critical error during initial sync setup: %s', 'wp-github-sync'), $error_message));
        }
    }
    
    /**
     * Prepare files for initial sync by copying them to a temporary directory.
     *
     * @param string $temp_dir     The temporary directory to copy files to.
     * @param array  $paths_to_sync Associative array of paths to sync and whether to include them.
     * @return bool|\WP_Error True on success or WP_Error on failure.
     */
    private function prepare_files_for_initial_sync($temp_dir, $paths_to_sync) {
        // Get WP path constants
        $wp_content_dir = WP_CONTENT_DIR;
        $abspath = ABSPATH;
        
        // Create wp-content directory in temp dir
        wp_mkdir_p($temp_dir . '/wp-content');
        
        wp_github_sync_log("Preparing to sync files from {$wp_content_dir} to temporary directory", 'debug');
        
        // Validate temporary directory (security)
        if (!$this->is_safe_path($temp_dir) || !is_dir($temp_dir) || !is_writable($temp_dir)) {
            wp_github_sync_log("Temporary directory is invalid or not writable: {$temp_dir}", 'error');
            return new \WP_Error('invalid_temp_dir', __('Temporary directory is invalid or not writable', 'wp-github-sync'));
        }
        
        // Copy each path that's enabled
        foreach ($paths_to_sync as $path => $include) {
            // Path validation for security
            if (!$this->is_valid_path_key($path)) {
                wp_github_sync_log("Invalid path specified in configuration: {$path}", 'error');
                continue;
            }
            
            if (!$include) {
                wp_github_sync_log("Skipping path {$path} (disabled in config)", 'debug');
                continue;
            }
            
            // Using WP_CONTENT_DIR directly to make testing easier
            if (strpos($path, 'wp-content/') === 0) {
                $rel_path = substr($path, strlen('wp-content/'));
                $source_path = rtrim($wp_content_dir, '/') . '/' . $rel_path;
            } else {
                // Don't allow paths outside of WordPress installation
                wp_github_sync_log("Only paths within wp-content are allowed: {$path}", 'error');
                continue;
            }
            
            // Security: Prevent path traversal attacks
            if (!$this->is_safe_path($source_path)) {
                wp_github_sync_log("Path traversal detected in: {$source_path}", 'error');
                continue;
            }
            
            // Safely combine paths to prevent directory traversal
            $dest_path = $temp_dir . '/' . $this->normalize_path($path);
            
            wp_github_sync_log("Processing path {$path}", 'debug');
            wp_github_sync_log("Source: {$source_path}", 'debug');
            wp_github_sync_log("Destination: {$dest_path}", 'debug');
            
            // Make sure source path exists and is within the allowed WP directory
            if (!file_exists($source_path) || !$this->is_within_wordpress($source_path)) {
                wp_github_sync_log("Source path doesn't exist or is outside WordPress: {$source_path}, skipping", 'warning');
                continue;
            }
            
            // Create destination directory
            wp_mkdir_p(dirname($dest_path));
            
            // Copy directory
            $copy_result = $this->copy_directory($source_path, $dest_path);
            
            if ($copy_result) {
                wp_github_sync_log("Successfully copied {$path} to temporary directory", 'debug');
            } else {
                wp_github_sync_log("Failed to copy {$path} to temporary directory", 'error');
            }
        }
        
        // List files in temp directory to verify
        $this->list_directory_recursive($temp_dir);
        
        return true;
    }
    
    /**
     * Check if a path is valid for use as a sync path key
     *
     * @param string $path The path to check
     * @return bool True if the path is valid
     */
    private function is_valid_path_key($path) {
        // Only allow alphanumeric characters, slashes, hyphens, underscores
        return (bool) preg_match('/^[a-zA-Z0-9\/_\-\.]+$/', $path);
    }
    
    /**
     * Check if a path is safe (no directory traversal)
     *
     * @param string $path The path to check
     * @return bool True if the path is safe
     */
    private function is_safe_path($path) {
        // Check for directory traversal attempts
        if (strpos($path, '..') !== false) {
            return false;
        }
        
        // No null bytes
        if (strpos($path, "\0") !== false) {
            return false;
        }
        
        // No potentially dangerous path segments
        $normalized = $this->normalize_path($path);
        return (strpos($normalized, '../') === false);
    }
    
    /**
     * Normalize a path by resolving directory traversal
     *
     * @param string $path The path to normalize
     * @return string The normalized path
     */
    private function normalize_path($path) {
        // Convert backslashes to forward slashes
        $path = str_replace('\\', '/', $path);
        
        // Remove any "." segments
        $path = str_replace('/./', '/', $path);
        
        // Remove duplicate slashes
        $path = preg_replace('#/{2,}#', '/', $path);
        
        // Remove trailing slash
        $path = rtrim($path, '/');
        
        return $path;
    }
    
    /**
     * Check if a path is within the WordPress installation
     *
     * @param string $path The path to check
     * @return bool True if the path is within WordPress
     */
    private function is_within_wordpress($path) {
        $real_path = realpath($path);
        $wp_content_real = realpath(WP_CONTENT_DIR);
        
        if ($real_path === false || $wp_content_real === false) {
            return false;
        }
        
        return strpos($real_path, $wp_content_real) === 0;
    }
    
    /**
     * List files in a directory recursively for debugging.
     *
     * @param string $dir The directory to list.
     * @param string $prefix Prefix for indentation in recursive calls.
     */
    private function list_directory_recursive($dir, $prefix = '') {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file == '.' || $file == '..') {
                continue;
            }
            
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                wp_github_sync_log("{$prefix}[DIR] {$file}/", 'debug');
                $this->list_directory_recursive($path, $prefix . '  ');
            } else {
                wp_github_sync_log("{$prefix}[FILE] {$file} (" . filesize($path) . " bytes)", 'debug');
            }
        }
    }
    
    /**
     * Continue a chunked sync process based on saved state
     *
     * @param array $sync_state The saved sync state
     * @param string $branch The branch name to commit to
     * @return bool|\WP_Error True on success or WP_Error on failure
     */
    private function continue_chunked_sync($sync_state, $branch) {
        wp_github_sync_log("Continuing chunked sync process at stage: " . $sync_state['stage'], 'info');
        
        // Ensure we have a temp directory
        $temp_dir = isset($sync_state['temp_dir']) ? $sync_state['temp_dir'] : '';
        if (empty($temp_dir) || !is_dir($temp_dir)) {
            wp_github_sync_log("Temporary directory missing in chunked sync state", 'error');
            delete_option('wp_github_sync_chunked_sync_state');
            return new \WP_Error('chunked_sync_error', __('Temporary directory missing in chunked sync state', 'wp-github-sync'));
        }
        
        // Update progress for user
        $this->update_progress($sync_state['progress_step'] ?? 5, 
                              "Resuming sync process - Stage: " . $sync_state['stage']);
        
        switch ($sync_state['stage']) {
            case 'collecting_files':
                return $this->process_chunked_file_collection($sync_state, $branch);
                
            case 'uploading_files':
                return $this->process_chunked_upload($sync_state, $branch);
                
            default:
                wp_github_sync_log("Unknown chunked sync stage: " . $sync_state['stage'], 'error');
                delete_option('wp_github_sync_chunked_sync_state');
                return new \WP_Error('chunked_sync_error', __('Unknown chunked sync stage', 'wp-github-sync'));
        }
    }
    
    /**
     * Process chunked file collection
     *
     * @param array $sync_state The saved sync state
     * @param string $branch The branch name to commit to
     * @return bool|\WP_Error True on success or WP_Error on failure
     */
    private function process_chunked_file_collection($sync_state, $branch) {
        $temp_dir = $sync_state['temp_dir'];
        $paths_to_sync = $sync_state['paths_to_sync'];
        $current_path_index = $sync_state['current_path_index'] ?? 0;
        $path_keys = array_keys($paths_to_sync);
        
        // Process the current path
        if (isset($path_keys[$current_path_index])) {
            $current_path = $path_keys[$current_path_index];
            $include = $paths_to_sync[$current_path];
            
            if ($include) {
                $this->update_progress(5, "Processing directory {$current_path_index + 1}/{" . count($path_keys) . "}: {$current_path}");
                wp_github_sync_log("Processing directory chunk: {$current_path}", 'info');
                
                // Using WP_CONTENT_DIR directly to make testing easier
                if (strpos($current_path, 'wp-content/') === 0) {
                    $rel_path = substr($current_path, strlen('wp-content/'));
                    $source_path = rtrim(WP_CONTENT_DIR, '/') . '/' . $rel_path;
                } else {
                    // Don't allow paths outside of WordPress installation
                    wp_github_sync_log("Only paths within wp-content are allowed: {$current_path}", 'error');
                    
                    // Skip to next path
                    $sync_state['current_path_index'] = $current_path_index + 1;
                    update_option('wp_github_sync_chunked_sync_state', $sync_state);
                    return $this->continue_chunked_sync($sync_state, $branch);
                }
                
                // Security: Prevent path traversal attacks
                if (!$this->is_safe_path($source_path)) {
                    wp_github_sync_log("Path traversal detected in: {$source_path}", 'error');
                    
                    // Skip to next path
                    $sync_state['current_path_index'] = $current_path_index + 1;
                    update_option('wp_github_sync_chunked_sync_state', $sync_state);
                    return $this->continue_chunked_sync($sync_state, $branch);
                }
                
                // Safely combine paths to prevent directory traversal
                $dest_path = $temp_dir . '/' . $this->normalize_path($current_path);
                
                // Make sure source path exists and is within the allowed WP directory
                if (!file_exists($source_path) || !$this->is_within_wordpress($source_path)) {
                    wp_github_sync_log("Source path doesn't exist or is outside WordPress: {$source_path}, skipping", 'warning');
                    
                    // Skip to next path
                    $sync_state['current_path_index'] = $current_path_index + 1;
                    update_option('wp_github_sync_chunked_sync_state', $sync_state);
                    return $this->continue_chunked_sync($sync_state, $branch);
                }
                
                // Create destination directory
                wp_mkdir_p(dirname($dest_path));
                
                // Copy directory in smaller chunks if needed
                if (!isset($sync_state['subdir_queue'])) {
                    // Initialize subdirectory queue for chunked processing
                    $sync_state['subdir_queue'] = [$source_path];
                    $sync_state['dest_base_path'] = $dest_path;
                    $sync_state['source_base_path'] = $source_path;
                    $sync_state['files_copied'] = 0;
                    update_option('wp_github_sync_chunked_sync_state', $sync_state);
                }
                
                return $this->process_chunked_directory($sync_state, $branch);
            } else {
                // Path is disabled, skip to next
                $sync_state['current_path_index'] = $current_path_index + 1;
                update_option('wp_github_sync_chunked_sync_state', $sync_state);
                return $this->continue_chunked_sync($sync_state, $branch);
            }
        } else {
            // All paths processed, move to next stage
            wp_github_sync_log("All directories processed, moving to upload stage", 'info');
            
            // Add standard files like README.md and .gitignore
            $this->update_progress(6, "Creating standard repository files");
            $result = $this->create_standard_repo_files($temp_dir, $branch);
            if (is_wp_error($result)) {
                $this->recursive_rmdir($temp_dir);
                delete_option('wp_github_sync_chunked_sync_state');
                return $result;
            }
            
            // Move to upload stage
            $sync_state['stage'] = 'uploading_files';
            $sync_state['progress_step'] = 7;
            update_option('wp_github_sync_chunked_sync_state', $sync_state);
            return $this->continue_chunked_sync($sync_state, $branch);
        }
    }
    
    /**
     * Process a chunked directory copy
     *
     * @param array $sync_state The saved sync state
     * @param string $branch The branch name to commit to
     * @return bool|\WP_Error True on success or WP_Error on failure
     */
    private function process_chunked_directory($sync_state, $branch) {
        $max_files_per_chunk = 50; // Process 50 files per chunk
        $files_processed = 0;
        
        if (empty($sync_state['subdir_queue'])) {
            // Queue empty, move to next path
            $sync_state['current_path_index'] += 1;
            unset($sync_state['subdir_queue']);
            unset($sync_state['dest_base_path']);
            unset($sync_state['source_base_path']);
            update_option('wp_github_sync_chunked_sync_state', $sync_state);
            return $this->continue_chunked_sync($sync_state, $branch);
        }
        
        // Get current directory to process
        $current_dir = array_shift($sync_state['subdir_queue']);
        $source_base_path = $sync_state['source_base_path'];
        $dest_base_path = $sync_state['dest_base_path'];
        
        // Calculate relative path
        $rel_path = substr($current_dir, strlen($source_base_path));
        $dest_dir = $dest_base_path . $rel_path;
        
        // Create destination directory
        if (!file_exists($dest_dir)) {
            wp_mkdir_p($dest_dir);
        }
        
        // Process directory content
        $items = scandir($current_dir);
        $subdirs = [];
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            
            $source_path = $current_dir . '/' . $item;
            $dest_path = $dest_dir . '/' . $item;
            
            if (is_dir($source_path)) {
                // Add to subdirectory queue for later processing
                $subdirs[] = $source_path;
            } else {
                // Copy file
                if (copy($source_path, $dest_path)) {
                    $files_processed++;
                    $sync_state['files_copied']++;
                    
                    // Break if we've processed enough files for this chunk
                    if ($files_processed >= $max_files_per_chunk) {
                        break;
                    }
                } else {
                    wp_github_sync_log("Failed to copy file: {$source_path}", 'error');
                }
            }
        }
        
        // Update progress
        $this->update_progress(5, "Copied {$sync_state['files_copied']} files so far");
        
        // If we have subdirectories, add them to the queue
        if (!empty($subdirs)) {
            $sync_state['subdir_queue'] = array_merge($subdirs, $sync_state['subdir_queue']);
        }
        
        // Save state and continue
        update_option('wp_github_sync_chunked_sync_state', $sync_state);
        
        if ($files_processed >= $max_files_per_chunk) {
            // Schedule next chunk to run immediately
            wp_schedule_single_event(time(), 'wp_github_sync_process_chunk');
            wp_github_sync_log("Scheduled next chunk after processing {$files_processed} files", 'info');
            return true; // Return true to indicate that processing will continue
        } else {
            // Continue immediately with next directory
            return $this->continue_chunked_sync($sync_state, $branch);
        }
    }
    
    /**
     * Process chunked file upload
     *
     * @param array $sync_state The saved sync state
     * @param string $branch The branch name to commit to
     * @return bool|\WP_Error True on success or WP_Error on failure
     */
    private function process_chunked_upload($sync_state, $branch) {
        $temp_dir = $sync_state['temp_dir'];
        $site_name = isset($sync_state['site_name']) ? $sync_state['site_name'] : get_bloginfo('name');
        
        try {
            $this->update_progress(7, "Uploading files to GitHub");
            wp_github_sync_log("Starting upload to GitHub", 'info');
            
            // Create an uploader instance
            $uploader = new Repository_Uploader($this->api_client);
            
            // Upload files to GitHub
            $result = $uploader->upload_files_to_github($temp_dir, $branch, "Initial sync from {$site_name}");
            
            // Clean up temporary directory
            wp_github_sync_log("Cleaning up temporary directory", 'debug');
            $this->recursive_rmdir($temp_dir);
            
            // Clean up chunked sync state
            delete_option('wp_github_sync_chunked_sync_state');
            
            if (is_wp_error($result)) {
                $error_message = $result->get_error_message();
                wp_github_sync_log("Upload failed: " . $error_message, 'error');
                return new \WP_Error('upload_failed', sprintf(__('Failed to upload to GitHub: %s', 'wp-github-sync'), $error_message));
            }
            
            $this->update_progress(8, "Initial sync completed successfully");
            wp_github_sync_log("Initial sync completed successfully", 'info');
            return $result;
        } catch (\Exception $upload_exception) {
            // Clean up
            $this->recursive_rmdir($temp_dir);
            delete_option('wp_github_sync_chunked_sync_state');
            
            // Log exception details
            $error_message = $upload_exception->getMessage();
            $trace = $upload_exception->getTraceAsString();
            wp_github_sync_log("Exception during upload to GitHub: " . $error_message, 'error');
            wp_github_sync_log("Stack trace: " . $trace, 'error');
            
            return new \WP_Error('upload_exception', sprintf(__('Exception during upload to GitHub: %s', 'wp-github-sync'), $error_message));
        }
    }
    
    /**
     * Create standard repository files (README.md, .gitignore)
     *
     * @param string $temp_dir The temporary directory
     * @param string $branch The branch name
     * @return bool|\WP_Error True on success or WP_Error on failure
     */
    private function create_standard_repo_files($temp_dir, $branch) {
        try {
            // Get site info for commit message
            $site_url = get_bloginfo('url');
            $site_name = get_bloginfo('name');
            
            // Sanitize site name and URL for security
            $site_name_safe = wp_strip_all_tags($site_name);
            $site_url_safe = esc_url($site_url);
            
            // Create README.md
            $readme_content = "# {$site_name_safe}\n\nWordPress site synced with GitHub.\n\nSite URL: {$site_url_safe}\n\n";
            $readme_content .= "## About\n\nThis repository contains the themes, plugins, and configuration for the WordPress site.\n";
            $readme_content .= "It is managed by the WordPress GitHub Sync plugin.\n";
            
            $readme_path = $temp_dir . '/README.md';
            if (file_put_contents($readme_path, $readme_content) === false) {
                return new \WP_Error('readme_creation_failed', __('Failed to create README.md file', 'wp-github-sync'));
            }
            
            // Create .gitignore
            $gitignore_content = "# WordPress core files\nwp-admin/\nwp-includes/\nwp-*.php\n\n";
            $gitignore_content .= "# Exclude sensitive files\nwp-config.php\n*.log\n.htaccess\n\n";
            $gitignore_content .= "# Exclude cache and backup files\n*.cache\n*.bak\n*~\n\n";
            
            $gitignore_path = $temp_dir . '/.gitignore';
            if (file_put_contents($gitignore_path, $gitignore_content) === false) {
                return new \WP_Error('gitignore_creation_failed', __('Failed to create .gitignore file', 'wp-github-sync'));
            }
            
            return true;
        } catch (\Exception $e) {
            wp_github_sync_log("Exception creating standard files: " . $e->getMessage(), 'error');
            return new \WP_Error('standard_files_exception', sprintf(__('Exception creating standard files: %s', 'wp-github-sync'), $e->getMessage()));
        }
    }
    
    /**
     * Copy a directory recursively.
     *
     * @param string $source The source directory.
     * @param string $dest   The destination directory.
     * @return bool True on success or false on failure.
     */
    private function copy_directory($source, $dest) {
        // Validate source and destination for security
        if (!$this->is_safe_path($source) || !$this->is_safe_path($dest)) {
            wp_github_sync_log("Security check failed: Unsafe path detected in copy operation", 'error');
            return false;
        }
        
        // Create destination directory if it doesn't exist
        if (!file_exists($dest)) {
            wp_mkdir_p($dest);
        }
        
        if (!is_dir($source)) {
            wp_github_sync_log("Source is not a directory: {$source}", 'error');
            return false;
        }
        
        try {
            // Get all files and directories
            $flags = \RecursiveDirectoryIterator::SKIP_DOTS;
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($source, $flags),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            
            // File types to skip for security
            $skip_extensions = array('php', 'phtml', 'php5', 'php7', 'phar', 'phps', 'pht', 'phtm', 'phhtml');
            
            // Track files copied
            $files_copied = 0;
            $files_skipped = 0;
            
            foreach ($iterator as $item) {
                $subpath = $iterator->getSubPathName();
                
                // Extra path security check
                if (!$this->is_safe_path($subpath)) {
                    wp_github_sync_log("Skipping unsafe path: {$subpath}", 'warning');
                    $files_skipped++;
                    continue;
                }
                
                $target_path = $dest . '/' . $subpath;
                
                if ($item->isDir()) {
                    // Create directory if it doesn't exist
                    if (!file_exists($target_path)) {
                        wp_mkdir_p($target_path);
                    }
                } else {
                    // Check file extension for security
                    $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                    
                    if (in_array($ext, $skip_extensions)) {
                        wp_github_sync_log("Skipping file with unsafe extension: {$subpath}", 'warning');
                        $files_skipped++;
                        continue;
                    }
                    
                    // GitHub file size limits:
                    // - Hard limit: 100MB (GitHub blocks)
                    // - Recommended: <50MB
                    // - Web interface limit: 25MB
                    // - We'll be conservative and skip files over 25MB
                    $file_size = $item->getSize();
                    if ($file_size > 26214400) { // 25MB
                        wp_github_sync_log("Skipping large file: {$subpath} (" . round($file_size / 1048576, 2) . "MB) - exceeds GitHub 25MB recommended limit", 'warning');
                        $files_skipped++;
                        
                        // Store skipped file data for reporting
                        if (!isset($this->stats['skipped_files'])) {
                            $this->stats['skipped_files'] = [];
                        }
                        $this->stats['skipped_files'][] = [
                            'path' => $subpath,
                            'size' => $file_size,
                            'reason' => 'size'
                        ];
                        
                        continue;
                    }
                    
                    // For files between 5MB and 25MB, use chunked upload to GitHub to avoid timeouts
                    $large_file_threshold = 5242880; // 5MB
                    $this->stats['large_files_found'] = ($this->stats['large_files_found'] ?? 0) + ($file_size > $large_file_threshold ? 1 : 0);
                    
                    // Copy file
                    if (copy($item, $target_path)) {
                        $files_copied++;
                    } else {
                        wp_github_sync_log("Failed to copy file: {$subpath}", 'error');
                        $files_skipped++;
                    }
                }
            }
            
            wp_github_sync_log("Directory copy complete: {$files_copied} files copied, {$files_skipped} files skipped", 'info');
            return true;
            
        } catch (\Exception $e) {
            wp_github_sync_log("Exception during directory copy: " . $e->getMessage(), 'error');
            return false;
        }
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