<?php
/**
 * Admin Controller
 *
 * @package WPGitHubSync
 */

namespace WPGitHubSync\Admin;

use WPGitHubSync\API\Client;
use WPGitHubSync\Sync\BackupManager;
use WPGitHubSync\UI\Components\Card;
use WPGitHubSync\UI\Components\Button;
use WPGitHubSync\UI\Components\Tabs;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin Controller class
 */
class AdminController {
    /**
     * Plugin version
     *
     * @var string
     */
    private $version;
    
    /**
     * API client
     *
     * @var Client
     */
    private $client;
    
    /**
     * Constructor
     *
     * @param string $version Plugin version.
     */
    public function __construct( $version ) {
        $this->version = $version;
        $this->client = new Client( $version );
    }
    
    /**
     * Register admin menu pages
     */
    public function register_menu_pages() {
        add_menu_page(
            __( 'GitHub Sync', 'wp-github-sync' ),
            __( 'GitHub Sync', 'wp-github-sync' ),
            'manage_options',
            'wp-github-sync',
            array( $this, 'render_dashboard_page' ),
            'dashicons-randomize',
            81
        );
        
        add_submenu_page(
            'wp-github-sync',
            __( 'Dashboard', 'wp-github-sync' ),
            __( 'Dashboard', 'wp-github-sync' ),
            'manage_options',
            'wp-github-sync',
            array( $this, 'render_dashboard_page' )
        );
        
        add_submenu_page(
            'wp-github-sync',
            __( 'History', 'wp-github-sync' ),
            __( 'History', 'wp-github-sync' ),
            'manage_options',
            'wp-github-sync-history',
            array( $this, 'render_history_page' )
        );
        
        add_submenu_page(
            'wp-github-sync',
            __( 'Backups', 'wp-github-sync' ),
            __( 'Backups', 'wp-github-sync' ),
            'manage_options',
            'wp-github-sync-backups',
            array( $this, 'render_backups_page' )
        );
        
        add_submenu_page(
            'wp-github-sync',
            __( 'Settings', 'wp-github-sync' ),
            __( 'Settings', 'wp-github-sync' ),
            'manage_options',
            'wp-github-sync-settings',
            array( $this, 'render_settings_page' )
        );
    }
    
    /**
     * Enqueue admin assets
     *
     * @param string $hook Current admin page.
     */
    public function enqueue_assets( $hook ) {
        if ( false === strpos( $hook, 'wp-github-sync' ) ) {
            return;
        }
        
        // Enqueue styles
        wp_enqueue_style(
            'wp-github-sync-admin',
            WP_GITHUB_SYNC_URL . 'assets/css/admin.css',
            array(),
            $this->version
        );
        
        // Enqueue scripts
        wp_enqueue_script(
            'wp-github-sync-admin',
            WP_GITHUB_SYNC_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            $this->version,
            true
        );
        
        // Localize script
        wp_localize_script(
            'wp-github-sync-admin',
            'wpGitHubSync',
            array(
                'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
                'apiNonce' => wp_create_nonce( 'wp_rest' ),
                'apiUrl'   => rest_url( 'wp-github-sync/v1' ),
                'strings'  => array(
                    'syncing'      => __( 'Syncing...', 'wp-github-sync' ),
                    'syncComplete' => __( 'Sync Complete!', 'wp-github-sync' ),
                    'syncFailed'   => __( 'Sync Failed!', 'wp-github-sync' ),
                    'confirm'      => __( 'Are you sure?', 'wp-github-sync' ),
                    'creating'     => __( 'Creating...', 'wp-github-sync' ),
                    'restoring'    => __( 'Restoring...', 'wp-github-sync' ),
                    'comparing'    => __( 'Comparing...', 'wp-github-sync' ),
                ),
            )
        );
    }
    
