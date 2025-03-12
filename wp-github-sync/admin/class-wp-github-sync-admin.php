<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * @package WPGitHubSync
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * The admin-specific functionality of the plugin.
 */
class WP_GitHub_Sync_Admin {

    /**connt
     * The version of this plugin.
     *
     * @var string
     */
    private $version;

    /**
     * GitHub API client instance.
     *
     * @var GitHub_API_Client
     */
    private $github_api;

    /**
     * Git Sync Manager instance.
     *
     * @var Git_Sync_Manager
     */
    private $sync_manager;

    /**
     * Initialize the class and set its properties.
     *
     * @param string $version The version of this plugin.
     */
    public function __construct($version) {
        $this->version = $version;
        $this->github_api = new GitHub_API_Client();
        $this->sync_manager = new Git_Sync_Manager($this->github_api);
        
        // Register AJAX handlers
        add_action('wp_ajax_wp_github_sync_deploy', array($this, 'handle_ajax_deploy'));
        add_action('wp_ajax_wp_github_sync_switch_branch', array($this, 'handle_ajax_switch_branch'));
        add_action('wp_ajax_wp_github_sync_rollback', array($this, 'handle_ajax_rollback'));
        add_action('wp_ajax_wp_github_sync_refresh_branches', array($this, 'handle_ajax_refresh_branches'));
        add_action('wp_ajax_wp_github_sync_regenerate_webhook', array($this, 'handle_ajax_regenerate_webhook'));
        add_action('wp_ajax_wp_github_sync_oauth_connect', array($this, 'handle_ajax_oauth_connect'));
        add_action('wp_ajax_wp_github_sync_oauth_disconnect', array($this, 'handle_ajax_oauth_disconnect'));
        add_action('wp_ajax_wp_github_sync_initial_sync', array($this, 'handle_ajax_initial_sync'));
        add_action('wp_ajax_wp_github_sync_full_sync', array($this, 'handle_ajax_full_sync'));
        add_action('wp_ajax_wp_github_sync_test_connection', array($this, 'handle_ajax_test_connection'));
        
        // Handle OAuth callback
        add_action('admin_init', array($this, 'handle_oauth_callback'));
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
        
        wp_enqueue_style(
            'wp-github-sync-admin',
            WP_GITHUB_SYNC_URL . 'admin/assets/css/wp-github-sync-admin.css',
            array(),
            $this->version,
            'all'
        );
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
        
        wp_enqueue_script(
            'wp-github-sync-admin',
            WP_GITHUB_SYNC_URL . 'admin/assets/js/wp-github-sync-admin.js',
            array('jquery'),
            $this->version,
            false
        );
        
        wp_localize_script(
            'wp-github-sync-admin',
            'wpGitHubSync',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'adminUrl' => admin_url(),
                'nonce' => wp_create_nonce('wp_github_sync_nonce'),
                'strings' => array(
                    'confirmDeploy' => __('Are you sure you want to deploy the latest changes from GitHub? This will update your site files.', 'wp-github-sync'),
                    'confirmSwitchBranch' => __('Are you sure you want to switch branches? This will update your site files to match the selected branch.', 'wp-github-sync'),
                    'confirmRollback' => __('Are you sure you want to roll back to this commit? This will revert your site files to an earlier state.', 'wp-github-sync'),
                    'confirmRegenerateWebhook' => __('Are you sure you want to regenerate the webhook secret? You will need to update it in your GitHub repository settings.', 'wp-github-sync'),
                    'confirmFullSync' => __('This will sync all your WordPress site files to GitHub. Continue?', 'wp-github-sync'),
                    'success' => __('Operation completed successfully.', 'wp-github-sync'),
                    'error' => __('An error occurred. Please try again.', 'wp-github-sync'),
                    'initialSyncNoRepo' => __('Please enter a repository name.', 'wp-github-sync'),
                    'initialSyncCreating' => __('Creating new repository...', 'wp-github-sync'),
                    'initialSyncConnecting' => __('Connecting to existing repository...', 'wp-github-sync'),
                    'initialSyncError' => __('An unexpected error occurred. Please try again.', 'wp-github-sync'),
                ),
            )
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
            array($this, 'display_admin_dashboard'),
            'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 19c-5 1.5-5-2.5-7-3m14 6v-3.87a3.37 3.37 0 0 0-.94-2.61c3.14-.35 6.44-1.54 6.44-7A5.44 5.44 0 0 0 20 4.77 5.07 5.07 0 0 0 19.91 1S18.73.65 16 2.48a13.38 13.38 0 0 0-7 0C6.27.65 5.09 1 5.09 1A5.07 5.07 0 0 0 5 4.77a5.44 5.44 0 0 0-1.5 3.78c0 5.42 3.3 6.61 6.44 7A3.37 3.37 0 0 0 9 18.13V22"></path></svg>'),
            80
        );
        
        // Dashboard submenu
        add_submenu_page(
            'wp-github-sync',
            __('Dashboard', 'wp-github-sync'),
            __('Dashboard', 'wp-github-sync'),
            'manage_options',
            'wp-github-sync',
            array($this, 'display_admin_dashboard')
        );
        
        // History submenu
        add_submenu_page(
            'wp-github-sync',
            __('Deployment History', 'wp-github-sync'),
            __('Deployment History', 'wp-github-sync'),
            'manage_options',
            'wp-github-sync-history',
            array($this, 'display_admin_history')
        );
        
        // Settings submenu
        add_submenu_page(
            'wp-github-sync',
            __('Settings', 'wp-github-sync'),
            __('Settings', 'wp-github-sync'),
            'manage_options',
            'wp-github-sync-settings',
            array($this, 'display_admin_settings')
        );
    }

    /**
     * Add dashboard widget.
     */
    public function add_dashboard_widget() {
        wp_add_dashboard_widget(
            'wp_github_sync_dashboard_widget',
            __('GitHub Sync Status', 'wp-github-sync'),
            array($this, 'render_dashboard_widget')
        );
    }

    /**
     * Display any admin notices.
     */
    public function display_admin_notices() {
        // Check if there's an update available
        $update_available = get_option('wp_github_sync_update_available', false);
        
        if ($update_available && wp_github_sync_current_user_can()) {
            $latest_commit = get_option('wp_github_sync_latest_commit', array());
            
            $message = __('A new update is available from GitHub.', 'wp-github-sync');
            
            if (!empty($latest_commit) && isset($latest_commit['message'])) {
                $commit_message = wp_github_sync_format_commit_message($latest_commit['message']);
                $message .= ' ' . sprintf(__('Latest commit: %s', 'wp-github-sync'), $commit_message);
            }
            
            $deploy_url = wp_nonce_url(
                add_query_arg(
                    array(
                        'page' => 'wp-github-sync',
                        'action' => 'deploy',
                    ),
                    admin_url('admin.php')
                ),
                'wp_github_sync_deploy'
            );
            
            $message .= ' <a href="' . esc_url($deploy_url) . '" class="button button-primary">' . __('Deploy Now', 'wp-github-sync') . '</a>';
            
            echo '<div class="notice notice-info is-dismissible"><p>' . $message . '</p></div>';
        }
        
        // Check if credentials are missing
        $repository = get_option('wp_github_sync_repository', '');
        $auth_method = get_option('wp_github_sync_auth_method', 'pat');
        $token = '';
        
        if ($auth_method === 'pat') {
            $token = get_option('wp_github_sync_access_token', '');
        } elseif ($auth_method === 'oauth') {
            $token = get_option('wp_github_sync_oauth_token', '');
        }
        
        // Show warning if repository or token is missing
        if (empty($repository) || empty($token)) {
            $settings_url = admin_url('admin.php?page=wp-github-sync-settings');
            
            $message = __('GitHub Sync plugin is not fully configured.', 'wp-github-sync');
            
            if (empty($repository)) {
                $message .= ' ' . __('GitHub repository URL is missing.', 'wp-github-sync');
            }
            
            if (empty($token)) {
                $message .= ' ' . __('GitHub authentication credentials are missing.', 'wp-github-sync');
            }
            
            $message .= ' <a href="' . esc_url($settings_url) . '">' . __('Configure Now', 'wp-github-sync') . '</a>';
            
            echo '<div class="notice notice-warning is-dismissible"><p>' . $message . '</p></div>';
        }
    }

    /**
     * Add action links to the plugins page.
     *
     * @param array $links The existing action links.
     * @return array The modified action links.
     */
    public function add_action_links($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=wp-github-sync-settings') . '">' . __('Settings', 'wp-github-sync') . '</a>';
        array_unshift($links, $settings_link);
        
        return $links;
    }

    /**
     * Display the admin dashboard page.
     */
    public function display_admin_dashboard() {
        // Handle direct actions like deploy, switch branch, rollback
        $this->handle_admin_actions();
        
        // Get current repository info
        $repository_url = get_option('wp_github_sync_repository', '');
        $branch = wp_github_sync_get_current_branch();
        $last_deployed_commit = get_option('wp_github_sync_last_deployed_commit', '');
        $last_deployment_time = get_option('wp_github_sync_last_deployment_time', 0);
        $update_available = get_option('wp_github_sync_update_available', false);
        $deployment_in_progress = get_option('wp_github_sync_deployment_in_progress', false);
        
        // Get latest commit info if we have one
        $latest_commit_info = '';
        
        if (!empty($last_deployed_commit)) {
            $commit_details = $this->github_api->request("repos/{$this->github_api->owner}/{$this->github_api->repo}/commits/{$last_deployed_commit}");
            
            if (!is_wp_error($commit_details) && !empty($commit_details)) {
                $commit_message = isset($commit_details['commit']['message']) ? $commit_details['commit']['message'] : '';
                $commit_author = isset($commit_details['commit']['author']['name']) ? $commit_details['commit']['author']['name'] : '';
                $commit_date = isset($commit_details['commit']['author']['date']) ? $commit_details['commit']['author']['date'] : '';
                
                $latest_commit_info = array(
                    'sha' => $last_deployed_commit,
                    'message' => $commit_message,
                    'author' => $commit_author,
                    'date' => $commit_date,
                );
            }
        }
        
        // Get branches for switching
        $branches = array();
        
        if (!empty($repository_url)) {
            $branches_api = $this->github_api->get_branches();
            
            if (!is_wp_error($branches_api)) {
                foreach ($branches_api as $branch_data) {
                    if (isset($branch_data['name'])) {
                        $branches[] = $branch_data['name'];
                    }
                }
            }
        }
        
        // Get recent commits for rollback
        $recent_commits = array();
        
        if (!empty($repository_url)) {
            $commits_api = $this->github_api->get_commits($branch, 10);
            
            if (!is_wp_error($commits_api)) {
                foreach ($commits_api as $commit_data) {
                    if (isset($commit_data['sha'])) {
                        $recent_commits[] = array(
                            'sha' => $commit_data['sha'],
                            'message' => isset($commit_data['commit']['message']) ? $commit_data['commit']['message'] : '',
                            'author' => isset($commit_data['commit']['author']['name']) ? $commit_data['commit']['author']['name'] : '',
                            'date' => isset($commit_data['commit']['author']['date']) ? $commit_data['commit']['author']['date'] : '',
                        );
                    }
                }
            }
        }
        
        // Include dashboard template
        include WP_GITHUB_SYNC_DIR . 'admin/templates/dashboard-page.php';
    }

    /**
     * Display the admin history page.
     */
    public function display_admin_history() {
        // Get deployment history
        $history = get_option('wp_github_sync_deployment_history', array());
        
        // Include history template
        include WP_GITHUB_SYNC_DIR . 'admin/templates/history-page.php';
    }

    /**
     * Display the admin settings page.
     */
    public function display_admin_settings() {
        include WP_GITHUB_SYNC_DIR . 'admin/templates/settings-page.php';
    }

    /**
     * Render the dashboard widget.
     */
    public function render_dashboard_widget() {
        $repository_url = get_option('wp_github_sync_repository', '');
        $branch = wp_github_sync_get_current_branch();
        $last_deployed_commit = get_option('wp_github_sync_last_deployed_commit', '');
        $last_deployment_time = get_option('wp_github_sync_last_deployment_time', 0);
        $update_available = get_option('wp_github_sync_update_available', false);
        
        // Check if plugin is configured
        if (empty($repository_url)) {
            ?>
            <div class="wp-github-sync-widget-info-box" style="background-color: rgba(219, 166, 23, 0.1); border-left: 4px solid #dba617; padding: 12px; margin-bottom: 10px; display: flex; align-items: center; gap: 10px;">
                <span class="dashicons dashicons-warning" style="color: #dba617;"></span>
                <div>
                    <p style="margin: 0 0 8px 0; font-weight: 600;"><?php _e('GitHub Sync is not configured yet', 'wp-github-sync'); ?></p>
                    <a href="<?php echo admin_url('admin.php?page=wp-github-sync-settings'); ?>" class="button" style="display: inline-flex; align-items: center; gap: 5px;">
                        <span class="dashicons dashicons-admin-tools" style="font-size: 16px; width: 16px; height: 16px;"></span>
                        <?php _e('Configure Now', 'wp-github-sync'); ?>
                    </a>
                </div>
            </div>
            <?php
            return;
        }
        
        // Parse repository URL to get owner/repo format
        $github_api = new GitHub_API_Client();
        $parsed_url = $github_api->parse_github_url($repository_url);
        $repo_display = $parsed_url ? $parsed_url['owner'] . '/' . $parsed_url['repo'] : $repository_url;
        
        ?>
        <div class="wp-github-sync-dashboard-widget" style="font-size: 13px;">
            <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 15px;">
                <div style="flex: 1; min-width: 200px;">
                    <div style="display: flex; margin-bottom: 8px; align-items: center;">
                        <span style="width: 20px; margin-right: 5px;"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 19c-5 1.5-5-2.5-7-3m14 6v-3.87a3.37 3.37 0 0 0-.94-2.61c3.14-.35 6.44-1.54 6.44-7A5.44 5.44 0 0 0 20 4.77 5.07 5.07 0 0 0 19.91 1S18.73.65 16 2.48a13.38 13.38 0 0 0-7 0C6.27.65 5.09 1 5.09 1A5.07 5.07 0 0 0 5 4.77a5.44 5.44 0 0 0-1.5 3.78c0 5.42 3.3 6.61 6.44 7A3.37 3.37 0 0 0 9 18.13V22"></path></svg></span>
                        <strong><?php _e('Repository:', 'wp-github-sync'); ?></strong>
                        <a href="<?php echo esc_url($repository_url); ?>" target="_blank" style="margin-left: 5px;"><?php echo esc_html($repo_display); ?></a>
                    </div>
                    
                    <div style="display: flex; margin-bottom: 8px; align-items: center;">
                        <span style="width: 20px; margin-right: 5px;"><span class="dashicons dashicons-randomize" style="font-size: 16px; width: 16px; height: 16px;"></span></span>
                        <strong><?php _e('Branch:', 'wp-github-sync'); ?></strong>
                        <span style="margin-left: 5px;"><?php echo esc_html($branch); ?></span>
                    </div>
                    
                    <?php if (!empty($last_deployed_commit)) : ?>
                        <div style="display: flex; margin-bottom: 8px; align-items: center;">
                            <span style="width: 20px; margin-right: 5px;"><span class="dashicons dashicons-backup" style="font-size: 16px; width: 16px; height: 16px;"></span></span>
                            <strong><?php _e('Current commit:', 'wp-github-sync'); ?></strong>
                            <code style="margin-left: 5px; background: #f6f7f7; padding: 2px 4px; border-radius: 3px; font-size: 12px;"><?php echo esc_html(substr($last_deployed_commit, 0, 8)); ?></code>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div style="flex: 1; min-width: 200px;">
                    <?php if (!empty($last_deployment_time)) : ?>
                        <div style="display: flex; margin-bottom: 8px; align-items: center;">
                            <span style="width: 20px; margin-right: 5px;"><span class="dashicons dashicons-clock" style="font-size: 16px; width: 16px; height: 16px;"></span></span>
                            <strong><?php _e('Last updated:', 'wp-github-sync'); ?></strong>
                            <span style="margin-left: 5px;"><?php echo wp_github_sync_time_diff($last_deployment_time); ?> <?php _e('ago', 'wp-github-sync'); ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <div style="display: flex; align-items: center; margin-bottom: 8px;">
                        <span style="width: 20px; margin-right: 5px;"><span class="dashicons dashicons-info" style="font-size: 16px; width: 16px; height: 16px;"></span></span>
                        <strong><?php _e('Status:', 'wp-github-sync'); ?></strong>
                        <span style="margin-left: 5px;">
                            <?php if ($update_available) : ?>
                                <span style="color: #dba617; display: flex; align-items: center; gap: 4px;">
                                    <span class="dashicons dashicons-warning" style="font-size: 16px; width: 16px; height: 16px;"></span>
                                    <?php _e('Update available', 'wp-github-sync'); ?>
                                </span>
                            <?php else : ?>
                                <span style="color: #00a32a; display: flex; align-items: center; gap: 4px;">
                                    <span class="dashicons dashicons-yes-alt" style="font-size: 16px; width: 16px; height: 16px;"></span>
                                    <?php _e('Up to date', 'wp-github-sync'); ?>
                                </span>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
            </div>
            
            <div style="padding-top: 12px; border-top: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                <?php if ($update_available) : ?>
                    <?php
                    $deploy_url = wp_nonce_url(
                        add_query_arg(
                            array(
                                'page' => 'wp-github-sync',
                                'action' => 'deploy',
                            ),
                            admin_url('admin.php')
                        ),
                        'wp_github_sync_deploy'
                    );
                    ?>
                    
                    <a href="<?php echo esc_url($deploy_url); ?>" class="button button-primary" style="display: inline-flex; align-items: center; gap: 5px;">
                        <span class="dashicons dashicons-cloud-upload" style="font-size: 16px; width: 16px; height: 16px;"></span>
                        <?php _e('Deploy Now', 'wp-github-sync'); ?>
                    </a>
                <?php endif; ?>
                
                <a href="<?php echo admin_url('admin.php?page=wp-github-sync'); ?>" class="button" style="display: inline-flex; align-items: center; gap: 5px;">
                    <span class="dashicons dashicons-dashboard" style="font-size: 16px; width: 16px; height: 16px;"></span>
                    <?php _e('View Dashboard', 'wp-github-sync'); ?>
                </a>
            </div>
        </div>
        <?php
    }

    /**
     * Handle admin action requests.
     */
    private function handle_admin_actions() {
        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';
        
        if (empty($action)) {
            return;
        }
        
        if (!wp_github_sync_current_user_can()) {
            wp_die(__('You do not have sufficient permissions to perform this action.', 'wp-github-sync'));
        }
        
        // Handle different actions
        switch ($action) {
            case 'deploy':
                if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'wp_github_sync_deploy')) {
                    wp_die(__('Security check failed. Please try again.', 'wp-github-sync'));
                }
                
                $branch = wp_github_sync_get_current_branch();
                $result = $this->sync_manager->deploy($branch);
                
                if (is_wp_error($result)) {
                    add_settings_error(
                        'wp_github_sync',
                        'deploy_failed',
                        sprintf(__('Deployment failed: %s', 'wp-github-sync'), $result->get_error_message()),
                        'error'
                    );
                } else {
                    add_settings_error(
                        'wp_github_sync',
                        'deploy_success',
                        __('Deployment completed successfully.', 'wp-github-sync'),
                        'success'
                    );
                }
                break;
                
            case 'switch_branch':
                if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'wp_github_sync_switch_branch')) {
                    wp_die(__('Security check failed. Please try again.', 'wp-github-sync'));
                }
                
                if (!isset($_GET['branch']) || empty($_GET['branch'])) {
                    wp_die(__('No branch specified.', 'wp-github-sync'));
                }
                
                $branch = sanitize_text_field($_GET['branch']);
                $result = $this->sync_manager->switch_branch($branch);
                
                if (is_wp_error($result)) {
                    add_settings_error(
                        'wp_github_sync',
                        'switch_branch_failed',
                        sprintf(__('Branch switch failed: %s', 'wp-github-sync'), $result->get_error_message()),
                        'error'
                    );
                } else {
                    add_settings_error(
                        'wp_github_sync',
                        'switch_branch_success',
                        sprintf(__('Successfully switched to branch: %s', 'wp-github-sync'), $branch),
                        'success'
                    );
                }
                break;
                
            case 'rollback':
                if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'wp_github_sync_rollback')) {
                    wp_die(__('Security check failed. Please try again.', 'wp-github-sync'));
                }
                
                if (!isset($_GET['commit']) || empty($_GET['commit'])) {
                    wp_die(__('No commit specified.', 'wp-github-sync'));
                }
                
                $commit = sanitize_text_field($_GET['commit']);
                $result = $this->sync_manager->rollback($commit);
                
                if (is_wp_error($result)) {
                    add_settings_error(
                        'wp_github_sync',
                        'rollback_failed',
                        sprintf(__('Rollback failed: %s', 'wp-github-sync'), $result->get_error_message()),
                        'error'
                    );
                } else {
                    add_settings_error(
                        'wp_github_sync',
                        'rollback_success',
                        sprintf(__('Successfully rolled back to commit: %s', 'wp-github-sync'), substr($commit, 0, 8)),
                        'success'
                    );
                }
                break;
        }
    }

    /**
     * Handle AJAX deploy request.
     */
    public function handle_ajax_deploy() {
        // Check permissions
        if (!wp_github_sync_current_user_can()) {
            wp_send_json_error(array('message' => __('You do not have sufficient permissions to perform this action.', 'wp-github-sync')));
        }
        
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wp_github_sync_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed. Please refresh the page and try again.', 'wp-github-sync')));
        }
        
        // Get current branch
        $branch = wp_github_sync_get_current_branch();
        
        // Deploy
        $result = $this->sync_manager->deploy($branch);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        } else {
            wp_send_json_success(array('message' => __('Deployment completed successfully.', 'wp-github-sync')));
        }
    }

    /**
     * Handle AJAX switch branch request.
     */
    public function handle_ajax_switch_branch() {
        // Check permissions
        if (!wp_github_sync_current_user_can()) {
            wp_send_json_error(array('message' => __('You do not have sufficient permissions to perform this action.', 'wp-github-sync')));
        }
        
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wp_github_sync_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed. Please refresh the page and try again.', 'wp-github-sync')));
        }
        
        // Check if branch is provided
        if (!isset($_POST['branch']) || empty($_POST['branch'])) {
            wp_send_json_error(array('message' => __('No branch specified.', 'wp-github-sync')));
        }
        
        $branch = sanitize_text_field($_POST['branch']);
        
        // Switch branch
        $result = $this->sync_manager->switch_branch($branch);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        } else {
            wp_send_json_success(array(
                'message' => sprintf(__('Successfully switched to branch: %s', 'wp-github-sync'), $branch),
                'branch' => $branch,
            ));
        }
    }

    /**
     * Handle AJAX rollback request.
     */
    public function handle_ajax_rollback() {
        // Check permissions
        if (!wp_github_sync_current_user_can()) {
            wp_send_json_error(array('message' => __('You do not have sufficient permissions to perform this action.', 'wp-github-sync')));
        }
        
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wp_github_sync_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed. Please refresh the page and try again.', 'wp-github-sync')));
        }
        
        // Check if commit is provided
        if (!isset($_POST['commit']) || empty($_POST['commit'])) {
            wp_send_json_error(array('message' => __('No commit specified.', 'wp-github-sync')));
        }
        
        $commit = sanitize_text_field($_POST['commit']);
        
        // Rollback
        $result = $this->sync_manager->rollback($commit);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        } else {
            wp_send_json_success(array(
                'message' => sprintf(__('Successfully rolled back to commit: %s', 'wp-github-sync'), substr($commit, 0, 8)),
                'commit' => $commit,
            ));
        }
    }

    /**
     * Handle AJAX refresh branches request.
     */
    public function handle_ajax_refresh_branches() {
        // Check permissions
        if (!wp_github_sync_current_user_can()) {
            wp_send_json_error(array('message' => __('You do not have sufficient permissions to perform this action.', 'wp-github-sync')));
        }
        
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wp_github_sync_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed. Please refresh the page and try again.', 'wp-github-sync')));
        }
        
        // Refresh GitHub API client
        $this->github_api->initialize();
        
        // Get branches
        $branches = array();
        $branches_api = $this->github_api->get_branches();
        
        if (is_wp_error($branches_api)) {
            wp_send_json_error(array('message' => $branches_api->get_error_message()));
        } else {
            foreach ($branches_api as $branch_data) {
                if (isset($branch_data['name'])) {
                    $branches[] = $branch_data['name'];
                }
            }
            
            wp_send_json_success(array('branches' => $branches));
        }
    }

    /**
     * Handle AJAX regenerate webhook secret request.
     */
    public function handle_ajax_regenerate_webhook() {
        // Check permissions
        if (!wp_github_sync_current_user_can()) {
            wp_send_json_error(array('message' => __('You do not have sufficient permissions to perform this action.', 'wp-github-sync')));
        }
        
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wp_github_sync_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed. Please refresh the page and try again.', 'wp-github-sync')));
        }
        
        // Generate new webhook secret
        $new_secret = wp_github_sync_generate_webhook_secret();
        update_option('wp_github_sync_webhook_secret', $new_secret);
        
        wp_send_json_success(array(
            'message' => __('Webhook secret regenerated successfully.', 'wp-github-sync'),
            'secret' => $new_secret,
        ));
    }

    /**
     * Handle AJAX OAuth connect request.
     */
    public function handle_ajax_oauth_connect() {
        // Check permissions
        if (!wp_github_sync_current_user_can()) {
            wp_send_json_error(array('message' => __('You do not have sufficient permissions to perform this action.', 'wp-github-sync')));
        }
        
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wp_github_sync_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed. Please refresh the page and try again.', 'wp-github-sync')));
        }
        
        // Here we would generate a GitHub OAuth URL and redirect the user
        // For simplicity, we're just returning the URL that should be opened in a new window/tab
        
        // In a real implementation, you would have registered a GitHub OAuth App
        $client_id = defined('WP_GITHUB_SYNC_OAUTH_CLIENT_ID') ? WP_GITHUB_SYNC_OAUTH_CLIENT_ID : '';
        
        if (empty($client_id)) {
            wp_send_json_error(array('message' => __('GitHub OAuth Client ID is not configured. Please define WP_GITHUB_SYNC_OAUTH_CLIENT_ID in your wp-config.php file.', 'wp-github-sync')));
        }
        
        // Generate a state value for security
        $state = wp_generate_password(24, false);
        update_option('wp_github_sync_oauth_state', $state);
        
        // Generate the auth URL
        $redirect_uri = admin_url('admin.php?page=wp-github-sync-settings&github_oauth_callback=1');
        $oauth_url = add_query_arg(
            array(
                'client_id' => $client_id,
                'redirect_uri' => urlencode($redirect_uri),
                'scope' => 'repo',
                'state' => $state,
            ),
            'https://github.com/login/oauth/authorize'
        );
        
        wp_send_json_success(array('oauth_url' => $oauth_url));
    }

    /**
     * Handle AJAX OAuth disconnect request.
     */
    public function handle_ajax_oauth_disconnect() {
        // Check permissions
        if (!wp_github_sync_current_user_can()) {
            wp_send_json_error(array('message' => __('You do not have sufficient permissions to perform this action.', 'wp-github-sync')));
        }
        
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wp_github_sync_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed. Please refresh the page and try again.', 'wp-github-sync')));
        }
        
        // Delete the stored OAuth token
        delete_option('wp_github_sync_oauth_token');
        
        wp_send_json_success(array('message' => __('Successfully disconnected from GitHub.', 'wp-github-sync')));
    }

    /**
     * Handle AJAX initial sync request.
     */
    public function handle_ajax_initial_sync() {
        // Check permissions
        if (!wp_github_sync_current_user_can()) {
            wp_send_json_error(array('message' => __('You do not have sufficient permissions to perform this action.', 'wp-github-sync')));
        }
        
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wp_github_sync_initial_sync')) {
            wp_send_json_error(array('message' => __('Security check failed. Please refresh the page and try again.', 'wp-github-sync')));
        }
        
        // Check if we should create a new repository
        $create_new_repo = isset($_POST['create_new_repo']) && $_POST['create_new_repo'] == 1;
        $repo_name = isset($_POST['repo_name']) ? sanitize_text_field($_POST['repo_name']) : '';
        
        // Make sure GitHub API is initialized with the latest settings
        $this->github_api->initialize();
        
        // If creating a new repository
        if ($create_new_repo) {
            if (empty($repo_name)) {
                // Generate default repo name based on site URL
                $site_url = parse_url(get_site_url(), PHP_URL_HOST);
                $repo_name = sanitize_title(str_replace('.', '-', $site_url));
            }
            
            // Create description based on site name
            $site_name = get_bloginfo('name');
            $description = sprintf(__('WordPress site: %s', 'wp-github-sync'), $site_name);
            
            // Create the repository
            $result = $this->github_api->create_repository($repo_name, $description);
            
            if (is_wp_error($result)) {
                wp_send_json_error(array('message' => sprintf(__('Failed to create repository: %s', 'wp-github-sync'), $result->get_error_message())));
                return;
            }
            
            // Get the repository URL and owner/repo details
            if (isset($result['html_url'])) {
                $repo_url = $result['html_url'];
                $repo_owner = isset($result['owner']['login']) ? $result['owner']['login'] : '';
                $repo_name = isset($result['name']) ? $result['name'] : '';
                
                // Save repository URL to settings
                update_option('wp_github_sync_repository', $repo_url);
                
                // Try initial sync for a new repository
                $sync_result = $this->github_api->initial_sync();
                
                if (is_wp_error($sync_result)) {
                    // Even if sync fails, we created the repo, so consider it successful
                    wp_send_json_success(array(
                        'message' => sprintf(
                            __('Repository created successfully at %s. However, initial file sync failed: %s', 'wp-github-sync'),
                            $repo_url,
                            $sync_result->get_error_message()
                        ),
                        'repo_url' => $repo_url,
                    ));
                    return;
                }
                
                // Set deployed branch and mark first deployment
                update_option('wp_github_sync_branch', 'main');
                update_option('wp_github_sync_last_deployment_time', time());
                
                wp_send_json_success(array(
                    'message' => sprintf(__('Repository created and initialized successfully at %s', 'wp-github-sync'), $repo_url),
                    'repo_url' => $repo_url,
                ));
                return;
            } else {
                wp_send_json_error(array('message' => __('Repository created, but the response did not include the repository URL.', 'wp-github-sync')));
                return;
            }
        } else {
            // Using existing repository - perform initial deployment
            $repo_url = get_option('wp_github_sync_repository', '');
            
            if (empty($repo_url)) {
                wp_send_json_error(array('message' => __('No repository URL configured. Please enter a repository URL in the settings.', 'wp-github-sync')));
                return;
            }
            
            // Get the branch
            $branch = get_option('wp_github_sync_branch', 'main');
            
            // Perform the deployment
            $result = $this->sync_manager->deploy($branch);
            
            if (is_wp_error($result)) {
                wp_send_json_error(array('message' => sprintf(__('Initial deployment failed: %s', 'wp-github-sync'), $result->get_error_message())));
            } else {
                wp_send_json_success(array('message' => __('Initial deployment completed successfully.', 'wp-github-sync')));
            }
        }
    }

    /**
     * Handle AJAX full sync request.
     */
    public function handle_ajax_full_sync() {
        // Check permissions
        if (!wp_github_sync_current_user_can()) {
            wp_github_sync_log("Permission denied for full sync", 'error');
            wp_send_json_error(array('message' => __('You do not have sufficient permissions to perform this action.', 'wp-github-sync')));
            return;
        }
        
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wp_github_sync_nonce')) {
            wp_github_sync_log("Invalid nonce for full sync", 'error');
            wp_send_json_error(array('message' => __('Security check failed. Please refresh the page and try again.', 'wp-github-sync')));
            return;
        }
        
        // Check if sync is already in progress
        if (get_option('wp_github_sync_sync_in_progress', false)) {
            wp_github_sync_log("Sync already in progress, cannot start another", 'warning');
            wp_send_json_error(array('message' => __('A sync operation is already in progress. Please wait for it to complete.', 'wp-github-sync')));
            return;
        }
        
        // Ensure GitHub API is initialized
        $this->github_api->initialize();
        
        // Get repository URL and check if it exists
        $repo_url = get_option('wp_github_sync_repository', '');
        
        if (empty($repo_url)) {
            wp_send_json_error(array('message' => __('No repository URL configured. Please enter a repository URL in the settings.', 'wp-github-sync')));
            return;
        }
        
        // First test if authentication is working
        $auth_test = $this->github_api->test_authentication();
        if ($auth_test !== true) {
            wp_send_json_error(array('message' => sprintf(__('Authentication error: %s', 'wp-github-sync'), $auth_test)));
            return;
        }
        
        // Check if repository exists
        $repo_exists = $this->github_api->repository_exists();
        
        if (!$repo_exists) {
            wp_send_json_error(array('message' => __('Repository does not exist or is not accessible with current credentials.', 'wp-github-sync')));
            return;
        }
        
        // Perform the full sync to GitHub
        $branch = wp_github_sync_get_current_branch();
        
        // Show a message that sync is starting
        wp_github_sync_log("Starting full sync to GitHub for branch: {$branch}", 'info');
        
        // Set a flag that sync is in progress
        update_option('wp_github_sync_sync_in_progress', true);
        
        // Execute the initial sync operation
        $result = $this->github_api->initial_sync($branch);
        
        // Clear the in-progress flag
        delete_option('wp_github_sync_sync_in_progress');
        
        if (is_wp_error($result)) {
            // Log the error details
            wp_github_sync_log("Full sync to GitHub failed: " . $result->get_error_message(), 'error');
            
            // Return error to the user
            wp_send_json_error(array(
                'message' => sprintf(__('Full sync to GitHub failed: %s', 'wp-github-sync'), $result->get_error_message())
            ));
        } else {
            // Update the sync time
            update_option('wp_github_sync_last_deployment_time', time());
            
            // Log successful sync
            wp_github_sync_log("Full sync to GitHub completed successfully", 'info');
            
            // Return success message
            wp_send_json_success(array(
                'message' => __('All WordPress files have been successfully synced to GitHub!', 'wp-github-sync')
            ));
        }
    }

    /**
     * Handle AJAX test connection request.
     */
    public function handle_ajax_test_connection() {
        // Check permissions
        if (!wp_github_sync_current_user_can()) {
            wp_send_json_error(array('message' => __('You do not have sufficient permissions to perform this action.', 'wp-github-sync')));
        }
        
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wp_github_sync_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed. Please refresh the page and try again.', 'wp-github-sync')));
        }
        
        // Check if a temporary token was provided for testing
        $temp_token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';
        
        // Create a temporary API client for testing
        $github_api = new GitHub_API_Client();
        
        // If a temporary token was provided, use it instead of the stored one
        if (!empty($temp_token)) {
            // Set the token directly on the API client (bypassing encryption)
            $github_api->set_temporary_token($temp_token);
        }
        
        // Test authentication
        $auth_test = $github_api->test_authentication();
        
        if ($auth_test === true) {
            // Authentication succeeded, now check repo if provided
            $repo_url = isset($_POST['repo_url']) ? esc_url_raw($_POST['repo_url']) : get_option('wp_github_sync_repository', '');
            
            if (!empty($repo_url)) {
                // Parse the repo URL
                $parsed_url = $github_api->parse_github_url($repo_url);
                
                if ($parsed_url) {
                    // Check if repo exists and is accessible
                    $repo_exists = $github_api->repository_exists($parsed_url['owner'], $parsed_url['repo']);
                    
                    if ($repo_exists) {
                        // Success! Authentication and repo are valid
                        wp_send_json_success(array(
                            'message' => __('Success! Your GitHub credentials and repository are valid.', 'wp-github-sync'),
                            'username' => $github_api->get_user_login(),
                            'repo_info' => array(
                                'owner' => $parsed_url['owner'],
                                'repo' => $parsed_url['repo']
                            )
                        ));
                    } else {
                        // Authentication worked but repo isn't accessible
                        wp_send_json_error(array(
                            'message' => __('Authentication successful, but the repository could not be accessed. Please check your repository URL and ensure your token has access to it.', 'wp-github-sync'),
                            'username' => $github_api->get_user_login(),
                            'auth_ok' => true,
                            'repo_error' => true
                        ));
                    }
                } else {
                    // Authentication worked but repo URL is invalid
                    wp_send_json_error(array(
                        'message' => __('Authentication successful, but the repository URL is invalid. Please provide a valid GitHub repository URL.', 'wp-github-sync'),
                        'username' => $github_api->get_user_login(),
                        'auth_ok' => true,
                        'url_error' => true
                    ));
                }
            } else {
                // Authentication worked but no repo was provided
                wp_send_json_success(array(
                    'message' => __('Authentication successful! Your GitHub credentials are valid.', 'wp-github-sync'),
                    'username' => $github_api->get_user_login(),
                    'auth_ok' => true,
                    'no_repo' => true
                ));
            }
        } else {
            // Authentication failed
            wp_send_json_error(array(
                'message' => sprintf(__('Authentication failed: %s', 'wp-github-sync'), $auth_test),
                'auth_error' => true
            ));
        }
    }

    public function handle_oauth_callback() {
        // Check if this is an OAuth callback
        if (!isset($_GET['github_oauth_callback']) || $_GET['github_oauth_callback'] != 1) {
            return;
        }
        
        // Check if we have a code and state
        if (!isset($_GET['code']) || !isset($_GET['state'])) {
            add_settings_error(
                'wp_github_sync',
                'oauth_failed',
                __('GitHub OAuth authentication failed. Missing code or state.', 'wp-github-sync'),
                'error'
            );
            return;
        }
        
        // Verify state to prevent CSRF
        $stored_state = get_option('wp_github_sync_oauth_state', '');
        
        if (empty($stored_state) || $_GET['state'] !== $stored_state) {
            add_settings_error(
                'wp_github_sync',
                'oauth_failed',
                __('GitHub OAuth authentication failed. Invalid state.', 'wp-github-sync'),
                'error'
            );
            return;
        }
        
        // Clear the state
        delete_option('wp_github_sync_oauth_state');
        
        // Exchange code for access token
        $client_id = defined('WP_GITHUB_SYNC_OAUTH_CLIENT_ID') ? WP_GITHUB_SYNC_OAUTH_CLIENT_ID : '';
        $client_secret = defined('WP_GITHUB_SYNC_OAUTH_CLIENT_SECRET') ? WP_GITHUB_SYNC_OAUTH_CLIENT_SECRET : '';
        
        if (empty($client_id) || empty($client_secret)) {
            add_settings_error(
                'wp_github_sync',
                'oauth_failed',
                __('GitHub OAuth authentication failed. Client ID or Client Secret is not configured.', 'wp-github-sync'),
                'error'
            );
            return;
        }
        
        $response = wp_remote_post('https://github.com/login/oauth/access_token', array(
            'body' => array(
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'code' => $_GET['code'],
                'redirect_uri' => admin_url('admin.php?page=wp-github-sync-settings&github_oauth_callback=1'),
            ),
            'headers' => array(
                'Accept' => 'application/json',
            ),
        ));
        
        if (is_wp_error($response)) {
            add_settings_error(
                'wp_github_sync',
                'oauth_failed',
                sprintf(__('GitHub OAuth authentication failed. Error: %s', 'wp-github-sync'), $response->get_error_message()),
                'error'
            );
            return;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!isset($body['access_token'])) {
            add_settings_error(
                'wp_github_sync',
                'oauth_failed',
                __('GitHub OAuth authentication failed. No access token received.', 'wp-github-sync'),
                'error'
            );
            return;
        }
        
        // Store the token
        $encrypted_token = wp_github_sync_encrypt($body['access_token']);
        update_option('wp_github_sync_oauth_token', $encrypted_token);
        update_option('wp_github_sync_auth_method', 'oauth');
        
        add_settings_error(
            'wp_github_sync',
            'oauth_success',
            __('Successfully connected to GitHub using OAuth.', 'wp-github-sync'),
            'success'
        );
    }
}