<?php
/**
 * Reports screen: scan history plus a single scan report.
 *
 * @package WP_Site_Toolkit
 *
 * @var array $view Screen data.
 */

defined( 'ABSPATH' ) || exit;

$wpstk_requested = (int) WPSTK_Security::get_query_arg( 'scan_id', '0' );
$wpstk_scan      = $wpstk_requested > 0 ? WPSTK_Scan_Store::get( $wpstk_requested ) : $view['latest'];
$wpstk_history   = WPSTK_Scan_Store::get_recent( 30 );
$wpstk_format    = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
$wpstk_modules   = $view['audit']->get_modules();

if ( ! $wpstk_scan ) {
	WPSTK_View::empty_state(
		__( 'No completed audit is stored yet. Run one and the report will appear here.', 'wp-site-toolkit' ),
		__( 'Run an audit', 'wp-site-toolkit' ),
		WPSTK_Admin::page_url( 'wp-site-toolkit-audit' )
	);

	return;
}

$wpstk_checks   = WPSTK_Scan_Store::sort_by_severity( WPSTK_Scan_Store::get_checks( (int) $wpstk_scan['id'] ) );
$wpstk_counts   = array(
	'critical'       => (int) $wpstk_scan['critical_count'],
	'warning'        => (int) $wpstk_scan['warning_count'],
	'recommendation' => (int) $wpstk_scan['recommendation_count'],
	'passed'         => (int) $wpstk_scan['passed_count'],
	'skipped'        => (int) $wpstk_scan['skipped_count'],
);
$wpstk_previous = null;

foreach ( $wpstk_history as $wpstk_row ) {
	if ( (int) $wpstk_row['id'] < (int) $wpstk_scan['id'] ) {
		$wpstk_previous = $wpstk_row;

		break;
	}
}
?>

<div class="wpstk-report-actions wpstk-no-print">
	<a class="button" href="
	<?php
	echo esc_url(
		wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'wpstk_export_csv',
					'scan_id' => (int) $wpstk_scan['id'],
				),
				admin_url( 'admin-post.php' )
			),
			'wpstk_export_csv'
		)
	);
	?>
	"><?php echo esc_html__( 'Export CSV', 'wp-site-toolkit' ); ?></a>

	<button type="button" class="button" data-wpstk-print><?php echo esc_html__( 'Print report', 'wp-site-toolkit' ); ?></button>

	<?php if ( WPSTK_Security::can( 'manage' ) ) : ?>
		<a class="button wpstk-button-danger" data-wpstk-confirm="<?php echo esc_attr__( 'Delete this scan and its findings?', 'wp-site-toolkit' ); ?>" href="
		<?php
		echo esc_url(
			wp_nonce_url(
				add_query_arg(
					array(
						'action'  => 'wpstk_delete_scan',
						'scan_id' => (int) $wpstk_scan['id'],
					),
					admin_url( 'admin-post.php' )
				),
				'wpstk_delete_scan'
			)
		);
		?>
		"><?php echo esc_html__( 'Delete scan', 'wp-site-toolkit' ); ?></a>
	<?php endif; ?>
</div>

<div class="wpstk-card wpstk-report-head">
	<div>
		<h2 class="wpstk-card__title">
			<?php
			printf(
				/* translators: %s: date and time. */
				esc_html__( 'Report from %s', 'wp-site-toolkit' ),
				esc_html( mysql2date( $wpstk_format, $wpstk_scan['finished_at'] ) )
			);
			?>
		</h2>
		<p class="description">
			<?php
			$wpstk_duration = isset( $wpstk_scan['summary']['duration'] ) ? (int) $wpstk_scan['summary']['duration'] : 0;

			printf(
				/* translators: 1: how the scan was started, 2: duration in seconds, 3: number of checks. */
				esc_html__( 'Started %1$s, completed in %2$s seconds, %3$s checks run.', 'wp-site-toolkit' ),
				esc_html( 'cron' === $wpstk_scan['trigger_type'] ? __( 'automatically', 'wp-site-toolkit' ) : __( 'manually', 'wp-site-toolkit' ) ),
				esc_html( number_format_i18n( $wpstk_duration ) ),
				esc_html( number_format_i18n( (int) $wpstk_scan['total_checks'] ) )
			);
			?>
		</p>
	</div>

	<div class="wpstk-report-head__score">
		<?php
		$wpstk_overall = $wpstk_scan['overall_score'];
		$wpstk_grade   = WPSTK_Check::grade( $wpstk_overall );

		WPSTK_View::score_card(
			array(
				'title'    => __( 'Website Health', 'wp-site-toolkit' ),
				'score'    => $wpstk_overall,
				'subtitle' => $wpstk_grade['description'],
				'large'    => true,
			)
		);
		?>
	</div>
