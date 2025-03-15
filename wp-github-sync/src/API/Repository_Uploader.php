<?php
/**
 * GitHub Repository Uploader for the WordPress GitHub Sync plugin.
 *
 * @package WPGitHubSync
 */

namespace WPGitHubSync\API;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * GitHub Repository Uploader class.
 */
class Repository_Uploader {

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
     * File processing statistics
     * 
     * @var array
     */
    private $file_stats = [
        'total_files' => 0,
        'processed_files' => 0,
        'binary_files' => 0,
        'text_files' => 0,
        'blobs_created' => 0,
        'failures' => 0
    ];

    /**
     * Initialize the Repository Uploader class.
     *
     * @param API_Client $api_client The API client instance.
     */
    public function __construct($api_client) {
        $this->api_client = $api_client;
    }
    
    /**
     * Set a progress callback function
     * 
     * @param callable $callback Function that takes ($subStep, $detail, $stats)
     * @return void
     */
    public function set_progress_callback($callback) {
        $this->progress_callback = $callback;
    }
    
    /**
     * Update progress via callback
     * 
     * @param int $subStep Sub-step number
     * @param string $detail Progress detail message
     * @param array $stats Optional additional stats
     */
    private function update_progress($subStep, $detail, $stats = []) {
        if (is_callable($this->progress_callback)) {
            // Merge provided stats with ongoing file stats
            $stats = array_merge($this->file_stats, $stats);
            call_user_func($this->progress_callback, $subStep, $detail, $stats);
        }
    }

    /**
     * Upload files to GitHub using the Git Data API.
     *
     * This method implements a complete workflow to upload files to GitHub:
     * 1. Gets the reference to the target branch
     * 2. Gets the commit the reference points to
     * 3. Gets the tree the commit points to
     * 4. Creates a new tree with the new files
     * 5. Creates a new commit pointing to the new tree
     * 6. Updates the reference to point to the new commit
     *
     * @param string $directory      Directory containing files to upload
     * @param string $branch         Branch to upload to
     * @param string $commit_message Commit message
     * @return bool|\WP_Error True on success or WP_Error on failure
     */
    public function upload_files_to_github($directory, $branch, $commit_message) {
        wp_github_sync_log("Starting GitHub upload process for branch: {$branch}", 'info');
        
        // Check if directory exists and is readable
        if (!is_dir($directory) || !is_readable($directory)) {
            return new \WP_Error('invalid_directory', __('Directory does not exist or is not readable', 'wp-github-sync'));
        }
        
        // Validate branch name (strict validation)
        if (!preg_match('/^[a-zA-Z0-9_\-\.\/]+$/', $branch)) {
            return new \WP_Error('invalid_branch', __('Invalid branch name. Only alphanumeric characters, underscores, hyphens, dots, and forward slashes are allowed.', 'wp-github-sync'));
        }
        
        // Check for problematic git branch patterns
        if (preg_match('/^\./', $branch) || preg_match('/\/$/', $branch) || strpos($branch, '..') !== false) {
            return new \WP_Error('invalid_branch', __('Invalid branch name. Cannot start with dot, end with slash, or contain double dots.', 'wp-github-sync'));
        }
        
        // Check for other unsafe branch names
        $unsafe_branches = array('HEAD', 'master.lock');
        if (in_array($branch, $unsafe_branches, true) || preg_match('/\.lock$/', $branch)) {
            return new \WP_Error('invalid_branch', __('This branch name is reserved or invalid.', 'wp-github-sync'));
        }
        
        // Check directory is not empty
        $dir_contents = array_diff(scandir($directory), array('.', '..'));
        if (empty($dir_contents)) {
            return new \WP_Error('empty_directory', __('Directory is empty, nothing to upload', 'wp-github-sync'));
        }
        
        // Check rate limit before starting expensive operation
        $rate_limit = $this->api_client->request('rate_limit');
        if (!is_wp_error($rate_limit) && isset($rate_limit['resources']['core']['remaining'])) {
            $remaining = $rate_limit['resources']['core']['remaining'];
            if ($remaining < 100) { // Arbitrary threshold, adjust as needed
                wp_github_sync_log("GitHub API rate limit low: {$remaining} remaining", 'warning');
                // Continue anyway, but log warning
            }
        }
        
        // First, verify we can access the repository
        $repo_info = $this->api_client->get_repository();
        if (is_wp_error($repo_info)) {
            wp_github_sync_log("Repository access check failed: " . $repo_info->get_error_message(), 'error');
            return new \WP_Error('github_api_repo_access', __('Cannot access repository. Please check your authentication and repository URL.', 'wp-github-sync'));
        }
        
        // Step 1: Get all branches to see what exists
        $all_branches = $this->api_client->get_branches();
        if (is_wp_error($all_branches)) {
            wp_github_sync_log("Failed to get branches: " . $all_branches->get_error_message(), 'error');
            return new \WP_Error('github_api_error', __('Failed to get branches. Please check your authentication.', 'wp-github-sync'));
        }
        
        // Check if our target branch exists
        $branch_exists = false;
        $default_branch = isset($repo_info['default_branch']) ? $repo_info['default_branch'] : 'main';
        
        foreach ($all_branches as $branch_data) {
            if (isset($branch_data['name']) && $branch_data['name'] === $branch) {
                $branch_exists = true;
                break;
            }
        }
        
        // Get or create the branch reference
        $reference = $this->get_or_create_branch_reference($branch, $branch_exists, $default_branch);
        if (is_wp_error($reference)) {
            return $reference;
        }
        
        // At this point, we should have a valid reference
        if (!isset($reference['object']['sha'])) {
            return new \WP_Error('github_api_error', __('Invalid branch reference', 'wp-github-sync'));
        }
        
        $ref_sha = $reference['object']['sha'];
        wp_github_sync_log("Got reference SHA: {$ref_sha}", 'debug');
        
        // Step 2: Get the commit the reference points to
        $commit = $this->api_client->request(
            "repos/{$this->api_client->get_owner()}/{$this->api_client->get_repo()}/git/commits/{$ref_sha}"
        );
        
        if (is_wp_error($commit)) {
            return new \WP_Error('github_api_error', __('Failed to get commit', 'wp-github-sync'));
        }
        
        $base_tree_sha = $commit['tree']['sha'];
        wp_github_sync_log("Got base tree SHA: {$base_tree_sha}", 'debug');
        
        // Step 3: Create tree items from the files in the directory
        $tree_items = $this->create_tree_items($directory);
        
        if (empty($tree_items)) {
            return new \WP_Error('github_api_error', __('No files to upload', 'wp-github-sync'));
        }
        
        wp_github_sync_log("Created " . count($tree_items) . " blobs", 'info');
        
        // Step 4: Create a new tree with the new files
        $new_tree_sha = $this->create_tree($tree_items, $base_tree_sha);
        if (is_wp_error($new_tree_sha)) {
            return $new_tree_sha;
        }
        
        wp_github_sync_log("Final tree created with SHA: {$new_tree_sha}", 'info');
        
        // Step 5: Create a new commit
        try {
            // Add retry mechanism for commit creation (can sometimes fail due to network issues)
            $max_retries = 3;
            $retry_delay = 2; // seconds
            $attempt = 0;
            $success = false;
            $new_commit = null;
                
            while ($attempt < $max_retries && !$success) {
                $attempt++;
                    
                if ($attempt > 1) {
                    wp_github_sync_log("Retry attempt {$attempt} for commit creation", 'info');
                    sleep($retry_delay);
                    $retry_delay *= 2; // Exponential backoff
                }
                    
                $new_commit = $this->api_client->request(
                    "repos/{$this->api_client->get_owner()}/{$this->api_client->get_repo()}/git/commits",
                    'POST',
                    [
                        'message' => $commit_message,
                        'tree' => $new_tree_sha,
                        'parents' => [$ref_sha]
                    ]
                );
                
                if (!is_wp_error($new_commit)) {
                    $success = true;
                } else {
                    wp_github_sync_log("Commit creation failed, error: " . $new_commit->get_error_message(), 'error');
                    
                    // Only retry certain errors that might be transient
                    $error_code = $new_commit->get_error_code();
                    if (!in_array($error_code, ['github_api_rate_limit', 'github_secondary_limit'])) {
                        break; // Don't retry if it's not a rate limit issue
                    }
                }
            }
            
            if (is_wp_error($new_commit)) {
                wp_github_sync_log("Failed to create commit: " . $new_commit->get_error_message(), 'error');
                return new \WP_Error('github_api_error', __('Failed to create commit', 'wp-github-sync'));
            }
            
            $new_commit_sha = $new_commit['sha'];
            wp_github_sync_log("Created new commit with SHA: {$new_commit_sha}", 'info');
            
            // Step 6: Update the reference
            // Sanitize branch name again for safety
            $sanitized_branch = preg_replace('/[^a-zA-Z0-9_\-\.\/]/', '', $branch);
            if ($sanitized_branch !== $branch) {
                wp_github_sync_log("Branch name was sanitized from '{$branch}' to '{$sanitized_branch}'", 'warning');
            }
            
            $update_ref = $this->api_client->request(
                "repos/{$this->api_client->get_owner()}/{$this->api_client->get_repo()}/git/refs/heads/{$sanitized_branch}",
                'PATCH',
                [
                    'sha' => $new_commit_sha,
                    'force' => true // Force update in case of non-fast-forward update
                ]
            );
            
            if (is_wp_error($update_ref)) {
                wp_github_sync_log("Failed to update reference: " . $update_ref->get_error_message(), 'error');
                return new \WP_Error('github_api_error', __('Failed to update reference', 'wp-github-sync'));
            }
            
            wp_github_sync_log("Successfully updated reference to new commit", 'info');
            
            // Store this commit as the last deployed commit
            update_option('wp_github_sync_last_deployed_commit', $new_commit_sha);
            
            return true;
        } catch (\Exception $e) {
            wp_github_sync_log("Exception during commit creation or reference update: " . $e->getMessage(), 'error');
            return new \WP_Error('github_api_exception', $e->getMessage());
        }
    }

