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

class Excessive_External_Links_Check extends Check {

	const THRESHOLD = 100;

	public function get_id() {
		return 'excessive-external-links';
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

		$xpath     = new \DOMXPath( $dom );
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$links     = $xpath->query( '//a[@href]' );

		$external = 0;

		foreach ( $links as $link ) {
			$host = wp_parse_url( $link->getAttribute( 'href' ), PHP_URL_HOST );

			if ( $host && strtolower( $host ) !== strtolower( $site_host ) ) {
				++$external;
			}
		}

		if ( $external > self::THRESHOLD ) {
			return array(
				$this->issue(
					$context,
					self::SEVERITY_LOW,
					sprintf(
						/* translators: %d: number of external links found. */
						__( 'This page has %d external links, which is unusually high.', 'wp-seo-doctor' ),
						$external
					),
					array( 'count' => $external )
				),
			);
		}

		return array();
	}
}
