<?php
/**
 * Rollback Manager
 *
 * @package WPGitHubSync
 */

namespace WPGitHubSync\Sync;

use WPGitHubSync\API\Client;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Manages rollback operations
 */
class RollbackManager {
    /**
     * Plugin version
     *
     * @var string
     */
    private $version;

    /**
     * Deployment history
     *
     * @var array
     */
    private $deployment_history = [];

    /**
     * API client
     *
     * @var Client
     */
    private $client;

    /**
     * Backup manager
     *
     * @var BackupManager
     */
    private $backup_manager;

    /**
     * Initialize the rollback manager
     *
     * @param string $version Plugin version.
     */
    public function __construct( $version ) {
        $this->version = $version;
        $this->client = new Client( $version );
        $this->backup_manager = new BackupManager();
        $this->load_deployment_history();
    }

    /**
     * Load deployment history
     */
    protected function load_deployment_history() {
        $this->deployment_history = get_option( 'wp_github_sync_deployment_history', [] );
    }

    /**
     * Save deployment history
     */
    protected function save_deployment_history() {
        update_option( 'wp_github_sync_deployment_history', $this->deployment_history, false );
    }

    /**
     * Add a deployment to history
     *
     * @param array $deployment Deployment data.
     */
    public function add_deployment( $deployment ) {
        $deployment_defaults = [
            'id'        => uniqid( 'deploy-' ),
            'timestamp' => time(),
            'date'      => current_time( 'mysql' ),
            'user_id'   => get_current_user_id(),
            'user_login' => wp_get_current_user()->user_login,
            'success'   => true,
            'message'   => '',
            'commit'    => '',
            'branch'    => '',
            'backup_id' => '',
        ];

        $deployment = wp_parse_args( $deployment, $deployment_defaults );

        // Add to the beginning of the history array
        array_unshift( $this->deployment_history, $deployment );

        // Limit history to 100 entries
        if ( count( $this->deployment_history ) > 100 ) {
            $this->deployment_history = array_slice( $this->deployment_history, 0, 100 );
        }

        $this->save_deployment_history();
    }

    /**
     * Find a deployment by ID
     *
     * @param string $deployment_id Deployment ID.
     * @return array|null Deployment data or null if not found.
     */
    public function find_deployment( $deployment_id ) {
        foreach ( $this->deployment_history as $deployment ) {
            if ( isset( $deployment['id'] ) && $deployment['id'] === $deployment_id ) {
                return $deployment;
            }
        }

        return null;
    }

    /**
     * Find a deployment by commit SHA
     *
     * @param string $commit_sha Commit SHA.
     * @return array|null Deployment data or null if not found.
     */
    public function find_deployment_by_commit( $commit_sha ) {
        foreach ( $this->deployment_history as $deployment ) {
            if ( isset( $deployment['commit'] ) && $deployment['commit'] === $commit_sha ) {
                return $deployment;
            }
        }

        return null;
    }

    /**
     * Roll back to a specific deployment
     *
     * @param string $deployment_id Deployment ID.
     * @return true|\WP_Error True on success or WP_Error on failure.
     */
    public function rollback_to_deployment( $deployment_id ) {
        $deployment = $this->find_deployment( $deployment_id );

        if ( ! $deployment ) {
            return new \WP_Error(
                'deployment_not_found',
                __( 'Deployment not found', 'wp-github-sync' )
            );
        }

        // Check if this deployment has a backup ID
        if ( ! empty( $deployment['backup_id'] ) ) {
            // Try to restore from backup first
            $result = $this->backup_manager->restore_backup( $deployment['backup_id'] );

            if ( $result ) {
                $this->log_rollback_success( $deployment );
                return true;
            }
        }

        // If backup restoration failed or no backup exists, try to deploy the commit
        if ( ! empty( $deployment['commit'] ) ) {
            return $this->rollback_to_commit( $deployment['commit'] );
        }

        return new \WP_Error(
            'rollback_failed',
            __( 'No suitable rollback method found', 'wp-github-sync' )
        );
    }

