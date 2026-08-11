<?php
/**
 * The single outbound-fetch wrapper. Every feature that requests a URL —
 * broken-link checks, redirect-chain follows, sitemap/robots fetches,
 * eventual Pro AI/license calls — goes through here. Nothing else in the
 * plugin is allowed to call wp_remote_get() directly (Step 1 §9).
 *
 * @package SEODoc
 */

namespace SEODoc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Http_Client {

	const MAX_REDIRECTS = 3;

	const TIMEOUT = 10;

	/**
	 * @param string $url
	 * @param array  $args wp_remote_request() args to merge over the safe defaults.
	 * @return array|\WP_Error
	 */
	public static function get( $url, $args = array() ) {
		return self::request( 'GET', $url, $args );
	}

	/**
	 * @param string $url
	 * @param array  $args
	 * @return array|\WP_Error
	 */
	public static function head( $url, $args = array() ) {
		return self::request( 'HEAD', $url, $args );
	}

	/**
	 * Follows redirects manually (redirection => 0 on the underlying
	 * request) so every hop is re-validated before it's fetched, not just
	 * the original URL — a redirect chain that lands on a private IP is
	 * blocked at the hop that reaches it, not just at the start.
	 */
	private static function request( $method, $url, $args = array() ) {
		$hops = 0;

		while ( $hops <= self::MAX_REDIRECTS ) {
			$check = self::validate_url( $url );
			if ( is_wp_error( $check ) ) {
				return $check;
			}

			$response = wp_remote_request(
				$url,
				array_merge(
					array(
						'method'              => $method,
						'timeout'             => self::TIMEOUT,
						'redirection'         => 0,
						'reject_unsafe_urls'  => true,
						'sslverify'           => true,
						'user-agent'          => 'WP SEO Doctor/' . SEODOC_VERSION . '; ' . home_url( '/' ),
					),
					$args
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
				$location = wp_remote_retrieve_header( $response, 'location' );
				if ( empty( $location ) ) {
					return $response;
				}
				$url = self::resolve_location( $url, $location );
				++$hops;
				continue;
			}

			return $response;
		}

		return new \WP_Error(
			'seodoc_too_many_redirects',
			__( 'Too many redirects while fetching this URL.', 'wp-seo-doctor' )
		);
	}

	/**
	 * Scheme allowlist + WP core's own unsafe-URL rejection + an explicit
	 * DNS-resolved private/loopback/link-local IP check (the link-local
	 * range 169.254.0.0/16 matters specifically because it covers cloud
	 * metadata endpoints like 169.254.169.254).
	 *
	 * Known residual risk: the IP is validated here and the HTTP request
	 * resolves DNS again a moment later, so a fast DNS-rebind between the
	 * two lookups isn't closed by this alone — that requires pinning the
	 * resolved IP at the transport layer, which WP's HTTP API does not
	 * expose. Flagged for the Step 15 security audit rather than solved
	 * here with a bespoke cURL layer.
	 *
	 * @return true|\WP_Error
	 */
	private static function validate_url( $url ) {
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new \WP_Error( 'seodoc_invalid_scheme', __( 'Only http(s) URLs may be fetched.', 'wp-seo-doctor' ) );
		}

		if ( ! wp_http_validate_url( $url ) ) {
			return new \WP_Error( 'seodoc_unsafe_url', __( 'This URL was blocked as unsafe to fetch.', 'wp-seo-doctor' ) );
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( self::host_resolves_to_disallowed_ip( $host ) ) {
			return new \WP_Error(
				'seodoc_private_ip',
				__( 'This URL resolves to a private or internal address and cannot be fetched.', 'wp-seo-doctor' )
			);
		}

		return true;
	}

	private static function host_resolves_to_disallowed_ip( $host ) {
		$ip = filter_var( $host, FILTER_VALIDATE_IP ) ? $host : gethostbyname( $host );

		if ( $ip === $host && ! filter_var( $host, FILTER_VALIDATE_IP ) ) {
			// Could not resolve; let the HTTP layer fail naturally rather
			// than guessing an unresolved host is safe.
			return false;
		}

		return ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	private static function resolve_location( $base_url, $location ) {
		if ( wp_parse_url( $location, PHP_URL_HOST ) ) {
			return $location;
		}

		return \WP_Http::make_absolute_url( $location, $base_url );
	}
}
