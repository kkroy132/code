<?php
/**
 * @package SEODoc
 */

namespace SEODoc\Checks\On_Page;

use SEODoc\Checks\Check;
use SEODoc\Checks\Scan_Context;
use SEODoc\Checks\Seo_Meta_Reader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Title_Length_Check extends Check {

	const MIN_LENGTH = 30;
	const MAX_LENGTH = 60;

	public function get_id() {
		return 'seo-title-length';
	}

	public function get_category() {
		return 'on-page';
	}

	public function applies_to( Scan_Context $context ) {
		return 'post' === $context->get_object_type() && $context->get_post();
	}

	public function run( Scan_Context $context ) {
		$title = trim( (string) Seo_Meta_Reader::get_title( $context ) );

		if ( '' === $title ) {
			return array(); // Missing_Title_Check already flags this.
		}

		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $title ) : strlen( $title );

		if ( $length < self::MIN_LENGTH ) {
			return array(
				$this->issue(
					$context,
					self::SEVERITY_LOW,
					sprintf(
						/* translators: 1: current length, 2: recommended minimum length. */
						__( 'SEO title is short (%1$d characters). Titles under %2$d characters may waste available space in search results.', 'wp-seo-doctor' ),
						$length,
						self::MIN_LENGTH
					),
					array( 'length' => $length )
				),
			);
		}

		if ( $length > self::MAX_LENGTH ) {
			return array(
				$this->issue(
					$context,
					self::SEVERITY_LOW,
					sprintf(
						/* translators: 1: current length, 2: recommended maximum length. */
						__( 'SEO title is long (%1$d characters) and may be truncated in search results past ~%2$d characters.', 'wp-seo-doctor' ),
						$length,
						self::MAX_LENGTH
					),
					array( 'length' => $length )
				),
			);
		}

		return array();
	}
}
