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
        
        // Generate tab content for all settings sections
        $general_settings = $this->render_general_settings( $settings );
        $backup_settings = $this->render_backup_settings( $settings );
        $advanced_settings = $this->render_advanced_settings( $settings );
        
        // Add tab navigation with custom icons (content is rendered directly in template)
        $tabs->add_tab( 'general', __( 'General', 'wp-github-sync' ), '', 'admin-generic' );
        $tabs->add_tab( 'backup', __( 'Backup', 'wp-github-sync' ), '', 'backup' );
        $tabs->add_tab( 'advanced', __( 'Advanced', 'wp-github-sync' ), '', 'admin-tools' );
        
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
        <div class="wp-github-sync-settings-section">
            <div class="wp-github-sync-section-header">
                <h3><span class="dashicons dashicons-admin-site"></span> <?php esc_html_e( 'Repository Settings', 'wp-github-sync' ); ?></h3>
                <p class="wp-github-sync-section-description"><?php esc_html_e( 'Configure your GitHub repository connection details', 'wp-github-sync' ); ?></p>
            </div>
            
            <div class="wp-github-sync-field-group">
                <div class="wp-github-sync-field with-tooltip">
                    <label for="repo_url" class="wp-github-sync-label">
                        <?php esc_html_e( 'Repository URL', 'wp-github-sync' ); ?>
                        <span class="required">*</span>
                    </label>
                    <div class="wp-github-sync-input-container">
                        <div class="wp-github-sync-input-wrapper">
                            <span class="wp-github-sync-input-icon"><span class="dashicons dashicons-admin-site"></span></span>
                            <input type="text" id="repo_url" name="wp_github_sync_settings[repo_url]" class="wp-github-sync-text-input" 
                                value="<?php echo esc_attr( isset( $settings['repo_url'] ) ? $settings['repo_url'] : '' ); ?>" 
                                placeholder="https://github.com/username/repository" />
                        </div>
                        <div class="wp-github-sync-tooltip">
                            <span class="dashicons dashicons-editor-help"></span>
                            <div class="wp-github-sync-tooltip-content">
                                <p><?php esc_html_e( 'Enter the full URL to your GitHub repository. This should be in the format https://github.com/username/repository', 'wp-github-sync' ); ?></p>
                                <p><?php esc_html_e( 'You must have write access to this repository for sync to work properly.', 'wp-github-sync' ); ?></p>
                            </div>
                        </div>
                    </div>
                    <p class="wp-github-sync-field-description"><?php esc_html_e( 'The complete URL to your GitHub repository.', 'wp-github-sync' ); ?></p>
                </div>
                
                <div class="wp-github-sync-field with-tooltip">
                    <label for="sync_branch" class="wp-github-sync-label"><?php esc_html_e( 'Branch', 'wp-github-sync' ); ?></label>
                    <div class="wp-github-sync-input-container">
                        <div class="wp-github-sync-input-wrapper">
                            <span class="wp-github-sync-input-icon"><span class="dashicons dashicons-randomize"></span></span>
                            <input type="text" id="sync_branch" name="wp_github_sync_settings[sync_branch]" class="wp-github-sync-text-input" 
                                value="<?php echo esc_attr( isset( $settings['sync_branch'] ) ? $settings['sync_branch'] : 'main' ); ?>" 
                                placeholder="main" />
                        </div>
                        <div class="wp-github-sync-tooltip">
                            <span class="dashicons dashicons-editor-help"></span>
                            <div class="wp-github-sync-tooltip-content">
                                <p><?php esc_html_e( 'Specify which branch to sync with. Most repositories use "main" or "master" as their default branch.', 'wp-github-sync' ); ?></p>
                                <p><?php esc_html_e( 'If left empty, the repository\'s default branch will be used.', 'wp-github-sync' ); ?></p>
                            </div>
                        </div>
                    </div>
                    <p class="wp-github-sync-field-description"><?php esc_html_e( 'The branch to sync with (e.g., main, master, develop).', 'wp-github-sync' ); ?></p>
                </div>
            </div>
        </div>
        
        <div class="wp-github-sync-settings-section">
            <div class="wp-github-sync-section-header">
                <h3><span class="dashicons dashicons-lock"></span> <?php esc_html_e( 'Authentication', 'wp-github-sync' ); ?></h3>
                <p class="wp-github-sync-section-description"><?php esc_html_e( 'Configure authentication method for GitHub API access', 'wp-github-sync' ); ?></p>
            </div>
            
            <div class="wp-github-sync-field-group">
                <div class="wp-github-sync-field with-tooltip">
                    <label class="wp-github-sync-label"><?php esc_html_e( 'Authentication Method', 'wp-github-sync' ); ?></label>
                    <div class="wp-github-sync-input-container">
                        <div class="wp-github-sync-toggle-tabs">
                            <div class="wp-github-sync-toggle-tab <?php echo (isset( $settings['auth_method'] ) && $settings['auth_method'] === 'pat' || !isset( $settings['auth_method'] )) ? 'active' : ''; ?>" data-method="pat">
                                <input type="radio" id="auth_method_pat" name="wp_github_sync_settings[auth_method]" value="pat" 
                                    <?php checked( isset( $settings['auth_method'] ) ? $settings['auth_method'] : 'pat', 'pat' ); ?> />
                                <label for="auth_method_pat"><?php esc_html_e( 'Personal Access Token', 'wp-github-sync' ); ?></label>
                            </div>
                            <div class="wp-github-sync-toggle-tab <?php echo (isset( $settings['auth_method'] ) && $settings['auth_method'] === 'oauth') ? 'active' : ''; ?>" data-method="oauth">
                                <input type="radio" id="auth_method_oauth" name="wp_github_sync_settings[auth_method]" value="oauth" 
                                    <?php checked( isset( $settings['auth_method'] ) ? $settings['auth_method'] : 'pat', 'oauth' ); ?> />
                                <label for="auth_method_oauth"><?php esc_html_e( 'OAuth Token', 'wp-github-sync' ); ?></label>
                            </div>
                            <div class="wp-github-sync-toggle-tab <?php echo (isset( $settings['auth_method'] ) && $settings['auth_method'] === 'github_app') ? 'active' : ''; ?>" data-method="github_app">
                                <input type="radio" id="auth_method_github_app" name="wp_github_sync_settings[auth_method]" value="github_app" 
                                    <?php checked( isset( $settings['auth_method'] ) ? $settings['auth_method'] : 'pat', 'github_app' ); ?> />
                                <label for="auth_method_github_app"><?php esc_html_e( 'GitHub App', 'wp-github-sync' ); ?></label>
                            </div>
                        </div>
                        <div class="wp-github-sync-tooltip">
                            <span class="dashicons dashicons-editor-help"></span>
                            <div class="wp-github-sync-tooltip-content">
                                <p><strong><?php esc_html_e( 'Personal Access Token (Recommended)', 'wp-github-sync' ); ?></strong></p>
                                <p><?php esc_html_e( 'Simplest method. Create a PAT in your GitHub account settings with "repo" scope permissions.', 'wp-github-sync' ); ?></p>
                                <p><strong><?php esc_html_e( 'OAuth Token', 'wp-github-sync' ); ?></strong></p>
                                <p><?php esc_html_e( 'Used for advanced integrations. Requires OAuth App configuration.', 'wp-github-sync' ); ?></p>
                                <p><strong><?php esc_html_e( 'GitHub App', 'wp-github-sync' ); ?></strong></p>
                                <p><?php esc_html_e( 'Most secure. Requires creating a GitHub App with repository permissions.', 'wp-github-sync' ); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- PAT Input -->
                <div class="wp-github-sync-auth-method-fields pat <?php echo (isset( $settings['auth_method'] ) && $settings['auth_method'] === 'pat' || !isset( $settings['auth_method'] )) ? 'active' : ''; ?>">
                    <div class="wp-github-sync-field with-tooltip">
                        <label for="access_token" class="wp-github-sync-label">
                            <?php esc_html_e( 'Personal Access Token', 'wp-github-sync' ); ?>
                            <span class="required">*</span>
                        </label>
                        <div class="wp-github-sync-input-container">
                            <div class="wp-github-sync-input-wrapper password">
                                <span class="wp-github-sync-input-icon"><span class="dashicons dashicons-key"></span></span>
                                <input type="password" id="access_token" name="wp_github_sync_settings[access_token]" class="wp-github-sync-text-input" 
                                    value="<?php echo esc_attr( isset( $settings['access_token'] ) ? $settings['access_token'] : '' ); ?>" />
                                <button type="button" class="wp-github-sync-toggle-password" aria-label="<?php esc_attr_e( 'Toggle password visibility', 'wp-github-sync' ); ?>">
                                    <span class="dashicons dashicons-visibility"></span>
                                </button>
                            </div>
                            <div class="wp-github-sync-tooltip">
                                <span class="dashicons dashicons-editor-help"></span>
                                <div class="wp-github-sync-tooltip-content">
                                    <p><?php esc_html_e( 'Create a Personal Access Token with "repo" scope permission in your GitHub account settings.', 'wp-github-sync' ); ?></p>
                                    <p><a href="https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/creating-a-personal-access-token" target="_blank"><?php esc_html_e( 'Learn how to create a token', 'wp-github-sync' ); ?></a></p>
                                </div>
                            </div>
                        </div>
                        <p class="wp-github-sync-field-description">
                            <?php esc_html_e( 'GitHub Personal Access Token with repository permissions.', 'wp-github-sync' ); ?>
                            <a href="https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/creating-a-personal-access-token" target="_blank" class="wp-github-sync-learn-more">
                                <?php esc_html_e( 'Learn more', 'wp-github-sync' ); ?>
                            </a>
                        </p>
                    </div>
                </div>
                
                <!-- OAuth Input -->
                <div class="wp-github-sync-auth-method-fields oauth <?php echo (isset( $settings['auth_method'] ) && $settings['auth_method'] === 'oauth') ? 'active' : ''; ?>">
                    <div class="wp-github-sync-field with-tooltip">
                        <label for="oauth_token" class="wp-github-sync-label">
                            <?php esc_html_e( 'OAuth Token', 'wp-github-sync' ); ?>
                            <span class="required">*</span>
                        </label>
                        <div class="wp-github-sync-input-container">
                            <div class="wp-github-sync-input-wrapper password">
                                <span class="wp-github-sync-input-icon"><span class="dashicons dashicons-admin-network"></span></span>
                                <input type="password" id="oauth_token" name="wp_github_sync_settings[oauth_token]" class="wp-github-sync-text-input" 
                                    value="<?php echo esc_attr( isset( $settings['oauth_token'] ) ? $settings['oauth_token'] : '' ); ?>" />
                                <button type="button" class="wp-github-sync-toggle-password" aria-label="<?php esc_attr_e( 'Toggle password visibility', 'wp-github-sync' ); ?>">
                                    <span class="dashicons dashicons-visibility"></span>
                                </button>
                            </div>
                            <div class="wp-github-sync-tooltip">
                                <span class="dashicons dashicons-editor-help"></span>
                                <div class="wp-github-sync-tooltip-content">
                                    <p><?php esc_html_e( 'Enter your OAuth token generated from a GitHub OAuth application with proper repository permissions.', 'wp-github-sync' ); ?></p>
                                    <p><?php esc_html_e( 'OAuth tokens typically begin with "gho_".', 'wp-github-sync' ); ?></p>
                                </div>
                            </div>
                        </div>
                        <p class="wp-github-sync-field-description">
                            <?php esc_html_e( 'GitHub OAuth token for your registered OAuth application.', 'wp-github-sync' ); ?>
                            <a href="https://docs.github.com/en/developers/apps/building-oauth-apps" target="_blank" class="wp-github-sync-learn-more">
                                <?php esc_html_e( 'Learn more', 'wp-github-sync' ); ?>
                            </a>
                        </p>
                    </div>
                </div>
                
                <!-- GitHub App Input -->
                <div class="wp-github-sync-auth-method-fields github_app <?php echo (isset( $settings['auth_method'] ) && $settings['auth_method'] === 'github_app') ? 'active' : ''; ?>">
                    <div class="wp-github-sync-field with-tooltip">
                        <label for="github_app_id" class="wp-github-sync-label">
                            <?php esc_html_e( 'GitHub App ID', 'wp-github-sync' ); ?>
                            <span class="required">*</span>
                        </label>
                        <div class="wp-github-sync-input-container">
                            <div class="wp-github-sync-input-wrapper">
                                <span class="wp-github-sync-input-icon"><span class="dashicons dashicons-id"></span></span>
                                <input type="text" id="github_app_id" name="wp_github_sync_settings[github_app_id]" class="wp-github-sync-text-input" 
                                    value="<?php echo esc_attr( isset( $settings['github_app_id'] ) ? $settings['github_app_id'] : '' ); ?>" />
                            </div>
                            <div class="wp-github-sync-tooltip">
                                <span class="dashicons dashicons-editor-help"></span>
                                <div class="wp-github-sync-tooltip-content">
                                    <p><?php esc_html_e( 'The GitHub App ID from your registered GitHub App.', 'wp-github-sync' ); ?></p>
                                    <p><?php esc_html_e( 'This is a numeric identifier shown in the app settings.', 'wp-github-sync' ); ?></p>
                                </div>
                            </div>
                        </div>
                        <p class="wp-github-sync-field-description"><?php esc_html_e( 'Numeric identifier for your GitHub App.', 'wp-github-sync' ); ?></p>
                    </div>
                    
                    <div class="wp-github-sync-field with-tooltip">
                        <label for="github_app_installation_id" class="wp-github-sync-label">
                            <?php esc_html_e( 'Installation ID', 'wp-github-sync' ); ?>
                            <span class="required">*</span>
                        </label>
                        <div class="wp-github-sync-input-container">
                            <div class="wp-github-sync-input-wrapper">
                                <span class="wp-github-sync-input-icon"><span class="dashicons dashicons-admin-plugins"></span></span>
                                <input type="text" id="github_app_installation_id" name="wp_github_sync_settings[github_app_installation_id]" class="wp-github-sync-text-input" 
                                    value="<?php echo esc_attr( isset( $settings['github_app_installation_id'] ) ? $settings['github_app_installation_id'] : '' ); ?>" />
                            </div>
                            <div class="wp-github-sync-tooltip">
                                <span class="dashicons dashicons-editor-help"></span>
                                <div class="wp-github-sync-tooltip-content">
                                    <p><?php esc_html_e( 'The installation ID for your GitHub App, which links the app to a specific user or organization.', 'wp-github-sync' ); ?></p>
                                    <p><?php esc_html_e( 'You can find this in the URL of your GitHub App installation or in the installation settings.', 'wp-github-sync' ); ?></p>
                                </div>
                            </div>
                        </div>
                        <p class="wp-github-sync-field-description"><?php esc_html_e( 'The ID for this specific installation of your GitHub App.', 'wp-github-sync' ); ?></p>
                    </div>
                    
                    <div class="wp-github-sync-field with-tooltip">
                        <label for="github_app_key" class="wp-github-sync-label">
                            <?php esc_html_e( 'Private Key', 'wp-github-sync' ); ?>
                            <span class="required">*</span>
                        </label>
                        <div class="wp-github-sync-input-container">
                            <div class="wp-github-sync-input-wrapper">
                                <span class="wp-github-sync-input-icon"><span class="dashicons dashicons-shield"></span></span>
                                <textarea id="github_app_key" name="wp_github_sync_settings[github_app_key]" class="wp-github-sync-textarea" rows="6"><?php echo esc_textarea( isset( $settings['github_app_key'] ) ? $settings['github_app_key'] : '' ); ?></textarea>
                            </div>
                            <div class="wp-github-sync-tooltip">
                                <span class="dashicons dashicons-editor-help"></span>
                                <div class="wp-github-sync-tooltip-content">
                                    <p><?php esc_html_e( 'Paste the entire private key file content including the BEGIN and END markers.', 'wp-github-sync' ); ?></p>
                                    <p><?php esc_html_e( 'This key is generated when you create a GitHub App and is used to sign JWT tokens for authentication.', 'wp-github-sync' ); ?></p>
                                </div>
                            </div>
                        </div>
                        <p class="wp-github-sync-field-description"><?php esc_html_e( 'The private key generated for your GitHub App.', 'wp-github-sync' ); ?></p>
                    </div>
                </div>
                
                <div class="wp-github-sync-connection-test">
                    <button type="button" class="wp-github-sync-button secondary wp-github-sync-connection-test-button" id="connection-test-button">
                        <span class="dashicons dashicons-update"></span> 
                        <?php esc_html_e( 'Test Connection', 'wp-github-sync' ); ?>
                    </button>
                    <div id="connection-test-result" class="wp-github-sync-connection-test-result"></div>
                </div>
            </div>
        </div>
        
        <div class="wp-github-sync-settings-section">
            <div class="wp-github-sync-section-header">
                <h3><span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Sync Settings', 'wp-github-sync' ); ?></h3>
                <p class="wp-github-sync-section-description"><?php esc_html_e( 'Configure automatic synchronization options', 'wp-github-sync' ); ?></p>
            </div>
            
            <div class="wp-github-sync-field-group">
                <div class="wp-github-sync-field with-toggle">
                    <div class="wp-github-sync-toggle-wrapper">
                        <label class="wp-github-sync-label"><?php esc_html_e( 'Automatic Sync', 'wp-github-sync' ); ?></label>
                        <div class="wp-github-sync-toggle-switch">
                            <input type="checkbox" id="auto_sync" name="wp_github_sync_settings[auto_sync]" value="1" 
                                <?php checked( isset( $settings['auto_sync'] ) ? $settings['auto_sync'] : '', 1 ); ?> />
                            <label for="auto_sync" class="wp-github-sync-toggle-slider"></label>
                        </div>
                    </div>
                    <p class="wp-github-sync-field-description"><?php esc_html_e( 'Automatically check for and sync updates from GitHub on a schedule.', 'wp-github-sync' ); ?></p>
                </div>
                
                <div class="wp-github-sync-field with-tooltip" id="sync_interval_container" <?php echo (isset( $settings['auto_sync'] ) && $settings['auto_sync'] == 1) ? '' : 'style="display:none;"'; ?>>
                    <label for="sync_interval" class="wp-github-sync-label"><?php esc_html_e( 'Sync Interval', 'wp-github-sync' ); ?></label>
                    <div class="wp-github-sync-input-container">
                        <div class="wp-github-sync-input-wrapper">
                            <span class="wp-github-sync-input-icon"><span class="dashicons dashicons-calendar-alt"></span></span>
                            <select id="sync_interval" name="wp_github_sync_settings[sync_interval]" class="wp-github-sync-select">
                                <option value="hourly" <?php selected( isset( $settings['sync_interval'] ) ? $settings['sync_interval'] : 'daily', 'hourly' ); ?>><?php esc_html_e( 'Hourly', 'wp-github-sync' ); ?></option>
                                <option value="twicedaily" <?php selected( isset( $settings['sync_interval'] ) ? $settings['sync_interval'] : 'daily', 'twicedaily' ); ?>><?php esc_html_e( 'Twice Daily', 'wp-github-sync' ); ?></option>
                                <option value="daily" <?php selected( isset( $settings['sync_interval'] ) ? $settings['sync_interval'] : 'daily', 'daily' ); ?>><?php esc_html_e( 'Daily', 'wp-github-sync' ); ?></option>
                                <option value="weekly" <?php selected( isset( $settings['sync_interval'] ) ? $settings['sync_interval'] : 'daily', 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'wp-github-sync' ); ?></option>
                            </select>
                        </div>
                        <div class="wp-github-sync-tooltip">
                            <span class="dashicons dashicons-editor-help"></span>
                            <div class="wp-github-sync-tooltip-content">
                                <p><?php esc_html_e( 'Specifies how often your WordPress site will check GitHub for updates.', 'wp-github-sync' ); ?></p>
                                <p><?php esc_html_e( 'More frequent checks provide faster updates but may impact performance.', 'wp-github-sync' ); ?></p>
                            </div>
                        </div>
                    </div>
                    <p class="wp-github-sync-field-description"><?php esc_html_e( 'How often WordPress should check for updates from GitHub.', 'wp-github-sync' ); ?></p>
                </div>
                
                <div class="wp-github-sync-field with-toggle" id="auto_deploy_container" <?php echo (isset( $settings['auto_sync'] ) && $settings['auto_sync'] == 1) ? '' : 'style="display:none;"'; ?>>
                    <div class="wp-github-sync-toggle-wrapper">
                        <label class="wp-github-sync-label" for="auto_deploy"><?php esc_html_e( 'Auto Deploy Updates', 'wp-github-sync' ); ?></label>
                        <div class="wp-github-sync-toggle-switch">
                            <input type="checkbox" id="auto_deploy" name="wp_github_sync_settings[auto_deploy]" value="1" 
                                <?php checked( isset( $settings['auto_deploy'] ) ? $settings['auto_deploy'] : '', 1 ); ?> />
                            <label for="auto_deploy" class="wp-github-sync-toggle-slider"></label>
                        </div>
                    </div>
                    <p class="wp-github-sync-field-description wp-github-sync-warning">
                        <span class="dashicons dashicons-warning"></span>
                        <?php esc_html_e( 'Automatically deploy updates when found without requiring manual approval.', 'wp-github-sync' ); ?>
                    </p>
                </div>
            </div>
        </div>
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
        <div class="wp-github-sync-settings-section">
            <div class="wp-github-sync-section-header">
                <h3><span class="dashicons dashicons-backup"></span> <?php esc_html_e( 'Backup Settings', 'wp-github-sync' ); ?></h3>
                <p class="wp-github-sync-section-description"><?php esc_html_e( 'Configure automatic backups and backup content options', 'wp-github-sync' ); ?></p>
            </div>
            
            <div class="wp-github-sync-field-group">
                <div class="wp-github-sync-field with-toggle with-tooltip">
                    <div class="wp-github-sync-toggle-wrapper">
                        <label class="wp-github-sync-label" for="auto_backup">
                            <?php esc_html_e( 'Create Automatic Backups', 'wp-github-sync' ); ?>
                        </label>
                        <div class="wp-github-sync-toggle-switch">
                            <input type="checkbox" id="auto_backup" name="wp_github_sync_settings[auto_backup]" value="1" 
                                <?php checked( isset( $settings['auto_backup'] ) ? $settings['auto_backup'] : '1', 1 ); ?> />
                            <label for="auto_backup" class="wp-github-sync-toggle-slider"></label>
                        </div>
                        <div class="wp-github-sync-tooltip">
                            <span class="dashicons dashicons-editor-help"></span>
                            <div class="wp-github-sync-tooltip-content">
                                <p><?php esc_html_e( 'When enabled, the plugin will automatically create a backup before syncing changes from GitHub.', 'wp-github-sync' ); ?></p>
                                <p><?php esc_html_e( 'This allows you to restore your site if something goes wrong during the sync process.', 'wp-github-sync' ); ?></p>
                            </div>
                        </div>
                    </div>
                    <p class="wp-github-sync-field-description">
                        <?php esc_html_e( 'Creates a backup of your site before applying any updates from GitHub.', 'wp-github-sync' ); ?>
                    </p>
                </div>
                
                <div id="backup-options-container" <?php echo (isset( $settings['auto_backup'] ) && $settings['auto_backup'] == 0) ? 'style="display:none;"' : ''; ?>>
                    <div class="wp-github-sync-field-grid">
                        <div class="wp-github-sync-field with-toggle with-tooltip">
                            <div class="wp-github-sync-toggle-wrapper">
                                <label class="wp-github-sync-label" for="backup_themes">
                                    <?php esc_html_e( 'Backup Themes', 'wp-github-sync' ); ?>
                                </label>
                                <div class="wp-github-sync-toggle-switch">
                                    <input type="checkbox" id="backup_themes" name="wp_github_sync_settings[backup_themes]" value="1" 
                                        <?php checked( isset( $settings['backup_themes'] ) ? $settings['backup_themes'] : '1', 1 ); ?> />
                                    <label for="backup_themes" class="wp-github-sync-toggle-slider"></label>
                                </div>
                                <div class="wp-github-sync-tooltip">
                                    <span class="dashicons dashicons-editor-help"></span>
                                    <div class="wp-github-sync-tooltip-content">
                                        <p><?php esc_html_e( 'Include all theme files in the wp-content/themes directory when creating backups.', 'wp-github-sync' ); ?></p>
                                        <p><?php esc_html_e( 'This ensures you can restore your theme files if they\'re modified during sync.', 'wp-github-sync' ); ?></p>
                                    </div>
                                </div>
                            </div>
                            <p class="wp-github-sync-field-description"><?php esc_html_e( 'Include WordPress themes in backups.', 'wp-github-sync' ); ?></p>
                        </div>
                        
                        <div class="wp-github-sync-field with-toggle with-tooltip">
                            <div class="wp-github-sync-toggle-wrapper">
                                <label class="wp-github-sync-label" for="backup_plugins">
                                    <?php esc_html_e( 'Backup Plugins', 'wp-github-sync' ); ?>
                                </label>
                                <div class="wp-github-sync-toggle-switch">
                                    <input type="checkbox" id="backup_plugins" name="wp_github_sync_settings[backup_plugins]" value="1" 
                                        <?php checked( isset( $settings['backup_plugins'] ) ? $settings['backup_plugins'] : '1', 1 ); ?> />
                                    <label for="backup_plugins" class="wp-github-sync-toggle-slider"></label>
                                </div>
                                <div class="wp-github-sync-tooltip">
                                    <span class="dashicons dashicons-editor-help"></span>
                                    <div class="wp-github-sync-tooltip-content">
                                        <p><?php esc_html_e( 'Include all plugin files in the wp-content/plugins directory when creating backups.', 'wp-github-sync' ); ?></p>
                                        <p><?php esc_html_e( 'This ensures you can restore your plugins if they\'re modified during sync.', 'wp-github-sync' ); ?></p>
                                    </div>
                                </div>
                            </div>
                            <p class="wp-github-sync-field-description"><?php esc_html_e( 'Include WordPress plugins in backups.', 'wp-github-sync' ); ?></p>
                        </div>
                    </div>
                    
                    <div class="wp-github-sync-field-grid">
                        <div class="wp-github-sync-field with-toggle with-tooltip">
                            <div class="wp-github-sync-toggle-wrapper">
                                <label class="wp-github-sync-label" for="backup_uploads">
                                    <?php esc_html_e( 'Backup Uploads', 'wp-github-sync' ); ?>
                                </label>
                                <div class="wp-github-sync-toggle-switch">
                                    <input type="checkbox" id="backup_uploads" name="wp_github_sync_settings[backup_uploads]" value="1" 
                                        <?php checked( isset( $settings['backup_uploads'] ) ? $settings['backup_uploads'] : '0', 1 ); ?> />
                                    <label for="backup_uploads" class="wp-github-sync-toggle-slider"></label>
                                </div>
                                <div class="wp-github-sync-tooltip">
                                    <span class="dashicons dashicons-editor-help"></span>
                                    <div class="wp-github-sync-tooltip-content">
                                        <p><?php esc_html_e( 'Include uploads directory in backups.', 'wp-github-sync' ); ?></p>
                                        <p class="wp-github-sync-warning-text"><?php esc_html_e( 'Warning: This may significantly increase backup size and time if you have many media files.', 'wp-github-sync' ); ?></p>
                                    </div>
                                </div>
                            </div>
                            <p class="wp-github-sync-field-description wp-github-sync-warning">
                                <span class="dashicons dashicons-warning"></span>
                                <?php esc_html_e( 'Include media uploads in backups (increases backup size).', 'wp-github-sync' ); ?>
                            </p>
                        </div>
                        
                        <div class="wp-github-sync-field with-toggle with-tooltip">
                            <div class="wp-github-sync-toggle-wrapper">
                                <label class="wp-github-sync-label" for="backup_config">
                                    <?php esc_html_e( 'Backup wp-config.php', 'wp-github-sync' ); ?>
                                </label>
                                <div class="wp-github-sync-toggle-switch">
                                    <input type="checkbox" id="backup_config" name="wp_github_sync_settings[backup_config]" value="1" 
                                        <?php checked( isset( $settings['backup_config'] ) ? $settings['backup_config'] : '0', 1 ); ?> />
                                    <label for="backup_config" class="wp-github-sync-toggle-slider"></label>
                                </div>
                                <div class="wp-github-sync-tooltip">
                                    <span class="dashicons dashicons-editor-help"></span>
                                    <div class="wp-github-sync-tooltip-content">
                                        <p><?php esc_html_e( 'Include wp-config.php file in backups.', 'wp-github-sync' ); ?></p>
                                        <p class="wp-github-sync-warning-text"><?php esc_html_e( 'Warning: wp-config.php contains sensitive information like database credentials.', 'wp-github-sync' ); ?></p>
                                        <p><?php esc_html_e( 'Only enable this if you store backups in a secure location.', 'wp-github-sync' ); ?></p>
                                    </div>
                                </div>
                            </div>
                            <p class="wp-github-sync-field-description wp-github-sync-warning">
                                <span class="dashicons dashicons-warning"></span>
                                <?php esc_html_e( 'Include configuration file with sensitive data.', 'wp-github-sync' ); ?>
                            </p>
                        </div>
                    </div>
                    
                    <div class="wp-github-sync-field with-tooltip">
                        <label for="max_backups" class="wp-github-sync-label"><?php esc_html_e( 'Maximum Backups', 'wp-github-sync' ); ?></label>
                        <div class="wp-github-sync-input-container">
                            <div class="wp-github-sync-input-wrapper number">
                                <span class="wp-github-sync-input-icon"><span class="dashicons dashicons-archive"></span></span>
                                <input type="number" id="max_backups" name="wp_github_sync_settings[max_backups]" class="wp-github-sync-number-input" 
                                    value="<?php echo esc_attr( isset( $settings['max_backups'] ) ? $settings['max_backups'] : '10' ); ?>" min="1" max="100" />
                            </div>
                            <div class="wp-github-sync-tooltip">
                                <span class="dashicons dashicons-editor-help"></span>
                                <div class="wp-github-sync-tooltip-content">
                                    <p><?php esc_html_e( 'The maximum number of backup files to keep on your server.', 'wp-github-sync' ); ?></p>
                                    <p><?php esc_html_e( 'When this limit is reached, the oldest backups will be automatically deleted.', 'wp-github-sync' ); ?></p>
                                    <p><?php esc_html_e( 'Set this based on your server storage capacity and how many restore points you want to maintain.', 'wp-github-sync' ); ?></p>
                                </div>
                            </div>
                        </div>
                        <p class="wp-github-sync-field-description"><?php esc_html_e( 'Maximum number of backup files to keep.', 'wp-github-sync' ); ?></p>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="wp-github-sync-settings-section">
            <div class="wp-github-sync-section-header">
                <h3><span class="dashicons dashicons-shield"></span> <?php esc_html_e( 'Security & Recovery', 'wp-github-sync' ); ?></h3>
                <p class="wp-github-sync-section-description"><?php esc_html_e( 'Configure options for secure deployments and error recovery', 'wp-github-sync' ); ?></p>
            </div>
            
            <div class="wp-github-sync-field-group">
                <div class="wp-github-sync-field with-toggle with-tooltip">
                    <div class="wp-github-sync-toggle-wrapper">
                        <label class="wp-github-sync-label" for="maintenance_mode">
                            <?php esc_html_e( 'Maintenance Mode', 'wp-github-sync' ); ?>
                        </label>
                        <div class="wp-github-sync-toggle-switch">
                            <input type="checkbox" id="maintenance_mode" name="wp_github_sync_settings[maintenance_mode]" value="1" 
                                <?php checked( isset( $settings['maintenance_mode'] ) ? $settings['maintenance_mode'] : '1', 1 ); ?> />
                            <label for="maintenance_mode" class="wp-github-sync-toggle-slider"></label>
                        </div>
                        <div class="wp-github-sync-tooltip">
                            <span class="dashicons dashicons-editor-help"></span>
                            <div class="wp-github-sync-tooltip-content">
                                <p><?php esc_html_e( 'When enabled, your site will temporarily display a maintenance page during deployments.', 'wp-github-sync' ); ?></p>
                                <p><?php esc_html_e( 'This prevents users from accessing the site while files are being updated, which could cause errors.', 'wp-github-sync' ); ?></p>
                            </div>
                        </div>
                    </div>
                    <p class="wp-github-sync-field-description">
                        <?php esc_html_e( 'Activate maintenance mode during deployments to prevent user access.', 'wp-github-sync' ); ?>
                    </p>
                </div>
                
                <div class="wp-github-sync-field with-toggle with-tooltip">
                    <div class="wp-github-sync-toggle-wrapper">
                        <label class="wp-github-sync-label" for="auto_rollback">
                            <?php esc_html_e( 'Automatic Rollback', 'wp-github-sync' ); ?>
                        </label>
                        <div class="wp-github-sync-toggle-switch">
                            <input type="checkbox" id="auto_rollback" name="wp_github_sync_settings[auto_rollback]" value="1" 
                                <?php checked( isset( $settings['auto_rollback'] ) ? $settings['auto_rollback'] : '1', 1 ); ?> />
                            <label for="auto_rollback" class="wp-github-sync-toggle-slider"></label>
                        </div>
                        <div class="wp-github-sync-tooltip">
                            <span class="dashicons dashicons-editor-help"></span>
                            <div class="wp-github-sync-tooltip-content">
                                <p><?php esc_html_e( 'Automatically roll back to the previous version if deployment fails or causes errors.', 'wp-github-sync' ); ?></p>
                                <p><?php esc_html_e( 'This helps prevent your site from going down due to deployment issues.', 'wp-github-sync' ); ?></p>
                            </div>
                        </div>
                    </div>
                    <p class="wp-github-sync-field-description">
                        <?php esc_html_e( 'Automatically restore to the previous version if a deployment fails.', 'wp-github-sync' ); ?>
                    </p>
                </div>
                
                <div class="wp-github-sync-field with-toggle with-tooltip">
                    <div class="wp-github-sync-toggle-wrapper">
                        <label class="wp-github-sync-label" for="notify_updates">
                            <?php esc_html_e( 'Email Notifications', 'wp-github-sync' ); ?>
                        </label>
                        <div class="wp-github-sync-toggle-switch">
                            <input type="checkbox" id="notify_updates" name="wp_github_sync_settings[notify_updates]" value="1" 
                                <?php checked( isset( $settings['notify_updates'] ) ? $settings['notify_updates'] : '0', 1 ); ?> />
                            <label for="notify_updates" class="wp-github-sync-toggle-slider"></label>
                        </div>
                        <div class="wp-github-sync-tooltip">
                            <span class="dashicons dashicons-editor-help"></span>
                            <div class="wp-github-sync-tooltip-content">
                                <p><?php esc_html_e( 'Send email notifications to site administrators when updates are available or when deployments succeed or fail.', 'wp-github-sync' ); ?></p>
                                <p><?php esc_html_e( 'Emails will be sent to the admin email address configured in WordPress.', 'wp-github-sync' ); ?></p>
                            </div>
                        </div>
                    </div>
                    <p class="wp-github-sync-field-description">
                        <?php esc_html_e( 'Send email notifications about updates and deployment status.', 'wp-github-sync' ); ?>
                    </p>
                </div>
            </div>
        </div>
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
        <div class="wp-github-sync-settings-section">
            <div class="wp-github-sync-section-header">
                <h3><span class="dashicons dashicons-admin-links"></span> <?php esc_html_e( 'Webhook Integration', 'wp-github-sync' ); ?></h3>
                <p class="wp-github-sync-section-description"><?php esc_html_e( 'Configure GitHub webhooks for automatic deployments when code is pushed', 'wp-github-sync' ); ?></p>
            </div>
            
            <div class="wp-github-sync-field-group">
                <div class="wp-github-sync-field with-toggle with-tooltip">
                    <div class="wp-github-sync-toggle-wrapper">
                        <label class="wp-github-sync-label" for="webhook_sync">
                            <?php esc_html_e( 'Enable Webhook Sync', 'wp-github-sync' ); ?>
                        </label>
                        <div class="wp-github-sync-toggle-switch">
                            <input type="checkbox" id="webhook_sync" name="wp_github_sync_settings[webhook_sync]" value="1" 
                                <?php checked( isset( $settings['webhook_sync'] ) ? $settings['webhook_sync'] : '0', 1 ); ?> />
                            <label for="webhook_sync" class="wp-github-sync-toggle-slider"></label>
                        </div>
                        <div class="wp-github-sync-tooltip">
                            <span class="dashicons dashicons-editor-help"></span>
                            <div class="wp-github-sync-tooltip-content">
                                <p><?php esc_html_e( 'When enabled, GitHub can trigger automatic synchronization of your site when changes are pushed to the repository.', 'wp-github-sync' ); ?></p>
                                <p><?php esc_html_e( 'This creates a true CI/CD pipeline between your GitHub repository and WordPress site.', 'wp-github-sync' ); ?></p>
                            </div>
                        </div>
                    </div>
                    <p class="wp-github-sync-field-description">
                        <?php esc_html_e( 'Automatically deploy updates when changes are pushed to GitHub.', 'wp-github-sync' ); ?>
                    </p>
                </div>
                
                <div id="webhook-settings-container" <?php echo (isset( $settings['webhook_sync'] ) && $settings['webhook_sync'] == 0) ? 'style="display:none;"' : ''; ?>>
                    <div class="wp-github-sync-field with-tooltip">
                        <label for="webhook_url" class="wp-github-sync-label">
                            <?php esc_html_e( 'Webhook URL', 'wp-github-sync' ); ?>
                            <span class="required">*</span>
                        </label>
                        <div class="wp-github-sync-input-container">
                            <div class="wp-github-sync-input-wrapper">
                                <span class="wp-github-sync-input-icon"><span class="dashicons dashicons-admin-links"></span></span>
                                <input type="text" id="webhook_url" class="wp-github-sync-text-input" 
                                    value="<?php echo esc_url( rest_url( 'wp-github-sync/v1/webhook' ) ); ?>" readonly />
                                <button type="button" class="wp-github-sync-copy-button" data-clipboard-target="#webhook_url">
                                    <span class="dashicons dashicons-clipboard"></span>
                                </button>
                            </div>
                            <div class="wp-github-sync-tooltip">
                                <span class="dashicons dashicons-editor-help"></span>
                                <div class="wp-github-sync-tooltip-content">
                                    <p><?php esc_html_e( 'Use this URL when setting up the webhook in your GitHub repository.', 'wp-github-sync' ); ?></p>
                                    <ol>
                                        <li><?php esc_html_e( 'In your GitHub repository, go to Settings > Webhooks > Add webhook', 'wp-github-sync' ); ?></li>
                                        <li><?php esc_html_e( 'Enter this URL in the "Payload URL" field', 'wp-github-sync' ); ?></li>
                                        <li><?php esc_html_e( 'Set content type to "application/json"', 'wp-github-sync' ); ?></li>
                                        <li><?php esc_html_e( 'Enter the secret key in the "Secret" field', 'wp-github-sync' ); ?></li>
                                        <li><?php esc_html_e( 'Choose which events trigger the webhook (usually "Push events")', 'wp-github-sync' ); ?></li>
                                    </ol>
                                </div>
                            </div>
                        </div>
                        <p class="wp-github-sync-field-description">
                            <?php esc_html_e( 'URL to use in GitHub webhook settings (click button to copy).', 'wp-github-sync' ); ?>
                        </p>
                    </div>
                    
                    <div class="wp-github-sync-field with-tooltip">
                        <label for="webhook_secret" class="wp-github-sync-label">
                            <?php esc_html_e( 'Webhook Secret', 'wp-github-sync' ); ?>
                            <span class="required">*</span>
                        </label>
                        <div class="wp-github-sync-input-container">
                            <div class="wp-github-sync-input-wrapper password">
                                <span class="wp-github-sync-input-icon"><span class="dashicons dashicons-shield"></span></span>
                                <?php 
                                // Generate secret if not exists
                                if (empty($settings['webhook_secret'])) {
                                    $webhook_secret = bin2hex(random_bytes(16));
                                } else {
                                    $webhook_secret = $settings['webhook_secret'];
                                }
                                ?>
                                <input type="password" id="webhook_secret" name="wp_github_sync_settings[webhook_secret]" class="wp-github-sync-text-input" 
                                    value="<?php echo esc_attr($webhook_secret); ?>" />
                                <button type="button" class="wp-github-sync-toggle-password" aria-label="<?php esc_attr_e( 'Toggle secret visibility', 'wp-github-sync' ); ?>">
                                    <span class="dashicons dashicons-visibility"></span>
                                </button>
                                <button type="button" class="wp-github-sync-copy-button" data-clipboard-target="#webhook_secret">
                                    <span class="dashicons dashicons-clipboard"></span>
                                </button>
                            </div>
                            <div class="wp-github-sync-tooltip">
                                <span class="dashicons dashicons-editor-help"></span>
                                <div class="wp-github-sync-tooltip-content">
                                    <p><?php esc_html_e( 'This secret key is used to verify that webhook requests are coming from GitHub.', 'wp-github-sync' ); ?></p>
                                    <p><?php esc_html_e( 'You must enter the same secret in your GitHub webhook settings.', 'wp-github-sync' ); ?></p>
                                    <p><?php esc_html_e( 'Keep this value secure and treat it like a password.', 'wp-github-sync' ); ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="wp-github-sync-button-group">
                            <button type="button" class="wp-github-sync-button secondary wp-github-sync-regenerate-button" id="regenerate-webhook-secret">
                                <span class="dashicons dashicons-update"></span>
                                <?php esc_html_e( 'Generate New Secret', 'wp-github-sync' ); ?>
                            </button>
                        </div>
                        <p class="wp-github-sync-field-description">
                            <?php esc_html_e( 'Secret key to validate webhook requests from GitHub.', 'wp-github-sync' ); ?>
                        </p>
                    </div>
                    
                    <div class="wp-github-sync-field-grid">
                        <div class="wp-github-sync-field with-toggle with-tooltip">
                            <div class="wp-github-sync-toggle-wrapper">
                                <label class="wp-github-sync-label" for="webhook_auto_deploy">
                                    <?php esc_html_e( 'Auto Deploy', 'wp-github-sync' ); ?>
                                </label>
                                <div class="wp-github-sync-toggle-switch">
                                    <input type="checkbox" id="webhook_auto_deploy" name="wp_github_sync_settings[webhook_auto_deploy]" value="1" 
                                        <?php checked( isset( $settings['webhook_auto_deploy'] ) ? $settings['webhook_auto_deploy'] : '1', 1 ); ?> />
                                    <label for="webhook_auto_deploy" class="wp-github-sync-toggle-slider"></label>
                                </div>
                                <div class="wp-github-sync-tooltip">
                                    <span class="dashicons dashicons-editor-help"></span>
                                    <div class="wp-github-sync-tooltip-content">
                                        <p><?php esc_html_e( 'Automatically deploy changes when a webhook is received.', 'wp-github-sync' ); ?></p>
                                        <p><?php esc_html_e( 'If disabled, webhooks will only notify you of updates but won\'t deploy them.', 'wp-github-sync' ); ?></p>
                                    </div>
                                </div>
                            </div>
                            <p class="wp-github-sync-field-description wp-github-sync-warning">
                                <span class="dashicons dashicons-warning"></span>
                                <?php esc_html_e( 'Automatically deploy updates without manual approval.', 'wp-github-sync' ); ?>
                            </p>
                        </div>
                        
                        <div class="wp-github-sync-field with-toggle with-tooltip">
                            <div class="wp-github-sync-toggle-wrapper">
                                <label class="wp-github-sync-label" for="webhook_specific_branch">
                                    <?php esc_html_e( 'Branch Filter', 'wp-github-sync' ); ?>
                                </label>
                                <div class="wp-github-sync-toggle-switch">
                                    <input type="checkbox" id="webhook_specific_branch" name="wp_github_sync_settings[webhook_specific_branch]" value="1" 
                                        <?php checked( isset( $settings['webhook_specific_branch'] ) ? $settings['webhook_specific_branch'] : '1', 1 ); ?> />
                                    <label for="webhook_specific_branch" class="wp-github-sync-toggle-slider"></label>
                                </div>
                                <div class="wp-github-sync-tooltip">
                                    <span class="dashicons dashicons-editor-help"></span>
                                    <div class="wp-github-sync-tooltip-content">
                                        <p><?php esc_html_e( 'Only respond to webhooks for your configured branch.', 'wp-github-sync' ); ?></p>
                                        <p><?php esc_html_e( 'This allows you to set up the same webhook for multiple branches without triggering deployments for each push.', 'wp-github-sync' ); ?></p>
                                    </div>
                                </div>
                            </div>
                            <p class="wp-github-sync-field-description">
                                <?php esc_html_e( 'Only process webhooks from your configured branch.', 'wp-github-sync' ); ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="wp-github-sync-settings-section">
            <div class="wp-github-sync-section-header">
                <h3><span class="dashicons dashicons-admin-tools"></span> <?php esc_html_e( 'Advanced Settings', 'wp-github-sync' ); ?></h3>
                <p class="wp-github-sync-section-description"><?php esc_html_e( 'Configure debug options and synchronization behavior', 'wp-github-sync' ); ?></p>
            </div>
            
            <div class="wp-github-sync-field-group">
                <div class="wp-github-sync-field with-toggle with-tooltip">
                    <div class="wp-github-sync-toggle-wrapper">
                        <label class="wp-github-sync-label" for="delete_removed">
                            <?php esc_html_e( 'Delete Removed Files', 'wp-github-sync' ); ?>
                        </label>
                        <div class="wp-github-sync-toggle-switch">
                            <input type="checkbox" id="delete_removed" name="wp_github_sync_settings[delete_removed]" value="1" 
                                <?php checked( isset( $settings['delete_removed'] ) ? $settings['delete_removed'] : '1', 1 ); ?> />
                            <label for="delete_removed" class="wp-github-sync-toggle-slider"></label>
                        </div>
                        <div class="wp-github-sync-tooltip">
                            <span class="dashicons dashicons-editor-help"></span>
                            <div class="wp-github-sync-tooltip-content">
                                <p><?php esc_html_e( 'When enabled, files that exist in WordPress but were removed from the GitHub repository will be deleted during sync.', 'wp-github-sync' ); ?></p>
                                <p><?php esc_html_e( 'This ensures your WordPress site exactly matches the repository content.', 'wp-github-sync' ); ?></p>
                                <p class="wp-github-sync-warning-text"><?php esc_html_e( 'Warning: Be careful when enabling this if you have files in WordPress that aren\'t in the repository.', 'wp-github-sync' ); ?></p>
                            </div>
                        </div>
                    </div>
                    <p class="wp-github-sync-field-description">
                        <?php esc_html_e( 'Delete files locally that have been removed from GitHub.', 'wp-github-sync' ); ?>
                    </p>
                </div>
                
                <div class="wp-github-sync-field-grid">
                    <div class="wp-github-sync-field with-toggle with-tooltip">
                        <div class="wp-github-sync-toggle-wrapper">
                            <label class="wp-github-sync-label" for="debug_mode">
                                <?php esc_html_e( 'Debug Mode', 'wp-github-sync' ); ?>
                            </label>
                            <div class="wp-github-sync-toggle-switch">
                                <input type="checkbox" id="debug_mode" name="wp_github_sync_settings[debug_mode]" value="1" 
                                    <?php checked( isset( $settings['debug_mode'] ) ? $settings['debug_mode'] : '0', 1 ); ?> />
                                <label for="debug_mode" class="wp-github-sync-toggle-slider"></label>
                            </div>
                            <div class="wp-github-sync-tooltip">
                                <span class="dashicons dashicons-editor-help"></span>
                                <div class="wp-github-sync-tooltip-content">
                                    <p><?php esc_html_e( 'Enable detailed logging of all plugin operations to help diagnose issues.', 'wp-github-sync' ); ?></p>
                                    <p><?php esc_html_e( 'Debug logs are stored in wp-content/uploads/wp-github-sync/logs/.', 'wp-github-sync' ); ?></p>
                                </div>
                            </div>
                        </div>
                        <p class="wp-github-sync-field-description">
                            <?php esc_html_e( 'Enable detailed debug logging for troubleshooting.', 'wp-github-sync' ); ?>
                        </p>
                    </div>
                    
                    <div class="wp-github-sync-field with-tooltip">
                        <label for="log_retention" class="wp-github-sync-label"><?php esc_html_e( 'Log Retention', 'wp-github-sync' ); ?></label>
                        <div class="wp-github-sync-input-container">
                            <div class="wp-github-sync-input-wrapper number">
                                <span class="wp-github-sync-input-icon"><span class="dashicons dashicons-calendar"></span></span>
                                <input type="number" id="log_retention" name="wp_github_sync_settings[log_retention]" class="wp-github-sync-number-input" 
                                    value="<?php echo esc_attr( isset( $settings['log_retention'] ) ? $settings['log_retention'] : '7' ); ?>" min="1" max="90" />
                                <span class="wp-github-sync-input-suffix"><?php esc_html_e( 'days', 'wp-github-sync' ); ?></span>
                            </div>
                            <div class="wp-github-sync-tooltip">
                                <span class="dashicons dashicons-editor-help"></span>
                                <div class="wp-github-sync-tooltip-content">
                                    <p><?php esc_html_e( 'Number of days to keep log files before they are automatically deleted.', 'wp-github-sync' ); ?></p>
                                    <p><?php esc_html_e( 'Set this to a lower value to prevent logs from consuming too much disk space.', 'wp-github-sync' ); ?></p>
                                </div>
                            </div>
                        </div>
                        <p class="wp-github-sync-field-description">
                            <?php esc_html_e( 'Number of days to keep logs before deletion.', 'wp-github-sync' ); ?>
                        </p>
                    </div>
                </div>
                
                <!-- View Logs Button -->
                <div class="wp-github-sync-button-container">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-github-sync-logs' ) ); ?>" class="wp-github-sync-button secondary">
                        <span class="dashicons dashicons-text-page"></span>
                        <?php esc_html_e( 'View Debug Logs', 'wp-github-sync' ); ?>
                    </a>
                </div>
            </div>
        </div>
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