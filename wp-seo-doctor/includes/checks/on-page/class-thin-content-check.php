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

class Thin_Content_Check extends Check {

	const CRITICAL_THRESHOLD = 50;

	const MEDIUM_THRESHOLD = 300;

	public function get_id() {
		return 'thin-content';
	}

	public function get_category() {
		return 'on-page';
	}

	public function applies_to( Scan_Context $context ) {
		return 'post' === $context->get_object_type() && $context->get_post();
	}

	/**
	 * Word count is computed from raw post_content with shortcodes and
	 * tags stripped — an approximation of rendered length, not a full
	 * the_content() filter run, to avoid the cost/side effects of
	 * executing every content filter during a batch scan.
	 */
	public function run( Scan_Context $context ) {
		$text  = wp_strip_all_tags( strip_shortcodes( $context->get_content() ) );
		$words = preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
		$count = is_array( $words ) ? count( $words ) : 0;

		if ( $count < self::CRITICAL_THRESHOLD ) {
			return array(
				$this->issue(
					$context,
					self::SEVERITY_HIGH,
					sprintf(
						/* translators: %d: word count. */
						__( 'This page has very little content (%d words).', 'wp-seo-doctor' ),
						$count
					),
					array( 'word_count' => $count )
				),
			);
		}

		if ( $count < self::MEDIUM_THRESHOLD ) {
			return array(
				$this->issue(
					$context,
					self::SEVERITY_MEDIUM,
					sprintf(
						/* translators: 1: word count, 2: recommended minimum word count. */
						__( 'This page has thin content (%1$d words). Pages under %2$d words often struggle to rank.', 'wp-seo-doctor' ),
						$count,
						self::MEDIUM_THRESHOLD
					),
					array( 'word_count' => $count )
				),
			);
		}

		return array();
	}
}
