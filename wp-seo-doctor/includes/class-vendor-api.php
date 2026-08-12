<?php
/**
 * Shared client for the vendor's own backend — the same server Step 12's
 * OAuth proxy talks to, generalized here because Step 13's AI layer
 * needs the identical shape (license-key-authed JSON calls to a
 * placeholder base URL that isn't live yet — see docs/13). Step 12's
 * Oauth class keeps its own already-shipped, already-correct
 * implementation rather than being refactored onto this for no
 * functional reason; both read the same seodoc_pro_api_base filter, so
 * they can't drift on the actual base URL even though the code isn't
 * shared line-for-line.
 *
 * @package SEODoc
 */

namespace SEODoc;

use SEODoc\Http_Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Vendor_Api {

	public static function base_url() {
		return apply_filters( 'seodoc_pro_api_base', 'https://api.wpseodoctor.com' );
	}

	/**
	 * @param string $path Leading-slash path, e.g. '/ai/generate-title'.
	 * @param array  $body
	 * @return array|\WP_Error Decoded JSON body.
	 */
	public static function post_json( $path, array $body = array() ) {
		return self::handle_response(
			Http_Client::post(
				self::base_url() . $path,
				array(
					'headers' => self::auth_headers( array( 'Content-Type' => 'application/json' ) ),
					'body'    => wp_json_encode( $body ),
				)
			)
		);
	}

	/**
	 * @param string $path
	 * @param array  $query
	 * @return array|\WP_Error Decoded JSON body.
	 */
	public static function get_json( $path, array $query = array() ) {
		$url = $query ? add_query_arg( $query, self::base_url() . $path ) : self::base_url() . $path;

		return self::handle_response(
			Http_Client::get( $url, array( 'headers' => self::auth_headers() ) )
		);
	}

	private static function auth_headers( array $extra = array() ) {
		return array_merge(
			array(
				'X-License-Key' => (string) get_option( 'seodoc_pro_license_key' ),
				'X-Site-Url'    => home_url( '/' ),
			),
			$extra
		);
	}

	private static function handle_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 ) {
			return new \WP_Error(
				'seodoc_vendor_api_error',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The WP SEO Doctor service returned an error (HTTP %d).', 'wp-seo-doctor' ),
					$code
				)
			);
		}

		return json_decode( (string) wp_remote_retrieve_body( $response ), true );
	}
}
