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
            $('.js-test-connection').on('click', function(e) {
                e.preventDefault();
                WpGitHubSync.testConnection();
            });
            
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
        },
        
        initTabs: function() {
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
            
            // Check for hash in URL
            var hash = window.location.hash;
            if (hash && $(hash).length > 0 && $(hash).hasClass('wp-github-sync-tab-content')) {
                $('.wp-github-sync-tabs-nav a[href="' + hash + '"]').trigger('click');
            } else {
                // Activate first tab by default
                $('.wp-github-sync-tabs-nav a:first').trigger('click');
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
            var $button = $('.js-test-connection');
            var $result = $('.js-connection-result');
            
            // Disable button
            $button.prop('disabled', true).text('Testing...');
            
            // Get form data
            var data = {
                repo_url: $('#repo_url').val(),
                auth_method: $('input[name="auth_method"]:checked').val(),
                access_token: $('#access_token').val()
            };
            
            // Make API request
            $.ajax({
                url: wpGitHubSync.apiUrl + '/test-connection',
                method: 'POST',
                data: data,
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', wpGitHubSync.apiNonce);
                },
                success: function(response) {
                    $button.prop('disabled', false).text('Test Connection');
                    
                    // Show success message
                    $result.html('<div class="wp-github-sync-status success"><span class="dashicons dashicons-yes"></span> ' + response.message + '</div>');
                },
                error: function(xhr) {
                    $button.prop('disabled', false).text('Test Connection');
                    
                    // Show error message
                    var message = xhr.responseJSON && xhr.responseJSON.message ? 
                                 xhr.responseJSON.message : 
                                 'Connection failed';
                                 
                    $result.html('<div class="wp-github-sync-status error"><span class="dashicons dashicons-warning"></span> ' + message + '</div>');
                }
            });
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