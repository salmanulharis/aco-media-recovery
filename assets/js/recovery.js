jQuery(document).ready(function ($) {
    // Current State
    var state = {
        page: 1,
        per_page: 15,
        search: '',
        filter: 'missing',
        tab: 'tab-scanner',
        selected: [],
        items: [] // List of items on current page
    };

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
                    if (res.success) {
                        alert(res.data.message);
                    } else {
                        alert('Error: ' + res.data.message);
                    }
                },
                error: function () {
                    alert('Error saving settings.');
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
                if (res.success) {
                    renderTable(res.data.items);
                    renderPagination(res.data.pages, res.data.current, res.data.total_count);
                    updateStats(res.data.stats);
                } else {
                    tbody.html('<tr><td colspan="6" class="acomr-table-placeholder log-error">Error: ' + res.data.message + '</td></tr>');
                }
            },
            error: function () {
                tbody.html('<tr><td colspan="6" class="acomr-table-placeholder log-error">Error making AJAX request.</td></tr>');
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
                if (res.success && res.data.items) {
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
                    logToConsole('[ERROR] Failed to query matching IDs: ' + res.data.message, 'error');
                    $('#btn-recover-all').prop('disabled', false);
                }
            },
            error: function () {
                logToConsole('[ERROR] Request error while querying matching IDs.', 'error');
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
                    if (res.success && res.data.logs) {
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
                        logToConsole('[ERROR] Failed to process batch chunk: ' + (res.data ? res.data.message : 'Unknown error'), 'error');
                        processed += chunk.length;
                        updateProgressBar(processed, total);
                    }
                    // Continue to next chunk
                    processNextChunk();
                },
                error: function () {
                    logToConsole('[ERROR] HTTP Request failed during chunk processing.', 'error');
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
                if (res.success && res.data.logs) {
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
                    logToConsole('[ERROR] JSON Import Failed: ' + (res.data ? res.data.message : 'Unknown error'), 'error');
                }
                $('#btn-import-json').prop('disabled', false);
                loadMediaList();
            },
            error: function () {
                logToConsole('[ERROR] Request error during JSON import.', 'error');
                $('#btn-import-json').prop('disabled', false);
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
