<?php
defined('ABSPATH') || exit;

class Sinemagor_Post_Health {

    public static function init(): void {
        add_action('wp_ajax_sg_health_scan',   [self::class, 'ajax_scan']);
        add_action('wp_ajax_sg_health_fix_one',[self::class, 'ajax_fix_one']);
    }

    /**
     * Scan all published Sinemagor posts and return health report.
     */
    public static function scan(): array {
        $posts = get_posts([
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_query'     => [['key' => '_sinemagor_tmdb_id', 'compare' => 'EXISTS']],
        ]);

        $report = [
            'total'   => count($posts),
            'healthy' => 0,
            'issues'  => [],
            'summary' => [
                'no_faq'           => 0,
                'no_internal_links'=> 0,
                'no_wtw'           => 0,
                'no_rating'        => 0,
                'no_verdict'       => 0,
            ],
        ];

        foreach ($posts as $post) {
            $id      = $post->ID;
            $issues  = [];

            // Check FAQ
            if (!get_post_meta($id, '_sinemagor_faqs', true)) {
                $issues[] = 'no_faq';
                $report['summary']['no_faq']++;
            }
            // Check internal links built
            if (!get_post_meta($id, '_sinemagor_links_built', true)) {
                $issues[] = 'no_internal_links';
                $report['summary']['no_internal_links']++;
            }
            // Check Where to Watch
            if (!get_post_meta($id, '_sinemagor_wtw', true)) {
                $issues[] = 'no_wtw';
                $report['summary']['no_wtw']++;
            }
            // Check editor rating
            if (!get_post_meta($id, '_sinemagor_editor_rating', true)) {
                $issues[] = 'no_rating';
                $report['summary']['no_rating']++;
            }
            // Check verdict
            if (!get_post_meta($id, '_sinemagor_verdict', true)) {
                $issues[] = 'no_verdict';
                $report['summary']['no_verdict']++;
            }

            if (empty($issues)) {
                $report['healthy']++;
            } else {
                $report['issues'][] = [
                    'post_id'  => $id,
                    'title'    => $post->post_title,
                    'edit_url' => get_edit_post_link($id, 'raw'),
                    'issues'   => $issues,
                ];
            }
        }

        return $report;
    }

    /**
     * Fix a specific issue for a post.
     */
    public static function fix(int $post_id, string $issue): bool {
        switch ($issue) {
            case 'no_faq':
                $result = Sinemagor_FAQ_Generator::generate($post_id);
                return !is_wp_error($result);

            case 'no_internal_links':
                Sinemagor_Internal_Linker::rebuild($post_id);
                return true;

            case 'no_wtw':
                delete_post_meta($post_id, '_sinemagor_wtw');
                $data = Sinemagor_Where_To_Watch::fetch($post_id);
                return !empty($data);
        }
        return false;
    }

