<?php
/**
 * Dashboard template
 *
 * @package WPGitHubSync
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<div class="wrap wp-github-sync-wrap">
    <div class="wp-github-sync-header">
        <img src="<?php echo esc_url( WP_GITHUB_SYNC_URL . 'assets/img/logo.svg' ); ?>" alt="GitHub Sync" class="wp-github-sync-logo">
        <h1 class="wp-github-sync-title">
            <?php esc_html_e( 'GitHub Sync', 'wp-github-sync' ); ?>
            <span class="wp-github-sync-version"><?php echo esc_html( $this->version ); ?></span>
        </h1>
    </div>
    
    <div class="wp-github-sync-notices"></div>
    
    <div class="wp-github-sync-dashboard">
        <div class="wp-github-sync-grid">
            <?php echo $status_card->render(); ?>
            <?php echo $sync_status_card->render(); ?>
            <?php echo $actions_card->render(); ?>
            <?php echo $commits_card->render(); ?>
        </div>
    </div>
</div>