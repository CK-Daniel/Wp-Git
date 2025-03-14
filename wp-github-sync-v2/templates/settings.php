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
                                <?php 
                                // General settings content
                                echo $general_settings; 
                                ?>
                            </div>
                            <div class="tab-content" data-tab="backup">
                                <?php 
                                // Backup settings content
                                echo $backup_settings; 
                                ?>
                            </div>
                            <div class="tab-content" data-tab="advanced">
                                <?php 
                                // Advanced settings content
                                echo $advanced_settings; 
                                ?>
                            </div>
                        </div>
                        <div class="wp-github-sync-form-actions">
                            <?php submit_button( __( 'Save Settings', 'wp-github-sync' ), 'primary wp-github-sync-button', 'submit', false, array('id' => 'save-settings')); ?>
                            <button type="button" class="wp-github-sync-button secondary" id="reset-settings">
                                <?php esc_html_e( 'Reset Settings', 'wp-github-sync' ); ?>
                            </button>
                            <div id="save-settings-feedback" class="wp-github-sync-save-feedback" style="display: none;">
                                <span class="dashicons dashicons-saved"></span> <?php esc_html_e('Settings saved successfully!', 'wp-github-sync'); ?>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Enhanced progress dialog / modal -->
    <div class="wp-github-sync-dialog" id="wp-github-sync-progress-dialog">
        <div class="wp-github-sync-dialog-content">
            <div class="wp-github-sync-dialog-header">
                <h3 id="wp-github-sync-dialog-title"><?php esc_html_e( 'Processing', 'wp-github-sync' ); ?></h3>
                <button type="button" class="wp-github-sync-dialog-close" aria-label="<?php esc_attr_e( 'Close', 'wp-github-sync' ); ?>">
                    <span class="dashicons dashicons-no-alt"></span>
                </button>
            </div>
            <div class="wp-github-sync-dialog-body">
                <div class="wp-github-sync-progress">
                    <div class="wp-github-sync-progress-bar" id="wp-github-sync-progress-bar"></div>
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
        // Log for debugging
        console.log('Settings page loaded');
        
        // Check if tab contents exist
        console.log('Tab content elements:', $('.tab-content').length);
        $('.tab-content').each(function() {
            console.log('Tab:', $(this).data('tab'), 'Content length:', $(this).html().length);
        });
        
        // Enhanced tab switching with animation
        $('.wp-github-sync-tab').on('click', function() {
            var tab = $(this).data('tab');
            console.log('Tab clicked:', tab);
            
            // Update active tab
            $('.wp-github-sync-tab').removeClass('active');
            $(this).addClass('active');
            
            // Show/hide tab content with fade effect
            $('.tab-content').hide();
            $('.tab-content').removeClass('active');
            var $targetTab = $('.tab-content[data-tab="' + tab + '"]');
            $targetTab.addClass('active').fadeIn(300);
            
            console.log('Showing tab content:', tab, 'Found:', $targetTab.length);
            
            // Update URL hash
            if (history.pushState) {
                history.pushState(null, null, '#' + tab);
            } else {
                window.location.hash = tab;
            }
            
            // Store active tab in sessionStorage
            sessionStorage.setItem('active_wp_github_sync_tab', tab);
        });
        
        // Initialize active tab from URL hash or sessionStorage
        function initTabFromHash() {
            // Try to get tab from URL hash first
            var hash = window.location.hash.substring(1);
            
            // If no hash, try from sessionStorage
            if (!hash || !$('.wp-github-sync-tab[data-tab="' + hash + '"]').length) {
                hash = sessionStorage.getItem('active_wp_github_sync_tab');
            }
            
            // If we have a valid tab, activate it
            if (hash && $('.wp-github-sync-tab[data-tab="' + hash + '"]').length) {
                $('.wp-github-sync-tab[data-tab="' + hash + '"]').trigger('click');
            } else {
                // Default to first tab
                $('.wp-github-sync-tab:first').trigger('click');
            }
        }
        
        // Run on page load
        initTabFromHash();
        
        // Run when hash changes
        $(window).on('hashchange', initTabFromHash);
        
        // Improved test connection button with visual feedback
        $('#test-connection').on('click', function() {
            var $button = $(this);
            var $status = $('#wp-github-sync-connection-status');
            var authMethod = $('input[name="wp_github_sync_settings[auth_method]"]:checked').val() || 'pat';
            var token = '';
            
            // Disable button and show spinner
            $button.prop('disabled', true).addClass('wp-github-sync-button-loading');
            $button.find('.dashicons').addClass('wp-github-sync-spin');
            
            if (authMethod === 'pat') {
                token = $('input[name="wp_github_sync_settings[access_token]"]').val();
            } else {
                token = $('input[name="wp_github_sync_settings[oauth_token]"]').val();
            }
            
            var repoUrl = $('input[name="wp_github_sync_settings[repo_url]"]').val();
            var branch = $('input[name="wp_github_sync_settings[sync_branch]"]').val();
            
            if (!token || !repoUrl) {
                $status.removeClass('pending success testing').addClass('error');
                $status.find('.dashicons').removeClass('dashicons-warning dashicons-yes dashicons-update wp-github-sync-spin').addClass('dashicons-no');
                $status.find('.status-text').text('<?php esc_html_e( 'Please enter repository URL and access token', 'wp-github-sync' ); ?>');
                
                // Re-enable button
                $button.prop('disabled', false).removeClass('wp-github-sync-button-loading');
                $button.find('.dashicons').removeClass('wp-github-sync-spin');
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
                    branch: branch,
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
                    
                    // Re-enable button
                    $button.prop('disabled', false).removeClass('wp-github-sync-button-loading');
                    $button.find('.dashicons').removeClass('wp-github-sync-spin');
                },
                error: function() {
                    $status.removeClass('pending testing success').addClass('error');
                    $status.find('.dashicons').removeClass('dashicons-warning dashicons-update dashicons-yes wp-github-sync-spin').addClass('dashicons-no');
                    $status.find('.status-text').text('<?php esc_html_e( 'Connection test failed', 'wp-github-sync' ); ?>');
                    
                    // Re-enable button
                    $button.prop('disabled', false).removeClass('wp-github-sync-button-loading');
                    $button.find('.dashicons').removeClass('wp-github-sync-spin');
                }
            });
        });
        
        // Enhanced reset settings button with confirmation modal
        $('#reset-settings').on('click', function(e) {
            e.preventDefault();
            
            // Show confirmation dialog
            $('#wp-github-sync-dialog-title').text('<?php esc_html_e( 'Reset Settings', 'wp-github-sync' ); ?>');
            $('#wp-github-sync-progress-message').html('<?php esc_html_e( 'Are you sure you want to reset all settings to default values?', 'wp-github-sync' ); ?>' + 
                '<div class="wp-github-sync-dialog-confirm-buttons">' +
                '<button type="button" class="wp-github-sync-button secondary" id="cancel-reset"><?php esc_html_e( 'Cancel', 'wp-github-sync' ); ?></button>' +
                '<button type="button" class="wp-github-sync-button primary" id="confirm-reset"><?php esc_html_e( 'Reset Settings', 'wp-github-sync' ); ?></button>' +
                '</div>');
            $('#wp-github-sync-progress-dialog').addClass('open');
            
            // Focus confirm button
            setTimeout(function() {
                $('#confirm-reset').focus();
            }, 100);
            
            // Cancel button action
            $('#cancel-reset').on('click', function() {
                $('#wp-github-sync-progress-dialog').removeClass('open');
            });
            
            // Confirm reset action
            $('#confirm-reset').on('click', function() {
                // Update dialog content to show progress
                $('#wp-github-sync-dialog-title').text('<?php esc_html_e( 'Resetting Settings', 'wp-github-sync' ); ?>');
                $('#wp-github-sync-progress-message').html('<?php esc_html_e( 'Please wait while settings are reset...', 'wp-github-sync' ); ?>');
                $('#wp-github-sync-progress-bar').css('width', '0%').animate({width: '90%'}, 1000);
                
                // AJAX request to reset settings
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wp_github_sync_reset_settings',
                        nonce: wpGitHubSync.apiNonce
                    },
                    success: function(response) {
                        // Complete progress bar animation
                        $('#wp-github-sync-progress-bar').animate({width: '100%'}, 300);
                        
                        setTimeout(function() {
                            // Close dialog
                            $('#wp-github-sync-progress-dialog').removeClass('open');
                            
                            if (response.success) {
                                // Show success message
                                $('<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings reset successfully!', 'wp-github-sync' ); ?></p></div>').insertBefore('.wp-github-sync-content').hide().fadeIn();
                                
                                // Reload page after short delay
                                setTimeout(function() {
                                    location.reload();
                                }, 1500);
                            } else {
                                alert(response.data.message || '<?php esc_html_e( 'Failed to reset settings', 'wp-github-sync' ); ?>');
                            }
                        }, 500);
                    },
                    error: function() {
                        // Close dialog
                        $('#wp-github-sync-progress-dialog').removeClass('open');
                        alert('<?php esc_html_e( 'Failed to reset settings due to a server error', 'wp-github-sync' ); ?>');
                    }
                });
            });
        });
        
        // Improved dialog interactions
        $('.wp-github-sync-dialog-close').on('click', function() {
            $(this).closest('.wp-github-sync-dialog').removeClass('open');
        });
        
        // Close dialog when clicking outside of it
        $(document).on('click', function(e) {
            if ($(e.target).closest('.wp-github-sync-dialog-content').length === 0 && 
                $(e.target).hasClass('wp-github-sync-dialog')) {
                $('.wp-github-sync-dialog').removeClass('open');
            }
        });
        
        // Close dialog on ESC key
        $(document).on('keyup', function(e) {
            if (e.key === 'Escape' && $('.wp-github-sync-dialog.open').length) {
                $('.wp-github-sync-dialog').removeClass('open');
            }
        });
        
        // Toggle settings visibility for checkboxes
        $('.wp-github-sync-toggle-switch input[type="checkbox"]').on('change', function() {
            var targetSelector = $(this).data('toggle-target');
            if (targetSelector) {
                if ($(this).is(':checked')) {
                    $(targetSelector).slideDown(300);
                } else {
                    $(targetSelector).slideUp(300);
                }
            }
        });
        
        // Initialize toggle states
        $('.wp-github-sync-toggle-switch input[type="checkbox"]').each(function() {
            var targetSelector = $(this).data('toggle-target');
            if (targetSelector) {
                if ($(this).is(':checked')) {
                    $(targetSelector).show();
                } else {
                    $(targetSelector).hide();
                }
            }
        });
        
        // Password visibility toggle
        $('.wp-github-sync-toggle-password').on('click', function() {
            var $input = $(this).prev('input');
            var type = $input.attr('type') === 'password' ? 'text' : 'password';
            $input.attr('type', type);
            
            // Toggle icon
            $(this).find('.dashicons')
                .toggleClass('dashicons-visibility')
                .toggleClass('dashicons-hidden');
        });
        
        // Form submission animation and feedback
        $('.wp-github-sync-settings-form').on('submit', function() {
            // Show loading animation on submit button
            var $submitButton = $('#save-settings');
            var originalText = $submitButton.val();
            
            $submitButton.prop('disabled', true).addClass('wp-github-sync-button-loading');
            $submitButton.val('<?php esc_html_e('Saving...', 'wp-github-sync'); ?>');
            
            // Store the form data in sessionStorage for later comparison to detect changes
            var formData = $(this).serialize();
            sessionStorage.setItem('wp_github_sync_form_data', formData);
            
            // Check if we're coming back from a form submission
            if (window.location.search.indexOf('settings-updated=true') !== -1) {
                // Show success message
                $('#save-settings-feedback').fadeIn().delay(3000).fadeOut();
                
                // Scroll to top if there are admin notices
                if ($('.notice').length) {
                    $('html, body').animate({
                        scrollTop: $('.notice:first').offset().top - 50
                    }, 500);
                }
            }
            
            return true;
        });
        
        // Check if we're coming back from a form submission
        if (window.location.search.indexOf('settings-updated=true') !== -1) {
            // Show success message
            $('#save-settings-feedback').fadeIn().delay(3000).fadeOut();
            
            // Highlight the saved fields briefly
            $('.wp-github-sync-text-input, .wp-github-sync-select, .wp-github-sync-textarea').addClass('wp-github-sync-saved-field');
            setTimeout(function() {
                $('.wp-github-sync-saved-field').removeClass('wp-github-sync-saved-field');
            }, 2000);
        }
    });
