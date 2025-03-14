<?php
/**
 * Final simplified test for WP-GitHub-Sync initializing empty repositories
 */

// Setup requirements
define('WPINC', 'wp-includes');
define('WP_CONTENT_DIR', '/tmp');
define('ABSPATH', '/');
define('DAY_IN_SECONDS', 86400);
define('WP_GITHUB_SYNC_URL', '');
define('WP_GITHUB_SYNC_DIR', '/');

// Functions needed globally
function get_option($option, $default = false) {
    global $wp_options;
    return isset($wp_options[$option]) ? $wp_options[$option] : $default;
}

function update_option($option, $value, $autoload = null) {
    global $wp_options;
    $wp_options[$option] = $value;
    return true;
}

function get_transient($key) {
    global $wp_transients;
    return isset($wp_transients[$key]) ? $wp_transients[$key] : false;
}

function set_transient($key, $value, $expiration = 0) {
    global $wp_transients;
    $wp_transients[$key] = $value;
    return true;
}

function delete_transient($key) {
    global $wp_transients;
    if (isset($wp_transients[$key])) {
        unset($wp_transients[$key]);
        return true;
    }
    return false;
}

function trailingslashit($string) {
    return rtrim($string, '/\\') . '/';
}

function get_bloginfo($show = '') {
    switch ($show) {
        case 'name': return 'Test Site';
        case 'url':  return 'https://example.com';
        case 'version': return '6.3';
        default:     return '';
    }
}

function wp_github_sync_log($message, $level = 'info') {
    echo "[$level] $message\n";
}

function wp_github_sync_encrypt($data) {
    return $data;
}

function wp_github_sync_decrypt($data) {
    return $data;
}

function is_wp_error($thing) {
    return $thing instanceof WP_Error;
}

class WP_Error {
    private $message;
    
    public function __construct($code = '', $message = '') {
        $this->message = $message;
    }
    
    public function get_error_message() {
        return $this->message;
    }
}

function wp_remote_request($url, $args = []) {
    $method = isset($args['method']) ? $args['method'] : 'GET';
    $headers = isset($args['headers']) ? $args['headers'] : [];
    $body = isset($args['body']) ? $args['body'] : null;
    
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HEADER, true);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
    
    // Set headers
    $header_array = [];
    foreach ($headers as $key => $value) {
        $header_array[] = "$key: $value";
    }
    
    if (!empty($header_array)) {
        curl_setopt($curl, CURLOPT_HTTPHEADER, $header_array);
    }
    
    // Set body for POST, PUT, etc.
    if ($body && in_array($method, ['POST', 'PUT', 'PATCH'])) {
        if (is_array($body)) {
            $body = http_build_query($body);
        }
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }
    
    $response = curl_exec($curl);
    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    
    curl_close($curl);
    
    if ($error) {
        return new WP_Error('http_request_failed', $error);
    }
    
    $headers_string = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    
    $headers = [];
    foreach (explode("\r\n", $headers_string) as $line) {
        if (strpos($line, ':') !== false) {
            list($key, $value) = explode(':', $line, 2);
            $headers[strtolower(trim($key))] = trim($value);
        }
    }
    
    return [
        'headers' => $headers,
        'body' => $body,
        'response' => [
            'code' => $status_code,
            'message' => ''
        ],
        'cookies' => [],
        'http_response' => null
    ];
}

function wp_remote_get($url, $args = []) {
    return wp_remote_request($url, $args);
}

function wp_remote_post($url, $args = []) {
    $args['method'] = 'POST';
    return wp_remote_request($url, $args);
}

function wp_remote_retrieve_body($response) {
    if (is_wp_error($response) || !isset($response['body'])) {
        return '';
    }
    return $response['body'];
}

function wp_remote_retrieve_response_code($response) {
    if (is_wp_error($response) || !isset($response['response']['code'])) {
        return 0;
    }
    return $response['response']['code'];
}

function wp_remote_retrieve_header($response, $header) {
    if (is_wp_error($response) || !isset($response['headers'][strtolower($header)])) {
        return '';
    }
    return $response['headers'][strtolower($header)];
}

/**
 * GitHub API Client class mockup
 */
class GitHub_API_Client {
    private $token;
    private $owner;
    private $repo;
    
    public function __construct($token, $repo_url) {
        $this->token = $token;
        
        // Parse repo URL
        if (preg_match('@github\.com[/:]([\w-]+)/([\w-]+)@', $repo_url, $matches)) {
            $this->owner = $matches[1];
            $this->repo = $matches[2];
            echo "Parsed repo URL: {$this->owner}/{$this->repo}\n";
        } else {
            echo "Invalid GitHub repository URL\n";
        }
    }
    
