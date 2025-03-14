/**
 * Admin JavaScript for WordPress GitHub Sync plugin.
 *
 * @package WPGitHubSync
 */
(function($) {
    'use strict';
    
    // Initialize on document ready
    $(document).ready(function() {
        // Tab functionality for all pages
        function initializeTabs() {
            // Remove any existing handlers to prevent duplicates (important for PJAX or Turbo sites)
            $('.wp-github-sync-tab').off('click.wp-github-sync');
            $('.wp-github-sync-tab-link').off('click.wp-github-sync');
            
            // Tab click handler with namespace to allow later removal if needed
            $('.wp-github-sync-tab').on('click.wp-github-sync', function(e) {
                e.preventDefault();
                
                // Get active tab info before changing
                var previousTab = $('.wp-github-sync-tab.active').data('tab');
                
                // Update tab state
                $('.wp-github-sync-tab').removeClass('active');
                $(this).addClass('active');
                
                var tab = $(this).data('tab');
                
                // Validate tab name to prevent XSS
                if (!tab || typeof tab !== 'string' || !tab.match(/^[a-zA-Z0-9_-]+$/)) {
                    console.error('Invalid tab name');
                    return;
                }
                
                // Hide all tab content
                $('.wp-github-sync-tab-content').removeClass('active');
                
                // Find the right tab content using both methods
                var $tabContent = $('#' + tab + '-tab-content');
                if ($tabContent.length === 0) {
                    $tabContent = $('.wp-github-sync-tab-content[data-tab="' + tab + '"]');
                }
                
                // If tab content exists, show it
                if ($tabContent.length > 0) {
                    $tabContent.addClass('active');
                    
                    // Update URL hash, but only if it changed (avoids duplicate history entries)
                    if (previousTab !== tab) {
                        // Use history API instead of directly setting location.hash to avoid page scroll
                        if (history.pushState) {
                            history.pushState(null, null, '#' + tab);
                        } else {
                            window.location.hash = tab;
                        }
                    }
                    
                    // Trigger custom event for other scripts to respond to tab change
                    $(document).trigger('wp-github-sync-tab-changed', [tab, previousTab]);
                } else {
                    console.error('Tab content not found for tab: ' + tab);
                }
            });
            
            // Initialize tabs from URL hash or default to first tab
            var initializeFromHash = function() {
                var hash = window.location.hash.substring(1);
                if (hash && $('.wp-github-sync-tab[data-tab="' + hash + '"]').length) {
                    // Use direct trigger to avoid duplicate history entries
                    $('.wp-github-sync-tab[data-tab="' + hash + '"]').trigger('click.wp-github-sync');
                } else if ($('.wp-github-sync-tab.active').length === 0 && $('.wp-github-sync-tab').length > 0) {
                    // If no tab is active, activate the first one
                    $('.wp-github-sync-tab').first().trigger('click.wp-github-sync');
                }
            };
            
            // Initialize from hash on page load
            initializeFromHash();
            
            // Handle tab link buttons for easy navigation between tabs
            $('.wp-github-sync-tab-link').on('click.wp-github-sync', function(e) {
                e.preventDefault();
                var targetTab = $(this).data('tab-target');
                
                // Validate tab name to prevent XSS
                if (!targetTab || typeof targetTab !== 'string' || !targetTab.match(/^[a-zA-Z0-9_-]+$/)) {
                    console.error('Invalid target tab name');
                    return;
                }
                
                if (targetTab && $('.wp-github-sync-tab[data-tab="' + targetTab + '"]').length) {
                    $('.wp-github-sync-tab[data-tab="' + targetTab + '"]').trigger('click.wp-github-sync');
                    
                    // Scroll to tabs with animation
                    $('html, body').animate({
                        scrollTop: $('.wp-github-sync-tabs').offset().top - 50
                    }, 300);
                }
            });
            
            // Handle hashchange event for back/forward browser navigation
            $(window).off('hashchange.wp-github-sync').on('hashchange.wp-github-sync', function() {
                initializeFromHash();
            });
            
            // Log successful tab initialization
            console.log('WP GitHub Sync: Tabs initialized');
        }
        
        // Initialize tabs
        initializeTabs();
        // Handle the initial sync process
        $('#initial_sync_button').on('click', function() {
            var createNewRepo = $('#create_new_repo').is(':checked');
            var repoName = $('#new_repo_name').val();
            
            if (createNewRepo && !repoName) {
                alert('Please enter a repository name.');
                return;
            }
            
            showOverlay();
            $('.wp-github-sync-loading-message').text('Setting up GitHub Sync...');
            
            if (createNewRepo) {
                $('.wp-github-sync-loading-submessage').text('Creating new repository...');
            } else {
                $('.wp-github-sync-loading-submessage').text('Connecting to existing repository...');
            }
            
            $.ajax({
                url: wpGitHubSync.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wp_github_sync_initial_sync',
                    create_new_repo: createNewRepo ? 1 : 0,
                    repo_name: repoName,
                    nonce: wpGitHubSync.initialSyncNonce // Using the specific nonce for initial sync
                },
                success: function(response) {
                    if (response.success) {
                        $('.wp-github-sync-loading-message').text('Success!');
                        $('.wp-github-sync-loading-submessage').text(response.data.message);
                        
                        // Redirect to dashboard after 2 seconds
                        setTimeout(function() {
                            window.location.href = wpGitHubSync.adminUrl + '?page=wp-github-sync';
                        }, 2000);
                    } else {
                        $('.wp-github-sync-loading-message').text('Error');
                        $('.wp-github-sync-loading-submessage').text(response.data.message);
                        
                        // Hide overlay after 3 seconds
                        setTimeout(function() {
                            hideOverlay();
                        }, 3000);
                    }
                },
                error: function() {
                    $('.wp-github-sync-loading-message').text('Error');
                    $('.wp-github-sync-loading-submessage').text('An unexpected error occurred. Please try again.');
                    
                    // Hide overlay after 3 seconds
                    setTimeout(function() {
                        hideOverlay();
                    }, 3000);
                }
            });
        });
        // Handle deploying the latest changes
        $('.wp-github-sync-deploy').on('click', function() {
            if (confirm(wpGitHubSync.strings.confirmDeploy)) {
                showOverlay();
                
                $.ajax({
                    url: wpGitHubSync.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'wp_github_sync_deploy',
                        nonce: wpGitHubSync.nonce
                    },
                    success: function(response) {
                        hideOverlay();
                        if (response.success) {
                            alert(response.data.message);
                            window.location.reload();
                        } else {
                            alert(response.data.message || wpGitHubSync.strings.error);
                        }
                    },
                    error: function() {
                        hideOverlay();
                        alert(wpGitHubSync.strings.error);
                    }
                });
            }
        });
        
        // Handle switching branches
        $('.wp-github-sync-switch-branch').on('click', function() {
            var branch = $('#wp-github-sync-branch-select').val();
            
            if (!branch) {
                return;
            }
            
            if (confirm(wpGitHubSync.strings.confirmSwitchBranch)) {
                showOverlay();
                
                $.ajax({
                    url: wpGitHubSync.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'wp_github_sync_switch_branch',
                        nonce: wpGitHubSync.nonce,
                        branch: branch
                    },
                    success: function(response) {
                        hideOverlay();
                        if (response.success) {
                            alert(response.data.message);
                            window.location.reload();
                        } else {
                            alert(response.data.message || wpGitHubSync.strings.error);
                        }
                    },
                    error: function() {
                        hideOverlay();
                        alert(wpGitHubSync.strings.error);
                    }
                });
            }
        });
        
        // Handle rolling back to a previous commit
        $('.wp-github-sync-rollback').on('click', function() {
            var commit = $(this).data('commit');
            
            if (!commit) {
                return;
            }
            
            if (confirm(wpGitHubSync.strings.confirmRollback)) {
                showOverlay();
                
                $.ajax({
                    url: wpGitHubSync.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'wp_github_sync_rollback',
                        nonce: wpGitHubSync.nonce,
                        commit: commit
                    },
                    success: function(response) {
                        hideOverlay();
                        if (response.success) {
                            alert(response.data.message);
                            window.location.reload();
                        } else {
                            alert(response.data.message || wpGitHubSync.strings.error);
                        }
                    },
                    error: function() {
                        hideOverlay();
                        alert(wpGitHubSync.strings.error);
                    }
                });
            }
        });
        
        // Handle checking for updates
        $('.wp-github-sync-check-updates').on('click', function() {
            showOverlay();
            
            // First, simulate a check for updates operation
            setTimeout(function() {
                // Then simulate a deployment operation if updates are available
                hideOverlay();
                window.location.reload();
            }, 2000);
        });
        
        // Handle full sync to GitHub
        $('.wp-github-sync-full-sync').on('click', function() {
            if (confirm(wpGitHubSync.strings.confirmFullSync || 'This will sync all your WordPress files to GitHub. Continue?')) {
                showOverlay();
                
                // Set up progress indicators
                $('.wp-github-sync-loading-message').text('Preparing for sync...');
                $('.wp-github-sync-loading-submessage').text('Initializing...');
                
                // Show that this might take time
                setTimeout(function() {
                    $('.wp-github-sync-loading-message').text('Syncing files to GitHub...');
                    $('.wp-github-sync-loading-submessage').text('Collecting files from WordPress...');
                }, 2000);
                
                // Update progress during the process
                var updateProgress = function() {
                    // If overlay is still visible, we're still processing
                    if ($('.wp-github-sync-overlay').is(':visible')) {
                        // Simulate progress updates with realistic messages
                        var messages = [
                            'Creating file blobs...',
                            'Building file trees...',
                            'Preparing commit data...',
                            'Uploading to GitHub...',
                            'Finalizing changes...'
                        ];
                        
                        // Randomly select a message that hasn't been shown yet
                        var currentMessage = $('.wp-github-sync-loading-submessage').text();
                        var filteredMessages = messages.filter(function(msg) {
                            return msg !== currentMessage;
                        });
                        
                        if (filteredMessages.length > 0) {
                            var randomIndex = Math.floor(Math.random() * filteredMessages.length);
                            $('.wp-github-sync-loading-submessage').text(filteredMessages[randomIndex]);
                        }
                        
                        // Continue updating progress
                        setTimeout(updateProgress, 5000);
                    }
                };
                
                // Start progress updates
                setTimeout(updateProgress, 5000);
                
                $.ajax({
                    url: wpGitHubSync.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'wp_github_sync_full_sync',
                        nonce: wpGitHubSync.nonce
                    },
                    success: function(response) {
                        // Update progress before showing result
                        $('.wp-github-sync-loading-message').text('Sync Complete!');
                        $('.wp-github-sync-loading-submessage').text('Processing results...');
                        
                        // Create a unique message ID to prevent duplicates
                        var messageId = 'wp-github-sync-message-' + new Date().getTime();
                        
                        setTimeout(function() {
                            hideOverlay();
                            
                            // Remove any existing messages
                            $('.wp-github-sync-info-box.temp-message').remove();
                            
                            if (response.success) {
                                // Show success message
                                var successBox = $('<div id="' + messageId + '" class="wp-github-sync-info-box success temp-message" style="margin: 20px 0; display: none;">'+
                                    '<div class="wp-github-sync-info-box-icon">'+
                                    '<span class="dashicons dashicons-yes-alt"></span>'+
                                    '</div>'+
                                    '<div class="wp-github-sync-info-box-content">'+
                                    '<h4 class="wp-github-sync-info-box-title">Sync Successful</h4>'+
                                    '<p class="wp-github-sync-info-box-message">' + 
                                    (response.data.message || 'All files have been successfully synced to GitHub!') + 
                                    '</p>'+
                                    '</div>'+
                                    '</div>');
                                
                                // Insert success message at the top of the content
                                $('.wp-github-sync-card').first().prepend(successBox);
                                
                                // Fade in the message
                                successBox.fadeIn(300);
                                
                                // Scroll to the success message
                                $('html, body').animate({
                                    scrollTop: successBox.offset().top - 50
                                }, 500);
                                
                                // Update UI with success indicators
                                $('.wp-github-sync-status-value:contains("Up to date")').html(
                                    '<span class="wp-github-sync-status-up-to-date">'+
                                    '<span class="dashicons dashicons-yes-alt"></span>'+
                                    'Up to date (synced just now)'+
                                    '</span>'
                                );
                                
                                // Reload after a delay to show updated status
                                setTimeout(function() {
                                    window.location.reload();
                                }, 5000);
                            } else {
                                // Show error message
                                var errorBox = $('<div id="' + messageId + '" class="wp-github-sync-info-box error temp-message" style="margin: 20px 0; display: none;">'+
                                    '<div class="wp-github-sync-info-box-icon">'+
                                    '<span class="dashicons dashicons-warning"></span>'+
                                    '</div>'+
                                    '<div class="wp-github-sync-info-box-content">'+
                                    '<h4 class="wp-github-sync-info-box-title">Sync Failed</h4>'+
                                    '<p class="wp-github-sync-info-box-message">' + 
                                    (response.data.message || wpGitHubSync.strings.error) + 
                                    '</p>'+
                                    '</div>'+
                                    '</div>');
                                
                                // Insert error message at the top of the content
                                $('.wp-github-sync-card').first().prepend(errorBox);
                                
                                // Fade in the message
                                errorBox.fadeIn(300);
                                
                                // Scroll to the error message
                                $('html, body').animate({
                                    scrollTop: errorBox.offset().top - 50
                                }, 500);
                            }
                        }, 1000);
                    },
                    error: function(xhr, status, error) {
                        hideOverlay();
                        var errorMessage = wpGitHubSync.strings.error;
                        if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                            errorMessage = xhr.responseJSON.data.message;
                        }
                        
                        // Show error message
                        var errorBox = $('<div class="wp-github-sync-info-box error" style="margin: 20px 0;">'+
                            '<div class="wp-github-sync-info-box-icon">'+
                            '<span class="dashicons dashicons-warning"></span>'+
                            '</div>'+
                            '<div class="wp-github-sync-info-box-content">'+
                            '<h4 class="wp-github-sync-info-box-title">Sync Failed</h4>'+
                            '<p class="wp-github-sync-info-box-message">' + errorMessage + '</p>'+
                            '</div>'+
                            '</div>');
                        
                        // Insert error message at the top of the content
                        $('.wp-github-sync-card').first().prepend(errorBox);
                        
                        // Scroll to the error message
                        $('html, body').animate({
                            scrollTop: errorBox.offset().top - 50
                        }, 500);
                    },
                    timeout: 600000 // 10 minute timeout for large sites
                });
            }
        });
        
        // Handle refreshing branches
        $('.wp-github-sync-refresh-branches').on('click', function() {
            showOverlay();
            
            $.ajax({
                url: wpGitHubSync.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wp_github_sync_refresh_branches',
                    nonce: wpGitHubSync.nonce
                },
                success: function(response) {
                    hideOverlay();
                    if (response.success) {
                        // Update the branch dropdown
                        var $branchSelect = $('#wp-github-sync-branch-select');
                        var currentBranch = $branchSelect.val();
                        
                        // Clear current options
                        $branchSelect.empty();
                        
                        // Add new options
                        $.each(response.data.branches, function(index, branch) {
                            $branchSelect.append(
                                $('<option></option>')
                                    .val(branch)
                                    .text(branch)
                                    .prop('selected', branch === currentBranch)
                            );
                        });
                        
                        alert('Branches refreshed successfully.');
                    } else {
                        alert(response.data.message || wpGitHubSync.strings.error);
                    }
                },
                error: function() {
                    hideOverlay();
                    alert(wpGitHubSync.strings.error);
                }
            });
        });
        
        // Handle webhook secret regeneration
        $('.wp-github-sync-regenerate-webhook').on('click', function() {
            if (confirm(wpGitHubSync.strings.confirmRegenerateWebhook)) {
                showOverlay();
                
                $.ajax({
                    url: wpGitHubSync.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'wp_github_sync_regenerate_webhook',
                        nonce: wpGitHubSync.nonce
                    },
                    success: function(response) {
                        hideOverlay();
                        if (response.success) {
                            // Update the webhook secret field
                            $('#wp_github_sync_webhook_secret').val(response.data.secret);
                            alert(response.data.message);
                        } else {
                            alert(response.data.message || wpGitHubSync.strings.error);
                        }
                    },
                    error: function() {
                        hideOverlay();
                        alert(wpGitHubSync.strings.error);
                    }
                });
            }
        });
        
        // Handle copying webhook secret
        $('.wp-github-sync-copy-webhook').on('click', function() {
            var webhookSecret = $('#wp_github_sync_webhook_secret');
            
            if (webhookSecret.length) {
                webhookSecret.select();
                document.execCommand('copy');
                
                // Change button text temporarily
                var $button = $(this);
                var originalText = $button.text();
                
                $button.text('Copied!');
                
                setTimeout(function() {
                    $button.text(originalText);
                }, 2000);
            }
        });
        
        // Handle showing/hiding token
        $('.wp-github-sync-reveal-token').on('click', function() {
            var $tokenField = $('#wp_github_sync_access_token');
            var $button = $(this);
            
            if ($tokenField.attr('type') === 'password') {
                $tokenField.attr('type', 'text');
                $button.text('Hide');
            } else {
                $tokenField.attr('type', 'password');
                $button.text('Show');
            }
        });
        
        // Handle OAuth connection
        $('.wp-github-sync-connect-oauth').on('click', function() {
            showOverlay();
            
            $.ajax({
                url: wpGitHubSync.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wp_github_sync_oauth_connect',
                    nonce: wpGitHubSync.nonce
                },
                success: function(response) {
                    hideOverlay();
                    if (response.success) {
                        // Open GitHub OAuth in a new window/tab
                        window.open(response.data.oauth_url, '_blank');
                    } else {
                        alert(response.data.message || wpGitHubSync.strings.error);
                    }
                },
                error: function() {
                    hideOverlay();
                    alert(wpGitHubSync.strings.error);
                }
            });
        });
        
        // Handle OAuth disconnection
        $('.wp-github-sync-disconnect-oauth').on('click', function() {
            if (confirm('Are you sure you want to disconnect from GitHub?')) {
                showOverlay();
                
                $.ajax({
                    url: wpGitHubSync.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'wp_github_sync_oauth_disconnect',
                        nonce: wpGitHubSync.nonce
                    },
                    success: function(response) {
                        hideOverlay();
                        if (response.success) {
                            alert(response.data.message);
                            window.location.reload();
                        } else {
                            alert(response.data.message || wpGitHubSync.strings.error);
                        }
                    },
                    error: function() {
                        hideOverlay();
                        alert(wpGitHubSync.strings.error);
                    }
                });
            }
        });
        
        // Handle authentication method toggle
        $('input[name="wp_github_sync_auth_method"]').on('change', function() {
            var method = $(this).val();
            
            if (method === 'pat') {
                $('.wp-github-sync-pat-field').show();
                $('.wp-github-sync-oauth-field').hide();
            } else if (method === 'oauth') {
                $('.wp-github-sync-pat-field').hide();
                $('.wp-github-sync-oauth-field').show();
            }
        });
        
        // Trigger authentication method toggle on load
        $('input[name="wp_github_sync_auth_method"]:checked').trigger('change');
    });
    
    // Helper function to show the loading overlay
    function showOverlay() {
        $('.wp-github-sync-overlay').fadeIn(300);
    }
    
    // Helper function to hide the loading overlay
    function hideOverlay() {
        $('.wp-github-sync-overlay').fadeOut(300);
    }
})(jQuery);