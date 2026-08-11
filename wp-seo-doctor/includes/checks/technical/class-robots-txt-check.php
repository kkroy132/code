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

class Robots_Txt_Check extends Scan_Level_Check {

	public function get_id() {
		return 'robots-txt-issue';
	}

	public function get_category() {
		return 'technical';
	}

	public function run() {
		$url      = home_url( '/robots.txt' );
		$response = Http_Client::get( $url );

		if ( is_wp_error( $response ) ) {
			return array(
				$this->issue( self::SEVERITY_MEDIUM, __( 'robots.txt could not be fetched.', 'wp-seo-doctor' ), $url ),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			return array(
				$this->issue(
					self::SEVERITY_MEDIUM,
					sprintf(
						/* translators: %d: HTTP status code. */
						__( 'robots.txt returned HTTP %d.', 'wp-seo-doctor' ),
						$code
					),
					$url
				),
			);
		}

		$body = (string) wp_remote_retrieve_body( $response );

		if ( preg_match( '/^\s*Disallow:\s*\/\s*$/mi', $body ) && ! preg_match( '/^\s*Allow:/mi', $body ) ) {
			return array(
				$this->issue(
					self::SEVERITY_CRITICAL,
					__( 'robots.txt disallows all crawling of this site.', 'wp-seo-doctor' ),
					$url
				),
			);
		}

		return array();
	}
}
