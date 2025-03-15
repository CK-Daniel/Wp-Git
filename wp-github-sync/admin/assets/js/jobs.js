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
                        
                        // Schedule next refresh if auto-refresh is still enabled
                        if (autoRefreshEnabled) {
                            scheduleNextRefresh();
                        }
                    } else {
                        console.error('Error checking jobs status:', response);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX error:', status, error);
                    
                    // Even on error, schedule next refresh if auto-refresh is enabled
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
            $(this).addClass('active');
            scheduleNextRefresh();
        } else {
            // Stop auto-refresh
            $(this).removeClass('active');
            clearTimeout(refreshTimerId);
        }
    });
    
    // Initial auto-refresh
    if (autoRefreshEnabled) {
        scheduleNextRefresh();
    }
    
    // Manual refresh button
    $('#manual-refresh-button').on('click', function() {
        refreshJobsStatus();
        updateRefreshCounter();
    });
});