    /**
     * Get or create a branch reference.
     * Handles various edge cases like empty repositories, non-existent branches,
     * and prevents issues with unsafe branch names.
     *
     * @param string $branch        The branch name.
     * @param bool   $branch_exists Whether the branch already exists.
     * @param string $default_branch The default branch name to use as a base for new branches.
     * @return array|\WP_Error The branch reference or WP_Error on failure.
     */
    private function get_or_create_branch_reference($branch, $branch_exists, $default_branch) {
        if ($branch_exists) {
            // Branch exists, get reference
            $reference = $this->api_client->request(
                "repos/{$this->api_client->get_owner()}/{$this->api_client->get_repo()}/git/refs/heads/{$branch}"
            );
            
            // If it fails, return error
            if (is_wp_error($reference)) {
                wp_github_sync_log("Failed to get reference for existing branch {$branch}: " . $reference->get_error_message(), 'error');
                return new \WP_Error('github_api_error', __('Failed to get branch reference. Please verify your GitHub authentication credentials have sufficient permissions.', 'wp-github-sync'));
            }
        } else {
            // Branch doesn't exist, we need to create it
            wp_github_sync_log("Branch {$branch} not found. Attempting to create it from {$default_branch}.", 'info');
            
            // Get the SHA of the default branch
            $default_ref = $this->api_client->request(
                "repos/{$this->api_client->get_owner()}/{$this->api_client->get_repo()}/git/refs/heads/{$default_branch}",
                'GET',
                [],
                true // Set last parameter to true to explicitly handle empty repository error
            );
            
            if (is_wp_error($default_ref)) {
                wp_github_sync_log("Failed to get default branch reference: " . $default_ref->get_error_message(), 'error');
                
                // Check if this is an empty repository (no default branch exists yet)
                $error_message = $default_ref->get_error_message();
                if (strpos($error_message, 'Not Found') !== false || 
                    strpos($error_message, '404') !== false || 
                    strpos($error_message, 'Git Repository is empty') !== false) {
                    
                    wp_github_sync_log("Default branch not found. Attempting to initialize empty repository.", 'info');
                    
                    // Try to initialize the repository
                    $init_result = $this->api_client->initialize_repository($branch);
                    if (is_wp_error($init_result)) {
                        wp_github_sync_log("Repository initialization failed, trying fallback method: " . $init_result->get_error_message(), 'warning');
                        
                        // Fall back to our manual method
                        $reference = $this->create_initial_branch($branch);
                        if (is_wp_error($reference)) {
                            wp_github_sync_log("Failed to create initial branch: " . $reference->get_error_message(), 'error');
                            return $reference;
                        }
                        return $reference;
                    }
                    
                    // Repository was initialized, now get the reference
                    $ref = $this->api_client->request(
                        "repos/{$this->api_client->get_owner()}/{$this->api_client->get_repo()}/git/refs/heads/{$branch}"
                    );
                    
                    if (is_wp_error($ref)) {
                        wp_github_sync_log("Failed to get branch reference after initialization: " . $ref->get_error_message(), 'error');
                        return new \WP_Error('ref_not_found', __('Repository initialized but branch reference could not be retrieved.', 'wp-github-sync'));
                    }
                    
                    wp_github_sync_log("Repository initialized and reference retrieved successfully", 'info');
                    return $ref;
                } else {
                    // Some other error occurred
                    return new \WP_Error('github_api_error', sprintf(__('Failed to get default branch reference: %s', 'wp-github-sync'), $error_message));
                }
            } else {
                // Create the new branch from default
                $create_ref = $this->api_client->request(
                    "repos/{$this->api_client->get_owner()}/{$this->api_client->get_repo()}/git/refs",
                    'POST',
                    [
                        'ref' => "refs/heads/{$branch}",
                        'sha' => $default_ref['object']['sha']
                    ]
                );
                
                if (is_wp_error($create_ref)) {
                    wp_github_sync_log("Failed to create new branch: " . $create_ref->get_error_message(), 'error');
                    return new \WP_Error('github_api_error', __('Failed to create new branch', 'wp-github-sync'));
                }
                
                $reference = $create_ref;
            }
        }
        
        return $reference;
    }

