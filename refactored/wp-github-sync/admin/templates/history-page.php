<?php
/**
 * Deployment history admin page template.
 *
 * @package WPGitHubSync
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Check user capability
if (!wp_github_sync_current_user_can()) {
    wp_die(__('You do not have sufficient permissions to access this page.', 'wp-github-sync'));
}

// Get deployment history
$deployment_history = get_option('wp_github_sync_deployment_history', array());
$deployment_in_progress = get_option('wp_github_sync_deployment_in_progress', false);

// Handle rollback action
if (isset($_POST['wp_github_sync_action']) && $_POST['wp_github_sync_action'] === 'rollback' && wp_github_sync_current_user_can()) {
    check_admin_referer('wp_github_sync_rollback');
    
    $commit_sha = sanitize_text_field($_POST['wp_github_sync_commit_sha']);
    
    if (!empty($commit_sha)) {
        $sync_manager = new WPGitHubSync\Sync\Sync_Manager(new WPGitHubSync\API\API_Client());
        $result = $sync_manager->rollback($commit_sha);
        
        if (is_wp_error($result)) {
            add_settings_error('wp_github_sync', 'rollback_error', $result->get_error_message(), 'error');
        } else {
            add_settings_error('wp_github_sync', 'rollback_success', __('Successfully rolled back to the selected commit.', 'wp-github-sync'), 'success');
        }
    }
}

// Display admin notices
settings_errors('wp_github_sync');
?>

<div class="wrap">
    <h1><?php _e('Deployment History', 'wp-github-sync'); ?></h1>
    
    <?php if (empty($deployment_history)): ?>
        <div class="notice notice-info">
            <p><?php _e('No deployment history available.', 'wp-github-sync'); ?></p>
        </div>
    <?php else: ?>
        <p><?php _e('This page shows the deployment history for your WordPress site. You can view details of each deployment and roll back to a previous version if needed.', 'wp-github-sync'); ?></p>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th width="15%"><?php _e('Date', 'wp-github-sync'); ?></th>
                    <th width="10%"><?php _e('Commit', 'wp-github-sync'); ?></th>
                    <th width="10%"><?php _e('Branch/Ref', 'wp-github-sync'); ?></th>
                    <th width="30%"><?php _e('Message', 'wp-github-sync'); ?></th>
                    <th width="15%"><?php _e('Author', 'wp-github-sync'); ?></th>
                    <th width="10%"><?php _e('User', 'wp-github-sync'); ?></th>
                    <th width="10%"><?php _e('Actions', 'wp-github-sync'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php 
                // Sort deployments by timestamp, newest first
                usort($deployment_history, function($a, $b) {
                    return $b['timestamp'] - $a['timestamp'];
                });
                
                foreach ($deployment_history as $deployment): 
                ?>
                    <tr>
                        <td>
                            <?php 
                            echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $deployment['timestamp']);
                            echo '<br><small>' . wp_github_sync_time_diff($deployment['timestamp']) . ' ' . __('ago', 'wp-github-sync') . '</small>';
                            ?>
                        </td>
                        <td>
                            <?php 
                            if (isset($deployment['commit']['sha'])) {
                                echo '<code>' . substr($deployment['commit']['sha'], 0, 7) . '</code>';
                            } else {
                                echo '—';
                            }
                            ?>
                        </td>
                        <td>
                            <?php 
                            echo esc_html($deployment['ref']);
                            ?>
                        </td>
                        <td>
                            <?php 
                            if (isset($deployment['commit']['message'])) {
                                echo wp_github_sync_format_commit_message($deployment['commit']['message'], 100);
                            } else {
                                echo '—';
                            }
                            ?>
                        </td>
                        <td>
                            <?php 
                            if (isset($deployment['commit']['author'])) {
                                echo esc_html($deployment['commit']['author']);
                                if (isset($deployment['commit']['date'])) {
                                    echo '<br><small>' . date_i18n(get_option('date_format'), strtotime($deployment['commit']['date'])) . '</small>';
                                }
                            } else {
                                echo '—';
                            }
                            ?>
                        </td>
                        <td><?php echo esc_html($deployment['user']); ?></td>
                        <td>
                            <?php if (!$deployment_in_progress && isset($deployment['commit']['sha'])): ?>
                                <form method="post" action="">
                                    <?php wp_nonce_field('wp_github_sync_rollback'); ?>
                                    <input type="hidden" name="wp_github_sync_action" value="rollback">
                                    <input type="hidden" name="wp_github_sync_commit_sha" value="<?php echo esc_attr($deployment['commit']['sha']); ?>">
                                    <button type="submit" class="button" onclick="return confirm('<?php esc_attr_e('Are you sure you want to roll back to this commit? This will update files on your site.', 'wp-github-sync'); ?>');">
                                        <?php _e('Rollback', 'wp-github-sync'); ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div class="notice notice-info" style="margin-top: 20px;">
            <p>
                <strong><?php _e('Warning:', 'wp-github-sync'); ?></strong>
                <?php _e('Rolling back to a previous commit will update files on your site to match the state of the repository at that point in time. Always make a backup before rolling back.', 'wp-github-sync'); ?>
            </p>
        </div>
    <?php endif; ?>
</div>