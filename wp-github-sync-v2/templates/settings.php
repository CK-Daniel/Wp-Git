<?php
/**
 * Settings page template.
 *
 * @package WPGitHubSync
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Check user capability
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wp-github-sync' ) );
}
?>

<div class="wrap wp-github-sync">
    <header class="wp-github-sync-header">
        <div class="wp-github-sync-header-content">
            <h1 class="wp-github-sync-title"><?php esc_html_e( 'GitHub Sync Settings', 'wp-github-sync' ); ?></h1>
            <p class="wp-github-sync-subtitle"><?php esc_html_e( 'Configure your GitHub synchronization settings', 'wp-github-sync' ); ?></p>
        </div>
        <div class="wp-github-sync-header-icon">
            <span class="dashicons dashicons-admin-settings"></span>
        </div>
    </header>

    <?php settings_errors(); ?>

    <div class="wp-github-sync-content">
        <div class="wp-github-sync-sidebar">
            <div class="wp-github-sync-card wp-github-sync-help-card">
                <div class="wp-github-sync-card-header">
                    <h2><?php esc_html_e( 'Quick Help', 'wp-github-sync' ); ?></h2>
                </div>
                <div class="wp-github-sync-card-content">
                    <p><?php esc_html_e( 'Need help setting up GitHub Sync?', 'wp-github-sync' ); ?></p>
                    <ul class="wp-github-sync-help-links">
                        <li>
                            <a href="#" class="wp-github-sync-help-link">
                                <span class="dashicons dashicons-book"></span>
                                <?php esc_html_e( 'Documentation', 'wp-github-sync' ); ?>
                            </a>
                        </li>
                        <li>
                            <a href="#" class="wp-github-sync-help-link">
                                <span class="dashicons dashicons-video-alt3"></span>
                                <?php esc_html_e( 'Video Tutorials', 'wp-github-sync' ); ?>
                            </a>
                        </li>
                        <li>
                            <a href="#" class="wp-github-sync-help-link">
                                <span class="dashicons dashicons-editor-help"></span>
                                <?php esc_html_e( 'FAQs', 'wp-github-sync' ); ?>
                            </a>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="wp-github-sync-card wp-github-sync-status-card">
                <div class="wp-github-sync-card-header">
                    <h2><?php esc_html_e( 'Connection Status', 'wp-github-sync' ); ?></h2>
                </div>
                <div class="wp-github-sync-card-content">
                    <div id="wp-github-sync-connection-status" class="wp-github-sync-status-indicator pending">
                        <span class="dashicons dashicons-warning"></span>
                        <span class="status-text"><?php esc_html_e( 'Not Connected', 'wp-github-sync' ); ?></span>
                    </div>
                    <button type="button" class="wp-github-sync-button secondary" id="test-connection">
                        <span class="dashicons dashicons-update"></span>
                        <?php esc_html_e( 'Test Connection', 'wp-github-sync' ); ?>
                    </button>
                </div>
            </div>
        </div>

        <div class="wp-github-sync-main">
            <div class="wp-github-sync-card">
                <div class="wp-github-sync-card-header">
                    <?php echo $tabs->render(); ?>
                </div>
                <div class="wp-github-sync-card-content">
                    <form method="post" action="options.php" class="wp-github-sync-settings-form">
                        <?php settings_fields( 'wp_github_sync_settings' ); ?>
                        <div class="wp-github-sync-tab-content">
                            <!-- Tabs content will be dynamically shown/hidden via JavaScript -->
                            <div class="tab-content active" data-tab="general">
                                <?php echo $general_settings; ?>
                            </div>
                            <div class="tab-content" data-tab="backup">
                                <?php echo $backup_settings; ?>
                            </div>
                            <div class="tab-content" data-tab="advanced">
                                <?php echo $advanced_settings; ?>
                            </div>
                        </div>
                        <div class="wp-github-sync-form-actions">
                            <?php submit_button( __( 'Save Settings', 'wp-github-sync' ), 'primary wp-github-sync-button', 'submit', false ); ?>
                            <button type="button" class="wp-github-sync-button secondary" id="reset-settings">
                                <?php esc_html_e( 'Reset Settings', 'wp-github-sync' ); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Progress dialog / modal -->
    <div class="wp-github-sync-dialog" id="wp-github-sync-progress-dialog">
        <div class="wp-github-sync-dialog-content">
            <div class="wp-github-sync-dialog-header">
                <h3 id="wp-github-sync-dialog-title"><?php esc_html_e( 'Processing', 'wp-github-sync' ); ?></h3>
                <button type="button" class="wp-github-sync-dialog-close">×</button>
            </div>
            <div class="wp-github-sync-dialog-body">
                <div class="wp-github-sync-progress">
                    <div class="wp-github-sync-progress-bar"></div>
                </div>
                <div class="wp-github-sync-progress-message" id="wp-github-sync-progress-message">
                    <?php esc_html_e( 'Please wait...', 'wp-github-sync' ); ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    jQuery(document).ready(function($) {
        // Tab switching
        $('.wp-github-sync-tab').on('click', function() {
            var tab = $(this).data('tab');
            
            // Update active tab
            $('.wp-github-sync-tab').removeClass('active');
            $(this).addClass('active');
            
            // Show/hide tab content
            $('.tab-content').removeClass('active');
            $('.tab-content[data-tab="' + tab + '"]').addClass('active');
            
            // Update URL hash
            window.location.hash = tab;
        });
        
        // Initialize active tab from URL hash
        function initTabFromHash() {
            var hash = window.location.hash.substring(1);
            if (hash && $('.wp-github-sync-tab[data-tab="' + hash + '"]').length) {
                $('.wp-github-sync-tab[data-tab="' + hash + '"]').trigger('click');
            }
        }
        
        // Run on page load
        initTabFromHash();
        
        // Run when hash changes
        $(window).on('hashchange', initTabFromHash);
        
        // Test connection button
        $('#test-connection').on('click', function() {
            var $status = $('#wp-github-sync-connection-status');
            var authMethod = $('input[name="wp_github_sync_settings[auth_method]"]:checked').val() || 'pat';
            var token = '';
            
            if (authMethod === 'pat') {
                token = $('input[name="wp_github_sync_settings[access_token]"]').val();
            } else {
                token = $('input[name="wp_github_sync_settings[oauth_token]"]').val();
            }
            
            var repoUrl = $('input[name="wp_github_sync_settings[repo_url]"]').val();
            
            if (!token || !repoUrl) {
                $status.removeClass('pending success').addClass('error');
                $status.find('.dashicons').removeClass('dashicons-warning dashicons-yes').addClass('dashicons-no');
                $status.find('.status-text').text('<?php esc_html_e( 'Please enter repository URL and access token', 'wp-github-sync' ); ?>');
                return;
            }
            
            // Update status indicator
            $status.removeClass('pending success error').addClass('testing');
            $status.find('.dashicons').removeClass('dashicons-warning dashicons-yes dashicons-no').addClass('dashicons-update wp-github-sync-spin');
            $status.find('.status-text').text('<?php esc_html_e( 'Testing connection...', 'wp-github-sync' ); ?>');
            
            // AJAX request to test connection
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'wp_github_sync_test_connection',
                    auth_method: authMethod,
                    token: token,
                    repo_url: repoUrl,
                    nonce: wpGitHubSync.apiNonce
                },
                success: function(response) {
                    if (response.success) {
                        $status.removeClass('pending testing error').addClass('success');
                        $status.find('.dashicons').removeClass('dashicons-warning dashicons-update dashicons-no wp-github-sync-spin').addClass('dashicons-yes');
                        $status.find('.status-text').text(response.data.message || '<?php esc_html_e( 'Connected successfully!', 'wp-github-sync' ); ?>');
                    } else {
                        $status.removeClass('pending testing success').addClass('error');
                        $status.find('.dashicons').removeClass('dashicons-warning dashicons-update dashicons-yes wp-github-sync-spin').addClass('dashicons-no');
                        $status.find('.status-text').text(response.data.message || '<?php esc_html_e( 'Connection failed', 'wp-github-sync' ); ?>');
                    }
                },
                error: function() {
                    $status.removeClass('pending testing success').addClass('error');
                    $status.find('.dashicons').removeClass('dashicons-warning dashicons-update dashicons-yes wp-github-sync-spin').addClass('dashicons-no');
                    $status.find('.status-text').text('<?php esc_html_e( 'Connection test failed', 'wp-github-sync' ); ?>');
                }
            });
        });
        
        // Reset settings button
        $('#reset-settings').on('click', function(e) {
            e.preventDefault();
            
            if (confirm('<?php esc_html_e( 'Are you sure you want to reset all settings to default values?', 'wp-github-sync' ); ?>')) {
                // Show progress dialog
                $('#wp-github-sync-dialog-title').text('<?php esc_html_e( 'Resetting Settings', 'wp-github-sync' ); ?>');
                $('#wp-github-sync-progress-message').text('<?php esc_html_e( 'Please wait while settings are reset...', 'wp-github-sync' ); ?>');
                $('#wp-github-sync-progress-dialog').addClass('open');
                
                // AJAX request to reset settings
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wp_github_sync_reset_settings',
                        nonce: wpGitHubSync.apiNonce
                    },
                    success: function(response) {
                        // Close dialog
                        $('#wp-github-sync-progress-dialog').removeClass('open');
                        
                        if (response.success) {
                            // Reload page to show default settings
                            location.reload();
                        } else {
                            alert(response.data.message || '<?php esc_html_e( 'Failed to reset settings', 'wp-github-sync' ); ?>');
                        }
                    },
                    error: function() {
                        // Close dialog
                        $('#wp-github-sync-progress-dialog').removeClass('open');
                        alert('<?php esc_html_e( 'Failed to reset settings due to a server error', 'wp-github-sync' ); ?>');
                    }
                });
            }
        });
        
        // Close dialog button
        $('.wp-github-sync-dialog-close').on('click', function() {
            $(this).closest('.wp-github-sync-dialog').removeClass('open');
        });
        
        // Close dialog when clicking outside of it
        $(document).on('click', function(e) {
            if ($(e.target).closest('.wp-github-sync-dialog-content').length === 0 && 
                $(e.target).closest('.wp-github-sync-button').length === 0) {
                $('.wp-github-sync-dialog').removeClass('open');
            }
        });
    });
</script>