    /**
     * Render dashboard page
     */
    public function render_dashboard_page() {
        // Get repository status
        $repo_status = $this->get_repository_status();
        
        // Create repository status card
        $status_card = new Card( __( 'Repository Status', 'wp-github-sync' ) );
        
        if ( is_wp_error( $repo_status ) ) {
            $status_content = '<div class="wp-github-sync-status error">';
            $status_content .= '<span class="dashicons dashicons-warning"></span> ';
            $status_content .= esc_html( $repo_status->get_error_message() );
            $status_content .= '</div>';
            
            $button = new Button(
                __( 'Configure Repository', 'wp-github-sync' ),
                admin_url( 'admin.php?page=wp-github-sync-settings' ),
                'wp-github-sync-button'
            );
            
            $status_card->set_content( $status_content );
            $status_card->set_footer( $button->render() );
        } else {
            $status_content = '<div class="wp-github-sync-status success">';
            $status_content .= '<span class="dashicons dashicons-yes"></span> ';
            $status_content .= sprintf(
                /* translators: 1: Repository owner, 2: Repository name */
                __( 'Connected to %1$s/%2$s', 'wp-github-sync' ),
                '<strong>' . esc_html( $repo_status['owner'] ) . '</strong>',
                '<strong>' . esc_html( $repo_status['name'] ) . '</strong>'
            );
            $status_content .= '</div>';
            
            $status_content .= '<div class="wp-github-sync-details">';
            $status_content .= '<p><strong>' . __( 'Default Branch:', 'wp-github-sync' ) . '</strong> ' . esc_html( $repo_status['default_branch'] ) . '</p>';
            $status_content .= '<p><strong>' . __( 'Last Synced:', 'wp-github-sync' ) . '</strong> ' . esc_html( $this->get_last_sync_time() ) . '</p>';
            $status_content .= '</div>';
            
            $sync_button = new Button(
                __( 'Sync Now', 'wp-github-sync' ),
                '#',
                'wp-github-sync-button js-sync-button'
            );
            
            $status_card->set_content( $status_content );
            $status_card->set_footer( $sync_button->render() );
        }
        
        // Create quick actions card
        $actions_card = new Card( __( 'Quick Actions', 'wp-github-sync' ) );
        
        $actions_content = '<div class="wp-github-sync-actions">';
        $actions_content .= '<ul>';
        $actions_content .= '<li><a href="' . admin_url( 'admin.php?page=wp-github-sync-backups' ) . '" class="wp-github-sync-button"><span class="dashicons dashicons-backup"></span> ' . __( 'View Backups', 'wp-github-sync' ) . '</a></li>';
        $actions_content .= '<li><a href="#" class="wp-github-sync-button js-create-backup"><span class="dashicons dashicons-media-archive"></span> ' . __( 'Create Backup', 'wp-github-sync' ) . '</a></li>';
        $actions_content .= '<li><a href="' . admin_url( 'admin.php?page=wp-github-sync-history' ) . '" class="wp-github-sync-button"><span class="dashicons dashicons-backup"></span> ' . __( 'View History', 'wp-github-sync' ) . '</a></li>';
        $actions_content .= '</ul>';
        $actions_content .= '</div>';
        
        $actions_card->set_content( $actions_content );
        
        // Recent commits card
        $commits_card = new Card( __( 'Recent Commits', 'wp-github-sync' ) );
        
        $commits = $this->get_recent_commits();
        
        if ( is_wp_error( $commits ) ) {
            $commits_content = '<div class="wp-github-sync-error">';
            $commits_content .= esc_html( $commits->get_error_message() );
            $commits_content .= '</div>';
        } else {
            $commits_content = '<ul class="wp-github-sync-commits">';
            
            foreach ( $commits as $commit ) {
                $commits_content .= '<li class="wp-github-sync-commit">';
                $commits_content .= '<div class="wp-github-sync-commit-message">' . esc_html( $commit['message'] ) . '</div>';
                $commits_content .= '<div class="wp-github-sync-commit-meta">';
                $commits_content .= '<span class="wp-github-sync-commit-author">' . esc_html( $commit['author'] ) . '</span>';
                $commits_content .= '<span class="wp-github-sync-commit-date">' . esc_html( $commit['date'] ) . '</span>';
                $commits_content .= '</div>';
                $commits_content .= '</li>';
            }
            
            $commits_content .= '</ul>';
        }
        
        $commits_card->set_content( $commits_content );
        
        // Sync status card
        $sync_status_card = new Card( __( 'Sync Status', 'wp-github-sync' ) );
        
        $sync_status_content = '<div class="wp-github-sync-sync-status"></div>';
        
        $sync_status_card->set_content( $sync_status_content );
        
        // Render the page
        include WP_GITHUB_SYNC_DIR . 'templates/dashboard.php';
    }
    
