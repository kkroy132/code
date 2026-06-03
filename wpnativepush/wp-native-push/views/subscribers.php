<?php
defined( 'ABSPATH' ) || exit;

// ── Filters ───────────────────────────────────────────────────────────────────
$f_search  = sanitize_text_field( $_GET['s']       ?? '' );
$f_browser = sanitize_key( $_GET['browser']         ?? 'all' );
$f_device  = sanitize_key( $_GET['device']          ?? 'all' );
$f_days    = absint( $_GET['days']                  ?? 0 );
$f_country = strtoupper( sanitize_key( $_GET['country'] ?? '' ) );
$per_page  = 30;
$cur_page  = max( 1, absint( $_GET['paged'] ?? 1 ) );
$offset    = ( $cur_page - 1 ) * $per_page;

// ── Data ──────────────────────────────────────────────────────────────────────
$stats         = WNP_Subscriber::get_stats();
$country_stats = WNP_Subscriber::get_country_stats();
$result        = WNP_Subscriber::get_filtered( [
    'search'   => $f_search,
    'browser'  => $f_browser,
    'device'   => $f_device,
    'days'     => $f_days,
    'country'  => $f_country,
    'per_page' => $per_page,
    'offset'   => $offset,
] );
$subscribers   = $result['rows'];
$total         = $result['total'];
$total_pages   = (int) ceil( $total / $per_page );

// ── Filter URL helper ─────────────────────────────────────────────────────────
if ( ! function_exists( 'wnp_sub_url' ) ) {
    function wnp_sub_url( array $ov = [] ): string {
        return add_query_arg( array_merge( [
            'page'    => 'wp-native-push',
            'tab'     => 'subscribers',
            's'       => $_GET['s']       ?? '',
            'browser' => $_GET['browser'] ?? '',
            'device'  => $_GET['device']  ?? '',
            'days'    => $_GET['days']    ?? '',
            'country' => $_GET['country'] ?? '',
            'paged'   => 1,
        ], $ov ), admin_url( 'admin.php' ) );
    }
}

$export_url = wp_nonce_url(
    add_query_arg( 'action', 'wnp_export_csv', admin_url( 'admin-post.php' ) ),
    'wnp_export_csv'
);
$browser_labels = [ 'chrome'=>'🟡 Chrome', 'firefox'=>'🦊 Firefox', 'edge'=>'🌐 Edge', 'safari'=>'🧭 Safari', 'other'=>'🌍 Other' ];
$device_labels  = [ 'desktop'=>'🖥 Desktop', 'mobile'=>'📱 Mobile' ];
$date_labels    = [ '0'=>'All Time', '7'=>'Last 7 Days', '30'=>'Last 30 Days', '90'=>'Last 90 Days' ];

// Inline style helpers
$card_style  = 'background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px 20px;display:flex;align-items:center;gap:12px;position:relative;overflow:hidden;';
$num_style   = 'font-size:26px;font-weight:700;color:#1d2327;line-height:1;display:block;';
$lbl_style   = 'font-size:11px;color:#646970;text-transform:uppercase;letter-spacing:.04em;display:block;margin-top:3px;';
$pct_style   = 'font-size:12px;font-weight:700;color:#8c8f94;margin-left:auto;';
$ico_style   = 'font-size:26px;line-height:1;flex-shrink:0;';
$accent_top  = 'position:absolute;top:0;left:0;right:0;height:3px;border-radius:8px 8px 0 0;';
?>

