/**
 * Dashboard specific JavaScript functionality
 */
(function($) {
    'use strict';

    // Initialize Dashboard functionality
    function initDashboard() {
        bindTabSwitching();
        bindActionButtons();
        handleBranchManagement();
        setupDeveloperTools();
    }

    // Tab switching functionality
    function bindTabSwitching() {
        $('.wp-github-sync-tab').on('click', function() {
            // Remove active class from all tabs and contents
            $('.wp-github-sync-tab').removeClass('active');
            $('.wp-github-sync-tab-content').removeClass('active');
            
            // Add active class to clicked tab
            $(this).addClass('active');
            
            // Show corresponding tab content
            const tabId = $(this).data('tab');
            $(`#${tabId}-tab-content`).addClass('active');
            
            // Update URL hash for direct linking
            window.location.hash = tabId;
        });
        
        // Tab links inside tab content
        $('.wp-github-sync-tab-link').on('click', function(e) {
            e.preventDefault();
            const targetTab = $(this).data('tab-target');
            $(`.wp-github-sync-tab[data-tab="${targetTab}"]`).click();
        });
        
        // Handle direct linking via URL hash
        function handleUrlHash() {
            const hash = window.location.hash.substring(1);
            if (hash && $(`.wp-github-sync-tab[data-tab="${hash}"]`).length) {
                $(`.wp-github-sync-tab[data-tab="${hash}"]`).click();
            }
        }
        
        // Handle hash on page load and when hash changes
        handleUrlHash();
        $(window).on('hashchange', handleUrlHash);
    }
    
    // Main action buttons functionality
    function bindActionButtons() {
        const nonce = $('#wp-github-sync-nonce').val();
        
        // Deploy latest changes button
        $('.wp-github-sync-deploy').on('click', function() {
            if (!confirm(wpGitHubSync.strings.confirmDeploy)) {
                return;
            }
            
            showOverlay(wpGitHubSync.strings.deploying);
            
            $.ajax({
                url: wpGitHubSync.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wp_github_sync_deploy',
                    nonce: nonce
                },
                success: function(response) {
                    if (response.success) {
                        showOverlay(wpGitHubSync.strings.success, response.data.message);
                        setTimeout(function() {
                            window.location.reload();
                        }, 2000);
                    } else {
                        showOverlay(wpGitHubSync.strings.error, response.data.message);
                        setTimeout(hideOverlay, 3000);
                    }
                },
                error: handleAjaxError
            });
        });
        
        // Check for updates button
        $('.wp-github-sync-check-updates').on('click', function() {
            showOverlay(wpGitHubSync.strings.checkingUpdates);
            
            $.ajax({
                url: wpGitHubSync.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wp_github_sync_check_updates',
                    nonce: nonce
                },
                success: function(response) {
                    if (response.success) {
                        showOverlay(wpGitHubSync.strings.success, response.data.message);
                        setTimeout(function() {
                            window.location.reload();
                        }, 2000);
                    } else {
                        showOverlay(wpGitHubSync.strings.error, response.data.message);
                        setTimeout(hideOverlay, 3000);
                    }
                },
                error: handleAjaxError
            });
        });
        
        // Full sync to GitHub button
        $('.wp-github-sync-full-sync').on('click', function() {
            if (!confirm(wpGitHubSync.strings.confirmFullSync)) {
                return;
            }
            
            showOverlay(wpGitHubSync.strings.syncing);
            
            $.ajax({
                url: wpGitHubSync.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wp_github_sync_full_sync',
                    nonce: nonce
                },
                success: function(response) {
                    if (response.success) {
                        showOverlay(wpGitHubSync.strings.success, response.data.message);
                        setTimeout(function() {
                            window.location.reload();
                        }, 2000);
                    } else {
                        showOverlay(wpGitHubSync.strings.error, response.data.message);
                        setTimeout(hideOverlay, 3000);
                    }
                },
                error: handleAjaxError
            });
        });
    }
    
    // Branch management functionality
    function handleBranchManagement() {
        const nonce = $('#wp-github-sync-nonce').val();
        
        // Switch branch button
        $('.wp-github-sync-switch-branch').on('click', function() {
            const branch = $('#wp-github-sync-branch-select').val();
            
            if (!branch || !confirm(wpGitHubSync.strings.confirmSwitchBranch.replace('%s', branch))) {
                return;
            }
            
            showOverlay(wpGitHubSync.strings.switchingBranch.replace('%s', branch));
            
            $.ajax({
                url: wpGitHubSync.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wp_github_sync_switch_branch',
                    nonce: nonce,
                    branch: branch
                },
                success: function(response) {
                    if (response.success) {
                        showOverlay(wpGitHubSync.strings.success, response.data.message);
                        setTimeout(function() {
                            window.location.reload();
                        }, 2000);
                    } else {
                        showOverlay(wpGitHubSync.strings.error, response.data.message);
                        setTimeout(hideOverlay, 3000);
                    }
                },
                error: handleAjaxError
            });
        });
        
        // Refresh branches button
        $('.wp-github-sync-refresh-branches').on('click', function() {
            showOverlay(wpGitHubSync.strings.refreshingBranches);
            
            $.ajax({
                url: wpGitHubSync.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wp_github_sync_refresh_branches',
                    nonce: nonce
                },
                success: function(response) {
                    if (response.success) {
                        showOverlay(wpGitHubSync.strings.success, wpGitHubSync.strings.branchesRefreshed);
                        
                        // Update branch list in the dropdown
                        const $branchSelect = $('#wp-github-sync-branch-select');
                        $branchSelect.empty();
                        
                        if (response.data.branches && response.data.branches.length > 0) {
                            const currentBranch = $branchSelect.data('current-branch') || '';
                            
                            response.data.branches.forEach(function(branch) {
                                $branchSelect.append(`<option value="${branch}" ${branch === currentBranch ? 'selected' : ''}>${branch}</option>`);
                            });
                        }
                        
                        setTimeout(hideOverlay, 2000);
                    } else {
                        showOverlay(wpGitHubSync.strings.error, response.data.message);
                        setTimeout(hideOverlay, 3000);
                    }
                },
                error: handleAjaxError
            });
        });
        
        // Rollback button
        $('.wp-github-sync-rollback').on('click', function() {
            const commit = $(this).data('commit');
            const commitShort = commit.substring(0, 8);
            
            if (!commit || !confirm(wpGitHubSync.strings.confirmRollback.replace('%s', commitShort))) {
                return;
            }
            
            showOverlay(wpGitHubSync.strings.rollingBack.replace('%s', commitShort));
            
            $.ajax({
                url: wpGitHubSync.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wp_github_sync_rollback',
                    nonce: nonce,
                    commit: commit
                },
                success: function(response) {
                    if (response.success) {
                        showOverlay(wpGitHubSync.strings.success, response.data.message);
                        setTimeout(function() {
                            window.location.reload();
                        }, 2000);
                    } else {
                        showOverlay(wpGitHubSync.strings.error, response.data.message);
                        setTimeout(hideOverlay, 3000);
                    }
                },
                error: handleAjaxError
            });
        });
    }
    
    // Developer tools functionality
    function setupDeveloperTools() {
        const nonce = $('#wp-github-sync-nonce').val();
        
        // Component selection
        $('#wp-github-sync-component-select').on('change', function() {
            const componentValue = $(this).val();
            const $buttons = $('.wp-github-sync-sync-component, .wp-github-sync-diff-component');
            
            if (componentValue) {
                $buttons.prop('disabled', false);
            } else {
                $buttons.prop('disabled', true);
            }
        });
        
        // Regenerate webhook secret
        $('.wp-github-sync-regenerate-webhook').on('click', function() {
            if (!confirm(wpGitHubSync.strings.confirmRegenerateWebhook)) {
                return;
            }
            
            showOverlay(wpGitHubSync.strings.regeneratingWebhook);
            
            $.ajax({
                url: wpGitHubSync.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wp_github_sync_regenerate_webhook',
                    nonce: nonce
                },
                success: function(response) {
                    if (response.success) {
                        showOverlay(wpGitHubSync.strings.success, response.data.message);
                        
                        // Update the webhook secret displayed on the page
                        setTimeout(function() {
                            window.location.reload();
                        }, 2000);
                    } else {
                        showOverlay(wpGitHubSync.strings.error, response.data.message);
                        setTimeout(hideOverlay, 3000);
                    }
                },
                error: handleAjaxError
            });
        });
    }
    
    // Helper functions
    function showOverlay(message, submessage = '') {
        $('.wp-github-sync-loading-message').text(message);
        $('.wp-github-sync-loading-submessage').text(submessage);
        $('.wp-github-sync-overlay').fadeIn(200);
    }
    
    function hideOverlay() {
        $('.wp-github-sync-overlay').fadeOut(200);
    }
    
    function handleAjaxError(xhr, status, error) {
        console.error('AJAX Error:', status, error);
        
        let errorMessage = wpGitHubSync.strings.error;
        if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
            errorMessage = xhr.responseJSON.data.message;
        }
        
        showOverlay(wpGitHubSync.strings.error, errorMessage);
        setTimeout(hideOverlay, 3000);
    }
    
    // Initialize on document ready
    $(document).ready(initDashboard);
    
})(jQuery);