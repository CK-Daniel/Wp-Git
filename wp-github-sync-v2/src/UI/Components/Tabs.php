<?php
/**
 * Tabs UI Component
 *
 * @package WPGitHubSync
 */

namespace WPGitHubSync\UI\Components;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Tabs UI component
 */
class Tabs {
    /**
     * Tabs ID
     *
     * @var string
     */
    private $id;
    
    /**
     * Tabs list
     *
     * @var array
     */
    private $tabs = [];
    
    /**
     * Additional CSS classes
     *
     * @var array
     */
    private $classes = [];
    
    /**
     * Initialize tabs component
     *
     * @param string $id Tabs ID.
     */
    public function __construct( $id ) {
        $this->id = preg_replace('/[^a-z0-9_-]/', '', strtolower($id));
        $this->add_class( 'wp-github-sync-tabs' );
    }
    
    /**
     * Add a CSS class to the tabs
     *
     * @param string $class CSS class to add.
     * @return $this For method chaining.
     */
    public function add_class( $class ) {
        $this->classes[] = sanitize_html_class( $class );
        return $this;
    }
    
    /**
     * Add a tab
     *
     * @param string $id      Tab ID.
     * @param string $title   Tab title.
     * @param string $content Tab content.
     * @return $this For method chaining.
     */
    public function add_tab( $id, $title, $content ) {
        $this->tabs[ preg_replace('/[^a-z0-9_-]/', '', strtolower($id)) ] = [
            'title'   => $title,
            'content' => $content,
        ];
        return $this;
    }
    
    /**
     * Render the tabs
     *
     * @return string HTML for the tabs.
     */
    public function render() {
        if ( empty( $this->tabs ) ) {
            return '';
        }
        
        $classes = implode( ' ', $this->classes );
        
        $html = '<div id="' . esc_attr( $this->id ) . '" class="' . esc_attr( $classes ) . '">';
        
        // Modern tabs design
        $html .= '<div class="wp-github-sync-tabs-wrapper">';
        
        $first_tab = true;
        foreach ( $this->tabs as $id => $tab ) {
            $active_class = $first_tab ? ' active' : '';
            $html .= '<button type="button" class="wp-github-sync-tab' . esc_attr( $active_class ) . '" data-tab="' . esc_attr( $id ) . '">';
            $html .= '<span class="dashicons dashicons-admin-generic"></span> ';
            $html .= esc_html( $tab['title'] );
            $html .= '</button>';
            $first_tab = false;
        }
        
        $html .= '</div>';
        
        // We're not including tab content here since it's handled in the template
        // This allows for better separation and more flexible layouts
        
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Output the tabs
     */
    public function output() {
        echo $this->render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
}