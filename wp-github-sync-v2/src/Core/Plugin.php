<?php
/**
 * Main Plugin Class
 *
 * @package WPGitHubSync
 */

namespace WPGitHubSync\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Main plugin class
 */
class Plugin {

    /**
     * Plugin version
     *
     * @var string
     */
    protected $version;

    /**
     * Plugin instance
     *
     * @var Plugin
     */
    private static $instance = null;

    /**
     * Class loader
     *
     * @var Loader
     */
    protected $loader;

    /**
     * Initialize the plugin
     */
    private function __construct() {
        $this->version = '2.0.0';
        $this->loader = new Loader();
        
        $this->set_locale();
        $this->define_api_hooks();
        $this->define_admin_hooks();
        $this->define_sync_hooks();
    }

    /**
     * Get plugin instance
     *
     * @return Plugin
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Set plugin locale
     *
     * @return void
     */
    private function set_locale() {
        $plugin_i18n = new I18n();
        $this->loader->add_action( 'plugins_loaded', $plugin_i18n, 'load_plugin_textdomain' );
    }

    /**
     * Register API hooks
     *
     * @return void
     */
    private function define_api_hooks() {
        $api_client = new \WPGitHubSync\API\Client( $this->get_version() );
        $repository = new \WPGitHubSync\API\Repository( $this->get_version() );
        $webhook_handler = new \WPGitHubSync\API\WebhookHandler( $this->get_version() );

        // Register REST routes
        $this->loader->add_action( 'rest_api_init', $api_client, 'register_routes' );
        
        // Register webhook handler
        $this->loader->add_action( 'wp_ajax_nopriv_wp_github_sync_webhook', $webhook_handler, 'handle_webhook' );
    }

    /**
     * Register admin hooks
     *
     * @return void
     */
    private function define_admin_hooks() {
        $admin = new \WPGitHubSync\Admin\AdminController( $this->get_version() );
        $settings = new \WPGitHubSync\Admin\Settings( $this->get_version() );
        $notice_manager = new \WPGitHubSync\Admin\NoticeManager( $this->get_version() );

        // Admin pages
        $this->loader->add_action( 'admin_menu', $admin, 'register_menu_pages' );
        $this->loader->add_action( 'admin_enqueue_scripts', $admin, 'enqueue_assets' );
        
        // Settings
        $this->loader->add_action( 'admin_init', $settings, 'register_settings' );
        
        // AJAX handlers
        $this->loader->add_action( 'wp_ajax_wp_github_sync_test_connection', $admin, 'handle_test_connection' );
        $this->loader->add_action( 'wp_ajax_wp_github_sync_get_commits', $admin, 'handle_get_commits' );
        
        // Admin notices
        $this->loader->add_action( 'admin_notices', $notice_manager, 'display_notices' );
    }

    /**
     * Register sync hooks
     *
     * @return void
     */
    private function define_sync_hooks() {
        $sync_manager = new \WPGitHubSync\Sync\Manager( $this->get_version() );
        $backup_manager = new \WPGitHubSync\Sync\BackupManager( $this->get_version() );
        $rollback_manager = new \WPGitHubSync\Sync\RollbackManager( $this->get_version() );

        // Schedule sync
        $this->loader->add_action( 'init', $sync_manager, 'maybe_schedule_sync' );
        $this->loader->add_action( 'wp_github_sync_cron', $sync_manager, 'handle_scheduled_sync' );
        
        // Backup hooks
        $this->loader->add_action( 'wp_github_sync_before_deploy', $backup_manager, 'create_backup' );
        
        // Activation hooks
        register_activation_hook( WP_GITHUB_SYNC_FILE, array( $sync_manager, 'activate' ) );
        register_deactivation_hook( WP_GITHUB_SYNC_FILE, array( $sync_manager, 'deactivate' ) );
    }

    /**
     * Run the plugin
     *
     * @return void
     */
    public function run() {
        $this->loader->run();
    }

    /**
     * Get plugin version
     *
     * @return string
     */
    public function get_version() {
        return $this->version;
    }
}