    /**
     * Create the initial branch when the repository is empty.
     *
     * @param string $branch The branch name to create.
     * @return array|\WP_Error The branch reference or WP_Error on failure.
     */
    private function create_initial_branch($branch) {
        wp_github_sync_log("Default branch not found. Creating initial commit for empty repository.", 'info');
        
        try {
            // Step 1: Create a blob for a README.md file (following GitHub's documented approach)
            $readme_content = "# WordPress GitHub Sync\n\nThis repository was created by the WordPress GitHub Sync plugin.\n";
            $blob = $this->api_client->request(
                "repos/{$this->api_client->get_owner()}/{$this->api_client->get_repo()}/git/blobs",
                'POST',
                [
                    'content' => $readme_content,
                    'encoding' => 'utf-8'
                ]
            );
            
            if (is_wp_error($blob)) {
                wp_github_sync_log("Failed to create README blob: " . $blob->get_error_message(), 'error');
                
                // Check if this might be due to repository being completely empty or uninitialized
                $error_message = $blob->get_error_message();
                if (strpos($error_message, 'Git Repository is empty') !== false) {
                    wp_github_sync_log("Repository is completely empty. Attempting to initialize repository with first commit.", 'info');
                    
                    // For an empty repository, we need to create an initial commit directly using the GitHub API
                    // This requires using the GitHub Content API instead of the Git Data API
                    
                    try {
                        // Create a README file using the contents API directly
                        $readme_content = "# WordPress GitHub Sync\n\nThis repository was created by the WordPress GitHub Sync plugin.\n";
                        $readme_commit = $this->api_client->request(
                            "repos/{$this->api_client->get_owner()}/{$this->api_client->get_repo()}/contents/README.md",
                            'PUT',
                            [
                                'message' => 'Initial commit',
                                'content' => base64_encode($readme_content),
                                'branch' => $branch
                            ]
                        );
                        
                        if (is_wp_error($readme_commit)) {
                            wp_github_sync_log("Failed to create initial README.md file: " . $readme_commit->get_error_message(), 'error');
                            return new \WP_Error(
                                'empty_repository', 
                                __('Git Repository is empty. Please initialize the repository with a README file in GitHub first.', 'wp-github-sync')
                            );
                        }
                        
                        wp_github_sync_log("Successfully created initial commit with README.md", 'info');
                        
                        // Now get the reference to the branch we just created
                        $ref = $this->api_client->request(
                            "repos/{$this->api_client->get_owner()}/{$this->api_client->get_repo()}/git/refs/heads/{$branch}"
                        );
                        
                        if (is_wp_error($ref)) {
                            wp_github_sync_log("Failed to get branch reference after initialization: " . $ref->get_error_message(), 'error');
                            return new \WP_Error(
                                'ref_not_found',
                                __('Repository initialized but branch reference could not be retrieved.', 'wp-github-sync')
                            );
                        }
                        
                        return $ref;
                    } catch (\Exception $e) {
                        wp_github_sync_log("Exception initializing empty repository: " . $e->getMessage(), 'error');
                        return new \WP_Error(
                            'empty_repository_exception', 
                            __('Error initializing empty repository: ', 'wp-github-sync') . $e->getMessage()
                        );
                    }
                }
                
                return new \WP_Error('github_api_error', __('Failed to create initial blob', 'wp-github-sync'));
            }
            
            // Step 2: Create a tree entry with the blob
            $tree = $this->api_client->request(
                "repos/{$this->api_client->get_owner()}/{$this->api_client->get_repo()}/git/trees",
                'POST',
                [
                    'tree' => [
                        [
                            'path' => 'README.md',
                            'mode' => '100644',
                            'type' => 'blob',
                            'sha' => $blob['sha']
                        ]
                    ]
                ]
            );
            
            if (is_wp_error($tree)) {
                wp_github_sync_log("Failed to create tree: " . $tree->get_error_message(), 'error');
                
                // Try a completely empty tree as a fallback
                wp_github_sync_log("Attempting to create an empty tree as fallback", 'info');
                $empty_tree = $this->api_client->request(
                    "repos/{$this->api_client->get_owner()}/{$this->api_client->get_repo()}/git/trees",
                    'POST',
                    [
                        'tree' => []
                    ]
                );
                
                if (is_wp_error($empty_tree)) {
                    wp_github_sync_log("Failed to create empty tree: " . $empty_tree->get_error_message(), 'error');
                    
                    // Check if this is a newly created repository without initialization
                    $error_message = $empty_tree->get_error_message();
                    if (strpos($error_message, 'Git Repository is empty') !== false) {
                        return new \WP_Error(
                            'empty_repository', 
                            __('Git Repository is empty. Please create a repository with README initialization or push an initial commit manually.', 'wp-github-sync')
                        );
                    }
                    
                    return new \WP_Error('github_api_error', __('Failed to create initial tree', 'wp-github-sync'));
                }
                
                $tree = $empty_tree;
            }
            
            // Step 3: Create the initial commit with the tree
            $initial_commit = $this->api_client->request(
                "repos/{$this->api_client->get_owner()}/{$this->api_client->get_repo()}/git/commits",
                'POST',
                [
                    'message' => 'Initial commit',
                    'tree' => $tree['sha'],
                    'parents' => []
                ]
            );
            
            if (is_wp_error($initial_commit)) {
                wp_github_sync_log("Failed to create initial commit: " . $initial_commit->get_error_message(), 'error');
                
                // Check for specific error conditions
                $error_message = $initial_commit->get_error_message();
                
                if (strpos($error_message, 'Git Repository is empty') !== false) {
                    return new \WP_Error(
                        'empty_repository', 
                        __('Git Repository is empty. Please initialize the repository in GitHub first by creating a README.', 'wp-github-sync')
                    );
                }
                
                // Check if this is a 422 error (Unprocessable Entity)
                if (strpos($error_message, '422') !== false) {
                    wp_github_sync_log("422 error received. Repository might not be empty. Trying to use existing branches.", 'info');
                    
                    // Try to get all refs to see what's available
                    $all_refs = $this->api_client->request(
                        "repos/{$this->api_client->get_owner()}/{$this->api_client->get_repo()}/git/refs"
                    );
                    
                    if (!is_wp_error($all_refs) && !empty($all_refs)) {
                        // Find any branch reference
                        foreach ($all_refs as $ref) {
                            if (isset($ref['ref']) && strpos($ref['ref'], 'refs/heads/') === 0) {
                                $existing_branch = str_replace('refs/heads/', '', $ref['ref']);
                                wp_github_sync_log("Found existing branch: {$existing_branch}", 'info');
                                
                                // Create the new branch from this existing branch
                                $create_branch = $this->api_client->request(
                                    "repos/{$this->api_client->get_owner()}/{$this->api_client->get_repo()}/git/refs",
                                    'POST',
                                    [
                                        'ref' => "refs/heads/{$branch}",
                                        'sha' => $ref['object']['sha']
                                    ]
                                );
                                
                                if (!is_wp_error($create_branch)) {
                                    wp_github_sync_log("Successfully created branch from existing branch", 'info');
                                    return $create_branch;
                                }
                            }
                        }
                    }
                }
                
                return new \WP_Error('github_api_error', __('Failed to create initial commit', 'wp-github-sync'));
            }
            
            // Step 4: Create the main branch reference with the initial commit
            $create_main_ref = $this->api_client->request(
                "repos/{$this->api_client->get_owner()}/{$this->api_client->get_repo()}/git/refs",
                'POST',
                [
                    'ref' => "refs/heads/{$branch}",
                    'sha' => $initial_commit['sha']
                ]
            );
            
            if (is_wp_error($create_main_ref)) {
                wp_github_sync_log("Failed to create main branch reference: " . $create_main_ref->get_error_message(), 'error');
                
                // Try to get the reference we just created - sometimes the branch is created despite the error
                $check_ref = $this->api_client->request(
                    "repos/{$this->api_client->get_owner()}/{$this->api_client->get_repo()}/git/refs/heads/{$branch}"
                );
                
                if (!is_wp_error($check_ref)) {
                    wp_github_sync_log("Branch reference exists despite creation error. Using existing reference.", 'info');
                    return $check_ref;
                }
                
                return new \WP_Error('github_api_error', __('Failed to create initial branch reference', 'wp-github-sync'));
            }
            
            wp_github_sync_log("Successfully created initial branch reference with README.md", 'info');
            return $create_main_ref;
        } catch (\Exception $e) {
            wp_github_sync_log("Exception in create_initial_branch: " . $e->getMessage(), 'error');
            return new \WP_Error('github_api_exception', __('Exception creating initial branch: ', 'wp-github-sync') . $e->getMessage());
        }
    }

