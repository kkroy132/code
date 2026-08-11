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

class Pagination_Check extends Check {

	public function get_id() {
		return 'pagination-missing';
	}

	public function get_category() {
		return 'technical';
	}

	/**
	 * Only applies to posts actually split into multiple pages via the
	 * WordPress <!--nextpage--> tag.
	 */
	public function applies_to( Scan_Context $context ) {
		return 'post' === $context->get_object_type()
			&& $context->get_post()
			&& false !== strpos( $context->get_content(), '<!--nextpage-->' );
	}

	public function run( Scan_Context $context ) {
		$dom = $context->get_dom();
		if ( ! $dom ) {
			return array();
		}

		$xpath    = new \DOMXPath( $dom );
		$has_next = $xpath->query( '//link[@rel="next"] | //a[@rel="next"]' )->length > 0;

		if ( ! $has_next ) {
			return array(
				$this->issue(
					$context,
					self::SEVERITY_LOW,
					__( 'This multi-page post has no rel="next" pagination link to its next page.', 'wp-seo-doctor' )
				),
			);
		}

		return array();
	}
}
