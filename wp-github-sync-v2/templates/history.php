<?php
/**
 * History template
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
            <?php esc_html_e( 'Sync History', 'wp-github-sync' ); ?>
            <span class="wp-github-sync-version"><?php echo esc_html( $this->version ); ?></span>
        </h1>
    </div>
    
    <div class="wp-github-sync-notices"></div>
    
    <?php echo $tabs->render(); ?>
    
    <div id="rollback-confirm-modal" class="wp-github-sync-modal">
        <div class="wp-github-sync-modal-backdrop"></div>
        <div class="wp-github-sync-modal-container">
            <div class="wp-github-sync-modal-header">
                <h3 class="wp-github-sync-modal-title"><?php esc_html_e( 'Confirm Rollback', 'wp-github-sync' ); ?></h3>
                <button type="button" class="wp-github-sync-modal-close">&times;</button>
            </div>
            <div class="wp-github-sync-modal-content">
                <p><?php esc_html_e( 'Are you sure you want to roll back to this commit? This will revert your site to an earlier state.', 'wp-github-sync' ); ?></p>
                <p><strong><?php esc_html_e( 'A backup will be created before rolling back.', 'wp-github-sync' ); ?></strong></p>
                <div class="wp-github-sync-rollback-details">
                    <p><strong><?php esc_html_e( 'Commit:', 'wp-github-sync' ); ?></strong> <code id="rollback-commit-sha"></code></p>
                </div>
            </div>
            <div class="wp-github-sync-modal-footer">
                <button type="button" class="button wp-github-sync-modal-cancel"><?php esc_html_e( 'Cancel', 'wp-github-sync' ); ?></button>
                <button type="button" class="button button-primary wp-github-sync-modal-confirm"><?php esc_html_e( 'Roll Back', 'wp-github-sync' ); ?></button>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Rollback button click
    $('.js-rollback-button').on('click', function(e) {
        e.preventDefault();
        
        const commit = $(this).data('commit');
        
        // Set values in the modal
        $('#rollback-commit-sha').text(commit);
        
        // Show the modal
        $('#rollback-confirm-modal').show();
    });
    
    // Cancel rollback
    $('.wp-github-sync-modal-cancel, .wp-github-sync-modal-close').on('click', function() {
        $('#rollback-confirm-modal').hide();
    });
    
    // Confirm rollback
    $('.wp-github-sync-modal-confirm').on('click', function() {
        const commit = $('#rollback-commit-sha').text();
        
        // Hide the modal
        $('#rollback-confirm-modal').hide();
        
        // Show loading message
        $('.wp-github-sync-notices').html('<div class="notice notice-info"><p><span class="spinner is-active"></span> ' +
            '<?php esc_html_e( 'Rolling back to commit...', 'wp-github-sync' ); ?></p></div>');
        
        // Send AJAX request
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wp_github_sync_rollback',
                commit: commit,
                nonce: '<?php echo esc_js( wp_create_nonce( 'wp_github_sync_rollback' ) ); ?>'
            },
            success: function(response) {
                if (response.success) {
                    $('.wp-github-sync-notices').html('<div class="notice notice-success"><p>' + 
                        response.data.message + '</p></div>');
                        
                    // Reload page after delay
                    setTimeout(function() {
                        window.location.reload();
                    }, 2000);
                } else {
                    $('.wp-github-sync-notices').html('<div class="notice notice-error"><p>' + 
                        response.data.message + '</p></div>');
                }
            },
            error: function() {
                $('.wp-github-sync-notices').html('<div class="notice notice-error"><p>' + 
                    '<?php esc_html_e( 'An error occurred while processing your request.', 'wp-github-sync' ); ?></p></div>');
            }
        });
    });
});
</script>