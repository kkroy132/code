<?php
/**
 * Thin wrapper over the real Google Search Console API v1
 * (searchAnalytics.query) — a genuine, documented, public Google API
 * endpoint; only the OAuth credentials behind it are proxied (see
 * class-oauth.php). Every request still goes through Http_Client, same
 * as every other outbound fetch in either plugin (Step 1 §9).
 *
 * @package SEODoc
 */

namespace SEODoc\Gsc;

use SEODoc\Http_Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gsc_Client {

	const API_BASE = 'https://searchconsole.googleapis.com/webmasters/v3';

	/**
	 * @param array $args {
	 *     @type string   $start_date  Y-m-d.
	 *     @type string   $end_date    Y-m-d.
	 *     @type string[] $dimensions  Default ['page'].
	 *     @type int      $row_limit   Default 100.
	 * }
	 * @return array|\WP_Error Decoded JSON response body.
	 */
	public static function query_search_analytics( array $args ) {
		$token = Oauth::get_valid_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$site_url = rawurlencode( Oauth::get_property_url() );
		$endpoint = self::API_BASE . "/sites/{$site_url}/searchAnalytics/query";

		$response = Http_Client::post(
			$endpoint,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'startDate'  => $args['start_date'],
						'endDate'    => $args['end_date'],
						'dimensions' => isset( $args['dimensions'] ) ? $args['dimensions'] : array( 'page' ),
						'rowLimit'   => isset( $args['row_limit'] ) ? $args['row_limit'] : 100,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 ) {
			return new \WP_Error(
				'seodoc_gsc_api_error',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Google Search Console API returned an error (HTTP %d).', 'wp-seo-doctor' ),
					$code
				)
			);
		}

		return json_decode( (string) wp_remote_retrieve_body( $response ), true );
	}
}
