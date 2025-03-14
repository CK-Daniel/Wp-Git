/**
 * WordPress GitHub Sync admin JavaScript
 * 
 * Modern, interactive UI for the admin dashboard
 */
(function($) {
    'use strict';
    
    // Initialize on document ready
    $(document).ready(function() {
        WpGitHubSync.init();
    });
    
    // Main plugin object
    var WpGitHubSync = {
        init: function() {
            this.setupEventListeners();
            this.initComponents();
        },
        
        setupEventListeners: function() {
            // Sync button click handler
            $('.js-sync-button').on('click', function(e) {
                e.preventDefault();
                WpGitHubSync.startSync();
            });
            
            // Create backup button
            $('.js-create-backup').on('click', function(e) {
                e.preventDefault();
                WpGitHubSync.createBackup();
            });
            
            // Test connection button
            $('.js-test-connection, #connection-test-button').on('click', function(e) {
                e.preventDefault();
                WpGitHubSync.testConnection();
            });
            
            // Initialize connection status on page load
            if ($('#wp-github-sync-connection-status').length) {
                WpGitHubSync.updateConnectionStatus();
            }
            
            // Restore backup confirmation
            $('.js-restore-backup').on('click', function(e) {
                e.preventDefault();
                if (confirm(wpGitHubSync.strings.confirm)) {
                    var backupId = $(this).data('backup-id');
                    WpGitHubSync.restoreBackup(backupId);
                }
            });
            
            // Compare button
            $('.js-compare-button').on('click', function(e) {
                e.preventDefault();
                WpGitHubSync.compareRepositories();
            });
            
            // Toggle advanced settings
            $('.js-toggle-advanced').on('click', function(e) {
                e.preventDefault();
                $('.wp-github-sync-advanced-settings').slideToggle();
                $(this).find('.dashicons').toggleClass('dashicons-arrow-down dashicons-arrow-up');
            });
            
            // Toggle conditional fields based on checkboxes
            $('#auto_sync').on('change', function() {
                if ($(this).is(':checked')) {
                    $('#sync_interval_container, #auto_deploy_container').slideDown(300);
                } else {
                    $('#sync_interval_container, #auto_deploy_container').slideUp(300);
                }
            });
            
            $('#auto_backup').on('change', function() {
                if ($(this).is(':checked')) {
                    $('#backup-options-container').slideDown(300);
                } else {
                    $('#backup-options-container').slideUp(300);
                }
            });
            
            $('#webhook_sync').on('change', function() {
                if ($(this).is(':checked')) {
                    $('#webhook-settings-container').slideDown(300);
                } else {
                    $('#webhook-settings-container').slideUp(300);
                }
            });
            
            // Authentication method tabs
            $('.wp-github-sync-toggle-tab').on('click', function() {
                var method = $(this).data('method');
                
                if (wpGitHubSync.debug) {
                    console.log('Auth method tab clicked:', method);
                }
                
                // Update active tab
                $('.wp-github-sync-toggle-tab').removeClass('active');
                $(this).addClass('active');
                
                // Show selected auth method fields
                $('.wp-github-sync-auth-method-fields').hide().removeClass('active');
                $('.wp-github-sync-auth-method-fields.' + method).show().addClass('active');
                
                // Check the radio button
                $(this).find('input[type="radio"]').prop('checked', true);
            });
            
            // Initialize auth method tabs
            $('input[name="wp_github_sync_settings[auth_method]"]:checked').each(function() {
                var method = $(this).val();
                
                if (wpGitHubSync.debug) {
                    console.log('Initializing auth method:', method);
                }
                
                $('.wp-github-sync-toggle-tab[data-method="' + method + '"]').addClass('active');
                $('.wp-github-sync-auth-method-fields.' + method).show().addClass('active');
            });
            
            // Password visibility toggle
            $('.wp-github-sync-toggle-password').on('click', function() {
                var $input = $(this).siblings('input');
                var type = $input.attr('type') === 'password' ? 'text' : 'password';
                $input.attr('type', type);
                
                // Toggle icon
                $(this).find('.dashicons')
                    .toggleClass('dashicons-visibility')
                    .toggleClass('dashicons-hidden');
            });
            
            // Copy to clipboard functionality
            $('.wp-github-sync-copy-button').on('click', function() {
                var targetId = $(this).data('clipboard-target');
                var $target = $(targetId);
                
                if ($target.length > 0) {
                    // Select the text
                    $target.select();
                    $target[0].setSelectionRange(0, 99999); // For mobile devices
                    
                    // Copy to clipboard
                    document.execCommand('copy');
                    
                    // Visual feedback
                    var $icon = $(this).find('.dashicons');
                    $icon.removeClass('dashicons-clipboard').addClass('dashicons-yes');
                    
                    // Change back after 2 seconds
                    setTimeout(function() {
                        $icon.removeClass('dashicons-yes').addClass('dashicons-clipboard');
                    }, 2000);
                }
            });
            
            // Generate new webhook secret
            $('#regenerate-webhook-secret').on('click', function() {
                // Generate a random string (16 bytes hex = 32 characters)
                var randomBytes = new Uint8Array(16);
                window.crypto.getRandomValues(randomBytes);
                var secret = Array.from(randomBytes).map(b => b.toString(16).padStart(2, '0')).join('');
                
                // Set the value in the input field
                $('#webhook_secret').val(secret);
                
                // Show the secret (change to text type)
                $('#webhook_secret').attr('type', 'text');
                $('#webhook_secret').siblings('.wp-github-sync-toggle-password')
                    .find('.dashicons')
                    .removeClass('dashicons-visibility')
                    .addClass('dashicons-hidden');
                
                // Visual feedback
                var $button = $(this);
                $button.addClass('disabled').prop('disabled', true);
                
                // Re-enable after 2 seconds
                setTimeout(function() {
                    $button.removeClass('disabled').prop('disabled', false);
                }, 2000);
            });
        },
        
        initComponents: function() {
            // Initialize tabs if present
            if ($('.wp-github-sync-tabs').length) {
                this.initTabs();
            }
            
            // Initialize modals if present
            if ($('.wp-github-sync-modal-trigger').length) {
                this.initModals();
            }
            
            // Initialize tooltips
            this.initTooltips();
            
            // Add form validation
            this.initFormValidation();
        },
        
        /**
         * Initialize form validation
         */
        initFormValidation: function() {
            var self = this;
            
            // Handle settings form submission
            $('.wp-github-sync-settings-form').on('submit', function(e) {
                if (wpGitHubSync.debug) {
                    console.log('Settings form submitted');
                }
                
                // Validate form before submission
                var isValid = true;
                var authMethod = $('input[name="wp_github_sync_settings[auth_method]"]:checked').val() || 'pat';
                
                // Check repository URL (required)
                var repoUrl = $('input[name="wp_github_sync_settings[repo_url]"]').val().trim();
                if (!repoUrl) {
                    isValid = false;
                    self.highlightFieldError('repo_url', 'Repository URL is required');
                }
                
                // Check authentication based on selected method
                if (authMethod === 'pat') {
                    var token = $('input[name="wp_github_sync_settings[access_token]"]').val().trim();
                    if (!token && token !== '********') {
                        isValid = false;
                        self.highlightFieldError('access_token', 'Personal Access Token is required');
                    }
                } else if (authMethod === 'oauth') {
                    var oauthToken = $('input[name="wp_github_sync_settings[oauth_token]"]').val().trim();
                    if (!oauthToken && oauthToken !== '********') {
                        isValid = false;
                        self.highlightFieldError('oauth_token', 'OAuth Token is required');
                    }
                } else if (authMethod === 'github_app') {
                    var appId = $('input[name="wp_github_sync_settings[github_app_id]"]').val().trim();
                    var installId = $('input[name="wp_github_sync_settings[github_app_installation_id]"]').val().trim();
                    var appKey = $('#github_app_key').val().trim();
                    
                    if (!appId) {
                        isValid = false;
                        self.highlightFieldError('github_app_id', 'GitHub App ID is required');
                    }
                    if (!installId) {
                        isValid = false;
                        self.highlightFieldError('github_app_installation_id', 'Installation ID is required');
                    }
                    if (!appKey) {
                        isValid = false;
                        self.highlightFieldError('github_app_key', 'Private Key is required');
                    }
                }
                
                if (!isValid) {
                    e.preventDefault();
                    return false;
                }
                
                // Form is valid, show loading state
                var $submitButton = $('#save-settings');
                $submitButton.prop('disabled', true).addClass('wp-github-sync-button-loading');
                $submitButton.val(wpGitHubSync.strings.saving || 'Saving...');
                
                // Store form data for comparison later
                sessionStorage.setItem('wp_github_sync_form_data', $(this).serialize());
                
                return true;
            });
            
            // Check for settings update success
            if (window.location.search.indexOf('settings-updated=true') !== -1) {
                $('#save-settings-feedback').fadeIn().delay(3000).fadeOut();
                
                // Highlight saved fields
                $('.wp-github-sync-text-input, .wp-github-sync-select, .wp-github-sync-textarea').addClass('wp-github-sync-saved-field');
                setTimeout(function() {
                    $('.wp-github-sync-saved-field').removeClass('wp-github-sync-saved-field');
                }, 2000);
            }
        },
        
        /**
         * Highlight field with error
         */
        highlightFieldError: function(fieldId, message) {
            var $field = $('#' + fieldId);
            var $wrapper = $field.closest('.wp-github-sync-input-wrapper');
            
            // Add error class
            $wrapper.addClass('wp-github-sync-field-error');
            
            // Show error message
            var $errorMsg = $wrapper.siblings('.wp-github-sync-field-error-message');
            if ($errorMsg.length === 0) {
                $errorMsg = $('<div class="wp-github-sync-field-error-message">' + message + '</div>');
                $wrapper.after($errorMsg);
            } else {
                $errorMsg.text(message);
            }
            
            // Scroll to the error
            $('html, body').animate({
                scrollTop: $wrapper.offset().top - 100
            }, 300);
            
            // Focus the field
            $field.focus();
            
            // Remove error after field is changed
            $field.one('input', function() {
                $wrapper.removeClass('wp-github-sync-field-error');
                $errorMsg.remove();
            });
        },
        
        initTabs: function() {
            // Legacy tabs support
            $('.wp-github-sync-tabs-nav a').on('click', function(e) {
                e.preventDefault();
                
                var target = $(this).attr('href');
                
                // Update active tab
                $('.wp-github-sync-tabs-nav a').removeClass('active');
                $(this).addClass('active');
                
                // Show target tab content
                $('.wp-github-sync-tab-content').removeClass('active');
                $(target).addClass('active');
                
                // Update URL hash without jumping
                if (history.pushState) {
                    history.pushState(null, null, target);
                }
            });
            
            // Modern tabs support (used in settings page)
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
            
            // Check for hash in URL for both tab styles
            var hash = window.location.hash.replace('#', '');
            
            // Try modern tabs first
            if (hash && $('.wp-github-sync-tab[data-tab="' + hash + '"]').length) {
                $('.wp-github-sync-tab[data-tab="' + hash + '"]').trigger('click');
            } 
            // Try legacy tabs
            else if (hash && $(hash).length > 0 && $(hash).hasClass('wp-github-sync-tab-content')) {
                $('.wp-github-sync-tabs-nav a[href="' + hash + '"]').trigger('click');
            } 
            // Default behavior
            else {
                // Activate first tab by default (try both styles)
                if ($('.wp-github-sync-tab').length) {
                    $('.wp-github-sync-tab:first').trigger('click');
                } else if ($('.wp-github-sync-tabs-nav a').length) {
                    $('.wp-github-sync-tabs-nav a:first').trigger('click');
                }
            }
        },
        
        initModals: function() {
            $('.wp-github-sync-modal-trigger').on('click', function(e) {
                e.preventDefault();
                
                var modalId = $(this).data('modal');
                $('#' + modalId).addClass('active');
                
                // Trap focus in modal
                $('#' + modalId).find('.wp-github-sync-modal-close').focus();
            });
            
            // Close modal on backdrop click or close button
            $('.wp-github-sync-modal-close, .wp-github-sync-modal-backdrop').on('click', function() {
                $('.wp-github-sync-modal').removeClass('active');
            });
            
            // Prevent propagation from modal content
            $('.wp-github-sync-modal-content').on('click', function(e) {
                e.stopPropagation();
            });
            
            // Close modal on ESC key
            $(document).on('keyup', function(e) {
                if (e.key === 'Escape' && $('.wp-github-sync-modal.active').length) {
                    $('.wp-github-sync-modal').removeClass('active');
                }
            });
        },
        
        initTooltips: function() {
            $('.wp-github-sync-tooltip').each(function() {
                var $tooltip = $(this);
                var $trigger = $tooltip.find('.wp-github-sync-tooltip-trigger');
                var $content = $tooltip.find('.wp-github-sync-tooltip-content');
                
                $trigger.on('mouseenter', function() {
                    $content.addClass('active');
                }).on('mouseleave', function() {
                    $content.removeClass('active');
                });
            });
        },
        
        startSync: function() {
            var $button = $('.js-sync-button');
            var $status = $('.wp-github-sync-sync-status');
            
            // Disable button and show progress
            $button.prop('disabled', true).text(wpGitHubSync.strings.syncing);
            
            // Create progress bar if not exists
            if ($('.wp-github-sync-progress').length === 0) {
                $status.after('<div class="wp-github-sync-progress"><div class="wp-github-sync-progress-bar" style="width: 0%"></div></div>');
            } else {
                $('.wp-github-sync-progress-bar').css('width', '0%');
            }
            
            // Make API request
            $.ajax({
                url: wpGitHubSync.apiUrl + '/sync',
                method: 'POST',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', wpGitHubSync.apiNonce);
                },
                success: function(response) {
                    $button.prop('disabled', false).text(wpGitHubSync.strings.syncComplete);
                    $('.wp-github-sync-progress-bar').css('width', '100%');
                    
                    // Update status
                    $status.html('<div class="wp-github-sync-status success"><span class="dashicons dashicons-yes"></span> ' + response.message + '</div>');
                    
                    // Reload page after delay
                    setTimeout(function() {
                        window.location.reload();
                    }, 2000);
                },
                error: function(xhr) {
                    $button.prop('disabled', false).text(wpGitHubSync.strings.syncFailed);
                    
                    // Update status with error
                    var message = xhr.responseJSON && xhr.responseJSON.message ? 
                                 xhr.responseJSON.message : 
                                 'Unknown error occurred';
                                 
                    $status.html('<div class="wp-github-sync-status error"><span class="dashicons dashicons-warning"></span> ' + message + '</div>');
                }
            });
        },
        
        createBackup: function() {
            var $button = $('.js-create-backup');
            
            // Disable button
            $button.prop('disabled', true);
            
            // Show loading indicator
            this.showNotice('Creating backup...', 'info');
            
            // Make API request
            $.ajax({
                url: wpGitHubSync.apiUrl + '/backup',
                method: 'POST',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', wpGitHubSync.apiNonce);
                },
                success: function(response) {
                    $button.prop('disabled', false);
                    
                    // Show success message
                    WpGitHubSync.showNotice(response.message, 'success');
                    
                    // Reload page after delay
                    setTimeout(function() {
                        window.location.reload();
                    }, 2000);
                },
                error: function(xhr) {
                    $button.prop('disabled', false);
                    
                    // Show error message
                    var message = xhr.responseJSON && xhr.responseJSON.message ? 
                                 xhr.responseJSON.message : 
                                 'Unknown error occurred';
                                 
                    WpGitHubSync.showNotice(message, 'error');
                }
            });
        },
        
        testConnection: function() {
            var $button = $('#test-connection, .js-test-connection');
            var $status = $('#wp-github-sync-connection-status');
            var $result = $('#connection-test-result, .js-connection-result');
            
            // Log debug info
            if (wpGitHubSync.debug) {
                console.log('Testing connection with form data');
            }
            
            // Get auth method
            var authMethod = $('input[name="wp_github_sync_settings[auth_method]"]:checked').val() || 'pat';
            
            // Get form data
            var data = {
                action: 'wp_github_sync_test_connection', // AJAX action name
                nonce: wpGitHubSync.apiNonce,
                repo_url: $('input[name="wp_github_sync_settings[repo_url]"]').val(),
                branch: $('input[name="wp_github_sync_settings[sync_branch]"]').val() || 'main',
                auth_method: authMethod
            };
            
            // Get token based on auth method
            if (authMethod === 'pat') {
                data.token = $('input[name="wp_github_sync_settings[access_token]"]').val();
            } else if (authMethod === 'oauth') {
                data.token = $('input[name="wp_github_sync_settings[oauth_token]"]').val();
            } else if (authMethod === 'github_app') {
                data.github_app_id = $('input[name="wp_github_sync_settings[github_app_id]"]').val();
                data.github_app_installation_id = $('input[name="wp_github_sync_settings[github_app_installation_id]"]').val();
                data.github_app_key = $('#github_app_key').val();
            }
            
            // Log the data we're sending
            if (wpGitHubSync.debug) {
                console.log('Test connection data:', JSON.parse(JSON.stringify(data)));
            }
            
            // Validation
            if (!data.repo_url) {
                this.showConnectionError('Repository URL is required');
                return;
            }
            
            if (authMethod === 'pat' && !data.token) {
                this.showConnectionError('Personal Access Token is required');
                return;
            } else if (authMethod === 'oauth' && !data.token) {
                this.showConnectionError('OAuth Token is required');
                return;
            } else if (authMethod === 'github_app' && (!data.github_app_id || !data.github_app_installation_id || !data.github_app_key)) {
                this.showConnectionError('All GitHub App credentials are required');
                return;
            }
            
            // Disable button and show loading state
            $button.prop('disabled', true);
            if ($button.find('.dashicons').length) {
                $button.find('.dashicons').addClass('wp-github-sync-spin');
            }
            
            // Update status indicator to testing state
            if ($status.length) {
                $status.removeClass('pending success error')
                    .addClass('testing')
                    .find('.dashicons')
                    .removeClass('dashicons-warning dashicons-yes dashicons-no')
                    .addClass('dashicons-update wp-github-sync-spin');
                $status.find('.status-text').text(wpGitHubSync.strings.testing || 'Testing connection...');
            }
            
            // Make AJAX request to our WordPress handler
            $.ajax({
                url: wpGitHubSync.ajaxUrl,
                method: 'POST',
                data: data,
                success: function(response) {
                    // Log the response for debugging
                    if (wpGitHubSync.debug) {
                        console.log('Test connection response:', response);
                    }
                    
                    // Re-enable button
                    $button.prop('disabled', false);
                    if ($button.find('.dashicons').length) {
                        $button.find('.dashicons').removeClass('wp-github-sync-spin');
                    }
                    
                    if (response.success) {
                        // Show success message
                        if ($status.length) {
                            $status.removeClass('pending testing error')
                                .addClass('success')
                                .find('.dashicons')
                                .removeClass('dashicons-warning dashicons-update dashicons-no wp-github-sync-spin')
                                .addClass('dashicons-yes');
                            $status.find('.status-text').text(response.data.message || 'Connection successful!');
                        }
                        
                        if ($result.length) {
                            $result.html('<div class="wp-github-sync-status success"><span class="dashicons dashicons-yes"></span> ' + 
                                (response.data.message || 'Connection successful!') + '</div>');
                        }
                    } else {
                        // Show error message
                        WpGitHubSync.showConnectionError(response.data.message || 'Connection failed', $status, $result);
                    }
                },
                error: function(xhr, status, error) {
                    // Re-enable button
                    $button.prop('disabled', false);
                    if ($button.find('.dashicons').length) {
                        $button.find('.dashicons').removeClass('wp-github-sync-spin');
                    }
                    
                    // Try to parse error response
                    var message = 'Connection test failed';
                    
                    try {
                        if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                            message = xhr.responseJSON.data.message;
                        } else if (xhr.responseText) {
                            var resp = JSON.parse(xhr.responseText);
                            if (resp.data && resp.data.message) {
                                message = resp.data.message;
                            }
                        }
                    } catch (e) {
                        console.error('Error parsing AJAX response:', e);
                    }
                    
                    // Show error message
                    WpGitHubSync.showConnectionError(message, $status, $result);
                }
            });
        },
        
        /**
         * Display connection error
         */
        showConnectionError: function(message, $status, $result) {
            if (!$status) {
                $status = $('#wp-github-sync-connection-status');
            }
            if (!$result) {
                $result = $('#connection-test-result');
            }
            
            // Update status indicator if it exists
            if ($status.length) {
                $status.removeClass('pending testing success')
                    .addClass('error')
                    .find('.dashicons')
                    .removeClass('dashicons-warning dashicons-update dashicons-yes wp-github-sync-spin')
                    .addClass('dashicons-no');
                $status.find('.status-text').text(message);
            }
            
            // Update result container if it exists
            if ($result.length) {
                $result.html('<div class="wp-github-sync-status error"><span class="dashicons dashicons-no"></span> ' + 
                    message + '</div>');
            }
        },
        
        /**
         * Update connection status based on current settings
         */
        updateConnectionStatus: function() {
            var $status = $('#wp-github-sync-connection-status');
            if (!$status.length) return;
            
            // Check if we have settings with a repo URL and token
            var settings = wpGitHubSync.settings || {};
            var hasRepo = settings.repo_url && settings.repo_url.trim() !== '';
            var hasToken = false;
            
            if (settings.auth_method === 'pat') {
                hasToken = settings.access_token && settings.access_token.trim() !== '';
            } else if (settings.auth_method === 'oauth') {
                hasToken = settings.oauth_token && settings.oauth_token.trim() !== '';
            } else if (settings.auth_method === 'github_app') {
                hasToken = settings.github_app_id && settings.github_app_installation_id && settings.github_app_key;
            }
            
            if (hasRepo && hasToken) {
                // We have credentials, so let's test the connection automatically
                this.testConnection();
            } else {
                // No credentials, show pending status
                $status.removeClass('success error testing')
                    .addClass('pending')
                    .find('.dashicons')
                    .removeClass('dashicons-yes dashicons-no dashicons-update wp-github-sync-spin')
                    .addClass('dashicons-warning');
                $status.find('.status-text').text('Not Configured');
            }
        },
        
        restoreBackup: function(backupId) {
            // Show loading state
            WpGitHubSync.showNotice('Restoring backup...', 'info');
            
            // Make API request
            $.ajax({
                url: wpGitHubSync.apiUrl + '/restore/' + backupId,
                method: 'POST',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', wpGitHubSync.apiNonce);
                },
                success: function(response) {
                    // Show success message
                    WpGitHubSync.showNotice(response.message, 'success');
                    
                    // Reload page after delay
                    setTimeout(function() {
                        window.location.reload();
                    }, 2000);
                },
                error: function(xhr) {
                    // Show error message
                    var message = xhr.responseJSON && xhr.responseJSON.message ? 
                                 xhr.responseJSON.message : 
                                 'Restore failed';
                                 
                    WpGitHubSync.showNotice(message, 'error');
                }
            });
        },
        
        compareRepositories: function() {
            var $button = $('.js-compare-button');
            var $result = $('.js-compare-result');
            
            // Disable button
            $button.prop('disabled', true).text('Comparing...');
            
            // Get selected branches/commits
            var data = {
                source: $('#compare_source').val(),
                target: $('#compare_target').val()
            };
            
            // Make API request
            $.ajax({
                url: wpGitHubSync.apiUrl + '/compare',
                method: 'POST',
                data: data,
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', wpGitHubSync.apiNonce);
                },
                success: function(response) {
                    $button.prop('disabled', false).text('Compare');
                    
                    // Build comparison table
                    var html = '<div class="wp-github-sync-comparison">';
                    
                    if (response.files.length === 0) {
                        html += '<div class="wp-github-sync-status success"><span class="dashicons dashicons-yes"></span> No differences found</div>';
                    } else {
                        html += '<h3>Changes: ' + response.files.length + ' files</h3>';
                        html += '<table class="wp-list-table widefat fixed striped">';
                        html += '<thead><tr><th>File</th><th>Status</th></tr></thead>';
                        html += '<tbody>';
                        
                        $.each(response.files, function(i, file) {
                            var statusClass = '';
                            var statusIcon = '';
                            
                            switch (file.status) {
                                case 'added':
                                    statusClass = 'success';
                                    statusIcon = 'plus';
                                    break;
                                case 'modified':
                                    statusClass = 'warning';
                                    statusIcon = 'edit';
                                    break;
                                case 'removed':
                                    statusClass = 'error';
                                    statusIcon = 'trash';
                                    break;
                            }
                            
                            html += '<tr>';
                            html += '<td>' + file.filename + '</td>';
                            html += '<td class="wp-github-sync-status-' + statusClass + '">';
                            html += '<span class="dashicons dashicons-' + statusIcon + '"></span> ';
                            html += file.status;
                            html += '</td>';
                            html += '</tr>';
                        });
                        
                        html += '</tbody></table>';
                    }
                    
                    html += '</div>';
                    
                    $result.html(html);
                },
                error: function(xhr) {
                    $button.prop('disabled', false).text('Compare');
                    
                    // Show error message
                    var message = xhr.responseJSON && xhr.responseJSON.message ? 
                                 xhr.responseJSON.message : 
                                 'Comparison failed';
                                 
                    $result.html('<div class="wp-github-sync-status error"><span class="dashicons dashicons-warning"></span> ' + message + '</div>');
                }
            });
        },
        
        showNotice: function(message, type) {
            var noticeHtml = '<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>';
            
            // Remove any existing notices
            $('.wp-github-sync-notices').html(noticeHtml);
            
            // Auto-dismiss after 5 seconds
            setTimeout(function() {
                $('.notice').fadeOut();
            }, 5000);
        }
    };
    
})(jQuery);