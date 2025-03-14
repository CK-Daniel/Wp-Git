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
     * GitHub API client instance.
     *
     * @var \WPGitHubSync\API\API_Client
     */
    private $github_api;

    /**
     * Git Sync Manager instance.
     *
     * @var \WPGitHubSync\Sync\Sync_Manager
     */
    private $sync_manager;

    /**
     * Initialize the class and set its properties.
     *
     * @param string $version The version of this plugin.
     */
    public function __construct($version) {
        $this->version = $version;
        $this->github_api = new \WPGitHubSync\API\API_Client();
        $this->sync_manager = new \WPGitHubSync\Sync\Sync_Manager($this->github_api);
        
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
        add_action('wp_ajax_wp_github_sync_log_error', array($this, 'handle_ajax_log_error'));
        
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
            false
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
                'strings' => array(
                    'confirmDeploy' => __('Are you sure you want to deploy the latest changes from GitHub? This will update your site files.', 'wp-github-sync'),
                    'confirmSwitchBranch' => __('Are you sure you want to switch branches? This will update your site files to match the selected branch.', 'wp-github-sync'),
                    'confirmRollback' => __('Are you sure you want to roll back to this commit? This will revert your site files to an earlier state.', 'wp-github-sync'),
                    'confirmRegenerateWebhook' => __('Are you sure you want to regenerate the webhook secret? You will need to update it in your GitHub repository settings.', 'wp-github-sync'),
                    'confirmFullSync' => __('This will sync all your WordPress site files to GitHub. Continue?', 'wp-github-sync'),
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
                false
            );
        }
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
        
        // Logs submenu
        add_submenu_page(
            'wp-github-sync',
            __('Logs', 'wp-github-sync'),
            __('Logs', 'wp-github-sync'),
            'manage_options',
            'wp-github-sync-logs',
            array($this, 'display_logs_page')
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
        // Handle direct actions like deploy, switch branch, rollback
        $this->handle_admin_actions();
        
        // Get current repository info
        $repository_url = get_option('wp_github_sync_repository', '');
        $branch = wp_github_sync_get_current_branch();
        $last_deployed_commit = get_option('wp_github_sync_last_deployed_commit', '');
        $latest_commit_info = get_option('wp_github_sync_latest_commit', array());
        $update_available = get_option('wp_github_sync_update_available', false);
        $deployment_in_progress = get_option('wp_github_sync_deployment_in_progress', false);
        $last_deployment_time = !empty(get_option('wp_github_sync_deployment_history', array())) ? 
            max(array_column(get_option('wp_github_sync_deployment_history', array()), 'timestamp')) : 0;
        $branches = get_option('wp_github_sync_branches', array());
        $recent_commits = get_option('wp_github_sync_recent_commits', array());
        
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
        // Handle direct actions if any
        $this->handle_admin_actions();
        
        // Get deployment history
        $history = get_option('wp_github_sync_deployment_history', array());
        $repository_url = get_option('wp_github_sync_repository', '');
        
        // Sort history by date (newest first) and group by date
        $grouped_history = array();
        
        if (!empty($history)) {
            // Sort by timestamp (newest first)
            usort($history, function($a, $b) {
                return $b['timestamp'] <=> $a['timestamp'];
            });
            
            // Group by date
            foreach ($history as $deployment) {
                $date = date('Y-m-d', $deployment['timestamp']);
                if (!isset($grouped_history[$date])) {
                    $grouped_history[$date] = array();
                }
                $grouped_history[$date][] = $deployment;
            }
        }
        
        include WP_GITHUB_SYNC_DIR . 'admin/templates/history-page.php';
    }
    
    /**
     * Display the logs page.
     */
    public function display_logs_page() {
        // Check if wp_github_sync_log function exists
        if (!function_exists('wp_github_sync_log')) {
            wp_die(__('Required functions are missing. Please make sure the plugin is correctly installed.', 'wp-github-sync'));
        }
        
        // Verify user has permission
        if (!wp_github_sync_current_user_can()) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'wp-github-sync'));
        }
        
        // Handle log actions
        $this->handle_log_actions();
        
        // Get log file path
        $log_file = WP_CONTENT_DIR . '/wp-github-sync-debug.log';
        $logs = array();
        $log_file_size = 0;
        $log_level_filter = isset($_GET['level']) ? sanitize_text_field($_GET['level']) : '';
        $search_query = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
        
        // Validate log level filter if provided
        $valid_levels = array('debug', 'info', 'warning', 'error');
        if (!empty($log_level_filter) && !in_array($log_level_filter, $valid_levels)) {
            $log_level_filter = '';
        }
        
        // Check if log file exists and is readable
        if (file_exists($log_file) && is_readable($log_file)) {
            // Get file size
            $file_size = filesize($log_file);
            $log_file_size = size_format($file_size);
            
            // Check if file is too large to process entirely
            $max_size = apply_filters('wp_github_sync_max_log_size', 5 * 1024 * 1024); // 5MB default
            
            try {
                if ($file_size > $max_size) {
                    // For large files, read only the last portion
                    $log_content = $this->read_last_lines($log_file, 1000);
                    
                    // Add a notice that we're only showing partial logs
                    add_settings_error(
                        'wp_github_sync',
                        'logs_truncated',
                        sprintf(__('Log file is large (%s). Only showing the last 1000 entries.', 'wp-github-sync'), $log_file_size),
                        'info'
                    );
                } else {
                    // Read the entire log file
                    $log_content = file_get_contents($log_file);
                }
                
                // Parse log entries
                if (!empty($log_content)) {
                    $log_lines = explode(PHP_EOL, $log_content);
                    
                    // Process each log line
                    foreach ($log_lines as $line) {
                        if (empty(trim($line))) {
                            continue;
                        }
                        
                        // Parse log entry
                        // Format: [2023-01-01 12:00:00] [level] Message
                        if (preg_match('/\[(.*?)\] \[(.*?)\] (.*)/', $line, $matches)) {
                            $timestamp = $matches[1];
                            $level = strtolower($matches[2]);
                            $message = $matches[3];
                            
                            // Validate log level
                            if (!in_array($level, $valid_levels)) {
                                $level = 'info'; // Default to info for invalid levels
                            }
                            
                            // Apply filters
                            if (!empty($log_level_filter) && $level !== $log_level_filter) {
                                continue;
                            }
                            
                            if (!empty($search_query) && stripos($message, $search_query) === false) {
                                continue;
                            }
                            
                            // Add to logs array with sanitized values
                            $logs[] = array(
                                'timestamp' => $timestamp,
                                'level' => $level,
                                'message' => $message,
                            );
                        }
                    }
                    
                    // Reverse array to show newest logs first
                    $logs = array_reverse($logs);
                }
            } catch (Exception $e) {
                // Log the error and show a message
                error_log('WP GitHub Sync: Error reading log file - ' . $e->getMessage());
                add_settings_error(
                    'wp_github_sync',
                    'logs_error',
                    __('Error reading log file. Check PHP error log for details.', 'wp-github-sync'),
                    'error'
                );
            }
        }
        
        include WP_GITHUB_SYNC_DIR . 'admin/templates/logs-page.php';
    }
    
    /**
     * Read the last N lines of a file.
     * 
     * @param string $file_path Path to the file
     * @param int    $lines     Number of lines to read from end
     * @return string The last N lines of the file
     */
    private function read_last_lines($file_path, $lines = 100) {
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return '';
        }
        
        // Try to use SplFileObject which is more efficient
        try {
            $file = new \SplFileObject($file_path, 'r');
            $file->seek(PHP_INT_MAX);
            $total_lines = $file->key();
            
            // Calculate starting position
            $start = max(0, $total_lines - $lines);
            
            // Read the desired lines
            $file->seek($start);
            $content = '';
            
            while (!$file->eof()) {
                $content .= $file->fgets();
            }
            
            return $content;
        } catch (Exception $e) {
            // Fallback to a simpler implementation
            $content = file_get_contents($file_path);
            $content_lines = explode(PHP_EOL, $content);
            $content_lines = array_slice($content_lines, -$lines);
            return implode(PHP_EOL, $content_lines);
        }
    }
    
    /**
     * Handle log-related actions.
     */
    private function handle_log_actions() {
        if (!isset($_GET['action'])) {
            return;
        }
        
        // Check permissions
        if (!wp_github_sync_current_user_can()) {
            wp_die(__('You do not have sufficient permissions to perform this action.', 'wp-github-sync'));
        }
        
        $action = sanitize_text_field($_GET['action']);
        $log_file = WP_CONTENT_DIR . '/wp-github-sync-debug.log';
        
        // Verify the log file path is within WordPress content directory
        if (!$this->is_path_in_wp_content($log_file)) {
            wp_die(__('Invalid log file path.', 'wp-github-sync'));
        }
        
        switch ($action) {
            case 'clear_logs':
                // Verify nonce
                if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'wp_github_sync_clear_logs')) {
                    wp_die(__('Security check failed. Please try again.', 'wp-github-sync'));
                }
                
                try {
                    // Check if the log file exists
                    if (file_exists($log_file)) {
                        // Check if the file is writable
                        if (!is_writable($log_file)) {
                            // Try to make it writable
                            if (!@chmod($log_file, 0644)) {
                                throw new \Exception(__('Log file is not writable.', 'wp-github-sync'));
                            }
                        }
                        
                        // Clear log file
                        if (file_put_contents($log_file, '') === false) {
                            throw new \Exception(__('Failed to clear log file.', 'wp-github-sync'));
                        }
                    } else {
                        // If file doesn't exist, create an empty one
                        if (file_put_contents($log_file, '') === false) {
                            throw new \Exception(__('Failed to create log file.', 'wp-github-sync'));
                        }
                    }
                    
                    // Add success message
                    add_settings_error(
                        'wp_github_sync',
                        'logs_cleared',
                        __('Logs cleared successfully.', 'wp-github-sync'),
                        'success'
                    );
                } catch (\Exception $e) {
                    add_settings_error(
                        'wp_github_sync',
                        'logs_clear_error',
                        sprintf(__('Error clearing logs: %s', 'wp-github-sync'), $e->getMessage()),
                        'error'
                    );
                }
                break;
                
            case 'download_logs':
                // Verify nonce
                if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'wp_github_sync_download_logs')) {
                    wp_die(__('Security check failed. Please try again.', 'wp-github-sync'));
                }
                
                // Check if log file exists and is readable
                if (!file_exists($log_file)) {
                    wp_die(__('Log file not found.', 'wp-github-sync'));
                }
                
                if (!is_readable($log_file)) {
                    wp_die(__('Log file is not readable.', 'wp-github-sync'));
                }
                
                // Set the downloaded file name
                $download_name = 'wp-github-sync-logs-' . date('Y-m-d') . '.log';
                
                // Set headers for download
                nocache_headers(); // Disable caching
                header('Content-Description: File Transfer');
                header('Content-Type: text/plain');
                header('Content-Disposition: attachment; filename=' . $download_name);
                header('Expires: 0');
                header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
                header('Pragma: public');
                
                // Get file size
                $file_size = filesize($log_file);
                header('Content-Length: ' . $file_size);
                
                // Check if we need to limit the download size
                $max_download_size = apply_filters('wp_github_sync_max_download_size', 15 * 1024 * 1024); // 15MB default
                
                if ($file_size > $max_download_size) {
                    // Read and output only the last portion of the file
                    $fp = fopen($log_file, 'rb');
                    if ($fp) {
                        fseek($fp, -$max_download_size, SEEK_END);
                        // Add header indicating truncation
                        echo "--- Log file was too large. Showing only the last " . size_format($max_download_size) . " ---\n\n";
                        // Output file content
                        fpassthru($fp);
                        fclose($fp);
                    } else {
                        wp_die(__('Failed to open log file.', 'wp-github-sync'));
                    }
                } else {
                    // Read and output the entire file
                    readfile($log_file);
                }
                exit;
                
            case 'test_log':
                // Verify nonce
                if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'wp_github_sync_test_log')) {
                    wp_die(__('Security check failed. Please try again.', 'wp-github-sync'));
                }
                
                try {
                    // Create test log entries for each log level
                    if (!function_exists('wp_github_sync_log')) {
                        throw new \Exception(__('Logging function not available.', 'wp-github-sync'));
                    }
                    
                    // Add some useful context to the test logs
                    $site_name = get_bloginfo('name');
                    $time = date('H:i:s');
                    
                    // Create test log entries for each log level with force=true to ensure they're written
                    wp_github_sync_log("This is a test DEBUG message. Site: '{$site_name}', Time: {$time}", 'debug', true);
                    wp_github_sync_log("This is a test INFO message. Site: '{$site_name}', Time: {$time}", 'info', true);
                    wp_github_sync_log("This is a test WARNING message. Site: '{$site_name}', Time: {$time}", 'warning', true);
                    wp_github_sync_log("This is a test ERROR message. Site: '{$site_name}', Time: {$time}", 'error', true);
                    
                    // Add success message
                    add_settings_error(
                        'wp_github_sync',
                        'logs_created',
                        __('Test log entries created. You can now see how different log levels are displayed.', 'wp-github-sync'),
                        'success'
                    );
                } catch (\Exception $e) {
                    add_settings_error(
                        'wp_github_sync',
                        'logs_test_error',
                        sprintf(__('Error creating test logs: %s', 'wp-github-sync'), $e->getMessage()),
                        'error'
                    );
                }
                break;
        }
    }
    
    /**
     * Checks if a path is within the WordPress content directory.
     *
     * @param string $path The path to check.
     * @return bool True if the path is inside wp-content, false otherwise.
     */
    private function is_path_in_wp_content($path) {
        $wp_content_dir = wp_normalize_path(WP_CONTENT_DIR);
        $path = wp_normalize_path($path);
        
        return strpos($path, $wp_content_dir) === 0;
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
    
    /**
     * Handle direct admin actions (e.g., from URL).
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
            wp_github_sync_log("Deploy AJAX: Permission denied", 'error');
            wp_send_json_error(array('message' => __('You do not have sufficient permissions to perform this action.', 'wp-github-sync')));
            return;
        }
        
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wp_github_sync_nonce')) {
            wp_github_sync_log("Deploy AJAX: Nonce verification failed", 'error');
            wp_send_json_error(array('message' => __('Security check failed. Please refresh the page and try again.', 'wp-github-sync')));
            return;
        }
        
        // Get current branch
        $branch = wp_github_sync_get_current_branch();
        wp_github_sync_log("Deploy AJAX: Starting deployment of branch '{$branch}'", 'info');
        
        // Deploy
        $result = $this->sync_manager->deploy($branch);
        
        if (is_wp_error($result)) {
            $error_message = $result->get_error_message();
            wp_github_sync_log("Deploy AJAX: Deployment failed - {$error_message}", 'error');
            wp_send_json_error(array('message' => $error_message));
        } else {
            wp_github_sync_log("Deploy AJAX: Deployment of '{$branch}' completed successfully", 'info');
            wp_send_json_success(array('message' => __('Deployment completed successfully.', 'wp-github-sync')));
        }
    }

    /**
     * Handle AJAX switch branch request.
     */
    public function handle_ajax_switch_branch() {
        // Check permissions
        if (!wp_github_sync_current_user_can()) {
            wp_github_sync_log("Switch Branch AJAX: Permission denied", 'error');
            wp_send_json_error(array('message' => __('You do not have sufficient permissions to perform this action.', 'wp-github-sync')));
            return;
        }
        
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wp_github_sync_nonce')) {
            wp_github_sync_log("Switch Branch AJAX: Nonce verification failed", 'error');
            wp_send_json_error(array('message' => __('Security check failed. Please refresh the page and try again.', 'wp-github-sync')));
            return;
        }
        
        // Check if branch is provided
        if (!isset($_POST['branch']) || empty($_POST['branch'])) {
            wp_github_sync_log("Switch Branch AJAX: No branch specified in request", 'error');
            wp_send_json_error(array('message' => __('No branch specified.', 'wp-github-sync')));
            return;
        }
        
        $branch = sanitize_text_field($_POST['branch']);
        $current_branch = wp_github_sync_get_current_branch();
        
        wp_github_sync_log("Switch Branch AJAX: Attempting to switch from '{$current_branch}' to '{$branch}'", 'info');
        
        // Switch branch
        $result = $this->sync_manager->switch_branch($branch);
        
        if (is_wp_error($result)) {
            $error_message = $result->get_error_message();
            wp_github_sync_log("Switch Branch AJAX: Failed to switch to branch '{$branch}' - {$error_message}", 'error');
            wp_send_json_error(array('message' => $error_message));
        } else {
            wp_github_sync_log("Switch Branch AJAX: Successfully switched to branch '{$branch}'", 'info');
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
            wp_github_sync_log("Rollback AJAX: Permission denied", 'error');
            wp_send_json_error(array('message' => __('You do not have sufficient permissions to perform this action.', 'wp-github-sync')));
            return;
        }
        
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wp_github_sync_nonce')) {
            wp_github_sync_log("Rollback AJAX: Nonce verification failed", 'error');
            wp_send_json_error(array('message' => __('Security check failed. Please refresh the page and try again.', 'wp-github-sync')));
            return;
        }
        
        // Check if commit is provided
        if (!isset($_POST['commit']) || empty($_POST['commit'])) {
            wp_github_sync_log("Rollback AJAX: No commit specified in request", 'error');
            wp_send_json_error(array('message' => __('No commit specified.', 'wp-github-sync')));
            return;
        }
        
        $commit = sanitize_text_field($_POST['commit']);
        $commit_short = substr($commit, 0, 8);
        
        wp_github_sync_log("Rollback AJAX: Attempting rollback to commit '{$commit_short}'", 'info');
        
        // Rollback
        $result = $this->sync_manager->rollback($commit);
        
        if (is_wp_error($result)) {
            $error_message = $result->get_error_message();
            wp_github_sync_log("Rollback AJAX: Failed to rollback to commit '{$commit_short}' - {$error_message}", 'error');
            wp_send_json_error(array('message' => $error_message));
        } else {
            wp_github_sync_log("Rollback AJAX: Successfully rolled back to commit '{$commit_short}'", 'info');
            wp_send_json_success(array(
                'message' => sprintf(__('Successfully rolled back to commit: %s', 'wp-github-sync'), $commit_short),
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
            wp_github_sync_log("Refresh Branches AJAX: Permission denied", 'error');
            wp_send_json_error(array('message' => __('You do not have sufficient permissions to perform this action.', 'wp-github-sync')));
            return;
        }
        
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wp_github_sync_nonce')) {
            wp_github_sync_log("Refresh Branches AJAX: Nonce verification failed", 'error');
            wp_send_json_error(array('message' => __('Security check failed. Please refresh the page and try again.', 'wp-github-sync')));
            return;
        }
        
        wp_github_sync_log("Refresh Branches AJAX: Fetching branches from repository", 'info');
        
        // Refresh GitHub API client
        $this->github_api->initialize();
        
        // Get branches
        $branches = array();
        $branches_api = $this->github_api->get_branches();
        
        if (is_wp_error($branches_api)) {
            $error_message = $branches_api->get_error_message();
            wp_github_sync_log("Refresh Branches AJAX: Failed to fetch branches - {$error_message}", 'error');
            wp_send_json_error(array('message' => $error_message));
        } else {
            foreach ($branches_api as $branch_data) {
                if (isset($branch_data['name'])) {
                    $branches[] = $branch_data['name'];
                }
            }
            
            $branches_count = count($branches);
            wp_github_sync_log("Refresh Branches AJAX: Successfully fetched {$branches_count} branches", 'info');
            
            // Store the branches in the database for future use
            update_option('wp_github_sync_branches', $branches);
            
            wp_send_json_success(array('branches' => $branches));
        }
    }

    /**
     * Handle AJAX regenerate webhook secret request.
     */
    public function handle_ajax_regenerate_webhook() {
        // Check permissions
        if (!wp_github_sync_current_user_can()) {
            wp_github_sync_log("Regenerate Webhook AJAX: Permission denied", 'error');
            wp_send_json_error(array('message' => __('You do not have sufficient permissions to perform this action.', 'wp-github-sync')));
            return;
        }
        
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wp_github_sync_nonce')) {
            wp_github_sync_log("Regenerate Webhook AJAX: Nonce verification failed", 'error');
            wp_send_json_error(array('message' => __('Security check failed. Please refresh the page and try again.', 'wp-github-sync')));
            return;
        }
        
        wp_github_sync_log("Regenerate Webhook AJAX: Generating new webhook secret", 'info');
        
        // Get old secret for logging
        $old_secret = get_option('wp_github_sync_webhook_secret', '');
        $has_old_secret = !empty($old_secret);
        
        // Generate new webhook secret
        $new_secret = wp_github_sync_generate_webhook_secret();
        update_option('wp_github_sync_webhook_secret', $new_secret);
        
        if ($has_old_secret) {
            wp_github_sync_log("Regenerate Webhook AJAX: Webhook secret was successfully regenerated", 'info');
        } else {
            wp_github_sync_log("Regenerate Webhook AJAX: New webhook secret was generated (no previous secret existed)", 'info');
        }
        
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
            wp_github_sync_log("Permission denied for initial sync", 'error');
            wp_send_json_error(array('message' => __('You do not have sufficient permissions to perform this action.', 'wp-github-sync')));
            return;
        }
        
        // Verify nonce - accept both the specific initial sync nonce and the general plugin nonce
        if (!isset($_POST['nonce']) || 
            (!wp_verify_nonce($_POST['nonce'], 'wp_github_sync_initial_sync') && 
             !wp_verify_nonce($_POST['nonce'], 'wp_github_sync_nonce'))) {
            wp_github_sync_log("Invalid nonce for initial sync", 'error');
            wp_send_json_error(array('message' => __('Security check failed. Please refresh the page and try again.', 'wp-github-sync')));
            return;
        }
        
        try {
            // Check if we should create a new repository
            $create_new_repo = isset($_POST['create_new_repo']) && $_POST['create_new_repo'] == 1;
            $repo_name = isset($_POST['repo_name']) ? sanitize_text_field($_POST['repo_name']) : '';
            
            // Make sure GitHub API is initialized with the latest settings
            $this->github_api->initialize();
            
            wp_github_sync_log("Starting initial sync. Create new repo: " . ($create_new_repo ? 'yes' : 'no'), 'info');
            
            // Check if authentication is working
            $auth_test = $this->github_api->test_authentication();
            if ($auth_test !== true) {
                wp_github_sync_log("Authentication failed during initial sync: " . $auth_test, 'error');
                wp_send_json_error(array('message' => sprintf(__('Authentication failed: %s. Please check your GitHub access token.', 'wp-github-sync'), $auth_test)));
                return;
            }
            
            wp_github_sync_log("GitHub authentication successful", 'info');
            
            // If creating a new repository
            if ($create_new_repo) {
                try {
                    if (empty($repo_name)) {
                        // Generate default repo name based on site URL
                        $site_url = parse_url(get_site_url(), PHP_URL_HOST);
                        $repo_name = sanitize_title(str_replace('.', '-', $site_url));
                        wp_github_sync_log("Using generated repo name: " . $repo_name, 'info');
                    }
                    
                    // Create description based on site name
                    $site_name = get_bloginfo('name');
                    $description = sprintf(__('WordPress site: %s', 'wp-github-sync'), $site_name);
                    
                    wp_github_sync_log("Creating new repository: " . $repo_name, 'info');
                    
                    try {
                        // Create the repository
                        $result = $this->github_api->create_repository($repo_name, $description);
                        
                        if (is_wp_error($result)) {
                            wp_github_sync_log("Failed to create repository: " . $result->get_error_message(), 'error');
                            wp_send_json_error(array('message' => sprintf(__('Failed to create repository: %s', 'wp-github-sync'), $result->get_error_message())));
                            return;
                        }
                        
                        // Get the repository URL and owner/repo details
                        if (isset($result['html_url'])) {
                            $repo_url = $result['html_url'];
                            $repo_owner = isset($result['owner']['login']) ? $result['owner']['login'] : '';
                            $repo_name = isset($result['name']) ? $result['name'] : '';
                            
                            wp_github_sync_log("Repository created successfully: " . $repo_url, 'info');
                            
                            // Save repository URL to settings
                            update_option('wp_github_sync_repository', $repo_url);
                            
                            // Try initial sync for a new repository
                            wp_github_sync_log("Starting initial file sync to new repository", 'info');
                            
                            try {
                                // Create Repository instance with the API client
                                $repository = new \WPGitHubSync\API\Repository($this->github_api);
                                $sync_result = $repository->initial_sync();
                                
                                if (is_wp_error($sync_result)) {
                                    wp_github_sync_log("Initial file sync failed: " . $sync_result->get_error_message(), 'error');
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
                                update_option('wp_github_sync_last_deployed_commit', $sync_result);
                                
                                wp_github_sync_log("Repository created and initialized successfully", 'info');
                                
                                wp_send_json_success(array(
                                    'message' => sprintf(__('Repository created and initialized successfully at %s', 'wp-github-sync'), $repo_url),
                                    'repo_url' => $repo_url,
                                ));
                                return;
                            } catch (Exception $sync_exception) {
                                wp_github_sync_log("Exception during initial file sync: " . $sync_exception->getMessage(), 'error');
                                wp_github_sync_log("Stack trace: " . $sync_exception->getTraceAsString(), 'error');
                                wp_send_json_error(array('message' => sprintf(__('Repository created, but initial sync failed: %s', 'wp-github-sync'), $sync_exception->getMessage())));
                                return;
                            }
                        } else {
                            wp_github_sync_log("Repository created, but response missing URL", 'error');
                            wp_send_json_error(array('message' => __('Repository created, but the response did not include the repository URL.', 'wp-github-sync')));
                            return;
                        }
                    } catch (Exception $repo_exception) {
                        wp_github_sync_log("Exception creating repository: " . $repo_exception->getMessage(), 'error');
                        wp_github_sync_log("Stack trace: " . $repo_exception->getTraceAsString(), 'error');
                        wp_send_json_error(array('message' => sprintf(__('Failed to create repository: %s', 'wp-github-sync'), $repo_exception->getMessage())));
                        return;
                    }
                } catch (Exception $create_repo_exception) {
                    wp_github_sync_log("Critical exception during repository creation: " . $create_repo_exception->getMessage(), 'error');
                    wp_github_sync_log("Stack trace: " . $create_repo_exception->getTraceAsString(), 'error');
                    wp_send_json_error(array('message' => sprintf(__('Critical error while creating repository: %s', 'wp-github-sync'), $create_repo_exception->getMessage())));
                    return;
                }
            } else {
                // Using existing repository - perform initial deployment
                try {
                    $repo_url = get_option('wp_github_sync_repository', '');
                    
                    if (empty($repo_url)) {
                        wp_github_sync_log("No repository URL configured for initial sync", 'error');
                        wp_send_json_error(array('message' => __('No repository URL configured. Please enter a repository URL in the settings.', 'wp-github-sync')));
                        return;
                    }
                    
                    wp_github_sync_log("Performing initial sync with existing repository: " . $repo_url, 'info');
                    
                    try {
                        // Check if repository exists and is accessible
                        if (!$this->github_api->repository_exists()) {
                            wp_github_sync_log("Repository does not exist or is not accessible: " . $repo_url, 'error');
                            wp_send_json_error(array('message' => __('The repository does not exist or is not accessible with your current GitHub credentials.', 'wp-github-sync')));
                            return;
                        }
                        
                        wp_github_sync_log("Repository exists and is accessible", 'info');
                        
                        // Try to get the default branch from the repository
                        $default_branch = $this->github_api->get_default_branch();
                        if (!is_wp_error($default_branch) && !empty($default_branch)) {
                            wp_github_sync_log("Detected default branch: " . $default_branch, 'info');
                            update_option('wp_github_sync_branch', $default_branch);
                            $branch = $default_branch;
                        } else {
                            // Use configured branch or fallback to main
                            $branch = get_option('wp_github_sync_branch', 'main');
                            wp_github_sync_log("Using configured branch: " . $branch, 'info');
                        }
                        
                        // First try an initial sync to establish the repository structure
                        wp_github_sync_log("Attempting initial file sync to repository", 'info');
                        
                        try {
                            // Create Repository instance with the API client
                            $repository = new \WPGitHubSync\API\Repository($this->github_api);
                            $sync_result = $repository->initial_sync($branch);
                            
                            if (is_wp_error($sync_result)) {
                                wp_github_sync_log("Initial file sync failed: " . $sync_result->get_error_message(), 'error');
                                wp_send_json_error(array('message' => sprintf(__('Initial file sync failed: %s', 'wp-github-sync'), $sync_result->get_error_message())));
                                return;
                            }
                            
                            // Set initial deployment commit reference
                            if (!empty($sync_result)) {
                                update_option('wp_github_sync_last_deployed_commit', $sync_result);
                                update_option('wp_github_sync_last_deployment_time', time());
                                wp_github_sync_log("Initial sync completed successfully, commit: " . $sync_result, 'info');
                            }
                            
                            wp_send_json_success(array('message' => __('Initial sync completed successfully.', 'wp-github-sync')));
                            return;
                        } catch (Exception $sync_exception) {
                            wp_github_sync_log("Exception during initial file sync: " . $sync_exception->getMessage(), 'error');
                            wp_github_sync_log("Stack trace: " . $sync_exception->getTraceAsString(), 'error');
                            wp_send_json_error(array('message' => sprintf(__('Initial file sync failed: %s', 'wp-github-sync'), $sync_exception->getMessage())));
                            return;
                        }
                    } catch (Exception $repo_exception) {
                        wp_github_sync_log("Exception checking repository: " . $repo_exception->getMessage(), 'error');
                        wp_github_sync_log("Stack trace: " . $repo_exception->getTraceAsString(), 'error');
                        wp_send_json_error(array('message' => sprintf(__('Error verifying repository: %s', 'wp-github-sync'), $repo_exception->getMessage())));
                        return;
                    }
                } catch (Exception $existing_repo_exception) {
                    wp_github_sync_log("Critical exception during existing repository sync: " . $existing_repo_exception->getMessage(), 'error');
                    wp_github_sync_log("Stack trace: " . $existing_repo_exception->getTraceAsString(), 'error');
                    wp_send_json_error(array('message' => sprintf(__('Critical error during repository sync: %s', 'wp-github-sync'), $existing_repo_exception->getMessage())));
                    return;
                }
            }
        } catch (Exception $e) {
            // Catch any exceptions and return an error response
            wp_github_sync_log("Initial sync exception: " . $e->getMessage(), 'error');
            wp_github_sync_log("Stack trace: " . $e->getTraceAsString(), 'error');
            wp_send_json_error(array('message' => sprintf(__('Initial sync failed: %s', 'wp-github-sync'), $e->getMessage())));
            return;
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
        // Create Repository instance with the API client
        $repository = new \WPGitHubSync\API\Repository($this->github_api);
        $result = $repository->initial_sync($branch);
        
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
     * Handle AJAX log error request.
     */
    public function handle_ajax_log_error() {
        // Check permissions
        if (!wp_github_sync_current_user_can()) {
            return;
        }
        
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wp_github_sync_nonce')) {
            return;
        }
        
        // Get error details from AJAX request
        $error_context = isset($_POST['error_context']) ? sanitize_text_field($_POST['error_context']) : 'Unknown context';
        $error_status = isset($_POST['error_status']) ? sanitize_text_field($_POST['error_status']) : 'Unknown status';
        $error_message = isset($_POST['error_message']) ? sanitize_text_field($_POST['error_message']) : 'Unknown error';
        
        // Log error with detailed information
        wp_github_sync_log(
            "JavaScript Error - Context: {$error_context}, Status: {$error_status}, Message: {$error_message}",
            'error',
            true // Force logging even if debug mode is off
        );
        
        // No need to send a detailed response
        wp_send_json_success();
    }
    
    /**
     * Handle AJAX test connection request.
     */
    public function handle_ajax_test_connection() {
        // Check permissions
        if (!wp_github_sync_current_user_can()) {
            wp_github_sync_log("Test Connection AJAX: Permission denied", 'error');
            wp_send_json_error(array('message' => __('You do not have sufficient permissions to perform this action.', 'wp-github-sync')));
            return;
        }
        
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wp_github_sync_nonce')) {
            wp_github_sync_log("Test Connection AJAX: Nonce verification failed", 'error');
            wp_send_json_error(array('message' => __('Security check failed. Please refresh the page and try again.', 'wp-github-sync')));
            return;
        }
        
        // Check if a temporary token was provided for testing
        $temp_token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';
        $repo_url = isset($_POST['repo_url']) ? esc_url_raw($_POST['repo_url']) : get_option('wp_github_sync_repository', '');
        
        wp_github_sync_log("Test Connection AJAX: Testing GitHub connection" . 
            (!empty($temp_token) ? " with new token" : " with existing token") . 
            (!empty($repo_url) ? " and repository URL: {$repo_url}" : ""), 'info');
        
        // If a temporary token was provided, use it instead of the stored one
        if (!empty($temp_token)) {
            // Set the token directly on the API client (bypassing encryption)
            $this->github_api->set_temporary_token($temp_token);
        }
        
        // Test authentication
        $auth_test = $this->github_api->test_authentication();
        
        if ($auth_test === true) {
            $username = $this->github_api->get_user_login();
            wp_github_sync_log("Test Connection AJAX: Authentication successful! Authenticated as user: {$username}", 'info');
            
            // Authentication succeeded, now check repo if provided
            if (!empty($repo_url)) {
                // Parse the repo URL
                $parsed_url = $this->github_api->parse_github_url($repo_url);
                
                if ($parsed_url) {
                    $owner = $parsed_url['owner'];
                    $repo = $parsed_url['repo'];
                    wp_github_sync_log("Test Connection AJAX: Checking repository: {$owner}/{$repo}", 'info');
                    
                    // Check if repo exists and is accessible
                    $repo_exists = $this->github_api->repository_exists($owner, $repo);
                    
                    if ($repo_exists) {
                        wp_github_sync_log("Test Connection AJAX: Repository verified and accessible", 'info');
                        // Success! Authentication and repo are valid
                        wp_send_json_success(array(
                            'message' => __('Success! Your GitHub credentials and repository are valid.', 'wp-github-sync'),
                            'username' => $username,
                            'repo_info' => array(
                                'owner' => $owner,
                                'repo' => $repo
                            )
                        ));
                    } else {
                        wp_github_sync_log("Test Connection AJAX: Repository not accessible: {$owner}/{$repo}", 'error');
                        // Authentication worked but repo isn't accessible
                        wp_send_json_error(array(
                            'message' => __('Authentication successful, but the repository could not be accessed. Please check your repository URL and ensure your token has access to it.', 'wp-github-sync'),
                            'username' => $username,
                            'auth_ok' => true,
                            'repo_error' => true
                        ));
                    }
                } else {
                    wp_github_sync_log("Test Connection AJAX: Invalid repository URL format: {$repo_url}", 'error');
                    // Authentication worked but repo URL is invalid
                    wp_send_json_error(array(
                        'message' => __('Authentication successful, but the repository URL is invalid. Please provide a valid GitHub repository URL.', 'wp-github-sync'),
                        'username' => $username,
                        'auth_ok' => true,
                        'url_error' => true
                    ));
                }
            } else {
                // Authentication worked but no repo was provided
                wp_send_json_success(array(
                    'message' => __('Authentication successful! Your GitHub credentials are valid.', 'wp-github-sync'),
                    'username' => $username,
                    'auth_ok' => true,
                    'no_repo' => true
                ));
            }
        } else {
            wp_github_sync_log("Test Connection AJAX: Authentication failed - {$auth_test}", 'error');
            // Authentication failed
            wp_send_json_error(array(
                'message' => sprintf(__('Authentication failed: %s', 'wp-github-sync'), $auth_test),
                'auth_error' => true
            ));
        }
    }

    /**
     * Handle AJAX log error request.
     */
    public function handle_ajax_log_error() {
        // Check permissions
        if (!wp_github_sync_current_user_can()) {
            return;
        }
        
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wp_github_sync_nonce')) {
            return;
        }
        
        // Get error details from AJAX request
        $error_context = isset($_POST['error_context']) ? sanitize_text_field($_POST['error_context']) : 'Unknown context';
        $error_status = isset($_POST['error_status']) ? sanitize_text_field($_POST['error_status']) : 'Unknown status';
        $error_message = isset($_POST['error_message']) ? sanitize_text_field($_POST['error_message']) : 'Unknown error';
        
        // Log error with detailed information
        wp_github_sync_log(
            "JavaScript Error - Context: {$error_context}, Status: {$error_status}, Message: {$error_message}",
            'error',
            true // Force logging even if debug mode is off
        );
        
        // No need to send a detailed response
        wp_send_json_success();
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