<?php
/**
 * The admin-specific functionality of the plugin.
 * Initializes admin-related managers and handles non-AJAX admin actions.
 *
 * @package WPGitHubSync\Admin
 */

namespace WPGitHubSync\Admin;

// Use statements for dependencies and managers
use WPGitHubSync\API\API_Client;
use WPGitHubSync\API\Repository;
use WPGitHubSync\Sync\Sync_Manager;
use WPGitHubSync\Admin\Progress_Tracker; // Add Progress_Tracker
use WPGitHubSync\Admin\BackgroundTaskRunner; // Add BackgroundTaskRunner
use WPGitHubSync\Admin\Log_Manager; // Add Log_Manager
use WPGitHubSync\Admin\AJAX; // Namespace for new AJAX handlers

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Admin bootstrap class.
 */
class Admin {

    /**
     * The version of this plugin.
     *
     * @var string
     */
    private $version;

    /**
     * The main plugin file path relative to plugins dir.
     *
     * @var string
     */
    private $plugin_file;

    /**
     * GitHub API client instance.
     *
     * @var API_Client
     */
    private $github_api;

    /**
     * Git Sync Manager instance.
     *
     * @var Sync_Manager
     */
    private $sync_manager;

    /**
     * Repository instance.
     *
     * @var Repository
     */
    private $repository;

    /**
     * Asset Manager instance.
     *
     * @var Asset_Manager
     */
    private $asset_manager;

    /**
     * Admin Pages instance.
     *
     * @var Admin_Pages
     */
    private $admin_pages;

    /**
     * Menu Manager instance.
     *
     * @var Menu_Manager
     */
    private $menu_manager;

    /**
     * AJAX Handler instance.
     *
     * @var AJAX_Handler
     * @deprecated Use specific handlers instead.
     */
    private $ajax_handler;

    /**
     * Specific AJAX Handlers
     */
    private $sync_actions_handler;
    private $status_check_handler;
    private $settings_actions_handler;
    private $oauth_actions_handler;
    private $utility_actions_handler;

    /**
     * Notice Manager instance.
     *
     * @var Notice_Manager
     */
    private $notice_manager;

    /**
     * OAuth Handler instance.
     *
     * @var OAuth_Handler
     */
    private $oauth_handler;

    /**
     * Job Manager instance.
     *
     * @var Job_Manager
     */
    private $job_manager;

    /**
     * Progress Tracker instance.
     * @var Progress_Tracker
     */
    private $progress_tracker;

    /**
     * Background Task Runner instance.
     * @var BackgroundTaskRunner
     */
    private $background_task_runner;

    /**
     * Log Manager instance.
     * @var Log_Manager
     */
    private $log_manager;


    /**
     * Initialize the class and set its properties.
     *
     * @param string       $version      The version of this plugin.
     * @param string       $plugin_file  The main plugin file path.
     * @param API_Client   $github_api   The GitHub API client instance.
     * @param Sync_Manager $sync_manager The Sync Manager instance.
     * @param Repository   $repository   The Repository instance.
     */
    public function __construct(
        $version,
        $plugin_file,
        API_Client $github_api,
        Sync_Manager $sync_manager,
        Repository $repository
    ) {
        $this->version = $version;
        $this->plugin_file = $plugin_file;
        $this->github_api = $github_api;
        $this->sync_manager = $sync_manager;
        $this->repository = $repository;

        // Instantiate Core Managers & Helpers
        $this->asset_manager = new Asset_Manager($this->version);
        $this->notice_manager = new Notice_Manager();
        $this->oauth_handler = new OAuth_Handler();
        $this->progress_tracker = new Progress_Tracker();
        $this->background_task_runner = new BackgroundTaskRunner();
        $this->log_manager = new Log_Manager();

        // Instantiate Managers requiring dependencies
        $this->job_manager = new Job_Manager(
            $this->github_api,
            $this->sync_manager,
            $this->repository,
            $this->progress_tracker,
            $this->background_task_runner
        );
        // Inject API_Client into Admin_Pages
        $this->admin_pages = new Admin_Pages($this->log_manager, $this->job_manager, $this->github_api);
        $this->menu_manager = new Menu_Manager($this->admin_pages, $this->plugin_file);

        // Instantiate new AJAX Handlers
        $this->sync_actions_handler = new AJAX\SyncActionsHandler(
            $this->sync_manager,
            $this->repository,
            $this->job_manager,
            $this->progress_tracker
        );
        $this->status_check_handler = new AJAX\StatusCheckHandler($this->progress_tracker);
        $this->settings_actions_handler = new AJAX\SettingsActionsHandler($this->github_api);
        $this->oauth_actions_handler = new AJAX\OAuthActionsHandler(); // Add dependencies if needed
        $this->utility_actions_handler = new AJAX\UtilityActionsHandler(); // Add dependencies if needed

        // Register hooks for core managers
        $this->asset_manager->register_hooks();
        $this->menu_manager->register_hooks();
        $this->notice_manager->register_hooks();
        $this->oauth_handler->register_hooks();
        $this->job_manager->register_hooks();
        $this->log_manager->register_hooks(); // Register Log Manager hooks

        // Register hooks for new AJAX Handlers
        $this->sync_actions_handler->register_hooks();
        $this->status_check_handler->register_hooks();
        $this->settings_actions_handler->register_hooks();
        $this->oauth_actions_handler->register_hooks();
        $this->utility_actions_handler->register_hooks();

        // Deprecated AJAX Handler - keep for potential backward compatibility if needed, but don't register hooks
        // $this->ajax_handler = new AJAX_Handler($this->github_api, $this->sync_manager, $this->repository);
        // $this->ajax_handler->register_hooks(); // DO NOT REGISTER HOOKS FOR OLD HANDLER

        // Register dashboard widget hook
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widget'));

        // Hook for handling non-AJAX admin actions (e.g., from URL parameters)
        add_action('admin_init', array($this, 'handle_admin_actions'));
    }

