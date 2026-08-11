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

class Missing_Meta_Description_Check extends Check {

	public function get_id() {
		return 'missing-meta-description';
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
			return array(
				$this->issue(
					$context,
					self::SEVERITY_HIGH,
					__( 'This page has no meta description.', 'wp-seo-doctor' )
				),
			);
		}

		return array();
	}
}
