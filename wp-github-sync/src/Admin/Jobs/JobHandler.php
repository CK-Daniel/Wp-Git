<?php
/**
 * Interface for background job handlers.
 *
 * @package WPGitHubSync\Admin\Jobs
 */

namespace WPGitHubSync\Admin\Jobs;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Interface JobHandler.
 */
interface JobHandler {
    /**
     * Execute the job.
     *
     * @param array $params Parameters for the job.
     * @return mixed Result of the job execution.
     * @throws \Exception If the job fails.
     */
    public function handle(array $params);
}
