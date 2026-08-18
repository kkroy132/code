<?php
/**
 * Dashboard admin page view.
 *
 * @package QuickLinkQRPro
 *
 * @var array $counters
 * @var \QuickLinkQRPro\Models\Link[] $top_links
 * @var \QuickLinkQRPro\Models\Link[] $latest_links
 * @var array $latest_clicks
 * @var int $countries
 * @var array $clicks_by_day
 * @var array $by_device
 * @var array $by_browser
 * @var array $by_referrer
 * @var int $qlqr_chart_days Days the "Clicks" chart above actually covers (7 on the free plan, 30 on Pro).
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap qlqr-wrap">
	<h1 class="qlqr-page-title">
		<span class="dashicons dashicons-admin-links"></span>
		<?php esc_html_e( 'Plugnova Link Shortener & QR', 'plugnova-link-shortener-qr' ); ?>
	</h1>

	<div class="qlqr-cards">
		<div class="qlqr-card">
			<span class="qlqr-card-label"><?php esc_html_e( 'Total Links', 'plugnova-link-shortener-qr' ); ?></span>
			<span class="qlqr-card-value"><?php echo esc_html( number_format_i18n( $counters['total_links'] ) ); ?></span>
		</div>
		<div class="qlqr-card">
			<span class="qlqr-card-label"><?php esc_html_e( 'Total Clicks', 'plugnova-link-shortener-qr' ); ?></span>
			<span class="qlqr-card-value"><?php echo esc_html( number_format_i18n( $counters['total_clicks'] ) ); ?></span>
		</div>
		<div class="qlqr-card">
			<span class="qlqr-card-label"><?php esc_html_e( 'Unique Clicks', 'plugnova-link-shortener-qr' ); ?></span>
			<span class="qlqr-card-value"><?php echo esc_html( number_format_i18n( $counters['unique_clicks'] ) ); ?></span>
		</div>
		<div class="qlqr-card">
			<span class="qlqr-card-label"><?php esc_html_e( "Today's Clicks", 'plugnova-link-shortener-qr' ); ?></span>
			<span class="qlqr-card-value"><?php echo esc_html( number_format_i18n( $counters['today_clicks'] ) ); ?></span>
		</div>
		<div class="qlqr-card">
			<span class="qlqr-card-label"><?php esc_html_e( 'QR Codes Generated', 'plugnova-link-shortener-qr' ); ?></span>
			<span class="qlqr-card-value"><?php echo esc_html( number_format_i18n( $counters['qr_generated'] ) ); ?></span>
		</div>
		<div class="qlqr-card">
			<span class="qlqr-card-label"><?php esc_html_e( 'QR Scans', 'plugnova-link-shortener-qr' ); ?></span>
			<span class="qlqr-card-value"><?php echo esc_html( number_format_i18n( $counters['qr_scans'] ) ); ?></span>
		</div>
		<div class="qlqr-card">
			<span class="qlqr-card-label"><?php esc_html_e( 'Countries Reached', 'plugnova-link-shortener-qr' ); ?></span>
			<span class="qlqr-card-value"><?php echo esc_html( number_format_i18n( $countries ) ); ?></span>
		</div>
	</div>

	<?php if ( $counters['bot_clicks'] > 0 ) : ?>
	<p class="description" style="margin: -8px 0 16px;">
		<?php
		echo esc_html(
			sprintf(
				/* translators: %s: number of bot/crawler visits filtered out */
				__( '%s bot/crawler visits (WhatsApp previews, search engine bots, etc.) were detected and excluded from the numbers above.', 'plugnova-link-shortener-qr' ),
				number_format_i18n( $counters['bot_clicks'] )
			)
		);
		?>
	</p>
	<?php endif; ?>

	<?php if ( $counters['broken_links'] > 0 ) : ?>
	<div class="notice notice-warning" style="margin: 0 0 16px;">
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: number of broken links */
					_n( '%s link is currently broken (its destination returned an error on the last check).', '%s links are currently broken (their destinations returned an error on the last check).', $counters['broken_links'], 'plugnova-link-shortener-qr' ),
					number_format_i18n( $counters['broken_links'] )
				)
			);
			?>
			<a href="#links" class="qlqr-nav-tab-link" data-tab="links"><?php esc_html_e( 'View in Links →', 'plugnova-link-shortener-qr' ); ?></a>
		</p>
	</div>
	<?php endif; ?>

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

	<div class="qlqr-grid-2">
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
			<canvas id="qlqr-chart-clicks-by-day" height="90"
				data-labels='<?php echo esc_attr( wp_json_encode( wp_list_pluck( $clicks_by_day, 'date' ) ) ); ?>'
				data-values='<?php echo esc_attr( wp_json_encode( wp_list_pluck( $clicks_by_day, 'clicks' ) ) ); ?>'>
			</canvas>
		</div>
		<div class="qlqr-panel">
			<h2><?php esc_html_e( 'Devices', 'plugnova-link-shortener-qr' ); ?></h2>
			<canvas id="qlqr-chart-devices" height="90"
				data-labels='<?php echo esc_attr( wp_json_encode( wp_list_pluck( $by_device, 'label' ) ) ); ?>'
				data-values='<?php echo esc_attr( wp_json_encode( wp_list_pluck( $by_device, 'clicks' ) ) ); ?>'>
			</canvas>
		</div>
	</div>

	<div class="qlqr-grid-2">
		<div class="qlqr-panel">
			<h2><?php esc_html_e( 'Top Performing Links', 'plugnova-link-shortener-qr' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Title', 'plugnova-link-shortener-qr' ); ?></th>
						<th><?php esc_html_e( 'Short URL', 'plugnova-link-shortener-qr' ); ?></th>
						<th><?php esc_html_e( 'Clicks', 'plugnova-link-shortener-qr' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $top_links ) ) : ?>
					<tr><td colspan="3"><?php esc_html_e( 'No links yet.', 'plugnova-link-shortener-qr' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $top_links as $link ) : ?>
						<tr>
							<td><?php echo esc_html( $link->title ?: '(' . esc_html__( 'untitled', 'plugnova-link-shortener-qr' ) . ')' ); ?></td>
							<td><a href="<?php echo esc_url( $link->get_short_url() ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $link->get_short_url() ); ?></a></td>
							<td><?php echo esc_html( number_format_i18n( $link->total_clicks ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>

		<div class="qlqr-panel">
			<h2><?php esc_html_e( 'Latest Clicks', 'plugnova-link-shortener-qr' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Time', 'plugnova-link-shortener-qr' ); ?></th>
						<th><?php esc_html_e( 'Device', 'plugnova-link-shortener-qr' ); ?></th>
						<th><?php esc_html_e( 'Browser', 'plugnova-link-shortener-qr' ); ?></th>
						<th><?php esc_html_e( 'Referrer', 'plugnova-link-shortener-qr' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $latest_clicks ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No clicks recorded yet.', 'plugnova-link-shortener-qr' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $latest_clicks as $qlqr_click ) : ?>
						<tr>
							<td><?php echo esc_html( mysql2date( 'M j, g:i a', $qlqr_click['clicked_at'] ) ); ?></td>
							<td><?php echo esc_html( ucfirst( (string) $qlqr_click['device'] ) ); ?></td>
							<td><?php echo esc_html( (string) $qlqr_click['browser'] ); ?></td>
							<td><?php echo esc_html( $qlqr_click['referrer'] ? (string) $qlqr_click['referrer'] : __( 'Direct', 'plugnova-link-shortener-qr' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
	</div>

	<div class="qlqr-panel">
		<h2><?php esc_html_e( 'Latest Links', 'plugnova-link-shortener-qr' ); ?></h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Title', 'plugnova-link-shortener-qr' ); ?></th>
					<th><?php esc_html_e( 'Short URL', 'plugnova-link-shortener-qr' ); ?></th>
					<th><?php esc_html_e( 'Destination', 'plugnova-link-shortener-qr' ); ?></th>
					<th><?php esc_html_e( 'Created', 'plugnova-link-shortener-qr' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $latest_links ) ) : ?>
				<tr><td colspan="4"><?php esc_html_e( 'No links yet — create your first one from the Links page.', 'plugnova-link-shortener-qr' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $latest_links as $link ) : ?>
					<tr>
						<td><?php echo esc_html( $link->title ?: '—' ); ?></td>
						<td><a href="<?php echo esc_url( $link->get_short_url() ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $link->get_short_url() ); ?></a></td>
						<td class="qlqr-truncate"><?php echo esc_html( $link->destination_url ); ?></td>
						<td><?php echo esc_html( mysql2date( 'M j, Y', $link->created_at ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
	</div>
</div>