    /**
     * Add dashboard widget.
     */
    public function add_dashboard_widget() {
        // Check if user has permissions before adding widget
        if (wp_github_sync_current_user_can()) {
            wp_add_dashboard_widget(
                'wp_github_sync_dashboard_widget',
                __('GitHub Sync Status', 'wp-github-sync'),
                array($this, 'display_dashboard_widget')
            );
        }
    }

    /**
     * Display the dashboard widget content.
     * This remains here as it's simple and directly related to the dashboard widget hook.
     */
    public function display_dashboard_widget() {
        $last_commit = get_option('wp_github_sync_last_deployed_commit', '');
        $latest_commit = get_option('wp_github_sync_latest_commit', array());
        $update_available = get_option('wp_github_sync_update_available', false);

        echo '<div class="wp-github-sync-widget">';

        if (empty($last_commit)) {
            echo '<p>' . esc_html__('GitHub Sync is configured but no deployments have been made yet.', 'wp-github-sync') . '</p>';
        } else {
            echo '<p><strong>' . esc_html__('Last deployed commit:', 'wp-github-sync') . '</strong> ' . esc_html(substr($last_commit, 0, 8)) . '</p>';
        }

        if ($update_available && !empty($latest_commit)) {
            echo '<p class="wp-github-sync-update-available">' . esc_html__('Update available!', 'wp-github-sync') . '</p>';
            echo '<p><strong>' . esc_html__('Latest commit:', 'wp-github-sync') . '</strong> ' . esc_html(substr($latest_commit['sha'], 0, 8)) . '</p>';
            if (isset($latest_commit['message'])) {
                 echo '<p>' . esc_html(wp_github_sync_format_commit_message($latest_commit['message'])) . '</p>';
            }
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=wp-github-sync')) . '" class="button">' . esc_html__('Deploy Now', 'wp-github-sync') . '</a></p>';
        } elseif (!empty($last_commit)) {
            echo '<p>' . esc_html__('Your site is up to date with GitHub.', 'wp-github-sync') . '</p>';
        }

        echo '<p><a href="' . esc_url(admin_url('admin.php?page=wp-github-sync')) . '">' . esc_html__('View Dashboard', 'wp-github-sync') . '</a></p>';
        echo '</div>';
    }

    /**
     * Handle direct admin actions (e.g., from URL parameters like deploy, switch_branch, rollback).
     * This method remains here to catch actions triggered directly on admin pages before AJAX might be used.
     */
    public function handle_admin_actions() {
        // Only process on our plugin pages to avoid conflicts
        if (!isset($_GET['page']) || strpos($_GET['page'], 'wp-github-sync') === false) {
            return;
        }

        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';

        if (empty($action)) {
            return;
        }

        if (!wp_github_sync_current_user_can()) {
            wp_die(__('You do not have sufficient permissions to perform this action.', 'wp-github-sync'));
        }

        $nonce_verified = false;
        $result = null;
        $success_message = '';
        $error_message_format = '';

        // Handle different actions
        switch ($action) {
            case 'deploy':
                $nonce_verified = isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'wp_github_sync_deploy');
                if ($nonce_verified) {
                    $branch = wp_github_sync_get_current_branch();
                    $result = $this->sync_manager->deploy($branch);
                    $success_message = __('Deployment completed successfully.', 'wp-github-sync');
                    $error_message_format = __('Deployment failed: %s', 'wp-github-sync');
                }
                break;

            case 'switch_branch':
                $nonce_verified = isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'wp_github_sync_switch_branch');
                if ($nonce_verified && isset($_GET['branch']) && !empty($_GET['branch'])) {
                    $branch = sanitize_text_field($_GET['branch']);
                    $result = $this->sync_manager->switch_branch($branch);
                    $success_message = sprintf(__('Successfully switched to branch: %s', 'wp-github-sync'), $branch);
                    $error_message_format = __('Branch switch failed: %s', 'wp-github-sync');
                } elseif ($nonce_verified) {
                     wp_die(__('No branch specified.', 'wp-github-sync'));
                }
                break;

            case 'rollback':
                $nonce_verified = isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'wp_github_sync_rollback');
                 if ($nonce_verified && isset($_GET['commit']) && !empty($_GET['commit'])) {
                    $commit = sanitize_text_field($_GET['commit']);
                    $result = $this->sync_manager->rollback($commit);
                    $success_message = sprintf(__('Successfully rolled back to commit: %s', 'wp-github-sync'), substr($commit, 0, 8));
                    $error_message_format = __('Rollback failed: %s', 'wp-github-sync');
                } elseif ($nonce_verified) {
                     wp_die(__('No commit specified.', 'wp-github-sync'));
                }
                break;
        }

        // Process result and add admin notice
        if ($nonce_verified && $result !== null) {
            if (is_wp_error($result)) {
                add_settings_error(
                    'wp_github_sync', // Setting group
                    'admin_action_failed', // Slug
                    sprintf($error_message_format, $result->get_error_message()), // Message
                    'error' // Type
                );
            } else {
                 add_settings_error(
                    'wp_github_sync', // Setting group
                    'admin_action_success', // Slug
                    $success_message, // Message
                    'success' // Type
                );
            }
            // Redirect to remove action parameters from URL
            wp_safe_redirect(remove_query_arg(array('action', '_wpnonce', 'commit', 'branch'), wp_get_referer()));
            exit;
        } elseif ($action !== '' && !$nonce_verified) {
             wp_die(__('Security check failed. Please try again.', 'wp-github-sync'));
        }
    }
}
