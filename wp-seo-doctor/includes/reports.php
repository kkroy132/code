<?php
/**
 * Report builders.
 *
 * Each builder returns a normalised structure — title, meta, columns, rows —
 * that the admin screens render as tables and the exporters turn into CSV or
 * PDF without knowing anything about the underlying module.
 *
 * @package WP_SEO_Doctor
 */

defined('ABSPATH') || exit;

class WPSD_Reports {

    public static function init(): void {
        add_action('wpsd_send_email_report', [self::class, 'send_email_report']);
    }

    /**
     * @return array<string,string>
     */
    public static function available(): array {
        return [
            'audit'     => __('SEO Audit Report', 'wp-seo-doctor'),
            'health'    => __('SEO Health Report', 'wp-seo-doctor'),
            'technical' => __('Technical SEO Report', 'wp-seo-doctor'),
            'broken'    => __('Broken Link Report', 'wp-seo-doctor'),
            'internal'  => __('Internal Link Report', 'wp-seo-doctor'),
            'notfound'  => __('404 Report', 'wp-seo-doctor'),
            'redirects' => __('Redirect Report', 'wp-seo-doctor'),
            'content'   => __('Content Report', 'wp-seo-doctor'),
            'trend'     => __('SEO Trend Report', 'wp-seo-doctor'),
        ];
    }

    /**
     * Build a report.
     *
     * @return array{key:string,title:string,generated:string,meta:array<string,mixed>,columns:array<int,string>,rows:array<int,array<int,string>>}
     */
    public static function build(string $key, array $args = []): array {
        $method = 'build_' . str_replace('-', '_', $key);
        if (!method_exists(self::class, $method)) {
            $method = 'build_audit';
            $key    = 'audit';
        }

        $report = call_user_func([self::class, $method], $args);

        return array_merge([
            'key'       => $key,
            'title'     => self::available()[$key] ?? __('SEO Report', 'wp-seo-doctor'),
            'generated' => WPSD_Helpers::now(),
            'site'      => get_bloginfo('name'),
            'url'       => home_url('/'),
            'meta'      => [],
            'columns'   => [],
            'rows'      => [],
        ], $report);
    }

    // ─────────────────────────────────────────────────────────── builders ──

    /**
     * @param array<string,mixed> $args
     */
    private static function build_audit(array $args = []): array {
        $limit  = (int) ($args['limit'] ?? 500);
        $scan   = WPSD_Scanner::latest_completed();
        $score  = WPSD_Score::calculate();
        $result = WPSD_Issues::query([
            'status'   => 'open',
            'per_page' => $limit,
            'orderby'  => 'severity',
        ]);

        $rows = [];
        foreach ($result['rows'] as $issue) {
            $rows[] = [
                WPSD_Helpers::severity_label((string) $issue->severity),
                (string) $issue->title,
                (string) $issue->url,
                (string) $issue->message,
                (string) $issue->recommendation,
            ];
        }

        return [
            'meta' => [
                __('Health score', 'wp-seo-doctor')  => $score['score'] . '/100 (' . $score['label'] . ')',
                __('Pages scanned', 'wp-seo-doctor') => $scan ? (int) $scan->processed : 0,
                __('Last scan', 'wp-seo-doctor')     => $scan ? (string) $scan->finished_at : __('Never', 'wp-seo-doctor'),
                __('Open issues', 'wp-seo-doctor')   => $score['counts']['total'],
                __('Critical', 'wp-seo-doctor')      => $score['counts']['critical'],
                __('High', 'wp-seo-doctor')          => $score['counts']['high'],
                __('Medium', 'wp-seo-doctor')        => $score['counts']['medium'],
                __('Low', 'wp-seo-doctor')           => $score['counts']['low'],
            ],
            'columns' => [
                __('Severity', 'wp-seo-doctor'),
                __('Issue', 'wp-seo-doctor'),
                __('URL', 'wp-seo-doctor'),
                __('Detail', 'wp-seo-doctor'),
                __('Recommendation', 'wp-seo-doctor'),
            ],
            'rows' => $rows,
        ];
    }

    private static function build_health(array $args = []): array {
        $score  = WPSD_Score::calculate();
        $groups = WPSD_Score::by_group();
        $trend  = WPSD_Score::trend_delta(30);

        $rows = [];
        foreach ($groups as $group) {
            $rows[] = [
                (string) $group['label'],
                (string) $group['score'] . '/100',
                (string) $group['issues'],
            ];
        }

        return [
            'meta' => [
                __('Health score', 'wp-seo-doctor') => $score['score'] . '/100 (' . $score['label'] . ')',
                __('Grade', 'wp-seo-doctor')        => $score['grade'],
                __('30-day change', 'wp-seo-doctor') => sprintf('%+d', $trend['delta']),
                __('Passed checks', 'wp-seo-doctor') => $score['passed'],
                __('Open issues', 'wp-seo-doctor')  => $score['counts']['total'],
            ],
            'columns' => [
                __('Area', 'wp-seo-doctor'),
                __('Score', 'wp-seo-doctor'),
                __('Open issues', 'wp-seo-doctor'),
            ],
            'rows' => $rows,
        ];
    }

