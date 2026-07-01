jQuery(document).ready(function ($) {
    // Current State
    var state = {
        page: 1,
        per_page: 15,
        search: '',
        filter: 'missing',
        tab: 'tab-scanner',
        selected: [],
        items: [], // List of items on current page
        diagnostics_page: 1,
        diagnostics_search: ''
    };

    // Ensure the AJAX URL scheme matches the current page protocol to prevent SSL/Redirect POST drops
    if (window.location.protocol === 'https:' && ACO_Media_Recovery_Settings.ajax_url.indexOf('http:') === 0) {
        ACO_Media_Recovery_Settings.ajax_url = ACO_Media_Recovery_Settings.ajax_url.replace('http:', 'https:');
    }

    // Initialize UI
    init();

    function init() {
        // Tab switching (3-tab routing)
        $('.acomr-tab-btn').on('click', function () {
            var target = $(this).data('tab');
            $('.acomr-tab-btn').removeClass('active');
            $('.acomr-tab-content').removeClass('acomr-tab-active');
            
            $(this).addClass('active');
            $('#' + target).addClass('acomr-tab-active');
            
            state.tab = target;
        });

        // Check if Offload Pro plugin is active
        if (parseInt(ACO_Media_Recovery_Settings.is_pro_active) !== 1) {
            $('input[name="download_method"][value="offload"]').prop('disabled', true);
            $('input[name="download_method"][value="http"]').prop('checked', true);
            logToConsole('[WARNING] Offload Media Cloud Storage Pro plugin is not active. Offload Client downloads are disabled.', 'warning');
        }

        // Toggle CDN URL visibility based on download method
        $('input[name="download_method"]').on('change', function() {
            if ($(this).val() === 'http') {
                $('#cdn-url-group').slideDown();
            } else {
                $('#cdn-url-group').slideUp();
            }
        });

        // Hide CDN group if offload method is active on page load
        if ($('input[name="download_method"]:checked').val() === 'offload') {
            $('#cdn-url-group').hide();
        }

        // Database scan trigger
        $('#btn-scan').on('click', function () {
            state.page = 1;
            loadMediaList();
        });

        $('#scanner-filter').on('change', function () {
            state.filter = $(this).val();
            state.page = 1;
            loadMediaList();
        });

        // Search debouncing
        var searchTimeout = null;
        $('#scanner-search').on('keyup', function () {
            clearTimeout(searchTimeout);
            var query = $(this).val();
            searchTimeout = setTimeout(function () {
                state.search = query;
                state.page = 1;
                loadMediaList();
            }, 500);
        });

        // Checkbox events
        $('#check-all').on('change', function () {
            var checked = $(this).prop('checked');
            $('.acomr-item-checkbox').prop('checked', checked);
            updateSelection();
        });

        $(document).on('change', '.acomr-item-checkbox', function () {
            updateSelection();
        });

        // Single page pagination events
        $(document).on('click', '.acomr-page-link', function () {
            if ($(this).hasClass('active') || $(this).prop('disabled')) {
                return;
            }
            state.page = parseInt($(this).data('page'));
            loadMediaList();
        });

        // Recovery triggers
        $('#btn-recover-selected').on('click', function () {
            if (state.selected.length === 0) {
                alert(ACO_Media_Recovery_Settings.labels.no_selection);
                return;
            }
            if (confirm(ACO_Media_Recovery_Settings.labels.confirm_bulk)) {
                startBatchRecovery(state.selected);
            }
        });

        $('#btn-recover-all').on('click', function () {
            if (confirm('Are you sure you want to recover ALL matching files from the database? This might take a while.')) {
                fetchAllMatchingIdsAndRecover();
            }
        });

        // Manual JSON Import trigger
        $('#btn-import-json').on('click', function () {
            var jsonText = $('#json_input').val().trim();
            if (!jsonText) {
                alert('Please paste valid JSON mappings.');
                return;
            }
            startJsonImport(jsonText);
        });

        // Save settings trigger
        $('#btn-save-settings').on('click', function () {
            var btn = $(this);
            btn.prop('disabled', true).text('Saving...');
            
            var method = $('input[name="download_method"]:checked').val();
            var autoThumbs = $('#auto_thumbs').prop('checked') ? '1' : '0';
            var dryRun = $('#dry_run').prop('checked') ? '1' : '0';
            var customBaseUrl = $('#custom_base_url').val().trim();
            var customLocalDir = $('#custom_local_dir').val().trim();
            var smartOverlap = $('#smart_overlap').prop('checked') ? '1' : '0';
            var replaceExisting = $('#replace_existing').prop('checked') ? '1' : '0';

            $.ajax({
                url: ACO_Media_Recovery_Settings.ajax_url,
                method: 'POST',
                data: {
                    action: 'aco_media_recovery_save_settings',
                    security: ACO_Media_Recovery_Settings.nonce,
                    download_method: method,
                    auto_thumbs: autoThumbs,
                    dry_run: dryRun,
                    custom_base_url: customBaseUrl,
                    custom_local_dir: customLocalDir,
                    smart_overlap: smartOverlap,
                    replace_existing: replaceExisting
                },
                success: function (res) {
                    if (res && res.success && res.data) {
                        alert(res.data.message);
                    } else {
                        var errMsg = 'Unknown error occurred.';
                        if (res === -1 || res === '-1') {
                            errMsg = 'Security check failed / Nonce expired. Please refresh the page and try again.';
                        } else if (res === 0 || res === '0') {
                            errMsg = 'AJAX action not registered or failed.';
                        } else if (res && res.data && res.data.message) {
                            errMsg = res.data.message;
                        }
                        alert('Error: ' + errMsg);
                    }
                },
                error: function (xhr, status, error) {
                    var detail = '';
                    if (xhr.status === 403) {
                        detail = ' (403 Forbidden - Possibly blocked by Web Application Firewall or security plugin)';
                    } else if (xhr.status === 404) {
                        detail = ' (404 Not Found)';
                    } else if (xhr.status) {
                        detail = ' (HTTP ' + xhr.status + ')';
                    }
                    alert('Error saving settings: Request failed' + detail + '.');
                },
                complete: function () {
                    btn.prop('disabled', false).text('Save Settings');
                }
            });
        });

        // Clear console
        $('#btn-clear-console').on('click', function () {
            $('#acomr-console').empty();
        });

        // Toggle masked credentials reveal
        $(document).on('click', '.acomr-reveal-btn', function () {
            var container = $(this).siblings('.acomr-masked-value');
            var realVal = container.data('real');
            var maskedVal = container.data('masked');
            if (container.text() === maskedVal) {
                container.text(realVal);
                $(this).html('&#128064;'); // Switch to eye-slash icon
                $(this).attr('title', 'Hide Key');
            } else {
                container.text(maskedVal);
                $(this).html('&#128065;'); // Switch back to eye icon
                $(this).attr('title', 'Reveal Key');
            }
        });

        // Tab click event: if diagnostics tab is clicked, fetch diagnostics list
        $('.acomr-tab-btn').on('click', function () {
            var target = $(this).data('tab');
            if (target === 'tab-diagnostics') {
                loadDiagnosticsList();
            }
        });

        // Run health checks button
        $('#btn-run-health-checks').on('click', function() {
            runHealthChecks();
        });

        // Diagnostics refresh list
        $('#btn-refresh-diagnostics').on('click', function() {
            state.diagnostics_page = 1;
            loadDiagnosticsList();
        });

        // Diagnostics search input
        var diagSearchTimeout = null;
        $('#diagnostics-search').on('keyup', function() {
            clearTimeout(diagSearchTimeout);
            var query = $(this).val();
            diagSearchTimeout = setTimeout(function() {
                state.diagnostics_search = query;
                state.diagnostics_page = 1;
                loadDiagnosticsList();
            }, 500);
        });

        // Pagination links for diagnostics
        $(document).on('click', '.acomr-diag-page-link', function () {
            if ($(this).hasClass('active') || $(this).prop('disabled')) {
                return;
            }
            state.diagnostics_page = parseInt($(this).data('page'));
            loadDiagnosticsList();
        });

        // Expand / collapse details
        $(document).on('click', '.btn-view-diagnostics', function() {
            var btn = $(this);
            var id = btn.data('id');
            var mainRow = btn.closest('tr');
            var detailsRowId = 'diagnostics-details-' + id;
            var detailsRow = $('#' + detailsRowId);

            if (detailsRow.length > 0) {
                detailsRow.toggle();
                if (detailsRow.is(':visible')) {
                    btn.text('Hide Details');
                } else {
                    btn.text('View Details');
                }
            } else {
                btn.prop('disabled', true).text('Loading...');
                // Create a temporary row
                var tempRow = $('<tr class="acomr-details-row" id="' + detailsRowId + '">' +
                                '<td colspan="5" style="padding: 15px; background: #fafafa; border-bottom: 1px solid #dcdcde;">' +
                                '<div class="acomr-details-loading" style="text-align: center; color: #646970; font-style: italic;">' +
                                'Loading comprehensive diagnostic report...' +
                                '</div>' +
                                '</td>' +
                                '</tr>');
                mainRow.after(tempRow);
                loadAttachmentDiagnostics(id, tempRow, btn);
            }
        });

        // Initial Load
        loadMediaList();
    }

    /**
     * Fetch media details from WordPress.
     */
    function loadMediaList() {
        var tbody = $('#scanner-table-body');
        tbody.html('<tr><td colspan="6" class="acomr-table-placeholder">Scanning database attachments...</td></tr>');
        $('#check-all').prop('checked', false);
        state.selected = [];

        $.ajax({
            url: ACO_Media_Recovery_Settings.ajax_url,
            method: 'POST',
            data: {
                action: 'aco_media_recovery_fetch_filenames',
                security: ACO_Media_Recovery_Settings.nonce,
                page: state.page,
                per_page: state.per_page,
                search: state.search,
                filter: state.filter
            },
            success: function (res) {
                if (res && res.success && res.data) {
                    renderTable(res.data.items);
                    renderPagination(res.data.pages, res.data.current, res.data.total_count);
                    updateStats(res.data.stats);
                } else {
                    var errMsg = 'Unknown error occurred.';
                    if (res === -1 || res === '-1') {
                        errMsg = 'Security check failed / Nonce expired. Please refresh the page.';
                    } else if (res === 0 || res === '0') {
                        errMsg = 'AJAX action not registered.';
                    } else if (res && res.data && res.data.message) {
                        errMsg = res.data.message;
                    }
                    tbody.html('<tr><td colspan="6" class="acomr-table-placeholder log-error">Error: ' + errMsg + '</td></tr>');
                }
            },
            error: function (xhr, status, error) {
                var detail = '';
                if (xhr.status === 403) {
                    detail = ' (403 Forbidden - Possibly blocked by Web Application Firewall)';
                } else if (xhr.status) {
                    detail = ' (HTTP ' + xhr.status + ')';
                }
                tbody.html('<tr><td colspan="6" class="acomr-table-placeholder log-error">Error making AJAX request' + detail + '.</td></tr>');
            }
        });
    }

    /**
     * Render items in the table.
     */
    function renderTable(items) {
        var tbody = $('#scanner-table-body');
        state.items = items;

        if (items.length === 0) {
            tbody.html('<tr><td colspan="6" class="acomr-table-placeholder">No matching attachments found in database.</td></tr>');
            return;
        }

        var html = '';
        items.forEach(function (item) {
            var offloadBadge = item.is_offloaded 
                ? '<span class="acomr-badge acomr-badge-info">Offloaded</span>' 
                : '<span class="acomr-badge acomr-badge-muted">Local</span>';
                
            var localBadge = item.exists_locally 
                ? '<span class="acomr-badge acomr-badge-success">Exists</span>' 
                : (item.is_deleted 
                    ? '<span class="acomr-badge acomr-badge-danger">Deleted</span>' 
                    : '<span class="acomr-badge acomr-badge-warning">Missing</span>');

            var thumbBadge = item.thumb_count > 0
                ? '<span class="acomr-badge acomr-badge-count">' + item.thumb_count + '</span>'
                : '<span class="acomr-badge acomr-badge-muted">0</span>';

            html += '<tr>';
            html += '<td><input type="checkbox" class="acomr-item-checkbox" value="' + item.id + '"></td>';
            html += '<td>' + item.id + '</td>';
            html += '<td><strong>' + item.title + '</strong><br><code style="font-size: 11px; color:#646970;">' + item.filename + '</code></td>';
            html += '<td>' + offloadBadge + '</td>';
            html += '<td>' + localBadge + '</td>';
            html += '<td>' + thumbBadge + '</td>';
            html += '</tr>';
        });

        tbody.html(html);
    }

    /**
     * Render pagination links.
     */
    function renderPagination(totalPages, current, totalCount) {
        var pagination = $('#scanner-pagination');
        pagination.empty();

        if (totalCount === 0) return;

        var startEntry = (current - 1) * state.per_page + 1;
        var endEntry = Math.min(current * state.per_page, totalCount);

        var html = '<span class="acomr-pagination-info">Showing ' + startEntry + ' to ' + endEntry + ' of ' + totalCount + ' attachments</span>';

        html += '<button class="acomr-page-link" data-page="1" ' + (current === 1 ? 'disabled' : '') + '>&laquo;</button>';
        html += '<button class="acomr-page-link" data-page="' + (current - 1) + '" ' + (current === 1 ? 'disabled' : '') + '>&lsaquo;</button>';

        // Pages around current page
        var startPage = Math.max(1, current - 2);
        var endPage = Math.min(totalPages, current + 2);

        for (var i = startPage; i <= endPage; i++) {
            html += '<button class="acomr-page-link ' + (i === current ? 'active' : '') + '" data-page="' + i + '">' + i + '</button>';
        }

        html += '<button class="acomr-page-link" data-page="' + (current + 1) + '" ' + (current === totalPages ? 'disabled' : '') + '>&rsaquo;</button>';
        html += '<button class="acomr-page-link" data-page="' + totalPages + '" ' + (current === totalPages ? 'disabled' : '') + '>&raquo;</button>';

        pagination.html(html);
    }

    /**
     * Update select all and selected items state.
     */
    function updateSelection() {
        var selected = [];
        $('.acomr-item-checkbox:checked').each(function () {
            selected.push(parseInt($(this).val()));
        });
        state.selected = selected;
    }

    /**
     * Update Stats Row numbers.
     */
    function updateStats(stats) {
        if (!stats) return;
        $('#stat-total .acomr-stat-value').text(stats.total.toLocaleString());
        $('#stat-offloaded .acomr-stat-value').text(stats.offloaded.toLocaleString());
        
        var deletedCount = stats.deleted;
        $('#stat-deleted .acomr-stat-value').text(deletedCount.toLocaleString());
        
        var missingCount = stats.missing;
        $('#stat-missing .acomr-stat-value').text(missingCount.toLocaleString());
    }

    /**
     * Fetch ALL matching attachment IDs first, then process recovery in chunks.
     */
    function fetchAllMatchingIdsAndRecover() {
        $('#btn-recover-all').prop('disabled', true);
        
        logToConsole('[START] Querying database for all matching attachment IDs...', 'info');

        $.ajax({
            url: ACO_Media_Recovery_Settings.ajax_url,
            method: 'POST',
            data: {
                action: 'aco_media_recovery_fetch_filenames',
                security: ACO_Media_Recovery_Settings.nonce,
                page: 1,
                per_page: -1, // Skip limit/offset in SQL
                search: state.search,
                filter: state.filter
            },
            success: function (res) {
                if (res && res.success && res.data && res.data.items) {
                    var ids = res.data.items.map(function(item) { return item.id; });
                    if (ids.length === 0) {
                        logToConsole('[ERROR] No attachments found matching current criteria.', 'error');
                        $('#btn-recover-all').prop('disabled', false);
                    } else {
                        logToConsole('[FOUND] ' + ids.length + ' matching attachments to process.', 'info');
                        startBatchRecovery(ids, function() {
                            $('#btn-recover-all').prop('disabled', false);
                        });
                    }
                } else {
                    var errMsg = 'Unknown error occurred.';
                    if (res === -1 || res === '-1') {
                        errMsg = 'Security check failed / Nonce expired. Please refresh the page.';
                    } else if (res === 0 || res === '0') {
                        errMsg = 'AJAX action not registered.';
                    } else if (res && res.data && res.data.message) {
                        errMsg = res.data.message;
                    }
                    logToConsole('[ERROR] Failed to query matching IDs: ' + errMsg, 'error');
                    $('#btn-recover-all').prop('disabled', false);
                }
            },
            error: function (xhr, status, error) {
                var detail = '';
                if (xhr.status === 403) {
                    detail = ' (403 Forbidden - Possibly blocked by Web Application Firewall)';
                } else if (xhr.status) {
                    detail = ' (HTTP ' + xhr.status + ')';
                }
                logToConsole('[ERROR] Request error while querying matching IDs' + detail + '.', 'error');
                $('#btn-recover-all').prop('disabled', false);
            }
        });
    }

    /**
     * Start AJAX-based chunked batch recovery.
     */
    function startBatchRecovery(ids, callback) {
        $('#console-section').slideDown();
        $('#btn-recover-selected').prop('disabled', true);
        
        var method = $('input[name="download_method"]:checked').val();
        var autoThumbs = $('#auto_thumbs').prop('checked') ? '1' : '0';
        var dryRun = $('#dry_run').prop('checked') ? '1' : '0';
        var customBaseUrl = $('#custom_base_url').val().trim();
        var customLocalDir = $('#custom_local_dir').val().trim();
        var smartOverlap = $('#smart_overlap').prop('checked') ? '1' : '0';
        var replaceExisting = $('#replace_existing').prop('checked') ? '1' : '0';

        var total = ids.length;
        var processed = 0;
        var chunkSize = 5; // Process 5 items per HTTP request
        var queue = [...ids];

        updateProgressBar(0, total);
        logToConsole('[START] Initiated recovery batch. Mode: ' + method + '. Total: ' + total + ' files.', 'title');
        if (dryRun === '1') {
            logToConsole('[DRY RUN] Simulation is enabled. Disk writes will be bypassed.', 'warning');
        }

        function processNextChunk() {
            if (queue.length === 0) {
                logToConsole('[COMPLETE] Batch recovery finished successfully!', 'success');
                $('#btn-recover-selected').prop('disabled', false);
                if (callback) callback();
                loadMediaList(); // Refresh table view and stats
                return;
            }

            var chunk = queue.splice(0, chunkSize);
            
            $.ajax({
                url: ACO_Media_Recovery_Settings.ajax_url,
                method: 'POST',
                data: {
                    action: 'aco_media_recovery_recover_files',
                    security: ACO_Media_Recovery_Settings.nonce,
                    ids: chunk,
                    method: method,
                    auto_thumbs: autoThumbs,
                    dry_run: dryRun,
                    custom_base_url: customBaseUrl,
                    custom_local_dir: customLocalDir,
                    smart_overlap: smartOverlap,
                    replace_existing: replaceExisting
                },
                success: function (res) {
                    if (res && res.success && res.data && res.data.logs) {
                        res.data.logs.forEach(function (log) {
                            var statusType = log.status === 'success' ? 'success' : 'error';
                            logToConsole(log.message, statusType);
                            
                            // Log thumbnail results
                            if (log.thumbnails && log.thumbnails.length > 0) {
                                log.thumbnails.forEach(function (tLog) {
                                    var tStatus = tLog.indexOf('Failed') !== -1 ? 'warning' : 'muted';
                                    logToConsole('  => Thumbnail ' + tLog, tStatus);
                                });
                            }
                        });
                        processed += chunk.length;
                        updateProgressBar(processed, total);
                    } else {
                        var errMsg = 'Unknown error occurred.';
                        if (res === -1 || res === '-1') {
                            errMsg = 'Security check failed / Nonce expired. Please refresh the page.';
                        } else if (res === 0 || res === '0') {
                            errMsg = 'AJAX action not registered.';
                        } else if (res && res.data && res.data.message) {
                            errMsg = res.data.message;
                        }
                        logToConsole('[ERROR] Failed to process batch chunk: ' + errMsg, 'error');
                        processed += chunk.length;
                        updateProgressBar(processed, total);
                    }
                    // Continue to next chunk
                    processNextChunk();
                },
                error: function (xhr, status, error) {
                    var detail = '';
                    if (xhr.status === 403) {
                        detail = ' (403 Forbidden - Possibly blocked by Web Application Firewall)';
                    } else if (xhr.status) {
                        detail = ' (HTTP ' + xhr.status + ')';
                    }
                    logToConsole('[ERROR] HTTP Request failed during chunk processing' + detail + '.', 'error');
                    processed += chunk.length;
                    updateProgressBar(processed, total);
                    processNextChunk();
                }
            });
        }

        // Start recursion
        processNextChunk();
    }

    /**
     * Start AJAX-based manual JSON mappings import.
     */
    function startJsonImport(jsonText) {
        $('#console-section').slideDown();
        $('#btn-import-json').prop('disabled', true);

        var method = $('input[name="download_method"]:checked').val();
        var autoThumbs = $('#auto_thumbs').prop('checked') ? '1' : '0';
        var dryRun = $('#dry_run').prop('checked') ? '1' : '0';
        var customLocalDir = $('#custom_local_dir').val().trim();
        var replaceExisting = $('#replace_existing').prop('checked') ? '1' : '0';

        logToConsole('[START] Initiated JSON Custom Import...', 'title');
        if (dryRun === '1') {
            logToConsole('[DRY RUN] Simulation is enabled. Disk writes will be bypassed.', 'warning');
        }

        $.ajax({
            url: ACO_Media_Recovery_Settings.ajax_url,
            method: 'POST',
            data: {
                action: 'aco_media_recovery_manual_import',
                security: ACO_Media_Recovery_Settings.nonce,
                json_input: jsonText,
                method: method,
                auto_thumbs: autoThumbs,
                dry_run: dryRun,
                custom_local_dir: customLocalDir,
                replace_existing: replaceExisting
            },
            success: function (res) {
                if (res && res.success && res.data && res.data.logs) {
                    res.data.logs.forEach(function (log) {
                        var statusType = log.status === 'success' ? 'success' : 'error';
                        logToConsole(log.message, statusType);
                        
                        if (log.thumbnails && log.thumbnails.length > 0) {
                            log.thumbnails.forEach(function (tLog) {
                                var tStatus = tLog.indexOf('Failed') !== -1 ? 'warning' : 'muted';
                                logToConsole('  => Thumbnail ' + tLog, tStatus);
                            });
                        }
                    });
                    logToConsole('[COMPLETE] JSON custom mappings import finished.', 'success');
                } else {
                    var errMsg = 'Unknown error occurred.';
                    if (res === -1 || res === '-1') {
                        errMsg = 'Security check failed / Nonce expired. Please refresh the page.';
                    } else if (res === 0 || res === '0') {
                        errMsg = 'AJAX action not registered.';
                    } else if (res && res.data && res.data.message) {
                        errMsg = res.data.message;
                    }
                    logToConsole('[ERROR] JSON Import Failed: ' + errMsg, 'error');
                }
                $('#btn-import-json').prop('disabled', false);
                loadMediaList();
            },
            error: function (xhr, status, error) {
                var detail = '';
                if (xhr.status === 403) {
                    detail = ' (403 Forbidden - Possibly blocked by Web Application Firewall)';
                } else if (xhr.status) {
                    detail = ' (HTTP ' + xhr.status + ')';
                }
                logToConsole('[ERROR] Request error during JSON import' + detail + '.', 'error');
                $('#btn-import-json').prop('disabled', false);
            }
        });
    }

    /**
     * Run the Proactive System Health Audit.
     */
    function runHealthChecks() {
        var listContainer = $('#health-checks-list');
        listContainer.html('<div class="acomr-table-placeholder">Running diagnostic health audit... Please wait.</div>');
        $('#btn-run-health-checks').prop('disabled', true).text('Running...');

        $.ajax({
            url: ACO_Media_Recovery_Settings.ajax_url,
            method: 'POST',
            data: {
                action: 'aco_media_recovery_run_health_checks',
                security: ACO_Media_Recovery_Settings.nonce
            },
            success: function(res) {
                if (res && res.success && res.data && res.data.checks) {
                    var html = '<div class="acomr-health-checks-grid" style="display: grid; grid-template-columns: 1fr; gap: 15px;">';
                    res.data.checks.forEach(function(check) {
                        var statusClass = 'acomr-check-' + check.status;
                        var icon = '&#9432;'; // Info icon
                        if (check.status === 'success') {
                            icon = '&#9989;'; // Green check
                        } else if (check.status === 'critical') {
                            icon = '&#10060;'; // Red cross
                        } else if (check.status === 'warning') {
                            icon = '&#9888;'; // Warning sign
                        }

                        var borderLeftColor = '#2271b1';
                        if (check.status === 'critical') borderLeftColor = '#dc3232';
                        else if (check.status === 'warning') borderLeftColor = '#ffb900';
                        else if (check.status === 'success') borderLeftColor = '#46b450';

                        var badgeBg = '#f0f6fc';
                        var badgeTextColor = '#007cba';
                        if (check.status === 'critical') { badgeBg = '#fdf2f2'; badgeTextColor = '#dc3232'; }
                        else if (check.status === 'warning') { badgeBg = '#fffdf6'; badgeTextColor = '#ffb900'; }
                        else if (check.status === 'success') { badgeBg = '#f0fcf0'; badgeTextColor = '#46b450'; }

                        html += '<div class="acomr-health-card ' + statusClass + '" style="background: #ffffff; border: 1px solid #dcdcde; border-left: 5px solid ' + borderLeftColor + '; border-radius: 4px; padding: 15px; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">';
                        html += '  <div class="acomr-check-header" style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">';
                        html += '    <h4 style="margin: 0; font-size: 14px; font-weight: 600; color: #1d2327;">' + icon + ' ' + check.title + '</h4>';
                        html += '    <span class="acomr-badge-status status-' + check.status + '" style="text-transform: uppercase; font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 3px; background-color: ' + badgeBg + '; color: ' + badgeTextColor + ';">' + check.status + '</span>';
                        html += '  </div>';
                        html += '  <p class="acomr-check-message" style="margin: 0 0 8px 0; font-size: 13px; color: #2c3338;">' + check.message + '</p>';
                        if (check.fix) {
                            html += '  <div class="acomr-check-fix" style="background: #f6f7f7; border: 1px solid #dcdcde; padding: 8px 12px; border-radius: 4px; font-size: 12px; margin-top: 10px; color: #2c3338;">';
                            html += '    <strong>Suggested Resolution:</strong> ' + check.fix;
                            html += '  </div>';
                        }
                        html += '</div>';
                    });
                    html += '</div>';
                    listContainer.html(html);
                } else {
                    listContainer.html('<div class="acomr-table-placeholder log-error">Error running health checks: ' + (res.data && res.data.message ? res.data.message : 'Unknown error') + '</div>');
                }
            },
            error: function() {
                listContainer.html('<div class="acomr-table-placeholder log-error">Failed to complete system health checks request.</div>');
            },
            complete: function() {
                $('#btn-run-health-checks').prop('disabled', false).text('Run Health Audit');
            }
        });
    }

    /**
     * Load the non-offloaded attachments diagnostics list.
     */
    function loadDiagnosticsList() {
        var tbody = $('#diagnostics-table-body');
        tbody.html('<tr><td colspan="5" class="acomr-table-placeholder">Fetching non-offloaded attachments...</td></tr>');

        $.ajax({
            url: ACO_Media_Recovery_Settings.ajax_url,
            method: 'POST',
            data: {
                action: 'aco_media_recovery_fetch_not_offloaded',
                security: ACO_Media_Recovery_Settings.nonce,
                page: state.diagnostics_page,
                per_page: 15,
                search: state.diagnostics_search
            },
            success: function(res) {
                if (res && res.success && res.data) {
                    renderDiagnosticsTable(res.data.items);
                    renderDiagnosticsPagination(res.data.pages, res.data.current, res.data.total_count);
                } else {
                    tbody.html('<tr><td colspan="5" class="acomr-table-placeholder log-error">Error fetching list: ' + (res.data && res.data.message ? res.data.message : 'Unknown error') + '</td></tr>');
                }
            },
            error: function() {
                tbody.html('<tr><td colspan="5" class="acomr-table-placeholder log-error">Failed to query not-offloaded attachments.</td></tr>');
            }
        });
    }

    /**
     * Render the non-offloaded items.
     */
    function renderDiagnosticsTable(items) {
        var tbody = $('#diagnostics-table-body');
        if (items.length === 0) {
            tbody.html('<tr><td colspan="5" class="acomr-table-placeholder">All attachments are successfully offloaded, or no attachments match criteria!</td></tr>');
            return;
        }

        var html = '';
        items.forEach(function(item) {
            var badgeColor = 'background-color: #f6f7f7; color: #50575e;';
            if (item.severity === 'critical') {
                badgeColor = 'background-color: #fcf0f0; color: #d94f4f; border: 1px solid #f5c2c2;';
            } else if (item.severity === 'warning') {
                badgeColor = 'background-color: #fdfaf2; color: #cca300; border: 1px solid #fbebcb;';
            } else if (item.severity === 'info') {
                badgeColor = 'background-color: #f0f6fc; color: #2271b1; border: 1px solid #c2dbf0;';
            }

            html += '<tr style="border-bottom: 1px solid #dcdcde;">';
            html += '  <td style="padding: 12px 10px; font-weight: 500;">' + item.id + '</td>';
            html += '  <td style="padding: 12px 10px;"><strong>' + escapeHtml(item.title) + '</strong><br><code style="font-size: 11px; color: #646970;">' + escapeHtml(item.filename) + '</code></td>';
            html += '  <td style="padding: 12px 10px; color: #646970; font-size: 13px;">' + item.date + '</td>';
            html += '  <td style="padding: 12px 10px;"><span style="display: inline-block; font-size: 12px; font-weight: 500; padding: 4px 10px; border-radius: 4px; ' + badgeColor + '">' + item.issue + '</span></td>';
            html += '  <td style="padding: 12px 10px; text-align: right;"><button class="acomr-btn acomr-btn-secondary btn-view-diagnostics" data-id="' + item.id + '">View Details</button></td>';
            html += '</tr>';
        });
        tbody.html(html);
    }

    /**
     * Diagnostics Pagination
     */
    function renderDiagnosticsPagination(totalPages, current, totalCount) {
        var pagination = $('#diagnostics-pagination');
        pagination.empty();

        if (totalCount === 0) return;

        var startEntry = (current - 1) * 15 + 1;
        var endEntry = Math.min(current * 15, totalCount);

        var html = '<span class="acomr-pagination-info" style="font-size: 13px; color: #646970;">Showing ' + startEntry + ' to ' + endEntry + ' of ' + totalCount + ' non-offloaded attachments</span>';
        html += '<div style="display: flex; gap: 4px;">';
        html += '  <button class="acomr-diag-page-link" data-page="1" ' + (current === 1 ? 'disabled' : '') + ' style="padding: 4px 8px; border: 1px solid #8c8f94; border-radius: 4px; background: #fff; cursor: pointer;">&laquo;</button>';
        html += '  <button class="acomr-diag-page-link" data-page="' + (current - 1) + '" ' + (current === 1 ? 'disabled' : '') + ' style="padding: 4px 8px; border: 1px solid #8c8f94; border-radius: 4px; background: #fff; cursor: pointer;">&lsaquo;</button>';

        var startPage = Math.max(1, current - 2);
        var endPage = Math.min(totalPages, current + 2);

        for (var i = startPage; i <= endPage; i++) {
            var activeStyle = (i === current) ? 'background-color: #2271b1; color: #fff; border-color: #2271b1;' : 'background: #fff;';
            html += '  <button class="acomr-diag-page-link" data-page="' + i + '" style="padding: 4px 10px; border: 1px solid #8c8f94; border-radius: 4px; ' + activeStyle + ' cursor: pointer;">' + i + '</button>';
        }

        html += '  <button class="acomr-diag-page-link" data-page="' + (current + 1) + '" ' + (current === totalPages ? 'disabled' : '') + ' style="padding: 4px 8px; border: 1px solid #8c8f94; border-radius: 4px; background: #fff; cursor: pointer;">&rsaquo;</button>';
        html += '  <button class="acomr-diag-page-link" data-page="' + totalPages + '" ' + (current === totalPages ? 'disabled' : '') + ' style="padding: 4px 8px; border: 1px solid #8c8f94; border-radius: 4px; background: #fff; cursor: pointer;">&raquo;</button>';
        html += '</div>';

        pagination.html(html);
    }

    /**
     * Load Deep-Dive diagnostics for a single item.
     */
    function loadAttachmentDiagnostics(id, detailsRow, button) {
        $.ajax({
            url: ACO_Media_Recovery_Settings.ajax_url,
            method: 'POST',
            data: {
                action: 'aco_media_recovery_fetch_attachment_diagnostics',
                security: ACO_Media_Recovery_Settings.nonce,
                id: id
            },
            success: function(res) {
                if (res && res.success && res.data) {
                    var data = res.data;
                    var container = detailsRow.find('td');

                    // Start rendering details
                    var html = '<div class="acomr-diagnostic-report" style="background: #fafafa; border: 1px solid #c9cbce; border-radius: 6px; padding: 20px; font-family: inherit; color: #2c3338;">';
                    
                    // Header row
                    html += '  <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e0e2e4; padding-bottom: 10px; margin-bottom: 15px;">';
                    html += '    <h4 style="margin: 0; font-size: 15px; font-weight: 600; color: #1d2327;">Comprehensive Diagnostic Report: #' + data.info.id + '</h4>';
                    html += '    <span style="font-size: 11px; color: #646970;">Checked: ' + new Date().toLocaleTimeString() + '</span>';
                    html += '  </div>';

                    // Grid Layout: Left Column (Prerequisites & Issues) / Right Column (Details & Metadata)
                    html += '  <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">';
                    
                    // Left Column
                    html += '    <div>';
                    // Prerequisites Checkbox list
                    html += '      <h5 style="margin: 0 0 10px 0; font-size: 13px; font-weight: 600; text-transform: uppercase; color: #646970; letter-spacing: 0.5px;">Offload Prerequisites</h5>';
                    html += '      <div style="display: flex; flex-direction: column; gap: 8px; margin-bottom: 20px;">';
                    data.prereqs.forEach(function(p) {
                        var pass = p.status === 'pass';
                        var badge = pass ? '<span style="color: #46b450; font-weight: bold;">&#9989; PASS</span>' : '<span style="color: #dc3232; font-weight: bold;">&#10060; FAIL</span>';
                        html += '        <div style="display: flex; justify-content: space-between; align-items: center; padding: 6px 10px; background: #fff; border: 1px solid #dcdcde; border-radius: 4px;">';
                        html += '          <span><strong>' + p.name + '</strong><br><small style="color: #646970;">' + p.desc + '</small></span>';
                        html += '          <span>' + badge + '</span>';
                        html += '        </div>';
                    });
                    html += '      </div>';

                    // Issues and Fixes
                    html += '      <h5 style="margin: 0 0 10px 0; font-size: 13px; font-weight: 600; text-transform: uppercase; color: #646970; letter-spacing: 0.5px;">Detected Issues & Fixes</h5>';
                    if (data.issues.length === 0) {
                        html += '      <p style="color: #46b450; font-size: 13px;">No issues or prerequisites failures detected! This attachment is ready for offloading.</p>';
                    } else {
                        html += '      <div style="display: flex; flex-direction: column; gap: 10px;">';
                        data.issues.forEach(function(iss) {
                            var statusColor = '#dc3232'; // critical red
                            var bg = '#fdf2f2';
                            if (iss.severity === 'warning') {
                                statusColor = '#ffb900';
                                bg = '#fffdf6';
                            } else if (iss.severity === 'info') {
                                statusColor = '#007cba';
                                bg = '#f0f6fc';
                            }

                            html += '        <div style="border-left: 4px solid ' + statusColor + '; background: ' + bg + '; padding: 12px; border-radius: 4px;">';
                            html += '          <strong style="font-size: 13px; color: #1d2327;">' + iss.title + ' (' + iss.severity.toUpperCase() + ')</strong>';
                            html += '          <p style="margin: 5px 0; font-size: 12.5px; color: #2c3338;">' + iss.desc + '</p>';
                            html += '          <div style="font-size: 11.5px; margin-top: 8px; border-top: 1px solid rgba(0,0,0,0.05); padding-top: 6px;">';
                            html += '            <strong>Fix:</strong> ' + iss.fix;
                            html += '          </div>';
                            html += '        </div>';
                        });
                        html += '      </div>';
                    }
                    html += '    </div>';

                    // Right Column
                    html += '    <div>';
                    // Attachment Info table
                    html += '      <h5 style="margin: 0 0 10px 0; font-size: 13px; font-weight: 600; text-transform: uppercase; color: #646970; letter-spacing: 0.5px;">Attachment Details</h5>';
                    html += '      <table style="width:100%; font-size: 12.5px; margin-bottom: 20px; border-collapse: collapse;">';
                    html += '        <tr style="border-bottom: 1px solid #e0e2e4;"><td style="padding: 6px 0; font-weight:600; color:#50575e;">Title</td><td style="padding:6px 0;">' + escapeHtml(data.info.title) + '</td></tr>';
                    html += '        <tr style="border-bottom: 1px solid #e0e2e4;"><td style="padding: 6px 0; font-weight:600; color:#50575e;">MIME Type</td><td style="padding:6px 0;">' + escapeHtml(data.info.mime) + '</td></tr>';
                    html += '        <tr style="border-bottom: 1px solid #e0e2e4;"><td style="padding: 6px 0; font-weight:600; color:#50575e;">Local Size</td><td style="padding:6px 0;">' + data.info.size + '</td></tr>';
                    html += '        <tr style="border-bottom: 1px solid #e0e2e4;"><td style="padding: 6px 0; font-weight:600; color:#50575e;">Upload Date</td><td style="padding:6px 0;">' + data.info.upload_date + '</td></tr>';
                    html += '        <tr style="border-bottom: 1px solid #e0e2e4;"><td style="padding: 6px 0; font-weight:600; color:#50575e;">Database File Key</td><td style="padding:6px 0;"><code>' + escapeHtml(data.info.filename) + '</code></td></tr>';
                    html += '        <tr style="border-bottom: 1px solid #e0e2e4;"><td style="padding: 6px 0; font-weight:600; color:#50575e;">Full Local Path</td><td style="padding:6px 0; word-break: break-all;"><code>' + escapeHtml(data.info.upload_path) + '</code></td></tr>';
                    html += '      </table>';

                    // Cloud Provider and object keys
                    html += '      <h5 style="margin: 0 0 10px 0; font-size: 13px; font-weight: 600; text-transform: uppercase; color: #646970; letter-spacing: 0.5px;">Cloud Storage Resolution</h5>';
                    html += '      <table style="width:100%; font-size: 12.5px; margin-bottom: 20px; border-collapse: collapse;">';
                    html += '        <tr style="border-bottom: 1px solid #e0e2e4;"><td style="padding: 6px 0; font-weight:600; color:#50575e;">Provider / Bucket</td><td style="padding:6px 0;">' + (data.provider.provider ? '<strong>' + data.provider.provider.toUpperCase() + '</strong> (' + data.provider.bucket + ')' : '<span style="color:#d94f4f;">Not Configured</span>') + '</td></tr>';
                    html += '        <tr style="border-bottom: 1px solid #e0e2e4;"><td style="padding: 6px 0; font-weight:600; color:#50575e;">Generated Object Key</td><td style="padding:6px 0;"><code>' + (data.upload_key ? escapeHtml(data.upload_key) : 'N/A') + '</code></td></tr>';
                    
                    var rStatusBadge = '<span style="color:#646970; font-weight:bold;">UNAVAILABLE</span>';
                    if (data.remote.status === 'exists') {
                        rStatusBadge = '<span style="color:#46b450; font-weight:bold;">&#9989; FOUND ON CLOUD</span>';
                    } else if (data.remote.status === 'missing') {
                        rStatusBadge = '<span style="color:#dc3232; font-weight:bold;">&#10060; MISSING FROM CLOUD</span>';
                    } else if (data.remote.status === 'error') {
                        rStatusBadge = '<span style="color:#ffb900; font-weight:bold;">CHECK ERROR</span>';
                    }
                    html += '        <tr style="border-bottom: 1px solid #e0e2e4;"><td style="padding: 6px 0; font-weight:600; color:#50575e;">Storage Bucket Check</td><td style="padding:6px 0;">' + rStatusBadge + '</td></tr>';

                    var rewriteBadge = data.rewrite.status ? '<span style="color: #46b450; font-weight: bold;">ACTIVE (Rewritten)</span>' : '<span style="color: #646970;">INACTIVE (Local fallback)</span>';
                    html += '        <tr style="border-bottom: 1px solid #e0e2e4;"><td style="padding: 6px 0; font-weight:600; color:#50575e;">URL Rewrite Status</td><td style="padding:6px 0;">' + rewriteBadge + '</td></tr>';
                    html += '        <tr style="border-bottom: 1px solid #e0e2e4;"><td style="padding: 6px 0; font-weight:600; color:#50575e;">Resolved Public URL</td><td style="padding:6px 0; word-break: break-all;"><a href="' + data.rewrite.rewritten_url + '" target="_blank">' + escapeHtml(data.rewrite.rewritten_url) + '</a></td></tr>';
                    html += '      </table>';
                    html += '    </div>';
                    html += '  </div>';

                    // Collapsible Database Metadata Dump
                    html += '  <div style="margin-top: 20px;">';
                    html += '    <button type="button" class="acomr-btn acomr-btn-secondary btn-toggle-db-meta" style="font-size: 11px; padding: 4px 8px;">Show Database Metadata Dumps</button>';
                    html += '    <div class="db-meta-dumps" style="display: none; margin-top: 10px; max-height: 250px; overflow-y: auto; background: #fff; border: 1px solid #dcdcde; border-radius: 4px; padding: 10px; font-size: 11px; font-family: monospace;">';
                    html += '      <strong>_wp_attachment_metadata:</strong>';
                    html += '      <pre style="margin: 5px 0 10px 0; white-space: pre-wrap; word-break: break-all;">' + JSON.stringify(data.metadata.attachment_metadata, null, 2) + '</pre>';
                    html += '      <strong>acoofmp_sync_to_cloud_status:</strong>';
                    html += '      <pre style="margin: 5px 0 0 0; white-space: pre-wrap; word-break: break-all;">' + JSON.stringify(data.metadata.offload_metadata, null, 2) + '</pre>';
                    html += '    </div>';
                    html += '  </div>';

                    html += '</div>';

                    container.html(html);

                    // Add toggle handler for metadata dump
                    detailsRow.find('.btn-toggle-db-meta').on('click', function() {
                        var pre = detailsRow.find('.db-meta-dumps');
                        pre.toggle();
                        if (pre.is(':visible')) {
                            $(this).text('Hide Database Metadata Dumps');
                        } else {
                            $(this).text('Show Database Metadata Dumps');
                        }
                    });

                    button.text('Hide Details');
                } else {
                    detailsRow.find('td').html('<div class="acomr-details-error" style="color: #dc3232; padding: 10px; text-align: center;">Error: ' + (res.data && res.data.message ? res.data.message : 'Unknown error') + '</div>');
                }
            },
            error: function() {
                detailsRow.find('td').html('<div class="acomr-details-error" style="color: #dc3232; padding: 10px; text-align: center;">Failed to load diagnostic details from server.</div>');
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });
    }

    /**
     * Update progress bar percentages and message.
     */
    function updateProgressBar(processed, total) {
        var percentage = total > 0 ? Math.round((processed / total) * 100) : 0;
        $('#progress-bar').css('width', percentage + '%').text(percentage + '%');
        $('#progress-text').text('Processed ' + processed + ' of ' + total + ' files...');
    }

    /**
     * Scrollable terminal logging helper.
     */
    function logToConsole(message, type) {
        var consoleBox = $('#acomr-console');
        var classMap = {
            'info': 'log-info',
            'warning': 'log-warning',
            'error': 'log-error',
            'success': 'log-success',
            'muted': 'log-muted',
            'title': 'log-title'
        };
        
        var className = classMap[type] || 'log-muted';
        var finalMsg = (type === 'success') ? message : escapeHtml(message);
        consoleBox.append('<span class="' + className + '">' + finalMsg + '</span>');
        
        // Auto scroll to bottom
        consoleBox.scrollTop(consoleBox[0].scrollHeight);
    }

    /**
     * Simple HTML Escaper for console outputs.
     */
    function escapeHtml(text) {
        return text
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }
});
