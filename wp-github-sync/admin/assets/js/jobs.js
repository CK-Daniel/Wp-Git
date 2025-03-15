/**
 * JavaScript for the jobs monitor page.
 */
jQuery(document).ready(function($) {
    // Initialize refresh timer
    let refreshTimerId;
    const refreshInterval = 5000; // 5 seconds
    
    // Function to refresh the jobs page
    function refreshJobsStatus() {
        // Only refresh if auto-refresh is enabled
        if ($('#auto-refresh-toggle').is(':checked')) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'wp_github_sync_check_status',
                    nonce: wpGitHubSync.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Update chunked sync status
                        if (response.data.in_progress) {
                            // Update chunked sync display
                            $('.chunked-sync-status').text(response.data.stage || 'In Progress');
                            $('.chunked-sync-progress').text(response.data.progress || 'Processing...');
                            $('#chunked-sync-container').show();
                        } else {
                            // Hide chunked sync if not active
                            $('#chunked-sync-container').hide();
                        }
                        
                        // Increment refresh count
                        updateRefreshCounter();
                        
                        // Schedule next refresh
                        scheduleNextRefresh();
                    } else {
                        console.error('Error checking jobs status:', response);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX error:', status, error);
                    
                    // Even on error, schedule next refresh
                    scheduleNextRefresh();
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
    
    // Toggle auto-refresh when checkbox changes
    $('#auto-refresh-toggle').on('change', function() {
        if ($(this).is(':checked')) {
            // Start auto-refresh
            $('.refresh-status').text('Auto-refresh enabled');
            scheduleNextRefresh();
        } else {
            // Stop auto-refresh
            $('.refresh-status').text('Auto-refresh disabled');
            clearTimeout(refreshTimerId);
        }
    });
    
    // Initial refresh if auto-refresh is enabled
    if ($('#auto-refresh-toggle').is(':checked')) {
        scheduleNextRefresh();
    }
    
    // Manual refresh button
    $('#manual-refresh-button').on('click', function() {
        refreshJobsStatus();
        updateRefreshCounter();
    });
});