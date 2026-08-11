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

class Multiple_H1_Check extends Check {

	public function get_id() {
		return 'multiple-h1';
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
			return array();
		}

		$xpath = new \DOMXPath( $dom );
		$count = $xpath->query( '//h1' )->length;

		if ( $count > 1 ) {
			return array(
				$this->issue(
					$context,
					self::SEVERITY_MEDIUM,
					sprintf(
						/* translators: %d: number of H1 headings found. */
						__( 'This page has %d H1 headings; search engines expect exactly one.', 'wp-seo-doctor' ),
						$count
					),
					array( 'count' => $count )
				),
			);
		}

		return array();
	}
}