    /**
     * Create tree items from files in a directory.
     *
     * @param string $directory The directory containing files to upload.
     * @return array The tree items.
     */
    private function create_tree_items($directory) {
        $tree_items = [];
        $files_processed = 0;
        $total_size = 0;
        $upload_limit = 50 * 1024 * 1024; // 50MB recommended limit to avoid issues
        $max_files = 1000; // GitHub has limits on tree size
        $skipped_files = [];
        
        // Reset file stats for this operation
        $this->file_stats = [
            'total_files' => 0,
            'processed_files' => 0,
            'binary_files' => 0,
            'text_files' => 0,
            'blobs_created' => 0,
            'failures' => 0
        ];
        
        // Report initial progress
        $this->update_progress(1, "Starting file analysis");
        
        // Log directory contents first
        wp_github_sync_log("Scanning directory for files: {$directory}", 'debug');
        $this->list_directory_recursive($directory);
        
        // Create a reference to $this for use in the closure
        $self = $this;
        
        // Recursive function to process directories
        $process_directory = function($dir, $base_path = '') use (&$process_directory, &$tree_items, &$files_processed, &$total_size, &$skipped_files, $upload_limit, $max_files, $self) {
            wp_github_sync_log("Processing directory: {$dir} (base path: {$base_path})", 'debug');
            
            // Update directory processing progress
            $self->file_stats['total_files'] = max($self->file_stats['total_files'], $files_processed + 10); // Estimate
            $self->file_stats['processed_files'] = $files_processed;
            $self->update_progress(2, "Processing directory: " . basename($dir));
            
            if (!is_dir($dir)) {
                wp_github_sync_log("Directory does not exist: {$dir}", 'error');
                return;
            }
            
            $files = scandir($dir);
            
            if ($files === false) {
                wp_github_sync_log("Failed to scan directory: {$dir}", 'error');
                return;
            }
            
            wp_github_sync_log("Found " . count($files) . " items in {$dir}", 'debug');
            
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                
                // Skip hidden files and common unwanted patterns
                if (strpos($file, '.') === 0 || in_array($file, ['node_modules', 'vendor', '.git', 'cache'])) {
                    wp_github_sync_log("Skipping '{$file}' (hidden or excluded pattern)", 'debug');
                    continue;
                }
                
                $path = $dir . '/' . $file;
                $relative_path = $base_path . ($base_path ? '/' : '') . $file;
                
                // Normalize the path to use forward slashes for GitHub
                $relative_path = str_replace('\\', '/', $relative_path);
                
                if (is_dir($path)) {
                    // For directories, recursively process them
                    wp_github_sync_log("Recursing into directory: {$path}", 'debug');
                    $process_directory($path, $relative_path);
                } else {
                    wp_github_sync_log("Processing file: {$path}", 'debug');
                    
                    // Check file limits
                    if ($files_processed >= $max_files) {
                        wp_github_sync_log("Reached max file limit ({$max_files}). Some files were not processed.", 'warning');
                        return;
                    }
                    
                    // Check if file is binary or text
                    $is_binary = false;
                    $mime_type = '';
                    
                    // Get file extension
                    $file_ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    
                    // Define known binary extensions list
                    $binary_extensions = [
                        // Images
                        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'ico', 'svg', 'tif', 'tiff',
                        // Compressed files
                        'zip', 'gz', 'tar', 'rar', '7z', 'bz2', 'xz',
                        // Executables
                        'exe', 'dll', 'so', 'dylib', 
                        // Documents
                        'pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx',
                        // Audio/Video
                        'mp3', 'mp4', 'avi', 'mov', 'wav', 'ogg', 'webm',
                        // Fonts
                        'ttf', 'otf', 'woff', 'woff2', 'eot'
                    ];
                    
                    // Check if it's a known binary extension first
                    if (in_array($file_ext, $binary_extensions)) {
                        wp_github_sync_log("File {$file} has known binary extension, treating as binary", 'debug');
                        $is_binary = true;
                    } else {
                        // Try finfo if available for other files
                        if (class_exists('\\finfo')) {
                            try {
                                $finfo = new \finfo(FILEINFO_MIME);
                                $mime_type = $finfo->file($path);
                                wp_github_sync_log("MIME type for {$file}: {$mime_type}", 'debug');
                                
                                // Check if it's a text MIME type
                                $is_binary = (strpos($mime_type, 'text/') !== 0 && 
                                             strpos($mime_type, 'application/json') !== 0 &&
                                             strpos($mime_type, 'application/xml') !== 0 &&
                                             strpos($mime_type, 'application/javascript') !== 0 &&
                                             strpos($mime_type, 'application/x-php') !== 0);
                                
                                // If detected as image, force binary
                                if (strpos($mime_type, 'image/') === 0) {
                                    wp_github_sync_log("File {$file} has image MIME type, forcing binary", 'debug');
                                    $is_binary = true;
                                }
                            } catch (\Exception $e) {
                                // Fall back to a simple check if finfo fails
                                wp_github_sync_log("finfo failed, falling back to simple binary check: " . $e->getMessage(), 'debug');
                                $is_binary = $self->is_binary_file($path);
                            }
                        } else {
                            // If finfo class is not available, fallback to a simple check
                            wp_github_sync_log("finfo class not available, falling back to simple binary check", 'debug');
                            $is_binary = $self->is_binary_file($path);
                        }
                    }
                    
                    // Get file size
                    $file_size = filesize($path);
                    wp_github_sync_log("File size: " . round($file_size/1024, 2) . "KB, binary: " . ($is_binary ? "yes" : "no"), 'debug');
                    
                    // Skip if file is too large (GitHub API has a 100MB limit)
                    if ($file_size > 50 * 1024 * 1024) {
                        wp_github_sync_log("Skipping file {$relative_path} (too large: " . round($file_size/1024/1024, 2) . "MB)", 'warning');
                        $skipped_files[] = [
                            'path' => $relative_path,
                            'reason' => 'too_large',
                            'size' => $file_size
                        ];
                        continue;
                    }
                    
                    // Check if this file would exceed our total size limit
                    if ($total_size + $file_size > $upload_limit) {
                        wp_github_sync_log("Total upload limit reached. Skipping remaining files.", 'warning');
                        $skipped_files[] = [
                            'path' => $relative_path,
                            'reason' => 'upload_limit_reached',
                            'size' => $file_size
                        ];
                        return;
                    }
                    
                    try {
                        // For files, create a blob and add to tree
                        $content = file_get_contents($path);
                        
                        // Skip if file couldn't be read
                        if ($content === false) {
                            wp_github_sync_log("Skipping file {$relative_path} (couldn't read file)", 'warning');
                            $skipped_files[] = [
                                'path' => $relative_path,
                                'reason' => 'unreadable',
                                'size' => $file_size
                            ];
                            continue;
                        }
                        
                        // Common image formats should always be treated as binary
                        $file_ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                        $binary_extensions = [
                            // Images
                            'jpg', 'jpeg', 'png', 'gif', 'bmp', 'ico', 'webp', 'svg', 
                            // Archives
                            'zip', 'gz', 'tar', 'rar', '7z',
                            // Media
                            'mp3', 'mp4', 'mov', 'avi', 'wmv', 'webm', 
                            // Documents and fonts
                            'pdf', 'ttf', 'otf', 'woff', 'woff2', 'eot',
                            // Translation files (these often cause issues with UTF-8)
                            'mo', 'po'
                        ];
                        
                        if (in_array($file_ext, $binary_extensions)) {
                            wp_github_sync_log("File {$file} has binary extension ({$file_ext}), treating as binary", 'debug');
                            $is_binary = true;
                            
                            // Track file types for progress reporting
                            $self->file_stats['binary_files']++;
                        } else {
                            $self->file_stats['text_files']++;
                        }
                        
                        // Always treat files that look like images as binary
                        if (strpos($mime_type, 'image/') === 0) {
                            wp_github_sync_log("File {$file} has image MIME type, treating as binary", 'debug');
                            $is_binary = true;
                        }
                        
                        // Create blob based on file type
                        $blob_data = [];
                        
                        if ($is_binary) {
                            // Use base64 for all binary files
                            wp_github_sync_log("Using base64 encoding for binary file: {$file}", 'debug');
                            $blob_data = [
                                'content' => base64_encode($content),
                                'encoding' => 'base64'
                            ];
                        } else {
                            // For text files, try to detect the best encoding
                            // First check for null bytes which indicate binary content
                            if (strpos($content, "\0") !== false) {
                                wp_github_sync_log("File {$relative_path} contains null bytes, treating as binary", 'debug');
                                $blob_data = [
                                    'content' => base64_encode($content),
                                    'encoding' => 'base64'
                                ];
                            } 
                            // Check if content is valid UTF-8
                            else if (function_exists('mb_check_encoding') && mb_check_encoding($content, 'UTF-8')) {
                                wp_github_sync_log("Using UTF-8 encoding for text file: {$file}", 'debug');
                                $blob_data = [
                                    'content' => $content,
                                    'encoding' => 'utf-8'
                                ];
                            } 
                            // If mb_check_encoding isn't available, try another approach
                            else if (function_exists('json_encode') && function_exists('json_last_error')) {
                                // Test if we can JSON encode it (which requires valid UTF-8)
                                json_encode($content);
                                if (json_last_error() === JSON_ERROR_NONE) {
                                    wp_github_sync_log("JSON encode test passed, using UTF-8 for: {$file}", 'debug');
                                    $blob_data = [
                                        'content' => $content,
                                        'encoding' => 'utf-8'
                                    ];
                                } else {
                                    wp_github_sync_log("JSON encode test failed, using base64 for: {$file}", 'debug');
                                    $blob_data = [
                                        'content' => base64_encode($content),
                                        'encoding' => 'base64'
                                    ];
                                }
                            } 
                            // Default to base64 for safety
                            else {
                                wp_github_sync_log("Unable to verify encoding, using base64 for safety: {$file}", 'debug');
                                $blob_data = [
                                    'content' => base64_encode($content),
                                    'encoding' => 'base64'
                                ];
                            }
                        }
                        
                        // Update progress before blob creation
                        $self->file_stats['processed_files'] = $files_processed;
                        if ($files_processed % 5 == 0 || $file_ext === 'po') { // Update more frequently for translation files
                            $self->update_progress(3, "Processing file: {$relative_path} ({$files_processed}/{$self->file_stats['total_files']})");
                        }
                        
                        wp_github_sync_log("Creating blob for file: {$relative_path}", 'debug');
                        
                        // Handle large files (>3MB) differently - they may need special handling
                        $large_file_threshold = 3 * 1024 * 1024; // 3MB
                        $file_size = filesize($file_path);
                        
                        if ($file_size > $large_file_threshold) {
                            wp_github_sync_log("Large file detected ({$file_size} bytes). Using special upload handling.", 'info');
                            
                            // For large files, use retries and connection timeout adjustments
                            $max_blob_retries = 3;
                            $blob_retry_count = 0;
                            $blob_success = false;
                            $blob = null;
                            
                            while ($blob_retry_count < $max_blob_retries && !$blob_success) {
                                $blob_retry_count++;
                                
                                if ($blob_retry_count > 1) {
                                    wp_github_sync_log("Retry #{$blob_retry_count} for large file blob creation", 'debug');
                                    sleep(2 * $blob_retry_count); // Increasing delay for each retry
                                }
                                
                                // Use extended timeout for large file uploads
                                $blob = $self->api_client->request(
                                    "repos/{$self->api_client->get_owner()}/{$self->api_client->get_repo()}/git/blobs",
                                    'POST',
                                    $blob_data,
                                    false, // empty repo handling
                                    60 // Extended timeout in seconds for large files
                                );
                                
                                if (!is_wp_error($blob)) {
                                    $blob_success = true;
                                } else {
                                    wp_github_sync_log("Large file upload failed attempt #{$blob_retry_count}: " . $blob->get_error_message(), 'warning');
                                }
                            }
                        } else {
                            // Standard file upload
                            $blob = $self->api_client->request(
                                "repos/{$self->api_client->get_owner()}/{$self->api_client->get_repo()}/git/blobs",
                                'POST',
                                $blob_data
                            );
                        }
                        
                        if (is_wp_error($blob)) {
                            $error_message = $blob->get_error_message();
                            wp_github_sync_log("Failed to create blob for {$relative_path}: " . $error_message, 'error');
                            
                            // Enhanced retry strategy with more robust error detection
                            // Check for encoding errors, bad requests, or any 400-level errors
                            if (strpos($error_message, 'encoding') !== false ||
                                strpos($error_message, 'encode') !== false ||
                                strpos($error_message, '400') !== false ||
                                strpos($error_message, 'Bad Request') !== false ||
                                strpos($error_message, 'Unprocessable') !== false ||
                                strpos($error_message, '422') !== false ||
                                strpos($error_message, '412') !== false) {
                                
                                wp_github_sync_log("Retrying with forced base64 encoding due to API error", 'debug');
                                
                                // Force base64 encoding for retry - this is the most reliable format
                                $retry_blob_data = [
                                    'content' => base64_encode($content),
                                    'encoding' => 'base64'
                                ];
                                
                                // For .po files or 412 errors, add a status update
                                if ($file_ext === 'po' || strpos($error_message, '412') !== false) {
                                    $self->update_progress(3, "Special retry for translation file: {$relative_path}");
                                }
                                
                                $retry_blob = $self->api_client->request(
                                    "repos/{$self->api_client->get_owner()}/{$self->api_client->get_repo()}/git/blobs",
                                    'POST',
                                    $retry_blob_data
                                );
                                
                                if (!is_wp_error($retry_blob)) {
                                    wp_github_sync_log("Retry successful with base64 encoding", 'info');
                                    $blob = $retry_blob;
                                    // Track successful retry
                                    $this->file_stats['blobs_created']++;
                                } else if (($file_ext === 'po' || strpos($error_message, '412') !== false) && 
                                         function_exists('mb_convert_encoding')) {
                                    // Second retry attempt with sanitized content for .po files with 412 errors
                                    wp_github_sync_log("Translation file still failing, trying with sanitized content: {$relative_path}", 'warning');
                                    $self->update_progress(3, "Final retry with sanitized content: {$relative_path}");
                                    
                                    // Sanitize content using MB functions
                                    $sanitized_content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
                                    // Also strip NUL bytes which can cause issues
                                    $sanitized_content = str_replace("\0", "", $sanitized_content);
                                    
                                    $final_retry_blob = $self->api_client->request(
                                        "repos/{$self->api_client->get_owner()}/{$self->api_client->get_repo()}/git/blobs",
                                        'POST',
                                        [
                                            'content' => base64_encode($sanitized_content),
                                            'encoding' => 'base64'
                                        ]
                                    );
                                    
                                    if (!is_wp_error($final_retry_blob)) {
                                        wp_github_sync_log("Final retry successful with sanitized content", 'info');
                                        $blob = $final_retry_blob;
                                        // Track successful retry
                                        $self->file_stats['blobs_created']++;
                                    } else {
                                        wp_github_sync_log("Final retry also failed for {$relative_path}", 'error');
                                        $self->file_stats['failures']++;
                                    }
                                } else {
                                    // Try one more time with a short pause and slight modification
                                    wp_github_sync_log("First retry failed, pausing and trying again with additional encoding sanitization", 'debug');
                                    sleep(1); // Brief pause to avoid rate limiting
                                    
                                    // Additional sanitization to handle special cases
                                    if (function_exists('mb_convert_encoding')) {
                                        $sanitized_content = base64_encode(mb_convert_encoding($content, 'UTF-8', 'UTF-8'));
                                    } else {
                                        // Basic sanitization fallback if mb_convert_encoding is not available
                                        $sanitized_content = base64_encode($content);
                                    }
                                    
                                    $final_retry_blob_data = [
                                        'content' => $sanitized_content,
                                        'encoding' => 'base64'
                                    ];
                                    
                                    $final_retry_blob = $self->api_client->request(
                                        "repos/{$self->api_client->get_owner()}/{$self->api_client->get_repo()}/git/blobs",
                                        'POST',
                                        $final_retry_blob_data
                                    );
                                    
                                    if (!is_wp_error($final_retry_blob)) {
                                        wp_github_sync_log("Final retry successful with sanitized base64 encoding", 'info');
                                        $blob = $final_retry_blob;
                                    } else {
                                        wp_github_sync_log("All retries failed: " . $final_retry_blob->get_error_message(), 'error');
                                        $skipped_files[] = [
                                            'path' => $relative_path,
                                            'reason' => 'blob_creation_failed_on_multiple_retries',
                                            'error' => $final_retry_blob->get_error_message()
                                        ];
                                        continue;
                                    }
                                }
                            } else {
                                // Non-encoding errors (e.g., permissions, rate limits)
                                $skipped_files[] = [
                                    'path' => $relative_path,
                                    'reason' => 'blob_creation_failed',
                                    'error' => $error_message
                                ];
                                continue;
                            }
                        }
                        
                        // Determine file mode (executable or regular file)
                        $file_mode = '100644'; // Regular file
                        if (is_executable($path)) {
                            $file_mode = '100755'; // Executable file
                        }
                        
                        wp_github_sync_log("Adding tree item for {$relative_path} with SHA: {$blob['sha']}", 'debug');
                        $tree_items[] = [
                            'path' => $relative_path,
                            'mode' => $file_mode,
                            'type' => 'blob',
                            'sha' => $blob['sha']
                        ];
                        
                        $files_processed++;
                        $total_size += $file_size;
                        
                        if ($files_processed % 25 === 0) {
                            wp_github_sync_log("Processed {$files_processed} files, total size: " . round($total_size/1024/1024, 2) . "MB", 'info');
                        }
                    } catch (\Exception $e) {
                        wp_github_sync_log("Error processing file {$relative_path}: " . $e->getMessage(), 'error');
                        wp_github_sync_log("Exception stack trace: " . $e->getTraceAsString(), 'debug');
                        $skipped_files[] = [
                            'path' => $relative_path,
                            'reason' => 'exception',
                            'error' => $e->getMessage()
                        ];
                        continue;
                    }
                }
            }
        };
        