    /**
     * Render history page
     */
    public function render_history_page() {
        // Get sync history
        $sync_history = $this->get_sync_history();
        
        // Get deployment history
        $deploy_history = $this->get_deployment_history();
        
        // Create tabs
        $tabs = new Tabs( 'history-tabs' );
        
        // Sync history tab
        $sync_history_content = '<div class="wp-github-sync-timeline">';
        
        if ( empty( $sync_history ) ) {
            $sync_history_content .= '<div class="wp-github-sync-empty-state">';
            $sync_history_content .= '<p>' . __( 'No sync history found.', 'wp-github-sync' ) . '</p>';
            $sync_history_content .= '</div>';
        } else {
            foreach ( $sync_history as $entry ) {
                $status_class = ! empty( $entry['success'] ) ? 'success' : 'error';
                
                $sync_history_content .= '<div class="wp-github-sync-timeline-item">';
                $sync_history_content .= '<div class="wp-github-sync-timeline-dot ' . esc_attr( $status_class ) . '"></div>';
                $sync_history_content .= '<div class="wp-github-sync-timeline-content">';
                $sync_history_content .= '<div class="wp-github-sync-timeline-date">' . esc_html( $entry['date'] ) . '</div>';
                $sync_history_content .= '<h3>' . esc_html( $entry['action'] ) . '</h3>';
                $sync_history_content .= '<p>' . esc_html( $entry['message'] ) . '</p>';
                
                if ( ! empty( $entry['user_login'] ) ) {
                    $sync_history_content .= '<p><strong>' . __( 'User:', 'wp-github-sync' ) . '</strong> ' . esc_html( $entry['user_login'] ) . '</p>';
                }
                
                $sync_history_content .= '</div>';
                $sync_history_content .= '</div>';
            }
        }
        
        $sync_history_content .= '</div>';
        
        // Deployment history tab
        $deploy_history_content = '<div class="wp-github-sync-timeline">';
        
        if ( empty( $deploy_history ) ) {
            $deploy_history_content .= '<div class="wp-github-sync-empty-state">';
            $deploy_history_content .= '<p>' . __( 'No deployment history found.', 'wp-github-sync' ) . '</p>';
            $deploy_history_content .= '</div>';
        } else {
            foreach ( $deploy_history as $entry ) {
                $status_class = ! empty( $entry['success'] ) ? 'success' : 'error';
                
                $deploy_history_content .= '<div class="wp-github-sync-timeline-item">';
                $deploy_history_content .= '<div class="wp-github-sync-timeline-dot ' . esc_attr( $status_class ) . '"></div>';
                $deploy_history_content .= '<div class="wp-github-sync-timeline-content">';
                $deploy_history_content .= '<div class="wp-github-sync-timeline-date">' . esc_html( $entry['date'] ) . '</div>';
                
                $deploy_history_content .= '<h3>';
                if ( ! empty( $entry['commit'] ) ) {
                    $deploy_history_content .= sprintf(
                        /* translators: %s: Commit SHA */
                        __( 'Deployed commit %s', 'wp-github-sync' ),
                        '<code>' . esc_html( substr( $entry['commit'], 0, 7 ) ) . '</code>'
                    );
                } else {
                    $deploy_history_content .= __( 'Deployment', 'wp-github-sync' );
                }
                $deploy_history_content .= '</h3>';
                
                if ( ! empty( $entry['message'] ) ) {
                    $deploy_history_content .= '<p>' . esc_html( $entry['message'] ) . '</p>';
                }
                
                if ( ! empty( $entry['user_login'] ) ) {
                    $deploy_history_content .= '<p><strong>' . __( 'User:', 'wp-github-sync' ) . '</strong> ' . esc_html( $entry['user_login'] ) . '</p>';
                }
                
                if ( ! empty( $entry['branch'] ) ) {
                    $deploy_history_content .= '<p><strong>' . __( 'Branch:', 'wp-github-sync' ) . '</strong> ' . esc_html( $entry['branch'] ) . '</p>';
                }
                
                // Add rollback button
                if ( ! empty( $entry['commit'] ) ) {
                    $deploy_history_content .= '<div class="wp-github-sync-timeline-actions">';
                    $deploy_history_content .= '<a href="#" class="wp-github-sync-button js-rollback-button" data-commit="' . esc_attr( $entry['commit'] ) . '">';
                    $deploy_history_content .= '<span class="dashicons dashicons-undo"></span> ' . __( 'Rollback to this commit', 'wp-github-sync' );
                    $deploy_history_content .= '</a>';
                    $deploy_history_content .= '</div>';
                }
                
                $deploy_history_content .= '</div>';
                $deploy_history_content .= '</div>';
            }
        }
        
        $deploy_history_content .= '</div>';
        
        // Add tabs
        $tabs->add_tab( 'sync-history', __( 'Sync History', 'wp-github-sync' ), $sync_history_content );
        $tabs->add_tab( 'deploy-history', __( 'Deployment History', 'wp-github-sync' ), $deploy_history_content );
        
        // Render the page
        include WP_GITHUB_SYNC_DIR . 'templates/history.php';
    }
    
