jQuery(document).ready(function($) {
    // Toggle authentication fields based on auth method
    function toggleAuthFields() {
        var authMethod = $('#wp_github_sync_auth_method').val();
        $('.auth-field').hide();
        $('.auth-field-' + authMethod).show();
    }

    // Initialize auth fields
    toggleAuthFields();

    // Listen for auth method changes
    $('#wp_github_sync_auth_method').on('change', toggleAuthFields);

    // Tab switching is handled by admin.js

    // Initial sync button handler removed - Handled by admin.js (prefer background version)

    // GitHub App Connection testing
    $('.wp-github-sync-test-github-app').on('click', function() {
        const $statusArea = $('#github-app-connection-status');
        const appId = $('#wp_github_sync_github_app_id').val();
        const installationId = $('#wp_github_sync_github_app_installation_id').val();
        const privateKey = $('#wp_github_sync_github_app_key').val();
        const repoUrl = $('input[name="wp_github_sync_repository"]').val(); // Get repo URL from general settings

        // Don't test with masked key
        if (privateKey === '********') {
            $statusArea.html(
                '<div class="wp-github-sync-info-box warning" style="margin-top: 10px;">' +
                '<div class="wp-github-sync-info-box-icon"><span class="dashicons dashicons-warning"></span></div>' +
                '<div class="wp-github-sync-info-box-content">' +
                '<p>Please enter your private key. The masked key cannot be used for testing.</p>' +
                '</div></div>'
            );
            return;
        }

        // Check required fields
        if (!appId || !installationId || !privateKey) {
            $statusArea.html(
                '<div class="wp-github-sync-info-box error" style="margin-top: 10px;">' +
                '<div class="wp-github-sync-info-box-icon"><span class="dashicons dashicons-no"></span></div>' +
                '<div class="wp-github-sync-info-box-content">' +
                '<p>Please fill in all GitHub App fields (App ID, Installation ID, and Private Key).</p>' +
                '</div></div>'
            );
            return;
        }

        // Show testing indicator
        $statusArea.html(
            '<div class="wp-github-sync-info-box info" style="margin-top: 10px;">' +
            '<div class="wp-github-sync-info-box-icon"><span class="dashicons dashicons-update wp-github-sync-spin"></span></div>' +
            '<div class="wp-github-sync-info-box-content">' +
            '<p>Testing GitHub App connection...</p>' +
            '</div></div>'
        );

        // Send the AJAX request to test connection
        $.ajax({
            url: ajaxurl, // Use global ajaxurl
            type: 'POST',
            data: {
                action: 'wp_github_sync_test_github_app', // Need to create this AJAX handler
                app_id: appId,
                installation_id: installationId,
                private_key: privateKey,
                repo_url: repoUrl,
                nonce: wpGitHubSync.nonce // Use localized nonce
            },
            success: function(response) {
                if (response.success) {
                    // Success - GitHub App is valid
                    let message = response.data.message;

                    if (response.data.app_name) {
                        message += ' App name: <strong>' + response.data.app_name + '</strong>.';
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
             error: function(xhr, status, error) {
                 window.wpGitHubSyncAdmin.handleGlobalAjaxError(xhr, status, error, 'Test GitHub App Connection');
                 // Clear the status area as the global handler shows the notice
                 $statusArea.empty();
             }
        });
    });

    // PAT/OAuth Connection testing handler removed - Handled by admin.js

    // Repository creation toggle
    $('#create_new_repo').on('change', function() {
        if ($(this).is(':checked')) {
            $('#new_repo_options').slideDown();
        } else {
            $('#new_repo_options').slideUp();
        }
    });

    // Toggle slider click handler
    $('.wp-github-sync-toggle-slider').on('click', function() {
        const checkbox = $(this).siblings('input[type="checkbox"]');
        checkbox.prop('checked', !checkbox.prop('checked')).trigger('change');
    });

    // Handle hash changes
    $(window).on('hashchange', function() {
        const hash = window.location.hash.substr(1);
        if (hash && $('.wp-github-sync-tab[data-tab="' + hash + '"]').length) {
            $('.wp-github-sync-tab[data-tab="' + hash + '"]').click();
        }
    });

    // OAuth Connect Button
    $('.wp-github-sync-oauth-connect').on('click', function() {
         $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wp_github_sync_oauth_connect',
                nonce: wpGitHubSync.nonce
            },
            success: function(response) {
                if (response.success && response.data.oauth_url) {
                    // Open the GitHub auth URL in a new window/tab
                    window.open(response.data.oauth_url, '_blank');
                    // Optionally provide feedback to the user
                    $('#github-oauth-connection-status').html('<p>Please authorize the connection in the new window.</p>');
                } else {
                    alert('Error generating OAuth URL: ' + (response.data.message || 'Unknown error'));
                }
            },
             error: function(xhr, status, error) { window.wpGitHubSyncAdmin.handleGlobalAjaxError(xhr, status, error, 'OAuth Connect'); }
        });
    });

     // OAuth Disconnect Button
    $('.wp-github-sync-oauth-disconnect').on('click', function() {
         if (!confirm('Are you sure you want to disconnect your GitHub account?')) {
             return;
         }
         $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wp_github_sync_oauth_disconnect',
                nonce: wpGitHubSync.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    window.location.reload(); // Reload to update UI
                } else {
                    // Use global error handler
                    window.wpGitHubSyncAdmin.handleGlobalAjaxError({ responseJSON: response }, 'error', 'Disconnect Error', 'OAuth Disconnect');
                }
            },
             error: function(xhr, status, error) { window.wpGitHubSyncAdmin.handleGlobalAjaxError(xhr, status, error, 'OAuth Disconnect'); }
        });
    });

     // Webhook Secret Copy Button
     $('.wp-github-sync-copy-webhook').on('click', function() {
        var secret = $('#wp_github_sync_webhook_secret').val();
        var $temp = $('<input>');
        $('body').append($temp);
        $temp.val(secret).select();
        try {
            document.execCommand('copy');
            var $button = $(this);
            var originalText = $button.text();
            $button.text('Copied!');
            setTimeout(function() { $button.text(originalText); }, 1500);
        } catch (e) {
            // Use global error handler or a less intrusive notification
             window.wpGitHubSyncAdmin.handleGlobalAjaxError(null, 'error', 'Could not copy secret.', 'Copy Webhook Secret');
        }
        $temp.remove();
    });

     // Webhook Regenerate Button
     $('.wp-github-sync-regenerate-webhook').on('click', function() {
         if (!confirm('Are you sure you want to regenerate the webhook secret? You will need to update it in GitHub.')) {
             return;
         }
         $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wp_github_sync_regenerate_webhook',
                nonce: wpGitHubSync.nonce
            },
            success: function(response) {
                if (response.success && response.data.secret) {
                    $('#wp_github_sync_webhook_secret').val(response.data.secret);
                    $('#wp_github_sync_webhook_secret').val(response.data.secret);
                    // Use a more integrated notification if possible, alert is okay as fallback
                    alert(response.data.message);
                } else {
                    // Use global error handler
                    window.wpGitHubSyncAdmin.handleGlobalAjaxError({ responseJSON: response }, 'error', 'Regeneration Error', 'Regenerate Webhook');
                }
            },
             error: function(xhr, status, error) { window.wpGitHubSyncAdmin.handleGlobalAjaxError(xhr, status, error, 'Regenerate Webhook'); }
        });
    });

    // Add comment about missing backend action for GitHub App test
    // NOTE: The corresponding AJAX action 'wp_github_sync_test_github_app' needs to be implemented
    // in a backend handler class (e.g., SettingsActionsHandler.php).

});
