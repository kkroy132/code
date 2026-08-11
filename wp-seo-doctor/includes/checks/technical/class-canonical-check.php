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

class Canonical_Check extends Check {

	public function get_id() {
		return 'canonical-issue';
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
		$nodes = $xpath->query( '//link[@rel="canonical"]' );

		if ( 0 === $nodes->length ) {
			return array(
				$this->issue( $context, self::SEVERITY_MEDIUM, __( 'This page has no canonical tag.', 'wp-seo-doctor' ) ),
			);
		}

		$href           = $nodes->item( 0 )->getAttribute( 'href' );
		$canonical_host = wp_parse_url( $href, PHP_URL_HOST );
		$site_host      = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( $canonical_host && $site_host && strtolower( $canonical_host ) !== strtolower( $site_host ) ) {
			return array(
				$this->issue(
					$context,
					self::SEVERITY_HIGH,
					sprintf(
						/* translators: %s: the domain the canonical tag points to. */
						__( 'Canonical tag points to a different domain (%s).', 'wp-seo-doctor' ),
						$canonical_host
					),
					array( 'canonical_url' => $href )
				),
			);
		}

		return array();
	}
}
