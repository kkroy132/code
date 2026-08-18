<?php
/**
 * Read-only environment/configuration checks shown on the Settings page's "System Health" panel —
 * catches the hosting-environment problems that most commonly cause support requests (missing GD,
 * WP-Cron disabled, permalinks set to "plain", non-writable uploads folder, etc.) before they turn
 * into a confusing bug report.
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SystemHealth
 */
final class SystemHealth {

	/**
	 * Run every check.
	 *
	 * @return array<int, array{label: string, status: string, detail: string}> status is 'ok'|'warning'|'error'.
	 */
	public static function run_checks(): array {
		$results   = array();
		$results[] = self::php_version_check();
		$results[] = self::wp_version_check();
		$results[] = self::gd_extension_check();
		$results[] = self::permalinks_check();
		$results[] = self::uploads_writable_check();
		$results[] = self::wp_cron_check();
		$results[] = self::db_version_check();
		$results[] = self::geoip_check();
		$results[] = self::visitor_ip_check();

		return $results;
	}

	/**
	 * @return array{label: string, status: string, detail: string}
	 */
	private static function php_version_check(): array {
		$ok = version_compare( PHP_VERSION, QLQR_MIN_PHP, '>=' );

		return array(
			'label'  => __( 'PHP Version', 'plugnova-link-shortener-qr' ),
			'status' => $ok ? 'ok' : 'error',
			'detail' => $ok
				/* translators: 1: the PHP version running on this server, 2: the minimum PHP version the plugin requires */
				? sprintf( __( '%1$s (meets the %2$s minimum).', 'plugnova-link-shortener-qr' ), PHP_VERSION, QLQR_MIN_PHP )
				/* translators: 1: the PHP version running on this server, 2: the minimum PHP version the plugin requires */
				: sprintf( __( '%1$s is below the required minimum of %2$s. Ask your host to upgrade PHP.', 'plugnova-link-shortener-qr' ), PHP_VERSION, QLQR_MIN_PHP ),
		);
	}

	/**
	 * @return array{label: string, status: string, detail: string}
	 */
	private static function wp_version_check(): array {
		global $wp_version;

		$ok = isset( $wp_version ) && version_compare( $wp_version, QLQR_MIN_WP, '>=' );

		return array(
			'label'  => __( 'WordPress Version', 'plugnova-link-shortener-qr' ),
			'status' => $ok ? 'ok' : 'error',
			'detail' => $ok
				/* translators: 1: the WordPress version running on this site, 2: the minimum WordPress version the plugin requires */
				? sprintf( __( '%1$s (meets the %2$s minimum).', 'plugnova-link-shortener-qr' ), $wp_version, QLQR_MIN_WP )
				/* translators: 1: the WordPress version running on this site, 2: the minimum WordPress version the plugin requires */
				: sprintf( __( '%1$s is below the required minimum of %2$s. Please update WordPress.', 'plugnova-link-shortener-qr' ), $wp_version ?? '?', QLQR_MIN_WP ),
		);
	}

	/**
	 * @return array{label: string, status: string, detail: string}
	 */
	private static function gd_extension_check(): array {
		$ok = extension_loaded( 'gd' );

		return array(
			'label'  => __( 'GD Image Library', 'plugnova-link-shortener-qr' ),
			'status' => $ok ? 'ok' : 'warning',
			'detail' => $ok
				? __( 'Available — PNG/JPG QR codes can be generated.', 'plugnova-link-shortener-qr' )
				: __( 'Not available — QR codes can still be generated as SVG, but PNG/JPG output and logo overlays will not work. Ask your host to enable the GD PHP extension.', 'plugnova-link-shortener-qr' ),
		);
	}

