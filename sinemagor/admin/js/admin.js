/* global sinemagor, jQuery */
(function ($) {
    'use strict';

    // ── State ────────────────────────────────────────────────────────────────
    const state = {
        discover: { page: 1, total: 0, pages: 0, results: [] },
        library:  { page: 1, total: 0, pages: 0 },
        review:   { page: 1, total: 0, pages: 0 },
        selectedDiscover: new Set(),
        selectedLib:      new Set(),
        selectedRev:      new Set(),
        queueTimer:       null,
    };

    // ── Ajax helper ──────────────────────────────────────────────────────────
    function ajax(action, data, successCb, errorCb) {
        return $.ajax({
            url:    sinemagor.ajax_url,
            method: 'POST',
            data:   Object.assign({ action, nonce: sinemagor.nonce }, data),
            success(res) {
                if (res.success) successCb(res.data);
                else (errorCb || defaultError)(res.data);
            },
            error(xhr) { (errorCb || defaultError)(xhr.statusText); },
        });
    }
    function defaultError(msg) { alert('Error: ' + (msg || 'Unknown error')); }

    // ════════════════════════════════════════════════════════════════════════
    // LIBRARY TAB
    // ════════════════════════════════════════════════════════════════════════

    // ── Discover movies from TMDB ─────────────────────────────────────────
    function discoverMovies(page) {
        page = page || 1;
        state.discover.page = page;

        var count      = parseInt($('#sg-count-filter').val(), 10) || 100;
        var needed     = Math.ceil(count / 20);   // how many TMDB pages to fetch
        var startPage  = (page - 1) * needed + 1; // first TMDB page for this logical page

        $('#sg-discover-results').html('<div class="sg-loading-msg">🔍 Loading ' + count + ' movies... (0/' + needed + ' pages)</div>');

        var collected  = [];
        var tmdbTotal  = 0;
        var tmdbPages  = 0;
        var baseParams = {
            genre_id: $('#sg-genre-filter').val(),
            year:     $('#sg-year-filter').val(),
            language: $('#sg-lang-filter').val(),
            sort_by:  $('#sg-sort-filter').val(),
        };

        // Recursively fetch TMDB pages one by one, then render all at once
        (function fetchPage(tmdbPage, remaining) {
            var fetched = needed - remaining + 1;
            $('#sg-discover-results').html('<div class="sg-loading-msg">🔍 Loading ' + count + ' movies... (' + fetched + '/' + needed + ' pages)</div>');

            ajax(
                'sg_tmdb_discover',
                Object.assign({ page: tmdbPage }, baseParams),
                function (data) {
                    tmdbTotal = data.total;
                    tmdbPages = data.pages;
                    collected = collected.concat(data.results || []);

                    var noMore = remaining <= 1 ||
                                 !data.results    ||
                                 data.results.length < 20 ||
                                 tmdbPage >= tmdbPages;

                    if (noMore) {
                        var results = collected.slice(0, count);
                        var logicalPages = Math.max(1, Math.ceil(tmdbPages / needed));
                        state.discover = Object.assign(state.discover, {
                            total:   tmdbTotal,
                            pages:   logicalPages,
                            results: results,
                        });
                        renderDiscoverGrid(results);
                        renderPagination('#sg-discover-pagination', page, logicalPages, discoverMovies);
                        $('#sg-lib-bulk-bar').show();
                    } else {
                        fetchPage(tmdbPage + 1, remaining - 1);
                    }
                },
                function () {
                    // On error render whatever was collected so far
                    if (collected.length) {
                        renderDiscoverGrid(collected.slice(0, count));
                    } else {
                        $('#sg-discover-results').html('<p class="sg-empty">Request failed. Please try again.</p>');
                    }
                    $('#sg-lib-bulk-bar').show();
                }
            );
        })(startPage, needed);
    }

    function renderDiscoverGrid(movies) {
        if (!movies.length) {
            $('#sg-discover-results').html('<p class="sg-empty">No results found.</p>');
            return;
        }
        const html = movies.map(m => {
            const inLib = m.in_library ? ' sg-card--in-lib' : '';
            const disabled = m.in_library ? 'disabled' : '';
            return `<div class="sg-movie-card${inLib}" data-tmdb="${m.tmdb_id}">
                <div class="sg-card-check">
                    <input type="checkbox" class="sg-discover-check" value="${m.tmdb_id}" ${disabled} />
                </div>
                <img src="${m.poster_path ? 'https://image.tmdb.org/t/p/w185' + m.poster_path : ''}"
                     alt="${esc(m.title)}" loading="lazy" class="sg-card-poster" />
                <div class="sg-card-info">
                    <div class="sg-card-title">${esc(m.title)}</div>
                    <div class="sg-card-meta">${m.year || '—'} · ⭐${m.tmdb_rating}</div>
                    <div class="sg-card-genre">${esc(m.genre || '')}</div>
                    ${m.in_library ? '<span class="sg-badge sg-badge--green">In Library</span>' : ''}
                </div>
            </div>`;
        }).join('');
        $('#sg-discover-results').html(html);
        syncSelectedDiscover();
    }

    // Sync checkbox states with selectedDiscover set
    function syncSelectedDiscover() {
        $('.sg-discover-check').each(function () {
            this.checked = state.selectedDiscover.has(parseInt(this.value));
        });
        updateDiscoverCount();
    }

    function updateDiscoverCount() {
        $('#sg-selected-count').text(state.selectedDiscover.size + ' selected');
        $('#sg-add-selected-btn').prop('disabled', !state.selectedDiscover.size);
    }

    // ── Add selected movies to library ────────────────────────────────────
    function addSelectedToLibrary() {
        const ids = Array.from(state.selectedDiscover);
        if (!ids.length) return;

        const btn = $('#sg-add-selected-btn').text('Adding...').prop('disabled', true);

        ajax('sg_bulk_add', { tmdb_ids: ids }, function (data) {
            btn.text('+ Add to Library').prop('disabled', false);
            showNotice(`✅ Added: ${data.added}, Skipped (already in library): ${data.skipped}` +
                (data.errors.length ? `. Errors: ${data.errors.join(', ')}` : ''), 'success');
            state.selectedDiscover.clear();
            updateDiscoverCount();
            loadLibraryTable();
            discoverMovies(state.discover.page); // refresh "in library" badges
        }, function (err) {
            btn.text('+ Add to Library').prop('disabled', false);
            showNotice('Error: ' + err, 'error');
        });
    }

    // ── Library table ─────────────────────────────────────────────────────
    function loadLibraryTable(page) {
        page = page || 1;
        state.library.page = page;
        $('#sg-library-tbody').html('<tr><td colspan="8" class="sg-loading-row">Loading...</td></tr>');

        ajax('sg_get_library', {
            search:   $('#sg-lib-search').val(),
            status:   $('#sg-lib-status').val(),
            page,
            per_page: 20,
        }, function (data) {
            state.library.total = data.total;
            state.library.pages = data.pages;
            renderLibraryTable(data.rows);
            renderPagination('#sg-lib-pagination', page, data.pages, loadLibraryTable);
        });
    }

    function renderLibraryTable(rows) {
        if (!rows.length) {
            $('#sg-library-tbody').html('<tr><td colspan="8" class="sg-empty">No movies in library yet.</td></tr>');
            return;
        }
        const html = rows.map(r => `
            <tr data-id="${r.id}">
                <td><input type="checkbox" class="sg-lib-check" value="${r.id}" /></td>
                <td>${r.poster_thumb ? `<img src="${r.poster_thumb}" class="sg-thumb" loading="lazy" />` : '—'}</td>
                <td class="sg-td-title">${esc(r.title)}</td>
                <td>${r.year || '—'}</td>
                <td>${esc(r.genre || '—')}</td>
                <td>⭐${r.tmdb_rating || '—'}</td>
                <td><span class="sg-badge sg-badge--${statusColor(r.status)}">${r.status}</span></td>
                <td>
                    ${r.wp_post_id ? `<a href="/wp-admin/post.php?post=${r.wp_post_id}&action=edit" class="sg-link" target="_blank">Edit Post</a>` : '—'}
                </td>
            </tr>`).join('');
        $('#sg-library-tbody').html(html);
        syncLibraryChecks();
    }

    // ════════════════════════════════════════════════════════════════════════
    // REVIEW TAB
    // ════════════════════════════════════════════════════════════════════════

    function loadReviewTable(page) {
        page = page || 1;
        state.review.page = page;
        $('#sg-review-tbody').html('<tr><td colspan="8" class="sg-loading-row">Loading...</td></tr>');

        ajax('sg_get_library', {
            search:   $('#sg-rev-search').val(),
            status:   $('#sg-rev-status').val(),
            genre:    $('#sg-rev-genre').val(),
            year:     $('#sg-rev-year').val(),
            page,
            per_page: 20,
        }, function (data) {
            state.review.total = data.total;
            state.review.pages = data.pages;
            renderReviewTable(data.rows);
            renderPagination('#sg-rev-pagination', page, data.pages, loadReviewTable);
        });
    }

    function renderReviewTable(rows) {
        if (!rows.length) {
            $('#sg-review-tbody').html('<tr><td colspan="8" class="sg-empty">No movies found.</td></tr>');
            return;
        }
        const html = rows.map(r => `
            <tr data-id="${r.id}">
                <td><input type="checkbox" class="sg-rev-check" value="${r.id}"
                    ${r.status === 'published' ? 'disabled' : ''} /></td>
                <td>${r.poster_thumb ? `<img src="${r.poster_thumb}" class="sg-thumb" loading="lazy" />` : '—'}</td>
                <td class="sg-td-title">${esc(r.title)}</td>
                <td>${r.year || '—'}</td>
                <td>${esc(r.genre || '—')}</td>
                <td>⭐${r.tmdb_rating || '—'}</td>
                <td><span class="sg-badge sg-badge--${statusColor(r.status)}">${r.status}</span></td>
                <td>
                    ${r.status !== 'published'
                        ? `<button class="sg-btn sg-btn--primary sg-btn--sm sg-gen-single" data-id="${r.id}">⚡ Generate</button>`
                        : `<a href="/wp-admin/post.php?post=${r.wp_post_id}&action=edit" class="sg-link" target="_blank">Edit Post</a>`
                    }
                </td>
            </tr>`).join('');
        $('#sg-review-tbody').html(html);
        syncRevChecks();
    }

    // ── Single generate ───────────────────────────────────────────────────
    function generateSingle(movieId) {
        openModal('Generating Review...');

        ajax('sg_single_generate', { movie_id: movieId }, function (data) {
            const statusLabel = data.status === 'publish' ? 'Published' : 'Saved as Draft';
            $('#sg-modal-body').html(`<div class="sg-modal-success">
                <div class="sg-success-icon">✅</div>
                <p><strong>${statusLabel}!</strong></p>
                <p>The review has been generated and ${statusLabel.toLowerCase()}.</p>
            </div>`);
            $('#sg-view-post-btn').attr('href', data.post_url);
            $('#sg-edit-post-btn').attr('href', data.edit_url);
            $('#sg-modal-footer').show();
            loadReviewTable(state.review.page);
        }, function (err) {
            $('#sg-modal-body').html(`<div class="sg-modal-error">❌ Error: ${esc(err)}</div>`);
            $('#sg-modal-footer').show();
        });
    }

    // ── Bulk queue ────────────────────────────────────────────────────────
    function bulkQueue() {
        const ids = Array.from(state.selectedRev);
        if (!ids.length) { alert('Select at least one movie.'); return; }

        ajax('sg_bulk_queue', { movie_ids: ids }, function (data) {
            showNotice('⚡ ' + data.message, 'success');
            state.selectedRev.clear();
            updateRevCount();
            startQueuePolling();
        });
    }

    // ── Queue polling ─────────────────────────────────────────────────────
    function startQueuePolling() {
        $('#sg-queue-bar').show();
        if (state.queueTimer) clearInterval(state.queueTimer);
        state.queueTimer = setInterval(pollQueue, 4000);
        pollQueue();
    }

    function pollQueue() {
        ajax('sg_queue_status', {}, function (data) {
            $('#sg-queue-text').text(`${data.done} / ${data.total} completed`);
            $('#sg-progress-fill').css('width', data.percent + '%');

            if (data.errors && data.errors.length) {
                const errHtml = data.errors.map(e => `<span>⚠ Movie #${e.id}: ${esc(e.error)}</span>`).join(' ');
                $('#sg-queue-errors').html(errHtml);
            }

            if (!data.running) {
                clearInterval(state.queueTimer);
                $('#sg-queue-bar .sg-queue-info strong').text('✅ Bulk Generation Complete');
                loadReviewTable(state.review.page);
                setTimeout(() => $('#sg-queue-bar').fadeOut(), 4000);
            }
        });
    }

    // ════════════════════════════════════════════════════════════════════════
    // MODAL
    // ════════════════════════════════════════════════════════════════════════

    function openModal(title) {
        $('#sg-modal-title').text(title);
        $('#sg-modal-body').html('<div class="sg-spinner-wrap"><div class="sg-spinner"></div><p>AI is writing the review...</p></div>');
        $('#sg-modal-footer').hide();
        $('#sg-modal').show();
    }

    function closeModal() { $('#sg-modal').hide(); }

    // ════════════════════════════════════════════════════════════════════════
    // PAGINATION
    // ════════════════════════════════════════════════════════════════════════

    function renderPagination(selector, current, total, callback) {
        if (total <= 1) { $(selector).empty(); return; }
        let html = '';
        if (current > 1) html += `<button class="sg-page-btn" data-page="${current - 1}">‹ Prev</button>`;
        const start = Math.max(1, current - 2);
        const end   = Math.min(total, current + 2);
        for (let i = start; i <= end; i++) {
            html += `<button class="sg-page-btn${i === current ? ' active' : ''}" data-page="${i}">${i}</button>`;
        }
        if (current < total) html += `<button class="sg-page-btn" data-page="${current + 1}">Next ›</button>`;
        $(selector).html(html).off('click').on('click', '.sg-page-btn', function () {
            callback(parseInt($(this).data('page')));
        });
    }

    // ════════════════════════════════════════════════════════════════════════
    // CHECKBOX SYNC HELPERS
    // ════════════════════════════════════════════════════════════════════════

    function syncLibraryChecks() {
        $('.sg-lib-check').each(function () {
            this.checked = state.selectedLib.has(parseInt(this.value));
        });
    }

    function syncRevChecks() {
        $('.sg-rev-check').each(function () {
            this.checked = state.selectedRev.has(parseInt(this.value));
        });
    }

    function updateRevCount() {
        $('#sg-rev-selected-count').text(state.selectedRev.size + ' selected');
    }

    // ════════════════════════════════════════════════════════════════════════
    // UTILS
    // ════════════════════════════════════════════════════════════════════════

    function esc(str) {
        return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function statusColor(s) {
        return { published: 'green', draft: 'blue', pending: 'gray' }[s] || 'gray';
    }

    function showNotice(msg, type) {
        const cls = type === 'success' ? 'notice-success' : 'notice-error';
        const el  = $(`<div class="notice ${cls} is-dismissible sg-notice"><p>${msg}</p></div>`);
        $('.sg-section').first().before(el);
        setTimeout(() => el.fadeOut(() => el.remove()), 5000);
    }

    // ════════════════════════════════════════════════════════════════════════
    // EVENT BINDINGS
    // ════════════════════════════════════════════════════════════════════════

    $(function () {
        const initialTab = $('#sg-app').data('tab') || 'library';
        const initialized = {};

        // ── Tab switching — NO page reload ───────────────────────────────────
        $(document).on('click', '.sg-tab[data-tab]', function (e) {
            const tab = $(this).data('tab');
            const url  = $(this).attr('href');

            // If panel doesn't exist on this page, navigate normally (e.g. Settings page)
            if (!$('#sg-panel-' + tab).length) return;

            e.preventDefault();

            $('.sg-tab').removeClass('sg-tab--active');
            $(this).addClass('sg-tab--active');
            $('.sg-tab-panel').hide();
            $('#sg-panel-' + tab).show();

            if (history.pushState) history.pushState({ tab: tab }, '', url);
            initTab(tab);
        });

        $(window).on('popstate', function (e) {
            const tab = (e.originalEvent.state && e.originalEvent.state.tab) || 'library';
            $('.sg-tab').removeClass('sg-tab--active');
            $('.sg-tab[data-tab="' + tab + '"]').addClass('sg-tab--active');
            $('.sg-tab-panel').hide();
            $('#sg-panel-' + tab).show();
            initTab(tab);
        });

        function initTab(tab) {
            if (initialized[tab]) return;
            initialized[tab] = true;
            if (tab === 'library') loadLibraryTable();
            if (tab === 'review')  { loadReviewTable(); pollQueue(); }
        }

        // ── Library event handlers ────────────────────────────────────────────
        $('#sg-discover-btn').on('click', () => discoverMovies(1));
        $('#sg-search').on('keydown', e => { if (e.key === 'Enter') discoverMovies(1); });

        $(document).on('change', '.sg-discover-check', function () {
            const id = parseInt(this.value);
            this.checked ? state.selectedDiscover.add(id) : state.selectedDiscover.delete(id);
            updateDiscoverCount();
        });
        $('#sg-select-all-discover').on('change', function () {
            $('.sg-discover-check:not(:disabled)').each(function () {
                const id = parseInt(this.value);
                if (this.checked = document.getElementById('sg-select-all-discover').checked) {
                    state.selectedDiscover.add(id);
                } else {
                    state.selectedDiscover.delete(id);
                }
            });
            updateDiscoverCount();
        });
        $('#sg-clear-selection-btn').on('click', () => {
            state.selectedDiscover.clear();
            syncSelectedDiscover();
        });
        $('#sg-add-selected-btn').on('click', addSelectedToLibrary);

        $('#sg-lib-filter-btn').on('click', () => loadLibraryTable(1));
        $('#sg-lib-search').on('keydown', e => { if (e.key === 'Enter') loadLibraryTable(1); });

        $(document).on('change', '.sg-lib-check', function () {
            const id = parseInt(this.value);
            this.checked ? state.selectedLib.add(id) : state.selectedLib.delete(id);
            $('#sg-lib-selected-count').text(state.selectedLib.size + ' selected');
        });
        $('#sg-lib-check-all').on('change', function () {
            $('.sg-lib-check').each(function () {
                const id = parseInt(this.value);
                if (this.checked = document.getElementById('sg-lib-check-all').checked) {
                    state.selectedLib.add(id);
                } else {
                    state.selectedLib.delete(id);
                }
            });
        });
        $('#sg-lib-delete-btn').on('click', function () {
            const ids = Array.from(state.selectedLib);
            if (!ids.length) return;
            if (!confirm(`Delete ${ids.length} movie(s) from library?`)) return;
            ajax('sg_delete_movies', { ids }, function () {
                showNotice('Deleted ' + ids.length + ' movies.', 'success');
                state.selectedLib.clear();
                loadLibraryTable(1);
            });
        });

        // ── Review event handlers ─────────────────────────────────────────────
        $('#sg-rev-filter-btn').on('click', () => loadReviewTable(1));

        $(document).on('change', '.sg-rev-check', function () {
            const id = parseInt(this.value);
            this.checked ? state.selectedRev.add(id) : state.selectedRev.delete(id);
            updateRevCount();
        });
        $('#sg-rev-select-all, #sg-rev-check-all').on('change', function () {
            const checked = this.checked;
            $('.sg-rev-check:not(:disabled)').each(function () {
                const id = parseInt(this.value);
                this.checked = checked;
                checked ? state.selectedRev.add(id) : state.selectedRev.delete(id);
            });
            updateRevCount();
        });

        $(document).on('click', '.sg-gen-single', function () {
            generateSingle($(this).data('id'));
        });

        $('#sg-bulk-generate-btn').on('click', bulkQueue);

        $('#sg-queue-clear-btn').on('click', function () {
            if (!confirm('Cancel queue?')) return;
            ajax('sg_queue_clear', {}, function () {
                clearInterval(state.queueTimer);
                $('#sg-queue-bar').hide();
                showNotice('Queue cancelled.', 'success');
            });
        });

        // Modal close
        $('#sg-modal-close, #sg-modal-close-btn, .sg-modal-backdrop').on('click', closeModal);

        // ── Bulk Rebuild Internal Links (Post Health tab) ─────────────────
        var rebuildTimer = null;

        function pollRebuildStatus() {
            ajax('sg_rebuild_status', {}, function (data) {
                $('#sg-rebuild-text').text(data.done + ' / ' + data.total + ' posts');
                $('#sg-rebuild-fill').css('width', data.percent + '%');
                if (!data.running) {
                    clearInterval(rebuildTimer);
                    rebuildTimer = null;
                    $('#sg-rebuild-bar').slideUp();
                    $('#sg-bulk-rebuild-btn').text('🔗 Bulk Rebuild All Internal Links').prop('disabled', false);
                    showNotice('✅ Internal links rebuilt for ' + data.done + ' posts.', 'success');
                }
            });
        }

        $(document).on('click', '#sg-bulk-rebuild-btn', function () {
            if (!confirm('Rebuild internal links for all published Sinemagor posts? This runs in the background.')) return;
            $(this).text('Starting...').prop('disabled', true);
            ajax('sg_bulk_rebuild', {}, function (data) {
                if (!data.queued) {
                    showNotice('No posts found to rebuild.', 'error');
                    $('#sg-bulk-rebuild-btn').text('🔗 Bulk Rebuild All Internal Links').prop('disabled', false);
                    return;
                }
                $('#sg-rebuild-bar').slideDown();
                $('#sg-rebuild-text').text('0 / ' + data.queued + ' posts');
                $('#sg-rebuild-fill').css('width', '0%');
                rebuildTimer = setInterval(pollRebuildStatus, 3000);
            }, function () {
                showNotice('Failed to start rebuild.', 'error');
                $('#sg-bulk-rebuild-btn').text('🔗 Bulk Rebuild All Internal Links').prop('disabled', false);
            });
        });

        // ── Initialize the active tab on page load ────────────────────────
        initTab(initialTab);
    });

})(jQuery);
