<?php
/**
 * Handles the background deployment job.
 *
 * @package WPGitHubSync\Admin\Jobs
 */

namespace WPGitHubSync\Admin\Jobs;

use WPGitHubSync\API\API_Client;
use WPGitHubSync\Sync\Sync_Manager; // Use Sync_Manager which delegates to orchestrator

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Deploy Job Handler class.
 */
class DeployJobHandler implements JobHandler {

    /** @var API_Client */
    private $github_api;
    /** @var Sync_Manager */
    private $sync_manager;

    /**
     * Constructor.
     *
     * @param API_Client   $github_api   The API Client instance.
     * @param Sync_Manager $sync_manager The Sync Manager instance.
     */
    public function __construct(API_Client $github_api, Sync_Manager $sync_manager) {
        $this->github_api = $github_api;
        $this->sync_manager = $sync_manager;
    }

    /**
     * Execute the deployment job.
     *
     * @param array $params Parameters, expected to contain 'branch'.
     * @return bool|\WP_Error Result of the deployment.
     * @throws \Exception If deployment fails.
     */
    public function handle(array $params) {
        $branch = $params['branch'] ?? wp_github_sync_get_current_branch();
        wp_github_sync_log("Deploy Job Handler: Running deploy for branch '{$branch}'", 'info');

        // Initialize API client (might be redundant if already done)
        $this->github_api->initialize();

        // Delegate deployment to Sync_Manager (which uses DeploymentOrchestrator)
        $result = $this->sync_manager->deploy($branch);

        if (is_wp_error($result)) {
            throw new \Exception($result->get_error_message());
        }

        wp_github_sync_log("Deploy Job Handler: Deploy job for branch '{$branch}' completed successfully.", 'info');
        return true; // Indicate success
    }
}
