<?php
/**
 * Test script for WordPress GitHub Sync UI components
 * 
 * This is a standalone test script that can be run directly from the command line.
 */

// Define WordPress constants needed by the plugin
define('WPINC', 'wp-includes');
define('WP_CONTENT_DIR', dirname(__DIR__));
define('ABSPATH', dirname(__DIR__) . '/');
define('DAY_IN_SECONDS', 86400);
define('WP_GITHUB_SYNC_TESTING', true);

// Mock WordPress functions that the plugin uses
if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        global $wp_options;
        return isset($wp_options[$option]) ? $wp_options[$option] : $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value, $autoload = null) {
        global $wp_options;
        $wp_options[$option] = $value;
        return true;
    }
}

if (!function_exists('wp_github_sync_log')) {
    function wp_github_sync_log($message, $level = 'info', $force = false) {
        echo "[" . date('Y-m-d H:i:s') . "] [$level] $message\n";
    }
}

if (!function_exists('sanitize_html_class')) {
    function sanitize_html_class($class, $fallback = '') {
        // Basic sanitization for testing
        $class = preg_replace('/[^A-Za-z0-9_-]/', '', $class);
        if (empty($class)) {
            return $fallback;
        }
        return $class;
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_url')) {
    function esc_url($url) {
        return filter_var($url, FILTER_SANITIZE_URL);
    }
}

if (!function_exists('esc_html_e')) {
    function esc_html_e($text, $domain = 'default') {
        echo esc_html($text);
    }
}

if (!function_exists('__')) {
    function __($text, $domain = 'default') {
        return $text;
    }
}

if (!function_exists('human_time_diff')) {
    function human_time_diff($from, $to = 0) {
        if (empty($to)) {
            $to = time();
        }
        
        $diff = abs($to - $from);
        
        if ($diff < 60) {
            return $diff . ' seconds';
        } elseif ($diff < 3600) {
            return floor($diff / 60) . ' minutes';
        } elseif ($diff < 86400) {
            return floor($diff / 3600) . ' hours';
        } else {
            return floor($diff / 86400) . ' days';
        }
    }
}

if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0) {
        if ('mysql' === $type) {
            return date('Y-m-d H:i:s');
        } elseif ('timestamp' === $type) {
            return time();
        }
        return time();
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return $thing instanceof WP_Error;
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error {
        protected $errors = array();
        protected $error_data = array();
        protected $error_messages = array();
        
        public function __construct($code = '', $message = '', $data = '') {
            if (!empty($code)) {
                $this->add($code, $message, $data);
            }
        }
        
        public function add($code, $message, $data = '') {
            $this->errors[$code][] = $message;
            if (!empty($data)) {
                $this->error_data[$code] = $data;
            }
            $this->error_messages[] = $message;
        }
        
        public function get_error_message($code = '') {
            if (empty($code)) {
                return reset($this->error_messages);
            }
            
            if (isset($this->errors[$code]) && isset($this->errors[$code][0])) {
                return $this->errors[$code][0];
            }
            
            return '';
        }
        
        public function get_error_code() {
            $codes = array_keys($this->errors);
            return reset($codes);
        }
    }
}

// Include the UI components
require_once dirname(__DIR__) . '/wp-github-sync-v2/src/UI/Components/Card.php';
require_once dirname(__DIR__) . '/wp-github-sync-v2/src/UI/Components/Button.php';
require_once dirname(__DIR__) . '/wp-github-sync-v2/src/UI/Components/Tabs.php';
require_once dirname(__DIR__) . '/wp-github-sync-v2/src/UI/Components/ProgressTracker.php';
require_once dirname(__DIR__) . '/wp-github-sync-v2/src/UI/Components/ErrorHandler.php';

use WPGitHubSync\UI\Components\Card;
use WPGitHubSync\UI\Components\Button;
use WPGitHubSync\UI\Components\Tabs;
use WPGitHubSync\UI\Components\ProgressTracker;
use WPGitHubSync\UI\Components\ErrorHandler;

// Main test function
function run_ui_components_test() {
    echo "Testing UI Components:\n";
    echo "-------------------------------------\n";
    
    // Test Card component
    echo "\nTesting Card Component:\n";
    test_card_component();
    
    // Test Button component
    echo "\nTesting Button Component:\n";
    test_button_component();
    
    // Test Tabs component
    echo "\nTesting Tabs Component:\n";
    test_tabs_component();
    
    // Test ProgressTracker component
    echo "\nTesting ProgressTracker Component:\n";
    test_progress_tracker_component();
    
    // Test ErrorHandler component
    echo "\nTesting ErrorHandler Component:\n";
    test_error_handler_component();
    
    echo "\nTest completed.\n";
}

// Test Card component
function test_card_component() {
    // Basic card test
    $card = new Card('Test Card Title', 'This is card content', 'Card footer');
    $card->add_class('custom-class');
    
    $card_html = $card->render();
    echo "Card HTML Generated: " . (strpos($card_html, 'Test Card Title') !== false ? 'Success' : 'Failure') . "\n";
    echo "Card has custom class: " . (strpos($card_html, 'custom-class') !== false ? 'Success' : 'Failure') . "\n";
    
    // Card with method chaining
    $card2 = new Card();
    $card2->set_title('Chained Card')
          ->set_content('Chained content')
          ->set_footer('Chained footer')
          ->add_class('chained-class');
    
    $card2_html = $card2->render();
    echo "Chained Card HTML: " . (strpos($card2_html, 'Chained Card') !== false ? 'Success' : 'Failure') . "\n";
}