        // Process the directory
        $process_directory($directory);
        
        // Update final processing stats and progress
        $self->file_stats['total_files'] = $files_processed;
        $self->file_stats['processed_files'] = $files_processed;
        $self->update_progress(4, "File processing complete: {$files_processed} files, " . 
            round($total_size/1024/1024, 2) . "MB, " . count($tree_items) . " tree items");
        
        // Log summary of processed files
        wp_github_sync_log("Total files processed: {$files_processed}, total size: " . round($total_size/1024/1024, 2) . "MB", 'info');
        wp_github_sync_log("Tree items created: " . count($tree_items), 'info');
        
        // Log skipped files summary if any
        if (!empty($skipped_files)) {
            $skipped_count = count($skipped_files);
            wp_github_sync_log("Skipped {$skipped_count} files during processing", 'warning');
            
            // Group by reason
            $reasons = [];
            foreach ($skipped_files as $skip) {
                $reason = $skip['reason'];
                if (!isset($reasons[$reason])) {
                    $reasons[$reason] = 0;
                }
                $reasons[$reason]++;
            }
            
            foreach ($reasons as $reason => $count) {
                wp_github_sync_log("Reason '{$reason}': {$count} files", 'debug');
            }
            
            // Store skipped files in a transient for user feedback
            set_transient('wp_github_sync_skipped_files', $skipped_files, HOUR_IN_SECONDS);
            
            // Update progress with skipped files information
            $self->update_progress(4, "Warning: {$skipped_count} files were skipped during processing");
        }
        
