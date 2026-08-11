<?php
/**
 * @package SEODoc
 */

namespace SEODoc\Checks\Technical;

use SEODoc\Checks\Scan_Level_Check;
use SEODoc\Http_Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sitemap_Availability_Check extends Scan_Level_Check {

	public function get_id() {
		return 'sitemap-unavailable';
	}

	public function get_category() {
		return 'technical';
	}

	public function run() {
		$candidates = array(
			home_url( '/wp-sitemap.xml' ),   // WordPress core (5.5+).
			home_url( '/sitemap_index.xml' ), // Yoast / Rank Math.
			home_url( '/sitemap.xml' ),       // Common fallback.
		);

		foreach ( $candidates as $url ) {
			$response = Http_Client::head( $url );

			if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
				return array();
			}
		}

		return array(
			$this->issue(
				self::SEVERITY_HIGH,
				__( 'No XML sitemap was found at the common sitemap locations.', 'wp-seo-doctor' ),
				home_url( '/' )
			),
		);
	}
}
