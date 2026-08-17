<?php
/**
 * Single section screen (SEO, Links, Images, Performance, Security, Technical).
 *
 * @package WP_Site_Toolkit
 *
 * @var array $view Screen data.
 */

defined( 'ABSPATH' ) || exit;

$wpstk_module_id = $view['module_id'];
$wpstk_module    = $view['audit']->get_module( $wpstk_module_id );
$wpstk_latest    = $view['latest'];

if ( ! $wpstk_module ) {
	WPSTK_View::empty_state( __( 'That section is not available.', 'wp-site-toolkit' ) );

	return;
}

$wpstk_tab = WPSTK_Security::get_query_arg( 'tab', 'results' );

$wpstk_checks = $wpstk_latest
	? WPSTK_Scan_Store::sort_by_severity( WPSTK_Scan_Store::get_checks( (int) $wpstk_latest['id'], $wpstk_module_id ) )
	: array();

$wpstk_score  = ( $wpstk_latest && isset( $wpstk_latest['scores'][ $wpstk_module_id ] ) )
	? $wpstk_latest['scores'][ $wpstk_module_id ]
	: null;
$wpstk_counts = WPSTK_Check::count_by_status( $wpstk_checks );
?>

<div class="wpstk-card wpstk-module-head">
	<div class="wpstk-module-head__intro">
		<h2 class="wpstk-card__title">
			<span class="dashicons <?php echo esc_attr( $wpstk_module->get_icon() ); ?>" aria-hidden="true"></span>
			<?php echo esc_html( $wpstk_module->get_label() ); ?>
		</h2>
		<p><?php echo esc_html( $wpstk_module->get_description() ); ?></p>

		<?php if ( 'security' === $wpstk_module_id ) : ?>
			<p class="wpstk-callout"><?php echo esc_html__( 'This is a Basic Security Health Check. It reads configuration and response headers only. It does not test for vulnerabilities and is not a substitute for a full security review.', 'wp-site-toolkit' ); ?></p>
		<?php endif; ?>

		<?php if ( 'performance' === $wpstk_module_id ) : ?>
			<p class="wpstk-callout"><?php echo esc_html__( 'Basic local check only. These measurements are taken on the server and do not replace a real page speed or Core Web Vitals test taken from a visitor\'s browser.', 'wp-site-toolkit' ); ?></p>
		<?php endif; ?>
	</div>

	<div class="wpstk-module-head__score">
		<?php
		WPSTK_View::score_card(
			array(
				'title'  => __( 'Section score', 'wp-site-toolkit' ),
				'score'  => $wpstk_score,
				'counts' => $wpstk_counts,
			)
		);
		?>
	</div>
</div>

<?php if ( 'links' === $wpstk_module_id ) : ?>
	<nav class="nav-tab-wrapper wpstk-tabs">
		<a class="nav-tab <?php echo '404' === $wpstk_tab ? '' : 'nav-tab-active'; ?>" href="<?php echo esc_url( WPSTK_Admin::page_url( 'wp-site-toolkit-links' ) ); ?>">
			<?php echo esc_html__( 'Link results', 'wp-site-toolkit' ); ?>
		</a>
		<a class="nav-tab <?php echo '404' === $wpstk_tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( WPSTK_Admin::page_url( 'wp-site-toolkit-links', array( 'tab' => '404' ) ) ); ?>">
			<?php echo esc_html__( '404 monitor', 'wp-site-toolkit' ); ?>
		</a>
	</nav>
<?php endif; ?>

