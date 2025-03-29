<?php
/**
 * Template part for displaying the 'Webhook' tab content on the dashboard.
 *
 * @package WPGitHubSync
 *
 * Available variables:
 * $repository_url
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}
?>
<div class="wp-github-sync-tab-content" id="webhook-tab-content" data-tab="webhook">
    <div class="wp-github-sync-card">
        <h2>
            <span class="dashicons dashicons-admin-links"></span>
            <?php _e('Webhook Configuration', 'wp-github-sync'); ?>
        </h2>

        <div class="wp-github-sync-card-content">
            <p><?php _e('Set up a webhook in your GitHub repository to enable automatic deployments when code is pushed.', 'wp-github-sync'); ?></p>

            <div class="wp-github-sync-webhook-info">
                <div class="wp-github-sync-status-item">
                    <span class="wp-github-sync-status-label"><?php _e('Webhook URL:', 'wp-github-sync'); ?></span>
                    <span class="wp-github-sync-status-value code">
                        <?php echo esc_url(get_rest_url(null, 'wp-github-sync/v1/webhook')); ?>
                    </span>
                </div>

                <div class="wp-github-sync-status-item">
                    <span class="wp-github-sync-status-label"><?php _e('Secret:', 'wp-github-sync'); ?></span>
                    <span class="wp-github-sync-status-value code">
                        <?php echo esc_html(get_option('wp_github_sync_webhook_secret', '')); ?>
                    </span>
                </div>
            </div>

            <div class="wp-github-sync-action-buttons">
                <button class="wp-github-sync-button secondary wp-github-sync-regenerate-webhook">
                    <span class="dashicons dashicons-update"></span>
                    <?php _e('Regenerate Secret', 'wp-github-sync'); ?>
                </button>
            </div>

            <div class="wp-github-sync-info-box info">
                <div class="wp-github-sync-info-box-icon">
                    <span class="dashicons dashicons-info"></span>
                </div>
                <div class="wp-github-sync-info-box-content">
                    <h4 class="wp-github-sync-info-box-title"><?php _e('How to Configure Webhooks', 'wp-github-sync'); ?></h4>
                    <p class="wp-github-sync-info-box-message">
                        <?php
                        printf(
                            __('1. Go to your <a href="%s/settings/hooks" target="_blank">GitHub repository settings</a><br>2. Click "Add webhook"<br>3. Set the Payload URL to the Webhook URL above<br>4. Select "application/json" as content type<br>5. Enter the Secret shown above<br>6. Choose "Just the push event"<br>7. Ensure "Active" is checked<br>8. Click "Add webhook"', 'wp-github-sync'),
                            esc_url($repository_url) // Use the passed variable
                        );
                        ?>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
