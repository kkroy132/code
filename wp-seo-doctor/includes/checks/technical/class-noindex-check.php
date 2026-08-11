<?php
/**
 * @package SEODoc
 */

namespace SEODoc\Checks\Technical;

use SEODoc\Checks\Check;
use SEODoc\Checks\Scan_Context;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Noindex_Check extends Check {

	public function get_id() {
		return 'noindex-page';
	}

	public function get_category() {
		return 'technical';
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
		$nodes = $xpath->query( '//meta[translate(@name,"ROBOTS","robots")="robots"]' );

		foreach ( $nodes as $node ) {
			$content = strtolower( $node->getAttribute( 'content' ) );

			if ( false !== strpos( $content, 'noindex' ) ) {
				return array(
					$this->issue(
						$context,
						self::SEVERITY_HIGH,
						__( 'This published page is set to noindex and will not appear in search results.', 'wp-seo-doctor' )
					),
				);
			}
		}

		return array();
	}
}
