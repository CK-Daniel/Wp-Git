<?php
/**
 * Jobs Monitor page template.
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
?>
<div class="wrap wp-github-sync-wrap">
    <h1><?php _e('GitHub Sync Jobs Monitor', 'wp-github-sync'); ?></h1>
    
    <?php settings_errors('wp_github_sync_jobs'); ?>
    
    <div class="wp-github-sync-jobs-header">
        <div class="wp-github-sync-job-actions">
            <button type="button" id="manual-refresh-button" class="button">
                <span class="dashicons dashicons-update"></span>
                <?php _e('Refresh Now', 'wp-github-sync'); ?>
            </button>
            <div class="wp-github-sync-auto-refresh-toggle active">
                <span class="dashicons dashicons-update"></span>
                <span class="wp-github-sync-auto-refresh-label"><?php _e('Auto-refresh', 'wp-github-sync'); ?></span>
            </div>
        </div>
        
        <div class="wp-github-sync-job-info">
            <span class="refresh-info">(<?php _e('Refreshed', 'wp-github-sync'); ?>: <span id="refresh-count">0</span> <?php _e('times', 'wp-github-sync'); ?>)</span>
        </div>
    </div>
    
    <div class="wp-github-sync-card">
        <div class="wp-github-sync-card-header">
            <h2><span class="dashicons dashicons-update"></span> <?php _e('Active Background Jobs', 'wp-github-sync'); ?></h2>
        </div>
        
        <div class="wp-github-sync-card-content">
            
            <!-- Chunked Sync Status -->
            <div id="chunked-sync-container" class="wp-github-sync-active-job-card" <?php echo empty($chunked_sync_state) ? 'style="display:none;"' : ''; ?>>
                <h3 class="job-title"><span class="dashicons dashicons-update wp-github-spin"></span> <?php _e('Chunked Sync In Progress', 'wp-github-sync'); ?></h3>
                
                <?php if (!empty($chunked_sync_state)): ?>
                    <div class="job-details">
                        <p>
                            <strong><?php _e('Stage', 'wp-github-sync'); ?>:</strong> 
                            <span class="chunked-sync-status"><?php echo esc_html($chunked_sync_state['stage'] ?? 'Unknown'); ?></span>
                        </p>
                        
                        <p>
                            <strong><?php _e('Progress', 'wp-github-sync'); ?>:</strong> 
                            <span class="chunked-sync-progress">
                                <?php
                                $progress_message = '';
                                $stage = $chunked_sync_state['stage'] ?? 'unknown';
                                
                                switch($stage) {
                                    case 'authentication':
                                        $progress_message = __('Verifying authentication with GitHub...', 'wp-github-sync');
                                        break;
                                    case 'repository_check':
                                        $progress_message = __('Checking repository access...', 'wp-github-sync');
                                        break;
                                    case 'prepare_temp_directory':
                                        $progress_message = __('Preparing temporary directory...', 'wp-github-sync');
                                        break;
                                    case 'collecting_files':
                                        $progress_message = __('Collecting files from WordPress...', 'wp-github-sync');
                                        // Add file count if available
                                        if (isset($chunked_sync_state['files_copied'])) {
                                            $progress_message .= ' (' . $chunked_sync_state['files_copied'] . ' files processed)';
                                        }
                                        break;
                                    case 'uploading_files':
                                        $progress_message = __('Uploading files to GitHub...', 'wp-github-sync');
                                        break;
                                    default:
                                        $progress_message = __('Processing...', 'wp-github-sync');
                                }
                                
                                echo esc_html($progress_message);
                                ?>
                            </span>
                        </p>
                        
                        <p>
                            <strong><?php _e('Started', 'wp-github-sync'); ?>:</strong> 
                            <?php 
                            $timestamp = isset($chunked_sync_state['timestamp']) ? $chunked_sync_state['timestamp'] : time();
                            echo esc_html(human_time_diff($timestamp, time()) . ' ' . __('ago', 'wp-github-sync')); 
                            echo ' (' . esc_html(date_i18n('Y-m-d H:i:s', $timestamp)) . ')';
                            ?>
                        </p>
                        
                        <?php if (isset($chunked_sync_state['branch'])): ?>
                        <p>
                            <strong><?php _e('Branch', 'wp-github-sync'); ?>:</strong> 
                            <?php echo esc_html($chunked_sync_state['branch']); ?>
                        </p>
                        <?php endif; ?>
                        
                        <div class="job-actions">
                            <?php 
                            $cancel_url = wp_nonce_url(
                                add_query_arg(
                                    array(
                                        'page' => 'wp-github-sync-jobs',
                                        'action' => 'cancel_chunked_sync'
                                    ),
                                    admin_url('admin.php')
                                ),
                                'wp_github_sync_jobs_action',
                                'nonce'
                            );
                            ?>
                            <a href="<?php echo esc_url($cancel_url); ?>" class="button wp-github-sync-button danger" onclick="return confirm('<?php esc_attr_e('Are you sure you want to cancel this job? This cannot be undone.', 'wp-github-sync'); ?>')">
                                <span class="dashicons dashicons-no-alt"></span> <?php _e('Cancel Job', 'wp-github-sync'); ?>
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="job-details">
                        <p><?php _e('No chunked sync currently in progress.', 'wp-github-sync'); ?></p>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Deployment In Progress Status -->
            <?php if ($deployment_in_progress): ?>
            <div class="wp-github-sync-active-job-card">
                <h3 class="job-title"><span class="dashicons dashicons-update wp-github-spin"></span> <?php _e('Deployment In Progress', 'wp-github-sync'); ?></h3>
                
                <div class="job-details">
                    <p>
                        <strong><?php _e('Started', 'wp-github-sync'); ?>:</strong> 
                        <?php 
                        echo esc_html(human_time_diff($deployment_start_time, time()) . ' ' . __('ago', 'wp-github-sync')); 
                        echo ' (' . esc_html(date_i18n('Y-m-d H:i:s', $deployment_start_time)) . ')';
                        ?>
                    </p>
                    
                    <p><?php _e('Deployment operations can take several minutes to complete.', 'wp-github-sync'); ?></p>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Sync In Progress Status (Legacy) -->
            <?php if ($sync_in_progress): ?>
            <div class="wp-github-sync-active-job-card">
                <h3 class="job-title"><span class="dashicons dashicons-update wp-github-spin"></span> <?php _e('Sync In Progress', 'wp-github-sync'); ?></h3>
                
                <div class="job-details">
                    <p>
                        <strong><?php _e('Started', 'wp-github-sync'); ?>:</strong> 
                        <?php 
                        echo esc_html(human_time_diff($sync_start_time, time()) . ' ' . __('ago', 'wp-github-sync')); 
                        echo ' (' . esc_html(date_i18n('Y-m-d H:i:s', $sync_start_time)) . ')';
                        ?>
                    </p>
                    
                    <p><?php _e('Sync operations can take several minutes to complete.', 'wp-github-sync'); ?></p>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- No Active Jobs Message -->
            <?php if (empty($chunked_sync_state) && !$deployment_in_progress && !$sync_in_progress): ?>
            <div class="wp-github-sync-info-box info">
                <div class="wp-github-sync-info-box-icon">
                    <span class="dashicons dashicons-info"></span>
                </div>
                <div class="wp-github-sync-info-box-content">
                    <h4 class="wp-github-sync-info-box-title"><?php _e('No Active Jobs', 'wp-github-sync'); ?></h4>
                    <p class="wp-github-sync-info-box-message">
                        <?php _e('There are currently no active background jobs running.', 'wp-github-sync'); ?>
                    </p>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Scheduled Jobs Section -->
    <div class="wp-github-sync-card">
        <div class="wp-github-sync-card-header">
            <h2><span class="dashicons dashicons-calendar-alt"></span> <?php _e('Scheduled Jobs', 'wp-github-sync'); ?></h2>
        </div>
        
        <div class="wp-github-sync-card-content">
            <?php if (!empty($cron_events)): ?>
                <div class="wp-github-sync-scheduled-jobs">
                    <table>
                        <thead>
                            <tr>
                                <th><?php _e('Hook', 'wp-github-sync'); ?></th>
                                <th><?php _e('Next Run', 'wp-github-sync'); ?></th>
                                <th><?php _e('Arguments', 'wp-github-sync'); ?></th>
                                <th><?php _e('Interval', 'wp-github-sync'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cron_events as $event): ?>
                                <tr>
                                    <td><?php echo esc_html($event['hook']); ?></td>
                                    <td>
                                        <?php echo esc_html($event['next_run']); ?>
                                        <br>
                                        <small>(<?php echo esc_html($event['scheduled']); ?>)</small>
                                    </td>
                                    <td>
                                        <?php 
                                        if (!empty($event['args'])) {
                                            echo '<pre>' . esc_html(json_encode($event['args'], JSON_PRETTY_PRINT)) . '</pre>';
                                        } else {
                                            echo '<em>' . __('No arguments', 'wp-github-sync') . '</em>';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                        if ($event['interval'] > 0) {
                                            echo esc_html(human_time_diff(0, $event['interval']));
                                        } else {
                                            echo '<em>' . __('One-time', 'wp-github-sync') . '</em>';
                                        }
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="wp-github-sync-card-actions">
                    <?php 
                    $clear_url = wp_nonce_url(
                        add_query_arg(
                            array(
                                'page' => 'wp-github-sync-jobs',
                                'action' => 'clear_scheduled_jobs'
                            ),
                            admin_url('admin.php')
                        ),
                        'wp_github_sync_jobs_action',
                        'nonce'
                    );
                    ?>
                    <a href="<?php echo esc_url($clear_url); ?>" class="button wp-github-sync-button warning" onclick="return confirm('<?php esc_attr_e('Are you sure you want to clear all scheduled jobs? This cannot be undone.', 'wp-github-sync'); ?>')">
                        <span class="dashicons dashicons-trash"></span> <?php _e('Clear All Scheduled Jobs', 'wp-github-sync'); ?>
                    </a>
                </div>
            <?php else: ?>
                <div class="wp-github-sync-info-box info">
                    <div class="wp-github-sync-info-box-icon">
                        <span class="dashicons dashicons-info"></span>
                    </div>
                    <div class="wp-github-sync-info-box-content">
                        <h4 class="wp-github-sync-info-box-title"><?php _e('No Scheduled Jobs', 'wp-github-sync'); ?></h4>
                        <p class="wp-github-sync-info-box-message">
                            <?php _e('There are currently no scheduled jobs in the queue.', 'wp-github-sync'); ?>
                        </p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Plugin Information Section -->
    <div class="wp-github-sync-card">
        <div class="wp-github-sync-card-header">
            <h2><span class="dashicons dashicons-info"></span> <?php _e('Background Jobs Information', 'wp-github-sync'); ?></h2>
        </div>
        
        <div class="wp-github-sync-card-content">
            <p>
                <?php _e('WordPress GitHub Sync uses background jobs to handle time-intensive operations like initializing repositories, syncing large codebases, and deploying updates.', 'wp-github-sync'); ?>
            </p>
            
            <h3><?php _e('Job Types', 'wp-github-sync'); ?></h3>
            <ul class="wp-github-sync-info-list">
                <li>
                    <strong><?php _e('Chunked Sync', 'wp-github-sync'); ?></strong> - 
                    <?php _e('Breaks large file operations into smaller chunks to avoid timeouts. Used for initial sync and large repositories.', 'wp-github-sync'); ?>
                </li>
                <li>
                    <strong><?php _e('Background Deploy', 'wp-github-sync'); ?></strong> - 
                    <?php _e('Processes GitHub deployments asynchronously, especially useful for webhook-triggered updates.', 'wp-github-sync'); ?>
                </li>
                <li>
                    <strong><?php _e('Auto Sync', 'wp-github-sync'); ?></strong> - 
                    <?php _e('Scheduled checks for updates from GitHub based on the configured interval.', 'wp-github-sync'); ?>
                </li>
            </ul>
            
            <h3><?php _e('Troubleshooting', 'wp-github-sync'); ?></h3>
            <p>
                <?php _e('If a job appears to be stuck for more than 15 minutes:', 'wp-github-sync'); ?>
            </p>
            <ol>
                <li><?php _e('Check the logs for any error messages', 'wp-github-sync'); ?></li>
                <li><?php _e('Use the "Cancel Job" button to clear the stuck job', 'wp-github-sync'); ?></li>
                <li><?php _e('Verify your PHP is configured with sufficient timeout and memory settings', 'wp-github-sync'); ?></li>
                <li><?php _e('Check GitHub API rate limits if jobs frequently fail', 'wp-github-sync'); ?></li>
            </ol>
        </div>
    </div>
</div>

<!-- Jobs monitor styles loaded from jobs.css -->