<?php
/**
 * Dashboard admin page template.
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

// Get current settings and status
$repository_url = get_option('wp_github_sync_repository', '');
$branch = wp_github_sync_get_current_branch();
$last_deployed_commit = get_option('wp_github_sync_last_deployed_commit', '');
$latest_commit = get_option('wp_github_sync_latest_commit', array());
$update_available = get_option('wp_github_sync_update_available', false);
$deployment_in_progress = get_option('wp_github_sync_deployment_in_progress', false);
$deployment_history = get_option('wp_github_sync_deployment_history', array());
$last_backup = get_option('wp_github_sync_last_backup', array());

// Handle form submission for deployment actions
if (isset($_POST['wp_github_sync_action']) && wp_github_sync_current_user_can()) {
    check_admin_referer('wp_github_sync_action');
    
    $action = sanitize_text_field($_POST['wp_github_sync_action']);
    
    if ($action === 'deploy_latest') {
        // Deploy the latest commit
        if (!empty($latest_commit['sha'])) {
            $sync_manager = new WPGitHubSync\Sync\Sync_Manager(new WPGitHubSync\API\API_Client());
            $result = $sync_manager->deploy($latest_commit['sha']);
            
            if (is_wp_error($result)) {
                add_settings_error('wp_github_sync', 'deploy_error', $result->get_error_message(), 'error');
            } else {
                add_settings_error('wp_github_sync', 'deploy_success', __('Successfully deployed the latest commit.', 'wp-github-sync'), 'success');
            }
        }
    } elseif ($action === 'check_updates') {
        // Check for updates
        $sync_manager = new WPGitHubSync\Sync\Sync_Manager(new WPGitHubSync\API\API_Client());
        $sync_manager->check_for_updates();
        
        add_settings_error('wp_github_sync', 'check_updates', __('Checked for updates from GitHub.', 'wp-github-sync'), 'info');
    } elseif ($action === 'switch_branch') {
        // Switch to a different branch
        $new_branch = sanitize_text_field($_POST['wp_github_sync_branch']);
        
        if (!empty($new_branch)) {
            $sync_manager = new WPGitHubSync\Sync\Sync_Manager(new WPGitHubSync\API\API_Client());
            $result = $sync_manager->switch_branch($new_branch);
            
            if (is_wp_error($result)) {
                add_settings_error('wp_github_sync', 'switch_branch_error', $result->get_error_message(), 'error');
            } else {
                add_settings_error('wp_github_sync', 'switch_branch_success', sprintf(__('Successfully switched to branch %s.', 'wp-github-sync'), $new_branch), 'success');
            }
        }
    } elseif ($action === 'rollback') {
        // Rollback to a previous commit
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
    
    // Refresh the page to update the status
    echo '<meta http-equiv="refresh" content="0">';
}

// Display admin notices
settings_errors('wp_github_sync');
?>

<div class="wrap">
    <h1><?php _e('GitHub Sync Dashboard', 'wp-github-sync'); ?></h1>
    
    <?php if (empty($repository_url)): ?>
        <div class="notice notice-warning">
            <p>
                <?php _e('GitHub repository URL is not configured. Please configure it in the settings.', 'wp-github-sync'); ?>
                <a href="<?php echo admin_url('admin.php?page=wp-github-sync-settings'); ?>"><?php _e('Go to Settings', 'wp-github-sync'); ?></a>
            </p>
        </div>
    <?php else: ?>
        <div class="card">
            <h2><?php _e('Repository Information', 'wp-github-sync'); ?></h2>
            <p><strong><?php _e('Repository URL:', 'wp-github-sync'); ?></strong> <?php echo esc_html($repository_url); ?></p>
            <p><strong><?php _e('Current Branch:', 'wp-github-sync'); ?></strong> <?php echo esc_html($branch); ?></p>
            
            <?php if (!empty($last_deployed_commit)): ?>
                <p>
                    <strong><?php _e('Last Deployed Commit:', 'wp-github-sync'); ?></strong>
                    <?php echo substr($last_deployed_commit, 0, 8); ?>
                    <?php
                    $last_deploy = array_reduce($deployment_history, function($latest, $item) {
                        return (!$latest || $item['timestamp'] > $latest['timestamp']) ? $item : $latest;
                    });
                    if ($last_deploy && isset($last_deploy['commit']['message'])) {
                        echo ' - ' . wp_github_sync_format_commit_message($last_deploy['commit']['message']);
                    }
                    ?>
                </p>
                <p>
                    <strong><?php _e('Last Deployment Time:', 'wp-github-sync'); ?></strong>
                    <?php
                    if ($last_deploy) {
                        echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_deploy['timestamp']);
                        echo ' (' . wp_github_sync_time_diff($last_deploy['timestamp']) . ' ' . __('ago', 'wp-github-sync') . ')';
                    } else {
                        _e('Unknown', 'wp-github-sync');
                    }
                    ?>
                </p>
            <?php else: ?>
                <p><?php _e('No deployments have been made yet.', 'wp-github-sync'); ?></p>
            <?php endif; ?>
            
            <?php if (!empty($last_backup) && isset($last_backup['path']) && file_exists($last_backup['path'])): ?>
                <p>
                    <strong><?php _e('Last Backup:', 'wp-github-sync'); ?></strong>
                    <?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_backup['date'])); ?>
                </p>
            <?php endif; ?>
        </div>
        
        <div class="card">
            <h2><?php _e('Actions', 'wp-github-sync'); ?></h2>
            
            <?php if ($deployment_in_progress): ?>
                <div class="notice notice-info">
                    <p><?php _e('A deployment is currently in progress. Please wait until it completes.', 'wp-github-sync'); ?></p>
                </div>
            <?php else: ?>
                <form method="post" action="">
                    <?php wp_nonce_field('wp_github_sync_action'); ?>
                    <input type="hidden" name="wp_github_sync_action" value="check_updates">
                    <p>
                        <button type="submit" class="button"><?php _e('Check for Updates', 'wp-github-sync'); ?></button>
                    </p>
                </form>
                
                <?php if ($update_available && !empty($latest_commit)): ?>
                    <div class="notice notice-info" style="background-color: #f0f8ff; border-left-color: #00a0d2;">
                        <h3><?php _e('Update Available', 'wp-github-sync'); ?></h3>
                        <p><strong><?php _e('Commit:', 'wp-github-sync'); ?></strong> <?php echo substr($latest_commit['sha'], 0, 8); ?></p>
                        <p><strong><?php _e('Author:', 'wp-github-sync'); ?></strong> <?php echo esc_html($latest_commit['author']); ?></p>
                        <p><strong><?php _e('Date:', 'wp-github-sync'); ?></strong> <?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($latest_commit['date'])); ?></p>
                        <p><strong><?php _e('Message:', 'wp-github-sync'); ?></strong> <?php echo wp_github_sync_format_commit_message($latest_commit['message'], 200); ?></p>
                        
                        <form method="post" action="">
                            <?php wp_nonce_field('wp_github_sync_action'); ?>
                            <input type="hidden" name="wp_github_sync_action" value="deploy_latest">
                            <p>
                                <button type="submit" class="button button-primary"><?php _e('Deploy Now', 'wp-github-sync'); ?></button>
                            </p>
                        </form>
                    </div>
                <?php else: ?>
                    <div class="notice notice-success">
                        <p><?php _e('Your site is up to date with the selected branch.', 'wp-github-sync'); ?></p>
                    </div>
                <?php endif; ?>
                
                <h3><?php _e('Switch Branch', 'wp-github-sync'); ?></h3>
                <form method="post" action="">
                    <?php wp_nonce_field('wp_github_sync_action'); ?>
                    <input type="hidden" name="wp_github_sync_action" value="switch_branch">
                    <p>
                        <input type="text" name="wp_github_sync_branch" value="<?php echo esc_attr($branch); ?>" placeholder="<?php _e('Branch name', 'wp-github-sync'); ?>" class="regular-text">
                        <button type="submit" class="button"><?php _e('Switch Branch', 'wp-github-sync'); ?></button>
                    </p>
                    <p class="description"><?php _e('Warning: This will immediately deploy the latest commit from the specified branch.', 'wp-github-sync'); ?></p>
                </form>
            <?php endif; ?>
        </div>
        
        <div class="card">
            <h2><?php _e('Deployment History', 'wp-github-sync'); ?></h2>
            <?php if (empty($deployment_history)): ?>
                <p><?php _e('No deployment history available.', 'wp-github-sync'); ?></p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Date', 'wp-github-sync'); ?></th>
                            <th><?php _e('Commit', 'wp-github-sync'); ?></th>
                            <th><?php _e('Message', 'wp-github-sync'); ?></th>
                            <th><?php _e('User', 'wp-github-sync'); ?></th>
                            <th><?php _e('Actions', 'wp-github-sync'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_reverse($deployment_history) as $deployment): ?>
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
                                        echo substr($deployment['commit']['sha'], 0, 8);
                                    } else {
                                        echo substr($deployment['ref'], 0, 8);
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php 
                                    if (isset($deployment['commit']['message'])) {
                                        echo wp_github_sync_format_commit_message($deployment['commit']['message']);
                                    } else {
                                        echo '—';
                                    }
                                    ?>
                                </td>
                                <td><?php echo esc_html($deployment['user']); ?></td>
                                <td>
                                    <?php if (!$deployment_in_progress && isset($deployment['commit']['sha'])): ?>
                                        <form method="post" action="">
                                            <?php wp_nonce_field('wp_github_sync_action'); ?>
                                            <input type="hidden" name="wp_github_sync_action" value="rollback">
                                            <input type="hidden" name="wp_github_sync_commit_sha" value="<?php echo $deployment['commit']['sha']; ?>">
                                            <button type="submit" class="button"><?php _e('Rollback', 'wp-github-sync'); ?></button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<style type="text/css">
    .card {
        background: #fff;
        border: 1px solid #ccd0d4;
        border-radius: 3px;
        margin-top: 20px;
        padding: 20px;
        position: relative;
        box-shadow: 0 1px 1px rgba(0,0,0,.04);
    }
    .card h2:first-child {
        margin-top: 0;
    }
</style>