    public function initialize_repository($branch = 'main') {
        echo "Initializing repository {$this->owner}/{$this->repo} on branch {$branch}\n";
        
        // First check if README.md already exists
        $check_result = $this->make_request(
            "https://api.github.com/repos/{$this->owner}/{$this->repo}/contents/README.md",
            'GET'
        );
        
        // If README already exists, just return success
        if (isset($check_result['response']['code']) && $check_result['response']['code'] == 200) {
            echo "Repository already has a README.md file\n";
            return true;
        }
        
        // Create README.md with Contents API
        $readme_content = "# Test Repository\n\nInitialized by test script\n";
        
        // Get current commit SHA for the branch
        $branch_ref = $this->make_request(
            "https://api.github.com/repos/{$this->owner}/{$this->repo}/git/refs/heads/{$branch}",
            'GET'
        );
        
        // If branch doesn't exist, we need to get default branch info
        if (!isset($branch_ref['response']['code']) || $branch_ref['response']['code'] != 200) {
            $repo_info = $this->make_request(
                "https://api.github.com/repos/{$this->owner}/{$this->repo}",
                'GET'
            );
            
            if (isset($repo_info['response']['code']) && $repo_info['response']['code'] == 200) {
                $repo_data = json_decode($repo_info['body'], true);
                $default_branch = isset($repo_data['default_branch']) ? $repo_data['default_branch'] : 'main';
                
                // Get the default branch reference
                $branch_ref = $this->make_request(
                    "https://api.github.com/repos/{$this->owner}/{$this->repo}/git/refs/heads/{$default_branch}",
                    'GET'
                );
            }
        }
        
        // Now create the README
        if (isset($branch_ref['response']['code']) && $branch_ref['response']['code'] == 200) {
            $branch_data = json_decode($branch_ref['body'], true);
            $sha = isset($branch_data['object']['sha']) ? $branch_data['object']['sha'] : null;
            
            if ($sha) {
                // For a new file, we don't need to include SHA
                $result = $this->make_request(
                    "https://api.github.com/repos/{$this->owner}/{$this->repo}/contents/README.md",
                    'PUT',
                    [
                        'message' => 'Initial commit',
                        'content' => base64_encode($readme_content),
                        'branch' => $branch
                    ]
                );
            } else {
                echo "Couldn't get branch SHA, trying without it...\n";
                $result = $this->make_request(
                    "https://api.github.com/repos/{$this->owner}/{$this->repo}/contents/README.md",
                    'PUT',
                    [
                        'message' => 'Initial commit',
                        'content' => base64_encode($readme_content),
                        'branch' => $branch
                    ]
                );
            }
        } else {
            // If we can't get branch info, try with just a direct PUT
            $result = $this->make_request(
                "https://api.github.com/repos/{$this->owner}/{$this->repo}/contents/README.md",
                'PUT',
                [
                    'message' => 'Initial commit',
                    'content' => base64_encode($readme_content),
                    'branch' => $branch
                ]
            );
        }
        
        if (isset($result['response']['code']) && ($result['response']['code'] == 201 || $result['response']['code'] == 200)) {
            echo "Successfully initialized repository with README.md\n";
            return true;
        } else {
            $error = json_decode($result['body'], true);
            $message = isset($error['message']) ? $error['message'] : 'Unknown error';
            echo "Failed to initialize repository: $message\n";
            return false;
        }
    }
    
    private function make_request($url, $method = 'GET', $data = null) {
        $headers = [
            'Accept' => 'application/vnd.github+json',
            'Authorization' => "Bearer {$this->token}",
            'User-Agent' => 'WordPress-GitHub-Sync-Test',
            'X-GitHub-Api-Version' => '2022-11-28'
        ];
        
        $args = [
            'method' => $method,
            'headers' => $headers
        ];
        
        if ($data && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $args['body'] = json_encode($data);
        }
        
        return wp_remote_request($url, $args);
    }
    
    public function check_repository_exists() {
        $result = $this->make_request("https://api.github.com/repos/{$this->owner}/{$this->repo}");
        return (isset($result['response']['code']) && $result['response']['code'] == 200);
    }
    
    public function get_default_branch() {
        $result = $this->make_request("https://api.github.com/repos/{$this->owner}/{$this->repo}");
        
        if (isset($result['response']['code']) && $result['response']['code'] == 200) {
            $repo_data = json_decode($result['body'], true);
            return isset($repo_data['default_branch']) ? $repo_data['default_branch'] : 'main';
        }
        
        return 'main';
    }
}

// Main test function
function run_test() {
    // Test configuration
    $token = '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';  // Replace with your token
    $repo_url = 'https://github.com/CK-Daniel/kuper';    // Replace with your repository URL
    
    // Create the API client
    $api = new GitHub_API_Client($token, $repo_url);
    
    // Check if repository exists
    echo "\nChecking if repository exists...\n";
    if ($api->check_repository_exists()) {
        echo "Repository exists!\n";
    } else {
        echo "Repository does not exist or is not accessible with provided token.\n";
        return;
    }
    
    // Initialize the repository
    echo "\nInitializing repository...\n";
    if ($api->initialize_repository('main')) {
        echo "Repository initialized successfully!\n";
        
        // Get default branch
        $default_branch = $api->get_default_branch();
        echo "Default branch is: {$default_branch}\n";
    } else {
        echo "Repository initialization failed.\n";
    }
}

// Run the test
run_test();