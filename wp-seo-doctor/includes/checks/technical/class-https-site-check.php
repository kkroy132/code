<?php
/**
 * @package SEODoc
 */

namespace SEODoc\Checks\Technical;

use SEODoc\Checks\Scan_Level_Check;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Https_Site_Check extends Scan_Level_Check {

	public function get_id() {
		return 'site-not-https';
	}

	public function get_category() {
		return 'technical';
	}

	public function run() {
		if ( 'https' !== wp_parse_url( home_url(), PHP_URL_SCHEME ) ) {
			return array(
				$this->issue(
					self::SEVERITY_CRITICAL,
					__( 'This site is not served over HTTPS.', 'wp-seo-doctor' ),
					home_url( '/' )
				),
			);
		}

		return array();
	}
}
