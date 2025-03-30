(function($) {
    'use strict';

    // Make overlay functions globally accessible within wpGitHubSync scope
    window.wpGitHubSyncAdmin = window.wpGitHubSyncAdmin || {};

    /**
     * Show the loading overlay
     */
    window.wpGitHubSyncAdmin.showOverlay = function(message, submessage) {
        var $overlay = $('.wp-github-sync-overlay');
        // Clear previous errors/details when showing overlay
        $('#wp-github-sync-ajax-error-notice').empty().hide();
        $('.wp-github-sync-status-detail').empty(); // Clear status detail too

        if (message) {
            $('.wp-github-sync-loading-message').text(message);
        } else {
             $('.wp-github-sync-loading-message').text('Processing...'); // Default message
        }
        if (submessage) {
            $('.wp-github-sync-loading-submessage').text(submessage);
        } else {
             $('.wp-github-sync-loading-submessage').text(''); // Clear submessage
        }
        $overlay.fadeIn(200);
    }

    /**
     * Hide the loading overlay
     */
    window.wpGitHubSyncAdmin.hideOverlay = function() {
        $('.wp-github-sync-overlay').fadeOut(200);
    }

    /**
     * Global AJAX Error Handler
     * Displays errors prominently on the page instead of just the overlay.
     */
    window.wpGitHubSyncAdmin.handleGlobalAjaxError = function(xhr, status, error, context) {
        console.error(`AJAX Error${context ? ' (' + context + ')' : ''}:`, status, error);
        console.error('XHR:', xhr);

        let errorMsg = wpGitHubSync.strings.error || 'An unexpected error occurred. Please try again.'; // Default error
        let detailedMsg = '';

        if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
            errorMsg = xhr.responseJSON.data.message;
        } else if (xhr.responseText) {
            // Try to extract WP error message if possible
            const wpErrorMatch = xhr.responseText.match(/<p>(There has been a critical error.*?)<\/p>/i);
            const generalErrorMatch = xhr.responseText.match(/<p>(.*?)<\/p>/i); // More generic paragraph match
            if (wpErrorMatch && wpErrorMatch[1]) {
                errorMsg = 'WordPress Critical Error. Check server logs.';
                detailedMsg = wpErrorMatch[1]; // Show the critical error paragraph
            } else if (generalErrorMatch && generalErrorMatch[1]) {
                 // Avoid showing generic HTML page content as error
                 if (xhr.responseText.length < 500 && xhr.responseText.indexOf('<html') === -1) {
                     errorMsg = generalErrorMatch[1];
                 } else {
                     errorMsg = `Server returned status ${xhr.status}. Check console/logs.`;
                 }
            } else if (xhr.responseText.length < 500) { // Show short raw response if no paragraph found
                 errorMsg = xhr.responseText;
            } else {
                 errorMsg = `Server returned status ${xhr.status}. Check console/logs.`;
            }
        } else if (error) {
             errorMsg = error; // Use the status text like 'timeout', 'error', 'abort', 'parsererror'
        } else if (status) {
             errorMsg = status;
        }

        // Always hide the overlay on error
        window.wpGitHubSyncAdmin.hideOverlay();

        // Display the error using WordPress notices area
        const errorContainer = $('#wp-github-sync-ajax-error-notice');
        if (errorContainer.length === 0) {
             // If container doesn't exist, prepend it after h1
             $('h1.wp-heading-inline').first().after('<div id="wp-github-sync-ajax-error-notice"></div>');
        }
         $('#wp-github-sync-ajax-error-notice').html(
            '<div class="notice notice-error is-dismissible"><p><strong>GitHub Sync Error:</strong> ' +
            $('<div>').text(errorMsg).html() + // Sanitize basic message
            (detailedMsg ? '<br><small>' + detailedMsg + '</small>' : '') + // Show detailed message if available
            '</p></div>'
        ).show();

         // Make the notice dismissible
         $('#wp-github-sync-ajax-error-notice .notice').append('<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>');
         $('#wp-github-sync-ajax-error-notice').on('click', '.notice-dismiss', function() {
             $(this).closest('.notice').fadeOut(300, function() { $(this).remove(); });
         });
    }


    $(document).ready(function() {
        /**
         * Initialize tabs
         */
        function initializeTabs() {
            // Get the tab hash
            function getCurrentTab() {
                var hash = window.location.hash;
                // Default to 'guide' tab on dashboard, 'general' on settings
                var defaultTab = 'dashboard'; // Default assumption
                if ($('body').hasClass('github-sync_page_wp-github-sync-settings')) {
                    defaultTab = 'general';
                } else if ($('body').hasClass('github-sync_page_wp-github-sync')) {
                     defaultTab = 'guide'; // Explicitly set for dashboard
                }

                if (hash && hash.indexOf('#tab-') === 0) {
                    // Check if the tab exists on the current page before returning
                    var requestedTabId = hash.substring(5);
                    if ($('.wp-github-sync-tab[data-tab="' + requestedTabId + '"]').length > 0) {
                        return requestedTabId;
                    }
                }
                return defaultTab; // Return page-specific default if hash invalid or tab doesn't exist
            }

            // Show a specific tab
            function showTab(tabId) {
                // Hide all tabs content panes on the current page
                $('.wp-github-sync-tab-content').hide();
                // Deactivate all tabs on the current page
                $('.wp-github-sync-tab').removeClass('active');

                // Show selected tab content pane
                $('#tab-' + tabId).show();
                // Activate selected tab
                $('.wp-github-sync-tab[data-tab="' + tabId + '"]').addClass('active');

                // Update URL hash only if the tab exists
                if ($('#tab-' + tabId).length > 0) {
                    window.location.hash = 'tab-' + tabId;
                }
            }

            // Initialize based on URL hash or default
            function initializeFromHash() {
                var tabId = getCurrentTab();
                showTab(tabId);
            }

            // Set up tab click handlers
            $('.wp-github-sync-tab').on('click', function(e) {
                e.preventDefault();
                var tabId = $(this).data('tab');
                showTab(tabId);
            });

            // Handle hashchange event for back/forward browser navigation
            $(window).off('hashchange.wp-github-sync').on('hashchange.wp-github-sync', function() {
                initializeFromHash();
            });

            // Initialize on page load
            initializeFromHash();

            // Log successful tab initialization
            console.log('WP GitHub Sync: Tabs initialized');
        }

        // Initialize tabs and setup global tracking variable
        initializeTabs();
        window.lastStep = -1;

        // Define sync steps for progress tracking
        var syncSteps = [
            { name: 'Initializing', percent: 5 },
            { name: 'Authenticating with GitHub', percent: 10 },
            { name: 'Validating repository settings', percent: 20 },
            { name: 'Preparing local files', percent: 30 },
            { name: 'Scanning themes and plugins', percent: 40 },
            { name: 'Creating initial commit', percent: 60, subSteps: [
                'Analyzing file structure',
                'Processing text files',
                'Processing binary files',
                'Creating file blobs',
                'Building file tree',
                'Finalizing commit structure'
            ]},
            { name: 'Pushing to GitHub', percent: 80 },
            { name: 'Finalizing synchronization', percent: 95 },
            { name: 'Complete', percent: 100 }
        ];

        // Current sub-step tracking
        var currentSubStep = 0;
        var subStepStartTime = 0;

        // Progress update function
        function updateProgress(step, detail, subStep, packageInfo) {
            if (step < 0 || step >= syncSteps.length) return;

            var currentStep = syncSteps[step];
            var percentComplete = currentStep.percent;

            // Reset sub-step if we've moved to a new step
            if (step !== window.lastStep) {
                currentSubStep = 0;
                subStepStartTime = Date.now();
                window.lastStep = step;
            }

            // Handle sub-steps specifically for long-running operations
            if (currentStep.subSteps && subStep !== undefined) {
                currentSubStep = subStep;
                // Show progress within the step
                var subStepCount = currentStep.subSteps.length;
                var subPercent = subStep / subStepCount;

                // If we're at step 5 (Creating initial commit), calculate percentage between steps 5 and 6
                if (step === 5) {
                    var nextPercent = syncSteps[6].percent;
                    var stepRange = nextPercent - percentComplete;
                    percentComplete += Math.floor(stepRange * subPercent);

                    // Add the specific sub-step to the message
                    if (subStep < subStepCount) {
                        $('.wp-github-sync-loading-submessage').text(
                            currentStep.name + ' - ' + currentStep.subSteps[subStep]
                        );
                    }
                }

                // Calculate time elapsed
                var elapsedTime = Math.floor((Date.now() - subStepStartTime) / 1000);
                var timeDisplay = '';
                if (elapsedTime > 60) {
                    var minutes = Math.floor(elapsedTime / 60);
                    var seconds = elapsedTime % 60;
                    timeDisplay = ' (' + minutes + 'm ' + seconds + 's)';
                } else if (elapsedTime > 0) {
                    timeDisplay = ' (' + elapsedTime + 's)';
                }

                // Append time elapsed to the message
                if (timeDisplay) {
                    $('.wp-github-sync-loading-submessage').append(timeDisplay);
                }
            } else {
                $('.wp-github-sync-loading-submessage').text(currentStep.name);
            }

            // Handle package-specific progress data
            if (packageInfo) {
                // Create package progress section if it doesn't exist
                if ($('.chunked-sync-progress-detail').length === 0) {
                    $('.wp-github-sync-loading-submessage').after(
                        '<div class="chunked-sync-progress-detail">' +
                        '<p><strong>Package:</strong> <span id="current-package"></span></p>' +
                        '<p><strong>Item:</strong> <span id="current-item"></span></p>' +
                        '<div class="package-progress-bar"><div class="progress-inner"></div></div>' +
                        '</div>'
                    );
                }

                // Update package and item information
                if (packageInfo.current_package) {
                    $('#current-package').text(packageInfo.current_package);
                }

                if (packageInfo.current_item) {
                    $('#current-item').text(packageInfo.current_item);
                }

                // Update progress bar if percentage is available
                if (packageInfo.progress_percentage) {
                    $('.chunked-sync-progress-detail .progress-inner').css('width', packageInfo.progress_percentage + '%');
                }
            }

            // Update progress bar
            $('.wp-github-sync-progress-bar').css('width', percentComplete + '%');
            $('.wp-github-sync-current-step').text(step + 1);

            // Add detail message
            if (detail) {
                var $statusDetail = $('.wp-github-sync-status-detail');

                // Format detail with timestamp
                var now = new Date();
                var timestamp = '[' +
                    now.getHours().toString().padStart(2, '0') + ':' +
                    now.getMinutes().toString().padStart(2, '0') + ':' +
                    now.getSeconds().toString().padStart(2, '0') +
                    '] ';

                $statusDetail.append('<div>' + timestamp + detail + '</div>');

                // Scroll to the bottom if the element exists
                if ($statusDetail.length && $statusDetail[0]) {
                    $statusDetail.scrollTop($statusDetail[0].scrollHeight);
                }
            }
        }

        // Initialize progress tracking
        function initProgress() {
            $('.wp-github-sync-current-step').text('0');
            $('.wp-github-sync-total-steps').text(syncSteps.length);
            $('.wp-github-sync-progress-bar').css('width', '0%');

            // Create the status detail element if it doesn't exist
            if ($('.wp-github-sync-status-detail').length === 0) {
                $('.wp-github-sync-step-indicator').after('<div class="wp-github-sync-status-detail"></div>');
            } else {
                $('.wp-github-sync-status-detail').empty();
            }
        }

        // Check system requirements before starting sync
        function checkSystemRequirements(callback) {
            $('.wp-github-sync-loading-message').text('Checking system requirements...');
            $('.wp-github-sync-loading-submessage').text('Verifying PHP settings and permissions');

            $.ajax({
                url: wpGitHubSync.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wp_github_sync_check_requirements',
                    nonce: wpGitHubSync.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Requirements check passed
                        $('.wp-github-sync-loading-submessage').text('System requirements verified');
                        callback(true);
                    } else {
                        // Requirements check failed
                        $('.wp-github-sync-loading-message').text('System Requirements Error');
                        $('.wp-github-sync-loading-submessage').text(response.data.message);
                        updateProgress(0, 'Error: ' + response.data.message);

                        // Show detailed errors if available
                        if (response.data.details) {
                            var $statusDetail = $('.wp-github-sync-status-detail');
                            $statusDetail.append('<div class="wp-github-sync-requirements-error"><strong>Requirements not met:</strong></div>');
                            $.each(response.data.details, function(i, item) {
                                $statusDetail.append('<div class="wp-github-sync-requirement-item">' +
                                    '<span class="wp-github-sync-requirement-name">' + item.name + ':</span> ' +
                                    '<span class="wp-github-sync-requirement-status">' + item.message + '</span>' +
                                '</div>');
                            });

                            if ($statusDetail.length && $statusDetail[0]) {
                                $statusDetail.scrollTop($statusDetail[0].scrollHeight);
                            }
                        }

                        // Hide overlay after 10 seconds
                        setTimeout(function() {
                            hideOverlay();
                        }, 10000);

                        callback(false);
                    }
                },
                error: function(xhr, status, error) {
                    // AJAX request itself failed
                    $('.wp-github-sync-loading-message').text('Requirements Check Failed');
                    $('.wp-github-sync-loading-submessage').text('Error communicating with server: ' + error);
                    updateProgress(0, 'AJAX Error during requirements check: ' + error);

                    // Hide overlay after 5 seconds
                    setTimeout(function() {
                        hideOverlay();
                    }, 5000);

                    callback(false);
                }
            });
        }

        // Handle the initial sync process
        $('#initial_sync_button').on('click', function() {
            var createNewRepo = $('#create_new_repo').is(':checked');
            var repoName = $('#new_repo_name').val();
            var runInBackground = $('#run_in_background').is(':checked');

            if (createNewRepo && !repoName) {
                alert('Please enter a repository name.');
                return;
            }

            showOverlay();
            $('.wp-github-sync-loading-message').text('Preparing for GitHub Sync...');

            // Initialize progress display
            initProgress();

            // First check system requirements
            checkSystemRequirements(function(requirementsPassed) {
                if (!requirementsPassed) {
                    return; // Stop if requirements check failed
                }

                // Continue with initialization
                updateProgress(0, 'Starting synchronization process...');

                if (createNewRepo) {
                    updateProgress(0, 'Creating new repository: ' + repoName);
                } else {
                    updateProgress(0, 'Connecting to existing repository...');
                }

                if (runInBackground) {
                    updateProgress(0, 'Initiating background sync process...');
                }

                // Setup polling for progress updates
                var lastProgressTime = Date.now();
                var lastProgressStep = -1;
                var lastProgressDetail = '';
                var noProgressCount = 0;
                var maxNoProgressCount = 10; // About 15 seconds without progress
                var progressCheck;
                // Always use the standard nonce for progress checking since the server handler expects it
                var progressNonce = wpGitHubSync.nonce;

                progressCheck = setInterval(function() {
                    // Check if we haven't seen progress for a while
                    var currentTime = Date.now();
                    if (lastProgressStep >= 0 && (currentTime - lastProgressTime) > 30000) {
                        // It's been more than 30 seconds without progress at the same step
                        if (noProgressCount >= maxNoProgressCount) {
                            // Add a warning to the status detail
                            updateProgress(
                                lastProgressStep,
                                "⚠️ Operation taking longer than expected. This doesn't mean it failed, just that it's working on a larger repository or slower connection.",
                                currentSubStep
                            );

                            // Reset counter so we don't spam warnings
                            noProgressCount = 0;
                        }
                    }

                    $.ajax({
                        url: wpGitHubSync.ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'wp_github_sync_check_progress',
                            nonce: progressNonce
                        },
                        success: function(progressData) {
                            if (progressData.success && progressData.data.step !== undefined) {
                                // Track progress for timeout detection
                                var newStep = progressData.data.step;
                                var newDetail = progressData.data.detail || '';
                                var subStep = progressData.data.subStep;

                                // Check if we're making progress
                                if (newStep !== lastProgressStep || newDetail !== lastProgressDetail) {
                                    lastProgressTime = currentTime;
                                    lastProgressStep = newStep;
                                    lastProgressDetail = newDetail;
                                    noProgressCount = 0;
                                } else {
                                    noProgressCount++;
                                }

                                // Extract package-specific information if available
                                var packageInfo = null;
                                if (progressData.data.current_package ||
                                    progressData.data.current_item ||
                                    progressData.data.progress_percentage) {

                                    packageInfo = {
                                        current_package: progressData.data.current_package,
                                        current_item: progressData.data.current_item,
                                        progress_percentage: progressData.data.progress_percentage
                                    };
                                }

                                updateProgress(newStep, newDetail, subStep, packageInfo);

                                // Auto advance sub-steps if we're stuck at the "Creating initial commit" stage
                                if (newStep === 5 && subStep === undefined && noProgressCount > 2) {
                                    // Start cycling through sub-steps to show activity
                                    var fakeSubStep = Math.floor((noProgressCount - 2) / 2) % syncSteps[5].subSteps.length;
                                    updateProgress(5, null, fakeSubStep);
                                }

                                // If progress data indicates completion or failure for background process
                                if (runInBackground && (progressData.data.status === 'complete' || progressData.data.status === 'failed')) {
                                    clearInterval(progressCheck);

                                    if (progressData.data.status === 'complete') {
                                        updateProgress(syncSteps.length - 1, 'Sync completed successfully!');
                                        $('.wp-github-sync-loading-message').text('Success!');
                                        $('.wp-github-sync-loading-submessage').text(progressData.data.message || 'Synchronization completed successfully.');

                                        // Redirect to dashboard after 2 seconds
                                        setTimeout(function() {
                                            window.location.href = wpGitHubSync.adminUrl + '?page=wp-github-sync';
                                        }, 2000);
                                    } else {
                                        updateProgress(0, 'Error: ' + (progressData.data.message || 'Unknown error'));
                                        $('.wp-github-sync-loading-message').text('Error');
                                        $('.wp-github-sync-loading-submessage').text(progressData.data.message || 'An error occurred');

                                        // Hide overlay after 3 seconds
                                        setTimeout(function() {
                                            hideOverlay();
                                        }, 3000);
                                    }
                                }
                            }
                        }
                    });
                }, 1500);

                $.ajax({
                    url: wpGitHubSync.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: runInBackground ? 'wp_github_sync_background_initial_sync' : 'wp_github_sync_initial_sync',
                        create_new_repo: createNewRepo ? 1 : 0,
                        repo_name: repoName,
                        run_in_background: runInBackground ? 1 : 0,
                        nonce: wpGitHubSync.initialSyncNonce // Using the specific nonce for initial sync
                    },
                    success: function(response) {
                        if (!runInBackground) {
                            clearInterval(progressCheck);
                        }

                        if (response.success) {
                            if (runInBackground) {
                                updateProgress(1, 'Background sync process started successfully.');
                                $('.wp-github-sync-loading-message').text('Background Sync Started');
                                $('.wp-github-sync-loading-submessage').text(response.data.message || 'Background synchronization is now running.');
                                // For background mode, keep the overlay open to allow progress monitoring
                            } else {
                                updateProgress(syncSteps.length - 1, 'Sync completed successfully!');
                                $('.wp-github-sync-loading-message').text('Success!');
                                $('.wp-github-sync-loading-submessage').text(response.data.message);

                                // Redirect to dashboard after 2 seconds
                                setTimeout(function() {
                                    window.location.href = wpGitHubSync.adminUrl + '?page=wp-github-sync';
                                }, 2000);
                            }
                        } else {
                            // Handle error response
                            clearInterval(progressCheck);  // Stop progress checking on error
                            updateProgress(0, 'Error: ' + (response.data && response.data.message ? response.data.message : 'Unknown error'));
                            $('.wp-github-sync-loading-message').text('Error');
                            $('.wp-github-sync-loading-submessage').text(response.data && response.data.message ? response.data.message : 'An error occurred');

                            // Hide overlay after 3 seconds
                            setTimeout(function() {
                                hideOverlay();
                            }, 3000);
                        }
                    },
                    error: function(xhr, status, error) {
                        clearInterval(progressCheck);
                        updateProgress(0, 'AJAX Error: ' + error);
                        $('.wp-github-sync-loading-message').text('Error');
                        $('.wp-github-sync-loading-submessage').text('Communication error with server: ' + error);

                        // Add detailed error to status detail for better diagnostics
                        var $statusDetail = $('.wp-github-sync-status-detail');
                        $statusDetail.append('<div class="wp-github-sync-error-detail"><strong>Error Details:</strong></div>');
                        $statusDetail.append('<div>Status: ' + status + '</div>');
                        $statusDetail.append('<div>Error: ' + error + '</div>');

                        // Log detailed diagnostic info to console
                        console.error("AJAX Error:", status, error);
                        console.error("HTTP Status:", xhr.status);
                        console.error("Status Text:", xhr.statusText);
                        console.error("Ready State:", xhr.readyState);

                        // Log token exists but not the actual token (security)
                        if (typeof wpGitHubSync !== 'undefined') {
                            console.log("Configuration: Nonce exists:", !!wpGitHubSync.initialSyncNonce);
                            console.log("Authentication set:", !!wpGitHubSync.authSet);
                        }

                        if (xhr.responseText) {
                            console.error("Response:", xhr.responseText);
                            $statusDetail.append('<div><strong>Response:</strong></div>');

                            var responsePreview = xhr.responseText;
                            if (responsePreview.length > 500) {
                                responsePreview = responsePreview.substring(0, 500) + '... (truncated)';
                            }

                            // Display response in a readable format
                            $statusDetail.append('<div class="wp-github-sync-error-response">' + responsePreview.replace(/</g, '<').replace(/>/g, '>').replace(/\n/g, '<br>') + '</div>');

                            // Try to parse response for more details
                            try {
                                var responseObj = JSON.parse(xhr.responseText);
                                if (responseObj && responseObj.data && responseObj.data.message) {
                                    $('.wp-github-sync-loading-submessage').text(responseObj.data.message);
                                    console.error("Parsed error:", responseObj.data.message);
                                    $statusDetail.append('<div><strong>Detailed Error:</strong> ' + responseObj.data.message + '</div>');

                                    // Look for error codes
                                    if (responseObj.data.code) {
                                        $statusDetail.append('<div><strong>Error Code:</strong> ' + responseObj.data.code + '</div>');
                                        console.error("Error Code:", responseObj.data.code);
                                    }
                                } else {
                                    // Look for WordPress critical error
                                    if (xhr.responseText.indexOf('<p>There has been a critical error') !== -1) {
                                        $statusDetail.append('<div><strong>WordPress Critical Error</strong> detected in response.</div>');
                                        $('.wp-github-sync-loading-submessage').text('WordPress encountered a critical error. Check server logs for details.');
                                    }
                                }
                            } catch (e) {
                                console.error("Error parsing response:", e);
                                $statusDetail.append('<div>Could not parse JSON response: ' + e.message + '</div>');
                            }
                        } else {
                            $statusDetail.append('<div>No response body received</div>');
                        }

                        // Check for network-level errors
                        if (error === 'timeout') {
                            $statusDetail.append('<div><strong>Connection timeout</strong> - Server took too long to respond</div>');
                        } else if (xhr.status === 0) {
                            $statusDetail.append('<div><strong>Network Error</strong> - Could not connect to server. Possible CORS issue or server unreachable.</div>');
                        }

                        // Provide debugging guidance
                        $statusDetail.append('<div class="wp-github-sync-error-help"><strong>Troubleshooting:</strong></div>');
                        $statusDetail.append('<div>1. Check PHP error logs for detailed server errors</div>');
                        $statusDetail.append('<div>2. Check GitHub token permissions (need Content read/write for fine-grained tokens)</div>');
                        $statusDetail.append('<div>3. Verify GitHub repository settings are correct</div>');
                        $statusDetail.append('<div>4. Ensure WordPress has proper file system permissions</div>');

                        // Scroll to see error details
                        if ($statusDetail.length && $statusDetail[0]) {
                            $statusDetail.scrollTop($statusDetail[0].scrollHeight);
                        }

                        // Keep overlay visible longer to allow reading error details
                        setTimeout(function() {
                            hideOverlay();
                        }, 10000); // 10 seconds to read error details
                    }
                });
            });
        });

        // Handle repository URL toggle
        $('.wp-github-sync-repo-toggle').on('change', function() {
            var isNewRepo = $(this).val() === 'new';
            $('.wp-github-sync-repo-existing-fields').toggle(!isNewRepo);
            $('.wp-github-sync-repo-new-fields').toggle(isNewRepo);
        });

        // Handle branch validation
        $('.wp-github-sync-branch-field').on('input', function() {
            // Basic branch name validation (letters, numbers, dashes, underscores)
            var branchValue = $(this).val();
            var isValid = /^[a-zA-Z0-9_.-]+$/.test(branchValue);

            if (isValid) {
                $(this).removeClass('wp-github-sync-invalid');
            } else {
                $(this).addClass('wp-github-sync-invalid');
            }
        });

        // Handle test connection button
        $('#test_connection_button').on('click', function() {
            var $button = $(this);
            var originalText = $button.text();

            // Get token from the form fields
            var authMethod = $('input[name="wp_github_sync_auth_method"]:checked').val();
            var token = '';

            if (authMethod === 'pat') {
                token = $('#access_token').val();
            } else if (authMethod === 'oauth') {
                token = $('#oauth_token').val();
            }

            if (!token) {
                alert('Please enter a GitHub access token first.');
                return;
            }

            // Defensively check if the function exists before calling
            if (window.wpGitHubSyncAdmin && typeof window.wpGitHubSyncAdmin.showOverlay === 'function') {
                window.wpGitHubSyncAdmin.showOverlay('Testing Connection...');
            } else {
                console.error('WP GitHub Sync Error: showOverlay function not found.');
                // Optionally provide fallback feedback if overlay isn't available
                alert('Testing connection...');
            }
            $button.text('Testing...').prop('disabled', true);

            $.ajax({
                url: wpGitHubSync.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wp_github_sync_test_connection',
                    token: token,
                    auth_method: authMethod,
                    repo_url: $('input[name="wp_github_sync_repository"]').val(), // Add repo URL
                    nonce: wpGitHubSync.nonce
                },
                success: function(response) {
                    $button.prop('disabled', false).text(originalText);

                    if (response.success) {
                        // Use global error/success handling if available, or alert
                        window.wpGitHubSyncAdmin.hideOverlay(); // Hide overlay on completion
                        alert('Connection successful! ' + response.data.message);
                    } else {
                        window.wpGitHubSyncAdmin.hideOverlay(); // Hide overlay on completion
                        alert('Connection failed: ' + response.data.message);
                    }
                },
                error: function(xhr, status, error) {
                    // Use the global error handler
                    window.wpGitHubSyncAdmin.handleGlobalAjaxError(xhr, status, error, 'Test Connection');
                    $button.prop('disabled', false).text(originalText);
                    // No separate alert needed as global handler shows notice
                }
            });
        });

        // Handle authentication method toggle
        $('input[name="wp_github_sync_auth_method"]').on('change', function() {
            var method = $(this).val();
            $('.wp-github-sync-auth-fields').hide();
            $('#wp_github_sync_' + method + '_fields').show();
        });

        // Trigger change to initialize visibility
        $('input[name="wp_github_sync_auth_method"]:checked').trigger('change');

        // Initialize tooltips
        $('.wp-github-sync-tooltip-trigger').hover(
            function() {
                $(this).siblings('.wp-github-sync-tooltip').fadeIn(200);
            },
            function() {
                $(this).siblings('.wp-github-sync-tooltip').fadeOut(200);
            }
        );

        // Initialize code copy buttons
        $('.wp-github-sync-code-copy').on('click', function() {
            var $code = $(this).siblings('code');
            var text = $code.text();

            // Create a temporary element to copy from
            var $temp = $('<textarea>');
            $('body').append($temp);
            $temp.val(text).select();

            try {
                // Copy to clipboard
                document.execCommand('copy');
                // Show success message
                var $button = $(this);
                var originalText = $button.text();
                $button.text('Copied!');

                setTimeout(function() {
                    $button.text(originalText);
                }, 1500);
            } catch (err) {
                console.error('Could not copy text:', err);
                alert('Could not copy text. Please try manually selecting and copying.');
            }

            $temp.remove();
        });

        // Log ready status
        console.log('WP GitHub Sync: Admin scripts initialized');
    });
})(jQuery);
