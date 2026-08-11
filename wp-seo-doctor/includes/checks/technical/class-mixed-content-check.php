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

class Mixed_Content_Check extends Check {

	public function get_id() {
		return 'mixed-content';
	}

	public function get_category() {
		return 'technical';
	}

	public function applies_to( Scan_Context $context ) {
		return 'post' === $context->get_object_type()
			&& $context->get_post()
			&& 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME );
	}

	/**
	 * Limited to actual subresource-loading elements (img/script/iframe/
	 * stylesheet) — plain <a href="http://..."> hyperlinks aren't a
	 * mixed-content issue, that's Links\Insecure_Internal_Link_Check.
	 */
	public function run( Scan_Context $context ) {
		$dom = $context->get_dom();
		if ( ! $dom ) {
			return array();
		}

		$xpath = new \DOMXPath( $dom );
		$nodes = $xpath->query(
			'//img[starts-with(@src,"http://")] | ' .
			'//script[starts-with(@src,"http://")] | ' .
			'//iframe[starts-with(@src,"http://")] | ' .
			'//link[@rel="stylesheet"][starts-with(@href,"http://")]'
		);

		if ( $nodes->length > 0 ) {
			return array(
				$this->issue(
					$context,
					self::SEVERITY_MEDIUM,
					sprintf(
						/* translators: %d: number of insecure resources found. */
						__( '%d insecure (http://) resource(s) found on this https page.', 'wp-seo-doctor' ),
						$nodes->length
					),
					array( 'count' => $nodes->length )
				),
			);
		}

		return array();
	}
}
