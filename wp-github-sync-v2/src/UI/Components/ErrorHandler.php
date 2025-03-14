<?php
/**
 * Error Handler Component
 *
 * @package WPGitHubSync
 */

namespace WPGitHubSync\UI\Components;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Error Handler Component for displaying user-friendly error messages
 */
class ErrorHandler {
    /**
     * Error categories with user-friendly messages
     *
     * @var array
     */
    private static $error_categories = array(
        'github_api' => array(
            'title'       => 'GitHub API Error',
            'description' => 'There was a problem communicating with the GitHub API.',
            'icon'        => 'warning',
            'color'       => '#d63638',
            'help'        => 'This usually indicates an authentication problem or API rate limit issue.',
        ),
        'authentication' => array(
            'title'       => 'Authentication Error',
            'description' => 'Failed to authenticate with GitHub.',
            'icon'        => 'lock',
            'color'       => '#d63638',
            'help'        => 'Please check your Personal Access Token or OAuth token.',
        ),
        'rate_limit' => array(
            'title'       => 'API Rate Limit Exceeded',
            'description' => 'GitHub API rate limit has been exceeded.',
            'icon'        => 'warning',
            'color'       => '#dba617',
            'help'        => 'Please wait before making more requests or use authenticated requests.',
        ),
        'repository' => array(
            'title'       => 'Repository Error',
            'description' => 'There was a problem with the GitHub repository.',
            'icon'        => 'admin-generic',
            'color'       => '#d63638',
            'help'        => 'Please check that the repository exists and you have access to it.',
        ),
        'file_system' => array(
            'title'       => 'File System Error',
            'description' => 'Failed to perform file system operations.',
            'icon'        => 'media-document',
            'color'       => '#d63638',
            'help'        => 'Please check file permissions and disk space.',
        ),
        'zip' => array(
            'title'       => 'ZIP Archive Error',
            'description' => 'Failed to create or extract ZIP archive.',
            'icon'        => 'archive',
            'color'       => '#d63638',
            'help'        => 'Please check that the PHP ZIP extension is enabled.',
        ),
        'connection' => array(
            'title'       => 'Connection Error',
            'description' => 'Failed to connect to GitHub.',
            'icon'        => 'admin-site-alt3',
            'color'       => '#d63638',
            'help'        => 'Please check your internet connection or try again later.',
        ),
        'permissions' => array(
            'title'       => 'Permission Error',
            'description' => 'Insufficient permissions to perform the operation.',
            'icon'        => 'shield',
            'color'       => '#d63638',
            'help'        => 'Please check file system permissions or GitHub repository access.',
        ),
        'timeout' => array(
            'title'       => 'Request Timeout',
            'description' => 'The request to GitHub timed out.',
            'icon'        => 'backup',
            'color'       => '#dba617',
            'help'        => 'This could be due to a large repository or slow connection. Try again later.',
        ),
        'settings' => array(
            'title'       => 'Settings Error',
            'description' => 'Invalid or missing settings.',
            'icon'        => 'admin-settings',
            'color'       => '#d63638',
            'help'        => 'Please configure the plugin settings correctly.',
        ),
        'validation' => array(
            'title'       => 'Validation Error',
            'description' => 'Input validation failed.',
            'icon'        => 'editor-help',
            'color'       => '#dba617',
            'help'        => 'Please check your input and try again.',
        ),
        'not_found' => array(
            'title'       => 'Not Found',
            'description' => 'The requested resource was not found.',
            'icon'        => 'search',
            'color'       => '#dba617',
            'help'        => 'Please check that the resource exists.',
        ),
        'conflict' => array(
            'title'       => 'Conflict Error',
            'description' => 'There is a conflict with the current operation.',
            'icon'        => 'warning',
            'color'       => '#dba617',
            'help'        => 'Please resolve the conflict before continuing.',
        ),
        'unknown' => array(
            'title'       => 'Unknown Error',
            'description' => 'An unknown error occurred.',
            'icon'        => 'warning',
            'color'       => '#d63638',
            'help'        => 'Please check the error logs for more information.',
        ),
    );
    
    /**
     * Get error category details
     *
     * @param string $category Error category.
     * @return array Error category details.
     */
    public static function get_error_category( $category ) {
        return isset( self::$error_categories[ $category ] ) 
            ? self::$error_categories[ $category ] 
            : self::$error_categories['unknown'];
    }
    
