<?php
/**
 * Handles displaying admin notices for the WordPress GitHub Sync plugin.
 *
 * @package WPGitHubSync\Admin
 */

namespace WPGitHubSync\Admin;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Notice Manager class.
 */
class Notice_Manager {

    /**
     * Constructor.
     */
    public function __construct() {
        // Dependencies can be added here later if needed
    }

    /**
     * Register WordPress hooks.
     */
    public function register_hooks() {
        add_action('admin_notices', array($this, 'display_admin_notices'));
        // Add hook for settings errors displayed on our pages
        add_action('admin_print_styles', array($this, 'display_settings_errors'));
    }

    /**
     * Display admin notices.
     */
    public function display_admin_notices() {
        // Display notice if updates are available
        $update_available = get_option('wp_github_sync_update_available', false);
        $latest_commit = get_option('wp_github_sync_latest_commit', array());

        // Only show update notice on non-plugin pages
        if ($update_available && !empty($latest_commit) &&
            (!isset($_GET['page']) || strpos($_GET['page'], 'wp-github-sync') === false)) {
            ?>
            <div class="notice notice-info is-dismissible">
                <p>
                    <?php esc_html_e('GitHub Sync: New updates are available for deployment.', 'wp-github-sync'); ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wp-github-sync')); ?>"><?php esc_html_e('View Details', 'wp-github-sync'); ?></a>
                </p>
            </div>
            <?php
        }

        // Display notice if plugin is not fully configured
        $repository_url = get_option('wp_github_sync_repository', '');
        $auth_method = get_option('wp_github_sync_auth_method', 'pat');
        $token_set = false;
        if ($auth_method === 'pat') {
            $token_set = !empty(get_option('wp_github_sync_access_token', ''));
        } elseif ($auth_method === 'oauth') {
            $token_set = !empty(get_option('wp_github_sync_oauth_token', ''));
        } // Add check for GitHub App later if needed

        // Only show configuration warning on plugin pages
        if ((empty($repository_url) || !$token_set) &&
            (isset($_GET['page']) && strpos($_GET['page'], 'wp-github-sync') !== false)) {
            ?>
            <div class="notice notice-warning">
                <p>
                    <?php esc_html_e('GitHub Sync is not fully configured. Please complete the setup to enable syncing with GitHub.', 'wp-github-sync'); ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wp-github-sync-settings')); ?>"><?php esc_html_e('Configure Now', 'wp-github-sync'); ?></a>
                </p>
            </div>
            <?php
        }

        // Display transient notices (e.g., from token decryption errors)
        $transient_notice = get_transient('wp_github_sync_token_error');
        if ($transient_notice && is_array($transient_notice)) {
            $type = isset($transient_notice['type']) ? $transient_notice['type'] : 'error';
            $message = isset($transient_notice['message']) ? $transient_notice['message'] : __('An unknown error occurred.', 'wp-github-sync');
            ?>
            <div class="notice notice-<?php echo esc_attr($type); ?> is-dismissible">
                <p><?php echo esc_html($message); ?></p>
            </div>
            <?php
            // Delete the transient after displaying
            delete_transient('wp_github_sync_token_error');
        }
    }

    /**
     * Display settings errors specifically on our plugin pages.
     * WordPress normally only shows these on options.php.
     */
    public function display_settings_errors() {
        $screen = get_current_screen();
        if ($screen && strpos($screen->id, 'wp-github-sync') !== false) {
            settings_errors('wp_github_sync'); // Use the group name used in add_settings_error
        }
    }
}