<!-- ══════════ STAT CARDS ══════════ -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin-bottom:20px">

    <div style="<?php echo $card_style; ?>">
        <div style="<?php echo $accent_top; ?>background:#2271b1"></div>
        <span style="<?php echo $ico_style; ?>">👥</span>
        <div><span style="<?php echo $num_style; ?>"><?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?></span>
             <span style="<?php echo $lbl_style; ?>">Total Subscribers</span></div>
    </div>

    <div style="<?php echo $card_style; ?>">
        <div style="<?php echo $accent_top; ?>background:#4f46e5"></div>
        <span style="<?php echo $ico_style; ?>">🖥</span>
        <div><span style="<?php echo $num_style; ?>"><?php echo esc_html( number_format_i18n( $stats['desktop'] ) ); ?></span>
             <span style="<?php echo $lbl_style; ?>">Desktop</span></div>
        <?php if ( $stats['total'] > 0 ) : ?>
        <span style="<?php echo $pct_style; ?>"><?php echo esc_html( round( $stats['desktop'] / $stats['total'] * 100 ) ); ?>%</span>
        <?php endif; ?>
    </div>

    <div style="<?php echo $card_style; ?>">
        <div style="<?php echo $accent_top; ?>background:#0ea5e9"></div>
        <span style="<?php echo $ico_style; ?>">📱</span>
        <div><span style="<?php echo $num_style; ?>"><?php echo esc_html( number_format_i18n( $stats['mobile'] ) ); ?></span>
             <span style="<?php echo $lbl_style; ?>">Mobile</span></div>
        <?php if ( $stats['total'] > 0 ) : ?>
        <span style="<?php echo $pct_style; ?>"><?php echo esc_html( round( $stats['mobile'] / $stats['total'] * 100 ) ); ?>%</span>
        <?php endif; ?>
    </div>

    <div style="<?php echo $card_style; ?>">
        <div style="<?php echo $accent_top; ?>background:#fbbf24"></div>
        <span style="<?php echo $ico_style; ?>">🟡</span>
        <div><span style="<?php echo $num_style; ?>"><?php echo esc_html( number_format_i18n( $stats['chrome'] ) ); ?></span>
             <span style="<?php echo $lbl_style; ?>">Chrome</span></div>
    </div>

    <div style="<?php echo $card_style; ?>">
        <div style="<?php echo $accent_top; ?>background:#f97316"></div>
        <span style="<?php echo $ico_style; ?>">🦊</span>
        <div><span style="<?php echo $num_style; ?>"><?php echo esc_html( number_format_i18n( $stats['firefox'] ) ); ?></span>
             <span style="<?php echo $lbl_style; ?>">Firefox</span></div>
    </div>

    <div style="<?php echo $card_style; ?>">
        <div style="<?php echo $accent_top; ?>background:#0078d4"></div>
        <span style="<?php echo $ico_style; ?>">🌐</span>
        <div><span style="<?php echo $num_style; ?>"><?php echo esc_html( number_format_i18n( $stats['edge'] ) ); ?></span>
             <span style="<?php echo $lbl_style; ?>">Edge</span></div>
    </div>

</div>

<!-- ══════════ GROWTH CHART ══════════ -->
<?php if ( $stats['total'] > 0 ) :
    $max_d = max( array_values( $stats['by_day'] ) ) ?: 1;
?>
<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;margin-bottom:20px;overflow:hidden">
    <div style="padding:12px 16px;border-bottom:1px solid #dcdcde;background:#f6f7f7;display:flex;align-items:center;gap:8px">
        <strong style="font-size:13px">📈 <?php esc_html_e( 'Subscriber Growth — Last 30 Days', 'wp-native-push' ); ?></strong>
    </div>
    <div style="padding:16px 16px 0;height:120px;display:flex;align-items:flex-end;gap:2px;overflow:hidden">
        <?php foreach ( $stats['by_day'] as $day => $cnt ) :
            $h   = $cnt > 0 ? max( 6, (int) round( $cnt / $max_d * 90 ) ) : 2;
            $tip = date_i18n( 'd M', strtotime( $day ) ) . ': ' . $cnt;
        ?>
        <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;gap:2px;cursor:default" title="<?php echo esc_attr( $tip ); ?>">
            <?php if ( $cnt > 0 ) : ?><span style="font-size:9px;color:#2271b1;font-weight:700;line-height:1"><?php echo esc_html( $cnt ); ?></span><?php endif; ?>
            <div style="width:100%;height:<?php echo (int) $h; ?>px;background:<?php echo $cnt > 0 ? '#2271b1' : '#e8e8e8'; ?>;border-radius:2px 2px 0 0;transition:background .15s"></div>
        </div>
        <?php endforeach; ?>
    </div>
    <div style="display:flex;justify-content:space-between;padding:4px 16px 10px;font-size:10px;color:#8c8f94">
        <span><?php echo esc_html( date_i18n( 'd M', strtotime( '-29 days' ) ) ); ?></span>
        <span><?php esc_html_e( 'Today', 'wp-native-push' ); ?></span>
    </div>
</div>
<?php endif; ?>

<!-- ══════════ TOP COUNTRIES ══════════ -->
<?php if ( ! empty( $country_stats ) ) :
    $max_c = max( array_column( $country_stats, 'count' ) ) ?: 1;
