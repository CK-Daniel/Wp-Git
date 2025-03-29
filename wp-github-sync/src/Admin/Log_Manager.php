<?php
/**
 * Handles log management for the WordPress GitHub Sync plugin.
 *
 * @package WPGitHubSync\Admin
 */

namespace WPGitHubSync\Admin;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Log Manager class.
 */
class Log_Manager {

    /**
     * Path to the log file.
     *
     * @var string
     */
    private $log_file;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->log_file = WP_CONTENT_DIR . '/wp-github-sync-debug.log';
    }

    /**
     * Handle log-related actions (clear, download, test).
     */
    public function handle_log_actions() {
        if (!isset($_GET['action'])) {
            return;
        }

        // Check permissions
        if (!wp_github_sync_current_user_can()) {
            wp_die(__('You do not have sufficient permissions to perform this action.', 'wp-github-sync'));
        }

        $action = sanitize_text_field($_GET['action']);

        // Verify the log file path is within WordPress content directory
        if (!$this->is_path_in_wp_content($this->log_file)) {
            wp_die(__('Invalid log file path.', 'wp-github-sync'));
        }

        switch ($action) {
            case 'clear_logs':
                $this->clear_logs();
                break;

            case 'download_logs':
                $this->download_logs();
                break;

            case 'test_log':
                $this->create_test_logs();
                break;
        }
    }

    /**
     * Get parsed log entries based on filters.
     *
     * @param string $log_level_filter Optional log level filter.
     * @param string $search_query     Optional search query.
     * @return array An array containing 'logs', 'log_file_size', 'is_truncated'.
     */
    public function get_logs($log_level_filter = '', $search_query = '') {
        $logs = array();
        $log_file_size_formatted = '0 B';
        $is_truncated = false;
        $valid_levels = array('debug', 'info', 'warning', 'error');

        // Validate log level filter
        if (!empty($log_level_filter) && !in_array($log_level_filter, $valid_levels)) {
            $log_level_filter = '';
        }

        // Check if log file exists and is readable
        if (file_exists($this->log_file) && is_readable($this->log_file)) {
            // Get file size
            $file_size = filesize($this->log_file);
            $log_file_size_formatted = size_format($file_size);

            // Check if file is too large to process entirely
            $max_size = apply_filters('wp_github_sync_max_log_size', 25 * 1024 * 1024); // 25MB default
            $lines_to_read = 5000; // Read more lines if truncating

            try {
                if ($file_size > $max_size) {
                    $is_truncated = true;
                    wp_github_sync_log("Log file size ({$log_file_size_formatted}) exceeds limit ({$max_size} bytes). Reading last {$lines_to_read} lines.", 'warning');
                    $log_content = $this->read_last_lines($this->log_file, $lines_to_read);

                    add_settings_error(
                        'wp_github_sync',
                        'logs_truncated',
                        sprintf(__('Log file is large (%s). Displaying recent entries only (approx. last %d lines). Download the full log file for complete history.', 'wp-github-sync'), $log_file_size_formatted, $lines_to_read),
                        'warning'
                    );
                } else {
                    $log_content = file_get_contents($this->log_file);
                }

                // Parse log entries
                if (!empty($log_content)) {
                    $log_lines = explode(PHP_EOL, $log_content);

                    foreach ($log_lines as $line) {
                        if (empty(trim($line))) continue;

                        if (preg_match('/\[((?:[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2})(?: [A-Z]+)?)\] \[(.*?)\] (.*)/', $line, $matches)) {
                            $timestamp = $matches[1];
                            $level = strtolower($matches[2]);
                            $message = $matches[3];

                            if (!in_array($level, $valid_levels)) $level = 'unknown';

                            if (!empty($log_level_filter) && $level !== $log_level_filter && $level !== 'unknown') continue;
                            if (!empty($search_query) && stripos($line, $search_query) === false) continue;

                            $logs[] = ['timestamp' => $timestamp, 'level' => $level, 'message' => $message, 'raw' => null];
                        } else {
                            if (!empty($search_query) && stripos($line, $search_query) === false) continue;
                            if (empty($log_level_filter) || $log_level_filter === 'unknown') {
                                $logs[] = ['timestamp' => '', 'level' => 'raw', 'message' => $line, 'raw' => $line];
                            }
                        }
                    }
                    $logs = array_reverse($logs);
                }
            } catch (\Exception $e) {
                error_log('WP GitHub Sync: Error reading log file - ' . $e->getMessage());
                add_settings_error('wp_github_sync', 'logs_error', __('Error reading log file. Check PHP error log for details.', 'wp-github-sync'), 'error');
            }
        }

        return [
            'logs' => $logs,
            'log_file_size' => $log_file_size_formatted,
            'is_truncated' => $is_truncated,
        ];
    }

    /**
     * Clear the log file.
     */
    private function clear_logs() {
        // Verify nonce
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'wp_github_sync_clear_logs')) {
            wp_die(__('Security check failed. Please try again.', 'wp-github-sync'));
        }

        try {
            if (file_exists($this->log_file)) {
                if (!is_writable($this->log_file)) {
                    if (!@chmod($this->log_file, 0644)) {
                        throw new \Exception(__('Log file is not writable.', 'wp-github-sync'));
                    }
                }
                if (file_put_contents($this->log_file, '') === false) {
                    throw new \Exception(__('Failed to clear log file.', 'wp-github-sync'));
                }
            } else {
                if (file_put_contents($this->log_file, '') === false) {
                    throw new \Exception(__('Failed to create log file.', 'wp-github-sync'));
                }
            }
            add_settings_error('wp_github_sync', 'logs_cleared', __('Logs cleared successfully.', 'wp-github-sync'), 'success');
        } catch (\Exception $e) {
            add_settings_error('wp_github_sync', 'logs_clear_error', sprintf(__('Error clearing logs: %s', 'wp-github-sync'), $e->getMessage()), 'error');
        }
    }

    /**
     * Handle log file download request.
     */
    private function download_logs() {
        // Verify nonce
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'wp_github_sync_download_logs')) {
            wp_die(__('Security check failed. Please try again.', 'wp-github-sync'));
        }

        if (!file_exists($this->log_file)) wp_die(__('Log file not found.', 'wp-github-sync'));
        if (!is_readable($this->log_file)) wp_die(__('Log file is not readable.', 'wp-github-sync'));

        $download_name = 'wp-github-sync-logs-' . date('Y-m-d') . '.log';
        $file_size = filesize($this->log_file);
        $max_download_size = apply_filters('wp_github_sync_max_download_size', 15 * 1024 * 1024); // 15MB default

        nocache_headers();
        header('Content-Description: File Transfer');
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename=' . $download_name);
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        header('Content-Length: ' . $file_size); // Note: This might be inaccurate if truncating

        if ($file_size > $max_download_size) {
            $fp = fopen($this->log_file, 'rb');
            if ($fp) {
                fseek($fp, -$max_download_size, SEEK_END);
                echo "--- Log file was too large. Showing only the last " . size_format($max_download_size) . " ---\n\n";
                fpassthru($fp);
                fclose($fp);
            } else {
                wp_die(__('Failed to open log file.', 'wp-github-sync'));
            }
        } else {
            readfile($this->log_file);
        }
        exit;
    }

    /**
     * Create test log entries.
     */
    private function create_test_logs() {
        // Verify nonce
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'wp_github_sync_test_log')) {
            wp_die(__('Security check failed. Please try again.', 'wp-github-sync'));
        }

        try {
            if (!function_exists('wp_github_sync_log')) {
                throw new \Exception(__('Logging function not available.', 'wp-github-sync'));
            }

            $site_name = get_bloginfo('name');
            $time = date('H:i:s');

            wp_github_sync_log("This is a test DEBUG message. Site: '{$site_name}', Time: {$time}", 'debug', true);
            wp_github_sync_log("This is a test INFO message. Site: '{$site_name}', Time: {$time}", 'info', true);
            wp_github_sync_log("This is a test WARNING message. Site: '{$site_name}', Time: {$time}", 'warning', true);
            wp_github_sync_log("This is a test ERROR message. Site: '{$site_name}', Time: {$time}", 'error', true);

            add_settings_error('wp_github_sync', 'logs_created', __('Test log entries created. You can now see how different log levels are displayed.', 'wp-github-sync'), 'success');
        } catch (\Exception $e) {
            add_settings_error('wp_github_sync', 'logs_test_error', sprintf(__('Error creating test logs: %s', 'wp-github-sync'), $e->getMessage()), 'error');
        }
    }

    /**
     * Read the last N lines of a file.
     *
     * @param string $file_path Path to the file
     * @param int    $lines     Number of lines to read from end
     * @return string The last N lines of the file
     */
    private function read_last_lines($file_path, $lines = 100) {
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return '';
        }

        try {
            $file = new \SplFileObject($file_path, 'r');
            $file->seek(PHP_INT_MAX);
            $total_lines = $file->key();
            $start = max(0, $total_lines - $lines);
            $file->seek($start);
            $content = '';
            while (!$file->eof()) {
                $content .= $file->fgets();
            }
            return $content;
        } catch (\Exception $e) {
            $content = file_get_contents($file_path);
            $content_lines = explode(PHP_EOL, $content);
            $content_lines = array_slice($content_lines, -$lines);
            return implode(PHP_EOL, $content_lines);
        }
    }

    /**
     * Checks if a path is within the WordPress content directory.
     *
     * @param string $path The path to check.
     * @return bool True if the path is inside wp-content, false otherwise.
     */
    private function is_path_in_wp_content($path) {
        $wp_content_dir = wp_normalize_path(WP_CONTENT_DIR);
        $path = wp_normalize_path($path);
        return strpos($path, $wp_content_dir) === 0;
    }
}