    /**
     * Render backups page
     */
    public function render_backups_page() {
        // Get backups
        $backup_manager = new BackupManager();
        $backups = $backup_manager->list_backups();
        
        // Create backup button
        $create_backup_button = new Button(
            __( 'Create Backup', 'wp-github-sync' ),
            '#',
            'wp-github-sync-button js-create-backup'
        );
        
        // Render the page
        include WP_GITHUB_SYNC_DIR . 'templates/backups.php';
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        // Get settings
        $settings = get_option( 'wp_github_sync_settings', array() );
        
        // Create tabs
        $tabs = new Tabs( 'settings-tabs' );
        
        // General settings tab
        $general_settings = $this->render_general_settings( $settings );
        
        // Backup settings tab
        $backup_settings = $this->render_backup_settings( $settings );
        
        // Advanced settings tab
        $advanced_settings = $this->render_advanced_settings( $settings );
        
        // Add tabs
        $tabs->add_tab( 'general', __( 'General', 'wp-github-sync' ), $general_settings );
        $tabs->add_tab( 'backup', __( 'Backup', 'wp-github-sync' ), $backup_settings );
        $tabs->add_tab( 'advanced', __( 'Advanced', 'wp-github-sync' ), $advanced_settings );
        
        // Render the page
        include WP_GITHUB_SYNC_DIR . 'templates/settings.php';
    }
    