    private static function build_technical(array $args = []): array {
        $result = WPSD_Issues::query([
            'check_group' => 'technical',
            'status'      => 'open',
            'per_page'    => (int) ($args['limit'] ?? 500),
        ]);

        $rows = [];
        foreach ($result['rows'] as $issue) {
            $rows[] = [
                WPSD_Helpers::severity_label((string) $issue->severity),
                (string) $issue->title,
                (string) $issue->url,
                (string) $issue->message,
            ];
        }

        return [
            'meta' => [
                __('Technical issues', 'wp-seo-doctor') => $result['total'],
                __('HTTPS', 'wp-seo-doctor')            => strpos(home_url('/'), 'https://') === 0 ? __('Yes', 'wp-seo-doctor') : __('No', 'wp-seo-doctor'),
                __('Sitemap', 'wp-seo-doctor')          => (string) get_option('wpsd_sitemap_url', __('Not detected', 'wp-seo-doctor')),
                __('Indexable', 'wp-seo-doctor')        => get_option('blog_public') ? __('Yes', 'wp-seo-doctor') : __('No', 'wp-seo-doctor'),
            ],
            'columns' => [
                __('Severity', 'wp-seo-doctor'),
                __('Issue', 'wp-seo-doctor'),
                __('URL', 'wp-seo-doctor'),
                __('Detail', 'wp-seo-doctor'),
            ],
            'rows' => $rows,
        ];
    }

    private static function build_broken(array $args = []): array {
        $result = WPSD_Broken_Links::query([
            'status'   => 'broken',
            'per_page' => (int) ($args['limit'] ?? 500),
        ]);
        $stats = WPSD_Broken_Links::stats();

        $rows = [];
        foreach ($result['rows'] as $link) {
            $rows[] = [
                (string) $link->target_url,
                (string) ($link->http_status ?: '—'),
                (string) $link->link_type,
                (string) ($link->source_title ?: (string) $link->source_id),
                (string) $link->anchor,
                (string) ($link->last_checked ?: '—'),
            ];
        }

        return [
            'meta' => [
                __('Broken links', 'wp-seo-doctor')          => $stats['broken'],
                __('Broken internal', 'wp-seo-doctor')       => $stats['broken_internal'],
                __('Broken external', 'wp-seo-doctor')       => $stats['broken_external'],
                __('Broken affiliate', 'wp-seo-doctor')      => $stats['broken_affiliate'],
                __('Redirecting links', 'wp-seo-doctor')     => $stats['redirect'],
                __('Links tracked', 'wp-seo-doctor')         => $stats['total'],
            ],
            'columns' => [
                __('URL', 'wp-seo-doctor'),
                __('Status', 'wp-seo-doctor'),
                __('Type', 'wp-seo-doctor'),
                __('Found on', 'wp-seo-doctor'),
                __('Anchor', 'wp-seo-doctor'),
                __('Last checked', 'wp-seo-doctor'),
            ],
            'rows' => $rows,
        ];
    }

    private static function build_internal(array $args = []): array {
        $stats   = WPSD_Internal_Links::stats();
        $orphans = WPSD_Internal_Links::orphan_pages((int) ($args['limit'] ?? 300));
        $weak    = WPSD_Internal_Links::weakly_linked(100);

        $rows = [];
        foreach ($orphans as $orphan) {
            $rows[] = [
                __('Orphan', 'wp-seo-doctor'),
                (string) $orphan->post_title,
                (string) $orphan->url,
                '0',
            ];
        }
        foreach ($weak as $page) {
            $rows[] = [
                __('Weakly linked', 'wp-seo-doctor'),
                (string) $page->post_title,
                (string) $page->url,
                (string) $page->incoming,
            ];
        }

        return [
            'meta' => [
                __('Internal links', 'wp-seo-doctor')      => $stats['internal_links'],
                __('External links', 'wp-seo-doctor')      => $stats['external_links'],
                __('Pages with links', 'wp-seo-doctor')    => $stats['linked_pages'],
                __('Orphan pages', 'wp-seo-doctor')        => $stats['orphans'],
                __('Weakly linked pages', 'wp-seo-doctor') => $stats['weak'],
                __('Average links per page', 'wp-seo-doctor') => $stats['avg_outgoing'],
            ],
            'columns' => [
                __('Problem', 'wp-seo-doctor'),
                __('Page', 'wp-seo-doctor'),
                __('URL', 'wp-seo-doctor'),
                __('Incoming links', 'wp-seo-doctor'),
            ],
            'rows' => $rows,
        ];
    }

