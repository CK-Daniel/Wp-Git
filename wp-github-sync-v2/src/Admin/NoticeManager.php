<?php
/**
 * Admin notice manager for the plugin.
 *
 * @package WPGitHubSync
 */

namespace WPGitHubSync\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * NoticeManager class for handling admin notices.
 */
class NoticeManager {

    /**
     * The version of this plugin.
     *
     * @var string
     */
    private $version;

    /**
     * Initialize the class and set its properties.
     *
     * @param string $version The version of this plugin.
     */
    public function __construct( $version ) {
        $this->version = $version;
    }

    /**
     * Display admin notices.
     */
    public function display_notices() {
        $notices = get_option('wp_github_sync_admin_notices', array());
        
        if (empty($notices)) {
            return;
        }
        
        foreach ($notices as $key => $notice) {
            $type = isset($notice['type']) ? $notice['type'] : 'info';
            $message = isset($notice['message']) ? $notice['message'] : '';
            $is_dismissible = isset($notice['dismissible']) ? $notice['dismissible'] : true;
            
            if (!empty($message)) {
                $dismissible_class = $is_dismissible ? 'is-dismissible' : '';
                echo '<div class="notice notice-' . esc_attr($type) . ' ' . esc_attr($dismissible_class) . '">';
                echo '<p>' . wp_kses_post($message) . '</p>';
                echo '</div>';
            }
        }
        
        // Clear all notices after displaying them
        delete_option('wp_github_sync_admin_notices');
    }

    /**
     * Add an admin notice.
     *
     * @param string  $message      The notice message.
     * @param string  $type         The notice type (success, warning, error, info).
     * @param boolean $dismissible  Whether the notice is dismissible.
     */
    public static function add_notice($message, $type = 'info', $dismissible = true) {
        $notices = get_option('wp_github_sync_admin_notices', array());
        
        $notices[] = array(
            'message' => $message,
            'type' => $type,
            'dismissible' => $dismissible,
        );
        
        update_option('wp_github_sync_admin_notices', $notices);
    }
}