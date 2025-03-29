<?php
/**
 * Template part for displaying the loading/progress overlay.
 *
 * @package WPGitHubSync
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}
?>
<!-- Loading/Progress Overlay -->
<div class="wp-github-sync-overlay" style="display: none;">
    <div class="wp-github-sync-loader"></div>
    <div class="wp-github-sync-loading-message"><?php _e('Processing...', 'wp-github-sync'); ?></div>
    <div class="wp-github-sync-loading-submessage"></div>

    <!-- Progress Bar Container -->
    <div class="wp-github-sync-progress-container">
        <div class="wp-github-sync-progress-bar" style="width: 0%"></div>
    </div>

    <!-- Step Indicator -->
    <div class="wp-github-sync-step-indicator">
        <span class="wp-github-sync-current-step">0</span> <?php _e('of', 'wp-github-sync'); ?> <span class="wp-github-sync-total-steps">0</span>
    </div>

    <!-- Detailed Status -->
    <div class="wp-github-sync-status-detail"></div>
</div>
