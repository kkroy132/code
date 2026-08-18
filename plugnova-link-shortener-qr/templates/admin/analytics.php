<?php
/**
 * Analytics admin page view.
 *
 * @package QuickLinkQRPro
 *
 * @var array $clicks_by_day
 * @var array $by_country
 * @var array $by_device
 * @var array $by_browser
 * @var array $by_os
 * @var array $by_referrer
 * @var array $by_source
 * @var int $qlqr_chart_days Days the "Clicks" chart below actually covers (7 on the free plan, 30 on Pro).
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Convert a 2-letter ISO country code to its flag emoji using Unicode regional indicator symbols.
 *
 * @param string $code 2-letter ISO 3166-1 alpha-2 country code.
 * @return string Flag emoji, or empty string if $code isn't a valid 2-letter code.
 */
if ( ! function_exists( 'qlqr_country_flag' ) ) :
	function qlqr_country_flag( string $code ): string {
		$code = strtoupper( trim( $code ) );
		if ( 2 !== strlen( $code ) || ! ctype_alpha( $code ) ) {
			return '';
		}

		$flag = '';
		foreach ( str_split( $code ) as $letter ) {
			$flag .= mb_chr( 127397 + ord( $letter ), 'UTF-8' );
		}

		return $flag;
	}
endif;

/**
 * Render a simple ranked list table for a dimension breakdown.
 *
 * @param string $title      Panel title.
 * @param array  $rows       Rows of {label, clicks}.
 * @param bool   $is_country Whether $rows contains ISO country codes (adds a flag emoji).
 */
if ( ! function_exists( 'qlqr_render_dimension_panel' ) ) :
	function qlqr_render_dimension_panel( string $title, array $rows, bool $is_country = false ): void {
		?>
		<div class="qlqr-panel">
			<h2><?php echo esc_html( $title ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Value', 'plugnova-link-shortener-qr' ); ?></th><th><?php esc_html_e( 'Clicks', 'plugnova-link-shortener-qr' ); ?></th></tr></thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="2"><?php esc_html_e( 'No data yet.', 'plugnova-link-shortener-qr' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td>
								<?php if ( $is_country ) : ?>
									<?php echo esc_html( qlqr_country_flag( $row['label'] ) ); ?>
								<?php endif; ?>
								<?php echo esc_html( $row['label'] ); ?>
							</td>
							<td><?php echo esc_html( number_format_i18n( $row['clicks'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
endif;
?>
<div class="wrap qlqr-wrap">
	<h1 class="qlqr-page-title"><?php esc_html_e( 'Analytics', 'plugnova-link-shortener-qr' ); ?></h1>

	<?php if ( ! qlqr_fs()->can_use_premium_code() ) : ?>
	<div class="notice notice-info qlqr-analytics-notice">
		<p>
			<?php esc_html_e( 'Free plan shows the last 7 days. Upgrade to Pro for full analytics history.', 'plugnova-link-shortener-qr' ); ?>
			<a href="<?php echo esc_url( qlqr_fs()->get_upgrade_url() ); ?>" target="_blank" rel="noopener">
				<?php esc_html_e( 'Upgrade to Pro', 'plugnova-link-shortener-qr' ); ?>
			</a>
		</p>
	</div>
	<?php endif; ?>

	<div class="qlqr-panel">
		<h2>
			<?php
			printf(
				/* translators: %d: number of days the chart below covers */
				esc_html__( 'Clicks — Last %d Days', 'plugnova-link-shortener-qr' ),
				(int) $qlqr_chart_days
			);
			?>
		</h2>
		<?php // Distinct ID from the Dashboard panel's clicks-by-day canvas — both are rendered on the same unified tabbed page, so a shared ID would be a duplicate. The JS chart-type check matches on the "clicks-by-day" substring, so both stay line charts. ?>
		<canvas id="qlqr-analytics-clicks-by-day" height="80"
			data-labels='<?php echo esc_attr( wp_json_encode( wp_list_pluck( $clicks_by_day, 'date' ) ) ); ?>'
			data-values='<?php echo esc_attr( wp_json_encode( wp_list_pluck( $clicks_by_day, 'clicks' ) ) ); ?>'>
		</canvas>
	</div>

	<div class="qlqr-grid-2">
		<?php qlqr_render_dimension_panel( __( 'Top Countries', 'plugnova-link-shortener-qr' ), $by_country, true ); ?>
		<?php qlqr_render_dimension_panel( __( 'Devices', 'plugnova-link-shortener-qr' ), $by_device ); ?>
	</div>
	<div class="qlqr-grid-2">
		<?php qlqr_render_dimension_panel( __( 'Browsers', 'plugnova-link-shortener-qr' ), $by_browser ); ?>
		<?php qlqr_render_dimension_panel( __( 'Operating Systems', 'plugnova-link-shortener-qr' ), $by_os ); ?>
	</div>
	<div class="qlqr-grid-2">
		<?php qlqr_render_dimension_panel( __( 'Top Referrers', 'plugnova-link-shortener-qr' ), $by_referrer ); ?>
		<?php qlqr_render_dimension_panel( __( 'QR Scan vs. Direct Click', 'plugnova-link-shortener-qr' ), $by_source ); ?>
	</div>
</div>
