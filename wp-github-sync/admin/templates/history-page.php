<?php
/**
 * Admin history page template with visual timeline.
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

// Get deployment history and status
$history = get_option('wp_github_sync_deployment_history', array());
$last_deployed_commit = get_option('wp_github_sync_last_deployed_commit', '');

// Get the current timestamp for today's date
$current_time = current_time('timestamp');
$today_date = date('Y-m-d', $current_time);

// Group deployments by date for the timeline
$deployments_by_date = array();
if (!empty($history)) {
    $reversed_history = array_reverse($history);
    foreach ($reversed_history as $deployment) {
        $timestamp = isset($deployment['timestamp']) ? $deployment['timestamp'] : 0;
        $deploy_date = date('Y-m-d', $timestamp);
        
        if (!isset($deployments_by_date[$deploy_date])) {
            $deployments_by_date[$deploy_date] = array();
        }
        
        $deployments_by_date[$deploy_date][] = $deployment;
    }
}
?>
<div class="wrap wp-github-sync-wrap">
    <h1><?php _e('Version History', 'wp-github-sync'); ?></h1>
    
    <?php settings_errors('wp_github_sync'); ?>
    
    <div class="wp-github-sync-history-page">
        <?php if (empty($history)) : ?>
            <div class="wp-github-sync-info-box info">
                <div class="wp-github-sync-info-box-icon">
                    <span class="dashicons dashicons-info"></span>
                </div>
                <div class="wp-github-sync-info-box-content">
                    <h4 class="wp-github-sync-info-box-title"><?php _e('No Version History Found', 'wp-github-sync'); ?></h4>
                    <p class="wp-github-sync-info-box-message">
                        <?php _e('Your version history will appear here after you deploy changes from GitHub. This allows you to track all changes and roll back if needed.', 'wp-github-sync'); ?>
                        <br><br>
                        <a href="<?php echo admin_url('admin.php?page=wp-github-sync'); ?>" class="wp-github-sync-button">
                            <span class="dashicons dashicons-dashboard"></span>
                            <?php _e('Go to Dashboard', 'wp-github-sync'); ?>
                        </a>
                    </p>
                </div>
            </div>
        <?php else : ?>
            <div class="wp-github-sync-tabs">
                <div class="wp-github-sync-tab active" data-tab="timeline">
                    <span class="dashicons dashicons-clock"></span>
                    <?php _e('Timeline View', 'wp-github-sync'); ?>
                </div>
                <div class="wp-github-sync-tab" data-tab="table">
                    <span class="dashicons dashicons-list-view"></span>
                    <?php _e('Table View', 'wp-github-sync'); ?>
                </div>
            </div>
            
            <!-- Timeline View -->
            <div class="wp-github-sync-tab-content active" id="timeline-tab-content">
                <div class="wp-github-sync-card">
                    <h2>
                        <span class="dashicons dashicons-backup"></span>
                        <?php _e('Version Timeline', 'wp-github-sync'); ?>
                    </h2>
                    
                    <div class="wp-github-sync-card-content">
                        <div class="wp-github-sync-timeline">
                            <?php 
                            $today = new DateTime();
                            
                            foreach ($deployments_by_date as $date => $day_deployments) : 
                                $date_obj = new DateTime($date);
                                $diff = $date_obj->diff($today);
                                $is_today = ($diff->days === 0);
                                $is_yesterday = ($diff->days === 1);
                                
                                // Format the date display
                                if ($is_today) {
                                    $date_display = __('Today', 'wp-github-sync');
                                } elseif ($is_yesterday) {
                                    $date_display = __('Yesterday', 'wp-github-sync');
                                } else {
                                    $date_display = date_i18n(get_option('date_format'), strtotime($date));
                                }
                            ?>
                                <div class="wp-github-sync-timeline-day">
                                    <div class="wp-github-sync-timeline-date">
                                        <div class="wp-github-sync-timeline-date-badge">
                                            <?php echo esc_html($date_display); ?>
                                        </div>
                                    </div>
                                    
                                    <div class="wp-github-sync-timeline-events">
                                        <?php foreach ($day_deployments as $deployment) : 
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
                                            
                                            // Determine if this is the currently deployed version
                                            $is_current = ($commit_sha === $last_deployed_commit);
                                            
                                            // Extract the first line of the commit message for title
                                            $commit_title = wp_github_sync_format_commit_message($commit_message, 100);
                                            
                                            // Determine tag based on ref type and current status
                                            if ($is_current) {
                                                $tag_class = 'current';
                                                $tag_text = __('Current', 'wp-github-sync');
                                            } elseif (!$is_commit) {
                                                $tag_class = 'branch';
                                                $tag_text = __('Branch', 'wp-github-sync');
                                            } else {
                                                $tag_class = 'commit';
                                                $tag_text = __('Commit', 'wp-github-sync');
                                            }
                                        ?>
                                            <div class="wp-github-sync-timeline-event <?php echo $is_current ? 'current-version' : ''; ?>">
                                                <div class="wp-github-sync-timeline-icon">
                                                    <?php if ($is_current) : ?>
                                                        <span class="dashicons dashicons-yes-alt"></span>
                                                    <?php else : ?>
                                                        <span class="dashicons dashicons-backup"></span>
                                                    <?php endif; ?>
                                                </div>
                                                
                                                <div class="wp-github-sync-timeline-content">
                                                    <div class="wp-github-sync-timeline-header">
                                                        <span class="wp-github-sync-timeline-time">
                                                            <?php echo date_i18n(get_option('time_format'), $timestamp); ?>
                                                        </span>
                                                        
                                                        <span class="wp-github-sync-timeline-tag <?php echo esc_attr($tag_class); ?>">
                                                            <?php echo esc_html($tag_text); ?>
                                                        </span>
                                                        
                                                        <?php if (!$is_commit) : ?>
                                                            <span class="wp-github-sync-timeline-branch">
                                                                <?php echo esc_html($ref_display); ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                    
                                                    <h3 class="wp-github-sync-timeline-title">
                                                        <?php echo esc_html($commit_title); ?>
                                                    </h3>
                                                    
                                                    <div class="wp-github-sync-timeline-meta">
                                                        <div class="wp-github-sync-timeline-meta-item">
                                                            <span class="dashicons dashicons-admin-users"></span>
                                                            <?php echo esc_html($commit_author); ?>
                                                        </div>
                                                        
                                                        <div class="wp-github-sync-timeline-meta-item">
                                                            <span class="dashicons dashicons-code-standards"></span>
                                                            <code><?php echo esc_html(substr($commit_sha, 0, 8)); ?></code>
                                                        </div>
                                                        
                                                        <div class="wp-github-sync-timeline-meta-item">
                                                            <span class="dashicons dashicons-shield"></span>
                                                            <?php _e('Deployed by', 'wp-github-sync'); ?> <?php echo esc_html($user); ?>
                                                        </div>
                                                    </div>
                                                    
                                                    <?php if (!$is_current && !empty($commit_sha)) : ?>
                                                        <div class="wp-github-sync-timeline-actions">
                                                            <button class="wp-github-sync-button warning wp-github-sync-rollback" data-commit="<?php echo esc_attr($commit_sha); ?>">
                                                                <span class="dashicons dashicons-undo"></span>
                                                                <?php _e('Restore This Version', 'wp-github-sync'); ?>
                                                            </button>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="wp-github-sync-info-box info">
                            <div class="wp-github-sync-info-box-icon">
                                <span class="dashicons dashicons-info"></span>
                            </div>
                            <div class="wp-github-sync-info-box-content">
                                <h4 class="wp-github-sync-info-box-title"><?php _e('Using Version History', 'wp-github-sync'); ?></h4>
                                <p class="wp-github-sync-info-box-message">
                                    <?php _e('This timeline shows all versions of your site. The version marked as "Current" is what\'s currently active on your site. You can restore to any previous version by clicking "Restore This Version".', 'wp-github-sync'); ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Table View (for developers who prefer more detailed information) -->
            <div class="wp-github-sync-tab-content" id="table-tab-content">
                <div class="wp-github-sync-card">
                    <h2>
                        <span class="dashicons dashicons-list-view"></span>
                        <?php _e('Detailed Version History', 'wp-github-sync'); ?>
                    </h2>
                    
                    <div class="wp-github-sync-card-content">
                        <div class="wp-github-sync-table-container">
                            <table class="widefat wp-github-sync-table">
                                <thead>
                                    <tr>
                                        <th><?php _e('Status', 'wp-github-sync'); ?></th>
                                        <th><?php _e('Date & Time', 'wp-github-sync'); ?></th>
                                        <th><?php _e('Type', 'wp-github-sync'); ?></th>
                                        <th><?php _e('Commit SHA', 'wp-github-sync'); ?></th>
                                        <th><?php _e('Message', 'wp-github-sync'); ?></th>
                                        <th><?php _e('Author', 'wp-github-sync'); ?></th>
                                        <th><?php _e('Deployed By', 'wp-github-sync'); ?></th>
                                        <th><?php _e('Actions', 'wp-github-sync'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
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
                                        
                                        // Determine if this is the currently deployed version
                                        $is_current = ($commit_sha === $last_deployed_commit);
                                    ?>
                                        <tr <?php echo $is_current ? 'class="current-row"' : ''; ?>>
                                            <td>
                                                <?php if ($is_current) : ?>
                                                    <span class="wp-github-sync-status-up-to-date">
                                                        <span class="dashicons dashicons-yes-alt"></span>
                                                        <?php _e('Current', 'wp-github-sync'); ?>
                                                    </span>
                                                <?php else : ?>
                                                    <span class="dashicons dashicons-backup"></span>
                                                <?php endif; ?>
                                            </td>
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
                                                    echo '<span class="wp-github-sync-badge commit">' . __('Commit', 'wp-github-sync') . '</span>';
                                                } else {
                                                    echo '<span class="wp-github-sync-badge branch">' . sprintf(__('Branch: %s', 'wp-github-sync'), esc_html($ref_display)) . '</span>';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <?php 
                                                if (!empty($commit_sha)) {
                                                    echo '<code>' . esc_html(substr($commit_sha, 0, 8)) . '</code>';
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
                                                    echo '<span class="wp-github-sync-author">' . esc_html($commit_author) . '</span>';
                                                } else {
                                                    _e('Unknown', 'wp-github-sync');
                                                }
                                                ?>
                                            </td>
                                            <td><?php echo esc_html($user); ?></td>
                                            <td>
                                                <?php if (!empty($commit_sha) && !$is_current) : ?>
                                                    <button class="wp-github-sync-button warning wp-github-sync-rollback" data-commit="<?php echo esc_attr($commit_sha); ?>">
                                                        <span class="dashicons dashicons-undo"></span>
                                                        <?php _e('Restore', 'wp-github-sync'); ?>
                                                    </button>
                                                <?php elseif ($is_current) : ?>
                                                    <span class="wp-github-sync-current-badge"><?php _e('Active', 'wp-github-sync'); ?></span>
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
                </div>
            </div>
            
            <!-- Loading/Progress Overlay -->
            <div class="wp-github-sync-overlay" style="display: none;">
                <div class="wp-github-sync-loader"></div>
                <div class="wp-github-sync-loading-message"><?php _e('Processing...', 'wp-github-sync'); ?></div>
                <div class="wp-github-sync-loading-submessage"></div>
            </div>
            
            <!-- AJAX nonce is now provided via wp_localize_script -->
        <?php endif; ?>
    </div>
</div>