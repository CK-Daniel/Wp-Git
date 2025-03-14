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
        
        // Tabs navigation
        $html .= '<ul class="wp-github-sync-tabs-nav">';
        
        $first_tab = true;
        foreach ( $this->tabs as $id => $tab ) {
            $active_class = $first_tab ? ' active' : '';
            $html .= '<li class="wp-github-sync-tabs-nav-item">';
            $html .= '<a href="#' . esc_attr( $this->id . '-' . $id ) . '" class="wp-github-sync-tabs-nav-link' . esc_attr( $active_class ) . '">';
            $html .= esc_html( $tab['title'] );
            $html .= '</a>';
            $html .= '</li>';
            $first_tab = false;
        }
        
        $html .= '</ul>';
        
        // Tabs content
        $first_tab = true;
        foreach ( $this->tabs as $id => $tab ) {
            $active_class = $first_tab ? ' active' : '';
            $html .= '<div id="' . esc_attr( $this->id . '-' . $id ) . '" class="wp-github-sync-tab-content' . esc_attr( $active_class ) . '">';
            $html .= $tab['content']; // Not escaped as it may contain HTML
            $html .= '</div>';
            $first_tab = false;
        }
        
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