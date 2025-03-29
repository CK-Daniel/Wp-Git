<?php
/**
 * Helper functions specifically for the Initial Sync process.
 *
 * @package WPGitHubSync\API
 */

namespace WPGitHubSync\API;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Initial Sync Helper class.
 */
class InitialSyncHelper {

    /**
     * Determine package type from path.
     *
     * @param string $package_path The path relative to wp-content (e.g., 'themes', 'plugins/my-plugin').
     * @return string The package type ('theme', 'plugin', 'upload', 'misc').
     */
    public static function get_package_type(string $package_path): string {
        if (strpos($package_path, 'themes') === 0) return 'theme';
        if (strpos($package_path, 'plugins') === 0) return 'plugin';
        if (strpos($package_path, 'uploads') === 0) return 'upload';
        return 'misc';
    }

    /**
     * Get individual items (subdirectories or files) within a package source directory.
     *
     * @param string $source_path  The absolute path to the source directory (e.g., /path/to/wp-content/plugins).
     * @param string $package_type The type determined by get_package_type().
     * @return array An array of absolute paths to the items within the source directory.
     */
    public static function get_package_items(string $source_path, string $package_type): array {
        $items = [];
        if (!is_dir($source_path)) {
            wp_github_sync_log("InitialSyncHelper: Source path is not a directory: {$source_path}", 'warning');
            return $items;
        }

        $contents = scandir($source_path);
        if ($contents === false) {
             wp_github_sync_log("InitialSyncHelper: Failed to scan directory: {$source_path}", 'error');
             return $items;
        }

        foreach ($contents as $item) {
            if ($item === '.' || $item === '..') continue;

            $item_path = $source_path . '/' . $item;

            // For themes and plugins, each immediate subdirectory is an item.
            // For uploads or misc, each file/directory could potentially be an item (adjust as needed).
            if (is_dir($item_path) && ($package_type === 'theme' || $package_type === 'plugin')) {
                $items[] = $item_path;
            } elseif ($package_type === 'upload' || $package_type === 'misc') {
                 // Decide how to handle uploads/misc - maybe sync top-level items?
                 // For now, let's include both files and dirs at the top level of uploads/misc.
                 $items[] = $item_path;
            }
            // Add logic here if you need to handle files directly under themes/plugins differently.
        }
        return $items;
    }

    /**
     * Generate the relative path within the GitHub repository for a subpackage.
     * Assumes the target structure in GitHub mirrors the wp-content structure.
     *
     * @param string $package_path    The package path relative to wp-content (e.g., 'themes', 'plugins').
     * @param string $subpackage_name The name of the specific item (e.g., 'twentytwentyone', 'my-cool-plugin').
     * @return string The relative path for GitHub (e.g., 'wp-content/themes/twentytwentyone').
     */
    public static function get_relative_path_for_github(string $package_path, string $subpackage_name): string {
        // Ensure package_path starts with 'wp-content/' for consistency in the repo.
        $base = 'wp-content/';
        if (strpos($package_path, $base) === 0) {
            // If it already starts with wp-content, just append the subpackage name if needed.
            // This case seems less likely given how paths are usually defined.
             return rtrim($package_path, '/') . '/' . $subpackage_name;
        } else {
            // Prepend 'wp-content/' and append subpackage name.
            return $base . rtrim($package_path, '/') . '/' . $subpackage_name;
        }
        // Example: package_path = 'themes', subpackage_name = 'mytheme' -> 'wp-content/themes/mytheme'
        // Example: package_path = 'plugins', subpackage_name = 'myplugin' -> 'wp-content/plugins/myplugin'
    }

    /**
     * Prepare environment for background task execution (timeouts, memory limits).
     */
    public static function prepare_environment() {
        // Attempt to disable time limit
        @set_time_limit(0);

        // Attempt to increase memory limit
        $current_memory_limit = ini_get('memory_limit');
        $current_memory_bytes = wp_convert_hr_to_bytes($current_memory_limit);
        // Request a reasonable amount, e.g., 256M or 512M
        $desired_memory_bytes = wp_convert_hr_to_bytes('256M');

        if ($current_memory_bytes > 0 && $current_memory_bytes < $desired_memory_bytes) {
            @ini_set('memory_limit', '256M');
            $new_limit = ini_get('memory_limit');
            wp_github_sync_log("InitialSyncHelper: Attempted to increase memory limit to 256M. New limit: {$new_limit}", 'debug');
        }
    }

     /**
     * Schedule the next chunk processing task using Action Scheduler or WP Cron.
     */
    public static function schedule_next_chunk() {
        $hook = 'wp_github_sync_run_chunk_step'; // The hook that triggers process_chunked_sync_step
        $args = []; // No arguments needed for the chunk step processor

        if (function_exists('as_schedule_single_action')) {
            // Check if an identical action is already scheduled to avoid duplicates
            $actions = as_get_scheduled_actions([
                'hook' => $hook,
                'status' => \ActionScheduler_Store::STATUS_PENDING,
                'per_page' => 1,
            ], 'ids');

            if (empty($actions)) {
                as_schedule_single_action(time() + 5, $hook, $args, 'wp-github-sync'); // 5 sec delay
                wp_github_sync_log("InitialSyncHelper: Next chunk scheduled via Action Scheduler.", 'debug');
            } else {
                 wp_github_sync_log("InitialSyncHelper: Next chunk already scheduled via Action Scheduler.", 'debug');
            }
        } else {
            // Fallback to WP Cron
            if (!wp_next_scheduled($hook, $args)) {
                wp_schedule_single_event(time() + 5, $hook, $args);
                wp_github_sync_log("InitialSyncHelper: Next chunk scheduled via WP-Cron.", 'debug');
            } else {
                 wp_github_sync_log("InitialSyncHelper: Next chunk already scheduled via WP-Cron.", 'debug');
            }
        }
    }

} // End class InitialSyncHelper
