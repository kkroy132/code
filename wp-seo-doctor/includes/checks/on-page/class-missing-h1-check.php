<?php
/**
 * @package SEODoc
 */

namespace SEODoc\Checks\On_Page;

use SEODoc\Checks\Check;
use SEODoc\Checks\Scan_Context;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Missing_H1_Check extends Check {

	public function get_id() {
		return 'missing-h1';
	}

	public function get_category() {
		return 'on-page';
	}

	public function applies_to( Scan_Context $context ) {
		return 'post' === $context->get_object_type() && $context->get_post();
	}

	public function run( Scan_Context $context ) {
		$dom = $context->get_dom();
		if ( ! $dom ) {
			return array(); // Fetch failed; don't guess.
		}

		$xpath = new \DOMXPath( $dom );

		if ( 0 === $xpath->query( '//h1' )->length ) {
			return array(
				$this->issue(
					$context,
					self::SEVERITY_HIGH,
					__( 'This page has no H1 heading.', 'wp-seo-doctor' )
				),
			);
		}

		return array();
	}
}
