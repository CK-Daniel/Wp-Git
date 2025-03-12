<?php
/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks,
 * and sync functionality.
 *
 * @package WPGitHubSync
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * The core plugin class.
 */
class WP_GitHub_Sync {

    /**
     * The loader that's responsible for maintaining and registering all hooks.
     *
     * @var WP_GitHub_Sync_Loader
     */
    protected $loader;

    /**
     * Define the core functionality of the plugin.
     */
    public function __construct() {
        $this->load_dependencies();
        $this->set_locale();
        $this->define_admin_hooks();
        $this->define_sync_hooks();
    }

    /**
     * Load the required dependencies for this plugin.
     */
    private function load_dependencies() {
        /**
         * The class responsible for orchestrating the actions and filters of the
         * core plugin.
         */
        require_once WP_GITHUB_SYNC_DIR . 'includes/class-wp-github-sync-loader.php';

        /**
         * The class responsible for defining internationalization functionality.
         */
        require_once WP_GITHUB_SYNC_DIR . 'includes/class-wp-github-sync-i18n.php';

        /**
         * The class responsible for defining all actions in the admin area.
         */
        require_once WP_GITHUB_SYNC_DIR . 'admin/class-wp-github-sync-admin.php';

        /**
         * The class responsible for GitHub API integration.
         */
        require_once WP_GITHUB_SYNC_DIR . 'includes/class-github-api-client.php';

        /**
         * The class responsible for syncing files between WordPress and GitHub.
         */
        require_once WP_GITHUB_SYNC_DIR . 'includes/class-git-sync-manager.php';

        /**
         * The class responsible for managing plugin settings.
         */
        require_once WP_GITHUB_SYNC_DIR . 'includes/class-wp-github-sync-settings.php';

        /**
         * Utility functions.
         */
        require_once WP_GITHUB_SYNC_DIR . 'includes/wp-github-sync-helpers.php';

        $this->loader = new WP_GitHub_Sync_Loader();
    }

    /**
     * Define the locale for this plugin for internationalization.
     */
    private function set_locale() {
        $plugin_i18n = new WP_GitHub_Sync_i18n();
        $this->loader->add_action('plugins_loaded', $plugin_i18n, 'load_plugin_textdomain');
    }

    /**
     * Register all of the hooks related to the admin area functionality.
     */
    private function define_admin_hooks() {
        $plugin_admin = new WP_GitHub_Sync_Admin(WP_GITHUB_SYNC_VERSION);
        $plugin_settings = new WP_GitHub_Sync_Settings();

        // Admin menu and settings
        $this->loader->add_action('admin_menu', $plugin_admin, 'add_plugin_admin_menu');
        $this->loader->add_action('admin_init', $plugin_settings, 'register_settings');
        
        // Add settings link to the plugin
        $this->loader->add_filter('plugin_action_links_wp-github-sync/wp-github-sync.php', $plugin_admin, 'add_action_links');
        
        // Admin assets
        $this->loader->add_action('admin_enqueue_scripts', $plugin_admin, 'enqueue_styles');
        $this->loader->add_action('admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts');
        
        // Dashboard widget
        $this->loader->add_action('wp_dashboard_setup', $plugin_admin, 'add_dashboard_widget');
        
        // Admin notices
        $this->loader->add_action('admin_notices', $plugin_admin, 'display_admin_notices');
    }

    /**
     * Register all of the hooks related to the sync functionality.
     */
    private function define_sync_hooks() {
        $github_api = new GitHub_API_Client();
        $sync_manager = new Git_Sync_Manager($github_api);
        
        // REST API endpoints for webhooks
        $this->loader->add_action('rest_api_init', $sync_manager, 'register_webhook_endpoint');
        
        // Cron schedules
        $this->loader->add_action('init', $sync_manager, 'setup_cron_schedules');
        $this->loader->add_action('wp_github_sync_cron_hook', $sync_manager, 'check_for_updates');

        // Activation/deactivation hooks are registered separately in the main plugin file
        register_activation_hook(WP_GITHUB_SYNC_DIR . 'wp-github-sync.php', array($sync_manager, 'activate'));
        register_deactivation_hook(WP_GITHUB_SYNC_DIR . 'wp-github-sync.php', array($sync_manager, 'deactivate'));
    }

    /**
     * Run the loader to execute all of the hooks with WordPress.
     */
    public function run() {
        $this->loader->run();
    }
}