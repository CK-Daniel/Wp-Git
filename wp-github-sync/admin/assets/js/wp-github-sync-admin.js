/**
 * Admin JavaScript for WordPress GitHub Sync plugin.
 */
(function($) {
    'use strict';
    
    // Initialize on document ready
    $(document).ready(function() {
        // Tab functionality for all pages
        function initializeTabs() {
            // Tab click handler
            $('.wp-github-sync-tab').on('click', function() {
                $('.wp-github-sync-tab').removeClass('active');
                $(this).addClass('active');
                
                var tab = $(this).data('tab');
                
                $('.wp-github-sync-tab-content').removeClass('active');
                // Try to find tab content by ID first, if it fails, try by data-tab attribute
                var $tabContent = $('#' + tab + '-tab-content');
                if ($tabContent.length === 0) {
                    $tabContent = $('.wp-github-sync-tab-content[data-tab="' + tab + '"]');
                }
                $tabContent.addClass('active');
                
                // Update URL hash
                window.location.hash = tab;
            });
            
            // Check if URL has a hash for a tab
            var hash = window.location.hash.substring(1);
            if (hash && $('.wp-github-sync-tab[data-tab="' + hash + '"]').length) {
                $('.wp-github-sync-tab[data-tab="' + hash + '"]').click();
            } else if ($('.wp-github-sync-tab.active').length === 0 && $('.wp-github-sync-tab').length > 0) {
                // Activate first tab if none is active
                $('.wp-github-sync-tab').first().click();
            }
            
            // Handle tab link buttons (for easy navigation between tabs)
            $('.wp-github-sync-tab-link').on('click', function(e) {
                e.preventDefault();
                var targetTab = $(this).data('tab-target');
                if (targetTab && $('.wp-github-sync-tab[data-tab="' + targetTab + '"]').length) {
                    $('.wp-github-sync-tab[data-tab="' + targetTab + '"]').click();
                    
                    // Scroll to top of tabs
                    $('html, body').animate({
                        scrollTop: $('.wp-github-sync-tabs').offset().top - 50
                    }, 300);
                }
            });
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
                    nonce: wpGitHubSync.nonce
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
                $('.wp-github-sync-loading-message').text('Syncing all files to GitHub...');
                $('.wp-github-sync-loading-submessage').text('This may take some time depending on the size of your site.');
                
                $.ajax({
                    url: wpGitHubSync.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'wp_github_sync_full_sync',
                        nonce: wpGitHubSync.nonce
                    },
                    success: function(response) {
                        hideOverlay();
                        if (response.success) {
                            alert(response.data.message || 'Sync completed successfully!');
                            // Don't reload if it's a coming soon message
                            if (response.data.message && response.data.message.indexOf('coming soon') === -1) {
                                window.location.reload();
                            }
                        } else {
                            alert(response.data.message || wpGitHubSync.strings.error);
                        }
                    },
                    error: function(xhr, status, error) {
                        hideOverlay();
                        var errorMessage = wpGitHubSync.strings.error;
                        if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                            errorMessage = xhr.responseJSON.data.message;
                        }
                        alert(errorMessage);
                    }
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