<?php
/**
 * Handles WP-Cron schedules and tasks for the WordPress GitHub Sync plugin.
 *
 * @package WPGitHubSync\Sync
 */

namespace WPGitHubSync\Sync;

use WPGitHubSync\API\API_Client;
// Assuming Sync_Manager might be needed to trigger deploy, or Job_Manager
// use WPGitHubSync\Sync\Sync_Manager;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Cron Manager class.
 */
class CronManager {

    /**
     * GitHub API Client instance.
     * @var API_Client
     */
    private $github_api;

    /**
     * Constructor.
     *
     * @param API_Client $github_api   The GitHub API client.
     */
    public function __construct(API_Client $github_api) {
        $this->github_api = $github_api;
    }

    /**
     * Register hooks related to cron management.
     */
    public function register_hooks() {
        add_filter('cron_schedules', array($this, 'add_cron_interval'));
        add_action('wp_github_sync_cron_hook', array($this, 'check_for_updates'));
        // Hook to setup/clear schedules on option change or activation/deactivation
        add_action('update_option_wp_github_sync_auto_sync', array($this, 'setup_cron_schedules'), 10, 0);
        add_action('update_option_wp_github_sync_auto_sync_interval', array($this, 'setup_cron_schedules'), 10, 0);
    }

    /**
     * Setup or clear cron schedules based on plugin settings.
     */
    public function setup_cron_schedules() {
        $auto_sync_enabled = get_option('wp_github_sync_auto_sync', false);
        $hook = 'wp_github_sync_cron_hook';
        $timestamp = wp_next_scheduled($hook);

        if ($auto_sync_enabled) {
            $interval_name = 'wp_github_sync_interval';
            $schedules = wp_get_schedules();
            $current_interval_seconds = isset($schedules[$interval_name]['interval']) ? $schedules[$interval_name]['interval'] : 0;

            // Check if scheduled and if the interval matches the current setting
            $scheduled_interval = $timestamp ? wp_get_schedule($hook) : false;
            $needs_reschedule = false;

            if (!$timestamp) {
                // Not scheduled, schedule it
                $needs_reschedule = true;
                wp_github_sync_log("Cron job not scheduled. Scheduling now.", 'info');
            } elseif ($scheduled_interval !== $interval_name) {
                // Scheduled, but with the wrong interval name (shouldn't happen often)
                $needs_reschedule = true;
                wp_github_sync_log("Cron job scheduled with incorrect interval name '{$scheduled_interval}'. Rescheduling.", 'warning');
            }
            // Note: We don't check if the interval *value* changed because wp_get_schedule only returns the name.
            // The add_cron_interval filter ensures the name 'wp_github_sync_interval' always uses the current setting value.
            // If the interval setting changes, the filter updates the schedule definition, and WP Cron *should* use the new interval on the next run.
            // However, explicitly clearing/rescheduling when the interval *option* changes (hooked via update_option_) ensures it takes effect immediately.

            // If this function was triggered by the update_option hook, we should reschedule.
            // We can detect this by checking the current filter.
            if (current_filter() === 'update_option_wp_github_sync_auto_sync_interval') {
                 $needs_reschedule = true;
                 wp_github_sync_log("Cron interval setting changed. Rescheduling job.", 'info');
            }


            if ($needs_reschedule && $timestamp) {
                 wp_clear_scheduled_hook($hook);
                 wp_github_sync_log("Cleared existing cron job before rescheduling.", 'debug');
                 $timestamp = false; // Ensure it gets rescheduled below
            }

            if (!$timestamp) {
                 wp_schedule_event(time(), $interval_name, $hook);
                 $interval_minutes = get_option('wp_github_sync_auto_sync_interval', 5);
                 wp_github_sync_log("Scheduled auto-update check cron job with interval: {$interval_minutes} minutes.", 'info');
            } else {
                 wp_github_sync_log("Auto-update check cron job already scheduled correctly.", 'debug');
            }

        } else {
            // Clear the schedule if it exists but auto-sync is now disabled
            if ($timestamp) {
                wp_clear_scheduled_hook('wp_github_sync_cron_hook');
                wp_github_sync_log("Cleared auto-update check cron job.", 'info');
            }
        }
    }

