<?php
/**
 * @package SEODoc
 */

namespace SEODoc\Checks\Links;

use SEODoc\Checks\Check;
use SEODoc\Checks\Scan_Context;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Missing_Rel_Noopener_Check extends Check {

	public function get_id() {
		return 'missing-rel-noopener';
	}

	public function get_category() {
		return 'links';
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
		$links = $xpath->query( '//a[@target="_blank"]' );

		$count = 0;

		foreach ( $links as $link ) {
			$rel = strtolower( $link->getAttribute( 'rel' ) );

			if ( false === strpos( $rel, 'noopener' ) ) {
				++$count;
			}
		}

		if ( $count > 0 ) {
			return array(
				$this->issue(
					$context,
					self::SEVERITY_LOW,
					sprintf(
						/* translators: %d: number of links missing rel="noopener". */
						__( '%d link(s) open in a new tab without rel="noopener", a minor security/performance issue.', 'wp-seo-doctor' ),
						$count
					),
					array( 'count' => $count )
				),
			);
		}

		return array();
	}
}
