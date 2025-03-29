<?php
/**
 * Handles rendering admin pages for the WordPress GitHub Sync plugin.
 *
 * @package WPGitHubSync\Admin
 */

namespace WPGitHubSync\Admin;

// Use the new Log_Manager and Job_Manager classes
use WPGitHubSync\Admin\Log_Manager;
use WPGitHubSync\Admin\Job_Manager; // Needed for job page data
use WPGitHubSync\API\API_Client; // Need API Client for parsing URL

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Admin Pages class.
 */
class Admin_Pages {

    /**
     * Log Manager instance.
     *
     * @var Log_Manager
     */
    private $log_manager;

    /**
     * Job Manager instance.
     *
     * @var Job_Manager
     */
    private $job_manager;

    /**
     * API Client instance.
     *
     * @var API_Client
     */
    private $github_api;

    /**
     * Constructor.
     *
     * @param Log_Manager $log_manager The Log Manager instance.
     * @param Job_Manager $job_manager The Job Manager instance.
     * @param API_Client  $github_api  The API Client instance.
     */
    public function __construct(Log_Manager $log_manager, Job_Manager $job_manager, API_Client $github_api) {
        $this->log_manager = $log_manager;
        $this->job_manager = $job_manager;
        $this->github_api = $github_api; // Store API_Client instance
    }

    /**
     * Display the dashboard page.
     */
    public function display_dashboard_page() {
        // Handle direct actions like deploy, switch branch, rollback
        // NOTE: This logic might need to move to a central action handler later
        // $this->handle_admin_actions(); // Temporarily commented out, will be handled elsewhere

        // Get current repository info
        $repository_url = get_option('wp_github_sync_repository', '');
        $branch = wp_github_sync_get_current_branch();
        $last_deployed_commit = get_option('wp_github_sync_last_deployed_commit', '');
        $latest_commit_info = get_option('wp_github_sync_latest_commit', array());
        $update_available = get_option('wp_github_sync_update_available', false);
        // Use transient for lock status
        $is_deployment_locked = (bool) get_transient('wp_github_sync_deployment_lock');
        $last_deployment_time = !empty(get_option('wp_github_sync_deployment_history', array())) ?
            max(array_column(get_option('wp_github_sync_deployment_history', array()), 'timestamp')) : 0;
        $branches = get_option('wp_github_sync_branches', array());
        $recent_commits = get_option('wp_github_sync_recent_commits', array());

        // Parse URL using API Client method
        $parsed_url = $this->github_api->parse_github_url($repository_url);

        include WP_GITHUB_SYNC_DIR . 'admin/templates/dashboard-page.php';
    }

    /**
     * Display the settings page.
     */
    public function display_settings_page() {
        include WP_GITHUB_SYNC_DIR . 'admin/templates/settings-page.php';
    }

    /**
     * Display the deployment history page.
     */
    public function display_history_page() {
        // Handle direct actions if any
        // NOTE: This logic might need to move to a central action handler later
        // $this->handle_admin_actions(); // Temporarily commented out, will be handled elsewhere

        // Get deployment history
        $history = get_option('wp_github_sync_deployment_history', array());
        $repository_url = get_option('wp_github_sync_repository', '');

        // Sort history by date (newest first) and group by date
        $grouped_history = array();

        if (!empty($history)) {
            // Sort by timestamp (newest first)
            usort($history, function($a, $b) {
                return $b['timestamp'] <=> $a['timestamp'];
            });

            // Group by date
            foreach ($history as $deployment) {
                $date = date('Y-m-d', $deployment['timestamp']);
                if (!isset($grouped_history[$date])) {
                    $grouped_history[$date] = array();
                }
                $grouped_history[$date][] = $deployment;
            }
        }

        include WP_GITHUB_SYNC_DIR . 'admin/templates/history-page.php';
    }

    /**
     * Display the logs page.
     */
    public function display_logs_page() {
        // Check if wp_github_sync_log function exists
        if (!function_exists('wp_github_sync_log')) {
            wp_die(__('Required functions are missing. Please make sure the plugin is correctly installed.', 'wp-github-sync'));
        }

        // Verify user has permission
        if (!wp_github_sync_current_user_can()) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'wp-github-sync'));
        }

        // Log actions are now handled via the load hook in Log_Manager::register_hooks()

        // Get log data using Log_Manager with pagination
        $log_level_filter = isset($_GET['level']) ? sanitize_text_field($_GET['level']) : '';
        $search_query = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
        $current_page = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
        $logs_per_page = 100; // Or make this configurable?

        $log_data = $this->log_manager->get_logs($log_level_filter, $search_query, $current_page, $logs_per_page);

        // Extract variables for the template
        $logs = $log_data['logs'];
        $log_file_size = $log_data['log_file_size'];
        $is_truncated = $log_data['is_truncated'];
        $total_entries = $log_data['total_entries'];
        $total_pages = $log_data['total_pages'];
        // $current_page is already defined above

        // Include the template, passing the variables
        include WP_GITHUB_SYNC_DIR . 'admin/templates/logs-page.php';
    }

    /**
     * Display the jobs monitor page.
     */
    public function display_jobs_page() {
        // Validate user has permission
        if (!wp_github_sync_current_user_can()) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'wp-github-sync'));
        }

        // Job actions are handled by Job_Manager via load hook

        // Get data for the template using Job_Manager methods if available, or direct options
        // Note: We might need dedicated methods in Job_Manager to fetch this data cleanly.
        $chunked_sync_state = get_option('wp_github_sync_chunked_sync_state', null);

        // Get scheduled cron events
        $cron_events = array();
        $cron_array = _get_cron_array();

        if (!empty($cron_array)) {
            foreach ($cron_array as $timestamp => $hooks) {
                foreach ($hooks as $hook => $events) {
                    // Only show our plugin's events
                    if (strpos($hook, 'wp_github_sync') !== false) {
                        foreach ($events as $event_key => $event) {
                            $cron_events[] = array(
                                'timestamp' => $timestamp,
                                'hook' => $hook,
                                'args' => $event['args'],
                                'interval' => isset($event['interval']) ? $event['interval'] : 0,
                                'scheduled' => human_time_diff(time(), $timestamp) . ' ' .
                                              ($timestamp > time() ? __('from now', 'wp-github-sync') : __('ago', 'wp-github-sync')),
                                'next_run' => date_i18n('Y-m-d H:i:s', $timestamp),
                                'key' => $event_key
                            );
                        }
                    }
                }
            }
        }

        // Get deployment in progress
        $deployment_in_progress = get_option('wp_github_sync_deployment_in_progress', false); // Will be replaced by transient
        $deployment_start_time = get_option('wp_github_sync_last_deployment_time', 0); // Will be replaced by transient

        // Get sync in progress (for backward compatibility)
        $sync_in_progress = get_option('wp_github_sync_sync_in_progress', false); // Will be replaced by transient
        $sync_start_time = get_option('wp_github_sync_sync_start_time', 0); // Will be replaced by transient

        // Include the template file
        include WP_GITHUB_SYNC_DIR . 'admin/templates/jobs-page.php';
    }

    // --- Helper methods moved to Log_Manager ---
    // Removed: read_last_lines()
    // Removed: handle_log_actions()
    // Removed: is_path_in_wp_content()
}