	/**
	 * @return array{label: string, status: string, detail: string}
	 */
	private static function permalinks_check(): array {
		$structure = get_option( 'permalink_structure', '' );
		$ok        = '' !== $structure;

		return array(
			'label'  => __( 'Permalink Structure', 'plugnova-link-shortener-qr' ),
			'status' => $ok ? 'ok' : 'error',
			'detail' => $ok
				? __( 'A custom permalink structure is active — short links and bio pages will work.', 'plugnova-link-shortener-qr' )
				: __( 'Permalinks are set to "Plain" — short links and bio pages will not resolve. Go to Settings > Permalinks and choose any other option.', 'plugnova-link-shortener-qr' ),
		);
	}

	/**
	 * @return array{label: string, status: string, detail: string}
	 */
	private static function uploads_writable_check(): array {
		$upload_dir = wp_upload_dir();
		$ok         = empty( $upload_dir['error'] ) && wp_is_writable( $upload_dir['basedir'] );

		return array(
			'label'  => __( 'Uploads Directory Writable', 'plugnova-link-shortener-qr' ),
			'status' => $ok ? 'ok' : 'error',
			'detail' => $ok
				? __( 'Writable — generated QR code images can be saved.', 'plugnova-link-shortener-qr' )
				: __( 'Not writable — QR code image generation will fail. Check your uploads folder permissions.', 'plugnova-link-shortener-qr' ),
		);
	}

	/**
	 * @return array{label: string, status: string, detail: string}
	 */
	private static function wp_cron_check(): array {
		$disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

		return array(
			'label'  => __( 'WP-Cron', 'plugnova-link-shortener-qr' ),
			'status' => $disabled ? 'warning' : 'ok',
			'detail' => $disabled
				? __( 'DISABLE_WP_CRON is set — the daily broken-link check and Activity Log cleanup will not run unless a real system cron job calls wp-cron.php instead.', 'plugnova-link-shortener-qr' )
				: __( 'Enabled — the daily broken-link check and Activity Log cleanup will run on schedule.', 'plugnova-link-shortener-qr' ),
		);
	}

	/**
	 * @return array{label: string, status: string, detail: string}
	 */
	private static function db_version_check(): array {
		$installed = get_option( 'qlqr_db_version', '' );
		$ok        = $installed === QLQR_DB_VERSION;

		return array(
			'label'  => __( 'Database Schema', 'plugnova-link-shortener-qr' ),
			'status' => $ok ? 'ok' : 'warning',
			'detail' => $ok
				/* translators: %s: the installed database schema version, e.g. "1.0.0" */
				? sprintf( __( 'Up to date (%s).', 'plugnova-link-shortener-qr' ), $installed )
				: __( 'Out of date — use the Database Repair Tool below to sync it.', 'plugnova-link-shortener-qr' ),
		);
	}

	/**
	 * @return array{label: string, status: string, detail: string}
	 */
	private static function geoip_check(): array {
		$status = GeoIpInstaller::status();
		$ok     = ! empty( $status['installed'] );

		return array(
			'label'  => __( 'GeoIP Database', 'plugnova-link-shortener-qr' ),
			'status' => $ok ? 'ok' : 'warning',
			'detail' => $ok
				? __( 'Installed — country-based analytics and targeting are available.', 'plugnova-link-shortener-qr' )
				: __( 'Not installed — country-based analytics and targeting will be empty until you download it below.', 'plugnova-link-shortener-qr' ),
		);
	}

