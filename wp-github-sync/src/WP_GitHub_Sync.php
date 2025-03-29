<?php
/**
 * The core plugin class.
 *
 * @package WPGitHubSync
 */

namespace WPGitHubSync;

// Core WP_GitHub_Sync dependencies
use WPGitHubSync\Core\Loader;
use WPGitHubSync\Core\I18n;
use WPGitHubSync\Settings\Settings;

// Core Service Classes (to be instantiated once)
use WPGitHubSync\API\API_Client;
use WPGitHubSync\API\Repository_Uploader;
use WPGitHubSync\API\Repository;
use WPGitHubSync\Sync\File_Sync;
use WPGitHubSync\Sync\Backup_Manager;
use WPGitHubSync\Sync\Sync_Manager;

// Admin Facade/Bootstrap Class
use WPGitHubSync\Admin\Admin;


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
     * @var Loader
     */
    protected $loader;

    /**
     * The plugin version number.
     *
     * @var string
     */
    protected $version;

    /**
     * The main plugin file path.
     *
     * @var string
     */
    protected $plugin_file;

    // --- Core Service Instances ---
    protected $api_client;
    protected $repository_uploader;
    protected $repository;
    protected $file_sync;
    protected $backup_manager;
    protected $sync_manager;
    protected $cron_manager; // Add CronManager property
    protected $webhook_handler; // Add WebhookHandler property

    // --- Admin Instance ---
    protected $admin;

    /**
     * Define the core functionality of the plugin.
     */
    public function __construct() {
        $this->version = WP_GITHUB_SYNC_VERSION;
        $this->plugin_file = 'wp-github-sync/wp-github-sync.php'; // Relative path from plugins dir

        $this->load_dependencies();
        $this->instantiate_services();
        $this->set_locale();
        $this->define_admin_hooks();
        $this->define_sync_hooks();
    }

    /**
     * Load the required dependencies for this plugin.
     */
    private function load_dependencies() {
        // The Loader class handles registering hooks
        $this->loader = new Loader();
    }

    /**
     * Instantiate core service classes.
     */
    private function instantiate_services() {
        // Instantiate core services first
        $this->api_client = new API_Client();
        $this->repository_uploader = new Repository_Uploader($this->api_client);
        $this->file_sync = new File_Sync();
        $this->backup_manager = new Backup_Manager($this->file_sync); // Inject File_Sync

        // Instantiate InitialSyncManager with all dependencies
        $initial_sync_manager = new API\InitialSyncManager(
            $this->api_client,
            $this->repository_uploader,
            $this->repository_uploader->get_blob_creator(),   // Pass BlobCreator
            $this->repository_uploader->get_tree_builder(),   // Pass TreeBuilder
            $this->repository_uploader->get_branch_manager()  // Pass BranchManager
        );

        // Instantiate services that depend on others
        $this->repository = new Repository($this->api_client, $initial_sync_manager); // Inject API_Client, InitialSyncManager
        $this->sync_manager = new Sync_Manager(
            $this->api_client,
            $this->repository,
            $this->backup_manager,
            $this->file_sync
        ); // Inject all dependencies

        // Instantiate handlers used in define_sync_hooks
        $this->cron_manager = new Sync\CronManager($this->api_client, $this->sync_manager); // Pass dependencies
        $this->webhook_handler = new Sync\WebhookHandler($this->sync_manager, $this->repository); // Pass dependencies

        // Instantiate the Admin bootstrap class, injecting dependencies
        $this->admin = new Admin(
            $this->version,
            $this->plugin_file,
            $this->api_client,
            $this->sync_manager,
            $this->repository
        );
    }


    /**
     * Define the locale for this plugin for internationalization.
     */
    private function set_locale() {
        $plugin_i18n = new I18n();
        $this->loader->add_action('plugins_loaded', $plugin_i18n, 'load_plugin_textdomain');
    }

    /**
     * Register all of the hooks related to the admin area functionality.
     * Hooks are now registered within the Admin class and its managers.
     * We only need to register hooks handled directly by WP_GitHub_Sync or Settings.
     */
    private function define_admin_hooks() {
        // Settings registration is separate for now
        $plugin_settings = new Settings();
        $this->loader->add_action('admin_init', $plugin_settings, 'register_settings');

        // The Admin class constructor now handles registering hooks for:
        // - Assets (Asset_Manager)
        // - Menus (Menu_Manager)
        // - AJAX (AJAX_Handler)
        // - Notices (Notice_Manager)
        // - OAuth (OAuth_Handler)
        // - Jobs (Job_Manager)
        // - Dashboard Widget (Admin)
        // - Non-AJAX Actions (Admin)
    }

    /**
     * Register all of the hooks related to the sync functionality.
     */
    private function define_sync_hooks() {
        // Use the instantiated handlers

        // REST API endpoints for webhooks (handled by WebhookHandler)
        $this->loader->add_action('rest_api_init', $this->webhook_handler, 'register_webhook_endpoint');

        // Cron schedules (handled by CronManager)
        $this->loader->add_action('init', $this->cron_manager, 'setup_cron_schedules');
        $this->loader->add_action('wp_github_sync_cron_hook', $this->cron_manager, 'check_for_updates');

        // Background deployment hook is obsolete - handled by Job_Manager via Action Scheduler hooks
        // $this->loader->add_action('wp_github_sync_background_deploy', $this->sync_manager, 'background_deploy');

        // Activation/Deactivation hooks are handled globally but might call methods here if needed
        // The Sync_Manager instance is still available via get_sync_manager() if needed.
    }

    /**
     * Getter for the Sync Manager instance (needed for global activation/deactivation hooks).
     *
     * @return Sync_Manager
     */
    public function get_sync_manager() {
        return $this->sync_manager;
    }

    /**
     * Run the loader to execute all of the hooks with WordPress.
     */
    public function run() {
        $this->loader->run();
    }
}
