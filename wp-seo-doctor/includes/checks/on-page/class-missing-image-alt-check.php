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

class Missing_Image_Alt_Check extends Check {

	public function get_id() {
		return 'missing-image-alt';
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

		$xpath  = new \DOMXPath( $dom );
		$images = $xpath->query( '//img' );

		$missing = 0;

		foreach ( $images as $image ) {
			// Decorative images that are already correctly marked
			// invisible to assistive tech/search don't need ALT text.
			if ( 'presentation' === $image->getAttribute( 'role' ) || 'true' === $image->getAttribute( 'aria-hidden' ) ) {
				continue;
			}

			if ( ! $image->hasAttribute( 'alt' ) || '' === trim( $image->getAttribute( 'alt' ) ) ) {
				++$missing;
			}
		}

		if ( $missing > 0 ) {
			return array(
				$this->issue(
					$context,
					self::SEVERITY_MEDIUM,
					sprintf(
						/* translators: %d: number of images missing ALT text. */
						__( '%d image(s) on this page are missing descriptive ALT text.', 'wp-seo-doctor' ),
						$missing
					),
					array( 'missing_count' => $missing )
				),
			);
		}

		return array();
	}
}