	/**
	 * Report which IP the plugin is actually recording for visitors, and the country it resolves to.
	 *
	 * When a site sits behind Cloudflare or any other reverse proxy, REMOTE_ADDR is the proxy's own
	 * address rather than the visitor's — and those addresses are typically registered to the
	 * provider's home country, so every visitor is filed under one wrong country. The symptom
	 * ("everyone shows as US") gives no hint of the cause, so this states plainly what address is
	 * being used, whether a proxy header carrying the real one is present, and what the two resolve
	 * to. Everything shown here is about the current administrator's own request.
	 *
	 * @return array{label: string, status: string, detail: string}
	 */
	private static function visitor_ip_check(): array {
		$recorded = IpHash::get_request_ip();
		$country  = '' !== $recorded ? ( GeoLocator::country_for_ip( $recorded ) ?? '?' ) : '?';

		if ( '' === $recorded ) {
			return array(
				'label'  => __( 'Visitor IP Detection', 'plugnova-link-shortener-qr' ),
				'status' => 'error',
				'detail' => __( 'No usable visitor IP could be read, so country analytics and unique-click counting will not work.', 'plugnova-link-shortener-qr' ),
			);
		}

		$candidates = IpHash::proxy_header_candidates();
		$public     = array_values( array_filter( $candidates, array( IpHash::class, 'is_public_ip' ) ) );

		// A proxy header carrying a real, routable address that differs from the one being recorded
		// is the case the setting exists for.
		if ( ! empty( $public ) && $public[0] !== $recorded ) {
			return array(
				'label'  => __( 'Visitor IP Detection', 'plugnova-link-shortener-qr' ),
				'status' => 'warning',
				'detail' => sprintf(
					/* translators: 1: IP currently recorded, 2: its country code, 3: the real visitor IP a proxy header carries, 4: its country code. */
					__( 'Recording %1$s (%2$s), but a proxy header carries %3$s (%4$s) — this site is behind a proxy or CDN, so every visitor is being filed under the proxy\'s country. Turn on "Behind a Proxy or CDN" in Settings to record the real visitor address.', 'plugnova-link-shortener-qr' ),
					$recorded,
					$country,
					$public[0],
					GeoLocator::country_for_ip( $public[0] ) ?? '?'
				),
			);
		}

		// A proxy header carrying the same public address already being recorded confirms REMOTE_ADDR
		// is already correct — nothing for the proxy setting to fix.
		if ( ! empty( $public ) && $public[0] === $recorded ) {
			return array(
				'label'  => __( 'Visitor IP Detection', 'plugnova-link-shortener-qr' ),
				'status' => 'ok',
				'detail' => sprintf(
					/* translators: 1: the IP being recorded for the current request, 2: its resolved country code. */
					__( 'Recording %1$s (%2$s) — a proxy header agrees with this address, so it is already correct.', 'plugnova-link-shortener-qr' ),
					$recorded,
					$country
				),
			);
		}

		/*
		 * Proxy headers exist but hold only private or loopback addresses — the signature of a
		 * reverse proxy running on the same machine as PHP. Those are not visitor addresses, and
		 * turning the setting on would file every visitor under 127.0.0.1: no country at all, and
		 * every visitor counted as one person. Say so plainly, and list what was found, because the
		 * real client address may be in a header this plugin does not know about yet.
		 */
		if ( ! empty( $candidates ) && empty( $public ) ) {
			return array(
				'label'  => __( 'Visitor IP Detection', 'plugnova-link-shortener-qr' ),
				'status' => 'warning',
				'detail' => sprintf(
					/* translators: 1: IP currently recorded, 2: its country code, 3: comma-separated list of addresses found in proxy headers. */
					__( 'Recording %1$s (%2$s). Proxy headers are present but carry only internal addresses (%3$s), which are not visitor addresses — do NOT turn on "Behind a Proxy or CDN", as that would file every visitor under one internal address. Your host is hiding the real visitor IP; ask them which header carries it.', 'plugnova-link-shortener-qr' ),
					$recorded,
					$country,
					implode( ', ', array_unique( $candidates ) )
				),
			);
		}

		return array(
			'label'  => __( 'Visitor IP Detection', 'plugnova-link-shortener-qr' ),
			'status' => 'ok',
			'detail' => sprintf(
				/* translators: 1: the IP being recorded for the current request, 2: its resolved country code. */
				__( 'Recording %1$s (%2$s) — no proxy header carrying a different address was found.', 'plugnova-link-shortener-qr' ),
				$recorded,
				$country
			),
		);
	}
}
