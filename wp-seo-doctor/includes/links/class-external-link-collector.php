<?php
/**
 * Catalogs outgoing external links the same way Link_Graph catalogs
 * internal ones — a separate scanner stage rather than folding into
 * Link_Graph, since internal/external have different consumers
 * (orphan/suggestion engine vs. Broken_Link_Checker) and Link_Graph's
 * job description was scoped to the internal graph in Step 9.
 *
 * @package SEODoc
 */

namespace SEODoc\Links;

use SEODoc\Checks\Scan_Context;
use SEODoc\DB\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class External_Link_Collector {

	public static function record_from_context( Scan_Context $context ) {
		$dom = $context->get_dom();
		if ( ! $dom ) {
			return;
		}

		$source_url = $context->get_permalink();
		if ( ! $source_url ) {
			return;
		}

		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$xpath     = new \DOMXPath( $dom );
		$links     = $xpath->query( '//a[@href]' );

		$edges = array();

		foreach ( $links as $link ) {
			$href = trim( $link->getAttribute( 'href' ) );

			// Only fully-qualified http(s) URLs are checkable externally;
			// relative hrefs are always same-site (Link_Graph's job).
			if ( 0 !== stripos( $href, 'http://' ) && 0 !== stripos( $href, 'https://' ) ) {
				continue;
			}

			$host = wp_parse_url( $href, PHP_URL_HOST );
			if ( ! $host || strtolower( $host ) === strtolower( $site_host ) ) {
				continue;
			}

			$key = md5( $href );
			if ( ! isset( $edges[ $key ] ) ) {
				$edges[ $key ] = array(
					'url'         => $href,
					'anchor_text' => trim( $link->textContent ),
					'rel'         => trim( $link->getAttribute( 'rel' ) ),
					'occurrences' => 0,
				);
			}
			++$edges[ $key ]['occurrences'];
		}

		self::replace_source_edges( $context->get_object_type(), $context->get_object_id(), $source_url, $edges );
	}

	private static function replace_source_edges( $object_type, $object_id, $source_url, array $edges ) {
		global $wpdb;
		$table       = Schema::table_names( $wpdb )['links'];
		$source_hash = md5( $source_url );

		$wpdb->delete( $table, array( 'source_hash' => $source_hash, 'link_type' => 'external' ) );

		$now = current_time( 'mysql' );

		foreach ( $edges as $edge ) {
			// is_broken/http_status start unknown (0/false); last_checked
			// intentionally equals first_detected so Broken_Link_Checker's
			// "never verified" query picks these up on its next tick.
			$wpdb->insert(
				$table,
				array(
					'source_object_type' => $object_type,
					'source_object_id'   => $object_id,
					'source_url'         => $source_url,
					'source_hash'        => $source_hash,
					'target_url'         => $edge['url'],
					'target_hash'        => md5( $edge['url'] ),
					'anchor_text'        => mb_substr( $edge['anchor_text'], 0, 255 ),
					'anchor_hash'        => md5( $edge['anchor_text'] ),
					'link_type'          => 'external',
					'rel_attributes'     => mb_substr( $edge['rel'], 0, 100 ),
					'is_broken'          => 0,
					'occurrences'        => $edge['occurrences'],
					'first_detected'     => $now,
					'last_checked'       => $now,
				)
			);
		}
	}
}