    /**
     * Render the health dashboard HTML (used in Admin tab).
     */
    public static function render(): void {
        $report = self::scan();
        $pct    = $report['total'] > 0
            ? round($report['healthy'] / $report['total'] * 100)
            : 100;

        $color = $pct >= 80 ? '#4caf50' : ($pct >= 50 ? '#ffa726' : '#e53935');
        ?>
        <div class="sg-health-wrap">
            <h2 style="color:#e8b84b">🩺 Post Health Dashboard</h2>

            <!-- Score -->
            <div class="sg-health-score">
                <div class="sg-health-circle" style="--pct:<?php echo esc_attr($pct); ?>;--color:<?php echo esc_attr($color); ?>">
                    <span><?php echo esc_html($pct); ?>%</span>
                </div>
                <div class="sg-health-meta">
                    <div><strong style="color:#e0e0f0"><?php echo esc_html($report['healthy']); ?> / <?php echo esc_html($report['total']); ?></strong> posts fully healthy</div>
                    <div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap">
                        <?php foreach ([
                            'no_faq'           => '❓ No FAQ',
                            'no_internal_links'=> '🔗 No Internal Links',
                            'no_wtw'           => '📺 No Where to Watch',
                            'no_rating'        => '⭐ No Rating',
                            'no_verdict'       => '🏆 No Verdict',
                        ] as $key => $label): ?>
                            <span class="sg-health-badge" style="background:rgba(255,255,255,.06);border:1px solid #2e2e45;padding:4px 10px;border-radius:6px;font-size:.82rem;color:#aaa">
                                <?php echo esc_html($label); ?>: <strong style="color:#e8b84b"><?php echo esc_html($report['summary'][$key]); ?></strong>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Issues table -->
            <?php if (!empty($report['issues'])): ?>
            <table class="sg-table" style="margin-top:20px">
                <thead>
                    <tr>
                        <th>Post</th>
                        <th>Issues</th>
                        <th>Fix</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($report['issues'], 0, 50) as $row): ?>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url($row['edit_url']); ?>" target="_blank" class="sg-link">
                                <?php echo esc_html($row['title']); ?>
                            </a>
                        </td>
                        <td>
                            <?php foreach ($row['issues'] as $iss): ?>
                                <span style="display:inline-block;background:rgba(229,57,53,.15);color:#ef9a9a;font-size:.75rem;padding:2px 7px;border-radius:4px;margin:2px">
                                    <?php echo esc_html(str_replace('no_', '', $iss)); ?>
                                </span>
                            <?php endforeach; ?>
                        </td>
                        <td>
                            <?php foreach ($row['issues'] as $iss):
                                if (!in_array($iss, ['no_faq','no_internal_links','no_wtw'], true)) continue; ?>
                                <button class="sg-btn sg-btn--ghost sg-btn--sm sg-fix-btn"
                                    data-post="<?php echo esc_attr($row['post_id']); ?>"
                                    data-issue="<?php echo esc_attr($iss); ?>">
                                    Fix <?php echo esc_html(str_replace('no_', '', $iss)); ?>
                                </button>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <p style="color:#4caf50;margin-top:20px">✅ All posts are healthy!</p>
            <?php endif; ?>
        </div>

        <style>
        .sg-health-score{display:flex;gap:24px;align-items:center;background:#1a1a28;border:1px solid #2e2e45;border-radius:12px;padding:20px;margin-bottom:16px;flex-wrap:wrap}
        .sg-health-circle{width:90px;height:90px;border-radius:50%;background:conic-gradient(var(--color) calc(var(--pct)*1%),#2e2e45 0);display:flex;align-items:center;justify-content:center;flex-shrink:0;position:relative}
        .sg-health-circle::before{content:'';position:absolute;width:70px;height:70px;border-radius:50%;background:#1a1a28}
        .sg-health-circle span{position:relative;z-index:1;font-weight:800;font-size:1.1rem;color:#e0e0f0}
        </style>

        <script>
        document.querySelectorAll('.sg-fix-btn').forEach(function(btn){
            btn.addEventListener('click',function(){
                var postId=this.dataset.post, issue=this.dataset.issue;
                this.textContent='Fixing...'; this.disabled=true;
                var fd=new FormData();
                fd.append('action','sg_health_fix_one');
                fd.append('nonce',sinemagor.nonce);
                fd.append('post_id',postId);
                fd.append('issue',issue);
                fetch(sinemagor.ajax_url,{method:'POST',body:fd})
                    .then(r=>r.json())
                    .then(res=>{
                        this.textContent=res.success?'✅ Fixed':'❌ Failed';
                    });
            });
        });
        </script>
        <?php
    }

    public static function ajax_scan(): void {
        check_ajax_referer('sinemagor_nonce', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('Permission denied.');
        wp_send_json_success(self::scan());
    }

    public static function ajax_fix_one(): void {
        check_ajax_referer('sinemagor_nonce', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('Permission denied.');
        $post_id = (int) ($_POST['post_id'] ?? 0);
        $issue   = sanitize_key($_POST['issue'] ?? '');
        if (!$post_id || !$issue) wp_send_json_error('Invalid params.');
        $ok = self::fix($post_id, $issue);
        $ok ? wp_send_json_success('Fixed.') : wp_send_json_error('Fix failed.');
    }
}
