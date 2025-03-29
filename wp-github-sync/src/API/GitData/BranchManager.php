<?php
/**
 * Handles Git branch and reference operations via the GitHub API.
 *
 * @package WPGitHubSync\API\GitData
 */

namespace WPGitHubSync\API\GitData;

use WPGitHubSync\API\API_Client;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Branch Manager class.
 */
class BranchManager {

    /**
     * API Client instance.
     * @var API_Client
     */
    private $api_client;

    /**
     * Constructor.
     *
     * @param API_Client $api_client The API Client instance.
     */
    public function __construct(API_Client $api_client) {
        $this->api_client = $api_client;
    }

    /**
     * Get or create a branch reference.
     * Handles various edge cases like empty repositories and non-existent branches.
     *
     * @param string $branch         The target branch name.
     * @param string $default_branch The default branch name of the repo.
     * @return array|\WP_Error The branch reference array or WP_Error on failure.
     */
    public function get_or_create_branch_reference(string $branch, string $default_branch) {
        // Validate branch name
        if (!$this->is_valid_branch_name($branch)) {
             return new \WP_Error('invalid_branch', __('Invalid branch name provided.', 'wp-github-sync'));
        }

        // Check if branch exists
        $reference = $this->get_branch_reference($branch);

        if (!is_wp_error($reference)) {
            // Branch exists
            return $reference;
        }

        // Branch doesn't exist or error getting reference, check error type
        $error_code = $reference->get_error_code();
        $error_message = $reference->get_error_message();

        if (strpos($error_code, '404') !== false || strpos($error_message, 'Not Found') !== false) {
            // Branch ref not found, attempt to create it
            wp_github_sync_log("Branch {$branch} not found. Attempting to create it from {$default_branch}.", 'info');
            return $this->create_branch_from_default($branch, $default_branch);
        } else {
            // Some other error occurred trying to get the reference
            wp_github_sync_log("Failed to get reference for branch {$branch}: " . $error_message, 'error');
            return new \WP_Error('github_api_error', __('Failed to get branch reference. Please verify permissions.', 'wp-github-sync'));
        }
    }

    /**
     * Get the reference object for a specific branch.
     *
     * @param string $branch The branch name.
     * @return array|\WP_Error The reference object or WP_Error.
     */
    public function get_branch_reference(string $branch) {
         return $this->api_client->request(
            "repos/{$this->api_client->get_owner()}/{$this->api_client->get_repo()}/git/refs/heads/{$branch}"
        );
    }

    /**
     * Create a new branch reference based on the default branch.
     * Handles the case where the default branch itself might not exist (empty repo).
     *
     * @param string $new_branch     The name of the new branch to create.
     * @param string $default_branch The name of the default branch to base it on.
     * @return array|\WP_Error The new branch reference array or WP_Error on failure.
     */
    private function create_branch_from_default(string $new_branch, string $default_branch) {
        // Get the SHA of the default branch
        $default_ref = $this->get_branch_reference($default_branch);

        if (is_wp_error($default_ref)) {
            $error_message = $default_ref->get_error_message();
            wp_github_sync_log("Failed to get default branch ({$default_branch}) reference: " . $error_message, 'error');

            // Check if it's an empty repository error
            if (strpos($error_message, 'Not Found') !== false || strpos($error_message, '404') !== false || strpos($error_message, 'Git Repository is empty') !== false) {
                wp_github_sync_log("Default branch not found. Attempting to initialize empty repository with branch '{$new_branch}'.", 'info');
                // If the default branch doesn't exist, the repo is likely empty. Initialize it.
                return $this->create_initial_branch($new_branch);
            } else {
                // Other error getting default branch ref
                return new \WP_Error('github_api_error', sprintf(__('Failed to get default branch reference (%s): %s', 'wp-github-sync'), $default_branch, $error_message));
            }
        }

        // Create the new branch reference pointing to the default branch's commit SHA
        $create_ref = $this->api_client->request(
            "repos/{$this->api_client->get_owner()}/{$this->api_client->get_repo()}/git/refs",
            'POST',
            [
                'ref' => "refs/heads/{$new_branch}",
                'sha' => $default_ref['object']['sha']
            ]
        );

        if (is_wp_error($create_ref)) {
            wp_github_sync_log("Failed to create new branch '{$new_branch}': " . $create_ref->get_error_message(), 'error');
            return new \WP_Error('branch_creation_failed', __('Failed to create new branch', 'wp-github-sync'));
        }

        wp_github_sync_log("Successfully created branch '{$new_branch}' from '{$default_branch}'.", 'info');
        return $create_ref;
    }


