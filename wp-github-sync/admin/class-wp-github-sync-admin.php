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

    /**
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
                'nonce' => wp_create_nonce('wp_github_sync_nonce'),
                'strings' => array(
                    'confirmDeploy' => __('Are you sure you want to deploy the latest changes from GitHub? This will update your site files.', 'wp-github-sync'),
                    'confirmSwitchBranch' => __('Are you sure you want to switch branches? This will update your site files to match the selected branch.', 'wp-github-sync'),
                    'confirmRollback' => __('Are you sure you want to roll back to this commit? This will revert your site files to an earlier state.', 'wp-github-sync'),
                    'confirmRegenerateWebhook' => __('Are you sure you want to regenerate the webhook secret? You will need to update it in your GitHub repository settings.', 'wp-github-sync'),
                    'success' => __('Operation completed successfully.', 'wp-github-sync'),
                    'error' => __('An error occurred. Please try again.', 'wp-github-sync'),
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
            'dashicons-cloud',
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
            <p><?php _e('GitHub Sync is not configured yet.', 'wp-github-sync'); ?></p>
            <a href="<?php echo admin_url('admin.php?page=wp-github-sync-settings'); ?>" class="button"><?php _e('Configure Now', 'wp-github-sync'); ?></a>
            <?php
            return;
        }
        
        // Parse repository URL to get owner/repo format
        $github_api = new GitHub_API_Client();
        $parsed_url = $github_api->parse_github_url($repository_url);
        $repo_display = $parsed_url ? $parsed_url['owner'] . '/' . $parsed_url['repo'] : $repository_url;
        
        ?>
        <div class="wp-github-sync-dashboard-widget">
            <p>
                <strong><?php _e('Repository:', 'wp-github-sync'); ?></strong>
                <a href="<?php echo esc_url($repository_url); ?>" target="_blank"><?php echo esc_html($repo_display); ?></a>
            </p>
            
            <p>
                <strong><?php _e('Branch:', 'wp-github-sync'); ?></strong>
                <?php echo esc_html($branch); ?>
            </p>
            
            <?php if (!empty($last_deployed_commit)) : ?>
                <p>
                    <strong><?php _e('Current commit:', 'wp-github-sync'); ?></strong>
                    <?php echo esc_html(substr($last_deployed_commit, 0, 8)); ?>
                </p>
            <?php endif; ?>
            
            <?php if (!empty($last_deployment_time)) : ?>
                <p>
                    <strong><?php _e('Last updated:', 'wp-github-sync'); ?></strong>
                    <?php echo wp_github_sync_time_diff($last_deployment_time); ?> <?php _e('ago', 'wp-github-sync'); ?>
                </p>
            <?php endif; ?>
            
            <div class="wp-github-sync-widget-actions">
                <?php if ($update_available) : ?>
                    <p class="wp-github-sync-update-notice">
                        <?php _e('Update available', 'wp-github-sync'); ?>
                    </p>
                    
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
                    
                    <a href="<?php echo esc_url($deploy_url); ?>" class="button button-primary"><?php _e('Deploy Now', 'wp-github-sync'); ?></a>
                <?php else : ?>
                    <p class="wp-github-sync-up-to-date">
                        <?php _e('Site is up to date with GitHub', 'wp-github-sync'); ?>
                    </p>
                <?php endif; ?>
                
                <a href="<?php echo admin_url('admin.php?page=wp-github-sync'); ?>" class="button"><?php _e('View Details', 'wp-github-sync'); ?></a>
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
     * Handle OAuth callback.
     */
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