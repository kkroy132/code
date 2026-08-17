<?php
/**
 * Dashboard screen.
 *
 * @package WP_Site_Toolkit
 *
 * @var array $view Screen data.
 */

defined( 'ABSPATH' ) || exit;

$wpstk_latest  = $view['latest'];
$wpstk_audit   = $view['audit'];
$wpstk_modules = $wpstk_audit->get_modules();
$wpstk_scores  = $wpstk_latest ? (array) $wpstk_latest['scores'] : array();
$wpstk_overall = isset( $wpstk_scores['overall'] ) ? $wpstk_scores['overall'] : null;
$wpstk_grade   = WPSTK_Check::grade( $wpstk_overall );
$wpstk_counts  = $wpstk_latest ? array(
	'critical'       => (int) $wpstk_latest['critical_count'],
	'warning'        => (int) $wpstk_latest['warning_count'],
	'recommendation' => (int) $wpstk_latest['recommendation_count'],
	'passed'         => (int) $wpstk_latest['passed_count'],
	'skipped'        => (int) $wpstk_latest['skipped_count'],
) : array();
?>

<div class="wpstk-layout">
	<div class="wpstk-layout__main">
		<div class="wpstk-card wpstk-hero wpstk-hero--<?php echo esc_attr( $wpstk_grade['key'] ); ?>">
			<div class="wpstk-hero__score">
				<p class="wpstk-hero__label"><?php echo esc_html__( 'Website Health', 'site-toolkit' ); ?></p>
				<p class="wpstk-hero__value">
					<?php if ( null === $wpstk_overall ) : ?>
						<span class="wpstk-hero__number">&mdash;</span>
					<?php else : ?>
						<span class="wpstk-hero__number"><?php echo esc_html( number_format_i18n( (int) $wpstk_overall ) ); ?></span>
						<span class="wpstk-hero__total">/ 100</span>
					<?php endif; ?>
				</p>
				<p class="wpstk-hero__grade"><?php echo esc_html( $wpstk_grade['label'] ); ?></p>
				<p class="wpstk-hero__description"><?php echo esc_html( $wpstk_grade['description'] ); ?></p>
			</div>
			<div class="wpstk-hero__action">
				<?php WPSTK_View::runner( $view['running'] ); ?>

				<?php if ( $wpstk_latest ) : ?>
					<p class="wpstk-hero__meta">
						<?php
						printf(
							/* translators: %s: human readable time difference. */
							esc_html__( 'Last audit: %s ago', 'site-toolkit' ),
							esc_html( human_time_diff( (int) strtotime( $wpstk_latest['finished_at'] . ' UTC' ), time() ) )
						);
						?>
						&middot;
						<a href="<?php echo esc_url( WPSTK_Admin::page_url( 'site-toolkit-reports', array( 'scan_id' => (int) $wpstk_latest['id'] ) ) ); ?>">
							<?php echo esc_html__( 'View full report', 'site-toolkit' ); ?>
						</a>
					</p>
				<?php endif; ?>
			</div>
		</div>

		<?php if ( $wpstk_latest ) : ?>
			<?php WPSTK_View::summary_tiles( $wpstk_counts ); ?>
		<?php endif; ?>

		<h2 class="wpstk-section-title"><?php echo esc_html__( 'Section scores', 'site-toolkit' ); ?></h2>

		<div class="wpstk-grid">
			<?php foreach ( $wpstk_modules as $wpstk_module_id => $wpstk_module ) : ?>
				<?php
				$wpstk_module_score  = isset( $wpstk_scores[ $wpstk_module_id ] ) ? $wpstk_scores[ $wpstk_module_id ] : null;
				$wpstk_module_counts = isset( $wpstk_latest['summary']['counts'][ $wpstk_module_id ] )
					? $wpstk_latest['summary']['counts'][ $wpstk_module_id ]
					: array();

				WPSTK_View::score_card(
					array(
						'title'    => $wpstk_module->get_label(),
						'score'    => $wpstk_module_score,
						'subtitle' => $wpstk_module->get_description(),
						'icon'     => $wpstk_module->get_icon(),
						'url'      => WPSTK_Admin::page_url( 'site-toolkit-' . $wpstk_module_id ),
						'counts'   => $wpstk_module_counts,
					)
				);
				?>
			<?php endforeach; ?>
		</div>

		<?php if ( $wpstk_latest ) : ?>
			<?php
			$wpstk_all       = WPSTK_Scan_Store::get_checks( (int) $wpstk_latest['id'] );
			$wpstk_attention = array();

			foreach ( WPSTK_Scan_Store::sort_by_severity( $wpstk_all ) as $wpstk_check ) {
				if ( in_array( $wpstk_check['status'], array( 'critical', 'warning' ), true ) ) {
					$wpstk_attention[] = $wpstk_check;
				}
			}

			$wpstk_attention = array_slice( $wpstk_attention, 0, 8 );
			?>

			<h2 class="wpstk-section-title"><?php echo esc_html__( 'Needs attention first', 'site-toolkit' ); ?></h2>

			<?php if ( empty( $wpstk_attention ) ) : ?>
				<?php
				WPSTK_View::empty_state(
					__( 'No critical issues or warnings were found in the latest audit. Recommendations are listed in each section.', 'site-toolkit' )
				);
				?>
			<?php else : ?>
				<?php WPSTK_View::check_list( $wpstk_attention, true ); ?>

				<p>
					<a class="button" href="<?php echo esc_url( WPSTK_Admin::page_url( 'site-toolkit-reports', array( 'scan_id' => (int) $wpstk_latest['id'] ) ) ); ?>">
						<?php echo esc_html__( 'See every finding', 'site-toolkit' ); ?>
					</a>
				</p>
			<?php endif; ?>
		<?php else : ?>
			<h2 class="wpstk-section-title"><?php echo esc_html__( 'Getting started', 'site-toolkit' ); ?></h2>
			<?php
			WPSTK_View::empty_state(
				__( 'No audit has been run yet. Start one to see where your site stands — it runs in small batches so it will not lock up your admin area.', 'site-toolkit' ),
				__( 'Open Full Audit', 'site-toolkit' ),
				WPSTK_Admin::page_url( 'site-toolkit-audit' )
			);
			?>
		<?php endif; ?>
	</div>

	<div class="wpstk-layout__side">
		<div class="wpstk-card">
			<h2 class="wpstk-card__title"><?php echo esc_html__( 'How scoring works', 'site-toolkit' ); ?></h2>
			<p><?php echo esc_html__( 'Each section runs a fixed set of checks. A check that passes scores full marks, a recommendation scores most of them, a warning scores half, and a critical finding scores none. Checks that could not run are excluded.', 'site-toolkit' ); ?></p>
			<p><?php echo esc_html__( 'Scores are a guide to where to spend your time. They are not a guarantee of search rankings or of security.', 'site-toolkit' ); ?></p>
		</div>

		<div class="wpstk-card">
			<h2 class="wpstk-card__title"><?php echo esc_html__( 'Privacy', 'site-toolkit' ); ?></h2>
			<p><?php echo esc_html__( 'Everything is processed on this site. No content, statistics or personal data is sent anywhere, and there is no telemetry or advertising.', 'site-toolkit' ); ?></p>
			<p>
				<?php echo esc_html__( 'During an audit the plugin loads pages from your own site to inspect the markup it serves.', 'site-toolkit' ); ?>
				<?php
				if ( empty( $view['settings']['scan_external'] ) ) {
					echo esc_html__( 'External link checking is off, so no third-party site is contacted.', 'site-toolkit' );
				} else {
					echo esc_html__( 'External link checking is on, so sites you link to are requested to see whether they still respond.', 'site-toolkit' );
				}
				?>
			</p>
			<p>
				<a href="<?php echo esc_url( WPSTK_Admin::page_url( 'site-toolkit-settings' ) ); ?>">
					<?php echo esc_html__( 'Review settings', 'site-toolkit' ); ?>
				</a>
			</p>
		</div>

		<?php
		$wpstk_history = WPSTK_Scan_Store::get_recent( 6 );

		if ( count( $wpstk_history ) > 1 ) :
			?>
			<div class="wpstk-card">
				<h2 class="wpstk-card__title"><?php echo esc_html__( 'Recent scores', 'site-toolkit' ); ?></h2>
				<ul class="wpstk-history">
					<?php foreach ( $wpstk_history as $wpstk_row ) : ?>
						<li class="wpstk-history__item">
							<a href="<?php echo esc_url( WPSTK_Admin::page_url( 'site-toolkit-reports', array( 'scan_id' => (int) $wpstk_row['id'] ) ) ); ?>">
								<?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $wpstk_row['finished_at'] ) ); ?>
							</a>
							<span class="wpstk-history__score wpstk-history__score--<?php echo esc_attr( WPSTK_Admin::score_class( $wpstk_row['overall_score'] ) ); ?>">
								<?php echo esc_html( null === $wpstk_row['overall_score'] ? '—' : number_format_i18n( (int) $wpstk_row['overall_score'] ) ); ?>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>
	</div>
</div>
