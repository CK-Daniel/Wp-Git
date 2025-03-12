/**
 * Admin JavaScript for WordPress GitHub Sync.
 *
 * @package WPGitHubSync
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        // Settings page functionality
        $('.wp-github-sync-settings-page').on('change', 'input[name="wp_github_sync_auto_sync"]', function() {
            var $intervalField = $('.wp-github-sync-auto-sync-interval');
            if ($(this).is(':checked')) {
                $intervalField.show();
            } else {
                $intervalField.hide();
            }
        });

        // Initialize auto sync interval visibility
        if ($('input[name="wp_github_sync_auto_sync"]').length) {
            if (!$('input[name="wp_github_sync_auto_sync"]').is(':checked')) {
                $('.wp-github-sync-auto-sync-interval').hide();
            }
        }

        // Dashboard page functionality
        $('.wp-github-sync-rollback-button').on('click', function(e) {
            if (!confirm('Are you sure you want to roll back to this commit? This will update files on your site.')) {
                e.preventDefault();
                return false;
            }
        });

        // Auto-refresh functionality for dashboard when deployment is in progress
        if ($('.wp-github-sync-deployment-in-progress').length) {
            setTimeout(function() {
                location.reload();
            }, 10000); // Reload every 10 seconds when deployment is in progress
        }

        // Toggle webhook setup instructions
        $('.wp-github-sync-toggle-webhook-instructions').on('click', function(e) {
            e.preventDefault();
            $('.wp-github-sync-webhook-instructions').slideToggle();
        });

        // Copy to clipboard functionality
        $('.wp-github-sync-copy-button').on('click', function(e) {
            e.preventDefault();
            var $target = $($(this).data('target'));
            var text = $target.text();

            // Create a temporary textarea
            var $textarea = $('<textarea>').val(text).css({
                position: 'absolute',
                left: '-9999px'
            }).appendTo('body').select();

            try {
                // Copy the text
                document.execCommand('copy');
                $(this).text('Copied!');
                setTimeout(function() {
                    $('.wp-github-sync-copy-button').text('Copy');
                }, 2000);
            } catch (err) {
                console.error('Failed to copy text: ', err);
            }

            // Remove the textarea
            $textarea.remove();
        });

        // Mask/unmask token fields
        $('.wp-github-sync-toggle-visibility').on('click', function(e) {
            e.preventDefault();
            var $input = $($(this).data('target'));
            if ($input.attr('type') === 'password') {
                $input.attr('type', 'text');
                $(this).text('Hide');
            } else {
                $input.attr('type', 'password');
                $(this).text('Show');
            }
        });
    });

})(jQuery);