<?php defined( 'ABSPATH' ) || exit;

// ── Handle "Process Now" (called via normal POST when JS is unavailable) ─────
if ( isset( $_POST['wnp_process_now'] ) && check_admin_referer( 'wnp_process_now' ) ) {
    WNP_Sender::process_batch();
    $queue       = WNP_Sender::get_queue();
    $log         = array_slice( WNP_Sender::get_log(), 0, 30 );
    $recent_jobs = array_reverse( $queue );
}

// ── Handle "Clear Log" ────────────────────────────────────────────────────────
if ( isset( $_GET['wnp_clear_log'] ) && check_admin_referer( 'wnp_clear_log' ) ) {
    delete_option( 'wnp_send_log' );
    $log = [];
}

// ── Stats ─────────────────────────────────────────────────────────────────────
$completed  = array_filter( $queue, static fn( $j ) => $j['status'] === 'completed' );
$in_queue   = array_filter( $queue, static fn( $j ) => in_array( $j['status'], [ 'pending', 'processing' ], true ) );
$total_sent = array_sum( array_column( array_values( $completed ), 'sent' ) );

// ── Loopback test (cached 1 h) ────────────────────────────────────────────────
$loopback_ok = get_transient( 'wnp_loopback_ok' );
if ( $loopback_ok === false ) {
    $loopback_ok = WNP_Sender::test_loopback() ? '1' : '0';
    set_transient( 'wnp_loopback_ok', $loopback_ok, HOUR_IN_SECONDS );
}
$compose_url = add_query_arg( [ 'page' => 'wp-native-push', 'tab' => 'compose' ], admin_url( 'admin.php' ) );
?>

<?php if ( $loopback_ok === '0' ) : ?>
<div class="notice notice-warning" style="margin:0 0 16px">
    <p>
        <strong><?php esc_html_e( 'Notice:', 'wp-native-push' ); ?></strong>
        <?php esc_html_e( 'Your server cannot make loopback HTTP requests. Notifications will be sent when someone visits your site, or use the "Process Now" button below.', 'wp-native-push' ); ?>
    </p>
</div>
<?php endif; ?>

<!-- Stat Cards -->
<div class="wnp-stat-row">
    <div class="wnp-stat-card">
        <span class="wnp-stat-card__num"><?php echo esc_html( number_format_i18n( $total_subscribers ) ); ?></span>
        <span class="wnp-stat-card__lbl"><?php esc_html_e( 'Total Subscribers', 'wp-native-push' ); ?></span>
    </div>
    <div class="wnp-stat-card">
        <span class="wnp-stat-card__num"><?php echo esc_html( number_format_i18n( $total_sent ) ); ?></span>
        <span class="wnp-stat-card__lbl"><?php esc_html_e( 'Total Pushes Sent', 'wp-native-push' ); ?></span>
    </div>
    <div class="wnp-stat-card">
        <span class="wnp-stat-card__num"><?php echo esc_html( count( $in_queue ) ); ?></span>
        <span class="wnp-stat-card__lbl"><?php esc_html_e( 'Jobs in Queue', 'wp-native-push' ); ?></span>
    </div>
    <div class="wnp-stat-card wnp-stat-card--cta">
        <a href="<?php echo esc_url( $compose_url ); ?>" class="button button-primary button-hero">
            <?php esc_html_e( '+ Send Notification', 'wp-native-push' ); ?>
        </a>
    </div>
</div>

<!-- Process Now bar -->
<?php if ( count( $in_queue ) > 0 ) : ?>
<div class="wnp-process-bar">
    <span class="wnp-process-bar__msg">
        <?php printf(
            esc_html( _n( '%d job pending.', '%d jobs pending.', count( $in_queue ), 'wp-native-push' ) ),
            count( $in_queue )
        ); ?>
        <?php esc_html_e( 'If notifications are not arriving, click Process Now.', 'wp-native-push' ); ?>
    </span>
    <button type="button" id="wnp-process-now-btn" class="button button-primary" style="margin-left:12px">
        ⚡ <?php esc_html_e( 'Process Now', 'wp-native-push' ); ?>
    </button>
</div>
<?php endif; ?>

<!-- Recent Jobs -->
<?php if ( ! empty( $recent_jobs ) ) : ?>
<h2><?php esc_html_e( 'Recent Jobs', 'wp-native-push' ); ?></h2>
<table class="wp-list-table widefat fixed striped wnp-table">
    <thead>
        <tr>
            <th><?php esc_html_e( 'Title',  'wp-native-push' ); ?></th>
            <th><?php esc_html_e( 'Queued', 'wp-native-push' ); ?></th>
            <th><?php esc_html_e( 'Status', 'wp-native-push' ); ?></th>
            <th><?php esc_html_e( 'Sent',   'wp-native-push' ); ?></th>
            <th><?php esc_html_e( 'Failed', 'wp-native-push' ); ?></th>
            <th><?php esc_html_e( 'Total',  'wp-native-push' ); ?></th>
            <th><?php esc_html_e( 'Action', 'wp-native-push' ); ?></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ( array_slice( $recent_jobs, 0, 15 ) as $job ) : ?>
        <tr>
            <td><?php echo esc_html( $job['notification']['title'] ); ?></td>
            <td><?php echo esc_html( date_i18n( 'Y-m-d H:i', $job['queued_at'] ) ); ?></td>
            <td><span class="wnp-badge wnp-badge--<?php echo esc_attr( $job['status'] ); ?>"><?php echo esc_html( ucfirst( $job['status'] ) ); ?></span></td>
            <td><?php echo esc_html( $job['sent'] ); ?></td>
            <td><?php echo esc_html( $job['failed'] ); ?></td>
            <td><?php echo esc_html( $job['total'] ); ?></td>
            <td>
                <?php if ( in_array( $job['status'], [ 'pending', 'processing' ], true ) ) : ?>
                    <form method="post" style="display:inline">
                        <?php wp_nonce_field( 'wnp_process_now' ); ?>
                        <button type="submit" name="wnp_process_now" value="1" class="button button-small">
                            <?php esc_html_e( 'Process', 'wp-native-push' ); ?>
                        </button>
                    </form>
                <?php else : ?>
                    <span style="color:#646970">—</span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<!-- Activity Log -->
<?php if ( ! empty( $log ) ) : ?>
<h2 style="margin-top:28px"><?php esc_html_e( 'Activity Log', 'wp-native-push' ); ?></h2>
<div class="wnp-log">
    <?php foreach ( $log as $line ) : ?>
        <div class="wnp-log__line"><?php echo esc_html( $line ); ?></div>
    <?php endforeach; ?>
</div>
<p style="margin-top:6px">
    <button type="button" id="wnp-clear-log-btn" class="button button-small">
        <?php esc_html_e( 'Clear Log', 'wp-native-push' ); ?>
    </button>
</p>
<?php endif; ?>
