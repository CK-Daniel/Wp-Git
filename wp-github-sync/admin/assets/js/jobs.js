/**
 * JavaScript for the jobs monitor page.
 */
jQuery(document).ready(function($) {
    // Initialize refresh timer
    let refreshTimerId;
    const refreshInterval = 5000; // 5 seconds
    let autoRefreshEnabled = true;
    
    // Function to refresh the jobs page
    function refreshJobsStatus() {
        // Only refresh if auto-refresh is enabled
        if (autoRefreshEnabled) {
            $.ajax({
                url: ajaxurl, // Use global ajaxurl variable
                type: 'POST',
                data: {
                    action: 'wp_github_sync_check_status',
                    nonce: wpGitHubSync.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // TODO: Update this section to display a detailed list of jobs.
                        // This requires backend changes: either modify 'wp_github_sync_check_status'
                        // or create a new action ('wp_github_sync_get_jobs_list') to query
                        // Action Scheduler/WP Cron and return an array in response.data.jobs.
                        // Example job structure: [{hook: '...', args: ..., status: 'running', next_run_gmt: '...', ...}]

                        const jobsContainer = $('#wp-github-sync-jobs-list'); // Assuming a container element exists
                        jobsContainer.empty(); // Clear previous list

                        if (response.data.jobs && response.data.jobs.length > 0) {
                             // Placeholder: Render the detailed job list here
                             jobsContainer.append('<h2>Active/Pending Jobs</h2>');
                             const list = $('<ul></ul>');
                             response.data.jobs.forEach(function(job) {
                                 const argsString = JSON.stringify(job.args);
                                 // Add more details like next run time, etc.
                                 list.append(`<li><strong>${job.hook}</strong> (${job.status || 'pending'}) - Args: ${argsString}</li>`);
                             });
                             jobsContainer.append(list);
                             jobsContainer.show();
                        } else if (response.data.in_progress) {
                             // Fallback: Show the message provided by the backend status check
                             jobsContainer.append('<p>Background process running: ' + (response.data.message || 'Processing...') + '</p>');
                             jobsContainer.show();
                        } else {
                             jobsContainer.append('<p>No active background jobs found.</p>');
                             jobsContainer.show(); // Show the "no jobs" message
                        }


                        // Increment refresh count
                        updateRefreshCounter();
                        
                        // Schedule next refresh if auto-refresh is still enabled
                        if (autoRefreshEnabled) {
                            scheduleNextRefresh();
                        }
                    } else {
                        console.error('Error checking jobs status:', response);
                        // Optionally display an error message in the UI
                        $('#wp-github-sync-jobs-list').html('<p class="error">Error retrieving job status.</p>').show();
                    }
                },
                error: function(xhr, status, error) {
                    // Use global handler, but don't necessarily stop polling
                    window.wpGitHubSyncAdmin.handleGlobalAjaxError(xhr, status, error, 'Check Status');
                    console.error('AJAX error checking job status:', status, error);
                    $('#wp-github-sync-jobs-list').html('<p class="error">Error communicating with server.</p>').show();

                    // Even on error, schedule next refresh if auto-refresh is enabled
                    // This prevents a temporary network blip from stopping the monitor
                    if (autoRefreshEnabled) {
                        scheduleNextRefresh();
                    }
                }
            });
        }
    }
    
    // Function to update the refresh counter
    function updateRefreshCounter() {
        let count = parseInt($('#refresh-count').text()) || 0;
        $('#refresh-count').text(count + 1);
    }
    
    // Function to schedule the next refresh
    function scheduleNextRefresh() {
        clearTimeout(refreshTimerId);
        refreshTimerId = setTimeout(refreshJobsStatus, refreshInterval);
    }
    
    // Toggle auto-refresh when clicking the toggle button
    $('.wp-github-sync-auto-refresh-toggle').on('click', function() {
        autoRefreshEnabled = !autoRefreshEnabled;
        
        if (autoRefreshEnabled) {
            // Start auto-refresh
            $(this).addClass('active').text('Auto-Refresh: ON');
            refreshJobsStatus(); // Refresh immediately when turned on
        } else {
            // Stop auto-refresh
            $(this).removeClass('active').text('Auto-Refresh: OFF');
            clearTimeout(refreshTimerId);
        }
    });
    
    // Initial auto-refresh state and text
    if (autoRefreshEnabled) {
        $('.wp-github-sync-auto-refresh-toggle').addClass('active').text('Auto-Refresh: ON');
        scheduleNextRefresh();
    } else {
         $('.wp-github-sync-auto-refresh-toggle').removeClass('active').text('Auto-Refresh: OFF');
    }
    
    // Manual refresh button
    $('#manual-refresh-button').on('click', function() {
        refreshJobsStatus();
    });

    // Initial load
    refreshJobsStatus();
});