</div>

<?php WPSTK_View::summary_tiles( $wpstk_counts ); ?>

<h2 class="wpstk-section-title"><?php echo esc_html__( 'Section scores', 'wp-site-toolkit' ); ?></h2>

<div class="wpstk-table-wrap">
	<table class="wp-list-table widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php echo esc_html__( 'Section', 'wp-site-toolkit' ); ?></th>
				<th scope="col"><?php echo esc_html__( 'Score', 'wp-site-toolkit' ); ?></th>
				<?php if ( $wpstk_previous ) : ?>
					<th scope="col"><?php echo esc_html__( 'Previous', 'wp-site-toolkit' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Change', 'wp-site-toolkit' ); ?></th>
				<?php endif; ?>
				<th scope="col"><?php echo esc_html__( 'Critical', 'wp-site-toolkit' ); ?></th>
				<th scope="col"><?php echo esc_html__( 'Warnings', 'wp-site-toolkit' ); ?></th>
				<th scope="col"><?php echo esc_html__( 'Passed', 'wp-site-toolkit' ); ?></th>
				<th scope="col"><?php echo esc_html__( 'Not checked', 'wp-site-toolkit' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $wpstk_modules as $wpstk_module_id => $wpstk_module ) : ?>
				<?php
				$wpstk_score  = isset( $wpstk_scan['scores'][ $wpstk_module_id ] ) ? $wpstk_scan['scores'][ $wpstk_module_id ] : null;
				$wpstk_before = ( $wpstk_previous && isset( $wpstk_previous['scores'][ $wpstk_module_id ] ) )
					? $wpstk_previous['scores'][ $wpstk_module_id ]
					: null;
				$wpstk_module_counts = isset( $wpstk_scan['summary']['counts'][ $wpstk_module_id ] )
					? $wpstk_scan['summary']['counts'][ $wpstk_module_id ]
					: array();
				?>
				<tr>
					<td>
						<a href="<?php echo esc_url( WPSTK_Admin::page_url( 'wp-site-toolkit-' . $wpstk_module_id ) ); ?>">
							<?php echo esc_html( $wpstk_module->get_label() ); ?>
						</a>
					</td>
					<td>
						<span class="wpstk-score-pill wpstk-score-pill--<?php echo esc_attr( WPSTK_Admin::score_class( $wpstk_score ) ); ?>">
							<?php echo esc_html( null === $wpstk_score ? '—' : number_format_i18n( (int) $wpstk_score ) ); ?>
						</span>
					</td>
					<?php if ( $wpstk_previous ) : ?>
						<td><?php echo esc_html( null === $wpstk_before ? '—' : number_format_i18n( (int) $wpstk_before ) ); ?></td>
						<td>
							<?php
							if ( null === $wpstk_score || null === $wpstk_before ) {
								echo '&mdash;';
							} else {
								$wpstk_delta = (int) $wpstk_score - (int) $wpstk_before;

								printf(
									'<span class="wpstk-delta wpstk-delta--%1$s">%2$s</span>',
									esc_attr( $wpstk_delta > 0 ? 'up' : ( $wpstk_delta < 0 ? 'down' : 'flat' ) ),
									esc_html( ( $wpstk_delta > 0 ? '+' : '' ) . number_format_i18n( $wpstk_delta ) )
								);
							}
							?>
						</td>
					<?php endif; ?>
					<td><?php echo esc_html( number_format_i18n( isset( $wpstk_module_counts['critical'] ) ? (int) $wpstk_module_counts['critical'] : 0 ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( isset( $wpstk_module_counts['warning'] ) ? (int) $wpstk_module_counts['warning'] : 0 ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( isset( $wpstk_module_counts['passed'] ) ? (int) $wpstk_module_counts['passed'] : 0 ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( isset( $wpstk_module_counts['skipped'] ) ? (int) $wpstk_module_counts['skipped'] : 0 ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>

<h2 class="wpstk-section-title"><?php echo esc_html__( 'All findings', 'wp-site-toolkit' ); ?></h2>

<?php WPSTK_View::check_list( $wpstk_checks, true ); ?>

<h2 class="wpstk-section-title wpstk-no-print"><?php echo esc_html__( 'Scan history', 'wp-site-toolkit' ); ?></h2>

<div class="wpstk-table-wrap wpstk-no-print">
	<table class="wp-list-table widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php echo esc_html__( 'Finished', 'wp-site-toolkit' ); ?></th>
				<th scope="col"><?php echo esc_html__( 'Health', 'wp-site-toolkit' ); ?></th>
				<th scope="col"><?php echo esc_html__( 'Critical', 'wp-site-toolkit' ); ?></th>
				<th scope="col"><?php echo esc_html__( 'Warnings', 'wp-site-toolkit' ); ?></th>
				<th scope="col"><?php echo esc_html__( 'Passed', 'wp-site-toolkit' ); ?></th>
				<th scope="col"><?php echo esc_html__( 'Checks', 'wp-site-toolkit' ); ?></th>
				<th scope="col"><?php echo esc_html__( 'Actions', 'wp-site-toolkit' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $wpstk_history as $wpstk_row ) : ?>
				<tr<?php echo (int) $wpstk_row['id'] === (int) $wpstk_scan['id'] ? ' class="wpstk-row-current"' : ''; ?>>
					<td>
						<a href="<?php echo esc_url( WPSTK_Admin::page_url( 'wp-site-toolkit-reports', array( 'scan_id' => (int) $wpstk_row['id'] ) ) ); ?>">
							<?php echo esc_html( mysql2date( $wpstk_format, $wpstk_row['finished_at'] ) ); ?>
						</a>
					</td>
					<td>
						<span class="wpstk-score-pill wpstk-score-pill--<?php echo esc_attr( WPSTK_Admin::score_class( $wpstk_row['overall_score'] ) ); ?>">
							<?php echo esc_html( null === $wpstk_row['overall_score'] ? '—' : number_format_i18n( (int) $wpstk_row['overall_score'] ) ); ?>
						</span>
					</td>
					<td><?php echo esc_html( number_format_i18n( (int) $wpstk_row['critical_count'] ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( (int) $wpstk_row['warning_count'] ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( (int) $wpstk_row['passed_count'] ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( (int) $wpstk_row['total_checks'] ) ); ?></td>
					<td>
						<a class="button button-small" href="
						<?php
						echo esc_url(
							wp_nonce_url(
								add_query_arg(
									array(
										'action'  => 'wpstk_export_csv',
										'scan_id' => (int) $wpstk_row['id'],
									),
									admin_url( 'admin-post.php' )
								),
								'wpstk_export_csv'
							)
						);
						?>
						"><?php echo esc_html__( 'CSV', 'wp-site-toolkit' ); ?></a>

						<?php if ( WPSTK_Security::can( 'manage' ) ) : ?>
							<a class="button button-small wpstk-button-danger" data-wpstk-confirm="<?php echo esc_attr__( 'Delete this scan and its findings?', 'wp-site-toolkit' ); ?>" href="
							<?php
							echo esc_url(
								wp_nonce_url(
									add_query_arg(
										array(
											'action'  => 'wpstk_delete_scan',
											'scan_id' => (int) $wpstk_row['id'],
										),
										admin_url( 'admin-post.php' )
									),
									'wpstk_delete_scan'
								)
							);
							?>
							"><?php echo esc_html__( 'Delete', 'wp-site-toolkit' ); ?></a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>

<p class="description wpstk-no-print">
	<?php
	printf(
		/* translators: 1: number of days, 2: number of scans. */
		esc_html__( 'History is kept for %1$s days, up to %2$s scans.', 'wp-site-toolkit' ),
		esc_html( number_format_i18n( (int) $view['settings']['retention_days'] ) ),
		esc_html( number_format_i18n( (int) $view['settings']['max_scans'] ) )
	);
	?>
</p>
