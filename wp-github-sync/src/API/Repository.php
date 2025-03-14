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
     * 
     * @param string $branch The branch name to commit to.
     * @return bool|\WP_Error True on success or WP_Error on failure.
     */
    public function initial_sync($branch = 'main') {
        // First verify authentication is working
        $this->update_progress(0, "Verifying authentication");
        $auth_test = $this->api_client->test_authentication();
        if ($auth_test !== true) {
            wp_github_sync_log("Initial sync authentication test failed: " . $auth_test, 'error');
            $this->update_progress(0, "Authentication failed: " . $auth_test);
            return new \WP_Error('github_auth_failed', sprintf(__('GitHub authentication failed: %s', 'wp-github-sync'), $auth_test));
        }
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
                            return new \WP_Error('repo_init_failed', sprintf(__('Failed to initialize repository: %s', 'wp-github-sync'), $alt_init_result->get_error_message()));
                        }
                        
                        wp_github_sync_log("Repository initialized successfully through alternative method, continuing with sync", 'info');
                    } else {
                        wp_github_sync_log("Failed to create temporary directory for alternative initialization", 'error');
                        return new \WP_Error('repo_init_failed', sprintf(__('Failed to initialize repository: %s. Could not create temporary directory.', 'wp-github-sync'), $init_result->get_error_message()));
                    }
                } else {
                    wp_github_sync_log("Repository initialized successfully, continuing with sync", 'info');
                }
            } else {
                wp_github_sync_log("Failed to access repository: " . $error_message, 'error');
                return new \WP_Error('repo_access_failed', sprintf(__('Failed to access repository: %s', 'wp-github-sync'), $error_message));
            }
        }
        
        // Set up basic commit information
        try {
            $user = $this->api_client->get_user();
            
            if (is_wp_error($user)) {
                $error_message = $user->get_error_message();
                wp_github_sync_log("Failed to get user info: " . $error_message, 'error');
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
            
            // Create a temporary directory to prepare files
            try {
                $temp_dir = wp_tempnam('wp-github-sync-');
                if (empty($temp_dir)) {
                    wp_github_sync_log("Failed to create temporary filename with wp_tempnam", 'error');
                    return new \WP_Error('temp_name_failed', __('Failed to create temporary filename for sync', 'wp-github-sync'));
                }
                
                @unlink($temp_dir); // Remove the file so we can create a directory with the same name
                wp_github_sync_log("Creating temporary directory for initial sync: {$temp_dir}", 'debug');
                
                if (!wp_mkdir_p($temp_dir)) {
                    wp_github_sync_log("Failed to create temporary directory: {$temp_dir}", 'error');
                    return new \WP_Error('temp_dir_creation_failed', sprintf(__('Failed to create temporary directory for sync: %s', 'wp-github-sync'), $temp_dir));
                }
                
                if (!is_dir($temp_dir)) {
                    wp_github_sync_log("Directory does not exist after creation: {$temp_dir}", 'error');
                    return new \WP_Error('temp_dir_not_found', sprintf(__('Temporary directory does not exist after creation: %s', 'wp-github-sync'), $temp_dir));
                }
                
                if (!is_writable($temp_dir)) {
                    wp_github_sync_log("Temporary directory not writable: {$temp_dir}", 'error');
                    return new \WP_Error('temp_dir_not_writable', sprintf(__('Temporary directory not writable: %s', 'wp-github-sync'), $temp_dir));
                }
                
                // Define paths to sync
                $paths_to_sync = apply_filters('wp_github_sync_paths', [
                    'wp-content/themes' => true,
                    'wp-content/plugins' => true,
                    'wp-content/uploads' => false, // Default to not sync media
                ]);
                
                wp_github_sync_log("Preparing files for initial sync", 'info');
                $this->update_progress(4, "Analyzing content folders to sync");
                
                // Prepare files to sync
                try {
                    $this->update_progress(4, "Collecting files from WordPress content directory");
                    $result = $this->prepare_files_for_initial_sync($temp_dir, $paths_to_sync);
                    
                    if (is_wp_error($result)) {
                        $error_message = $result->get_error_message();
                        wp_github_sync_log("Failed to prepare files: " . $error_message, 'error');
                        // Clean up
                        $this->recursive_rmdir($temp_dir);
                        return new \WP_Error('file_preparation_failed', sprintf(__('Failed to prepare files: %s', 'wp-github-sync'), $error_message));
                    }
                } catch (\Exception $prep_exception) {
                    wp_github_sync_log("Exception preparing files: " . $prep_exception->getMessage(), 'error');
                    wp_github_sync_log("Stack trace: " . $prep_exception->getTraceAsString(), 'error');
                    $this->recursive_rmdir($temp_dir);
                    return new \WP_Error('file_preparation_exception', sprintf(__('Exception preparing files: %s', 'wp-github-sync'), $prep_exception->getMessage()));
                }
                
                // Create a README.md file at the root
                try {
                    $readme_content = "# {$site_name}\n\nWordPress site synced with GitHub.\n\nSite URL: {$site_url}\n\n";
                    $readme_content .= "## About\n\nThis repository contains the themes, plugins, and configuration for the WordPress site.\n";
                    $readme_content .= "It is managed by the [WordPress GitHub Sync](https://github.com/yourusername/wp-github-sync) plugin.\n";
                    
                    wp_github_sync_log("Creating README.md file", 'debug');
                    
                    $readme_path = $temp_dir . '/README.md';
                    if (file_put_contents($readme_path, $readme_content) === false) {
                        wp_github_sync_log("Failed to create README.md file at {$readme_path}", 'error');
                        $this->recursive_rmdir($temp_dir);
                        return new \WP_Error('readme_creation_failed', __('Failed to create README.md file', 'wp-github-sync'));
                    }
                    
                    // Verify file was created
                    if (!file_exists($readme_path)) {
                        wp_github_sync_log("README.md file was not created at {$readme_path}", 'error');
                        $this->recursive_rmdir($temp_dir);
                        return new \WP_Error('readme_missing', __('README.md file was not created', 'wp-github-sync'));
                    }
                } catch (\Exception $readme_exception) {
                    wp_github_sync_log("Exception creating README: " . $readme_exception->getMessage(), 'error');
                    wp_github_sync_log("Stack trace: " . $readme_exception->getTraceAsString(), 'error');
                    $this->recursive_rmdir($temp_dir);
                    return new \WP_Error('readme_exception', sprintf(__('Exception creating README: %s', 'wp-github-sync'), $readme_exception->getMessage()));
                }
                
                // Create a .gitignore file
                try {
                    $gitignore_content = "# WordPress core files\nwp-admin/\nwp-includes/\nwp-*.php\n\n";
                    $gitignore_content .= "# Exclude sensitive files\nwp-config.php\n*.log\n.htaccess\n\n";
                    $gitignore_content .= "# Exclude cache and backup files\n*.cache\n*.bak\n*~\n\n";
                    
                    wp_github_sync_log("Creating .gitignore file", 'debug');
                    
                    $gitignore_path = $temp_dir . '/.gitignore';
                    if (file_put_contents($gitignore_path, $gitignore_content) === false) {
                        wp_github_sync_log("Failed to create .gitignore file at {$gitignore_path}", 'error');
                        $this->recursive_rmdir($temp_dir);
                        return new \WP_Error('gitignore_creation_failed', __('Failed to create .gitignore file', 'wp-github-sync'));
                    }
                    
                    // Verify file was created
                    if (!file_exists($gitignore_path)) {
                        wp_github_sync_log("Gitignore file was not created at {$gitignore_path}", 'error');
                        $this->recursive_rmdir($temp_dir);
                        return new \WP_Error('gitignore_missing', __('.gitignore file was not created', 'wp-github-sync'));
                    }
                } catch (\Exception $gitignore_exception) {
                    wp_github_sync_log("Exception creating .gitignore: " . $gitignore_exception->getMessage(), 'error');
                    wp_github_sync_log("Stack trace: " . $gitignore_exception->getTraceAsString(), 'error');
                    $this->recursive_rmdir($temp_dir);
                    return new \WP_Error('gitignore_exception', sprintf(__('Exception creating .gitignore: %s', 'wp-github-sync'), $gitignore_exception->getMessage()));
                }
                
                // Create an uploader instance
                try {
                    $uploader = new Repository_Uploader($this->api_client);
                    
                    // Upload files to GitHub
                    wp_github_sync_log("Starting upload to GitHub", 'info');
                    $result = $uploader->upload_files_to_github($temp_dir, $branch, "Initial sync from {$site_name}");
                    
                    // Clean up temporary directory regardless of success or failure
                    wp_github_sync_log("Cleaning up temporary directory", 'debug');
                    $this->recursive_rmdir($temp_dir);
                    
                    if (is_wp_error($result)) {
                        $error_message = $result->get_error_message();
                        wp_github_sync_log("Upload failed: " . $error_message, 'error');
                        return new \WP_Error('upload_failed', sprintf(__('Failed to upload to GitHub: %s', 'wp-github-sync'), $error_message));
                    }
                    
                    wp_github_sync_log("Initial sync completed successfully", 'info');
                    return $result;
                } catch (\Exception $upload_exception) {
                    // Clean up temporary directory on exception
                    $this->recursive_rmdir($temp_dir);
                    
                    // Log exception details
                    $error_message = $upload_exception->getMessage();
                    $trace = $upload_exception->getTraceAsString();
                    wp_github_sync_log("Exception during upload to GitHub: " . $error_message, 'error');
                    wp_github_sync_log("Stack trace: " . $trace, 'error');
                    
                    return new \WP_Error('upload_exception', sprintf(__('Exception during upload to GitHub: %s', 'wp-github-sync'), $error_message));
                }
            } catch (\Exception $temp_dir_exception) {
                // Catch any exceptions during temporary directory setup
                $error_message = $temp_dir_exception->getMessage();
                $trace = $temp_dir_exception->getTraceAsString();
                wp_github_sync_log("Exception during temporary directory setup: " . $error_message, 'error');
                wp_github_sync_log("Stack trace: " . $trace, 'error');
                
                // Try to clean up if temp_dir is set
                if (!empty($temp_dir) && is_dir($temp_dir)) {
                    $this->recursive_rmdir($temp_dir);
                }
                
                return new \WP_Error('temp_dir_exception', sprintf(__('Exception during temporary directory setup: %s', 'wp-github-sync'), $error_message));
            }
        } catch (\Exception $e) {
            // Catch any exceptions during the entire process
            $error_message = $e->getMessage();
            $trace = $e->getTraceAsString();
            wp_github_sync_log("Critical exception during initial sync: " . $error_message, 'error');
            wp_github_sync_log("Stack trace: " . $trace, 'error');
            return new \WP_Error('critical_sync_exception', sprintf(__('Critical error during initial sync: %s', 'wp-github-sync'), $error_message));
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
        
        // Copy each path that's enabled
        foreach ($paths_to_sync as $path => $include) {
            if (!$include) {
                wp_github_sync_log("Skipping path {$path} (disabled in config)", 'debug');
                continue;
            }
            
            // Using WP_CONTENT_DIR directly to make testing easier
            if (strpos($path, 'wp-content/') === 0) {
                $rel_path = substr($path, strlen('wp-content/'));
                $source_path = rtrim($wp_content_dir, '/') . '/' . $rel_path;
            } else {
                $source_path = $abspath . '/' . $path;
            }
            
            $dest_path = $temp_dir . '/' . $path;
            
            wp_github_sync_log("Processing path {$path}", 'debug');
            wp_github_sync_log("Source: {$source_path}", 'debug');
            wp_github_sync_log("Destination: {$dest_path}", 'debug');
            
            // Make sure source path exists
            if (!file_exists($source_path)) {
                wp_github_sync_log("Source path doesn't exist: {$source_path}, skipping", 'warning');
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