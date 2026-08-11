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

class Nofollow_Internal_Link_Check extends Check {

	public function get_id() {
		return 'nofollow-internal-link';
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

		$count = 0;

		foreach ( $links as $link ) {
			$href        = $link->getAttribute( 'href' );
			$host        = wp_parse_url( $href, PHP_URL_HOST );
			$is_internal = ! $host || strtolower( $host ) === strtolower( $site_host );
			$rel         = strtolower( $link->getAttribute( 'rel' ) );

			if ( $is_internal && false !== strpos( $rel, 'nofollow' ) ) {
				++$count;
			}
		}

		if ( $count > 0 ) {
			return array(
				$this->issue(
					$context,
					self::SEVERITY_LOW,
					sprintf(
						/* translators: %d: number of internal links marked nofollow. */
						__( '%d internal link(s) are marked nofollow, which can block internal link equity unnecessarily.', 'wp-seo-doctor' ),
						$count
					),
					array( 'count' => $count )
				),
			);
		}

		return array();
	}
}
