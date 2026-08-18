<?php
/**
 * Privacy-preserving IP address handling. Raw IPs are never stored.
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IpHash
 */
final class IpHash {

	/**
	 * Get the visitor's IP address from the request, validated and with proxy-header awareness.
	 *
	 * @return string Empty string if no valid IP could be determined.
	 */
	public static function get_request_ip(): string {
		/*
		 * Proxy headers are read only when the site owner says the site sits behind a proxy or CDN,
		 * because a client can put anything in them when it can reach the origin directly. Off by
		 * default: REMOTE_ADDR cannot be spoofed, so an unconfigured site is never given forged
		 * geography. On a site that IS behind Cloudflare or similar, REMOTE_ADDR is the proxy's own
		 * address instead of the visitor's — which files every visitor under the proxy's country —
		 * so the setting exists to correct that without needing a code snippet.
		 *
		 * The filter still overrides the setting, so existing code using it keeps working.
		 */
		$trust_proxy = apply_filters( 'qlqr_trust_proxy_headers', (bool) get_option( 'qlqr_trust_proxy_headers', 0 ) );

		if ( $trust_proxy ) {
			foreach ( self::proxy_header_candidates() as $candidate ) {
				/*
				 * Public addresses only. Hosts that put a reverse proxy on the same machine forward
				 * with X-Forwarded-For: 127.0.0.1, and some send the private LAN address of an
				 * internal hop — neither is the visitor. Accepting the first syntactically valid
				 * address would file every visitor on the site under one loopback address: no
				 * country at all, and every visitor counted as the same person for unique clicks.
				 */
				if ( self::is_public_ip( $candidate ) ) {
					return $candidate;
				}
			}
		}

		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$remote = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
			if ( filter_var( $remote, FILTER_VALIDATE_IP ) ) {
				return $remote;
			}
		}

		return '';
	}

	/**
	 * Every address carried by a proxy header on this request, in the order they should be trusted.
	 *
	 * Exposed to the System Health panel as well, so the diagnostic there reports exactly what this
	 * method would act on rather than re-deriving it and risking the two disagreeing.
	 *
	 * @return string[] Syntactically valid addresses; may include private/loopback ones.
	 */
	public static function proxy_header_candidates(): array {
		/*
		 * Single-address headers first, most specific first: each is set by one named provider and
		 * carries the client address only. X-Forwarded-For comes last because it is a list that any
		 * hop may append to, so its left-most entry is the least trustworthy of the set.
		 */
		$headers = array(
			'HTTP_CF_CONNECTING_IP',   // Cloudflare
			'HTTP_TRUE_CLIENT_IP',     // Cloudflare Enterprise, Akamai
			'HTTP_X_SUCURI_CLIENTIP',  // Sucuri
			'HTTP_INCAP_CLIENT_IP',    // Imperva/Incapsula
			'HTTP_X_REAL_IP',          // nginx reverse proxies
			'HTTP_X_CLUSTER_CLIENT_IP',
			'HTTP_CLIENT_IP',
			'HTTP_X_FORWARDED_FOR',
		);

		$candidates = array();

		foreach ( $headers as $header ) {
			if ( empty( $_SERVER[ $header ] ) ) {
				continue;
			}

			$raw = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );

			// X-Forwarded-For is a comma-separated chain; the others hold a single address, and
			// splitting them is harmless.
			foreach ( explode( ',', $raw ) as $value ) {
				$value = trim( $value );

				// An IPv6 address may arrive bracketed and with a port, e.g. "[2001:db8::1]:443".
				if ( str_starts_with( $value, '[' ) ) {
					$value = (string) strtok( ltrim( $value, '[' ), ']' );
				}

				if ( filter_var( $value, FILTER_VALIDATE_IP ) ) {
					$candidates[] = $value;
				}
			}
		}

		return $candidates;
	}

	/**
	 * Whether an address is a routable public one — i.e. could actually belong to a visitor.
	 *
	 * @param string $ip Address to test.
	 * @return bool
	 */
	public static function is_public_ip( string $ip ): bool {
		return (bool) filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * One-way hash of an IP address, salted with the site's AUTH_SALT so it cannot be reversed
	 * or correlated with hashes from other sites.
	 *
	 * @param string $ip Raw IP address.
	 * @return string 64-character hex hash, or empty string if $ip is empty.
	 */
	public static function hash( string $ip ): string {
		if ( '' === $ip ) {
			return '';
		}

		$salt = defined( 'AUTH_SALT' ) ? AUTH_SALT : 'qlqr-fallback-salt';

		return hash( 'sha256', $ip . $salt );
	}
}