?>
<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;margin-bottom:20px;overflow:hidden">
    <div style="padding:11px 16px;border-bottom:1px solid #dcdcde;background:#f6f7f7;display:flex;align-items:center;justify-content:space-between">
        <strong style="font-size:13px">🌍 <?php esc_html_e( 'Top Countries', 'wp-native-push' ); ?></strong>
        <?php if ( $f_country ) : ?>
        <a href="<?php echo esc_url( wnp_sub_url( [ 'country' => '' ] ) ); ?>" class="button button-small">
            ✕ <?php esc_html_e( 'Clear', 'wp-native-push' ); ?>
        </a>
        <?php endif; ?>
    </div>
    <div style="padding:12px 16px;display:flex;flex-direction:column;gap:5px">
        <?php foreach ( $country_stats as $code => $info ) :
            $barpct  = round( $info['count'] / $max_c * 100 );
            $totpct  = $stats['total'] > 0 ? round( $info['count'] / $stats['total'] * 100, 1 ) : 0;
            $is_on   = ( $f_country === $code );
            $row_bg  = $is_on ? 'background:#f0f6fc;outline:2px solid #2271b1;border-radius:6px;' : '';
        ?>
        <a href="<?php echo esc_url( wnp_sub_url( [ 'country' => $is_on ? '' : $code ] ) ); ?>"
           style="display:flex;align-items:center;gap:10px;padding:7px 8px;border-radius:6px;text-decoration:none;color:#1d2327;transition:background .12s;<?php echo $row_bg; ?>"
           onmouseover="this.style.background='#f0f6fc'" onmouseout="this.style.background='<?php echo $is_on ? '#f0f6fc' : 'transparent'; ?>'"
           title="Filter by <?php echo esc_attr( $info['name'] ); ?>">
            <span style="font-size:20px;line-height:1;width:24px;flex-shrink:0"><?php echo esc_html( $info['flag'] ); ?></span>
            <span style="font-size:13px;font-weight:500;width:150px;flex-shrink:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo esc_html( $info['name'] ); ?></span>
            <div style="flex:1;height:6px;background:#f0f0f1;border-radius:3px;overflow:hidden">
                <div style="height:100%;width:<?php echo (int) $barpct; ?>%;background:#2271b1;border-radius:3px"></div>
            </div>
            <span style="font-size:13px;font-weight:700;color:#1d2327;width:32px;text-align:right;flex-shrink:0"><?php echo esc_html( $info['count'] ); ?></span>
            <span style="font-size:11px;color:#8c8f94;width:36px;text-align:right;flex-shrink:0"><?php echo esc_html( $totpct ); ?>%</span>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ══════════ TOOLBAR ══════════ -->
