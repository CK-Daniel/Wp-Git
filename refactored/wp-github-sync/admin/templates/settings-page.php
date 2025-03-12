<?php
/**
 * Settings admin page template.
 *
 * @package WPGitHubSync
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Check user capability
if (!wp_github_sync_current_user_can()) {
    wp_die(__('You do not have sufficient permissions to access this page.', 'wp-github-sync'));
}

// Handle test connection form submission
if (isset($_POST['wp_github_sync_test_connection']) && wp_github_sync_current_user_can()) {
    check_admin_referer('wp_github_sync_test_connection');
    
    $test_token = isset($_POST['wp_github_sync_test_token']) ? sanitize_text_field($_POST['wp_github_sync_test_token']) : '';
    $test_repo = isset($_POST['wp_github_sync_test_repo']) ? sanitize_text_field($_POST['wp_github_sync_test_repo']) : '';
    
    $api_client = new WPGitHubSync\API\API_Client();
    
    if (!empty($test_token)) {
        $api_client->set_temporary_token($test_token);
    }
    
    if (!empty($test_repo)) {
        $parsed_url = $api_client->parse_github_url($test_repo);
        if ($parsed_url) {
            // Repository URL is valid
            $auth_result = $api_client->test_authentication();
            
            if ($auth_result === true) {
                // Authentication successful, check repository access
                $repo_result = $api_client->repository_exists($parsed_url['owner'], $parsed_url['repo']);
                
                if ($repo_result) {
                    add_settings_error('wp_github_sync_test', 'test_success', __('Connection test successful! Authentication and repository access verified.', 'wp-github-sync'), 'success');
                } else {
                    add_settings_error('wp_github_sync_test', 'repo_error', __('Authentication successful, but repository not found or access denied. Please check the repository URL and your permissions.', 'wp-github-sync'), 'error');
                }
            } else {
                // Authentication failed
                add_settings_error('wp_github_sync_test', 'auth_error', sprintf(__('Authentication failed: %s', 'wp-github-sync'), $auth_result), 'error');
            }
        } else {
            // Invalid repository URL format
            add_settings_error('wp_github_sync_test', 'url_error', __('Invalid repository URL format. Please use the format: https://github.com/username/repository', 'wp-github-sync'), 'error');
        }
    } else {
        // Missing repository URL
        add_settings_error('wp_github_sync_test', 'repo_missing', __('Please enter a repository URL to test.', 'wp-github-sync'), 'error');
    }
}
?>

<div class="wrap">
    <h1><?php _e('GitHub Sync Settings', 'wp-github-sync'); ?></h1>
    
    <?php settings_errors(); ?>
    
    <form method="post" action="options.php">
        <?php
        settings_fields('wp_github_sync_settings');
        do_settings_sections('wp_github_sync_settings');
        submit_button();
        ?>
    </form>
    
    <hr>
    
    <h2><?php _e('Test Connection', 'wp-github-sync'); ?></h2>
    <p><?php _e('Use this form to test your GitHub authentication and repository access.', 'wp-github-sync'); ?></p>
    
    <form method="post" action="">
        <?php wp_nonce_field('wp_github_sync_test_connection'); ?>
        <table class="form-table">
            <tr>
                <th scope="row"><?php _e('Repository URL', 'wp-github-sync'); ?></th>
                <td>
                    <input type="text" name="wp_github_sync_test_repo" value="<?php echo esc_attr(get_option('wp_github_sync_repository', '')); ?>" class="regular-text">
                    <p class="description"><?php _e('Enter the GitHub repository URL to test.', 'wp-github-sync'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Test Token', 'wp-github-sync'); ?></th>
                <td>
                    <input type="password" name="wp_github_sync_test_token" class="regular-text">
                    <p class="description"><?php _e('Optional. Enter a token to use for this test only. If left empty, the plugin will use the stored token.', 'wp-github-sync'); ?></p>
                </td>
            </tr>
        </table>
        
        <p class="submit">
            <input type="submit" name="wp_github_sync_test_connection" class="button button-secondary" value="<?php _e('Test Connection', 'wp-github-sync'); ?>">
        </p>
    </form>
    
    <hr>
    
    <h2><?php _e('Webhook Setup', 'wp-github-sync'); ?></h2>
    <p><?php _e('To set up a webhook in your GitHub repository, use the following information:', 'wp-github-sync'); ?></p>
    
    <table class="form-table">
        <tr>
            <th scope="row"><?php _e('Webhook URL', 'wp-github-sync'); ?></th>
            <td>
                <code><?php echo esc_html(rest_url('wp-github-sync/v1/webhook')); ?></code>
                <p class="description"><?php _e('Add this URL to your GitHub repository webhook settings.', 'wp-github-sync'); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php _e('Secret', 'wp-github-sync'); ?></th>
            <td>
                <code><?php echo esc_html(get_option('wp_github_sync_webhook_secret', '')); ?></code>
                <p class="description"><?php _e('Add this secret to your GitHub repository webhook settings.', 'wp-github-sync'); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php _e('Content Type', 'wp-github-sync'); ?></th>
            <td>
                <code>application/json</code>
                <p class="description"><?php _e('Set the content type to application/json in your GitHub webhook settings.', 'wp-github-sync'); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php _e('Events', 'wp-github-sync'); ?></th>
            <td>
                <code>Just the push event</code>
                <p class="description"><?php _e('Configure the webhook to trigger only on push events.', 'wp-github-sync'); ?></p>
            </td>
        </tr>
    </table>
</div>