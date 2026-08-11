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

class Empty_Anchor_Text_Check extends Check {

	public function get_id() {
		return 'empty-link-text';
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

		$empty = 0;

		foreach ( $links as $link ) {
			$text      = trim( $link->textContent );
			$aria      = trim( (string) $link->getAttribute( 'aria-label' ) );
			$has_image = $xpath->query( './/img[@alt]', $link )->length > 0;

			if ( '' === $text && '' === $aria && ! $has_image ) {
				++$empty;
			}
		}

		if ( $empty > 0 ) {
			return array(
				$this->issue(
					$context,
					self::SEVERITY_MEDIUM,
					sprintf(
						/* translators: %d: number of links with no accessible text. */
						__( '%d link(s) have no visible text or accessible label.', 'wp-seo-doctor' ),
						$empty
					),
					array( 'count' => $empty )
				),
			);
		}

		return array();
	}
}
