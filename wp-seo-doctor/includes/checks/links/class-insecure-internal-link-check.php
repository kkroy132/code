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

class Insecure_Internal_Link_Check extends Check {

	public function get_id() {
		return 'insecure-internal-link';
	}

	public function get_category() {
		return 'links';
	}

	public function applies_to( Scan_Context $context ) {
		return 'post' === $context->get_object_type()
			&& $context->get_post()
			&& 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME );
	}

	public function run( Scan_Context $context ) {
		$dom = $context->get_dom();
		if ( ! $dom ) {
			return array();
		}

		$xpath     = new \DOMXPath( $dom );
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$links     = $xpath->query( '//a[starts-with(@href,"http://")]' );

		$count = 0;

		foreach ( $links as $link ) {
			$host = wp_parse_url( $link->getAttribute( 'href' ), PHP_URL_HOST );

			if ( $host && strtolower( $host ) === strtolower( $site_host ) ) {
				++$count;
			}
		}

		if ( $count > 0 ) {
			return array(
				$this->issue(
					$context,
					self::SEVERITY_MEDIUM,
					sprintf(
						/* translators: %d: number of insecure internal links. */
						__( '%d internal link(s) point to the insecure http:// version of this site.', 'wp-seo-doctor' ),
						$count
					),
					array( 'count' => $count )
				),
			);
		}

		return array();
	}
}
