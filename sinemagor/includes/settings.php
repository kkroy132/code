<?php
defined('ABSPATH') || exit;

class Sinemagor_Settings {

    const OPTION = 'sinemagor_settings';

    // Available models on OpenRouter
    const MODELS = [
        '— DeepSeek —'                            => null,
        'deepseek/deepseek-v3.2'                  => 'DeepSeek V3.2 ⭐ Recommended',
        'deepseek/deepseek-chat'                  => 'DeepSeek V3',
        'deepseek/deepseek-r1'                    => 'DeepSeek R1 (reasoning)',
        'deepseek/deepseek-r1-0528'               => 'DeepSeek R1 0528',
        '— Google —'                              => null,
        'google/gemini-2.5-flash-preview'         => 'Gemini 2.5 Flash',
        'google/gemini-2.5-pro-preview'           => 'Gemini 2.5 Pro',
        'google/gemini-flash-1.5'                 => 'Gemini Flash 1.5',
        '— Meta Llama —'                          => null,
        'meta-llama/llama-4-maverick'             => 'Llama 4 Maverick',
        'meta-llama/llama-4-scout'                => 'Llama 4 Scout',
        'meta-llama/llama-3.3-70b-instruct'       => 'Llama 3.3 70B',
        '— Mistral —'                             => null,
        'mistralai/mistral-small-3.2'             => 'Mistral Small 3.2',
        'mistralai/mistral-nemo'                  => 'Mistral Nemo',
        '— Anthropic —'                           => null,
        'anthropic/claude-3.5-haiku'              => 'Claude 3.5 Haiku (fast)',
        'anthropic/claude-sonnet-4-5'             => 'Claude Sonnet 4.5',
        '— OpenAI —'                              => null,
        'openai/gpt-4o-mini'                      => 'GPT-4o Mini',
        'openai/gpt-4.1-nano'                     => 'GPT-4.1 Nano',
    ];

    public static function get(string $key, $default = '') {
        $opts = get_option(self::OPTION, []);
        return $opts[$key] ?? $default;
    }

    public static function save(array $data): void {
        $opts = get_option(self::OPTION, []);
        $opts = array_merge($opts, $data);
        update_option(self::OPTION, $opts);
    }

    public static function register(): void {
        register_setting('sinemagor_settings_group', self::OPTION, [
            'sanitize_callback' => [self::class, 'sanitize'],
        ]);
    }

    public static function sanitize(array $input): array {
        return [
            'tmdb_api_key'       => sanitize_text_field($input['tmdb_api_key']       ?? ''),
            'openrouter_api_key' => sanitize_text_field($input['openrouter_api_key'] ?? ''),
            'ai_model'           => sanitize_text_field($input['ai_model']           ?? 'deepseek/deepseek-chat'),
            'tmdb_image_base'    => 'https://image.tmdb.org/t/p/',
            'bulk_batch_size'    => max(1, min(20, (int) ($input['bulk_batch_size']   ?? 5))),
            'auto_publish'       => isset($input['auto_publish']) ? 1 : 0,
            'post_category'      => (int) ($input['post_category'] ?? 0),
        ];
    }