<div style="display:flex;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:16px">

    <!-- Search -->
    <form method="get" style="flex:1;min-width:200px;max-width:320px;position:relative">
        <input type="hidden" name="page"    value="wp-native-push" />
        <input type="hidden" name="tab"     value="subscribers" />
        <?php foreach ( ['browser','device','days','country'] as $k ) :
            $v = sanitize_key( $_GET[$k] ?? '' );
            if ( $v && $v !== 'all' && $v !== '0' ) :
        ?><input type="hidden" name="<?php echo esc_attr($k); ?>" value="<?php echo esc_attr($v); ?>" /><?php
            endif;
        endforeach; ?>
        <span style="position:absolute;left:9px;top:50%;transform:translateY(-50%);opacity:.5;pointer-events:none">🔍</span>
        <input type="text" name="s" class="wnp-inp" style="padding-left:30px;width:100%"
               value="<?php echo esc_attr( $f_search ); ?>"
               placeholder="<?php esc_attr_e( 'Search by browser or endpoint…', 'wp-native-push' ); ?>" />
    </form>

    <!-- Filter dropdowns -->
    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">

        <!-- Browser -->
        <div style="position:relative" id="wnp-dd-browser">
            <button type="button" class="button wnp-filter-btn" data-dd="browser">
                <?php echo $f_browser && $f_browser !== 'all' ? esc_html( $browser_labels[$f_browser] ?? $f_browser ) : '🟡 Browser'; ?> &#9660;
            </button>
            <div class="wnp-dd-menu" style="display:none;position:absolute;top:calc(100% + 4px);left:0;z-index:9999;background:#fff;border:1px solid #dcdcde;border-radius:6px;box-shadow:0 8px 24px rgba(0,0,0,.12);min-width:160px;" id="wnp-ddm-browser">
                <a href="<?php echo esc_url( wnp_sub_url(['browser'=>'all']) ); ?>" style="display:flex;align-items:center;justify-content:space-between;padding:8px 14px;font-size:13px;color:#1d2327;text-decoration:none;border-bottom:1px solid #f0f0f1;background:#f0f6fc;font-weight:600;color:#2271b1;">All Browsers</a>
                <?php foreach ( $browser_labels as $v => $l ) : ?>
                <a href="<?php echo esc_url( wnp_sub_url(['browser'=>$v]) ); ?>" style="display:flex;align-items:center;justify-content:space-between;padding:8px 14px;font-size:13px;color:#1d2327;text-decoration:none;border-bottom:1px solid #f0f0f1;background:#f0f6fc;font-weight:600;color:#2271b1;">
                    <?php echo esc_html( $l ); ?>
                    <?php if ( isset($stats[$v]) ) : ?><span style="background:#f0f0f1;color:#646970;font-size:11px;font-weight:700;padding:1px 6px;border-radius:20px;"><?php echo esc_html($stats[$v]); ?></span><?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Device -->
        <div style="position:relative" id="wnp-dd-device">
            <button type="button" class="button wnp-filter-btn" data-dd="device">
                <?php echo $f_device && $f_device !== 'all' ? esc_html( $device_labels[$f_device] ?? $f_device ) : '🖥 Device'; ?> &#9660;
            </button>
            <div class="wnp-dd-menu" style="display:none;position:absolute;top:calc(100% + 4px);left:0;z-index:9999;background:#fff;border:1px solid #dcdcde;border-radius:6px;box-shadow:0 8px 24px rgba(0,0,0,.12);min-width:160px;" id="wnp-ddm-device">
                <a href="<?php echo esc_url( wnp_sub_url(['device'=>'all']) ); ?>" style="display:flex;align-items:center;justify-content:space-between;padding:8px 14px;font-size:13px;color:#1d2327;text-decoration:none;border-bottom:1px solid #f0f0f1;background:#f0f6fc;font-weight:600;color:#2271b1;">All Devices</a>
                <?php foreach ( $device_labels as $v => $l ) : ?>
                <a href="<?php echo esc_url( wnp_sub_url(['device'=>$v]) ); ?>" style="display:flex;align-items:center;justify-content:space-between;padding:8px 14px;font-size:13px;color:#1d2327;text-decoration:none;border-bottom:1px solid #f0f0f1;background:#f0f6fc;font-weight:600;color:#2271b1;">
                    <?php echo esc_html( $l ); ?>
                    <?php if ( isset($stats[$v]) ) : ?><span style="background:#f0f0f1;color:#646970;font-size:11px;font-weight:700;padding:1px 6px;border-radius:20px;"><?php echo esc_html($stats[$v]); ?></span><?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Date -->
        <div style="position:relative" id="wnp-dd-date">
            <button type="button" class="button wnp-filter-btn" data-dd="date">
                📅 <?php echo esc_html( $date_labels[(string)$f_days] ?? 'All Time' ); ?> &#9660;
            </button>
            <div class="wnp-dd-menu" style="display:none;position:absolute;top:calc(100% + 4px);left:0;z-index:9999;background:#fff;border:1px solid #dcdcde;border-radius:6px;box-shadow:0 8px 24px rgba(0,0,0,.12);min-width:160px;" id="wnp-ddm-date">
                <?php foreach ( $date_labels as $v => $l ) : ?>
                <a href="<?php echo esc_url( wnp_sub_url(['days'=>$v]) ); ?>" style="display:flex;align-items:center;justify-content:space-between;padding:8px 14px;font-size:13px;color:#1d2327;text-decoration:none;border-bottom:1px solid #f0f0f1;background:#f0f6fc;font-weight:600;color:#2271b1;">
                    <?php echo esc_html( $l ); ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Country -->
        <?php if ( ! empty( $country_stats ) ) : ?>
        <div style="position:relative" id="wnp-dd-country">
            <button type="button" class="button wnp-filter-btn" data-dd="country">
                🌍 <?php echo $f_country ? esc_html( WNP_Subscriber::flag_emoji($f_country).' '.WNP_Subscriber::country_name($f_country) ) : 'Country'; ?> &#9660;
            </button>
            <div class="wnp-dd-menu" style="display:none;position:absolute;top:calc(100% + 4px);left:0;z-index:9999;background:#fff;border:1px solid #dcdcde;border-radius:6px;box-shadow:0 8px 24px rgba(0,0,0,.12);min-width:160px;" id="wnp-ddm-country" style="max-height:220px;overflow-y:auto">
                <a href="<?php echo esc_url( wnp_sub_url(['country'=>'']) ); ?>" style="display:flex;align-items:center;justify-content:space-between;padding:8px 14px;font-size:13px;color:#1d2327;text-decoration:none;border-bottom:1px solid #f0f0f1;background:#f0f6fc;font-weight:600;color:#2271b1;">🌍 All Countries</a>
                <?php foreach ( $country_stats as $code => $info ) : ?>
                <a href="<?php echo esc_url( wnp_sub_url(['country'=>$code]) ); ?>" style="display:flex;align-items:center;justify-content:space-between;padding:8px 14px;font-size:13px;color:#1d2327;text-decoration:none;border-bottom:1px solid #f0f0f1;background:#f0f6fc;font-weight:600;color:#2271b1;">
                    <?php echo esc_html( $info['flag'].' '.$info['name'] ); ?>
                    <span style="background:#f0f0f1;color:#646970;font-size:11px;font-weight:700;padding:1px 6px;border-radius:20px;"><?php echo esc_html( $info['count'] ); ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ( $f_search || ($f_browser&&$f_browser!=='all') || ($f_device&&$f_device!=='all') || $f_days || $f_country ) : ?>
        <a href="<?php echo esc_url( wnp_sub_url(['s'=>'','browser'=>'','device'=>'','days'=>'','country'=>'']) ); ?>"
           class="button" style="color:#d63638;border-color:#d63638">
            ✕ <?php esc_html_e( 'Clear', 'wp-native-push' ); ?>
        </a>
        <?php endif; ?>
    </div>

    <!-- Right actions -->
    <div style="margin-left:auto;display:flex;align-items:center;gap:10px">
        <span style="font-size:13px;color:#646970">
            <?php printf( '%s of %s',
                '<strong>'.esc_html(number_format_i18n($total)).'</strong>',
                '<strong>'.esc_html(number_format_i18n($stats['total'])).'</strong>'
            ); ?>
        </span>
        <a href="<?php echo esc_url( $export_url ); ?>" class="button button-secondary">
            📤 <?php esc_html_e( 'Export CSV', 'wp-native-push' ); ?>
        </a>
        <button type="button" id="wnp-bulk-del" class="button"
                style="color:#d63638;border-color:#d63638;opacity:.4;cursor:not-allowed" disabled>
            🗑 <?php esc_html_e( 'Delete Selected', 'wp-native-push' ); ?> (<span id="wnp-sel-count">0</span>)
        </button>
    </div>

