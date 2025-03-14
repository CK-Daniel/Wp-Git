<?php
/**
 * Backups template
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
            <?php esc_html_e( 'Backups', 'wp-github-sync' ); ?>
            <span class="wp-github-sync-version"><?php echo esc_html( $this->version ); ?></span>
        </h1>
    </div>
    
    <div class="wp-github-sync-notices"></div>
    
    <div class="wp-github-sync-header-actions">
        <?php echo $create_backup_button->render(); ?>
    </div>
    
    <div class="wp-github-sync-backups">
        <?php if ( empty( $backups ) ) : ?>
            <div class="wp-github-sync-empty-state">
                <p><?php esc_html_e( 'No backups found.', 'wp-github-sync' ); ?></p>
                <p><?php esc_html_e( 'Create a backup to protect your WordPress installation before syncing with GitHub.', 'wp-github-sync' ); ?></p>
            </div>
        <?php else : ?>
            <div class="wp-github-sync-backup-grid">
                <?php foreach ( $backups as $backup_id => $metadata ) : ?>
                    <div class="wp-github-sync-backup-card">
                        <div class="wp-github-sync-backup-card-header">
                            <h3 class="wp-github-sync-backup-card-title">
                                <?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $metadata['date'] ) ) ); ?>
                            </h3>
                        </div>
                        <div class="wp-github-sync-backup-card-content">
                            <p><strong><?php esc_html_e( 'ID:', 'wp-github-sync' ); ?></strong> <?php echo esc_html( $backup_id ); ?></p>
                            <p><strong><?php esc_html_e( 'User:', 'wp-github-sync' ); ?></strong> <?php echo esc_html( $metadata['user_login'] ); ?></p>
                            <p><strong><?php esc_html_e( 'WordPress Version:', 'wp-github-sync' ); ?></strong> <?php echo esc_html( $metadata['wp_version'] ); ?></p>
                            <p><strong><?php esc_html_e( 'Contents:', 'wp-github-sync' ); ?></strong></p>
                            <ul class="wp-github-sync-backup-paths">
                                <?php foreach ( $metadata['paths'] as $path ) : ?>
                                    <li><?php echo esc_html( $path ); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <div class="wp-github-sync-backup-card-footer">
                            <a href="#" class="wp-github-sync-button js-restore-backup" data-backup-id="<?php echo esc_attr( $backup_id ); ?>">
                                <span class="dashicons dashicons-backup"></span>
                                <?php esc_html_e( 'Restore', 'wp-github-sync' ); ?>
                            </a>
                            <a href="#" class="wp-github-sync-button outline js-delete-backup" data-backup-id="<?php echo esc_attr( $backup_id ); ?>">
                                <span class="dashicons dashicons-trash"></span>
                                <?php esc_html_e( 'Delete', 'wp-github-sync' ); ?>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div id="restore-confirm-modal" class="wp-github-sync-modal">
    <div class="wp-github-sync-modal-backdrop"></div>
    <div class="wp-github-sync-modal-container">
        <div class="wp-github-sync-modal-header">
            <h3 class="wp-github-sync-modal-title"><?php esc_html_e( 'Confirm Restore', 'wp-github-sync' ); ?></h3>
            <button type="button" class="wp-github-sync-modal-close">&times;</button>
        </div>
        <div class="wp-github-sync-modal-content">
            <p><?php esc_html_e( 'Are you sure you want to restore this backup? This will overwrite your current files.', 'wp-github-sync' ); ?></p>
            <p><strong><?php esc_html_e( 'This action cannot be undone.', 'wp-github-sync' ); ?></strong></p>
        </div>
        <div class="wp-github-sync-modal-footer">
            <button type="button" class="button wp-github-sync-modal-cancel"><?php esc_html_e( 'Cancel', 'wp-github-sync' ); ?></button>
            <button type="button" class="button button-primary wp-github-sync-modal-confirm"><?php esc_html_e( 'Restore Backup', 'wp-github-sync' ); ?></button>
        </div>
    </div>
</div>

<div id="delete-confirm-modal" class="wp-github-sync-modal">
    <div class="wp-github-sync-modal-backdrop"></div>
    <div class="wp-github-sync-modal-container">
        <div class="wp-github-sync-modal-header">
            <h3 class="wp-github-sync-modal-title"><?php esc_html_e( 'Confirm Delete', 'wp-github-sync' ); ?></h3>
            <button type="button" class="wp-github-sync-modal-close">&times;</button>
        </div>
        <div class="wp-github-sync-modal-content">
            <p><?php esc_html_e( 'Are you sure you want to delete this backup?', 'wp-github-sync' ); ?></p>
            <p><strong><?php esc_html_e( 'This action cannot be undone.', 'wp-github-sync' ); ?></strong></p>
        </div>
        <div class="wp-github-sync-modal-footer">
            <button type="button" class="button wp-github-sync-modal-cancel"><?php esc_html_e( 'Cancel', 'wp-github-sync' ); ?></button>
            <button type="button" class="button button-primary wp-github-sync-modal-confirm"><?php esc_html_e( 'Delete Backup', 'wp-github-sync' ); ?></button>
        </div>
    </div>
</div>