    /**
     * Render the settings page HTML
     */
    public static function render(): void {
        if (!current_user_can('manage_options')) return;

        if (isset($_POST['sinemagor_save_settings'])) {
            check_admin_referer('sinemagor_settings');
            self::sanitize_and_save($_POST);
            echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
        }

        if (isset($_POST['sinemagor_run_now'])) {
            check_admin_referer('sinemagor_settings');
            Sinemagor_Auto_Pilot::trigger_now();
            echo '<div class="notice notice-success"><p>✅ Auto Pilot run triggered manually.</p></div>';
        }

        if (isset($_POST['sinemagor_clear_log'])) {
            check_admin_referer('sinemagor_settings');
            Sinemagor_Auto_Pilot::clear_log();
            echo '<div class="notice notice-success"><p>Log cleared.</p></div>';
        }

        $tmdb_key  = self::get('tmdb_api_key');
        $or_key    = self::get('openrouter_api_key');
        $model     = self::get('ai_model', 'deepseek/deepseek-v3.2');
        $batch     = self::get('bulk_batch_size', 5);
        $autopub   = self::get('auto_publish', 0);
        $cat       = self::get('post_category', 0);
        $img_base  = self::get('tmdb_image_base', 'https://image.tmdb.org/t/p/');

        // v1.3 settings
        $aff_amazon   = self::get('affiliate_amazon',    '');
        $aff_jw       = self::get('affiliate_justwatch', '');
        $aff_itunes   = self::get('affiliate_itunes',    '');
        $wtw_locale   = self::get('wtw_locale',          'en_US');
        $sitemap_url  = self::get('sitemap_url',         '');
        $cost_alert   = self::get('cost_alert_email',    '');
        $cost_budget  = self::get('cost_budget_usd',     '');

        $ap_enabled   = self::get('autopilot_enabled',   0);
        $ap_feed      = self::get('autopilot_feed',      'trending');
        $ap_per_run   = self::get('autopilot_per_run',   10);
        $ap_min_rating= self::get('autopilot_min_rating',6.0);
        $ap_language  = self::get('autopilot_language',  'en');

        // Auto Pilot status
        $ap_status    = Sinemagor_Auto_Pilot::get_status();
        $ap_next      = Sinemagor_Auto_Pilot::next_run_time();
        $ap_log       = Sinemagor_Auto_Pilot::get_log();
        ?>
        <div class="sg-settings-wrap">
        <h2>⚙️ Sinemagor Settings</h2>
        <form method="post">
            <?php wp_nonce_field('sinemagor_settings'); ?>
            <table class="form-table sg-form-table">
                <tr>
                    <th>TMDB API Key</th>
                    <td>
                        <input type="password" name="tmdb_api_key" value="<?php echo esc_attr($tmdb_key); ?>" class="regular-text" />
                        <p class="description">Get free key at <a href="https://www.themoviedb.org/settings/api" target="_blank">themoviedb.org</a></p>
                    </td>
                </tr>
                <tr>
                    <th>OpenRouter API Key</th>
                    <td>
                        <input type="password" name="openrouter_api_key"
                               value="<?php echo esc_attr($or_key); ?>"
                               class="regular-text" autocomplete="new-password" />
                        <p class="description">Get key at <a href="https://openrouter.ai/keys" target="_blank">openrouter.ai/keys</a> — free tier available, pay-per-token.</p>
                    </td>
                </tr>

                <tr>
                    <th>AI Model</th>
                    <td>
                        <select name="ai_model" id="sg-model-select">
                            <?php foreach (self::MODELS as $val => $label): ?>
                                <?php if ($label === null): ?>
                                    <optgroup label="<?php echo esc_attr(trim($val, '— ')); ?>"></optgroup>
                                <?php else: ?>
                                    <option value="<?php echo esc_attr($val); ?>" <?php selected($model, $val); ?>>
                                        <?php echo esc_html($label); ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <p class="description" style="margin-top:6px">
                            DeepSeek V3 — সবচেয়ে কম cost, ভালো quality। Claude Haiku — fastest।
                        </p>

                        <!-- Test button -->
                        <div style="margin-top:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                            <button type="button" id="sg-test-model-btn" class="button button-secondary">
                                🧪 Test Selected Model
                            </button>
                            <span id="sg-test-status" style="color:#aaa;font-size:.85rem"></span>
                        </div>

                        <!-- Test result preview -->
                        <div id="sg-test-result" style="display:none;margin-top:14px;background:#0f0f17;border:1px solid #2e2e45;border-radius:8px;padding:16px;font-size:.85rem;line-height:1.7;color:#c0c0d8;max-height:320px;overflow-y:auto;white-space:pre-wrap;font-family:monospace"></div>
                    </td>
                </tr>
                <tr>
                    <th>Bulk Batch Size</th>
                    <td>
                        <input type="number" name="bulk_batch_size" value="<?php echo esc_attr($batch); ?>" min="1" max="20" class="small-text" />
                        <p class="description">How many movies to queue per bulk generate (max 20). Processed via WP Cron.</p>
                    </td>
                </tr>
                <tr>
                    <th>TMDB Image Base URL</th>
                    <td>
                        <input type="text" name="tmdb_image_base" value="<?php echo esc_attr($img_base); ?>" class="regular-text" />
                        <p class="description">Do not change unless TMDB updates their CDN. Poster path appended after size (e.g. w500).</p>
                    </td>
                </tr>
                <tr>
                    <th>Post Category</th>
                    <td>
                        <?php wp_dropdown_categories([
                            'name'             => 'post_category',
                            'selected'         => $cat,
                            'show_option_none' => '— Default —',
                            'option_none_value'=> 0,
                        ]); ?>
                    </td>
                </tr>
                <tr>
                    <th>Auto Re-generate After</th>
                    <td>
                        <input type="number" name="regen_days" value="<?php echo esc_attr(Sinemagor_Settings::get('regen_days', 0)); ?>" min="0" max="365" class="small-text" />
                        days &nbsp;
                        <input type="number" name="regen_per_run" value="<?php echo esc_attr(Sinemagor_Settings::get('regen_per_run', 3)); ?>" min="1" max="10" class="small-text" />
                        posts/day
                        <p class="description">Set 0 to disable. Oldest-modified posts get refreshed with new AI content automatically.</p>
                        <p class="description">Next regen run: <strong><?php echo esc_html(Sinemagor_Auto_Regenerate::next_run()); ?></strong></p>
                    </td>
                </tr>
                <tr>
                    <th>Auto Publish</th>
                    <td>
                        <label>
                            <input type="checkbox" name="auto_publish" value="1" <?php checked($autopub, 1); ?> />
                            Publish immediately after AI generation (otherwise saves as Draft)
                        </label>
                    </td>
                </tr>
            </table>

            <!-- ══ Retro Linker Section ══ -->
            <hr style="margin:30px 0;border-color:#2e2e45"/>
            <h2 style="color:#e8b84b">🔄 Retroactive Link Rebuilder</h2>
            <p style="color:#7a7a9a;margin-bottom:16px;font-size:.9rem">
                When a new post is published, old posts with the same cast/director automatically get updated links and categories.
                Also runs nightly at 3 AM.
            </p>

            <?php $retro_progress = Sinemagor_Retro_Linker::get_progress(); ?>

            <!-- Progress Bar -->
            <div id="sg-retro-bar" style="background:#1a1a28;border:1px solid #2e2e45;border-radius:10px;padding:16px 20px;margin-bottom:16px">
                <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:10px">
                    <div>
                        <strong style="color:#e0e0f0" id="sg-retro-status-label">
                            <?php echo $retro_progress['running'] ? '⚡ Running...' : '✅ Ready'; ?>
                        </strong>
                        <span style="color:#7a7a9a;font-size:.85rem;margin-left:10px" id="sg-retro-text">
                            <?php echo $retro_progress['done']; ?> / <?php echo $retro_progress['total']; ?> posts updated
                        </span>
                    </div>
                    <div style="margin-left:auto;display:flex;gap:8px">
                        <button type="button" id="sg-retro-rebuild-btn" class="button button-primary">
                            🔄 Full Rebuild Now
                        </button>
                    </div>
                </div>
                <div style="height:6px;background:#2e2e45;border-radius:3px">
                    <div id="sg-retro-fill" style="height:100%;background:linear-gradient(90deg,#e8b84b,#4caf50);border-radius:3px;width:<?php echo $retro_progress['percent']; ?>%;transition:width .4s"></div>
                </div>
                <?php if ($retro_progress['done_at']): ?>
                <div style="font-size:.78rem;color:#606080;margin-top:6px">
                    Last completed: <?php echo esc_html($retro_progress['done_at']); ?>
                    · Source: <?php echo esc_html($retro_progress['source'] ?: 'manual'); ?>
                </div>
                <?php endif; ?>
            </div>

            <table class="form-table sg-form-table">
                <tr>
                    <th>How it works</th>
                    <td style="color:#7a7a9a;font-size:.88rem;line-height:1.7">
                        <strong style="color:#e0e0f0">On new publish:</strong> Finds posts sharing the same cast/director/genre → queues them → updates categories + links in background.<br>
                        <strong style="color:#e0e0f0">Nightly 3 AM:</strong> Full rebuild of all posts to catch anything missed.<br>
                        <strong style="color:#e0e0f0">Full Rebuild:</strong> Manually trigger a complete rebuild of all <?php echo esc_html(wp_count_posts()->publish ?? 0); ?> posts.
                    </td>
                </tr>
            </table>

            <script>
            jQuery(function($){
                var timer = null;

                function pollRetro() {
                    $.post(sinemagor.ajax_url, {action:'sg_retro_status', nonce:sinemagor.nonce}, function(res){
                        if(!res.success) return;
                        var d = res.data;
                        $('#sg-retro-text').text(d.done + ' / ' + d.total + ' posts updated');
                        $('#sg-retro-fill').css('width', d.percent + '%');
                        if(d.running) {
                            $('#sg-retro-status-label').text('⚡ Running...');
                            if(!timer) timer = setInterval(pollRetro, 3000);
                        } else {
                            $('#sg-retro-status-label').text('✅ Ready');
                            clearInterval(timer); timer = null;
                        }
                    });
                }

                $('#sg-retro-rebuild-btn').on('click', function(){
                    if(!confirm('Rebuild links and categories for ALL published posts? This runs in the background.')) return;
                    $(this).text('Starting...').prop('disabled', true);
                    $.post(sinemagor.ajax_url, {action:'sg_retro_rebuild', nonce:sinemagor.nonce}, function(res){
                        $('#sg-retro-rebuild-btn').text('🔄 Full Rebuild Now').prop('disabled', false);
                        if(res.success) { pollRetro(); timer = setInterval(pollRetro, 3000); }
                    });
                });

                // Auto-poll if running
                <?php if ($retro_progress['running']): ?>
                timer = setInterval(pollRetro, 3000);
                <?php endif; ?>
            });
            </script>

            <!-- ══ Auto Category Section ══ -->
            <hr style="margin:30px 0;border-color:#2e2e45"/>
            <h2 style="color:#e8b84b">🗂 Auto Category Settings</h2>
            <p style="color:#7a7a9a;margin-bottom:16px;font-size:.9rem">
                Automatically create and assign categories for genre, director, cast, and language on post publish.
            </p>

            <?php
            $stats = Sinemagor_Auto_Category::get_stats();
            $ac_genre    = Sinemagor_Settings::get('autocat_genre',         1);
            $ac_director = Sinemagor_Settings::get('autocat_director',      1);
            $ac_cast     = Sinemagor_Settings::get('autocat_cast',          1);
            $ac_language = Sinemagor_Settings::get('autocat_language',      1);
            $ac_limit    = Sinemagor_Settings::get('autocat_cast_limit',    3);
            $ac_link     = Sinemagor_Settings::get('autocat_link_content',  1);
            $ac_genres_l = Sinemagor_Settings::get('autocat_link_genres',   1);
            ?>

            <!-- Stats bar -->
            <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px">
                <?php foreach ([
                    'genre'    => ['🎭', 'Genre'],
                    'director' => ['🎬', 'Director'],
                    'cast'     => ['👤', 'Cast'],
                    'language' => ['🌍', 'Language'],
                ] as $type => [$icon, $label]): ?>
                <div style="background:#1a1a28;border:1px solid #2e2e45;border-radius:8px;padding:10px 16px;min-width:100px;text-align:center">
                    <div style="font-size:1.2rem"><?php echo $icon; ?></div>
                    <div style="font-size:1.1rem;font-weight:700;color:#e8b84b"><?php echo $stats[$type] ?? 0; ?></div>
                    <div style="font-size:.75rem;color:#7a7a9a"><?php echo $label; ?> cats</div>
                </div>
                <?php endforeach; ?>
                <div style="margin-left:auto;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                    <button type="button" id="sg-setup-cats-btn" class="button">🗂 Create Parent Categories</button>
                    <button type="button" id="sg-rebuild-cats-btn" class="button button-primary">🔄 Rebuild All Categories</button>
                </div>
            </div>

            <table class="form-table sg-form-table">
                <tr>
                    <th>Auto Categories</th>
                    <td>
                        <label style="display:block;margin-bottom:8px"><input type="checkbox" name="autocat_genre"    value="1" <?php checked($ac_genre,    1); ?> /> 🎭 Genre categories</label>
                        <label style="display:block;margin-bottom:8px"><input type="checkbox" name="autocat_director" value="1" <?php checked($ac_director, 1); ?> /> 🎬 Director categories</label>
                        <label style="display:block;margin-bottom:8px"><input type="checkbox" name="autocat_cast"     value="1" <?php checked($ac_cast,     1); ?> /> 👤 Cast categories</label>
                        <label style="display:block"><input type="checkbox" name="autocat_language" value="1" <?php checked($ac_language, 1); ?> /> 🌍 Language categories</label>
                    </td>
                </tr>
                <tr>
                    <th>Cast Limit</th>
                    <td>
                        <select name="autocat_cast_limit">
                            <?php foreach ([1,2,3,4,5] as $n): ?>
                                <option value="<?php echo $n; ?>" <?php selected($ac_limit, $n); ?>>Top <?php echo $n; ?> cast member<?php echo $n > 1 ? 's' : ''; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">How many cast members get their own category.</p>
                    </td>
                </tr>
                <tr>
                    <th>Auto Content Linking</th>
                    <td>
                        <label style="display:block;margin-bottom:8px">
                            <input type="checkbox" name="autocat_link_content" value="1" <?php checked($ac_link, 1); ?> />
                            Auto-link actor and director names in post content to their category pages
                        </label>
                        <label style="display:block">
                            <input type="checkbox" name="autocat_link_genres" value="1" <?php checked($ac_genres_l, 1); ?> />
                            Also link genre names (e.g. "Thriller" → /category/sg-thriller/)
                        </label>
                        <p class="description">Each name linked only once per post (first occurrence). Skips headings and existing links.</p>
                    </td>
                </tr>
            </table>

            <script>
            jQuery(function($){
                $('#sg-setup-cats-btn').on('click', function(){
                    $(this).text('Creating...').prop('disabled', true);
                    $.post(sinemagor.ajax_url, {action:'sg_setup_categories', nonce:sinemagor.nonce}, function(res){
                        $('#sg-setup-cats-btn').text('✅ Done').prop('disabled', false);
                    });
                });
                $('#sg-rebuild-cats-btn').on('click', function(){
                    if(!confirm('Rebuild categories for all published posts? This may take a moment.')) return;
                    $(this).text('Rebuilding...').prop('disabled', true);
                    $.post(sinemagor.ajax_url, {action:'sg_rebuild_categories', nonce:sinemagor.nonce}, function(res){
                        if(res.success){
                            $('#sg-rebuild-cats-btn').text('✅ Done — ' + res.data.processed + ' posts processed').prop('disabled', false);
                        }
                    });
                });
            });
            </script>

            <!-- ══ v1.3 Settings ══ -->
            <hr style="margin:30px 0;border-color:#2e2e45"/>
            <h2 style="color:#e8b84b">🔗 Affiliate & Streaming</h2>
            <table class="form-table sg-form-table">
                <tr>
                    <th>Amazon Affiliate Tag</th>
                    <td><input type="text" name="affiliate_amazon" value="<?php echo esc_attr($aff_amazon); ?>" class="regular-text" placeholder="yourname-20" />
                    <p class="description">Injected into Amazon links automatically.</p></td>
                </tr>
                <tr>
                    <th>JustWatch Ref</th>
                    <td><input type="text" name="affiliate_justwatch" value="<?php echo esc_attr($aff_jw); ?>" class="regular-text" placeholder="yoursite" /></td>
                </tr>
                <tr>
                    <th>Apple iTunes AT</th>
                    <td><input type="text" name="affiliate_itunes" value="<?php echo esc_attr($aff_itunes); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th>JustWatch Locale</th>
                    <td>
                        <select name="wtw_locale">
                            <?php foreach (['en_US'=>'English (US)','en_GB'=>'English (UK)','en_AU'=>'English (AU)','de_DE'=>'German','fr_FR'=>'French','hi_IN'=>'Hindi'] as $v=>$l): ?>
                                <option value="<?php echo esc_attr($v); ?>" <?php selected($wtw_locale,$v); ?>><?php echo esc_html($l); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Sitemap URL (override)</th>
                    <td><input type="url" name="sitemap_url" value="<?php echo esc_attr($sitemap_url); ?>" class="regular-text" placeholder="Auto-detected" />
                    <p class="description">Leave blank to auto-detect Yoast/RankMath/WP core sitemap.</p></td>
                </tr>
                <tr>
                    <th>Monthly Budget Alert</th>
                    <td>
                        <input type="email" name="cost_alert_email" value="<?php echo esc_attr($cost_alert); ?>" class="regular-text" placeholder="your@email.com" />
                        <input type="number" name="cost_budget_usd" value="<?php echo esc_attr($cost_budget); ?>" class="small-text" min="0" step="0.5" placeholder="USD" style="margin-left:8px" />
                        <p class="description">Email alert when monthly OpenRouter cost exceeds budget.</p>
                    </td>
                </tr>
            </table>

            <!-- ══ Auto Pilot Section ══ -->
            <hr style="margin:30px 0;border-color:#2e2e45"/>
            <h2 style="color:#e8b84b">🤖 Auto Pilot Settings</h2>
            <p style="color:#7a7a9a;margin-bottom:16px">Fully automatic: TMDB fetch → AI review → Publish. Runs every 6 hours via WP Cron.</p>

            <!-- Status bar -->
            <div style="background:#1a1a28;border:1px solid #2e2e45;border-radius:10px;padding:16px 20px;margin-bottom:20px">
                <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:center">
                    <div>
                        <strong style="color:#aaa;font-size:.8rem;text-transform:uppercase;letter-spacing:1px">Status</strong><br>
                        <span style="color:<?php echo $ap_status['status'] === 'running' ? '#4caf50' : ($ap_status['status'] === 'error' ? '#e53935' : '#e8b84b'); ?>;font-weight:700">
                            <?php echo esc_html(ucfirst($ap_status['status'])); ?>
                        </span>
                    </div>
                    <div>
                        <strong style="color:#aaa;font-size:.8rem;text-transform:uppercase;letter-spacing:1px">Next Run</strong><br>
                        <span style="color:#e0e0f0"><?php echo esc_html($ap_next); ?></span>
                    </div>
                    <div>
                        <strong style="color:#aaa;font-size:.8rem;text-transform:uppercase;letter-spacing:1px">Last Result</strong><br>
                        <span style="color:#aaa;font-size:.85rem"><?php echo esc_html($ap_status['message'] ?: '—'); ?></span>
                    </div>
                    <div style="margin-left:auto">
                        <button type="submit" name="sinemagor_run_now" class="button" style="background:#c2185b;color:#fff;border-color:#c2185b">
                            ▶ Run Now
                        </button>
                    </div>
                </div>
            </div>

            <table class="form-table sg-form-table">
                <tr>
                    <th>Enable Auto Pilot</th>
                    <td>
                        <label>
                            <input type="checkbox" name="autopilot_enabled" value="1" <?php checked($ap_enabled, 1); ?> />
                            Run full pipeline automatically every 6 hours
                        </label>
                    </td>
                </tr>
                <tr>
                    <th>TMDB Feed Source</th>
                    <td>
                        <select name="autopilot_feed">
                            <?php foreach (['trending' => '🔥 Trending (Today)', 'popular' => '⭐ Popular (All Time)', 'top_rated' => '🏆 TMDB Top Rated', 'upcoming' => '📅 Upcoming', 'now_playing' => '🎥 Now Playing'] as $val => $label): ?>
                                <option value="<?php echo esc_attr($val); ?>" <?php selected($ap_feed, $val); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Movies Per Run</th>
                    <td>
                        <input type="number" name="autopilot_per_run" value="<?php echo esc_attr($ap_per_run); ?>" min="1" max="20" class="small-text" />
                        <p class="description">Max 20 per run. Recommended: 5–10 to manage API costs.</p>
                    </td>
                </tr>
                <tr>
                    <th>Minimum TMDB Rating</th>
                    <td>
                        <input type="number" name="autopilot_min_rating" value="<?php echo esc_attr($ap_min_rating); ?>" min="0" max="10" step="0.5" class="small-text" />
                        <p class="description">Skip movies below this rating. Set 0 to import all.</p>
                    </td>
                </tr>
                <tr>
                    <th>Language Filter</th>
                    <td>
                        <select name="autopilot_language">
                            <option value=""   <?php selected($ap_language, '');   ?>>All Languages</option>
                            <option value="en" <?php selected($ap_language, 'en'); ?>>English only</option>
                            <option value="ko" <?php selected($ap_language, 'ko'); ?>>Korean only</option>
                            <option value="ja" <?php selected($ap_language, 'ja'); ?>>Japanese only</option>
                            <option value="hi" <?php selected($ap_language, 'hi'); ?>>Hindi only</option>
                            <option value="fr" <?php selected($ap_language, 'fr'); ?>>French only</option>
                        </select>
                    </td>
                </tr>
            </table>

            <!-- Activity Log -->
            <?php if ($ap_log): ?>
            <div style="margin-top:24px">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
                    <h3 style="margin:0;color:#aaa;font-size:.9rem;text-transform:uppercase;letter-spacing:1px">📋 Activity Log (last 100)</h3>
                    <button type="submit" name="sinemagor_clear_log" class="button button-small">Clear Log</button>
                </div>
                <div style="background:#0f0f17;border:1px solid #2e2e45;border-radius:8px;padding:14px;max-height:280px;overflow-y:auto;font-family:monospace;font-size:.8rem;line-height:1.7">
                    <?php foreach ($ap_log as $entry): ?>
                        <div style="color:<?php
                            $msg = $entry['msg'];
                            if (strpos($msg, '✅') !== false || strpos($msg, '✓') !== false) echo '#4caf50';
                            elseif (strpos($msg, '✗') !== false || strpos($msg, '💥') !== false || strpos($msg, 'error') !== false) echo '#e57373';
                            elseif (strpos($msg, '⚠') !== false) echo '#ffa726';
                            elseif (strpos($msg, '🚀') !== false) echo '#e8b84b';
                            else echo '#9090b0';
                        ?>">
                            <span style="color:#555"><?php echo esc_html($entry['time']); ?></span>
                            <?php echo esc_html($entry['msg']); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- IndexNow API Key (full tab is at ⚡ IndexNow) -->
            <hr style="margin:30px 0;border-color:#2e2e45"/>
            <h2 style="color:#e8b84b">⚡ IndexNow</h2>
            <?php $in_key = Sinemagor_Settings::get('indexnow_key', ''); ?>
            <table class="form-table sg-form-table">
                <tr>
                    <th>API Key</th>
                    <td>
                        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                            <input type="text" name="indexnow_key" id="sg-in-key-field"
                                value="<?php echo esc_attr($in_key); ?>"
                                class="regular-text" placeholder="Generate or paste your key" />
                            <button type="button" id="sg-in-gen-key" class="button">🔑 Generate Key</button>
                        </div>
                        <?php if ($in_key): ?>
                        <p class="description">Key file: <a href="<?php echo esc_url(home_url('/' . $in_key . '.txt')); ?>" target="_blank"><?php echo esc_html(home_url('/' . $in_key . '.txt')); ?></a> — <a href="<?php echo esc_url(admin_url('admin.php?page=sinemagor&tab=indexnow')); ?>">View IndexNow Tab →</a></p>
                        <?php else: ?>
                        <p class="description">Generate a key and save — then use the <a href="<?php echo esc_url(admin_url('admin.php?page=sinemagor&tab=indexnow')); ?>">⚡ IndexNow tab</a> to manage submissions.</p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
            <script>
            jQuery(function($){
                $('#sg-in-gen-key').on('click', function(){
                    var k = '';
                    for(var i=0;i<32;i++) k += '0123456789abcdef'[Math.floor(Math.random()*16)];
                    $('#sg-in-key-field').val(k);
                });
            });
            </script>

            <script>
            jQuery(function($){
                $('#sg-test-model-btn').on('click', function(){
                    var model  = $('#sg-model-select').val();
                    var apiKey = $('input[name="openrouter_api_key"]').val();

                    if (!apiKey) {
                        $('#sg-test-status').css('color','#e57373').text('⚠ API key is empty — enter your OpenRouter key above.');
                        return;
                    }
                    if (!model) {
                        $('#sg-test-status').css('color','#e57373').text('⚠ Please select a model.');
                        return;
                    }

                    $(this).prop('disabled', true);
                    $('#sg-test-status').css('color','#e8b84b').text('⏳ Sending test request...');
                    $('#sg-test-result').hide().text('');

                    $.post(sinemagor.ajax_url, {
                        action:  'sg_test_model',
                        nonce:   sinemagor.nonce,
                        model:   model,
                        api_key: apiKey,
                    }, function(res) {
                        $('#sg-test-model-btn').prop('disabled', false);
                        if (res.success) {
                            $('#sg-test-status').css('color','#4caf50').text(
                                '✅ ' + res.data.model + ' — ' + res.data.tokens + ' tokens, ~$' + res.data.cost
                            );
                            $('#sg-test-result').text(res.data.content).show();
                        } else {
                            $('#sg-test-status').css('color','#e57373').text('❌ ' + (res.data || 'Unknown error'));
                        }
                    }).fail(function(){
                        $('#sg-test-model-btn').prop('disabled', false);
                        $('#sg-test-status').css('color','#e57373').text('❌ Request failed.');
                    });
                });
            });
            </script>

            <p class="submit">
                <button type="submit" name="sinemagor_save_settings" class="button button-primary">Save Settings</button>
            </p>
        </form>
        </div>
        <?php
    }

