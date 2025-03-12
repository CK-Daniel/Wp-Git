<?php
/**
 * Admin history page template.
 *
 * @package WPGitHubSync
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}
?>
<div class="wrap">
    <h1><?php _e('Deployment History', 'wp-github-sync'); ?></h1>
    
    <?php settings_errors('wp_github_sync'); ?>
    
    <div class="wp-github-sync-history-page">
        <?php if (empty($history)) : ?>
            <div class="notice notice-info">
                <p><?php _e('No deployment history found. Deploy changes from GitHub to see your history here.', 'wp-github-sync'); ?></p>
            </div>
        <?php else : ?>
            <div class="wp-github-sync-card">
                <h2><?php _e('Recent Deployments', 'wp-github-sync'); ?></h2>
                
                <div class="wp-github-sync-card-content">
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php _e('Date & Time', 'wp-github-sync'); ?></th>
                                <th><?php _e('Reference', 'wp-github-sync'); ?></th>
                                <th><?php _e('Commit', 'wp-github-sync'); ?></th>
                                <th><?php _e('Message', 'wp-github-sync'); ?></th>
                                <th><?php _e('Author', 'wp-github-sync'); ?></th>
                                <th><?php _e('Deployed By', 'wp-github-sync'); ?></th>
                                <th><?php _e('Actions', 'wp-github-sync'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            // Display history in reverse order (newest first)
                            $reversed_history = array_reverse($history);
                            
                            foreach ($reversed_history as $deployment) : 
                                $timestamp = isset($deployment['timestamp']) ? $deployment['timestamp'] : 0;
                                $ref = isset($deployment['ref']) ? $deployment['ref'] : 'unknown';
                                $commit = isset($deployment['commit']) ? $deployment['commit'] : array();
                                $user = isset($deployment['user']) ? $deployment['user'] : 'system';
                                
                                $commit_sha = isset($commit['sha']) ? $commit['sha'] : '';
                                $commit_message = isset($commit['message']) ? $commit['message'] : '';
                                $commit_author = isset($commit['author']) ? $commit['author'] : '';
                                $commit_date = isset($commit['date']) ? $commit['date'] : '';
                                
                                // For display, format the ref/branch name
                                $is_commit = (strlen($ref) === 40);
                                $ref_display = $is_commit ? substr($ref, 0, 8) : $ref;
                            ?>
                                <tr>
                                    <td>
                                        <?php 
                                        if ($timestamp) {
                                            echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
                                        } else {
                                            _e('Unknown', 'wp-github-sync');
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                        if ($is_commit) {
                                            _e('Commit', 'wp-github-sync');
                                        } else {
                                            echo sprintf(__('Branch: %s', 'wp-github-sync'), esc_html($ref_display));
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                        if (!empty($commit_sha)) {
                                            echo esc_html(substr($commit_sha, 0, 8));
                                        } else {
                                            _e('Unknown', 'wp-github-sync');
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                        if (!empty($commit_message)) {
                                            echo esc_html(wp_github_sync_format_commit_message($commit_message, 60));
                                        } else {
                                            _e('Unknown', 'wp-github-sync');
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                        if (!empty($commit_author)) {
                                            echo esc_html($commit_author);
                                        } else {
                                            _e('Unknown', 'wp-github-sync');
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo esc_html($user); ?></td>
                                    <td>
                                        <?php if (!empty($commit_sha)) : ?>
                                            <button class="button wp-github-sync-rollback" data-commit="<?php echo esc_attr($commit_sha); ?>">
                                                <?php _e('Roll Back', 'wp-github-sync'); ?>
                                            </button>
                                        <?php else : ?>
                                            &mdash;
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Loading/Progress Overlay -->
            <div class="wp-github-sync-overlay" style="display: none;">
                <div class="wp-github-sync-loader"></div>
                <div class="wp-github-sync-loading-message"><?php _e('Processing...', 'wp-github-sync'); ?></div>
            </div>
        <?php endif; ?>
    </div>
</div>