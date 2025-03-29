<?php
/**
 * Handles creating Git blobs via the GitHub API.
 *
 * @package WPGitHubSync\API\GitData
 */

namespace WPGitHubSync\API\GitData;

use WPGitHubSync\API\API_Client;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Blob Creator class.
 */
class BlobCreator {

    /**
     * API Client instance.
     * @var API_Client
     */
    private $api_client;

    /**
     * Constructor.
     *
     * @param API_Client $api_client The API Client instance.
     */
    public function __construct(API_Client $api_client) {
        $this->api_client = $api_client;
    }

    /**
     * Create a Git blob for a given file path.
     * Handles encoding, retries, and large file considerations.
     *
     * @param string $file_path The absolute path to the file.
     * @param string $github_relative_path The relative path for logging/error messages.
     * @return array|\WP_Error Blob data array on success, WP_Error on failure.
     */
    public function create_blob(string $file_path, string $github_relative_path) {
        try {
            $content = file_get_contents($file_path);
            if ($content === false) {
                throw new \Exception("Could not read file content.");
            }

            $file_size = filesize($file_path);
            $is_binary = $this->is_binary_file($file_path);
            $blob_data = [];

            if ($is_binary) {
                $blob_data = ['content' => base64_encode($content), 'encoding' => 'base64'];
            } else {
                // Check for null bytes or invalid UTF-8
                if (strpos($content, "\0") !== false || (function_exists('mb_check_encoding') && !mb_check_encoding($content, 'UTF-8'))) {
                    wp_github_sync_log("File {$github_relative_path} contains null bytes or invalid UTF-8, using base64.", 'debug');
                    $blob_data = ['content' => base64_encode($content), 'encoding' => 'base64'];
                } else {
                    $blob_data = ['content' => $content, 'encoding' => 'utf-8'];
                }
            }

            // Handle large files (>3MB) with retries
            $large_file_threshold = 3 * 1024 * 1024; // 3MB
            $blob = null;

            if ($file_size > $large_file_threshold) {
                wp_github_sync_log("Large file detected ({$file_size} bytes): {$github_relative_path}. Using special upload handling.", 'info');
                $blob = $this->create_blob_with_retries($blob_data, $github_relative_path, 60); // 60s timeout
            } else {
                $blob = $this->create_blob_with_retries($blob_data, $github_relative_path); // Default timeout
            }

            if (is_wp_error($blob)) {
                 throw new \Exception($blob->get_error_message());
            }

            return $blob; // Return the successful blob data

        } catch (\Exception $e) {
            wp_github_sync_log("Failed to create blob for {$github_relative_path}: " . $e->getMessage(), 'error');
            return new \WP_Error('blob_creation_failed', $e->getMessage());
        }
    }

    /**
     * Attempts to create a blob with retries, handling potential encoding issues.
     *
     * @param array  $blob_data            The initial blob data (content and encoding).
     * @param string $github_relative_path The file path for logging.
     * @param int    $timeout              Request timeout.
     * @return array|\WP_Error Blob data array on success, WP_Error on failure.
     */
    private function create_blob_with_retries(array $blob_data, string $github_relative_path, int $timeout = 30) {
        $max_retries = 3;
        $retry_count = 0;
        $blob = null;

        while ($retry_count < $max_retries) {
            $retry_count++;
            if ($retry_count > 1) {
                wp_github_sync_log("Retry #{$retry_count} for blob creation: {$github_relative_path}", 'debug');
                sleep(1 * $retry_count); // Simple linear backoff
            }

            $blob = $this->api_client->request(
                "repos/{$this->api_client->get_owner()}/{$this->api_client->get_repo()}/git/blobs",
                'POST',
                $blob_data,
                false, // handle_empty_repo_error
                $timeout
            );

            if (!is_wp_error($blob)) {
                return $blob; // Success!
            }

            // If error, check if it's an encoding issue and we haven't already forced base64
            $error_message = $blob->get_error_message();
            $error_code = $blob->get_error_code();
            wp_github_sync_log("Blob creation attempt #{$retry_count} failed for {$github_relative_path}: {$error_message}", 'warning');

            if ($blob_data['encoding'] !== 'base64' &&
                (strpos($error_message, 'encoding') !== false || strpos($error_code, '422') !== false || strpos($error_code, '400') !== false))
            {
                wp_github_sync_log("Retrying blob creation with forced base64 encoding for {$github_relative_path}", 'debug');
                $original_content = ($blob_data['encoding'] === 'utf-8') ? $blob_data['content'] : base64_decode($blob_data['content']);
                $blob_data = ['content' => base64_encode($original_content), 'encoding' => 'base64'];
                // Continue loop to retry with base64
            } else {
                // Don't retry other errors or if already tried base64
                break;
            }
        }

        // If all retries failed
        return $blob; // Return the last WP_Error
    }


    /**
     * Simple check to determine if a file is binary or text.
     * Looks for null bytes or high ratio of non-printable chars.
     *
     * @param string $file The path to the file to check.
     * @return bool True if the file appears to be binary, false otherwise.
     */
    private function is_binary_file($file) {
        if (!is_file($file) || !is_readable($file)) {
            return false; // Cannot determine
        }

        $fh = fopen($file, 'r');
        if (!$fh) return false;
        $sample = fread($fh, 1024); // Read first 1KB
        fclose($fh);

        if ($sample === false || empty($sample)) {
            return false; // Empty or unreadable
        }

        // Check for null byte
        if (strpos($sample, "\0") !== false) {
            return true;
        }

        // Check ratio of control characters (excluding common whitespace)
        $control_chars = 0;
        $total_chars = strlen($sample);
        for ($i = 0; $i < $total_chars; $i++) {
            $char = ord($sample[$i]);
            if (($char < 32 && !in_array($char, [9, 10, 13])) || $char == 127) { // DEL char
                $control_chars++;
            }
        }

        // If > 10% control characters, likely binary (adjust threshold as needed)
        return ($total_chars > 0) && (($control_chars / $total_chars) > 0.1);
    }
}
