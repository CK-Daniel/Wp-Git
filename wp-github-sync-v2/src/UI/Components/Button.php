<?php
/**
 * Button UI Component
 *
 * @package WPGitHubSync
 */

namespace WPGitHubSync\UI\Components;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Button UI component
 */
class Button {
    /**
     * Button text
     *
     * @var string
     */
    private $text;
    
    /**
     * Button URL
     *
     * @var string
     */
    private $url;
    
    /**
     * Button CSS classes
     *
     * @var array
     */
    private $classes = [];
    
    /**
     * Button attributes
     *
     * @var array
     */
    private $attributes = [];
    
    /**
     * Initialize a button component
     *
     * @param string $text  Button text.
     * @param string $url   Button URL.
     * @param string $class Button CSS class.
     */
    public function __construct( $text = '', $url = '#', $class = '' ) {
        $this->text = $text;
        $this->url = $url;
        
        if ( ! empty( $class ) ) {
            $this->add_class( $class );
        }
    }
    
    /**
     * Add a CSS class to the button
     *
     * @param string $class CSS class to add.
     * @return $this For method chaining.
     */
    public function add_class( $class ) {
        if ( ! empty( $class ) ) {
            $classes = explode( ' ', $class );
            foreach ( $classes as $single_class ) {
                $this->classes[] = sanitize_html_class( $single_class );
            }
        }
        return $this;
    }
    
    /**
     * Set button text
     *
     * @param string $text Button text.
     * @return $this For method chaining.
     */
    public function set_text( $text ) {
        $this->text = $text;
        return $this;
    }
    
    /**
     * Set button URL
     *
     * @param string $url Button URL.
     * @return $this For method chaining.
     */
    public function set_url( $url ) {
        $this->url = $url;
        return $this;
    }
    
    /**
     * Add a data attribute to the button
     *
     * @param string $key   Attribute key.
     * @param string $value Attribute value.
     * @return $this For method chaining.
     */
    public function add_data_attribute( $key, $value ) {
        $sanitized_key = preg_replace('/[^a-z0-9_-]/', '', strtolower($key));
        $this->attributes[ 'data-' . $sanitized_key ] = esc_attr( $value );
        return $this;
    }
    
    /**
     * Add an attribute to the button
     *
     * @param string $key   Attribute key.
     * @param string $value Attribute value.
     * @return $this For method chaining.
     */
    public function add_attribute( $key, $value ) {
        $sanitized_key = preg_replace('/[^a-z0-9_-]/', '', strtolower($key));
        $this->attributes[ $sanitized_key ] = esc_attr( $value );
        return $this;
    }
    
    /**
     * Render the button
     *
     * @return string HTML for the button.
     */
    public function render() {
        $classes = ! empty( $this->classes ) ? implode( ' ', $this->classes ) : '';
        
        $attributes = '';
        foreach ( $this->attributes as $key => $value ) {
            $attributes .= ' ' . $key . '="' . $value . '"';
        }
        
        $html = '<a href="' . esc_url( $this->url ) . '" class="' . esc_attr( $classes ) . '"' . $attributes . '>';
        $html .= esc_html( $this->text );
        $html .= '</a>';
        
        return $html;
    }
    
    /**
     * Output the button
     */
    public function output() {
        echo $this->render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
    
    /**
     * Render a button as submit
     *
     * @param string $text  Button text.
     * @param string $name  Button name.
     * @param string $class Button CSS class.
     * @return string HTML for the button.
     */
    public static function submit( $text, $name = '', $class = '' ) {
        $classes = [];
        
        if ( ! empty( $class ) ) {
            $classes = explode( ' ', $class );
        }
        
        $classes[] = 'button';
        $classes[] = 'button-primary';
        
        $class_attr = implode( ' ', array_map( 'sanitize_html_class', $classes ) );
        
        $html = '<input type="submit"';
        
        if ( ! empty( $name ) ) {
            $html .= ' name="' . esc_attr( $name ) . '"';
        }
        
        $html .= ' class="' . esc_attr( $class_attr ) . '"';
        $html .= ' value="' . esc_attr( $text ) . '">';
        
        return $html;
    }
}