    /**
     * Create the initial branch when the repository is empty by creating an initial commit.
     * Uses the Content API as the Git Data API fails on truly empty repos.
     *
     * @param string $branch The branch name to create.
     * @return array|\WP_Error The branch reference or WP_Error on failure.
     */
    private function create_initial_branch(string $branch) {
        wp_github_sync_log("Creating initial commit for empty repository on branch '{$branch}'.", 'info');

        try {
            // Create a README file using the contents API directly
            $readme_content = "# WordPress GitHub Sync\n\nThis repository was created by the WordPress GitHub Sync plugin.\n";
            $readme_commit = $this->api_client->request(
                "repos/{$this->api_client->get_owner()}/{$this->api_client->get_repo()}/contents/README.md",
                'PUT',
                [
                    'message' => 'Initial commit',
                    'content' => base64_encode($readme_content),
                    'branch' => $branch // This creates the branch if it doesn't exist
                ]
            );

            if (is_wp_error($readme_commit)) {
                wp_github_sync_log("Failed to create initial README.md file: " . $readme_commit->get_error_message(), 'error');
                // Provide a more helpful error message if initialization fails
                 return new \WP_Error(
                    'empty_repo_init_failed',
                    __('Failed to initialize the empty repository. Please ensure your token has permissions to write content and create branches, or initialize the repository manually on GitHub with a README file.', 'wp-github-sync')
                );
            }

            wp_github_sync_log("Successfully created initial commit with README.md on branch '{$branch}'.", 'info');

            // Now get the reference to the branch we just created
            $ref = $this->get_branch_reference($branch);

            if (is_wp_error($ref)) {
                wp_github_sync_log("Failed to get branch reference after initialization: " . $ref->get_error_message(), 'error');
                return new \WP_Error(
                    'ref_not_found_after_init',
                    __('Repository initialized but the branch reference could not be retrieved immediately.', 'wp-github-sync')
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

    /**
     * Update a branch reference to point to a new commit SHA.
     *
     * @param string $branch The branch name (e.g., 'main').
     * @param string $new_commit_sha The SHA of the new commit.
     * @return bool|\WP_Error True on success, WP_Error on failure.
     */
    public function update_branch_reference(string $branch, string $new_commit_sha) {
         if (!$this->is_valid_branch_name($branch)) {
             return new \WP_Error('invalid_branch', __('Invalid branch name provided.', 'wp-github-sync'));
        }

        $update_ref = $this->api_client->request(
            "repos/{$this->api_client->get_owner()}/{$this->api_client->get_repo()}/git/refs/heads/{$branch}",
            'PATCH',
            [
                'sha' => $new_commit_sha,
                'force' => false // Typically should not force unless necessary
            ]
        );

        if (is_wp_error($update_ref)) {
            // Check for non-fast-forward error, potentially retry with force?
            $error_message = $update_ref->get_error_message();
            wp_github_sync_log("Failed to update reference for branch '{$branch}' to {$new_commit_sha}: " . $error_message, 'error');
            // Consider retrying with force=true if error indicates non-fast-forward
            // if (strpos($error_message, 'non-fast-forward') !== false) { ... retry ... }
            return new \WP_Error('ref_update_failed', __('Failed to update branch reference.', 'wp-github-sync'));
        }

        wp_github_sync_log("Successfully updated branch '{$branch}' reference to commit {$new_commit_sha}", 'info');
        return true;
    }

    /**
     * Validate a branch name against common Git/GitHub restrictions.
     *
     * @param string $branch The branch name to validate.
     * @return bool True if valid, false otherwise.
     */
    private function is_valid_branch_name(string $branch): bool {
        if (empty($branch)) return false;
        // Basic format check
        if (!preg_match('/^[a-zA-Z0-9_\-\.\/]+$/', $branch)) return false;
        // Cannot start with dot, end with slash, contain double dots, spaces, or control chars
        if (preg_match('/^\.|\/$|\.\.|\s|[\x00-\x1F\x7F]/', $branch)) return false;
        // Cannot be 'HEAD' or end in '.lock'
        if ($branch === 'HEAD' || preg_match('/\.lock$/', $branch)) return false;
        // Cannot contain sequences like @{
        if (strpos($branch, '@{') !== false) return false;

        return true;
    }

} // End class BranchManager
