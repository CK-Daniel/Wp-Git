<?php
/**
 * Admin logs page template.
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
    <h1><?php _e('GitHub Sync Logs', 'wp-github-sync'); ?></h1>
    
    <?php settings_errors(); ?>
    
    <div class="wp-github-sync-logs-header">
        <div class="wp-github-sync-log-actions">
            <a href="<?php echo esc_url(wp_nonce_url(add_query_arg('action', 'download_logs'), 'wp_github_sync_download_logs')); ?>" class="button">
                <span class="dashicons dashicons-download"></span>
                <?php _e('Download Logs', 'wp-github-sync'); ?>
            </a>
            <a href="<?php echo esc_url(wp_nonce_url(add_query_arg('action', 'clear_logs'), 'wp_github_sync_clear_logs')); ?>" class="button" onclick="return confirm('<?php esc_attr_e('Are you sure you want to clear all logs? This action cannot be undone.', 'wp-github-sync'); ?>');">
                <span class="dashicons dashicons-trash"></span>
                <?php _e('Clear Logs', 'wp-github-sync'); ?>
            </a>
            <a href="<?php echo esc_url(wp_nonce_url(add_query_arg('action', 'test_log'), 'wp_github_sync_test_log')); ?>" class="button">
                <span class="dashicons dashicons-welcome-write-blog"></span>
                <?php _e('Create Test Log', 'wp-github-sync'); ?>
            </a>
            <div class="wp-github-sync-auto-refresh-toggle active">
                <span class="dashicons dashicons-update"></span>
                <span class="wp-github-sync-auto-refresh-label"><?php _e('Auto-refresh', 'wp-github-sync'); ?></span>
            </div>
        </div>
        
        <div class="wp-github-sync-log-info">
            <?php if (file_exists(WP_CONTENT_DIR . '/wp-github-sync-debug.log')): ?>
                <span class="wp-github-sync-log-file-size">
                    <span class="dashicons dashicons-media-text"></span>
                    <?php printf(__('Log file size: %s', 'wp-github-sync'), $log_file_size); ?>
                </span>
            <?php else: ?>
                <span class="wp-github-sync-log-file-missing">
                    <span class="dashicons dashicons-warning"></span>
                    <?php _e('Log file does not exist. It will be created when logs are generated.', 'wp-github-sync'); ?>
                </span>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Log filters -->
    <div class="wp-github-sync-log-filters">
        <form method="get" action="">
            <input type="hidden" name="page" value="wp-github-sync-logs">
            
            <div class="wp-github-sync-search-box">
                <span class="dashicons dashicons-search"></span>
                <input type="text" name="search" value="<?php echo esc_attr($search_query); ?>" placeholder="<?php esc_attr_e('Search logs...', 'wp-github-sync'); ?>">
            </div>
            
            <div class="wp-github-sync-level-filter">
                <select name="level">
                    <option value=""><?php _e('All Levels', 'wp-github-sync'); ?></option>
                    <option value="debug" <?php selected($log_level_filter, 'debug'); ?>><?php _e('Debug', 'wp-github-sync'); ?></option>
                    <option value="info" <?php selected($log_level_filter, 'info'); ?>><?php _e('Info', 'wp-github-sync'); ?></option>
                    <option value="warning" <?php selected($log_level_filter, 'warning'); ?>><?php _e('Warning', 'wp-github-sync'); ?></option>
                    <option value="error" <?php selected($log_level_filter, 'error'); ?>><?php _e('Error', 'wp-github-sync'); ?></option>
                </select>
                <button type="submit" class="button"><?php _e('Filter', 'wp-github-sync'); ?></button>
                <?php if (!empty($search_query) || !empty($log_level_filter)): ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wp-github-sync-logs')); ?>" class="button"><?php _e('Clear Filters', 'wp-github-sync'); ?></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
    
    <!-- Logs table -->
    <div class="wp-github-sync-content-wrapper">
        <div class="wp-github-sync-logs-container">
            <?php if (empty($logs)): ?>
                <div class="wp-github-sync-no-logs">
                    <div class="wp-github-sync-no-logs-icon">
                        <span class="dashicons dashicons-info"></span>
                    </div>
                    <p><?php _e('No logs found. Logs will appear here when GitHub Sync actions occur.', 'wp-github-sync'); ?></p>
                    <p class="description"><?php _e('Note: Logging is only active when WP_DEBUG is enabled in your wp-config.php file.', 'wp-github-sync'); ?></p>
                </div>
            <?php else: ?>
                <div class="wp-github-sync-log-entries">
                    <table class="wp-github-sync-logs-table">
                        <thead>
                            <tr>
                                <th class="log-timestamp"><?php _e('Time', 'wp-github-sync'); ?></th>
                                <th class="log-level"><?php _e('Level', 'wp-github-sync'); ?></th>
                                <th class="log-message"><?php _e('Message', 'wp-github-sync'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <tr class="log-level-<?php echo esc_attr($log['level']); ?>">
                                    <td class="log-timestamp"><?php echo esc_html($log['timestamp']); ?></td>
                                    <td class="log-level">
                                        <span class="log-level-badge log-level-<?php echo esc_attr($log['level']); ?>">
                                            <?php echo esc_html(strtoupper($log['level'])); ?>
                                        </span>
                                    </td>
                                    <td class="log-message"><?php echo esc_html($log['message']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="wp-github-sync-logs-sidebar">
            <div class="wp-github-sync-sidebar-card">
                <h3>
                    <span class="dashicons dashicons-info"></span>
                    <?php _e('Log Information', 'wp-github-sync'); ?>
                </h3>
                <div class="wp-github-sync-sidebar-content">
                    <p><strong><?php _e('Log Statistics:', 'wp-github-sync'); ?></strong></p>
                    <ul>
                        <li>
                            <span class="log-count"><?php echo count($logs); ?></span>
                            <?php _e('Log entries', 'wp-github-sync'); ?>
                        </li>
                        <?php if (!empty($logs)): ?>
                            <?php 
                            // Count log levels safely
                            $level_counts = array();
                            foreach ($logs as $log) {
                                if (isset($log['level'])) {
                                    $level = $log['level'];
                                    if (!isset($level_counts[$level])) {
                                        $level_counts[$level] = 0;
                                    }
                                    $level_counts[$level]++;
                                }
                            }
                            foreach ($level_counts as $level => $count): 
                            ?>
                            <li>
                                <span class="log-level-badge log-level-<?php echo esc_attr($level); ?>"><?php echo esc_html(strtoupper($level)); ?></span>
                                <span class="log-count"><?php echo intval($count); ?></span>
                            </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
            
            <div class="wp-github-sync-sidebar-card">
                <h3>
                    <span class="dashicons dashicons-editor-help"></span>
                    <?php _e('Log Levels', 'wp-github-sync'); ?>
                </h3>
                <div class="wp-github-sync-sidebar-content">
                    <p><?php _e('GitHub Sync uses the following log levels:', 'wp-github-sync'); ?></p>
                    <ul class="wp-github-sync-log-levels-list">
                        <li>
                            <span class="log-level-badge log-level-debug">DEBUG</span>
                            <?php _e('Detailed information for debugging', 'wp-github-sync'); ?>
                        </li>
                        <li>
                            <span class="log-level-badge log-level-info">INFO</span>
                            <?php _e('Normal operational messages', 'wp-github-sync'); ?>
                        </li>
                        <li>
                            <span class="log-level-badge log-level-warning">WARNING</span>
                            <?php _e('Warning events that might cause issues', 'wp-github-sync'); ?>
                        </li>
                        <li>
                            <span class="log-level-badge log-level-error">ERROR</span>
                            <?php _e('Error events that might still allow the process to continue', 'wp-github-sync'); ?>
                        </li>
                    </ul>
                </div>
            </div>
            
            <div class="wp-github-sync-sidebar-card">
                <h3>
                    <span class="dashicons dashicons-admin-tools"></span>
                    <?php _e('Debug Mode', 'wp-github-sync'); ?>
                </h3>
                <div class="wp-github-sync-sidebar-content">
                    <?php if (defined('WP_DEBUG') && WP_DEBUG): ?>
                        <div class="wp-github-sync-debug-enabled">
                            <p>
                                <span class="dashicons dashicons-yes-alt"></span>
                                <?php _e('WordPress debug mode is enabled.', 'wp-github-sync'); ?>
                            </p>
                            <p class="description"><?php _e('Logs are being actively generated.', 'wp-github-sync'); ?></p>
                        </div>
                    <?php else: ?>
                        <div class="wp-github-sync-debug-disabled">
                            <p>
                                <span class="dashicons dashicons-warning"></span>
                                <?php _e('WordPress debug mode is disabled.', 'wp-github-sync'); ?>
                            </p>
                            <p class="description"><?php _e('To enable logging, add the following to your wp-config.php file:', 'wp-github-sync'); ?></p>
                            <pre>define('WP_DEBUG', true);</pre>
                            <p class="description"><?php _e('You can also filter "wp_github_sync_enable_logging" to enable logging without WP_DEBUG.', 'wp-github-sync'); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- CSS moved to admin.css file for better organization and performance -->

<script>
jQuery(document).ready(function($) {
    // Auto-refresh logs every 30 seconds if the page is visible
    let autoRefresh = true;
    let refreshInterval;
    let isRefreshing = false;
    
    function setupAutoRefresh() {
        if (autoRefresh) {
            refreshInterval = setInterval(function() {
                if (document.visibilityState === 'visible' && !isRefreshing) {
                    isRefreshing = true;
                    
                    // Only reload if we're still on the logs page
                    if (window.location.href.indexOf('page=wp-github-sync-logs') > -1) {
                        try {
                            window.location.reload();
                        } catch (e) {
                            console.error('Failed to refresh logs page:', e);
                            isRefreshing = false;
                        }
                    } else {
                        clearInterval(refreshInterval);
                    }
                }
            }, 30000);
        }
    }
    
    // Toggle auto-refresh
    $('.wp-github-sync-auto-refresh-toggle').on('click', function(e) {
        e.preventDefault();
        autoRefresh = !autoRefresh;
        
        if (autoRefresh) {
            $(this).addClass('active');
            setupAutoRefresh();
        } else {
            $(this).removeClass('active');
            clearInterval(refreshInterval);
        }
    });
    
    // Setup initial auto-refresh
    setupAutoRefresh();
    
    // Stop auto-refresh when user interacts with filters
    $('.wp-github-sync-log-filters input, .wp-github-sync-log-filters select').on('focus', function() {
        clearInterval(refreshInterval);
    });
    
    // Debounce function to prevent rapid execution
    function debounce(func, wait) {
        let timeout;
        return function() {
            const context = this;
            const args = arguments;
            clearTimeout(timeout);
            timeout = setTimeout(function() {
                func.apply(context, args);
            }, wait);
        };
    }
    
    // Highlight search terms in log messages (safely)
    const searchTerm = '<?php echo esc_js($search_query); ?>';
    if (searchTerm && searchTerm.length > 0) {
        const safeHighlight = debounce(function() {
            $('.log-message').each(function() {
                const text = $(this).text();
                // Escape HTML special chars for safety
                const escapedTerm = searchTerm.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                
                try {
                    // Create safe HTML with mark tags
                    const highlightedText = text.replace(
                        new RegExp(escapedTerm, 'gi'),
                        function(match) {
                            return '<mark>' + $('<div>').text(match).html() + '</mark>';
                        }
                    );
                    $(this).html(highlightedText);
                } catch (e) {
                    console.error('Error highlighting search term:', e);
                    // If regex fails, just use the original text
                    $(this).text(text);
                }
            });
        }, 100);
        
        safeHighlight();
    }
    
    // Initialize any tooltips
    if (typeof $.fn.tooltip === 'function') {
        $('.wp-github-sync-logs-table .log-timestamp').tooltip({
            placement: 'top',
            container: 'body'
        });
    }
});
</script>