</div><!-- toolbar -->

<!-- ══════════ TABLE ══════════ -->
<?php if ( empty( $subscribers ) ) : ?>
<div style="text-align:center;padding:60px 20px;color:#646970">
    <div style="font-size:48px;margin-bottom:12px"><?php echo $f_search||$f_browser!=='all'||$f_device!=='all'||$f_days||$f_country ? '🔍' : '👥'; ?></div>
    <p style="font-size:17px;font-weight:600;color:#1d2327;margin:0 0 8px">
        <?php echo $f_search||$f_browser!=='all'||$f_device!=='all'||$f_days||$f_country
            ? esc_html__( 'No subscribers match your filters.', 'wp-native-push' )
            : esc_html__( 'No subscribers yet.', 'wp-native-push' ); ?>
    </p>
</div>

<?php else : ?>

<table class="wp-list-table widefat fixed striped">
    <thead>
        <tr>
            <th style="width:36px"><input type="checkbox" id="wnp-cb-all" /></th>
            <th style="width:46px">ID</th>
            <th><?php esc_html_e( 'Device &amp; Browser', 'wp-native-push' ); ?></th>
            <th style="width:150px"><?php esc_html_e( 'Country', 'wp-native-push' ); ?></th>
            <th><?php esc_html_e( 'Endpoint', 'wp-native-push' ); ?></th>
            <th style="width:110px"><?php esc_html_e( 'Subscribed', 'wp-native-push' ); ?></th>
            <th style="width:52px"><?php esc_html_e( 'Del', 'wp-native-push' ); ?></th>
        </tr>
    </thead>
    <tbody id="wnp-sub-tbody">
    <?php foreach ( $subscribers as $sub ) :
        $ua   = $sub['user_agent'] ?? '';
        $bkey = WNP_Subscriber::detect_browser( strtolower( $ua ) );
        $dkey = WNP_Subscriber::detect_device( strtolower( $ua ) );
        $bico = WNP_Subscriber::browser_icon( $ua );
        $dico = WNP_Subscriber::device_icon( $ua );

        // Browser/device badge colours
        $bcol = [ 'chrome'=>'#fef9c3;color:#854d0e', 'firefox'=>'#ffedd5;color:#9a3412',
                  'edge'=>'#e0f2fe;color:#0c4a6e', 'safari'=>'#dcfce7;color:#166534', 'other'=>'#f3f4f6;color:#374151' ];
        $dcol = [ 'desktop'=>'#ede9fe;color:#5b21b6', 'mobile'=>'#e0f2fe;color:#0369a1' ];
        $bstyle = 'display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600;background:' . ( $bcol[$bkey] ?? '#f3f4f6;color:#374151' );
        $dstyle = 'display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600;background:' . ( $dcol[$dkey] ?? '#f3f4f6;color:#374151' );

        // Short UA string
        preg_match( '/\(([^)]+)\)/', $ua, $m );
        $ua_short = $m[1] ?? substr( $ua, 0, 55 );

        // Country
        $c_code = strtoupper( $sub['country'] ?? '' );
        $c_name = $sub['country_name'] ?? ( $c_code ? WNP_Subscriber::country_name( $c_code ) : '' );
        $c_flag = $c_code ? WNP_Subscriber::flag_emoji( $c_code ) : '';
    ?>
    <tr id="wnp-row-<?php echo esc_attr( $sub['id'] ); ?>">
        <td><input type="checkbox" class="wnp-cb" value="<?php echo esc_attr( $sub['id'] ); ?>" /></td>
        <td><code><?php echo esc_html( $sub['id'] ); ?></code></td>
        <td>
            <span style="<?php echo $dstyle; ?>"><?php echo esc_html( $dico . ' ' . ucfirst( $dkey ) ); ?></span>
            <span style="<?php echo $bstyle; ?>"><?php echo esc_html( $bico . ' ' . ucfirst( $bkey ) ); ?></span>
            <div style="font-size:11px;color:#8c8f94;margin-top:3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:280px">
                <?php echo esc_html( $ua_short ); ?>
            </div>
        </td>
        <td>
            <?php if ( $c_code ) : ?>
            <a href="<?php echo esc_url( wnp_sub_url( ['country' => $c_code] ) ); ?>"
               style="display:flex;align-items:center;gap:6px;text-decoration:none;color:#1d2327"
               title="Filter by <?php echo esc_attr( $c_name ); ?>">
                <span style="font-size:18px;line-height:1"><?php echo esc_html( $c_flag ); ?></span>
                <span style="font-size:12px;font-weight:500"><?php echo esc_html( $c_name ); ?></span>
            </a>
            <?php else : ?>
            <span style="color:#c3c4c7">—</span>
            <?php endif; ?>
        </td>
        <td>
            <span style="font-family:monospace;font-size:11px;color:#646970" title="<?php echo esc_attr( $sub['endpoint'] ); ?>">
                <?php echo esc_html( substr( $sub['endpoint'], 0, 55 ) . '…' ); ?>
            </span>
        </td>
        <td>
            <span style="font-size:12px;font-weight:500"><?php echo esc_html( date_i18n( 'd M Y', strtotime( $sub['created_at'] ) ) ); ?></span><br>
            <span style="font-size:10px;color:#8c8f94"><?php echo esc_html( human_time_diff( strtotime( $sub['created_at'] ) ) . ' ago' ); ?></span>
        </td>
        <td style="text-align:center">
            <button class="button button-small wnp-del-btn" data-id="<?php echo esc_attr( $sub['id'] ); ?>"
                    style="color:#d63638;padding:2px 6px">🗑</button>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<!-- Pagination -->
<?php if ( $total_pages > 1 ) : ?>
<div style="margin-top:12px;display:flex;align-items:center;justify-content:space-between">
    <div>
        <?php echo paginate_links( [ // phpcs:ignore
            'base'      => wnp_sub_url( [ 'paged' => '%#%' ] ),
            'format'    => '',
            'prev_text' => '&laquo;',
            'next_text' => '&raquo;',
            'total'     => $total_pages,
            'current'   => $cur_page,
        ] ); ?>
    </div>
    <span style="font-size:13px;color:#646970">
        <?php printf( 'Showing %d–%d of %d', $offset + 1, min( $offset + $per_page, $total ), $total ); ?>
    </span>
</div>
<?php endif; ?>

<?php endif; // empty subscribers ?>
