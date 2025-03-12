<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * @package WPGitHubSync
 */

namespace WPGitHubSync\Admin;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Admin class.
 */
class Admin {

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
    public function __construct($version) {
        $this->version = $version;
    }

    /**
     * Register the stylesheets for the admin area.
     */
    public function enqueue_styles() {
        wp_enqueue_style(
            'wp-github-sync-admin',
            WP_GITHUB_SYNC_URL . 'admin/assets/css/admin.css',
            array(),
            $this->version
        );
    }

    /**
     * Register the JavaScript for the admin area.
     */
    public function enqueue_scripts() {
        wp_enqueue_script(
            'wp-github-sync-admin',
            WP_GITHUB_SYNC_URL . 'admin/assets/js/admin.js',
            array('jquery'),
            $this->version,
            false
        );
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
            array($this, 'display_dashboard_page'),
            'dashicons-update',
            65
        );

        // Dashboard submenu
        add_submenu_page(
            'wp-github-sync',
            __('Dashboard', 'wp-github-sync'),
            __('Dashboard', 'wp-github-sync'),
            'manage_options',
            'wp-github-sync',
            array($this, 'display_dashboard_page')
        );

        // Settings submenu
        add_submenu_page(
            'wp-github-sync',
            __('Settings', 'wp-github-sync'),
            __('Settings', 'wp-github-sync'),
            'manage_options',
            'wp-github-sync-settings',
            array($this, 'display_settings_page')
        );

        // Deployment history submenu
        add_submenu_page(
            'wp-github-sync',
            __('Deployment History', 'wp-github-sync'),
            __('Deployment History', 'wp-github-sync'),
            'manage_options',
            'wp-github-sync-history',
            array($this, 'display_history_page')
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

    /**
     * Display the dashboard page.
     */
    public function display_dashboard_page() {
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
        include WP_GITHUB_SYNC_DIR . 'admin/templates/history-page.php';
    }

    /**
     * Add dashboard widget.
     */
    public function add_dashboard_widget() {
        wp_add_dashboard_widget(
            'wp_github_sync_dashboard_widget',
            __('GitHub Sync Status', 'wp-github-sync'),
            array($this, 'display_dashboard_widget')
        );
    }

    /**
     * Display the dashboard widget content.
     */
    public function display_dashboard_widget() {
        $last_commit = get_option('wp_github_sync_last_deployed_commit', '');
        $latest_commit = get_option('wp_github_sync_latest_commit', array());
        $update_available = get_option('wp_github_sync_update_available', false);
        
        echo '<div class="wp-github-sync-widget">';
        
        if (empty($last_commit)) {
            echo '<p>' . __('GitHub Sync is configured but no deployments have been made yet.', 'wp-github-sync') . '</p>';
        } else {
            echo '<p><strong>' . __('Last deployed commit:', 'wp-github-sync') . '</strong> ' . substr($last_commit, 0, 8) . '</p>';
        }
        
        if ($update_available && !empty($latest_commit)) {
            echo '<p class="wp-github-sync-update-available">' . __('Update available!', 'wp-github-sync') . '</p>';
            echo '<p><strong>' . __('Latest commit:', 'wp-github-sync') . '</strong> ' . substr($latest_commit['sha'], 0, 8) . '</p>';
            echo '<p>' . wp_github_sync_format_commit_message($latest_commit['message']) . '</p>';
            echo '<p><a href="' . admin_url('admin.php?page=wp-github-sync') . '" class="button">' . __('Deploy Now', 'wp-github-sync') . '</a></p>';
        } elseif (!empty($last_commit)) {
            echo '<p>' . __('Your site is up to date with GitHub.', 'wp-github-sync') . '</p>';
        }
        
        echo '<p><a href="' . admin_url('admin.php?page=wp-github-sync') . '">' . __('View Dashboard', 'wp-github-sync') . '</a></p>';
        echo '</div>';
    }

    /**
     * Display admin notices.
     */
    public function display_admin_notices() {
        // Display notice if updates are available
        $update_available = get_option('wp_github_sync_update_available', false);
        $latest_commit = get_option('wp_github_sync_latest_commit', array());
        
        if ($update_available && !empty($latest_commit) && isset($_GET['page']) && strpos($_GET['page'], 'wp-github-sync') === false) {
            ?>
            <div class="notice notice-info is-dismissible">
                <p>
                    <?php _e('GitHub Sync: New updates are available for deployment.', 'wp-github-sync'); ?>
                    <a href="<?php echo admin_url('admin.php?page=wp-github-sync'); ?>"><?php _e('View Details', 'wp-github-sync'); ?></a>
                </p>
            </div>
            <?php
        }
        
        // Display notice if plugin is not fully configured
        $repository_url = get_option('wp_github_sync_repository', '');
        $access_token = get_option('wp_github_sync_access_token', '');
        
        if (empty($repository_url) || empty($access_token)) {
            if (isset($_GET['page']) && strpos($_GET['page'], 'wp-github-sync') !== false) {
                ?>
                <div class="notice notice-warning">
                    <p>
                        <?php _e('GitHub Sync is not fully configured. Please complete the setup to enable syncing with GitHub.', 'wp-github-sync'); ?>
                        <a href="<?php echo admin_url('admin.php?page=wp-github-sync-settings'); ?>"><?php _e('Configure Now', 'wp-github-sync'); ?></a>
                    </p>
                </div>
                <?php
            }
        }
    }
}