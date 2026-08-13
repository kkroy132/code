/**
 * WP SEO Doctor — admin behaviour.
 *
 * All server calls go through post(), which handles the nonce and normalises
 * WordPress's success/error envelope into a promise rejection on failure.
 */
(function ($) {
    'use strict';

    var i18n = (window.wpsdData && window.wpsdData.i18n) || {};

    function post(action, data) {
        return $.post(window.wpsdData.ajaxUrl, $.extend({
            action: 'wpsd_' + action,
            nonce: window.wpsdData.nonce
        }, data || {})).then(function (response) {
            if (!response || !response.success) {
                var message = (response && response.data && response.data.message) || i18n.failed;
                return $.Deferred().reject(new Error(message)).promise();
            }
            return response.data;
        }, function () {
            return $.Deferred().reject(new Error(i18n.failed)).promise();
        });
    }

    function setResult($el, message, state) {
        if (!$el || !$el.length) {
            return;
        }
        $el.removeClass('is-success is-error');
        if (state) {
            $el.addClass('is-' + state);
        }
        $el.text(message || '');
    }

    function busy($el, on) {
        if (!$el || !$el.length) {
            return;
        }
        $el.prop('disabled', !!on);
        if (on) {
            $el.data('wpsd-label', $el.text()).text(i18n.working || 'Working…');
        } else if ($el.data('wpsd-label')) {
            $el.text($el.data('wpsd-label'));
        }
    }

    function collectChecked(selector) {
        return $(selector + ':checked').map(function () {
            return $(this).val();
        }).get();
    }

    // ── Scanning ──────────────────────────────────────────────────────────

    var scan = {
        id: 0,
        running: false,

        start: function (type) {
            if (scan.running) {
                return;
            }
            scan.running = true;

            var $bar = $('#wpsd-scanbar');
            $bar.show();
            $('#wpsd-scan-status').text(i18n.working || '');
            $('#wpsd-scan-fill').css('width', '0%');

            post('start_scan', { type: type || 'full' }).then(function (data) {
                scan.id = data.scan_id;
                $bar.attr('data-scan-id', data.scan_id);

                if (!data.total) {
                    scan.finish();
                    $('#wpsd-scan-status').text('No published content to scan.');
                    return;
                }
                scan.batch();
            }).fail(function (error) {
                scan.running = false;
                $('#wpsd-scan-status').text(error.message);
            });
        },

        batch: function () {
            post('scan_batch', { scan_id: scan.id }).then(function (data) {
                $('#wpsd-scan-fill').css('width', data.percent + '%');
                $('#wpsd-scan-status').text(
                    (i18n.scanning || 'Scanning') + ' ' + data.processed + ' / ' + data.total +
                    ' — ' + data.issues + ' issues'
                );
                $('#wpsd-scan-current').text(data.current || '');

                if (data.done) {
                    scan.finish(data);
                    return;
                }
                scan.batch();
            }).fail(function (error) {
                scan.running = false;
                $('#wpsd-scan-status').text(error.message);
            });
        },

        finish: function (data) {
            scan.running = false;
            $('#wpsd-scan-current').text('');

            if (data && typeof data.score !== 'undefined') {
                $('#wpsd-scan-status').text(
                    (i18n.done || 'Done') + ' — ' + data.score + '/100, ' + data.issues + ' issues found.'
                );
            }
            // Reload so every table on the page reflects the new findings.
            window.setTimeout(function () {
                window.location.reload();
            }, 1200);
        }
    };

    $(document).on('click', '#wpsd-start-scan, .wpsd-scan-trigger', function () {
        scan.start($(this).data('type'));
    });

    $(document).on('click', '#wpsd-cancel-scan', function () {
        var id = scan.id || $('#wpsd-scanbar').attr('data-scan-id');
        if (!id) {
            return;
        }
        post('cancel_scan', { scan_id: id }).always(function () {
            window.location.reload();
        });
    });

    $(document).on('click', '.wpsd-delete-scan', function () {
        if (!window.confirm(i18n.confirmDelete)) {
            return;
        }
        var $button = $(this);
        post('delete_scan', { scan_id: $button.data('scan-id') }).then(function () {
            $button.closest('tr').fadeOut(200, function () {
                $(this).remove();
            });
        });
    });

    // Resume a scan that was already running when the page loaded.
    $(function () {
        var existing = $('#wpsd-scanbar').attr('data-scan-id');
        if (existing) {
            scan.id = parseInt(existing, 10);
            scan.running = true;
            scan.batch();
        }
    });

    // ── Issues ────────────────────────────────────────────────────────────

    $(document).on('change', '#wpsd-select-all-issues', function () {
        $('.wpsd-issue-check').prop('checked', $(this).is(':checked'));
    });

    $(document).on('click', '.wpsd-issue-bulk', function () {
        var ids = collectChecked('.wpsd-issue-check');
        var $result = $('#wpsd-issue-bulk-result');

        if (!ids.length) {
            setResult($result, 'Select at least one issue.', 'error');
            return;
        }

        var $button = $(this);
        busy($button, true);

        post('issue_status', { ids: ids, status: $button.data('status') }).then(function (data) {
            setResult($result, data.updated + ' updated.', 'success');
            window.setTimeout(function () {
                window.location.reload();
            }, 700);
        }).fail(function (error) {
            setResult($result, error.message, 'error');
        }).always(function () {
            busy($button, false);
        });
    });

    $(document).on('click', '.wpsd-rescan-post', function () {
        var $button = $(this);
        var $result = $button.closest('td').find('.wpsd-inline-result');

        busy($button, true);
        post('rescan_post', { post_id: $button.data('post-id') }).then(function (data) {
            setResult($result.length ? $result : $button.parent(), data.message, 'success');
        }).fail(function (error) {
            setResult($result.length ? $result : $button.parent(), error.message, 'error');
        }).always(function () {
            busy($button, false);
        });
    });

    // ── Broken links ──────────────────────────────────────────────────────

    $(document).on('click', '#wpsd-check-links, #wpsd-check-links-all', function () {
        var $button = $(this);
        var force = $button.attr('id') === 'wpsd-check-links-all';
        var $result = $('#wpsd-quickfix-result');

        busy($button, true);

        function runBatch() {
            return post('check_links', { force: force ? 1 : 0 }).then(function (data) {
                setResult(
                    $result,
                    data.checked + ' checked, ' + data.broken + ' broken, ' + data.remaining + ' remaining.',
                    data.broken > 0 ? 'error' : 'success'
                );

                // "Check everything" keeps going until the queue drains.
                if (force && data.checked > 0 && data.remaining > 0) {
                    return runBatch();
                }
                return data;
            });
        }

        runBatch().fail(function (error) {
            setResult($result, error.message, 'error');
        }).always(function () {
            busy($button, false);
        });
    });

    $(document).on('click', '.wpsd-link-action', function () {
        var $button = $(this);
        var $row = $button.closest('tr');
        var $result = $row.find('.wpsd-inline-result');
        var action = $button.data('action');
        var payload = {
            link_id: $row.data('link-id'),
            link_action: action
        };

        if (action === 'replace') {
            var replacement = window.prompt('New URL for this link:');
            if (!replacement) {
                return;
            }
            payload.new_url = replacement;
        }

        if (action === 'remove' && !window.confirm('Unlink this URL everywhere it appears? The anchor text stays as plain text.')) {
            return;
        }

        busy($button, true);
        post('link_action', payload).then(function (data) {
            setResult($result, data.message, 'success');
            if (action === 'recheck' && data.status === 'ok') {
                $row.find('.wpsd-status').attr('class', 'wpsd-status wpsd-status--ok').text(data.http_status);
            }
        }).fail(function (error) {
            setResult($result, error.message, 'error');
        }).always(function () {
            busy($button, false);
        });
    });

    // ── Internal links ────────────────────────────────────────────────────

    $(document).on('click', '#wpsd-rebuild-links', function () {
        var $button = $(this);
        var $result = $('#wpsd-quickfix-result');
        var offset = 0;
        var total = 0;

        busy($button, true);

        function step() {
            return post('rebuild_links', { offset: offset, limit: 50 }).then(function (data) {
                total += data.indexed;
                offset = data.offset;
                setResult($result, total + ' pages indexed…');

                if (!data.done) {
                    return step();
                }
                setResult($result, total + ' pages indexed.', 'success');
                return data;
            });
        }

        step().fail(function (error) {
            setResult($result, error.message, 'error');
        }).always(function () {
            busy($button, false);
        });
    });

    $(document).on('click', '.wpsd-suggest-sources', function () {
        var $button = $(this);
        var postId = $button.data('post-id');
        var $target = $button.siblings('.wpsd-suggestions');

        busy($button, true);
        post('link_suggestions', { post_id: postId, direction: 'sources' }).then(function (data) {
            if (!data.suggestions.length) {
                $target.html('<p class="wpsd-muted">No relevant source pages found.</p>');
                return;
            }

            var $list = $('<ul/>');
            data.suggestions.forEach(function (suggestion) {
                var $item = $('<li/>');
                $('<a/>', { href: suggestion.edit_url, text: suggestion.title }).appendTo($item);
                $('<input/>', {
                    type: 'text',
                    'class': 'wpsd-anchor-input',
                    value: suggestion.anchor
                }).appendTo($item);
                $('<button/>', {
                    type: 'button',
                    'class': 'button button-small wpsd-insert-link',
                    text: 'Insert link',
                    'data-source': suggestion.id,
                    'data-target': postId
                }).appendTo($item);
                $list.append($item);
            });

            $target.empty().append($list);
        }).fail(function (error) {
            $target.html('<p class="wpsd-inline-result is-error"></p>').find('p').text(error.message);
        }).always(function () {
            busy($button, false);
        });
    });

    $(document).on('click', '.wpsd-insert-link', function () {
        var $button = $(this);
        var $anchorField = $button.closest('li, tr').find('.wpsd-anchor-input');
        var anchor = $anchorField.val();

        if (!anchor) {
            return;
        }

        busy($button, true);
        post('insert_link', {
            source_id: $button.data('source'),
            target_id: $button.data('target'),
            anchor: anchor
        }).then(function (data) {
            $button.replaceWith($('<span class="wpsd-inline-result is-success"/>').text(data.message));
        }).fail(function (error) {
            var $note = $button.siblings('.wpsd-inline-result');
            if (!$note.length) {
                $note = $('<span class="wpsd-inline-result"/>').insertAfter($button);
            }
            setResult($note, error.message, 'error');
            busy($button, false);
        });
    });

    // ── 404 monitor ───────────────────────────────────────────────────────

    $(document).on('change', '#wpsd-select-all-404', function () {
        $('.wpsd-404-check').prop('checked', $(this).is(':checked'));
    });

    $(document).on('click', '.wpsd-404-bulk', function () {
        var ids = collectChecked('.wpsd-404-check');
        var $result = $('#wpsd-404-bulk-result');
        var action = $(this).data('action');

        if (!ids.length) {
            setResult($result, 'Select at least one URL.', 'error');
            return;
        }
        if (action === 'delete' && !window.confirm(i18n.confirmDelete)) {
            return;
        }

        var $button = $(this);
        busy($button, true);

        post('notfound_action', { ids: ids, notfound_action: action }).then(function (data) {
            setResult($result, data.message, 'success');
            window.setTimeout(function () {
                window.location.reload();
            }, 700);
        }).fail(function (error) {
            setResult($result, error.message, 'error');
        }).always(function () {
            busy($button, false);
        });
    });

    $(document).on('click', '.wpsd-404-suggest', function () {
        var $button = $(this);
        var $row = $button.closest('tr');
        var id = $row.data('notfound-id');
        var $target = $row.find('.wpsd-suggestions');

        busy($button, true);
        post('notfound_suggest', { id: id }).then(function (data) {
            if (!data.suggestions.length) {
                $target.html('<p class="wpsd-muted">No obvious match. Create a redirect manually.</p>');
                return;
            }

            var $list = $('<ul/>');
            data.suggestions.forEach(function (suggestion) {
                var $item = $('<li/>');
                $('<span/>', { text: suggestion.title }).appendTo($item);
                $('<span class="wpsd-ai-option__meta"/>').text(suggestion.reason).appendTo($item);
                $('<button/>', {
                    type: 'button',
                    'class': 'button button-small wpsd-404-redirect',
                    text: 'Redirect here (301)',
                    'data-id': id,
                    'data-target': suggestion.url
                }).appendTo($item);
                $list.append($item);
            });

            $target.empty().append($list);
        }).fail(function (error) {
            $target.html('<p class="wpsd-inline-result is-error"></p>').find('p').text(error.message);
        }).always(function () {
            busy($button, false);
        });
    });

    $(document).on('click', '.wpsd-404-redirect', function () {
        var $button = $(this);

        busy($button, true);
        post('notfound_action', {
            ids: [$button.data('id')],
            notfound_action: 'redirect',
            target: $button.data('target'),
            code: 301
        }).then(function (data) {
            $button.closest('.wpsd-suggestions')
                .html($('<p class="wpsd-inline-result is-success"/>').text(data.message));
        }).fail(function (error) {
            setResult($button.siblings('.wpsd-inline-result'), error.message, 'error');
            busy($button, false);
        });
    });

    // ── Redirects ─────────────────────────────────────────────────────────

    $(document).on('change', '#wpsd-select-all-redirects', function () {
        $('.wpsd-redirect-check').prop('checked', $(this).is(':checked'));
    });

    $(document).on('click', '.wpsd-redirect-bulk', function () {
        var ids = collectChecked('.wpsd-redirect-check');
        var $result = $('#wpsd-redirect-bulk-result');
        var action = $(this).data('action');

        if (!ids.length) {
            setResult($result, 'Select at least one redirect.', 'error');
            return;
        }
        if (action === 'delete' && !window.confirm(i18n.confirmDelete)) {
            return;
        }

        var $button = $(this);
        busy($button, true);

        post('redirect_action', { ids: ids, redirect_action: action }).then(function (data) {
            setResult($result, data.message, 'success');
            window.setTimeout(function () {
                window.location.reload();
            }, 700);
        }).fail(function (error) {
            setResult($result, error.message, 'error');
        }).always(function () {
            busy($button, false);
        });
    });

    $(document).on('click', '#wpsd-test-redirect', function () {
        var $button = $(this);
        var $result = $('#wpsd-test-result');

        busy($button, true);
        post('test_redirect', {
            source: $('#wpsd-source').val(),
            target: $('#wpsd-target').val(),
            match_type: $('#wpsd-match').val(),
            sample: $('#wpsd-test-sample').val()
        }).then(function (data) {
            if (data.error) {
                setResult($result, data.error, 'error');
            } else if (data.matches) {
                setResult($result, 'Match — would redirect to ' + data.target, 'success');
            } else {
                setResult($result, 'No match for that sample URL.', 'error');
            }
        }).fail(function (error) {
            setResult($result, error.message, 'error');
        }).always(function () {
            busy($button, false);
        });
    });

    $(document).on('click', '#wpsd-flatten-redirects', function () {
        var $button = $(this);
        var $result = $('#wpsd-quickfix-result');

        busy($button, true);
        post('flatten_redirects', {}).then(function (data) {
            setResult($result, data.message, 'success');
            window.setTimeout(function () {
                window.location.reload();
            }, 900);
        }).fail(function (error) {
            setResult($result, error.message, 'error');
        }).always(function () {
            busy($button, false);
        });
    });

    // ── Affiliate ─────────────────────────────────────────────────────────

    $(document).on('click', '#wpsd-retag-affiliate', function () {
        var $button = $(this);
        var $result = $('#wpsd-quickfix-result');

        busy($button, true);
        post('retag_affiliate', {}).then(function (data) {
            setResult($result, data.message, 'success');
        }).fail(function (error) {
            setResult($result, error.message, 'error');
        }).always(function () {
            busy($button, false);
        });
    });

    // ── Search Console ────────────────────────────────────────────────────

    $(document).on('click', '#wpsd-gsc-sync', function () {
        var $button = $(this);
        var $result = $('#wpsd-gsc-result');

        busy($button, true);
        setResult($result, 'Syncing — this can take a minute for large properties…');

        post('gsc_sync', {}).then(function (data) {
            setResult($result, data.message, 'success');
            window.setTimeout(function () {
                window.location.reload();
            }, 1200);
        }).fail(function (error) {
            setResult($result, error.message, 'error');
        }).always(function () {
            busy($button, false);
        });
    });

    $(document).on('click', '#wpsd-gsc-properties', function () {
        var $button = $(this);
        var $list = $('#wpsd-gsc-properties-list');

        busy($button, true);
        post('gsc_properties', {}).then(function (data) {
            if (!data.properties.length) {
                $list.html('<p class="wpsd-muted">No verified properties on this account.</p>');
                return;
            }

            var $ul = $('<ul class="wpsd-suggestions"/>');
            data.properties.forEach(function (property) {
                $('<li/>').append($('<code/>').text(property)).appendTo($ul);
            });
            $list.empty()
                .append('<p class="wpsd-muted">Copy the property you want into Settings → Search Console:</p>')
                .append($ul);
        }).fail(function (error) {
            $list.html('<p class="wpsd-inline-result is-error"></p>').find('p').text(error.message);
        }).always(function () {
            busy($button, false);
        });
    });

    // ── AI ────────────────────────────────────────────────────────────────

    function renderAI(task, result, $target) {
        $target.empty();

        function block(html) {
            return $('<div class="wpsd-ai-block"/>').append(html);
        }

        function option(text, field, postId, meta) {
            var $row = $('<div class="wpsd-ai-option"/>');
            $('<span class="wpsd-ai-option__text"/>').text(text).appendTo($row);
            if (meta) {
                $('<span class="wpsd-ai-option__meta"/>').text(meta).appendTo($row);
            }
            if (field && postId) {
                $('<button/>', {
                    type: 'button',
                    'class': 'button button-small wpsd-ai-apply',
                    text: 'Use this',
                    'data-field': field,
                    'data-post-id': postId,
                    'data-value': text
                }).appendTo($row);
            }
            return $row;
        }

        var postId = $('#wpsd-ai-post').val();

        switch (task) {
            case 'titles':
                result.forEach(function (title) {
                    $target.append(option(title, 'title', postId, title.length + ' chars'));
                });
                break;

            case 'descriptions':
                result.forEach(function (description) {
                    $target.append(option(description, 'description', postId, description.length + ' chars'));
                });
                break;

            case 'anchor_text':
                result.forEach(function (anchor) {
                    $target.append(option(anchor));
                });
                break;

            case 'alt_text':
                result.forEach(function (item) {
                    var $row = $('<div class="wpsd-ai-option"/>');
                    $('<span class="wpsd-ai-option__text"/>').text(item.alt).appendTo($row);
                    $('<span class="wpsd-ai-option__meta"/>').text(item.src.split('/').pop()).appendTo($row);
                    $('<button/>', {
                        type: 'button',
                        'class': 'button button-small wpsd-ai-apply',
                        text: 'Apply',
                        'data-field': 'alt',
                        'data-image': item.src,
                        'data-value': item.alt
                    }).appendTo($row);
                    $target.append($row);
                });
                break;

            case 'internal_links':
                result.forEach(function (link) {
                    var $row = $('<div class="wpsd-ai-option"/>');
                    $('<span class="wpsd-ai-option__text"/>').text(link.title + ' — “' + link.anchor + '”').appendTo($row);
                    $('<span class="wpsd-ai-option__meta"/>').text(link.reason).appendTo($row);
                    $('<input/>', { type: 'hidden', 'class': 'wpsd-anchor-input', value: link.anchor }).appendTo($row);
                    $('<button/>', {
                        type: 'button',
                        'class': 'button button-small wpsd-insert-link',
                        text: 'Insert',
                        'data-source': postId,
                        'data-target': link.target_id
                    }).appendTo($row);
                    $target.append($row);
                });
                break;

            case 'content':
                result.forEach(function (item) {
                    $target.append(block(
                        $('<div/>')
                            .append($('<strong/>').text(item.area + ' '))
                            .append($('<span class="wpsd-badge wpsd-badge--' + item.priority + '"/>').text(item.priority))
                            .append($('<p/>').text(item.suggestion))
                    ));
                });
                break;

            case 'readiness':
                $target.append(block(
                    $('<div/>')
                        .append($('<strong/>').text('Score: ' + result.score + '/100'))
                        .append($('<p/>').text(result.verdict))
                        .append(list('Strengths', result.strengths))
                        .append(list('Gaps', result.gaps))
                        .append(list('Unanswered questions', result.questions))
                ));
                break;

            case 'action_plan':
                $target.append(block($('<p/>').text(result.summary)));
                result.actions.forEach(function (action, index) {
                    $target.append(block(
                        $('<div/>')
                            .append($('<strong/>').text((index + 1) + '. ' + action.title))
                            .append($('<p/>').text(action.why))
                            .append($('<p/>').text(action.how))
                            .append($('<span class="wpsd-ai-option__meta"/>').text(
                                'Effort: ' + action.effort + ' · Impact: ' + action.impact
                            ))
                    ));
                });
                break;

            case 'explain':
                $target.append(block(
                    $('<div/>')
                        .append($('<p/>').text(result.explanation))
                        .append($('<p/>').append($('<em/>').text(result.impact)))
                        .append(list('Steps', result.steps))
                ));
                break;

            default:
                $target.append(block($('<p/>').text(typeof result === 'string' ? result : JSON.stringify(result, null, 2))));
        }

        function list(label, items) {
            if (!items || !items.length) {
                return $();
            }
            var $wrap = $('<div/>').append($('<strong/>').text(label));
            var $ul = $('<ul style="margin:4px 0 8px 18px;list-style:disc"/>');
            items.forEach(function (item) {
                $('<li/>').text(item).appendTo($ul);
            });
            return $wrap.append($ul);
        }
    }

    $(document).on('click', '.wpsd-ai-task', function () {
        var $button = $(this);
        var task = $button.data('task');
        var postId = $button.data('post-id');

        // Buttons in the per-page panel read the shared page selector.
        if (!postId && $button.hasClass('wpsd-ai-task--selected')) {
            postId = $('#wpsd-ai-post').val();
            if (!postId) {
                window.alert('Choose a page first.');
                return;
            }
        }

        var $target = $button.hasClass('wpsd-ai-task--selected')
            ? $('#wpsd-ai-page-output')
            : ($button.closest('td, .wpsd-card').find('.wpsd-ai-output').first());

        $target.html('<span class="wpsd-spinner"></span>' + (i18n.working || 'Working…'));
        busy($button, true);

        post('ai_request', { task: task, post_id: postId }).then(function (data) {
            renderAI(data.task, data.result, $target);
        }).fail(function (error) {
            $target.html('<p class="wpsd-inline-result is-error"></p>').find('p').text(error.message);
        }).always(function () {
            busy($button, false);
        });
    });

    $(document).on('click', '.wpsd-explain-issue', function () {
        var $button = $(this);
        var $row = $button.closest('tr');
        var $target = $row.find('.wpsd-ai-output');

        if (!$target.length) {
            $target = $('<div class="wpsd-ai-output"/>').appendTo($row.find('td').eq(2));
        }

        $target.html('<span class="wpsd-spinner"></span>' + (i18n.working || 'Working…'));
        busy($button, true);

        post('ai_request', { task: 'explain', issue_id: $button.data('issue-id') }).then(function (data) {
            renderAI('explain', data.result, $target);
        }).fail(function (error) {
            $target.html('<p class="wpsd-inline-result is-error"></p>').find('p').text(error.message);
        }).always(function () {
            busy($button, false);
        });
    });

    $(document).on('click', '#wpsd-ai-ask', function () {
        var $button = $(this);
        var $target = $('#wpsd-ai-answer');
        var question = $('#wpsd-ai-question').val();

        if (!question) {
            return;
        }

        $target.html('<span class="wpsd-spinner"></span>' + (i18n.working || 'Working…'));
        busy($button, true);

        post('ai_request', { task: 'ask', question: question }).then(function (data) {
            $target.empty().append($('<div class="wpsd-ai-block"/>').text(data.result));
        }).fail(function (error) {
            $target.html('<p class="wpsd-inline-result is-error"></p>').find('p').text(error.message);
        }).always(function () {
            busy($button, false);
        });
    });

    $(document).on('click', '.wpsd-ai-apply', function () {
        var $button = $(this);

        busy($button, true);
        post('ai_apply', {
            field: $button.data('field'),
            post_id: $button.data('post-id'),
            image: $button.data('image'),
            value: $button.data('value')
        }).then(function (data) {
            $button.replaceWith($('<span class="wpsd-inline-result is-success"/>').text(data.message));
        }).fail(function (error) {
            var $note = $button.siblings('.wpsd-inline-result');
            if (!$note.length) {
                $note = $('<span class="wpsd-inline-result"/>').insertAfter($button);
            }
            setResult($note, error.message, 'error');
            busy($button, false);
        });
    });

    // ── Link map ──────────────────────────────────────────────────────────

    /**
     * Force-directed layout, hand-rolled so the plugin ships no chart library.
     * A handful of iterations is enough to make the clusters legible.
     */
    function renderLinkMap($container, data) {
        var width = $container.width();
        var height = $container.height();
        var nodes = data.nodes;
        var edges = data.edges;

        if (!nodes.length) {
            $container.html('<div class="wpsd-linkmap__placeholder">No link data yet — rebuild the link graph.</div>');
            return;
        }

        var index = {};
        nodes.forEach(function (node, i) {
            node.x = width / 2 + Math.cos((i / nodes.length) * Math.PI * 2) * (width / 3);
            node.y = height / 2 + Math.sin((i / nodes.length) * Math.PI * 2) * (height / 3);
            node.vx = 0;
            node.vy = 0;
            index[node.id] = node;
        });

        var links = edges.filter(function (edge) {
            return index[edge.source] && index[edge.target];
        });

        for (var step = 0; step < 220; step++) {
            // Repulsion between every pair keeps labels from stacking.
            for (var a = 0; a < nodes.length; a++) {
                for (var b = a + 1; b < nodes.length; b++) {
                    var dx = nodes[b].x - nodes[a].x;
                    var dy = nodes[b].y - nodes[a].y;
                    var distance = Math.sqrt(dx * dx + dy * dy) || 0.01;
                    var force = 900 / (distance * distance);
                    var fx = (dx / distance) * force;
                    var fy = (dy / distance) * force;
                    nodes[a].vx -= fx;
                    nodes[a].vy -= fy;
                    nodes[b].vx += fx;
                    nodes[b].vy += fy;
                }
            }

            // Attraction along edges.
            links.forEach(function (edge) {
                var source = index[edge.source];
                var target = index[edge.target];
                var dx = target.x - source.x;
                var dy = target.y - source.y;
                var distance = Math.sqrt(dx * dx + dy * dy) || 0.01;
                var force = (distance - 90) * 0.008;
                var fx = (dx / distance) * force;
                var fy = (dy / distance) * force;
                source.vx += fx;
                source.vy += fy;
                target.vx -= fx;
                target.vy -= fy;
            });

            nodes.forEach(function (node) {
                node.x = Math.max(30, Math.min(width - 30, node.x + node.vx * 0.5));
                node.y = Math.max(20, Math.min(height - 20, node.y + node.vy * 0.5));
                node.vx *= 0.82;
                node.vy *= 0.82;
            });
        }

        var depthColors = ['#16a34a', '#65a30d', '#ca8a04', '#ea580c', '#dc2626'];
        var svg = ['<svg viewBox="0 0 ' + width + ' ' + height + '" xmlns="http://www.w3.org/2000/svg">'];

        links.forEach(function (edge) {
            var source = index[edge.source];
            var target = index[edge.target];
            svg.push('<line class="wpsd-linkmap__edge" x1="' + source.x.toFixed(1) + '" y1="' + source.y.toFixed(1) +
                '" x2="' + target.x.toFixed(1) + '" y2="' + target.y.toFixed(1) + '" />');
        });

        nodes.forEach(function (node) {
            var radius = Math.min(20, 4 + Math.sqrt(node.incoming) * 2.4);
            var color = node.depth === null || typeof node.depth === 'undefined'
                ? '#94a3b8'
                : depthColors[Math.min(depthColors.length - 1, node.depth)];

            svg.push('<circle class="wpsd-linkmap__node" cx="' + node.x.toFixed(1) + '" cy="' + node.y.toFixed(1) +
                '" r="' + radius.toFixed(1) + '" fill="' + color + '"><title>' +
                escapeXml(node.title) + ' — ' + node.incoming + ' in, ' + node.outgoing + ' out</title></circle>');

            if (radius > 7) {
                svg.push('<text class="wpsd-linkmap__label" x="' + (node.x + radius + 3).toFixed(1) +
                    '" y="' + (node.y + 3).toFixed(1) + '">' + escapeXml(node.title.substring(0, 26)) + '</text>');
            }
        });

        svg.push('</svg>');
        $container.html(svg.join(''));
    }

    function escapeXml(text) {
        return String(text).replace(/[<>&"']/g, function (character) {
            return {
                '<': '&lt;',
                '>': '&gt;',
                '&': '&amp;',
                '"': '&quot;',
                "'": '&apos;'
            }[character];
        });
    }

    $(function () {
        var $map = $('#wpsd-linkmap');
        if (!$map.length) {
            return;
        }

        $map.html('<div class="wpsd-linkmap__placeholder">' + ($map.data('loading') || 'Loading…') + '</div>');

        post('link_map', {}).then(function (data) {
            renderLinkMap($map, data);
        }).fail(function (error) {
            $map.html('<div class="wpsd-linkmap__placeholder"></div>').find('div').text(error.message);
        });
    });

}(jQuery));
