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

class Missing_Title_Check extends Check {

	public function get_id() {
		return 'missing-seo-title';
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
			return array(
				$this->issue(
					$context,
					self::SEVERITY_CRITICAL,
					__( 'This page has no SEO title.', 'wp-seo-doctor' )
				),
			);
		}

		return array();
	}
}