<?php if ( 'links' === $wpstk_module_id && '404' === $wpstk_tab ) : ?>
	<?php
	$wpstk_paged  = max( 1, (int) WPSTK_Security::get_query_arg( 'paged', '1' ) );
	$wpstk_per    = 25;
	$wpstk_rows   = WPSTK_Not_Found_Monitor::get_recent( $wpstk_per, ( $wpstk_paged - 1 ) * $wpstk_per );
	$wpstk_total  = WPSTK_Not_Found_Monitor::count_all();
	$wpstk_pages  = (int) ceil( $wpstk_total / $wpstk_per );
	$wpstk_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
	?>

	<div class="wpstk-card">
		<h2 class="wpstk-card__title"><?php echo esc_html__( '404 monitor', 'wp-site-toolkit' ); ?></h2>

		<?php if ( empty( $view['settings']['monitor_404'] ) ) : ?>
			<p class="wpstk-callout">
				<?php echo esc_html__( '404 monitoring is currently turned off, so nothing new is being recorded.', 'wp-site-toolkit' ); ?>
				<a href="<?php echo esc_url( WPSTK_Admin::page_url( 'wp-site-toolkit-settings' ) ); ?>"><?php echo esc_html__( 'Turn it on', 'wp-site-toolkit' ); ?></a>
			</p>
		<?php endif; ?>

		<p class="description">
			<?php
			printf(
				/* translators: %s: number of stored addresses. */
				esc_html__( 'The requested address, the referring URL and a hit counter are stored — nothing else. No IP addresses or user agents are recorded. Up to %s distinct addresses are kept.', 'wp-site-toolkit' ),
				esc_html( number_format_i18n( WPSTK_Not_Found_Monitor::MAX_ROWS ) )
			);
			?>
		</p>

		<?php if ( empty( $wpstk_rows ) ) : ?>
			<?php WPSTK_View::empty_state( __( 'No 404 requests have been recorded yet.', 'wp-site-toolkit' ) ); ?>
		<?php else : ?>
			<div class="wpstk-table-wrap">
				<table class="wp-list-table widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php echo esc_html__( 'Requested address', 'wp-site-toolkit' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Referrer', 'wp-site-toolkit' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Hits', 'wp-site-toolkit' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'First seen', 'wp-site-toolkit' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Last seen', 'wp-site-toolkit' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Action', 'wp-site-toolkit' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $wpstk_rows as $wpstk_row ) : ?>
							<tr>
								<td>
									<a href="<?php echo esc_url( home_url( $wpstk_row['url'] ) ); ?>" target="_blank" rel="noopener noreferrer">
										<?php echo esc_html( WPSTK_Content::shorten( $wpstk_row['url'], 70 ) ); ?>
									</a>
								</td>
								<td>
									<?php echo '' !== $wpstk_row['referrer'] ? esc_html( WPSTK_Content::shorten( $wpstk_row['referrer'], 50 ) ) : '&mdash;'; ?>
								</td>
								<td><?php echo esc_html( number_format_i18n( (int) $wpstk_row['hits'] ) ); ?></td>
								<td><?php echo esc_html( mysql2date( $wpstk_format, $wpstk_row['first_seen'] ) ); ?></td>
								<td><?php echo esc_html( mysql2date( $wpstk_format, $wpstk_row['last_seen'] ) ); ?></td>
								<td>
									<?php if ( WPSTK_Security::can( 'manage' ) ) : ?>
										<a class="button button-small" href="
										<?php
										echo esc_url(
											wp_nonce_url(
												add_query_arg(
													array(
														'action' => 'wpstk_delete_404',
														'row_id' => (int) $wpstk_row['id'],
													),
													admin_url( 'admin-post.php' )
												),
												'wpstk_delete_404'
											)
										);
										?>
										"><?php echo esc_html__( 'Remove', 'wp-site-toolkit' ); ?></a>
									<?php else : ?>
										&mdash;
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<?php if ( $wpstk_pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => WPSTK_Admin::page_url( 'wp-site-toolkit-links', array( 'tab' => '404' ) ) . '%_%',
								'format'    => '&paged=%#%',
								'current'   => $wpstk_paged,
								'total'     => $wpstk_pages,
								'prev_text' => '&laquo;',
								'next_text' => '&raquo;',
							)
						)
					);
					?>
				</div></div>
			<?php endif; ?>

			<?php if ( WPSTK_Security::can( 'manage' ) ) : ?>
				<p>
					<a class="button" href="
					<?php
					echo esc_url(
						wp_nonce_url(
							add_query_arg( array( 'action' => 'wpstk_clear_404' ), admin_url( 'admin-post.php' ) ),
							'wpstk_clear_404'
						)
					);
					?>
					"><?php echo esc_html__( 'Clear the 404 log', 'wp-site-toolkit' ); ?></a>
				</p>
			<?php endif; ?>
		<?php endif; ?>
	</div>
<?php else : ?>
	<?php if ( ! $wpstk_latest ) : ?>
		<?php
		WPSTK_View::empty_state(
			__( 'No audit has been completed yet, so there is nothing to show for this section.', 'wp-site-toolkit' ),
			__( 'Run an audit', 'wp-site-toolkit' ),
			WPSTK_Admin::page_url( 'wp-site-toolkit-audit' )
		);
		?>
	<?php else : ?>
		<p class="wpstk-result-meta">
			<?php
			printf(
				/* translators: %s: date and time of the audit. */
				esc_html__( 'From the audit finished on %s.', 'wp-site-toolkit' ),
				esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $wpstk_latest['finished_at'] ) )
			);
			?>
		</p>

		<?php WPSTK_View::check_list( $wpstk_checks ); ?>
	<?php endif; ?>
<?php endif; ?>