// Test Button component
function test_button_component() {
    // Basic button test
    $button = new Button('Test Button', 'https://example.com', 'primary-button');
    $button_html = $button->render();
    
    echo "Button HTML Generated: " . (strpos($button_html, 'Test Button') !== false ? 'Success' : 'Failure') . "\n";
    echo "Button has href: " . (strpos($button_html, 'href="https://example.com"') !== false ? 'Success' : 'Failure') . "\n";
    
    // Button with data attributes
    $button2 = new Button('Data Button', '#');
    $button2->add_data_attribute('action', 'test')
           ->add_data_attribute('id', '123')
           ->add_attribute('aria-label', 'Test Button');
    
    $button2_html = $button2->render();
    echo "Button with data attributes: " . (strpos($button2_html, 'data-action="test"') !== false ? 'Success' : 'Failure') . "\n";
    
    // Submit button
    $submit_html = Button::submit('Submit Form', 'submit_button', 'submit-class');
    echo "Submit button: " . (strpos($submit_html, 'type="submit"') !== false ? 'Success' : 'Failure') . "\n";
}

// Test Tabs component
function test_tabs_component() {
    // Create tabs
    $tabs = new Tabs('test-tabs');
    $tabs->add_tab('tab1', 'First Tab', 'Content for first tab')
         ->add_tab('tab2', 'Second Tab', 'Content for second tab')
         ->add_tab('tab3', 'Third Tab', 'Content for third tab');
    
    $tabs_html = $tabs->render();
    
    echo "Tabs HTML Generated: " . (strpos($tabs_html, 'test-tabs') !== false ? 'Success' : 'Failure') . "\n";
    echo "Tabs contain correct number of tabs: " . (substr_count($tabs_html, 'wp-github-sync-tabs-nav-link') === 3 ? 'Success' : 'Failure') . "\n";
    echo "First tab is active: " . (strpos($tabs_html, 'active') !== false ? 'Success' : 'Failure') . "\n";
}

// Test ProgressTracker component
function test_progress_tracker_component() {
    // Create deployment tracker
    $tracker = ProgressTracker::deployment_tracker();
    
    // Update steps to simulate progress
    $tracker->set_current_step(0);
    $tracker->update_step(0, 'completed', 100, 'Preparation complete');
    $tracker->set_current_step(1);
    $tracker->update_step(1, 'in_progress', 50, 'Creating backup...');
    
    $tracker_html = $tracker->render();
    
    echo "Progress Tracker HTML Generated: " . (strpos($tracker_html, 'wp-github-sync-progress-tracker') !== false ? 'Success' : 'Failure') . "\n";
    echo "Step 0 is complete: " . (strpos($tracker_html, 'completed') !== false ? 'Success' : 'Failure') . "\n";
    echo "Step 1 is in progress: " . (strpos($tracker_html, 'in_progress') !== false ? 'Success' : 'Failure') . "\n";
    echo "Overall progress is correct: " . ($tracker->get_overall_progress() === 21 ? 'Success' : 'Failure') . "\n";
    
    // Test sync tracker
    $sync_tracker = ProgressTracker::sync_tracker();
    $sync_tracker->set_current_step(2);
    $sync_tracker->update_step(0, 'completed', 100, 'Connected to GitHub');
    $sync_tracker->update_step(1, 'completed', 100, 'Compared repositories');
    $sync_tracker->update_step(2, 'in_progress', 75, 'Creating backup...');
    
    echo "Sync tracker has correct number of steps: " . (count($sync_tracker->get_data()['steps']) === 5 ? 'Success' : 'Failure') . "\n";
    echo "Sync tracker current step is 2: " . ($sync_tracker->get_data()['current_step'] === 2 ? 'Success' : 'Failure') . "\n";
}

// Test ErrorHandler component
function test_error_handler_component() {
    // Test with WP_Error object
    $error = new WP_Error('github_api_rate_limit', 'GitHub API rate limit exceeded');
    $error_html = ErrorHandler::render_error($error);
    
    echo "Error Handler renders WP_Error: " . (strpos($error_html, 'GitHub API rate limit exceeded') !== false ? 'Success' : 'Failure') . "\n";
    echo "Error Handler provides rate limit resolution steps: " . (strpos($error_html, 'Wait for the rate limit') !== false ? 'Success' : 'Failure') . "\n";
    
    // Test with direct error message
    $direct_error_html = ErrorHandler::render_error('File system error: Could not create directory', 'file_system');
    echo "Error Handler renders direct message: " . (strpos($direct_error_html, 'Could not create directory') !== false ? 'Success' : 'Failure') . "\n";
    
    // Test error categorization
    $repo_error = new WP_Error('repository_not_found', 'Repository does not exist');
    $category = ErrorHandler::categorize_error($repo_error);
    echo "Error categorization: " . ($category === 'repository' ? 'Success' : 'Failure') . "\n";
}

// Run the test
run_ui_components_test();