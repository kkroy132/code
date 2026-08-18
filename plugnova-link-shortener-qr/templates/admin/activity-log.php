<?php
/**
 * Activity Log admin page view. The table body is driven by assets/js/admin.js.
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap qlqr-wrap">
	<h1 class="qlqr-page-title"><?php esc_html_e( 'Activity Log', 'plugnova-link-shortener-qr' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'A record of who created, updated, trashed, restored, or deleted links and bio pages — and when. Entries older than 90 days are removed automatically.', 'plugnova-link-shortener-qr' ); ?>
	</p>

	<div class="qlqr-toolbar">
		<input type="search" id="qlqr-log-search" placeholder="<?php esc_attr_e( 'Search description or user…', 'plugnova-link-shortener-qr' ); ?>" />
		<select id="qlqr-log-object-type">
			<option value="all"><?php esc_html_e( 'All Types', 'plugnova-link-shortener-qr' ); ?></option>
			<option value="link"><?php esc_html_e( 'Links', 'plugnova-link-shortener-qr' ); ?></option>
			<option value="bio_page"><?php esc_html_e( 'Bio Pages', 'plugnova-link-shortener-qr' ); ?></option>
			<option value="bulk"><?php esc_html_e( 'Bulk Actions', 'plugnova-link-shortener-qr' ); ?></option>
		</select>
	</div>

	<table class="widefat striped" id="qlqr-log-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Date', 'plugnova-link-shortener-qr' ); ?></th>
				<th><?php esc_html_e( 'User', 'plugnova-link-shortener-qr' ); ?></th>
				<th><?php esc_html_e( 'Type', 'plugnova-link-shortener-qr' ); ?></th>
				<th><?php esc_html_e( 'Action', 'plugnova-link-shortener-qr' ); ?></th>
				<th><?php esc_html_e( 'Description', 'plugnova-link-shortener-qr' ); ?></th>
			</tr>
		</thead>
		<tbody id="qlqr-log-tbody">
			<tr><td colspan="5"><?php esc_html_e( 'Loading…', 'plugnova-link-shortener-qr' ); ?></td></tr>
		</tbody>
	</table>

	<div class="qlqr-pagination" id="qlqr-log-pagination"></div>
</div>
