<?php
/**
 * Full Audit screen.
 *
 * @package WP_Site_Toolkit
 *
 * @var array $view Screen data.
 */

defined( 'ABSPATH' ) || exit;

$wpstk_latest  = $view['latest'];
$wpstk_audit   = $view['audit'];
$wpstk_modules = $wpstk_audit->get_modules();
?>

<div class="wpstk-card wpstk-runner-card">
	<h2 class="wpstk-card__title"><?php echo esc_html__( 'Full site audit', 'wp-site-toolkit' ); ?></h2>
	<p><?php echo esc_html__( 'The audit runs every available check and works through your content in small batches, so it will not tie up the admin area or overload your server. You can leave this page open and watch the progress.', 'wp-site-toolkit' ); ?></p>

	<?php WPSTK_View::runner( $view['running'] ); ?>

	<p class="description">
		<?php
		printf(
			/* translators: 1: number of posts, 2: number of URLs, 3: number of pages. */
			esc_html__( 'Current limits: up to %1$s published entries, %2$s links and %3$s rendered pages per audit.', 'wp-site-toolkit' ),
			esc_html( number_format_i18n( (int) $view['settings']['max_posts'] ) ),
			esc_html( number_format_i18n( (int) $view['settings']['max_urls'] ) ),
			esc_html( number_format_i18n( (int) $view['settings']['page_samples'] ) )
		);
		?>
		<a href="<?php echo esc_url( WPSTK_Admin::page_url( 'wp-site-toolkit-settings' ) ); ?>"><?php echo esc_html__( 'Change limits', 'wp-site-toolkit' ); ?></a>
	</p>
</div>

<h2 class="wpstk-section-title"><?php echo esc_html__( 'What the audit covers', 'wp-site-toolkit' ); ?></h2>

<div class="wpstk-grid wpstk-grid--compact">
	<?php foreach ( $wpstk_modules as $wpstk_module_id => $wpstk_module ) : ?>
		<div class="wpstk-card wpstk-module-card">
			<h3 class="wpstk-module-card__title">
				<span class="dashicons <?php echo esc_attr( $wpstk_module->get_icon() ); ?>" aria-hidden="true"></span>
				<?php echo esc_html( $wpstk_module->get_label() ); ?>
			</h3>
			<p><?php echo esc_html( $wpstk_module->get_description() ); ?></p>
			<p>
				<a href="<?php echo esc_url( WPSTK_Admin::page_url( 'wp-site-toolkit-' . $wpstk_module_id ) ); ?>">
					<?php
					printf(
						/* translators: %s: section name. */
						esc_html__( 'Open %s results', 'wp-site-toolkit' ),
						esc_html( $wpstk_module->get_label() )
					);
					?>
				</a>
			</p>
		</div>
	<?php endforeach; ?>
</div>

<?php if ( $wpstk_latest ) : ?>
	<?php
	$wpstk_checks = WPSTK_Scan_Store::sort_by_severity( WPSTK_Scan_Store::get_checks( (int) $wpstk_latest['id'] ) );
	$wpstk_counts = array(
		'critical'       => (int) $wpstk_latest['critical_count'],
		'warning'        => (int) $wpstk_latest['warning_count'],
		'recommendation' => (int) $wpstk_latest['recommendation_count'],
		'passed'         => (int) $wpstk_latest['passed_count'],
		'skipped'        => (int) $wpstk_latest['skipped_count'],
	);
	?>

	<h2 class="wpstk-section-title">
		<?php echo esc_html__( 'Latest results', 'wp-site-toolkit' ); ?>
		<span class="wpstk-section-title__meta">
			<?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $wpstk_latest['finished_at'] ) ); ?>
		</span>
	</h2>

	<?php WPSTK_View::summary_tiles( $wpstk_counts ); ?>

	<p>
		<?php
		printf(
			/* translators: %s: number of checks. */
			esc_html__( 'Total checks: %s', 'wp-site-toolkit' ),
			esc_html( number_format_i18n( (int) $wpstk_latest['total_checks'] ) )
		);
		?>
	</p>

	<?php WPSTK_View::check_list( $wpstk_checks, true ); ?>
<?php else : ?>
	<?php WPSTK_View::empty_state( __( 'No audit has been completed yet. Use the button above to run the first one.', 'wp-site-toolkit' ) ); ?>
<?php endif; 
