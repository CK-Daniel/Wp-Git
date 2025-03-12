<?php
/**
 * Admin settings page template.
 *
 * @package WPGitHubSync
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Get settings and status
$settings = get_option('wp_github_sync_settings', array());
$is_configured = !empty($settings['github_repo']) && (!empty($settings['github_token']) || !empty($settings['github_oauth_token']));
$has_synced = get_option('wp_github_sync_last_deployment', false);

// Create default repo name suggestion based on site URL
$site_url = parse_url(get_site_url(), PHP_URL_HOST);
$default_repo_name = sanitize_title(str_replace('.', '-', $site_url));
?>
<div class="wrap wp-github-sync-wrap">
    <h1><?php _e('GitHub Sync Settings', 'wp-github-sync'); ?></h1>
    
    <?php settings_errors(); ?>
    
    <?php if (!$has_synced && $is_configured): ?>
    <div class="wp-github-sync-initial-sync-card">
        <h2>
            <span class="dashicons dashicons-update"></span>
            <?php _e('Initial Sync Setup', 'wp-github-sync'); ?>
        </h2>
        <p><?php _e('You\'ve configured GitHub Sync, but haven\'t performed your first synchronization yet. You can either connect to an existing repository or create a new one.', 'wp-github-sync'); ?></p>
        
        <div class="wp-github-sync-repo-creation">
            <div>
                <label for="create_new_repo"><?php _e('Create a new GitHub repository for this WordPress site', 'wp-github-sync'); ?></label>
                <input type="checkbox" id="create_new_repo" name="create_new_repo" value="1"/>
            </div>
            
            <div id="new_repo_options" style="display: none;">
                <label for="new_repo_name"><?php _e('Repository Name', 'wp-github-sync'); ?></label>
                <input type="text" id="new_repo_name" name="new_repo_name" placeholder="<?php echo esc_attr($default_repo_name); ?>" />
            </div>
        </div>
        
        <button type="button" id="initial_sync_button" class="wp-github-sync-initial-sync-button">
            <span class="dashicons dashicons-update"></span>
            <?php _e('Start Initial Sync', 'wp-github-sync'); ?>
        </button>
    </div>
    <?php endif; ?>
    
    <form method="post" action="options.php" class="wp-github-sync-settings-form">
        <?php settings_fields('wp_github_sync_settings'); ?>
        
        <div class="wp-github-sync-settings-content">
            <div class="wp-github-sync-tabs">
                <div class="wp-github-sync-tab active" data-tab="general">
                    <span class="dashicons dashicons-admin-settings"></span>
                    <?php _e('General', 'wp-github-sync'); ?>
                </div>
                <div class="wp-github-sync-tab" data-tab="authentication">
                    <span class="dashicons dashicons-lock"></span>
                    <?php _e('Authentication', 'wp-github-sync'); ?>
                </div>
                <div class="wp-github-sync-tab" data-tab="sync">
                    <span class="dashicons dashicons-update"></span>
                    <?php _e('Sync Options', 'wp-github-sync'); ?>
                </div>
                <div class="wp-github-sync-tab" data-tab="advanced">
                    <span class="dashicons dashicons-admin-tools"></span>
                    <?php _e('Advanced', 'wp-github-sync'); ?>
                </div>
            </div>
            
            <div class="wp-github-sync-card">
                <!-- Tab content will be dynamically loaded here -->
                <div id="wp-github-sync-tab-content-container">
                    <?php do_settings_sections('wp_github_sync_settings'); ?>
                </div>
                
                <div class="wp-github-sync-card-actions">
                    <?php submit_button(__('Save Settings', 'wp-github-sync'), 'primary wp-github-sync-button', 'submit', false); ?>
                </div>
            </div>
            
            <?php if ($has_synced): ?>
            <div class="wp-github-sync-info-box info">
                <div class="wp-github-sync-info-box-icon">
                    <span class="dashicons dashicons-info"></span>
                </div>
                <div class="wp-github-sync-info-box-content">
                    <h4 class="wp-github-sync-info-box-title"><?php _e('Need to update files from GitHub?', 'wp-github-sync'); ?></h4>
                    <p class="wp-github-sync-info-box-message">
                        <?php _e('Visit the GitHub Sync Dashboard to check for updates, deploy changes, or switch branches.', 'wp-github-sync'); ?>
                        <br>
                        <a href="<?php echo admin_url('admin.php?page=wp-github-sync'); ?>" class="wp-github-sync-button secondary" style="margin-top: 10px;">
                            <span class="dashicons dashicons-dashboard"></span>
                            <?php _e('Go to Dashboard', 'wp-github-sync'); ?>
                        </a>
                    </p>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </form>
    
    <!-- Loading/Progress Overlay -->
    <div class="wp-github-sync-overlay" style="display: none;">
        <div class="wp-github-sync-loader"></div>
        <div class="wp-github-sync-loading-message"><?php _e('Processing...', 'wp-github-sync'); ?></div>
        <div class="wp-github-sync-loading-submessage"></div>
    </div>
    
    <script>
    jQuery(document).ready(function($) {
        // Remove any existing handlers to prevent duplicates
        $('.wp-github-sync-tab').off('click.settings-tab');
        
        // Tab functionality
        $('.wp-github-sync-tab').on('click.settings-tab', function(e) {
            e.preventDefault();
            
            $('.wp-github-sync-tab').removeClass('active');
            $(this).addClass('active');
            
            // Get the selected tab
            const tab = $(this).data('tab');
            
            console.log('Tab clicked:', tab);
            
            // Reorganize form sections based on the tab
            organizeSettingsByTab(tab);
        });
        
        function organizeSettingsByTab(tab) {
            if (!tab) {
                console.error('No tab specified for organizeSettingsByTab');
                return;
            }
            
            console.log('Organizing settings for tab:', tab);
            
            // Hide all setting sections first
            $('.settings-section').hide();
            
            // Show only the sections for the selected tab
            $(`.settings-section[data-tab="${tab}"]`).show();
            
            // Update URL hash without scrolling
            if (history.pushState) {
                history.pushState(null, null, '#' + tab);
            } else {
                window.location.hash = tab;
            }
        }
        
        // Function to initialize tabs based on classes added to settings sections
        function initializeTabs() {
            console.log('Initializing settings tabs');
            
            // Add tab attribute to each settings section based on its title
            $('.form-table').each(function() {
                const $section = $(this).closest('.settings-section');
                
                if (!$section.length) return;
                
                const sectionTitle = $section.find('h2').text().toLowerCase();
                
                // Determine which tab this section belongs to
                let tab = 'general';
                
                if (sectionTitle.includes('authentication') || sectionTitle.includes('token') || sectionTitle.includes('oauth')) {
                    tab = 'authentication';
                } else if (sectionTitle.includes('sync') || sectionTitle.includes('deployment') || sectionTitle.includes('webhook')) {
                    tab = 'sync';
                } else if (sectionTitle.includes('advanced') || sectionTitle.includes('backup') || sectionTitle.includes('maintenance')) {
                    tab = 'advanced';
                }
                
                console.log('Setting section tab:', sectionTitle, '→', tab);
                $section.attr('data-tab', tab);
            });
            
            // Check how many sections are in each tab
            ['general', 'authentication', 'sync', 'advanced'].forEach(function(tabName) {
                const count = $(`.settings-section[data-tab="${tabName}"]`).length;
                console.log(`Tab ${tabName} has ${count} sections`);
            });
            
            // Check if URL has a hash for a tab
            const hash = window.location.hash.substring(1);
            if (hash && $('.wp-github-sync-tab[data-tab="' + hash + '"]').length) {
                console.log('Found hash in URL:', hash);
                $('.wp-github-sync-tab[data-tab="' + hash + '"]').click();
            } else {
                // Default to first tab
                console.log('No hash in URL, defaulting to general tab');
                organizeSettingsByTab('general');
            }
        }
        
        // Repository creation toggle
        $('#create_new_repo').on('change', function() {
            if ($(this).is(':checked')) {
                $('#new_repo_options').slideDown();
            } else {
                $('#new_repo_options').slideUp();
            }
        });
        
        // Initial sync button click handler
        $('#initial_sync_button').on('click', function() {
            const createNewRepo = $('#create_new_repo').is(':checked');
            let repoName = '';
            
            if (createNewRepo) {
                repoName = $('#new_repo_name').val() || '<?php echo esc_js($default_repo_name); ?>';
                
                if (!repoName) {
                    alert('<?php _e('Please enter a repository name', 'wp-github-sync'); ?>');
                    return;
                }
            }
            
            // Show loading overlay
            $('.wp-github-sync-overlay').show();
            $('.wp-github-sync-loading-message').text('<?php _e('Setting up GitHub Sync...', 'wp-github-sync'); ?>');
            
            if (createNewRepo) {
                $('.wp-github-sync-loading-submessage').text('<?php _e('Creating new repository...', 'wp-github-sync'); ?>');
            } else {
                $('.wp-github-sync-loading-submessage').text('<?php _e('Connecting to existing repository...', 'wp-github-sync'); ?>');
            }
            
            // AJAX call to handle initial sync
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'wp_github_sync_initial_sync',
                    create_new_repo: createNewRepo ? 1 : 0,
                    repo_name: repoName,
                    nonce: '<?php echo wp_create_nonce('wp_github_sync_initial_sync'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        $('.wp-github-sync-loading-message').text('<?php _e('Success!', 'wp-github-sync'); ?>');
                        $('.wp-github-sync-loading-submessage').text(response.data.message);
                        
                        // Redirect to dashboard after 2 seconds
                        setTimeout(function() {
                            window.location.href = '<?php echo admin_url('admin.php?page=wp-github-sync'); ?>';
                        }, 2000);
                    } else {
                        $('.wp-github-sync-loading-message').text('<?php _e('Error', 'wp-github-sync'); ?>');
                        $('.wp-github-sync-loading-submessage').text(response.data.message);
                        
                        // Hide overlay after 3 seconds
                        setTimeout(function() {
                            $('.wp-github-sync-overlay').hide();
                        }, 3000);
                    }
                },
                error: function() {
                    $('.wp-github-sync-loading-message').text('<?php _e('Error', 'wp-github-sync'); ?>');
                    $('.wp-github-sync-loading-submessage').text('<?php _e('An unexpected error occurred. Please try again.', 'wp-github-sync'); ?>');
                    
                    // Hide overlay after 3 seconds
                    setTimeout(function() {
                        $('.wp-github-sync-overlay').hide();
                    }, 3000);
                }
            });
        });
        
        // Connection testing
        $('.wp-github-sync-test-connection').on('click', function() {
            const $statusArea = $('#github-connection-status');
            const token = $('#wp_github_sync_access_token').val();
            const repoUrl = $('#wp_github_sync_repository').val();
            
            // Don't test with masked token
            if (token === '********') {
                $statusArea.html(
                    '<div class="wp-github-sync-info-box warning" style="margin-top: 10px;">' +
                    '<div class="wp-github-sync-info-box-icon"><span class="dashicons dashicons-warning"></span></div>' +
                    '<div class="wp-github-sync-info-box-content">' +
                    '<p>Please enter your token first. The masked token cannot be used for testing.</p>' +
                    '</div></div>'
                );
                return;
            }
            
            // Show testing indicator
            $statusArea.html(
                '<div class="wp-github-sync-info-box info" style="margin-top: 10px;">' +
                '<div class="wp-github-sync-info-box-icon"><span class="dashicons dashicons-update wp-github-sync-spin"></span></div>' +
                '<div class="wp-github-sync-info-box-content">' +
                '<p>Testing connection to GitHub...</p>' +
                '</div></div>'
            );
            
            // Send the AJAX request to test connection
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'wp_github_sync_test_connection',
                    token: token,
                    repo_url: repoUrl,
                    nonce: '<?php echo wp_create_nonce('wp_github_sync_nonce'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        // Success - credentials and possibly repo are valid
                        let message = response.data.message;
                        
                        if (response.data.username) {
                            message += ' Authenticated as <strong>' + response.data.username + '</strong>.';
                        }
                        
                        if (response.data.repo_info) {
                            message += ' Repository: <strong>' + response.data.repo_info.owner + '/' + response.data.repo_info.repo + '</strong>';
                        }
                        
                        $statusArea.html(
                            '<div class="wp-github-sync-info-box success" style="margin-top: 10px;">' +
                            '<div class="wp-github-sync-info-box-icon"><span class="dashicons dashicons-yes-alt"></span></div>' +
                            '<div class="wp-github-sync-info-box-content">' +
                            '<p>' + message + '</p>' +
                            '</div></div>'
                        );
                    } else {
                        // Error - display the error message
                        $statusArea.html(
                            '<div class="wp-github-sync-info-box error" style="margin-top: 10px;">' +
                            '<div class="wp-github-sync-info-box-icon"><span class="dashicons dashicons-no"></span></div>' +
                            '<div class="wp-github-sync-info-box-content">' +
                            '<p>' + response.data.message + '</p>' +
                            '</div></div>'
                        );
                    }
                },
                error: function() {
                    // AJAX request failed
                    $statusArea.html(
                        '<div class="wp-github-sync-info-box error" style="margin-top: 10px;">' +
                        '<div class="wp-github-sync-info-box-icon"><span class="dashicons dashicons-no"></span></div>' +
                        '<div class="wp-github-sync-info-box-content">' +
                        '<p>Connection test failed. Please try again.</p>' +
                        '</div></div>'
                    );
                }
            });
        });
        
        // Process settings sections after page load
        $('.form-table').each(function() {
            // Wrap each table in a section div for better styling
            $(this).wrap('<div class="wp-github-sync-settings-section settings-section"></div>');
            
            // Move the h2 title inside the section
            const $title = $(this).prev('h2');
            if ($title.length) {
                // Add dashicons based on section title
                let icon = 'dashicons-admin-generic';
                const titleText = $title.text().toLowerCase();
                
                if (titleText.includes('repository')) {
                    icon = 'dashicons-archive';
                } else if (titleText.includes('authentication')) {
                    icon = 'dashicons-lock';
                } else if (titleText.includes('deployment')) {
                    icon = 'dashicons-update';
                } else if (titleText.includes('webhook')) {
                    icon = 'dashicons-welcome-widgets-menus';
                } else if (titleText.includes('advanced')) {
                    icon = 'dashicons-admin-tools';
                } else if (titleText.includes('backup')) {
                    icon = 'dashicons-backup';
                }
                
                const $sectionDiv = $(this).parent('.wp-github-sync-settings-section');
                $title.addClass('wp-github-sync-section-title').prepend(`<span class="dashicons ${icon}"></span>`);
                $sectionDiv.prepend($title);
            }
        });
        
        // Add field descriptions to appropriate settings fields
        $('.form-table input, .form-table select, .form-table textarea').each(function() {
            const $description = $(this).siblings('.description');
            if ($description.length) {
                $description.addClass('wp-github-sync-field-description');
            }
        });
        
        // Initialize tabs system
        initializeTabs();
    });
    </script>
</div>