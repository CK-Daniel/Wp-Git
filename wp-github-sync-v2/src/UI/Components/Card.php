<?php
/**
 * Card UI Component
 *
 * @package WPGitHubSync
 */

namespace WPGitHubSync\UI\Components;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Card UI component
 */
class Card {
    /**
     * Card title
     *
     * @var string
     */
    private $title;
    
    /**
     * Card content
     *
     * @var string
     */
    private $content;
    
    /**
     * Card footer
     *
     * @var string
     */
    private $footer;
    
    /**
     * Card CSS classes
     *
     * @var array
     */
    private $classes = [];
    
    /**
     * Initialize a card component
     *
     * @param string $title   Card title.
     * @param string $content Card content.
     * @param string $footer  Card footer.
     */
    public function __construct( $title = '', $content = '', $footer = '' ) {
        $this->title = $title;
        $this->content = $content;
        $this->footer = $footer;
        $this->add_class( 'wp-github-sync-card' );
    }
    
    /**
     * Add a CSS class to the card
     *
     * @param string $class CSS class to add.
     * @return $this For method chaining.
     */
    public function add_class( $class ) {
        $this->classes[] = sanitize_html_class( $class );
        return $this;
    }
    
    /**
     * Set card title
     *
     * @param string $title Card title.
     * @return $this For method chaining.
     */
    public function set_title( $title ) {
        $this->title = $title;
        return $this;
    }
    
    /**
     * Set card content
     *
     * @param string $content Card content.
     * @return $this For method chaining.
     */
    public function set_content( $content ) {
        $this->content = $content;
        return $this;
    }
    
    /**
     * Set card footer
     *
     * @param string $footer Card footer.
     * @return $this For method chaining.
     */
    public function set_footer( $footer ) {
        $this->footer = $footer;
        return $this;
    }
    
    /**
     * Render the card
     *
     * @return string HTML for the card.
     */
    public function render() {
        $classes = implode( ' ', $this->classes );
        
        $html = '<div class="' . esc_attr( $classes ) . '">';
        
        if ( ! empty( $this->title ) ) {
            $html .= '<div class="wp-github-sync-card-header">';
            $html .= '<h3 class="wp-github-sync-card-title">' . esc_html( $this->title ) . '</h3>';
            $html .= '</div>';
        }
        
        $html .= '<div class="wp-github-sync-card-content">';
        $html .= $this->content; // Not escaped as it may contain HTML
        $html .= '</div>';
        
        if ( ! empty( $this->footer ) ) {
            $html .= '<div class="wp-github-sync-card-footer">';
            $html .= $this->footer; // Not escaped as it may contain HTML
            $html .= '</div>';
        }
        
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Output the card
     */
    public function output() {
        echo $this->render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
}