    /**
     * Categorize WordPress error
     *
     * @param \WP_Error $error WordPress error object.
     * @return string Error category.
     */
    public static function categorize_error( $error ) {
        if ( ! is_wp_error( $error ) ) {
            return 'unknown';
        }
        
        $error_code = $error->get_error_code();
        
        // Special case for github_api_rate_limit
        if ( $error_code === 'github_api_rate_limit' || strpos( $error_code, 'rate_limit' ) !== false ) {
            return 'rate_limit';
        } elseif ( strpos( $error_code, 'github_api' ) === 0 ) {
            return 'github_api';
        } elseif ( strpos( $error_code, 'auth' ) !== false || $error_code === 'invalid_token' ) {
            return 'authentication';
        } elseif ( strpos( $error_code, 'repo' ) !== false || strpos( $error_code, 'repository' ) !== false ) {
            return 'repository';
        } elseif ( strpos( $error_code, 'fs' ) !== false || strpos( $error_code, 'file' ) !== false ) {
            return 'file_system';
        } elseif ( strpos( $error_code, 'zip' ) !== false || strpos( $error_code, 'archive' ) !== false ) {
            return 'zip';
        } elseif ( strpos( $error_code, 'connect' ) !== false || strpos( $error_code, 'http' ) !== false ) {
            return 'connection';
        } elseif ( strpos( $error_code, 'perm' ) !== false || strpos( $error_code, 'access' ) !== false ) {
            return 'permissions';
        } elseif ( strpos( $error_code, 'timeout' ) !== false ) {
            return 'timeout';
        } elseif ( strpos( $error_code, 'settings' ) !== false || strpos( $error_code, 'config' ) !== false ) {
            return 'settings';
        } elseif ( strpos( $error_code, 'valid' ) !== false ) {
            return 'validation';
        } elseif ( strpos( $error_code, 'not_found' ) !== false || $error_code === '404' ) {
            return 'not_found';
        } elseif ( strpos( $error_code, 'conflict' ) !== false || $error_code === '409' ) {
            return 'conflict';
        }
        
        return 'unknown';
    }
    
    /**
     * Get user-friendly error details from WordPress error
     *
     * @param \WP_Error $error WordPress error object.
     * @return array User-friendly error details.
     */
    public static function get_friendly_error( $error ) {
        if ( ! is_wp_error( $error ) ) {
            return self::$error_categories['unknown'];
        }
        
        $category = self::categorize_error( $error );
        $details = self::get_error_category( $category );
        
        // Add the original error message for context
        $details['message'] = $error->get_error_message();
        $details['code'] = $error->get_error_code();
        $details['category'] = $category;
        
        // Add resolution steps based on error category
        $details['steps'] = self::get_resolution_steps( $category, $error );
        
        return $details;
    }
    
    /**
     * Get resolution steps for an error
     *
     * @param string    $category Error category.
     * @param \WP_Error $error    WordPress error object.
     * @return array Resolution steps.
     */
    public static function get_resolution_steps( $category, $error ) {
        $steps = array();
        
        switch ( $category ) {
            case 'github_api':
                $steps[] = __( 'Check your GitHub API authentication settings.', 'wp-github-sync' );
                $steps[] = __( 'Verify that your Personal Access Token has the correct permissions.', 'wp-github-sync' );
                $steps[] = __( 'Check the GitHub API status at https://www.githubstatus.com/.', 'wp-github-sync' );
                break;
                
            case 'authentication':
                $steps[] = __( 'Verify that your Personal Access Token is correct.', 'wp-github-sync' );
                $steps[] = __( 'Ensure your token has not expired or been revoked.', 'wp-github-sync' );
                $steps[] = __( 'Check that your token has the required scopes (repo, workflow).', 'wp-github-sync' );
                break;
                
            case 'rate_limit':
                $steps[] = __( 'Wait for the rate limit window to reset (usually 1 hour).', 'wp-github-sync' );
                $steps[] = __( 'Authenticate with a Personal Access Token to get higher rate limits.', 'wp-github-sync' );
                $steps[] = __( 'Optimize your code to make fewer API requests.', 'wp-github-sync' );
                break;
                
            case 'repository':
                $steps[] = __( 'Verify that the repository exists and is accessible.', 'wp-github-sync' );
                $steps[] = __( 'Check that your authentication token has access to this repository.', 'wp-github-sync' );
                $steps[] = __( 'Ensure the repository URL is correctly formatted.', 'wp-github-sync' );
                break;
                
            case 'file_system':
                $steps[] = __( 'Check file and directory permissions.', 'wp-github-sync' );
                $steps[] = __( 'Ensure you have enough disk space.', 'wp-github-sync' );
                $steps[] = __( 'Verify that the paths are correct and accessible.', 'wp-github-sync' );
                break;
                
            case 'zip':
                $steps[] = __( 'Check that the PHP ZIP extension is enabled.', 'wp-github-sync' );
                $steps[] = __( 'Ensure you have enough disk space for the ZIP operations.', 'wp-github-sync' );
                $steps[] = __( 'Try with a smaller repository or subset of files.', 'wp-github-sync' );
                break;
                
            case 'connection':
                $steps[] = __( 'Check your internet connection.', 'wp-github-sync' );
                $steps[] = __( 'Verify that GitHub is accessible from your server.', 'wp-github-sync' );
                $steps[] = __( 'Try again later as this might be a temporary issue.', 'wp-github-sync' );
                break;
                
            case 'permissions':
                $steps[] = __( 'Check file system permissions on your server.', 'wp-github-sync' );
                $steps[] = __( 'Ensure your GitHub token has the required permissions.', 'wp-github-sync' );
                $steps[] = __( 'Contact your server administrator if you cannot modify permissions.', 'wp-github-sync' );
                break;
                
            case 'timeout':
                $steps[] = __( 'Try again with a smaller repository or subset of files.', 'wp-github-sync' );
                $steps[] = __( 'Increase PHP timeout limits if possible.', 'wp-github-sync' );
                $steps[] = __( 'Try during off-peak hours when GitHub might be less busy.', 'wp-github-sync' );
                break;
                
            case 'settings':
                $steps[] = __( 'Review and update your plugin settings.', 'wp-github-sync' );
                $steps[] = __( 'Ensure all required fields are filled correctly.', 'wp-github-sync' );
                $steps[] = __( 'Try resetting to default settings and reconfigure.', 'wp-github-sync' );
                break;
                
            case 'validation':
                $steps[] = __( 'Check your input for any invalid characters or formats.', 'wp-github-sync' );
                $steps[] = __( 'Ensure all required fields are provided.', 'wp-github-sync' );
                $steps[] = __( 'Refer to the documentation for correct input formats.', 'wp-github-sync' );
                break;
                
            case 'not_found':
                $steps[] = __( 'Verify that the resource you are looking for exists.', 'wp-github-sync' );
                $steps[] = __( 'Check for typos in paths or URLs.', 'wp-github-sync' );
                $steps[] = __( 'Ensure you have permissions to access the resource.', 'wp-github-sync' );
                break;
                
            case 'conflict':
                $steps[] = __( 'Refresh your repository view to see the latest changes.', 'wp-github-sync' );
                $steps[] = __( 'Manually resolve conflicts in the repository.', 'wp-github-sync' );
                $steps[] = __( 'Try syncing smaller batches of changes.', 'wp-github-sync' );
                break;
                
            default:
                $steps[] = __( 'Check the error logs for more detailed information.', 'wp-github-sync' );
                $steps[] = __( 'Try restarting the process.', 'wp-github-sync' );
                $steps[] = __( 'Contact support if the issue persists.', 'wp-github-sync' );
                break;
        }
        
        return $steps;
    }
    
