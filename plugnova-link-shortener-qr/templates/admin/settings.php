<?php
/**
 * Settings admin page view.
 *
 * @package QuickLinkQRPro
 *
 * @var array $roles
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$qlqr_allowed_roles = (array) get_option( 'qlqr_allowed_roles', array( 'administrator', 'editor' ) );
?>
<div class="wrap qlqr-wrap">
	<h1 class="qlqr-page-title"><?php esc_html_e( 'Settings', 'plugnova-link-shortener-qr' ); ?></h1>

	<form method="post" action="options.php">
		<?php settings_fields( 'qlqr_settings_group' ); ?>

		<div class="qlqr-panel">
			<h2><?php esc_html_e( 'General', 'plugnova-link-shortener-qr' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="qlqr_url_prefix"><?php esc_html_e( 'URL Prefix', 'plugnova-link-shortener-qr' ); ?></label></th>
					<td>
						<code><?php echo esc_html( home_url( '/' ) ); ?></code>
						<input type="text" id="qlqr_url_prefix" name="qlqr_url_prefix" value="<?php echo esc_attr( get_option( 'qlqr_url_prefix', 'go' ) ); ?>" class="regular-text" style="width:120px;" />
						<code>/your-slug</code>
						<p class="description"><?php esc_html_e( 'The path segment used for all short links, e.g. "go", "r", or "link". Save changes and re-save Permalinks if links stop working.', 'plugnova-link-shortener-qr' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="qlqr_bio_url_prefix"><?php esc_html_e( 'Bio Link URL Prefix', 'plugnova-link-shortener-qr' ); ?></label></th>
					<td>
						<code><?php echo esc_html( home_url( '/' ) ); ?></code>
						<input type="text" id="qlqr_bio_url_prefix" name="qlqr_bio_url_prefix" value="<?php echo esc_attr( get_option( 'qlqr_bio_url_prefix', 'bio' ) ); ?>" class="regular-text" style="width:120px;" />
						<code>/your-page</code>
						<p class="description"><?php esc_html_e( 'The path segment used for Smart Bio Link pages, e.g. "bio" or "me". Keep this different from the URL Prefix above.', 'plugnova-link-shortener-qr' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="qlqr_default_redirect"><?php esc_html_e( 'Default Redirect Type', 'plugnova-link-shortener-qr' ); ?></label></th>
					<td>
						<select id="qlqr_default_redirect" name="qlqr_default_redirect">
							<?php foreach ( array( 301, 302, 307 ) as $qlqr_code ) : ?>
								<option value="<?php echo esc_attr( $qlqr_code ); ?>" <?php selected( (int) get_option( 'qlqr_default_redirect', 302 ), $qlqr_code ); ?>><?php echo esc_html( $qlqr_code ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( '302 is recommended. A 301 tells the browser the redirect is permanent, so it stops asking this site and later clicks from that visitor are never counted. Use 301 only if passing SEO link equity matters more to you than accurate click analytics.', 'plugnova-link-shortener-qr' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="qlqr_slug_length"><?php esc_html_e( 'Random Slug Length', 'plugnova-link-shortener-qr' ); ?></label></th>
					<td><input type="number" id="qlqr_slug_length" name="qlqr_slug_length" min="3" max="32" value="<?php echo esc_attr( get_option( 'qlqr_slug_length', 6 ) ); ?>" /></td>
				</tr>
			</table>
		</div>

		<div class="qlqr-panel">
			<h2><?php esc_html_e( 'QR Code Defaults', 'plugnova-link-shortener-qr' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="qlqr_qr_default_size"><?php esc_html_e( 'Default Size (px)', 'plugnova-link-shortener-qr' ); ?></label></th>
					<td><input type="number" id="qlqr_qr_default_size" name="qlqr_qr_default_size" min="64" max="2000" value="<?php echo esc_attr( get_option( 'qlqr_qr_default_size', 300 ) ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="qlqr_qr_default_fg"><?php esc_html_e( 'Default Foreground Color', 'plugnova-link-shortener-qr' ); ?></label></th>
					<td><input type="color" id="qlqr_qr_default_fg" name="qlqr_qr_default_fg" value="<?php echo esc_attr( get_option( 'qlqr_qr_default_fg', '#000000' ) ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="qlqr_qr_default_bg"><?php esc_html_e( 'Default Background Color', 'plugnova-link-shortener-qr' ); ?></label></th>
					<td><input type="color" id="qlqr_qr_default_bg" name="qlqr_qr_default_bg" value="<?php echo esc_attr( get_option( 'qlqr_qr_default_bg', '#ffffff' ) ); ?>" /></td>
				</tr>
			</table>
		</div>

		<div class="qlqr-panel">
			<h2><?php esc_html_e( 'Analytics & API', 'plugnova-link-shortener-qr' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Click Tracking', 'plugnova-link-shortener-qr' ); ?></th>
					<td><label><input type="checkbox" name="qlqr_tracking_enabled" value="1" <?php checked( get_option( 'qlqr_tracking_enabled', 1 ) ); ?> /> <?php esc_html_e( 'Record click analytics', 'plugnova-link-shortener-qr' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Your Own Clicks', 'plugnova-link-shortener-qr' ); ?></th>
					<td>
						<label><input type="checkbox" name="qlqr_exclude_admin_clicks" value="1" <?php checked( get_option( 'qlqr_exclude_admin_clicks', 0 ) ); ?> /> <?php esc_html_e( 'Do not count clicks from logged-in users who can manage links', 'plugnova-link-shortener-qr' ); ?></label>
						<p class="description"><?php esc_html_e( 'Checking that a link works means clicking it, and while your real traffic is still small those checks are a visible share of the numbers — same country, same browser, referred by your own site, and all from one IP, which also holds the unique-click count down. The link still redirects normally; only the recording is skipped.', 'plugnova-link-shortener-qr' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Automatic Broken-Link Checks', 'plugnova-link-shortener-qr' ); ?></th>
					<td>
						<label><input type="checkbox" name="qlqr_broken_link_check_enabled" value="1" <?php checked( get_option( 'qlqr_broken_link_check_enabled', 0 ) ); ?> /> <?php esc_html_e( 'Once a day, request each link\'s destination URL to check it still works', 'plugnova-link-shortener-qr' ); ?></label>
						<p class="description"><?php esc_html_e( 'Off by default. Each check is a real request to that destination\'s own server, so this only runs when you turn it on. You can still check any time without turning this on, using "Check All Links" on the Links screen.', 'plugnova-link-shortener-qr' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="qlqr_click_retention_months"><?php esc_html_e( 'Analytics Retention', 'plugnova-link-shortener-qr' ); ?></label></th>
					<td>
						<?php
						/*
						 * The fallback is 0 ("Forever"), not the 12 in Helpers\Options::DEFAULTS. Those
						 * defaults are written with add_option(), which never touches a site that is
						 * already installed — so a fresh install has 12 stored and shows it, while an
						 * existing site has nothing stored and must show "Forever", because that is
						 * genuinely what the cron will do. An update must never quietly start deleting
						 * a user's history.
						 */
						$qlqr_retention = (int) get_option( 'qlqr_click_retention_months', 0 );
						$qlqr_windows   = array(
							0  => __( 'Forever — never delete', 'plugnova-link-shortener-qr' ),
							3  => __( '3 months', 'plugnova-link-shortener-qr' ),
							6  => __( '6 months', 'plugnova-link-shortener-qr' ),
							12 => __( '12 months', 'plugnova-link-shortener-qr' ),
							24 => __( '24 months', 'plugnova-link-shortener-qr' ),
							36 => __( '36 months', 'plugnova-link-shortener-qr' ),
						);
						?>
						<select id="qlqr_click_retention_months" name="qlqr_click_retention_months">
							<?php foreach ( $qlqr_windows as $qlqr_months => $qlqr_label ) : ?>
								<option value="<?php echo esc_attr( (string) $qlqr_months ); ?>" <?php selected( $qlqr_retention, $qlqr_months ); ?>><?php echo esc_html( $qlqr_label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'How long to keep the individual click and view records behind the analytics charts. One row is stored per visit, so on a busy site this is the only table that grows without limit — a thousand clicks a day is over a third of a million rows a year. Older rows are removed once a day, a batch at a time.', 'plugnova-link-shortener-qr' ); ?></p>
						<p class="description"><strong><?php esc_html_e( 'Total click and view counts are never affected', 'plugnova-link-shortener-qr' ); ?></strong><?php esc_html_e( ' — those are kept separately on each link. What you lose beyond the window is the day-by-day chart and the device/country/referrer breakdowns for that older period.', 'plugnova-link-shortener-qr' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Behind a Proxy or CDN', 'plugnova-link-shortener-qr' ); ?></th>
					<td>
						<label><input type="checkbox" name="qlqr_trust_proxy_headers" value="1" <?php checked( get_option( 'qlqr_trust_proxy_headers', 0 ) ); ?> /> <?php esc_html_e( 'Read the visitor\'s real IP from proxy headers', 'plugnova-link-shortener-qr' ); ?></label>
						<p class="description"><?php esc_html_e( 'Turn this on only if your site sits behind Cloudflare, a CDN, or a reverse proxy. Without it the plugin records the proxy\'s own address, so every visitor is filed under the proxy\'s country — usually making the whole world look like one place. Leave it off otherwise: on a site reachable directly, these headers can be forged by the visitor. The System Health panel below shows which address is being recorded right now.', 'plugnova-link-shortener-qr' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'REST API', 'plugnova-link-shortener-qr' ); ?></th>
					<td><label><input type="checkbox" name="qlqr_rest_api_enabled" value="1" <?php checked( get_option( 'qlqr_rest_api_enabled', 1 ) ); ?> /> <?php esc_html_e( 'Enable the REST API endpoints', 'plugnova-link-shortener-qr' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Keyword Auto-Linking', 'plugnova-link-shortener-qr' ); ?></th>
					<td>
						<label><input type="checkbox" name="qlqr_keyword_autolink_enabled" value="1" <?php checked( get_option( 'qlqr_keyword_autolink_enabled', 1 ) ); ?> /> <?php esc_html_e( 'Automatically link configured keywords found in post/page content', 'plugnova-link-shortener-qr' ); ?></label>
						<p class="description"><?php esc_html_e( 'Keywords are configured per link: open a link from the Links tab and use its "Keyword Auto-Linking" section.', 'plugnova-link-shortener-qr' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<div class="qlqr-panel">
			<h2><?php esc_html_e( 'Security', 'plugnova-link-shortener-qr' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="qlqr_rate_limit_per_min"><?php esc_html_e( 'Rate Limit (requests/minute per visitor)', 'plugnova-link-shortener-qr' ); ?></label></th>
					<td><input type="number" id="qlqr_rate_limit_per_min" name="qlqr_rate_limit_per_min" min="5" max="1000" value="<?php echo esc_attr( get_option( 'qlqr_rate_limit_per_min', 60 ) ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Allowed Roles', 'plugnova-link-shortener-qr' ); ?></th>
					<td>
						<?php foreach ( $roles as $qlqr_role_slug => $qlqr_role_data ) : ?>
							<label style="display:block;">
								<input type="checkbox" name="qlqr_allowed_roles[]" value="<?php echo esc_attr( $qlqr_role_slug ); ?>" <?php checked( in_array( $qlqr_role_slug, $qlqr_allowed_roles, true ) ); ?> />
								<?php echo esc_html( translate_user_role( $qlqr_role_data['name'] ) ); ?>
							</label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'Which roles (in addition to Administrators) may manage links.', 'plugnova-link-shortener-qr' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Uninstall', 'plugnova-link-shortener-qr' ); ?></th>
					<td>
						<label><input type="checkbox" name="qlqr_delete_on_uninstall" value="1" <?php checked( get_option( 'qlqr_delete_on_uninstall', 0 ) ); ?> /> <?php esc_html_e( 'Delete all links, clicks, settings, and generated QR images when the plugin is uninstalled', 'plugnova-link-shortener-qr' ); ?></label>
					</td>
				</tr>
			</table>
		</div>

		<?php submit_button(); ?>
	</form>

	<div class="qlqr-panel">
		<h2><?php esc_html_e( 'GeoIP Country Database', 'plugnova-link-shortener-qr' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Powers the "Top Countries" analytics by resolving visitor IPs to countries entirely on your own server — no per-visitor external API calls. The database itself (~1.4MB) is downloaded once from a public, license-free dataset rather than bundled in the plugin, to keep the plugin package small.', 'plugnova-link-shortener-qr' ); ?>
		</p>
		<div id="qlqr-geoip-status">
			<span class="spinner is-active" style="float:none;"></span> <?php esc_html_e( 'Checking status…', 'plugnova-link-shortener-qr' ); ?>
		</div>
		<p class="submit">
			<button type="button" class="button button-primary" id="qlqr-geoip-download"><?php esc_html_e( 'Download / Update Database', 'plugnova-link-shortener-qr' ); ?></button>
			<button type="button" class="button" id="qlqr-geoip-remove" style="display:none;"><?php esc_html_e( 'Remove Database', 'plugnova-link-shortener-qr' ); ?></button>
		</p>
	</div>

	<div class="qlqr-panel">
		<h2><?php esc_html_e( 'System Health', 'plugnova-link-shortener-qr' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Checks your hosting environment for the settings and PHP extensions the plugin depends on.', 'plugnova-link-shortener-qr' ); ?>
		</p>
		<div id="qlqr-health-results">
			<span class="spinner is-active" style="float:none;"></span> <?php esc_html_e( 'Running checks…', 'plugnova-link-shortener-qr' ); ?>
		</div>
		<p class="submit">
			<button type="button" class="button" id="qlqr-health-recheck"><?php esc_html_e( 'Re-run Checks', 'plugnova-link-shortener-qr' ); ?></button>
		</p>
	</div>

	<div class="qlqr-panel">
		<h2><?php esc_html_e( 'Database Repair Tool', 'plugnova-link-shortener-qr' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Checks for missing/outdated database tables and orphaned rows (e.g. clicks left behind by a link that no longer exists), and can fix everything it finds in one click.', 'plugnova-link-shortener-qr' ); ?>
		</p>
		<div id="qlqr-db-results">
			<span class="spinner is-active" style="float:none;"></span> <?php esc_html_e( 'Running checks…', 'plugnova-link-shortener-qr' ); ?>
		</div>
		<p class="submit">
			<button type="button" class="button" id="qlqr-db-recheck"><?php esc_html_e( 'Re-run Checks', 'plugnova-link-shortener-qr' ); ?></button>
			<button type="button" class="button button-primary" id="qlqr-db-repair"><?php esc_html_e( 'Repair Now', 'plugnova-link-shortener-qr' ); ?></button>
		</p>
	</div>
</div>
