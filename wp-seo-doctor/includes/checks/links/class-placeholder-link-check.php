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

class Placeholder_Link_Check extends Check {

	public function get_id() {
		return 'placeholder-link';
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
		$links = $xpath->query( '//a[@href]' );

		$count = 0;

		foreach ( $links as $link ) {
			$href = trim( $link->getAttribute( 'href' ) );

			if ( '' === $href || '#' === $href || 0 === stripos( $href, 'javascript:' ) ) {
				++$count;
			}
		}

		if ( $count > 0 ) {
			return array(
				$this->issue(
					$context,
					self::SEVERITY_LOW,
					sprintf(
						/* translators: %d: number of placeholder links found. */
						__( '%d link(s) use a placeholder href ("#" or javascript:) instead of a real URL.', 'wp-seo-doctor' ),
						$count
					),
					array( 'count' => $count )
				),
			);
		}

		return array();
	}
}
