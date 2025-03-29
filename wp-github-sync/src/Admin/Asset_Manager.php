<?php
/**
 * Handles enqueuing admin styles and scripts for the WordPress GitHub Sync plugin.
 *
 * @package WPGitHubSync\Admin
 */

namespace WPGitHubSync\Admin;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Asset Manager class.
 */
class Asset_Manager {

    /**
     * The version of this plugin.
     *
     * @var string
     */
    private $version;

    /**
     * Constructor.
     *
     * @param string $version The version of this plugin.
     */
    public function __construct($version) {
        $this->version = $version;
    }

    /**
     * Register WordPress hooks.
     */
    public function register_hooks() {
        add_action('admin_enqueue_scripts', array($this, 'enqueue_styles'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    /**
     * Register the stylesheets for the admin area.
     */
    public function enqueue_styles() {
        $screen = get_current_screen();

        // Only enqueue on our plugin pages
        if (!$screen || strpos($screen->id, 'wp-github-sync') === false) {
            return;
        }

        // Always load the main admin CSS
        wp_enqueue_style(
            'wp-github-sync-admin',
            WP_GITHUB_SYNC_URL . 'admin/assets/css/admin.css',
            array(),
            $this->version,
            'all'
        );

        // Load page-specific CSS files
        if (strpos($screen->id, 'wp-github-sync-dashboard') !== false || $screen->id === 'toplevel_page_wp-github-sync') {
            wp_enqueue_style(
                'wp-github-sync-dashboard',
                WP_GITHUB_SYNC_URL . 'admin/assets/css/dashboard.css',
                array('wp-github-sync-admin'),
                $this->version,
                'all'
            );
        }

        // Load Jobs Monitor specific CSS
        if (strpos($screen->id, 'wp-github-sync-jobs') !== false) {
            wp_enqueue_style(
                'wp-github-sync-jobs',
                WP_GITHUB_SYNC_URL . 'admin/assets/css/jobs.css',
                array('wp-github-sync-admin'),
                $this->version,
                'all'
            );
        }
    }

    /**
     * Register the JavaScript for the admin area.
     */
    public function enqueue_scripts() {
        $screen = get_current_screen();

        // Only enqueue on our plugin pages
        if (!$screen || strpos($screen->id, 'wp-github-sync') === false) {
            return;
        }

        // Base admin script needed for all pages
        wp_enqueue_script(
            'wp-github-sync-admin',
            WP_GITHUB_SYNC_URL . 'admin/assets/js/admin.js',
            array('jquery'),
            $this->version,
            false // Load in footer
        );

        // Add localized script data
        wp_localize_script(
            'wp-github-sync-admin',
            'wpGitHubSync',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'adminUrl' => admin_url(),
                'nonce' => wp_create_nonce('wp_github_sync_nonce'),
                'initialSyncNonce' => wp_create_nonce('wp_github_sync_initial_sync'),
                'currentPage' => $screen->id,
                'strings' => array(
                    'confirmDeploy' => __('Are you sure you want to deploy the latest changes from GitHub? This will update your site files.', 'wp-github-sync'),
                    'confirmSwitchBranch' => __('Are you sure you want to switch branches? This will update your site files to match the selected branch.', 'wp-github-sync'),
                    'confirmRollback' => __('Are you sure you want to roll back to this commit? This will revert your site files to an earlier state.', 'wp-github-sync'),
                    'confirmRegenerateWebhook' => __('Are you sure you want to regenerate the webhook secret? You will need to update it in your GitHub repository settings.', 'wp-github-sync'),
                    'confirmFullSync' => __('This will sync all your WordPress site files to GitHub. Continue?', 'wp-github-sync'),
                    'backgroundProcessInfo' => __('This will run in the background without time limits.', 'wp-github-sync'),
                    'inBackground' => __('in background mode', 'wp-github-sync'),
                    'backgroundSyncStarted' => __('Background Process Started', 'wp-github-sync'),
                    'success' => __('Operation completed successfully.', 'wp-github-sync'),
                    'error' => __('An error occurred. Please try again.', 'wp-github-sync'),
                    'deploying' => __('Deploying latest changes...', 'wp-github-sync'),
                    'checkingUpdates' => __('Checking for updates...', 'wp-github-sync'),
                    'syncing' => __('Syncing files to GitHub...', 'wp-github-sync'),
                    'switchingBranch' => __('Switching to branch: %s', 'wp-github-sync'),
                    'refreshingBranches' => __('Refreshing branches list...', 'wp-github-sync'),
                    'branchesRefreshed' => __('Branches list refreshed successfully.', 'wp-github-sync'),
                    'rollingBack' => __('Rolling back to commit: %s', 'wp-github-sync'),
                    'regeneratingWebhook' => __('Regenerating webhook secret...', 'wp-github-sync'),
                ),
            )
        );

        // Load page-specific scripts
        if (strpos($screen->id, 'wp-github-sync-dashboard') !== false || $screen->id === 'toplevel_page_wp-github-sync') {
            wp_enqueue_script(
                'wp-github-sync-dashboard',
                WP_GITHUB_SYNC_URL . 'admin/assets/js/dashboard.js',
                array('jquery', 'wp-github-sync-admin'),
                $this->version,
                false // Load in footer
            );
        }

        // Load jobs monitor page scripts
        if (strpos($screen->id, 'wp-github-sync-jobs') !== false) {
            wp_enqueue_script(
                'wp-github-sync-jobs',
                WP_GITHUB_SYNC_URL . 'admin/assets/js/jobs.js',
                array('jquery', 'wp-github-sync-admin'),
                $this->version,
                false // Load in footer
            );
        }

        // Load settings page specific scripts
        if (strpos($screen->id, 'wp-github-sync-settings') !== false) {
            wp_enqueue_script(
                'wp-github-sync-settings',
                WP_GITHUB_SYNC_URL . 'admin/assets/js/settings.js',
                array('jquery', 'wp-github-sync-admin'), // Depends on base admin script
                $this->version,
                true // Load in footer
            );
            // Remove the old inline script for tabs, as it's now in settings.js
            /* wp_add_inline_script('wp-github-sync-admin', '
                // Ensure settings tabs work properly when loaded
                 jQuery(document).ready(function($) {
                    // Helper to forcibly display all tab content for debugging
                    function forceShowAllTabContent() {
                        $(".wp-github-sync-tab-content").css("display", "block");
                    }

                    // Helper to forcibly show a specific tab
                    function forceShowTab(tabId) {
                        $(".wp-github-sync-tab").removeClass("active");
                        $(".wp-github-sync-tab-content").removeClass("active").hide();
                        $(".wp-github-sync-tab[data-tab=" + tabId + "]").addClass("active");
                        $("#" + tabId + "-tab-content").addClass("active").show();
                    }

                    // Uncomment for debugging tab issues
                    // forceShowAllTabContent();

                    // Initialize tabs if not already done
                    if ($(".wp-github-sync-tab-content.active").length === 0) {
                        // Default to first tab
                        forceShowTab("general");
                    }

                    // Manually trigger authentication tab if hash is present
                    if (window.location.hash === "#authentication") {
                        forceShowTab("authentication");
                    }

                     console.log("Settings tabs initialized");
                 });
             '); */
        }
    }
}
