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

class Heading_Hierarchy_Check extends Check {

	public function get_id() {
		return 'heading-hierarchy-skip';
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

		$xpath = new \DOMXPath( $dom );
		$nodes = $xpath->query( '//h1 | //h2 | //h3 | //h4 | //h5 | //h6' );

		$previous_level = 0;

		foreach ( $nodes as $node ) {
			$level = (int) substr( $node->nodeName, 1 );

			if ( $previous_level > 0 && $level > $previous_level + 1 ) {
				return array(
					$this->issue(
						$context,
						self::SEVERITY_MEDIUM,
						sprintf(
							/* translators: 1: heading level skipped from, 2: heading level skipped to. */
							__( 'Heading structure skips a level (H%1$d to H%2$d), which can confuse screen readers and search engines.', 'wp-seo-doctor' ),
							$previous_level,
							$level
						)
					),
				);
			}

			$previous_level = $level;
		}

		return array();
	}
}
