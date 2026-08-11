<?php
/**
 * @package SEODoc
 */

namespace SEODoc\Checks\Technical;

use SEODoc\Checks\Check;
use SEODoc\Checks\Scan_Context;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Missing_Schema_Check extends Check {

	public function get_id() {
		return 'missing-schema';
	}

	public function get_category() {
		return 'technical';
	}

	public function applies_to( Scan_Context $context ) {
		return 'post' === $context->get_object_type() && $context->get_post();
	}

	public function run( Scan_Context $context ) {
		$dom = $context->get_dom();
		if ( ! $dom ) {
			return array();
		}

		$xpath = new \DOMXPath( $dom );

		if ( 0 === $xpath->query( '//script[@type="application/ld+json"]' )->length ) {
			return array(
				$this->issue(
					$context,
					self::SEVERITY_LOW,
					__( 'No structured data (schema) was found on this page.', 'wp-seo-doctor' )
				),
			);
		}

		return array();
	}
}
