<?php
/**
 * Sync manager for WordPress GitHub Sync.
 *
 * @package WPGitHubSync
 */

namespace WPGitHubSync\Sync;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Manager class for handling sync operations.
 */
class Manager {

    /**
     * The version of this plugin.
     *
     * @var string
     */
    private $version;

    /**
     * Initialize the class and set its properties.
     *
     * @param string $version The version of this plugin.
     */
    public function __construct( $version ) {
        $this->version = $version;
    }

    /**
     * Check if we need to schedule a sync.
     */
    public function maybe_schedule_sync() {
        $auto_sync = get_option('wp_github_sync_auto_sync', false);
        
        if ($auto_sync) {
            if (!wp_next_scheduled('wp_github_sync_cron')) {
                $interval = get_option('wp_github_sync_auto_sync_interval', 5);
                $interval = max(1, min(1440, $interval));  // Between 1 minute and 24 hours
                
                wp_schedule_event(time(), 'wp_github_sync_interval', 'wp_github_sync_cron');
            }
        } else {
            // If auto-sync is disabled, clear any scheduled events
            $timestamp = wp_next_scheduled('wp_github_sync_cron');
            if ($timestamp) {
                wp_unschedule_event($timestamp, 'wp_github_sync_cron');
            }
        }
    }

    /**
     * Handle scheduled sync.
     */
    public function handle_scheduled_sync() {
        // Implementation for scheduled sync
    }

    /**
     * Plugin activation hook.
     */
    public function activate() {
        // Set up cron schedule if auto-sync is enabled
        $this->maybe_schedule_sync();
        
        // Register custom cron interval
        add_filter('cron_schedules', array($this, 'add_cron_interval'));
    }

    /**
     * Plugin deactivation hook.
     */
    public function deactivate() {
        // Clear any scheduled cron jobs
        $timestamp = wp_next_scheduled('wp_github_sync_cron');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'wp_github_sync_cron');
        }
    }

    /**
     * Add custom cron interval.
     *
     * @param array $schedules The existing cron schedules.
     * @return array The modified cron schedules.
     */
    public function add_cron_interval($schedules) {
        $interval = get_option('wp_github_sync_auto_sync_interval', 5);
        $interval = max(1, min(1440, $interval));  // Between 1 minute and 24 hours
        
        $schedules['wp_github_sync_interval'] = array(
            'interval' => $interval * 60,  // Convert minutes to seconds
            'display' => sprintf(__('Every %d minutes', 'wp-github-sync'), $interval),
        );
        
        return $schedules;
    }
}