    /**
     * Render general settings
     *
     * @param array $settings Plugin settings.
     * @return string Settings HTML.
     */
    private function render_general_settings( $settings ) {
        ob_start();
        ?>
        <form method="post" action="options.php" class="wp-github-sync-settings-form">
            <?php settings_fields( 'wp_github_sync_settings' ); ?>
            
            <div class="wp-github-sync-settings-section">
                <h3><?php esc_html_e( 'Repository Settings', 'wp-github-sync' ); ?></h3>
                
                <div class="wp-github-sync-field">
                    <label for="repo_url"><?php esc_html_e( 'Repository URL', 'wp-github-sync' ); ?></label>
                    <input type="text" id="repo_url" name="wp_github_sync_settings[repo_url]" class="regular-text" 
                           value="<?php echo esc_attr( isset( $settings['repo_url'] ) ? $settings['repo_url'] : '' ); ?>" 
                           placeholder="https://github.com/username/repository" />
                    <p class="description"><?php esc_html_e( 'The GitHub repository URL (e.g., https://github.com/username/repository).', 'wp-github-sync' ); ?></p>
                </div>
            </div>
            
            <div class="wp-github-sync-settings-section">
                <h3><?php esc_html_e( 'Authentication', 'wp-github-sync' ); ?></h3>
                
                <div class="wp-github-sync-field">
                    <fieldset>
                        <legend class="screen-reader-text"><?php esc_html_e( 'Authentication Method', 'wp-github-sync' ); ?></legend>
                        
                        <div class="wp-github-sync-radio-group">
                            <label>
                                <input type="radio" name="wp_github_sync_settings[auth_method]" value="pat" 
                                       <?php checked( isset( $settings['auth_method'] ) ? $settings['auth_method'] : 'pat', 'pat' ); ?> />
                                <?php esc_html_e( 'Personal Access Token (PAT)', 'wp-github-sync' ); ?>
                            </label>
                            
                            <label>
                                <input type="radio" name="wp_github_sync_settings[auth_method]" value="oauth" 
                                       <?php checked( isset( $settings['auth_method'] ) ? $settings['auth_method'] : 'pat', 'oauth' ); ?> />
                                <?php esc_html_e( 'OAuth Token', 'wp-github-sync' ); ?>
                            </label>
                        </div>
                    </fieldset>
                </div>
                
                <div class="wp-github-sync-field">
                    <label for="access_token"><?php esc_html_e( 'Access Token', 'wp-github-sync' ); ?></label>
                    <input type="password" id="access_token" name="wp_github_sync_settings[access_token]" class="regular-text" 
                           value="<?php echo esc_attr( isset( $settings['access_token'] ) ? $settings['access_token'] : '' ); ?>" />
                    <p class="description">
                        <?php esc_html_e( 'Your GitHub Personal Access Token or OAuth Token.', 'wp-github-sync' ); ?>
                        <a href="https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/creating-a-personal-access-token" target="_blank">
                            <?php esc_html_e( 'Learn how to create a token', 'wp-github-sync' ); ?>
                        </a>
                    </p>
                </div>
                
                <div class="wp-github-sync-field">
                    <button type="button" class="button js-test-connection"><?php esc_html_e( 'Test Connection', 'wp-github-sync' ); ?></button>
                    <div class="js-connection-result"></div>
                </div>
            </div>
            
            <div class="wp-github-sync-settings-section">
                <h3><?php esc_html_e( 'Sync Settings', 'wp-github-sync' ); ?></h3>
                
                <div class="wp-github-sync-field">
                    <label for="sync_branch"><?php esc_html_e( 'Branch', 'wp-github-sync' ); ?></label>
                    <input type="text" id="sync_branch" name="wp_github_sync_settings[sync_branch]" class="regular-text" 
                           value="<?php echo esc_attr( isset( $settings['sync_branch'] ) ? $settings['sync_branch'] : 'main' ); ?>" 
                           placeholder="main" />
                    <p class="description"><?php esc_html_e( 'The branch to sync with. Defaults to the repository default branch.', 'wp-github-sync' ); ?></p>
                </div>
                
                <div class="wp-github-sync-field">
                    <label>
                        <input type="checkbox" name="wp_github_sync_settings[auto_sync]" value="1" 
                               <?php checked( isset( $settings['auto_sync'] ) ? $settings['auto_sync'] : '', 1 ); ?> />
                        <?php esc_html_e( 'Enable automatic sync', 'wp-github-sync' ); ?>
                    </label>
                    <p class="description"><?php esc_html_e( 'Automatically sync with GitHub on a schedule.', 'wp-github-sync' ); ?></p>
                </div>
                
                <div class="wp-github-sync-field">
                    <label for="sync_interval"><?php esc_html_e( 'Sync Interval', 'wp-github-sync' ); ?></label>
                    <select id="sync_interval" name="wp_github_sync_settings[sync_interval]">
                        <option value="hourly" <?php selected( isset( $settings['sync_interval'] ) ? $settings['sync_interval'] : 'daily', 'hourly' ); ?>><?php esc_html_e( 'Hourly', 'wp-github-sync' ); ?></option>
                        <option value="twicedaily" <?php selected( isset( $settings['sync_interval'] ) ? $settings['sync_interval'] : 'daily', 'twicedaily' ); ?>><?php esc_html_e( 'Twice Daily', 'wp-github-sync' ); ?></option>
                        <option value="daily" <?php selected( isset( $settings['sync_interval'] ) ? $settings['sync_interval'] : 'daily', 'daily' ); ?>><?php esc_html_e( 'Daily', 'wp-github-sync' ); ?></option>
                        <option value="weekly" <?php selected( isset( $settings['sync_interval'] ) ? $settings['sync_interval'] : 'daily', 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'wp-github-sync' ); ?></option>
                    </select>
                    <p class="description"><?php esc_html_e( 'How often to automatically sync with GitHub.', 'wp-github-sync' ); ?></p>
                </div>
            </div>
            
            <?php submit_button( __( 'Save Settings', 'wp-github-sync' ) ); ?>
        </form>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render backup settings
     *
     * @param array $settings Plugin settings.
     * @return string Settings HTML.
     */
    private function render_backup_settings( $settings ) {
        ob_start();
        ?>
        <form method="post" action="options.php" class="wp-github-sync-settings-form">
            <?php settings_fields( 'wp_github_sync_settings' ); ?>
            
            <div class="wp-github-sync-settings-section">
                <h3><?php esc_html_e( 'Backup Settings', 'wp-github-sync' ); ?></h3>
                
                <div class="wp-github-sync-field">
                    <label>
                        <input type="checkbox" name="wp_github_sync_settings[auto_backup]" value="1" 
                               <?php checked( isset( $settings['auto_backup'] ) ? $settings['auto_backup'] : '1', 1 ); ?> />
                        <?php esc_html_e( 'Create backup before syncing', 'wp-github-sync' ); ?>
                    </label>
                    <p class="description"><?php esc_html_e( 'Automatically create a backup before syncing with GitHub.', 'wp-github-sync' ); ?></p>
                </div>
                
                <div class="wp-github-sync-field">
                    <label>
                        <input type="checkbox" name="wp_github_sync_settings[backup_themes]" value="1" 
                               <?php checked( isset( $settings['backup_themes'] ) ? $settings['backup_themes'] : '1', 1 ); ?> />
                        <?php esc_html_e( 'Backup themes', 'wp-github-sync' ); ?>
                    </label>
                    <p class="description"><?php esc_html_e( 'Include wp-content/themes directory in backups.', 'wp-github-sync' ); ?></p>
                </div>
                
                <div class="wp-github-sync-field">
                    <label>
                        <input type="checkbox" name="wp_github_sync_settings[backup_plugins]" value="1" 
                               <?php checked( isset( $settings['backup_plugins'] ) ? $settings['backup_plugins'] : '1', 1 ); ?> />
                        <?php esc_html_e( 'Backup plugins', 'wp-github-sync' ); ?>
                    </label>
                    <p class="description"><?php esc_html_e( 'Include wp-content/plugins directory in backups.', 'wp-github-sync' ); ?></p>
                </div>
                
                <div class="wp-github-sync-field">
                    <label>
                        <input type="checkbox" name="wp_github_sync_settings[backup_uploads]" value="1" 
                               <?php checked( isset( $settings['backup_uploads'] ) ? $settings['backup_uploads'] : '0', 1 ); ?> />
                        <?php esc_html_e( 'Backup uploads', 'wp-github-sync' ); ?>
                    </label>
                    <p class="description"><?php esc_html_e( 'Include wp-content/uploads directory in backups (may significantly increase backup size).', 'wp-github-sync' ); ?></p>
                </div>
                
                <div class="wp-github-sync-field">
                    <label>
                        <input type="checkbox" name="wp_github_sync_settings[backup_config]" value="1" 
                               <?php checked( isset( $settings['backup_config'] ) ? $settings['backup_config'] : '0', 1 ); ?> />
                        <?php esc_html_e( 'Backup wp-config.php', 'wp-github-sync' ); ?>
                    </label>
                    <p class="description"><?php esc_html_e( 'Include wp-config.php in backups.', 'wp-github-sync' ); ?></p>
                </div>
                
                <div class="wp-github-sync-field">
                    <label for="max_backups"><?php esc_html_e( 'Maximum Backups', 'wp-github-sync' ); ?></label>
                    <input type="number" id="max_backups" name="wp_github_sync_settings[max_backups]" class="small-text" 
                           value="<?php echo esc_attr( isset( $settings['max_backups'] ) ? $settings['max_backups'] : '10' ); ?>" min="1" />
                    <p class="description"><?php esc_html_e( 'Maximum number of backups to keep. Older backups will be deleted.', 'wp-github-sync' ); ?></p>
                </div>
            </div>
            
            <?php submit_button( __( 'Save Settings', 'wp-github-sync' ) ); ?>
        </form>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render advanced settings
     *
     * @param array $settings Plugin settings.
     * @return string Settings HTML.
     */
    private function render_advanced_settings( $settings ) {
        ob_start();
        ?>
        <form method="post" action="options.php" class="wp-github-sync-settings-form">
            <?php settings_fields( 'wp_github_sync_settings' ); ?>
            
            <div class="wp-github-sync-settings-section">
                <h3><?php esc_html_e( 'Advanced Settings', 'wp-github-sync' ); ?></h3>
                
                <div class="wp-github-sync-field">
                    <label>
                        <input type="checkbox" name="wp_github_sync_settings[webhook_sync]" value="1" 
                               <?php checked( isset( $settings['webhook_sync'] ) ? $settings['webhook_sync'] : '0', 1 ); ?> />
                        <?php esc_html_e( 'Enable webhook sync', 'wp-github-sync' ); ?>
                    </label>
                    <p class="description"><?php esc_html_e( 'Automatically sync when GitHub repository is updated via webhook.', 'wp-github-sync' ); ?></p>
                </div>
                
                <div class="wp-github-sync-field">
                    <label for="webhook_secret"><?php esc_html_e( 'Webhook Secret', 'wp-github-sync' ); ?></label>
                    <input type="text" id="webhook_secret" name="wp_github_sync_settings[webhook_secret]" class="regular-text" 
                           value="<?php echo esc_attr( isset( $settings['webhook_secret'] ) ? $settings['webhook_secret'] : '' ); ?>" />
                    <p class="description"><?php esc_html_e( 'Secret key for GitHub webhook (leave empty to generate automatically).', 'wp-github-sync' ); ?></p>
                </div>
                
                <div class="wp-github-sync-field">
                    <label for="webhook_url"><?php esc_html_e( 'Webhook URL', 'wp-github-sync' ); ?></label>
                    <input type="text" id="webhook_url" class="regular-text" 
                           value="<?php echo esc_url( admin_url( 'admin-ajax.php?action=wp_github_sync_webhook' ) ); ?>" readonly />
                    <p class="description"><?php esc_html_e( 'URL to use when setting up a webhook in GitHub.', 'wp-github-sync' ); ?></p>
                    <button type="button" class="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.previousElementSibling.value);">
                        <?php esc_html_e( 'Copy to Clipboard', 'wp-github-sync' ); ?>
                    </button>
                </div>
                
                <div class="wp-github-sync-field">
                    <label>
                        <input type="checkbox" name="wp_github_sync_settings[debug_mode]" value="1" 
                               <?php checked( isset( $settings['debug_mode'] ) ? $settings['debug_mode'] : '0', 1 ); ?> />
                        <?php esc_html_e( 'Enable debug mode', 'wp-github-sync' ); ?>
                    </label>
                    <p class="description"><?php esc_html_e( 'Log detailed debug information.', 'wp-github-sync' ); ?></p>
                </div>
                
                <div class="wp-github-sync-field">
                    <label for="log_retention"><?php esc_html_e( 'Log Retention (days)', 'wp-github-sync' ); ?></label>
                    <input type="number" id="log_retention" name="wp_github_sync_settings[log_retention]" class="small-text" 
                           value="<?php echo esc_attr( isset( $settings['log_retention'] ) ? $settings['log_retention'] : '7' ); ?>" min="1" />
                    <p class="description"><?php esc_html_e( 'Number of days to keep logs before deleting them.', 'wp-github-sync' ); ?></p>
                </div>
            </div>
            
            <?php submit_button( __( 'Save Settings', 'wp-github-sync' ) ); ?>
        </form>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Handle AJAX test connection request
     */
    public function handle_test_connection() {
        check_ajax_referer( 'wp_github_sync_nonce', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array(
                'message' => __( 'You do not have permission to perform this action.', 'wp-github-sync' ),
            ) );
        }
        
        $repo_url = isset( $_POST['repo_url'] ) ? sanitize_text_field( wp_unslash( $_POST['repo_url'] ) ) : '';
        $auth_method = isset( $_POST['auth_method'] ) ? sanitize_text_field( wp_unslash( $_POST['auth_method'] ) ) : 'pat';
        $access_token = isset( $_POST['access_token'] ) ? sanitize_text_field( wp_unslash( $_POST['access_token'] ) ) : '';
        
        if ( empty( $repo_url ) ) {
            wp_send_json_error( array(
                'message' => __( 'Repository URL is required.', 'wp-github-sync' ),
            ) );
        }
        
        if ( empty( $access_token ) ) {
            wp_send_json_error( array(
                'message' => __( 'Access token is required.', 'wp-github-sync' ),
            ) );
        }
        
        // Test connection
        $settings = array(
            'repo_url'    => $repo_url,
            'auth_method' => $auth_method,
            'access_token' => $access_token,
        );
        
        update_option( 'wp_github_sync_settings', $settings );
        
        $result = $this->client->test_authentication();
        
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array(
                'message' => $result->get_error_message(),
            ) );
        }
        
        wp_send_json_success( array(
            'message' => __( 'Connection successful!', 'wp-github-sync' ),
        ) );
    }
    
