/* global sinemagor, jQuery */
(function ($) {
    'use strict';

    // Templates that need extra fields
    const EXTRA_FIELDS = {
        best_of_director:  ['director'],
        best_of_streaming: ['platform'],
        similar_movies:    ['movie'],
    };

    let selectedMovieIds = [];
    let previewDebounce  = null;

    // ── Init ──────────────────────────────────────────────────────────────────
    $(function () {
        if (!$('#sg-list-template').length) return;

        loadListPosts();
        updateTitlePreview();

        // Template change → show/hide extra fields + update title
        $('#sg-list-template').on('change', function () {
            updateExtraFields();
            updateTitlePreview();
            resetPreview();
        });

        // Any filter change → update title preview
        $('#sg-list-genre, #sg-list-year, #sg-list-count, #sg-list-rating, #sg-list-director, #sg-list-platform, #sg-list-movie-ref')
            .on('change input', function () {
                clearTimeout(previewDebounce);
                previewDebounce = setTimeout(updateTitlePreview, 400);
                resetPreview();
            });

        // Preview movies button
        $('#sg-list-load-movies-btn').on('click', loadMoviePreview);

        // Select all toggle
        $(document).on('change', '#sg-list-select-all', function () {
            const checked = this.checked;
            $('.sg-list-movie-check').each(function () {
                this.checked = checked;
                const id = parseInt(this.value);
                checked
                    ? selectedMovieIds.includes(id) || selectedMovieIds.push(id)
                    : (selectedMovieIds = selectedMovieIds.filter(i => i !== id));
            });
            updateGenerateBtn();
        });

        // Individual movie checkbox
        $(document).on('change', '.sg-list-movie-check', function () {
            const id = parseInt(this.value);
            if (this.checked) {
                if (!selectedMovieIds.includes(id)) selectedMovieIds.push(id);
            } else {
                selectedMovieIds = selectedMovieIds.filter(i => i !== id);
            }
            updateGenerateBtn();
        });

        // Generate
        $('#sg-list-generate-btn').on('click', generateListPost);

        // Modal close
        $('#sg-list-modal-close, #sg-list-close-btn, #sg-list-modal .sg-modal-backdrop')
            .on('click', function () { $('#sg-list-modal').hide(); });
    });

    // ── Update extra fields based on template ─────────────────────────────────
    function updateExtraFields() {
        const tpl    = $('#sg-list-template').val();
        const fields = EXTRA_FIELDS[tpl] || [];

        $('#sg-extra-director-wrap, #sg-extra-platform-wrap, #sg-extra-movie-wrap').hide();
        if (fields.includes('director')) $('#sg-extra-director-wrap').show();
        if (fields.includes('platform')) $('#sg-extra-platform-wrap').show();
        if (fields.includes('movie'))    $('#sg-extra-movie-wrap').show();

        $('#sg-list-extra').toggle(fields.length > 0);
    }

    // ── Title preview ─────────────────────────────────────────────────────────
    function updateTitlePreview() {
        $.ajax({
            url:  sinemagor.ajax_url,
            method: 'POST',
            data: {
                action:    'sg_list_preview',
                nonce:     sinemagor.nonce,
                template:  $('#sg-list-template').val(),
                genre:     $('#sg-list-genre').val(),
                year:      $('#sg-list-year').val(),
                count:     $('#sg-list-count').val(),
                director:  $('#sg-list-director').val(),
                platform:  $('#sg-list-platform').val(),
                movie:     $('#sg-list-movie-ref').val(),
            },
            success(res) {
                if (res.success) {
                    $('#sg-list-custom-title').val(res.data.title);
                }
            },
        });
    }

    // ── Load movie preview ────────────────────────────────────────────────────
    function loadMoviePreview() {
        const btn = $('#sg-list-load-movies-btn').text('Loading...').prop('disabled', true);
        selectedMovieIds = [];
        $('#sg-list-generate-btn').hide();

        $.ajax({
            url:    sinemagor.ajax_url,
            method: 'POST',
            data: {
                action:     'sg_list_movies',
                nonce:      sinemagor.nonce,
                genre:      $('#sg-list-genre').val(),
                year:       $('#sg-list-year').val(),
                count:      $('#sg-list-count').val(),
                director:   $('#sg-list-director').val(),
                min_rating: $('#sg-list-rating').val(),
            },
            success(res) {
                btn.text('📋 Preview Movies').prop('disabled', false);
                if (!res.success) { alert(res.data); return; }

                const movies = res.data;
                renderMovieGrid(movies);
                $('#sg-list-preview-wrap').show();
                $('#sg-list-preview-title').text('Movies in this list (' + movies.length + ' found):');

                if (movies.length < 3) {
                    $('#sg-list-warning').html('⚠️ Only ' + movies.length + ' movies found. Add more movies to your library or adjust filters.');
                } else {
                    $('#sg-list-warning').html('✅ ' + movies.length + ' movies ready. Uncheck any you want to exclude, then click Generate.');
                }

                // Auto-select all
                selectedMovieIds = movies.map(m => m.id);
                updateGenerateBtn();
            },
            error() {
                btn.text('📋 Preview Movies').prop('disabled', false);
                alert('Failed to load movies.');
            },
        });
    }

    function renderMovieGrid(movies) {
        if (!movies.length) {
            $('#sg-list-movie-grid').html('<p class="sg-empty">No movies found in your library matching these filters. Add more movies first.</p>');
            return;
        }

        const html = movies.map((m, i) => `
            <div class="sg-list-movie-card">
                <div class="sg-list-card-check">
                    <input type="checkbox" class="sg-list-movie-check" value="${m.id}" checked />
                    <span class="sg-list-card-rank">#${i + 1}</span>
                </div>
                ${m.poster_thumb
                    ? `<img src="${esc(m.poster_thumb)}" alt="${esc(m.title)}" loading="lazy" />`
                    : '<div class="sg-list-no-poster">🎬</div>'
                }
                <div class="sg-list-card-info">
                    <div class="sg-list-card-title">${esc(m.title)}</div>
                    <div class="sg-list-card-meta">
                        ${m.year || ''} · ⭐${m.tmdb_rating || '—'}
                        ${m.review_url ? ' · <span style="color:#4caf50;font-size:.72rem">✓ Review exists</span>' : ''}
                    </div>
                </div>
            </div>
        `).join('');

        $('#sg-list-movie-grid').html(html);
    }

    function updateGenerateBtn() {
        const count = selectedMovieIds.length;
        const btn   = $('#sg-list-generate-btn');
        if (count >= 3) {
            btn.text('⚡ Generate List Post (' + count + ' movies)').show();
        } else {
            btn.hide();
        }
    }

    function resetPreview() {
        $('#sg-list-preview-wrap').hide();
        $('#sg-list-generate-btn').hide();
        selectedMovieIds = [];
    }

    // ── Generate list post ────────────────────────────────────────────────────
    function generateListPost() {
        if (selectedMovieIds.length < 3) {
            alert('Select at least 3 movies.');
            return;
        }

        openListModal('Generating List Post...');

        $.ajax({
            url:    sinemagor.ajax_url,
            method: 'POST',
            data: {
                action:       'sg_list_generate',
                nonce:        sinemagor.nonce,
                template:     $('#sg-list-template').val(),
                genre:        $('#sg-list-genre').val(),
                year:         $('#sg-list-year').val(),
                count:        selectedMovieIds.length,
                director:     $('#sg-list-director').val(),
                platform:     $('#sg-list-platform').val(),
                movie_ref:    $('#sg-list-movie-ref').val(),
                custom_title: $('#sg-list-custom-title').val(),
                min_rating:   $('#sg-list-rating').val(),
                movie_ids:    selectedMovieIds,
            },
            success(res) {
                if (res.success) {
                    const d = res.data;
                    const statusLabel = d.status === 'publish' ? 'Published' : 'Saved as Draft';
                    $('#sg-list-modal-title').text('✅ ' + statusLabel + '!');
                    $('#sg-list-modal-body').html(`
                        <div class="sg-modal-success">
                            <div class="sg-success-icon">📋</div>
                            <p><strong>${esc(d.title)}</strong></p>
                            <p style="color:#aaa">${statusLabel} with ${selectedMovieIds.length} movies.</p>
                        </div>
                    `);
                    $('#sg-list-view-btn').attr('href', d.post_url);
                    $('#sg-list-edit-btn').attr('href', d.edit_url);
                    $('#sg-list-modal-footer').show();
                    loadListPosts();
                } else {
                    $('#sg-list-modal-title').text('❌ Error');
                    $('#sg-list-modal-body').html('<div class="sg-modal-error">Error: ' + esc(res.data) + '</div>');
                    $('#sg-list-modal-footer').show();
                }
            },
            error() {
                $('#sg-list-modal-title').text('❌ Error');
                $('#sg-list-modal-body').html('<div class="sg-modal-error">Network error. Please try again.</div>');
                $('#sg-list-modal-footer').show();
            },
        });
    }

    // ── Load published list posts ─────────────────────────────────────────────
    function loadListPosts() {
        const posts = getListPosts();
        if (!posts.length) {
            $('#sg-list-posts-tbody').html('<tr><td colspan="6" class="sg-empty">No list posts yet.</td></tr>');
            return;
        }

        const html = posts.map(p => `
            <tr>
                <td class="sg-td-title">${esc(p.title)}</td>
                <td><span class="sg-badge sg-badge--blue">${esc(p.template || 'custom')}</span></td>
                <td>${p.movie_count || '—'}</td>
                <td><span class="sg-badge sg-badge--${p.status === 'publish' ? 'green' : 'gray'}">${p.status}</span></td>
                <td style="color:#7a7a9a;font-size:.85rem">${p.date}</td>
                <td>
                    <a href="${esc(p.edit_url)}" class="sg-link" target="_blank">Edit</a>
                    ${p.view_url ? ' · <a href="' + esc(p.view_url) + '" class="sg-link" target="_blank">View</a>' : ''}
                </td>
            </tr>
        `).join('');

        $('#sg-list-posts-tbody').html(html);
    }

    function getListPosts() {
        // Fetch via WP REST or return empty (loaded server-side below)
        return window.sgListPosts || [];
    }

    // ── Modal ─────────────────────────────────────────────────────────────────
    function openListModal(title) {
        $('#sg-list-modal-title').text(title);
        $('#sg-list-modal-body').html(`
            <div class="sg-spinner-wrap">
                <div class="sg-spinner"></div>
                <p>AI is writing your list post...<br>
                <small style="color:#7a7a9a">This may take 30–60 seconds</small></p>
            </div>
        `);
        $('#sg-list-modal-footer').hide();
        $('#sg-list-modal').show();
    }

    // ── Utils ─────────────────────────────────────────────────────────────────
    function esc(str) {
        return String(str || '')
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

})(jQuery);
