<?php
/**
 * Progress Tracker Component
 *
 * @package WPGitHubSync
 */

namespace WPGitHubSync\UI\Components;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Progress Tracker Component for displaying detailed sync progress
 */
class ProgressTracker {
    /**
     * Tracker ID
     *
     * @var string
     */
    private $id;
    
    /**
     * Steps in the progress tracker
     *
     * @var array
     */
    private $steps = [];
    
    /**
     * Current step index
     *
     * @var int
     */
    private $current_step = 0;
    
    /**
     * Additional CSS classes
     *
     * @var array
     */
    private $classes = [];
    
    /**
     * Initialize progress tracker
     *
     * @param string $id Tracker ID.
     */
    public function __construct( $id = 'sync-progress' ) {
        $this->id = preg_replace('/[^a-z0-9_-]/', '', strtolower($id));
        $this->add_class( 'wp-github-sync-progress-tracker' );
    }
    
    /**
     * Add a CSS class to the progress tracker
     *
     * @param string $class CSS class to add.
     * @return $this For method chaining.
     */
    public function add_class( $class ) {
        $this->classes[] = sanitize_html_class( $class );
        return $this;
    }
    
    /**
     * Add a step to the progress tracker
     *
     * @param string $title       Step title.
     * @param string $description Step description.
     * @param string $icon        Step icon (dashicon name).
     * @return $this For method chaining.
     */
    public function add_step( $title, $description = '', $icon = 'editor-code' ) {
        $this->steps[] = [
            'title'       => $title,
            'description' => $description,
            'icon'        => $icon,
            'status'      => 'pending',
            'progress'    => 0,
            'message'     => '',
            'time'        => 0,
        ];
        return $this;
    }
    
    /**
     * Set the current active step
     *
     * @param int $step_index Step index.
     * @return $this For method chaining.
     */
    public function set_current_step( $step_index ) {
        if ( isset( $this->steps[ $step_index ] ) ) {
            $this->current_step = $step_index;
            
            // Mark previous steps as completed
            for ( $i = 0; $i < $step_index; $i++ ) {
                $this->steps[ $i ]['status'] = 'completed';
                $this->steps[ $i ]['progress'] = 100;
            }
            
            // Mark current step as in progress
            $this->steps[ $step_index ]['status'] = 'in_progress';
        }
        return $this;
    }
    
    /**
     * Update step status
     *
     * @param int    $step_index Step index.
     * @param string $status     Step status (pending, in_progress, completed, error).
     * @param int    $progress   Step progress (0-100).
     * @param string $message    Status message.
     * @return $this For method chaining.
     */
    public function update_step( $step_index, $status, $progress = 0, $message = '' ) {
        if ( isset( $this->steps[ $step_index ] ) ) {
            $this->steps[ $step_index ]['status'] = $status;
            $this->steps[ $step_index ]['progress'] = $progress;
            $this->steps[ $step_index ]['message'] = $message;
            $this->steps[ $step_index ]['time'] = time();
        }
        return $this;
    }
    
    /**
     * Get tracker data as array
     *
     * @return array Tracker data.
     */
    public function get_data() {
        return [
            'id'           => $this->id,
            'steps'        => $this->steps,
            'current_step' => $this->current_step,
            'total_steps'  => count( $this->steps ),
            'is_complete'  => $this->is_complete(),
            'has_error'    => $this->has_error(),
            'overall_progress' => $this->get_overall_progress(),
        ];
    }
    