    private static function build_notfound(array $args = []): array {
        $result = WPSD_Monitor_404::query([
            'status'   => 'all',
            'per_page' => (int) ($args['limit'] ?? 500),
            'orderby'  => 'hits',
        ]);
        $stats = WPSD_Monitor_404::stats();

        $rows = [];
        foreach ($result['rows'] as $row) {
            $rows[] = [
                (string) $row->url,
                (string) $row->hits,
                (string) $row->first_seen,
                (string) $row->last_seen,
                (string) ($row->referrer ?: '—'),
                (string) $row->status,
            ];
        }

        return [
            'meta' => [
                __('Distinct 404 URLs', 'wp-seo-doctor') => $stats['total'],
                __('Total hits', 'wp-seo-doctor')        => $stats['hits'],
                __('Seen this week', 'wp-seo-doctor')    => $stats['this_week'],
                __('Unresolved', 'wp-seo-doctor')        => $stats['unresolved'],
                __('Redirected', 'wp-seo-doctor')        => $stats['redirected'],
            ],
            'columns' => [
                __('URL', 'wp-seo-doctor'),
                __('Hits', 'wp-seo-doctor'),
                __('First seen', 'wp-seo-doctor'),
                __('Last seen', 'wp-seo-doctor'),
                __('Referrer', 'wp-seo-doctor'),
                __('Status', 'wp-seo-doctor'),
            ],
            'rows' => $rows,
        ];
    }

    private static function build_redirects(array $args = []): array {
        $result = WPSD_Redirects::query(['per_page' => (int) ($args['limit'] ?? 500)]);
        $stats  = WPSD_Redirects::stats();

        $rows = [];
        foreach ($result['rows'] as $rule) {
            $rows[] = [
                (string) $rule->source,
                (string) $rule->target,
                (string) $rule->code,
                (string) $rule->match_type,
                (string) $rule->hits,
                (string) ($rule->last_hit ?: '—'),
                $rule->enabled ? __('Enabled', 'wp-seo-doctor') : __('Disabled', 'wp-seo-doctor'),
            ];
        }

        return [
            'meta' => [
                __('Redirect rules', 'wp-seo-doctor')  => $stats['total'],
                __('Enabled', 'wp-seo-doctor')         => $stats['enabled'],
                __('Regex rules', 'wp-seo-doctor')     => $stats['regex'],
                __('410 Gone rules', 'wp-seo-doctor')  => $stats['gone'],
                __('Total hits', 'wp-seo-doctor')      => $stats['hits'],
                __('Chains detected', 'wp-seo-doctor') => $stats['chains'],
                __('Loops detected', 'wp-seo-doctor')  => $stats['loops'],
            ],
            'columns' => [
                __('Source', 'wp-seo-doctor'),
                __('Target', 'wp-seo-doctor'),
                __('Code', 'wp-seo-doctor'),
                __('Match', 'wp-seo-doctor'),
                __('Hits', 'wp-seo-doctor'),
                __('Last hit', 'wp-seo-doctor'),
                __('State', 'wp-seo-doctor'),
            ],
            'rows' => $rows,
        ];
    }

    private static function build_content(array $args = []): array {
        $limit = (int) ($args['limit'] ?? 200);
        $stats = WPSD_Content_SEO::stats();

        $rows = [];
        foreach (WPSD_Content_SEO::thin_pages($limit) as $page) {
            $rows[] = [
                __('Thin', 'wp-seo-doctor'),
                (string) $page['title'],
                (string) $page['url'],
                (string) $page['words'],
                (string) $page['modified'],
            ];
        }
        foreach (WPSD_Content_SEO::outdated_pages($limit) as $page) {
            $rows[] = [
                __('Outdated', 'wp-seo-doctor'),
                (string) $page['title'],
                (string) $page['url'],
                '—',
                (string) $page['modified'],
            ];
        }
        foreach (WPSD_Content_SEO::decaying_pages(50) as $page) {
            $rows[] = [
                __('Decaying', 'wp-seo-doctor'),
                (string) $page['title'],
                (string) $page['url'],
                sprintf('%s%%', $page['change_percent']),
                (string) $page['modified'],
            ];
        }

        return [
            'meta' => [
                __('Thin pages', 'wp-seo-doctor')        => $stats['thin'],
                __('Outdated pages', 'wp-seo-doctor')    => $stats['outdated'],
                __('Duplicate clusters', 'wp-seo-doctor') => $stats['duplicates'],
                __('Decaying pages', 'wp-seo-doctor')    => $stats['decaying'],
            ],
            'columns' => [
                __('Problem', 'wp-seo-doctor'),
                __('Page', 'wp-seo-doctor'),
                __('URL', 'wp-seo-doctor'),
                __('Words / change', 'wp-seo-doctor'),
                __('Last modified', 'wp-seo-doctor'),
            ],
            'rows' => $rows,
        ];
    }

