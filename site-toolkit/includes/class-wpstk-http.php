<?php
/**
 * Safe HTTP helpers used by the scanner.
 *
 * @package WP_Site_Toolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around the WordPress HTTP API with conservative defaults.
 *
 * Every request made by this plugin goes through this class: bounded timeouts,
 * bounded response size, manual redirect handling and no third-party services.
 *
 * @since 1.0.0
 */
class WPSTK_HTTP {

	/**
	 * Maximum number of redirects followed while building a redirect chain.
	 */
	const MAX_REDIRECTS = 4;

	/**
	 * Maximum number of bytes read from a page body.
	 */
	const MAX_BODY_BYTES = 400000;

	/**
	 * Builds the shared request arguments.
	 *
	 * @param int   $timeout Timeout in seconds.
	 * @param array $extra   Additional arguments.
	 *
	 * @return array
	 */
	public static function args( $timeout, $extra = array() ) {
		$args = array(
			'timeout'             => max( 1, (int) $timeout ),
			'redirection'         => 0,
			'httpversion'         => '1.1',
			'user-agent'          => 'WPSiteToolkit/' . WPSTK_VERSION . '; ' . home_url( '/' ),
			'reject_unsafe_urls'  => true,
			'limit_response_size' => self::MAX_BODY_BYTES,
			'headers'             => array( 'Accept' => 'text/html,*/*;q=0.8' ),
		);

		return array_merge( $args, $extra );
	}

	/**
	 * Whether a URL is a well formed http(s) address the scanner may request.
	 *
	 * @param string $url URL to test.
	 *
	 * @return bool
	 */
	public static function is_requestable( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return false;
		}

