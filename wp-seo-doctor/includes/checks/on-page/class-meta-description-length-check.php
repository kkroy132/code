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

class Meta_Description_Length_Check extends Check {

	const MIN_LENGTH = 70;
	const MAX_LENGTH = 160;

	public function get_id() {
		return 'meta-description-length';
	}

	public function get_category() {
		return 'on-page';
	}

	public function applies_to( Scan_Context $context ) {
		return 'post' === $context->get_object_type() && $context->get_post();
	}

	public function run( Scan_Context $context ) {
		$description = trim( (string) Seo_Meta_Reader::get_description( $context ) );

		if ( '' === $description ) {
			return array(); // Missing_Meta_Description_Check already flags this.
		}

		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $description ) : strlen( $description );

		if ( $length < self::MIN_LENGTH ) {
			return array(
				$this->issue(
					$context,
					self::SEVERITY_LOW,
					sprintf(
						/* translators: 1: current length, 2: recommended minimum length. */
						__( 'Meta description is short (%1$d characters), under the recommended %2$d.', 'wp-seo-doctor' ),
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
						__( 'Meta description is long (%1$d characters) and may be truncated past ~%2$d characters.', 'wp-seo-doctor' ),
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