    private static function build_trend(array $args = []): array {
        $days  = (int) ($args['days'] ?? 90);
        $trend = WPSD_Score::trend($days);
        $delta = WPSD_Score::trend_delta($days);

        $rows = [];
        foreach ($trend as $row) {
            $rows[] = [
                (string) $row->snapshot_date,
                (string) $row->score,
                (string) $row->total_issues,
                (string) $row->critical,
                (string) $row->high,
                (string) $row->broken_links,
                (string) $row->notfound_urls,
                (string) $row->orphan_pages,
            ];
        }

        return [
            'meta' => [
                __('Window', 'wp-seo-doctor')        => sprintf(
                    /* translators: %d: number of days */
                    _n('%d day', '%d days', $days, 'wp-seo-doctor'),
                    $days
                ),
                __('Snapshots', 'wp-seo-doctor')     => count($trend),
                __('Score change', 'wp-seo-doctor')  => sprintf('%+d', $delta['delta']),
                __('Direction', 'wp-seo-doctor')     => $delta['direction'],
            ],
            'columns' => [
                __('Date', 'wp-seo-doctor'),
                __('Score', 'wp-seo-doctor'),
                __('Issues', 'wp-seo-doctor'),
                __('Critical', 'wp-seo-doctor'),
                __('High', 'wp-seo-doctor'),
                __('Broken links', 'wp-seo-doctor'),
                __('404s', 'wp-seo-doctor'),
                __('Orphans', 'wp-seo-doctor'),
            ],
            'rows' => $rows,
        ];
    }

    // ──────────────────────────────────────────────────────────── email ──

    /**
     * Cron handler: email the health summary.
     */
    public static function send_email_report(): void {
        if (!WPSD_Settings::get('email_reports', false)) {
            return;
        }

        $to = (string) WPSD_Settings::get('report_email', '');
        if ($to === '' || !is_email($to)) {
            $to = (string) get_option('admin_email');
        }
        if (!is_email($to)) {
            return;
        }

        $score  = WPSD_Score::calculate();
        $delta  = WPSD_Score::trend_delta(30);
        $groups = WPSD_Issues::fix_first(5);

        $lines = [];
        $lines[] = sprintf(
            /* translators: %s: site name */
            __('SEO health summary for %s', 'wp-seo-doctor'),
            get_bloginfo('name')
        );
        $lines[] = '';
        $lines[] = sprintf(
            /* translators: 1: score, 2: label, 3: signed 30-day change */
            __('Health score: %1$d/100 (%2$s), %3$+d over 30 days', 'wp-seo-doctor'),
            $score['score'],
            $score['label'],
            $delta['delta']
        );
        $lines[] = sprintf(
            /* translators: 1: critical, 2: high, 3: medium, 4: low */
            __('Open issues: %1$d critical, %2$d high, %3$d medium, %4$d low', 'wp-seo-doctor'),
            $score['counts']['critical'],
            $score['counts']['high'],
            $score['counts']['medium'],
            $score['counts']['low']
        );
        $lines[] = sprintf(
            /* translators: 1: broken links, 2: unresolved 404s */
            __('Broken links: %1$d — Unresolved 404s: %2$d', 'wp-seo-doctor'),
            WPSD_Broken_Links::count_broken(),
            WPSD_Monitor_404::count_active()
        );

        if ($groups) {
            $lines[] = '';
            $lines[] = __('Fix these first:', 'wp-seo-doctor');
            foreach ($groups as $group) {
                $lines[] = sprintf(
                    '  • [%s] %s — %d pages',
                    strtoupper($group->severity),
                    $group->title,
                    (int) $group->affected
                );
            }
        }

        $lines[] = '';
        $lines[] = admin_url('admin.php?page=' . WPSD_SLUG);

        wp_mail(
            $to,
            sprintf(
                /* translators: 1: site name, 2: score */
                __('[%1$s] SEO health: %2$d/100', 'wp-seo-doctor'),
                get_bloginfo('name'),
                $score['score']
            ),
            implode("\n", $lines)
        );
    }
}