    /**
     * Render error message
     *
     * @param \WP_Error|string $error_or_message WordPress error object or error message.
     * @param string           $category         Error category (optional, auto-detected for WP_Error).
     * @return string HTML for the error message.
     */
    public static function render_error( $error_or_message, $category = 'unknown' ) {
        if ( is_wp_error( $error_or_message ) ) {
            $error_details = self::get_friendly_error( $error_or_message );
        } else {
            $error_details = self::get_error_category( $category );
            $error_details['message'] = $error_or_message;
            $error_details['steps'] = self::get_resolution_steps( $category, null );
        }
        
        $html = '<div class="wp-github-sync-error-message">';
        $html .= '<div class="wp-github-sync-error-header" style="border-left-color: ' . esc_attr( $error_details['color'] ) . ';">';
        $html .= '<span class="dashicons dashicons-' . esc_attr( $error_details['icon'] ) . '"></span>';
        $html .= '<h3>' . esc_html( $error_details['title'] ) . '</h3>';
        $html .= '</div>';
        
        $html .= '<div class="wp-github-sync-error-content">';
        $html .= '<p>' . esc_html( $error_details['description'] ) . '</p>';
        
        if ( ! empty( $error_details['message'] ) ) {
            $html .= '<div class="wp-github-sync-error-details">';
            $html .= '<code>' . esc_html( $error_details['message'] ) . '</code>';
            $html .= '</div>';
        }
        
        if ( ! empty( $error_details['steps'] ) ) {
            $html .= '<div class="wp-github-sync-error-resolution">';
            $html .= '<h4>' . __( 'Suggested Actions:', 'wp-github-sync' ) . '</h4>';
            $html .= '<ul>';
            
            foreach ( $error_details['steps'] as $step ) {
                $html .= '<li>' . esc_html( $step ) . '</li>';
            }
            
            $html .= '</ul>';
            $html .= '</div>';
        }
        
        $html .= '<div class="wp-github-sync-error-help">';
        $html .= '<p>' . esc_html( $error_details['help'] ) . '</p>';
        $html .= '</div>';
        
        $html .= '</div>'; // End error content
        $html .= '</div>'; // End error message
        
        return $html;
    }
    
    /**
     * Output error message
     *
     * @param \WP_Error|string $error_or_message WordPress error object or error message.
     * @param string           $category         Error category (optional, auto-detected for WP_Error).
     */
    public static function output_error( $error_or_message, $category = 'unknown' ) {
        echo self::render_error( $error_or_message, $category ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
}