</script>

<style>
/* Additional styles for the dialog */
.wp-github-sync-dialog-confirm-buttons {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 20px;
}

.wp-github-sync-button-loading {
    opacity: 0.8;
    cursor: not-allowed;
}

.wp-github-sync-progress-bar {
    transition: width 0.5s ease;
}

/* Save feedback styles */
.wp-github-sync-save-feedback {
    display: flex;
    align-items: center;
    color: var(--wp-github-success);
    margin-left: 15px;
    padding: 8px 15px;
    background-color: rgba(0, 163, 42, 0.1);
    border-radius: 4px;
    animation: fadeInRight 0.5s forwards;
}

.wp-github-sync-save-feedback .dashicons {
    margin-right: 8px;
    font-size: 20px;
    width: 20px;
    height: 20px;
}

.wp-github-sync-saved-field {
    animation: savedFieldHighlight 2s;
}

.wp-github-sync-form-actions {
    display: flex;
    align-items: center;
}

@keyframes fadeInRight {
    from {
        opacity: 0;
        transform: translateX(-20px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

@keyframes savedFieldHighlight {
    0% {
        background-color: transparent;
    }
    30% {
        background-color: rgba(0, 163, 42, 0.1);
    }
    100% {
        background-color: transparent;
    }
}
</style>