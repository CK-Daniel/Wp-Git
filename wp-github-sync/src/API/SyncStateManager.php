<?php
/**
 * Manages the state of chunked synchronization processes.
 *
 * @package WPGitHubSync\API
 */

namespace WPGitHubSync\API;

use WPGitHubSync\Utils\FilesystemHelper;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Sync State Manager class.
 */
class SyncStateManager {

    const OPTION_NAME = 'wp_github_sync_chunked_sync_state';
    const PROGRESS_OPTION = 'wp_github_sync_sync_progress'; // Added for consistency

    /**
     * Get the current sync state.
     *
     * @return array|null The sync state array or null if not set.
     */
    public function get_state(): ?array {
        return get_option(self::OPTION_NAME, null);
    }

    /**
     * Check if a sync process is currently running (based on state existence).
     *
     * @return bool True if sync state exists, false otherwise.
     */
    public function is_sync_running(): bool {
        return (bool) $this->get_state();
    }

    /**
     * Initialize the sync state.
     *
     * @param string $branch The branch being synced.
     * @return array The initial state array.
     */
    public function initialize_state(string $branch): array {
        $initial_state = [
            'timestamp' => time(),
            'branch' => $branch,
            'stage' => 'authentication', // Initial stage
            'progress_step' => 0,
            'status' => 'initializing', // Add initial status
        ];
        update_option(self::OPTION_NAME, $initial_state);
        wp_github_sync_log("Sync State Manager: Initialized state for branch '{$branch}'.", 'info');
        return $initial_state;
    }

    /**
     * Update the sync state with new key-value pairs.
     *
     * @param array $updates Associative array of updates.
     * @return bool True on success, false on failure.
     */
    public function update_state(array $updates): bool {
        $current_state = $this->get_state();
        if ($current_state === null) {
            // Cannot update non-existent state, maybe initialize first?
            wp_github_sync_log("Sync State Manager: Attempted to update non-existent state.", 'warning');
            return false;
        }
        $new_state = array_merge($current_state, $updates);
        return update_option(self::OPTION_NAME, $new_state);
    }

    /**
     * Clean up the sync state and associated temporary files/options.
     *
     * @param array|null $state Optional. The state array to use for cleanup (e.g., finding temp dir).
     */
    public function cleanup_state(?array $state = null) {
        $state_to_clean = $state ?: $this->get_state(); // Use provided state or fetch current

        if ($state_to_clean && isset($state_to_clean['temp_dir'])) {
            FilesystemHelper::recursive_rmdir($state_to_clean['temp_dir']);
            wp_github_sync_log("Sync State Manager: Cleaned up temp directory: " . $state_to_clean['temp_dir'], 'debug');
        }

        delete_option(self::OPTION_NAME);
        // Optionally clear the main progress option as well
        // delete_option(self::PROGRESS_OPTION);
        wp_github_sync_log("Sync State Manager: Cleaned up chunked sync state option.", 'info');
    }

    /**
     * Mark the sync state as failed due to a fatal error.
     * To be called from a shutdown handler.
     *
     * @param array $error The error array from error_get_last().
     */
    public function mark_fatal_error(array $error) {
        $sync_state = $this->get_state();
        // Only act if we are actually in a chunked sync process
        if (!empty($sync_state)) {
            wp_github_sync_log("Sync State Manager: Fatal error detected during sync: " . $error['message'], 'error');
            $sync_state['fatal_error'] = $error;
            $sync_state['status'] = 'failed'; // Mark state as failed
            update_option(self::OPTION_NAME, $sync_state); // Update option directly
            // Optionally schedule a cleanup/notification task here
        }
    }

    /**
     * Check if the state indicates a fatal error occurred previously.
     *
     * @param array|null $state Optional. The state array to check.
     * @return bool True if a fatal error is flagged, false otherwise.
     */
    public function has_fatal_error(?array $state = null): bool {
        $state_to_check = $state ?: $this->get_state();
        return isset($state_to_check['fatal_error']);
    }

} // End class SyncStateManager
