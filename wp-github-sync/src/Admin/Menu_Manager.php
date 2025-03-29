<?php
/**
 * Handles admin menu and action links for the WordPress GitHub Sync plugin.
 *
 * @package WPGitHubSync\Admin
 */

namespace WPGitHubSync\Admin;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Menu Manager class.
 */
class Menu_Manager {

    /**
     * Admin Pages instance.
     *
     * @var Admin_Pages
     */
    private $admin_pages;

    /**
     * The plugin file path (e.g., wp-github-sync/wp-github-sync.php).
     *
     * @var string
     */
    private $plugin_file;

    /**
     * Constructor.
     *
     * @param Admin_Pages $admin_pages The Admin Pages instance.
     * @param string      $plugin_file The main plugin file path relative to plugins dir.
     */
    public function __construct(Admin_Pages $admin_pages, $plugin_file) {
        $this->admin_pages = $admin_pages;
        $this->plugin_file = $plugin_file;
    }

    /**
     * Register WordPress hooks.
     */
    public function register_hooks() {
        add_action('admin_menu', array($this, 'add_plugin_admin_menu'));
        add_filter('plugin_action_links_' . $this->plugin_file, array($this, 'add_action_links'));
    }

    /**
     * Add plugin admin menu.
     */
    public function add_plugin_admin_menu() {
        // Main menu item
        add_menu_page(
            __('GitHub Sync', 'wp-github-sync'),
            __('GitHub Sync', 'wp-github-sync'),
            'manage_options',
            'wp-github-sync',
            array($this->admin_pages, 'display_dashboard_page'), // Use Admin_Pages instance
            'dashicons-update',
            65
        );

        // Dashboard submenu
        add_submenu_page(
            'wp-github-sync',
            __('Dashboard', 'wp-github-sync'),
            __('Dashboard', 'wp-github-sync'),
            'manage_options',
            'wp-github-sync', // Slug matches parent
            array($this->admin_pages, 'display_dashboard_page') // Use Admin_Pages instance
        );

        // Settings submenu
        add_submenu_page(
            'wp-github-sync',
            __('Settings', 'wp-github-sync'),
            __('Settings', 'wp-github-sync'),
            'manage_options',
            'wp-github-sync-settings',
            array($this->admin_pages, 'display_settings_page') // Use Admin_Pages instance
        );

        // Deployment history submenu
        add_submenu_page(
            'wp-github-sync',
            __('Deployment History', 'wp-github-sync'),
            __('Deployment History', 'wp-github-sync'),
            'manage_options',
            'wp-github-sync-history',
            array($this->admin_pages, 'display_history_page') // Use Admin_Pages instance
        );

        // Jobs Monitor submenu
        add_submenu_page(
            'wp-github-sync',
            __('Background Jobs', 'wp-github-sync'),
            __('Jobs Monitor', 'wp-github-sync'),
            'manage_options',
            'wp-github-sync-jobs',
            array($this->admin_pages, 'display_jobs_page') // Use Admin_Pages instance
        );

        // Logs submenu
        add_submenu_page(
            'wp-github-sync',
            __('Logs', 'wp-github-sync'),
            __('Logs', 'wp-github-sync'),
            'manage_options',
            'wp-github-sync-logs',
            array($this->admin_pages, 'display_logs_page') // Use Admin_Pages instance
        );
    }

    /**
     * Add action links to the plugin listing.
     *
     * @param array $links Existing plugin action links.
     * @return array Modified plugin action links.
     */
    public function add_action_links($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=wp-github-sync-settings') . '">' . __('Settings', 'wp-github-sync') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
}