		$parts = wp_parse_url( $url );

		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}

		return in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true );
	}

	/**
	 * Requests a URL and follows redirects one hop at a time.
	 *
	 * @param string $url     Absolute URL.
	 * @param int    $timeout Timeout in seconds.
	 *
	 * @return array {
	 *     @type string $type      One of ok, redirect, client, forbidden, not_found, server, timeout, error.
	 *     @type int    $status    Final HTTP status code, 0 when no response was received.
	 *     @type array  $chain     Ordered list of URLs visited.
	 *     @type string $final_url Last URL in the chain.
	 *     @type string $message   Human readable outcome.
	 *     @type float  $duration  Total request time in seconds.
	 * }
	 */
	public static function check_url( $url, $timeout ) {
		$result = array(
			'type'      => 'error',
			'status'    => 0,
			'chain'     => array(),
			'final_url' => $url,
			'message'   => '',
			'duration'  => 0.0,
		);

		if ( ! self::is_requestable( $url ) ) {
			$result['message'] = __( 'The address could not be parsed as a web URL.', 'site-toolkit' );

			return $result;
		}

		$start   = microtime( true );
		$current = $url;
		$hops    = 0;

		while ( $hops <= self::MAX_REDIRECTS ) {
			$result['chain'][] = $current;
			$result['final_url'] = $current;

			$response = wp_remote_head( $current, self::args( $timeout ) );
			$status   = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

			// Some servers do not implement HEAD properly; retry those with a GET.
			if ( is_wp_error( $response ) || in_array( $status, array( 0, 400, 403, 404, 405, 406, 501 ), true ) ) {
				$get_response = wp_remote_get( $current, self::args( $timeout ) );

				if ( ! is_wp_error( $get_response ) ) {
					$response = $get_response;
					$status   = (int) wp_remote_retrieve_response_code( $get_response );
				} elseif ( is_wp_error( $response ) ) {
					$response = $get_response;
				}
			}

			if ( is_wp_error( $response ) ) {
				$code              = $response->get_error_code();
				$result['status']  = 0;
				$result['type']    = ( false !== strpos( (string) $code, 'timeout' ) || false !== stripos( $response->get_error_message(), 'timed out' ) ) ? 'timeout' : 'error';
				$result['message'] = $response->get_error_message();

				break;
			}

			$result['status'] = $status;

			if ( $status >= 300 && $status < 400 ) {
				$location = wp_remote_retrieve_header( $response, 'location' );
				$location = is_array( $location ) ? reset( $location ) : $location;

				if ( ! $location ) {
					$result['type']    = 'redirect';
					$result['message'] = __( 'Redirect response without a destination.', 'site-toolkit' );

					break;
				}

				$next = self::resolve_url( $location, $current );

				if ( ! self::is_requestable( $next ) || in_array( $next, $result['chain'], true ) ) {
					$result['type']    = 'redirect';
					$result['message'] = __( 'Redirect loop or unsupported redirect target.', 'site-toolkit' );

					break;
				}

				$current = $next;
				++$hops;

				continue;
			}

			$result['type'] = self::classify_status( $status );

			break;
		}

		if ( $hops > self::MAX_REDIRECTS ) {
			$result['type']    = 'redirect';
			$result['message'] = __( 'Too many redirects.', 'site-toolkit' );
		}

		$result['duration'] = round( microtime( true ) - $start, 3 );

		if ( '' === $result['message'] ) {
			$result['message'] = self::describe( $result['type'], $result['status'] );
		}

		return $result;
	}

	/**
	 * Maps an HTTP status code to a result type.
	 *
	 * @param int $status Status code.
	 *
	 * @return string
	 */
	public static function classify_status( $status ) {
		$status = (int) $status;

		if ( $status >= 200 && $status < 300 ) {
			return 'ok';
		}

		if ( $status >= 300 && $status < 400 ) {
			return 'redirect';
		}

		if ( 404 === $status || 410 === $status ) {
			return 'not_found';
		}

		if ( 401 === $status || 403 === $status ) {
			return 'forbidden';
		}

		if ( $status >= 400 && $status < 500 ) {
			return 'client';
		}

		if ( $status >= 500 ) {
			return 'server';
		}

		return 'error';
	}

	/**
	 * Returns a readable description for a result type.
	 *
	 * @param string $type   Result type.
	 * @param int    $status Status code.
	 *
	 * @return string
	 */
	public static function describe( $type, $status = 0 ) {
		switch ( $type ) {
			case 'ok':
				/* translators: %d: HTTP status code. */
				return sprintf( __( 'Reachable (HTTP %d)', 'site-toolkit' ), (int) $status );
			case 'redirect':
				return __( 'Redirect could not be resolved', 'site-toolkit' );
			case 'not_found':
				/* translators: %d: HTTP status code. */
				return sprintf( __( 'Not found (HTTP %d)', 'site-toolkit' ), (int) $status );
			case 'forbidden':
				/* translators: %d: HTTP status code. */
				return sprintf( __( 'Access denied (HTTP %d)', 'site-toolkit' ), (int) $status );
			case 'client':
				/* translators: %d: HTTP status code. */
				return sprintf( __( 'Client error (HTTP %d)', 'site-toolkit' ), (int) $status );
			case 'server':
				/* translators: %d: HTTP status code. */
				return sprintf( __( 'Server error (HTTP %d)', 'site-toolkit' ), (int) $status );
			case 'timeout':
				return __( 'The request timed out', 'site-toolkit' );
			default:
				return __( 'The address could not be reached', 'site-toolkit' );
		}
	}

	/**
	 * Fetches a page and returns its body plus response metadata.
	 *
	 * @param string $url     Absolute URL.
	 * @param int    $timeout Timeout in seconds.
	 *
	 * @return array {
	 *     @type bool   $success  Whether a response was received.
	 *     @type int    $status   HTTP status code.
	 *     @type string $body     Response body, possibly truncated.
	 *     @type array  $headers  Lower-cased response headers.
	 *     @type float  $duration Request time in seconds.
	 *     @type string $message  Error message when the request failed.
	 * }
	 */
	public static function fetch( $url, $timeout ) {
		$out = array(
			'success'  => false,
			'status'   => 0,
			'body'     => '',
			'headers'  => array(),
			'duration' => 0.0,
			'message'  => '',
		);

		if ( ! self::is_requestable( $url ) ) {
			$out['message'] = __( 'The address could not be parsed as a web URL.', 'site-toolkit' );

			return $out;
		}

		$start    = microtime( true );
		$response = wp_remote_get( $url, self::args( $timeout, array( 'redirection' => 3 ) ) );

		$out['duration'] = round( microtime( true ) - $start, 3 );

		if ( is_wp_error( $response ) ) {
			$out['message'] = $response->get_error_message();

			return $out;
		}

		$headers = wp_remote_retrieve_headers( $response );

		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$headers = $headers->getAll();
		}

		$out['success'] = true;
		$out['status']  = (int) wp_remote_retrieve_response_code( $response );
		$out['body']    = (string) wp_remote_retrieve_body( $response );
		$out['headers'] = is_array( $headers ) ? array_change_key_case( $headers, CASE_LOWER ) : array();

		return $out;
	}

	/**
	 * Resolves a possibly relative URL against a base URL.
	 *
	 * @param string $url  Relative or absolute URL.
	 * @param string $base Base URL.
	 *
	 * @return string
	 */
	public static function resolve_url( $url, $base ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return '';
		}

		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}

		if ( 0 === strpos( $url, '//' ) ) {
			$scheme = wp_parse_url( $base, PHP_URL_SCHEME );

			return ( $scheme ? $scheme : 'https' ) . ':' . $url;
		}

		$parts = wp_parse_url( $base );

		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$root = $parts['scheme'] . '://' . $parts['host'];

		if ( ! empty( $parts['port'] ) ) {
			$root .= ':' . (int) $parts['port'];
		}

		if ( 0 === strpos( $url, '/' ) ) {
			return $root . $url;
		}

		$path = isset( $parts['path'] ) ? $parts['path'] : '/';
		$path = substr( $path, 0, (int) strrpos( $path, '/' ) + 1 );

		return $root . $path . $url;
	}
}
