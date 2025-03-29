<?php
/**
 * Handles progress tracking for background jobs in WordPress GitHub Sync plugin.
 *
 * @package WPGitHubSync\Admin
 */

namespace WPGitHubSync\Admin;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Progress Tracker class.
 */
class Progress_Tracker {

    /**
     * Store sync progress data
     *
     * @var array
     */
    private $sync_progress = [
        'step' => 0,
        'detail' => '',
        'status' => 'pending',
        'timestamp' => 0,
        'subStep' => null,
        'stats' => [],
        'fileProgress' => 0,
    ];

    /**
     * Store file processing statistics
     *
     * @var array
     */
    private $file_processing_stats = [
        'total_files' => 0,
        'processed_files' => 0,
        'binary_files' => 0,
        'text_files' => 0,
        'blobs_created' => 0,
        'failures' => 0
    ];

    /**
     * Constructor.
     */
    public function __construct() {
        // Load initial state if needed, though typically managed per-job
        $this->sync_progress = get_option('wp_github_sync_sync_progress', $this->sync_progress);
    }

    /**
     * Update sync progress (stores in options and transient).
     *
     * @param int $step The current step number
     * @param string $detail Detailed status message
     * @param string $status Status ('pending', 'running', 'failed', 'complete')
     * @param int|null $subStep Optional sub-step for granular progress tracking
     */
    public function update_sync_progress($step, $detail = '', $status = 'running', $subStep = null) {
        $this->sync_progress = [
            'step' => $step,
            'detail' => $detail,
            'status' => $status,
            'timestamp' => time(),
            'subStep' => $subStep,
            'stats' => $this->file_processing_stats, // Always include current stats
            'fileProgress' => 0, // Recalculate below
        ];

        // Calculate overall progress for file processing if relevant stats exist
        if (isset($this->file_processing_stats['total_files']) && $this->file_processing_stats['total_files'] > 0) {
            $this->sync_progress['fileProgress'] = round(
                ($this->file_processing_stats['processed_files'] / $this->file_processing_stats['total_files']) * 100
            );
        }

        // Store progress in options for background polling
        update_option('wp_github_sync_sync_progress', $this->sync_progress);
        // Also store in transient for faster AJAX checks
        set_transient('wp_github_sync_progress', $this->sync_progress, HOUR_IN_SECONDS); // 1 hour expiry

        wp_github_sync_log("Sync progress updated: Step {$step}" . ($subStep !== null ? ", Sub-step {$subStep}" : "") . " - {$detail} [Status: {$status}]", 'debug');
    }

     /**
     * Progress callback function to be passed to other classes.
     * Updates the progress stored by this tracker.
     *
     * @param int $subStep Sub-step number from the calling class
     * @param string $detail Progress detail message
     * @param array $stats Optional stats array from the calling class
     */
    public function update_sync_progress_from_callback($subStep, $detail, $stats = []) {
        // Determine the overall step based on the context if needed, or use a fixed step
        $current_progress = get_option('wp_github_sync_sync_progress', ['step' => 5]); // Default to step 5 if not set
        $overall_step = $current_progress['step'];

        if (!empty($stats)) {
            $this->update_file_stats($stats);
        }
        // Update progress using the main method, ensuring stats are included
        $this->update_sync_progress($overall_step, $detail, 'running', $subStep);
    }


    /**
     * Update file processing stats
     *
     * @param array $stats The stats to update or merge. Keys matching existing stats will be updated/incremented.
     */
    public function update_file_stats($stats) {
        foreach ($stats as $key => $value) {
            if (isset($this->file_processing_stats[$key])) {
                // If value is numeric, increment, otherwise replace
                if (is_numeric($value) && is_numeric($this->file_processing_stats[$key])) {
                     $this->file_processing_stats[$key] += $value;
                } else {
                    $this->file_processing_stats[$key] = $value;
                }
            } else {
                // Add new stat if it doesn't exist
                $this->file_processing_stats[$key] = $value;
            }
        }
        // Note: Progress is updated separately when update_sync_progress is called
    }

    /**
     * Reset progress and stats.
     */
    public function reset_progress() {
        $this->sync_progress = [
            'step' => 0,
            'detail' => '',
            'status' => 'pending',
            'timestamp' => 0,
            'subStep' => null,
            'stats' => [],
            'fileProgress' => 0,
        ];
        $this->file_processing_stats = [
            'total_files' => 0,
            'processed_files' => 0,
            'binary_files' => 0,
            'text_files' => 0,
            'blobs_created' => 0,
            'failures' => 0
        ];
        update_option('wp_github_sync_sync_progress', $this->sync_progress);
        delete_transient('wp_github_sync_progress');
        wp_github_sync_log("Progress tracker reset", 'info');
    }

    /**
     * Get current progress data.
     *
     * @return array The current progress data.
     */
    public function get_progress() {
        // Return the latest state from options/transient
        $progress = get_transient('wp_github_sync_progress');
        if (!$progress) {
            $progress = get_option('wp_github_sync_sync_progress');
        }
        return $progress ?: $this->sync_progress; // Return default if nothing stored
    }

    /**
     * Get current file processing stats.
     *
     * @return array The file processing stats.
     */
    public function get_file_stats() {
        return $this->file_processing_stats;
    }
}