    private static function sanitize_and_save(array $post): void {
        self::save([
            'tmdb_api_key'        => sanitize_text_field($post['tmdb_api_key']        ?? ''),
            'openrouter_api_key'  => sanitize_text_field($post['openrouter_api_key']  ?? ''),
            'ai_model'            => sanitize_text_field($post['ai_model']            ?? 'deepseek/deepseek-v3.2'),
            'tmdb_image_base'     => sanitize_url($post['tmdb_image_base']            ?? 'https://image.tmdb.org/t/p/'),
            'bulk_batch_size'     => max(1, min(20, (int) ($post['bulk_batch_size']   ?? 5))),
            'auto_publish'        => isset($post['auto_publish'])       ? 1 : 0,
            'post_category'       => (int) ($post['post_category']      ?? 0),
            // Auto Category
            'autocat_genre'         => isset($post['autocat_genre'])         ? 1 : 0,
            'autocat_director'      => isset($post['autocat_director'])      ? 1 : 0,
            'autocat_cast'          => isset($post['autocat_cast'])          ? 1 : 0,
            'autocat_language'      => isset($post['autocat_language'])      ? 1 : 0,
            'autocat_cast_limit'    => max(1, min(5, (int) ($post['autocat_cast_limit']  ?? 3))),
            'autocat_link_content'  => isset($post['autocat_link_content'])  ? 1 : 0,
            'autocat_link_genres'   => isset($post['autocat_link_genres'])   ? 1 : 0,
            'autopilot_enabled'   => isset($post['autopilot_enabled'])  ? 1 : 0,
            'autopilot_feed'      => sanitize_key($post['autopilot_feed']             ?? 'trending'),
            'autopilot_per_run'   => max(1, min(20, (int) ($post['autopilot_per_run'] ?? 10))),
            'autopilot_min_rating'=> max(0, min(10, (float) ($post['autopilot_min_rating'] ?? 0))),
            'autopilot_language'  => sanitize_text_field($post['autopilot_language']  ?? ''),
            // Auto Regenerate
            'regen_days'          => max(0, (int) ($post['regen_days']     ?? 0)),
            'regen_per_run'       => max(1, min(10, (int) ($post['regen_per_run'] ?? 3))),
            // IndexNow
            'indexnow_key'        => sanitize_text_field($post['indexnow_key'] ?? ''),
            // Affiliate & Streaming
            'affiliate_amazon'    => sanitize_text_field($post['affiliate_amazon']    ?? ''),
            'affiliate_justwatch' => sanitize_text_field($post['affiliate_justwatch'] ?? ''),
            'affiliate_itunes'    => sanitize_text_field($post['affiliate_itunes']    ?? ''),
            'wtw_locale'          => sanitize_text_field($post['wtw_locale']          ?? 'en_US'),
            'sitemap_url'         => sanitize_url($post['sitemap_url']                ?? ''),
            // Cost alert
            'cost_alert_email'    => sanitize_email($post['cost_alert_email']         ?? ''),
            'cost_budget_usd'     => max(0, (float) ($post['cost_budget_usd']         ?? 0)),
        ]);
    }
}