    /**
     * Roll back to a specific commit
     *
     * @param string $commit_sha Commit SHA.
     * @return true|\WP_Error True on success or WP_Error on failure.
     */
    public function rollback_to_commit( $commit_sha ) {
        // Create a backup before rolling back
        $backup_id = $this->backup_manager->create_backup();

        if ( ! $backup_id ) {
            return new \WP_Error(
                'backup_failed',
                __( 'Failed to create backup before rollback', 'wp-github-sync' )
            );
        }

        // Enable maintenance mode
        wp_github_sync_maintenance_mode( true );

        try {
            // Get commit details
            $commit = $this->client->get_commit( $commit_sha );

            if ( is_wp_error( $commit ) ) {
                throw new \Exception( $commit->get_error_message() );
            }

            // Download the repository at this commit
            $zip_file = $this->client->download_archive( $commit_sha );

            if ( is_wp_error( $zip_file ) ) {
                throw new \Exception( $zip_file->get_error_message() );
            }

            // Extract and deploy the files
            $repository = new \WPGitHubSync\API\Repository( $this->version );
            $result = $repository->extract_and_deploy( $zip_file );

            if ( is_wp_error( $result ) ) {
                throw new \Exception( $result->get_error_message() );
            }

            // Record the rollback
            $deployment = [
                'commit'    => $commit_sha,
                'message'   => __( 'Rollback to commit: ', 'wp-github-sync' ) . substr( $commit_sha, 0, 7 ),
                'backup_id' => $backup_id,
                'is_rollback' => true,
            ];

            $this->add_deployment( $deployment );

            return true;
        } catch ( \Exception $e ) {
            // Restore from backup on failure
            $this->backup_manager->restore_backup( $backup_id );

            return new \WP_Error(
                'rollback_failed',
                sprintf(
                    /* translators: %s: Error message */
                    __( 'Rollback failed: %s', 'wp-github-sync' ),
                    $e->getMessage()
                )
            );
        } finally {
            // Disable maintenance mode
            wp_github_sync_maintenance_mode( false );
        }
    }

    /**
     * Intelligent rollback that chooses the best method based on available information
     *
     * @param string $target Target commit SHA, deployment ID, or special keyword like 'previous'.
     * @return true|\WP_Error True on success or WP_Error on failure.
     */
    public function smart_rollback( $target ) {
        // Handle special keywords
        if ( $target === 'previous' || $target === 'last' ) {
            // Find the second-to-latest successful deployment
            $successful_deployments = array_filter( $this->deployment_history, function( $deployment ) {
                return ! empty( $deployment['success'] ) && empty( $deployment['is_rollback'] );
            } );

            if ( count( $successful_deployments ) >= 2 ) {
                $target_deployment = $successful_deployments[1]; // Second item (index 1) is the previous successful deployment
                return $this->rollback_to_deployment( $target_deployment['id'] );
            } else {
                return new \WP_Error(
                    'no_previous_deployment',
                    __( 'No previous successful deployment found', 'wp-github-sync' )
                );
            }
        }

        // Check if target is a deployment ID
        $deployment = $this->find_deployment( $target );
        if ( $deployment ) {
            return $this->rollback_to_deployment( $target );
        }

        // Check if target is a commit SHA
        $deployment = $this->find_deployment_by_commit( $target );
        if ( $deployment ) {
            return $this->rollback_to_deployment( $deployment['id'] );
        }

        // Assume target is a commit SHA that isn't in our history
        return $this->rollback_to_commit( $target );
    }

    /**
     * Get deployment history
     *
     * @param int $limit Maximum number of entries to return (0 for all).
     * @return array Deployment history.
     */
    public function get_deployment_history( $limit = 0 ) {
        if ( $limit > 0 && count( $this->deployment_history ) > $limit ) {
            return array_slice( $this->deployment_history, 0, $limit );
        }

        return $this->deployment_history;
    }

    /**
     * Log rollback success
     *
     * @param array $deployment Deployment data.
     */
    protected function log_rollback_success( $deployment ) {
        wp_github_sync_log(
            sprintf(
                /* translators: 1: Deployment ID, 2: Commit SHA */
                __( 'Successfully rolled back to deployment %1$s (commit: %2$s)', 'wp-github-sync' ),
                $deployment['id'],
                isset( $deployment['commit'] ) ? substr( $deployment['commit'], 0, 7 ) : 'unknown'
            ),
            'info'
        );

        // Add rollback to deployment history
        $this->add_deployment( [
            'commit'    => isset( $deployment['commit'] ) ? $deployment['commit'] : '',
            'message'   => sprintf(
                /* translators: %s: Deployment ID */
                __( 'Rollback to deployment: %s', 'wp-github-sync' ),
                $deployment['id']
            ),
            'is_rollback' => true,
        ] );
    }
}