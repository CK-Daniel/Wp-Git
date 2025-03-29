<?php
/**
 * Template part for displaying the 'Commits' tab content on the dashboard.
 *
 * @package WPGitHubSync
 *
 * Available variables:
 * $recent_commits, $last_deployed_commit
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}
?>
<!-- Changed id from commits-tab-content to tab-commits -->
<div class="wp-github-sync-tab-content" id="tab-commits" data-tab="commits">
    <div class="wp-github-sync-card">
        <h2>
            <span class="dashicons dashicons-backup"></span>
            <?php _e('Commit History', 'wp-github-sync'); ?>
        </h2>

        <div class="wp-github-sync-card-content">
            <p><?php _e('You can review recent commits and roll back to a previous version if needed.', 'wp-github-sync'); ?></p>

            <?php if (!empty($recent_commits)) : ?>
                <div class="wp-github-sync-commits-list">
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th><?php _e('Commit', 'wp-github-sync'); ?></th>
                                <th><?php _e('Message', 'wp-github-sync'); ?></th>
                                <th><?php _e('Author', 'wp-github-sync'); ?></th>
                                <th><?php _e('Date', 'wp-github-sync'); ?></th>
                                <th><?php _e('Actions', 'wp-github-sync'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_commits as $commit) : ?>
                                <?php
                                    // Ensure commit data is available before accessing
                                    $commit_sha = $commit['sha'] ?? '';
                                    $commit_message = $commit['message'] ?? '';
                                    $commit_author = $commit['author'] ?? 'Unknown';
                                    $commit_date = $commit['date'] ?? '';
                                ?>
                                <tr>
                                    <td class="commit-hash"><?php echo esc_html(substr($commit_sha, 0, 8)); ?></td>
                                    <td><?php echo esc_html(wp_github_sync_format_commit_message($commit_message)); ?></td>
                                    <td><?php echo esc_html($commit_author); ?></td>
                                    <td class="commit-date"><?php echo $commit_date ? esc_html(date_i18n(get_option('date_format'), strtotime($commit_date))) : 'N/A'; ?></td>
                                    <td>
                                        <?php if ($commit_sha !== $last_deployed_commit) : ?>
                                            <button class="wp-github-sync-button warning wp-github-sync-rollback" data-commit="<?php echo esc_attr($commit_sha); ?>">
                                                <span class="dashicons dashicons-undo"></span>
                                                <?php _e('Roll Back', 'wp-github-sync'); ?>
                                            </button>
                                        <?php else : ?>
                                            <span class="wp-github-sync-current-version">
                                                <span class="dashicons dashicons-yes-alt"></span>
                                                <?php _e('Current', 'wp-github-sync'); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="wp-github-sync-info-box warning">
                    <div class="wp-github-sync-info-box-icon">
                        <span class="dashicons dashicons-warning"></span>
                    </div>
                    <div class="wp-github-sync-info-box-content">
                        <h4 class="wp-github-sync-info-box-title"><?php _e('Rollback Warning', 'wp-github-sync'); ?></h4>
                        <p class="wp-github-sync-info-box-message">
                            <?php _e('Rolling back will change your site\'s files to match the selected commit. This is useful to undo recent changes, but may cause issues if the rolled-back version is incompatible with your database.', 'wp-github-sync'); ?>
                        </p>
                    </div>
                </div>
            <?php else : ?>
                <div class="wp-github-sync-info-box warning">
                    <div class="wp-github-sync-info-box-icon">
                        <span class="dashicons dashicons-warning"></span>
                    </div>
                    <div class="wp-github-sync-info-box-content">
                        <h4 class="wp-github-sync-info-box-title"><?php _e('No Commits Found', 'wp-github-sync'); ?></h4>
                        <p class="wp-github-sync-info-box-message">
                            <?php _e('No recent commits found. This could be due to an empty repository or authentication issues.', 'wp-github-sync'); ?>
                        </p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