    /**
     * Add custom cron interval based on plugin settings.
     *
     * @param array $schedules Existing cron schedules.
     * @return array Modified cron schedules.
     */
    public function add_cron_interval($schedules) {
        $interval = get_option('wp_github_sync_auto_sync_interval', 5);
        $interval_seconds = max(60, $interval * MINUTE_IN_SECONDS); // Ensure minimum 60 seconds

        $schedules['wp_github_sync_interval'] = array(
            'interval' => $interval_seconds,
            'display' => sprintf(__('Every %d minutes', 'wp-github-sync'), $interval),
        );

        return $schedules;
    }

    /**
     * Check for updates from GitHub (Cron callback).
     */
    public function check_for_updates() {
        // Ensure API client is initialized
        $this->github_api->initialize();

        // Check deployment lock (use transient)
        if (get_transient('wp_github_sync_deployment_lock')) {
            wp_github_sync_log('Cron Check: Skipping update check because a deployment is in progress', 'info');
            return;
        }

        wp_github_sync_log('Cron Check: Checking for updates from GitHub', 'info');

        $branch = wp_github_sync_get_current_branch();
        $latest_commit = $this->github_api->get_latest_commit($branch);

        if (is_wp_error($latest_commit)) {
            wp_github_sync_log("Cron Check: Error checking for updates - " . $latest_commit->get_error_message(), 'error');
            return;
        }

        $last_deployed_commit = get_option('wp_github_sync_last_deployed_commit', '');

        if (isset($latest_commit['sha']) && $latest_commit['sha'] !== $last_deployed_commit) {
            $latest_sha = $latest_commit['sha'];
            $latest_short_sha = substr($latest_sha, 0, 8);
            wp_github_sync_log("Cron Check: New commit found: {$latest_short_sha}", 'info');

            // Update latest commit info
            update_option('wp_github_sync_latest_commit', [
                'sha' => $latest_sha,
                'message' => $latest_commit['commit']['message'] ?? '',
                'author' => $latest_commit['commit']['author']['name'] ?? 'Unknown',
                'date' => $latest_commit['commit']['author']['date'] ?? '',
                'timestamp' => time(),
            ]);

            $auto_deploy = get_option('wp_github_sync_auto_deploy', false);
            if ($auto_deploy) {
                wp_github_sync_log("Cron Check: Auto-deploy enabled, scheduling background deployment.", 'info');
                // Schedule deployment via Job Manager's hook if possible, or directly if needed
                 if (function_exists('as_schedule_single_action')) {
                     as_schedule_single_action(time() + 5, 'wp_github_sync_run_background_deploy', ['params' => ['branch' => $branch]], 'wp-github-sync');
                 } else {
                     wp_schedule_single_event(time() + 5, 'wp_github_sync_run_background_deploy', [['branch' => $branch]]);
                 }
            } else {
                wp_github_sync_log("Cron Check: Auto-deploy disabled, marking update available.", 'info');
                update_option('wp_github_sync_update_available', true);
                if (get_option('wp_github_sync_notify_updates', false)) {
                    $this->send_update_notification($latest_commit);
                }
            }
        } else {
            wp_github_sync_log('Cron Check: No new commits found.', 'info');
            // Clear update available flag if we are up-to-date
            delete_option('wp_github_sync_update_available');
        }
    }

    /**
     * Send an email notification about available updates.
     *
     * @param array $commit The commit data.
     */
    private function send_update_notification($commit) {
        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');
        $commit_message = $commit['commit']['message'] ?? '';
        $commit_author = $commit['commit']['author']['name'] ?? 'Unknown';
        $commit_date = $commit['commit']['author']['date'] ?? '';

        $subject = sprintf(__('[%s] New GitHub update available', 'wp-github-sync'), $site_name);

        $message = sprintf(
            __("Hello,\n\nA new update is available for your WordPress site '%s' from GitHub.\n\nCommit: %s\nAuthor: %s\nDate: %s\nMessage: %s\n\nYou can deploy this update from the WordPress admin area under 'GitHub Sync'.\n\nRegards,\nWordPress GitHub Sync", 'wp-github-sync'),
            $site_name,
            substr($commit['sha'], 0, 8),
            $commit_author,
            $commit_date ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($commit_date)) : 'N/A',
            $commit_message
        );

        wp_mail($admin_email, $subject, $message);
        wp_github_sync_log("Update notification email sent to {$admin_email}", 'info');
    }

} // End class CronManager
