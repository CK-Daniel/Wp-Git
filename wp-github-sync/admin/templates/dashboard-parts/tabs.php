<?php
/**
 * Template part for displaying the dashboard tabs.
 *
 * @package WPGitHubSync
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}
?>
<div class="wp-github-sync-tabs">
    <div class="wp-github-sync-tab active" data-tab="guide">
        <span class="dashicons dashicons-book"></span>
        <?php _e('Getting Started', 'wp-github-sync'); ?>
    </div>
    <div class="wp-github-sync-tab" data-tab="branches">
        <span class="dashicons dashicons-randomize"></span>
        <?php _e('Branches', 'wp-github-sync'); ?>
    </div>
    <div class="wp-github-sync-tab" data-tab="commits">
        <span class="dashicons dashicons-backup"></span>
        <?php _e('Commits', 'wp-github-sync'); ?>
    </div>
    <div class="wp-github-sync-tab" data-tab="webhook">
        <span class="dashicons dashicons-admin-links"></span>
        <?php _e('Webhook', 'wp-github-sync'); ?>
    </div>
    <?php /* Removed Developer Tools Tab
    <div class="wp-github-sync-tab" data-tab="dev">
        <span class="dashicons dashicons-code-standards"></span>
        <?php _e('Developer Tools', 'wp-github-sync'); ?>
    </div>
    */ ?>
</div>