    /**
     * Handle AJAX get commits request
     */
    public function handle_get_commits() {
        check_ajax_referer( 'wp_github_sync_nonce', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array(
                'message' => __( 'You do not have permission to perform this action.', 'wp-github-sync' ),
            ) );
        }
        
        $branch = isset( $_POST['branch'] ) ? sanitize_text_field( wp_unslash( $_POST['branch'] ) ) : '';
        
        $commits = $this->get_recent_commits( $branch, 10 );
        
        if ( is_wp_error( $commits ) ) {
            wp_send_json_error( array(
                'message' => $commits->get_error_message(),
            ) );
        }
        
        wp_send_json_success( array(
            'commits' => $commits,
        ) );
    }
    
    /**
     * Get repository status
     *
     * @return array|\WP_Error Repository status or error.
     */
    private function get_repository_status() {
        $repo = $this->client->get_repository();
        
        if ( is_wp_error( $repo ) ) {
            return $repo;
        }
        
        return array(
            'name'           => $repo['name'],
            'owner'          => $repo['owner']['login'],
            'default_branch' => $repo['default_branch'],
            'url'            => $repo['html_url'],
        );
    }
    
    /**
     * Get last sync time
     *
     * @return string Formatted last sync time or 'Never'.
     */
    private function get_last_sync_time() {
        $last_sync = get_option( 'wp_github_sync_last_sync' );
        
        if ( ! $last_sync ) {
            return __( 'Never', 'wp-github-sync' );
        }
        
        $time_diff = human_time_diff( $last_sync, current_time( 'timestamp' ) );
        
        return sprintf(
            /* translators: %s: Human readable time difference */
            __( '%s ago', 'wp-github-sync' ),
            $time_diff
        );
    }
    
    /**
     * Get recent commits
     *
     * @param string $branch   Branch name.
     * @param int    $per_page Number of commits to fetch.
     * @return array|\WP_Error List of commits or error.
     */
    private function get_recent_commits( $branch = '', $per_page = 5 ) {
        $commits = $this->client->get_commits( $branch, $per_page );
        
        if ( is_wp_error( $commits ) ) {
            return $commits;
        }
        
        $formatted_commits = array();
        
        foreach ( $commits as $commit ) {
            $commit_data = $commit['commit'];
            $author = isset( $commit['author']['login'] ) ? $commit['author']['login'] : $commit_data['author']['name'];
            
            $formatted_commits[] = array(
                'sha'     => $commit['sha'],
                'message' => $this->get_commit_message_first_line( $commit_data['message'] ),
                'author'  => $author,
                'date'    => $this->format_commit_date( $commit_data['author']['date'] ),
                'url'     => $commit['html_url'],
            );
        }
        
        return $formatted_commits;
    }
    
    /**
     * Get only the first line of a commit message
     *
     * @param string $message Commit message.
     * @return string First line of commit message.
     */
    private function get_commit_message_first_line( $message ) {
        $lines = explode( "\n", $message );
        return trim( $lines[0] );
    }
    
    /**
     * Format commit date
     *
     * @param string $date ISO date string.
     * @return string Formatted date.
     */
    private function format_commit_date( $date ) {
        $timestamp = strtotime( $date );
        return human_time_diff( $timestamp, current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'wp-github-sync' );
    }
    
    /**
     * Get sync history
     *
     * @param int $limit Maximum number of entries to return.
     * @return array Sync history.
     */
    private function get_sync_history( $limit = 10 ) {
        $history = get_option( 'wp_github_sync_sync_history', array() );
        
        // Sort by timestamp in descending order
        usort( $history, function( $a, $b ) {
            return $b['timestamp'] - $a['timestamp'];
        } );
        
        // Limit the number of entries
        return array_slice( $history, 0, $limit );
    }
    
    /**
     * Get deployment history
     *
     * @param int $limit Maximum number of entries to return.
     * @return array Deployment history.
     */
    private function get_deployment_history( $limit = 10 ) {
        $history = get_option( 'wp_github_sync_deployment_history', array() );
        
        // Sort by timestamp in descending order
        usort( $history, function( $a, $b ) {
            return $b['timestamp'] - $a['timestamp'];
        } );
        
        // Limit the number of entries
        return array_slice( $history, 0, $limit );
    }
}