    /**
     * Check if the tracker is complete
     *
     * @return bool True if complete, false otherwise.
     */
    public function is_complete() {
        if ( empty( $this->steps ) ) {
            return false;
        }
        
        foreach ( $this->steps as $step ) {
            if ( $step['status'] !== 'completed' ) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Check if the tracker has an error
     *
     * @return bool True if an error occurred, false otherwise.
     */
    public function has_error() {
        foreach ( $this->steps as $step ) {
            if ( $step['status'] === 'error' ) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get overall progress percentage
     *
     * @return int Progress percentage (0-100).
     */
    public function get_overall_progress() {
        if ( empty( $this->steps ) ) {
            return 0;
        }
        
        $completed_steps = 0;
        $total_progress = 0;
        
        foreach ( $this->steps as $step ) {
            if ( $step['status'] === 'completed' ) {
                $completed_steps++;
                $total_progress += 100;
            } elseif ( $step['status'] === 'in_progress' ) {
                $total_progress += $step['progress'];
            }
        }
        
        return (int) ( $total_progress / count( $this->steps ) );
    }
    
    /**
     * Render the progress tracker
     *
     * @return string HTML for the progress tracker.
     */
    public function render() {
        $classes = implode( ' ', $this->classes );
        
        $status_class = '';
        if ( $this->is_complete() ) {
            $status_class = ' completed';
        } elseif ( $this->has_error() ) {
            $status_class = ' error';
        }
        
        $overall_progress = $this->get_overall_progress();
        
        $html = '<div id="' . esc_attr( $this->id ) . '" class="' . esc_attr( $classes . $status_class ) . '" data-current-step="' . esc_attr( $this->current_step ) . '">';
        
        // Overall progress bar
        $html .= '<div class="wp-github-sync-progress-tracker-overall">';
        $html .= '<div class="wp-github-sync-progress-tracker-bar">';
        $html .= '<div class="wp-github-sync-progress-tracker-bar-inner" style="width: ' . esc_attr( $overall_progress ) . '%"></div>';
        $html .= '</div>';
        $html .= '<div class="wp-github-sync-progress-tracker-percentage">' . esc_html( $overall_progress ) . '%</div>';
        $html .= '</div>';
        
        // Steps
        $html .= '<div class="wp-github-sync-progress-tracker-steps">';
        
        foreach ( $this->steps as $index => $step ) {
            $step_class = 'wp-github-sync-progress-tracker-step';
            $step_class .= ' ' . $step['status'];
            
            if ( $index === $this->current_step ) {
                $step_class .= ' current';
            }
            
            $html .= '<div class="' . esc_attr( $step_class ) . '" data-step="' . esc_attr( $index ) . '">';
            
            // Step header
            $html .= '<div class="wp-github-sync-progress-tracker-step-header">';
            $html .= '<div class="wp-github-sync-progress-tracker-step-icon">';
            $html .= '<span class="dashicons dashicons-' . esc_attr( $step['icon'] ) . '"></span>';
            $html .= '</div>';
            $html .= '<div class="wp-github-sync-progress-tracker-step-info">';
            $html .= '<h4 class="wp-github-sync-progress-tracker-step-title">' . esc_html( $step['title'] ) . '</h4>';
            
            if ( ! empty( $step['description'] ) ) {
                $html .= '<div class="wp-github-sync-progress-tracker-step-description">' . esc_html( $step['description'] ) . '</div>';
            }
            
            $html .= '</div>';
            
            // Step status indicator
            $html .= '<div class="wp-github-sync-progress-tracker-step-status">';
            switch ( $step['status'] ) {
                case 'completed':
                    $html .= '<span class="dashicons dashicons-yes-alt"></span>';
                    break;
                case 'in_progress':
                    $html .= '<span class="spinner is-active"></span>';
                    break;
                case 'error':
                    $html .= '<span class="dashicons dashicons-warning"></span>';
                    break;
                default:
                    $html .= '<span class="dashicons dashicons-marker"></span>';
                    break;
            }
            $html .= '</div>';
            
            $html .= '</div>'; // End step header
            
            // Step details (only shown for current or completed steps)
            if ( $step['status'] !== 'pending' ) {
                $html .= '<div class="wp-github-sync-progress-tracker-step-details">';
                
                // Progress bar (only for in-progress steps)
                if ( $step['status'] === 'in_progress' && $step['progress'] > 0 ) {
                    $html .= '<div class="wp-github-sync-progress-tracker-step-progress">';
                    $html .= '<div class="wp-github-sync-progress-tracker-step-bar">';
                    $html .= '<div class="wp-github-sync-progress-tracker-step-bar-inner" style="width: ' . esc_attr( $step['progress'] ) . '%"></div>';
                    $html .= '</div>';
                    $html .= '<div class="wp-github-sync-progress-tracker-step-percentage">' . esc_html( $step['progress'] ) . '%</div>';
                    $html .= '</div>';
                }
                
                // Status message
                if ( ! empty( $step['message'] ) ) {
                    $html .= '<div class="wp-github-sync-progress-tracker-step-message">' . esc_html( $step['message'] ) . '</div>';
                }
                
                // Timestamp
                if ( $step['time'] > 0 ) {
                    $html .= '<div class="wp-github-sync-progress-tracker-step-time">';
                    $html .= esc_html( human_time_diff( $step['time'], time() ) ) . ' ' . __( 'ago', 'wp-github-sync' );
                    $html .= '</div>';
                }
                
                $html .= '</div>'; // End step details
            }
            
            $html .= '</div>'; // End step
        }
        
        $html .= '</div>'; // End steps
        
        $html .= '</div>'; // End tracker
        
        return $html;
    }
    
    /**
     * Output the progress tracker
     */
    public function output() {
        echo $this->render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
    
    /**
     * Create a default deployment tracker
     *
     * @return ProgressTracker Configured tracker for deployment.
     */
    public static function deployment_tracker() {
        $tracker = new self( 'deployment-tracker' );
        
        $tracker->add_step( __( 'Preparing', 'wp-github-sync' ), __( 'Preparing for deployment', 'wp-github-sync' ), 'admin-settings' );
        $tracker->add_step( __( 'Backup', 'wp-github-sync' ), __( 'Creating backup', 'wp-github-sync' ), 'backup' );
        $tracker->add_step( __( 'Download', 'wp-github-sync' ), __( 'Downloading files from GitHub', 'wp-github-sync' ), 'download' );
        $tracker->add_step( __( 'Extract', 'wp-github-sync' ), __( 'Extracting files', 'wp-github-sync' ), 'archive' );
        $tracker->add_step( __( 'Deploy', 'wp-github-sync' ), __( 'Deploying files', 'wp-github-sync' ), 'upload' );
        $tracker->add_step( __( 'Cleanup', 'wp-github-sync' ), __( 'Cleaning up temporary files', 'wp-github-sync' ), 'cleanup' );
        $tracker->add_step( __( 'Complete', 'wp-github-sync' ), __( 'Deployment complete', 'wp-github-sync' ), 'yes' );
        
        return $tracker;
    }
    
    /**
     * Create a sync tracker
     *
     * @return ProgressTracker Configured tracker for sync.
     */
    public static function sync_tracker() {
        $tracker = new self( 'sync-tracker' );
        
        $tracker->add_step( __( 'Connect', 'wp-github-sync' ), __( 'Connecting to GitHub', 'wp-github-sync' ), 'admin-links' );
        $tracker->add_step( __( 'Compare', 'wp-github-sync' ), __( 'Comparing repositories', 'wp-github-sync' ), 'backup' );
        $tracker->add_step( __( 'Backup', 'wp-github-sync' ), __( 'Creating backup', 'wp-github-sync' ), 'backup' );
        $tracker->add_step( __( 'Sync', 'wp-github-sync' ), __( 'Syncing files', 'wp-github-sync' ), 'update' );
        $tracker->add_step( __( 'Complete', 'wp-github-sync' ), __( 'Sync complete', 'wp-github-sync' ), 'yes' );
        
        return $tracker;
    }
}