        return $tree_items;
    }
    
    /**
     * List files in a directory recursively for debugging.
     *
     * @param string $dir The directory to list.
     * @param string $prefix Prefix for indentation in recursive calls.
     */
    private function list_directory_recursive($dir, $prefix = '') {
        if (!is_dir($dir)) {
            wp_github_sync_log("Not a directory: {$dir}", 'warning');
            return;
        }
        
        $files = scandir($dir);
        if ($files === false) {
            wp_github_sync_log("Failed to scan directory: {$dir}", 'error');
            return;
        }
        
        wp_github_sync_log("{$prefix}Directory contents of {$dir}:", 'debug');
        
        foreach ($files as $file) {
            if ($file == '.' || $file == '..') {
                continue;
            }
            
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                wp_github_sync_log("{$prefix}[DIR] {$file}/", 'debug');
                $this->list_directory_recursive($path, $prefix . '  ');
            } else {
                $filesize = filesize($path);
                $file_type = is_readable($path) ? "readable" : "not readable";
                wp_github_sync_log("{$prefix}[FILE] {$file} ({$filesize} bytes, {$file_type})", 'debug');
            }
        }
    }

    /**
     * Create a tree with the given tree items.
     *
     * @param array  $tree_items   The tree items.
     * @param string $base_tree_sha The base tree SHA.
     * @return string|\WP_Error The new tree SHA or WP_Error on failure.
     */
    private function create_tree($tree_items, $base_tree_sha) {
        wp_github_sync_log("Creating tree with " . count($tree_items) . " files", 'info');
        
        // Check if we have a large number of files that may exceed GitHub's limits
        $max_items_per_request = 100; // GitHub may have issues with too many items in a single request
        $chunks = array_chunk($tree_items, $max_items_per_request);
        
        if (count($chunks) > 1) {
            wp_github_sync_log("Files split into " . count($chunks) . " chunks due to size", 'info');
            
            // We need to build the tree incrementally
            $current_base_tree = $base_tree_sha;
            
            foreach ($chunks as $index => $chunk) {
                wp_github_sync_log("Processing chunk " . ($index + 1) . " of " . count($chunks), 'info');
                
                $chunk_tree = $this->api_client->request(
                    "repos/{$this->api_client->get_owner()}/{$this->api_client->get_repo()}/git/trees",
                    'POST',
                    [
                        'base_tree' => $current_base_tree,
                        'tree' => $chunk
                    ]
                );
                
                if (is_wp_error($chunk_tree)) {
                    wp_github_sync_log("Failed to create tree chunk " . ($index + 1) . ": " . $chunk_tree->get_error_message(), 'error');
                    return new \WP_Error('github_api_error', __('Failed to create tree chunk', 'wp-github-sync'));
                }
                
                // Use this tree as the base for the next chunk
                $current_base_tree = $chunk_tree['sha'];
                wp_github_sync_log("Created tree chunk " . ($index + 1) . " with SHA: {$current_base_tree}", 'debug');
            }
            
            return $current_base_tree;
        } else {
            // Single chunk approach for smaller trees
            $new_tree = $this->api_client->request(
                "repos/{$this->api_client->get_owner()}/{$this->api_client->get_repo()}/git/trees",
                'POST',
                [
                    'base_tree' => $base_tree_sha,
                    'tree' => $tree_items
                ]
            );
            
            if (is_wp_error($new_tree)) {
                wp_github_sync_log("Failed to create tree: " . $new_tree->get_error_message(), 'error');
                return new \WP_Error('github_api_error', __('Failed to create tree', 'wp-github-sync'));
            }
            
            return $new_tree['sha'];
        }
    }
    
    /**
     * Simple check to determine if a file is binary or text.
     *
     * @param string $file The path to the file to check.
     * @return bool True if the file appears to be binary, false otherwise.
     */
    private function is_binary_file($file) {
        if (!is_file($file) || !is_readable($file)) {
            return false;
        }
        
        // Read the first 1024 bytes of the file
        $fh = fopen($file, 'r');
        if (!$fh) {
            return false;
        }
        
        $sample = fread($fh, 1024);
        fclose($fh);
        
        if ($sample === false) {
            return false;
        }
        
        // Check for null byte, which is a good indicator of binary content
        if (strpos($sample, "\0") !== false) {
            return true;
        }
        
        // Check the ratio of control characters to printable characters
        $control_chars = 0;
        $printable_chars = 0;
        
        for ($i = 0; $i < strlen($sample); $i++) {
            $char = ord($sample[$i]);
            
            // Control characters (except for whitespace like tab, CR, LF)
            if (($char < 32 && !in_array($char, [9, 10, 13])) || $char > 126) {
                $control_chars++;
            } else {
                $printable_chars++;
            }
        }
        
        // If more than 30% are control characters, it's probably binary
        $total_chars = $control_chars + $printable_chars;
        return ($total_chars > 0) && (($control_chars / $total_chars) > 0.3);
    }
}