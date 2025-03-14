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
     * Initialize the Repository Uploader class.
     *
     * @param API_Client $api_client The API client instance.
     */
    public function __construct($api_client) {
        $this->api_client = $api_client;
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
        
        // Validate branch name (basic validation)
        if (!preg_match('/^[a-zA-Z0-9_\-\.\/]+$/', $branch)) {
            return new \WP_Error('invalid_branch', __('Invalid branch name', 'wp-github-sync'));
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
            $new_commit = $this->api_client->request(
                "repos/{$this->api_client->get_owner()}/{$this->api_client->get_repo()}/git/commits",
                'POST',
                [
                    'message' => $commit_message,
                    'tree' => $new_tree_sha,
                    'parents' => [$ref_sha]
                ]
            );
            
            if (is_wp_error($new_commit)) {
                wp_github_sync_log("Failed to create commit: " . $new_commit->get_error_message(), 'error');
                return new \WP_Error('github_api_error', __('Failed to create commit', 'wp-github-sync'));
            }
            
            $new_commit_sha = $new_commit['sha'];
            wp_github_sync_log("Created new commit with SHA: {$new_commit_sha}", 'info');
            
            // Step 6: Update the reference
            $update_ref = $this->api_client->request(
                "repos/{$this->api_client->get_owner()}/{$this->api_client->get_repo()}/git/refs/heads/{$branch}",
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
        
        // Recursive function to process directories
        $process_directory = function($dir, $base_path = '') use (&$process_directory, &$tree_items, &$files_processed, &$total_size, &$skipped_files, $upload_limit, $max_files) {
            $files = scandir($dir);
            
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
                    $process_directory($path, $relative_path);
                } else {
                    // Check file limits
                    if ($files_processed >= $max_files) {
                        wp_github_sync_log("Reached max file limit ({$max_files}). Some files were not processed.", 'warning');
                        return;
                    }
                    
                    // Check if file is binary or text
                    $finfo = new \finfo(FILEINFO_MIME);
                    $mime_type = $finfo->file($path);
                    $is_binary = (strpos($mime_type, 'text/') !== 0 && 
                                 strpos($mime_type, 'application/json') !== 0 &&
                                 strpos($mime_type, 'application/xml') !== 0);
                    
                    // Get file size
                    $file_size = filesize($path);
                    
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
                        
                        // Create blob based on file type
                        $blob_data = [];
                        if ($is_binary) {
                            $blob_data = [
                                'content' => base64_encode($content),
                                'encoding' => 'base64'
                            ];
                        } else {
                            $blob_data = [
                                'content' => $content,
                                'encoding' => 'utf-8'
                            ];
                        }
                        
                        $blob = $this->api_client->request(
                            "repos/{$this->api_client->get_owner()}/{$this->api_client->get_repo()}/git/blobs",
                            'POST',
                            $blob_data
                        );
                        
                        if (is_wp_error($blob)) {
                            wp_github_sync_log("Failed to create blob for {$relative_path}: " . $blob->get_error_message(), 'error');
                            $skipped_files[] = [
                                'path' => $relative_path,
                                'reason' => 'blob_creation_failed',
                                'error' => $blob->get_error_message()
                            ];
                            continue;
                        }
                        
                        // Determine file mode (executable or regular file)
                        $file_mode = '100644'; // Regular file
                        if (is_executable($path)) {
                            $file_mode = '100755'; // Executable file
                        }
                        
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
        }
        
        return $tree_items;
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
}