<?php
/**
 * Builds the internal link graph: one wp_seodoc_links row per outgoing
 * internal link, recorded during the batch scan via the
 * seodoc_register_scanner_stage extension point (Step 1 §3). This is
 * what Orphan_Detector and the Suggestion_Engine read from. External
 * links are deliberately skipped here — Step 10's broken-link checker
 * owns discovering and HTTP-verifying those.
 *
 * @package SEODoc
 */

namespace SEODoc\Links;

use SEODoc\Checks\Scan_Context;
use SEODoc\DB\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Link_Graph {

	/**
	 * Scanner-stage entry point: replaces this object's outgoing-internal-
	 * link rows with whatever's in its current content, so a link that
	 * was removed since the last scan disappears from the graph instead
	 * of lingering as a stale edge.
	 */
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

			if ( '' === $href || 0 === stripos( $href, 'javascript:' ) || 0 === stripos( $href, 'mailto:' ) || 0 === stripos( $href, 'tel:' ) ) {
				continue;
			}

			$absolute = self::resolve( $href, $source_url );

			// Different fragments on the same page count as one edge to
			// that page for graph/orphan purposes.
			$fragment_pos = strpos( $absolute, '#' );
			if ( false !== $fragment_pos ) {
				$absolute = substr( $absolute, 0, $fragment_pos );
			}
			if ( '' === $absolute ) {
				continue; // Was a bare "#..." same-page anchor.
			}

			$host = wp_parse_url( $absolute, PHP_URL_HOST );
			if ( ! $host || strtolower( $host ) !== strtolower( $site_host ) ) {
				continue;
			}

			$key = md5( $absolute );
			if ( ! isset( $edges[ $key ] ) ) {
				$edges[ $key ] = array(
					'url'         => $absolute,
					'anchor_text' => trim( $link->textContent ),
					'rel'         => trim( $link->getAttribute( 'rel' ) ),
					'occurrences' => 0,
				);
			}
			++$edges[ $key ]['occurrences'];
		}

		self::replace_source_edges( $context->get_object_type(), $context->get_object_id(), $source_url, $edges );
	}

	private static function resolve( $href, $base_url ) {
		if ( wp_parse_url( $href, PHP_URL_HOST ) ) {
			return $href;
		}
		return \WP_Http::make_absolute_url( $href, $base_url );
	}

	private static function replace_source_edges( $object_type, $object_id, $source_url, array $edges ) {
		global $wpdb;
		$table       = Schema::table_names( $wpdb )['links'];
		$source_hash = md5( $source_url );

		$wpdb->delete( $table, array( 'source_hash' => $source_hash, 'link_type' => 'internal' ) );

		$now = current_time( 'mysql' );

		foreach ( $edges as $edge ) {
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
					'link_type'          => 'internal',
					'rel_attributes'     => mb_substr( $edge['rel'], 0, 100 ),
					'is_broken'          => 0,
					'occurrences'        => $edge['occurrences'],
					'first_detected'     => $now,
					'last_checked'       => $now,
				)
			);
		}
	}

	public static function get_incoming_count( $url ) {
		global $wpdb;
		$table = Schema::table_names( $wpdb )['links'];

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT source_hash) FROM {$table} WHERE target_hash = %s AND link_type = 'internal'",
				md5( $url )
			)
		);
	}

	public static function get_outgoing_count( $url ) {
		global $wpdb;
		$table = Schema::table_names( $wpdb )['links'];

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT target_hash) FROM {$table} WHERE source_hash = %s AND link_type = 'internal'",
				md5( $url )
			)
		);
	}

	public static function links_to( $source_url, $target_url ) {
		global $wpdb;
		$table = Schema::table_names( $wpdb )['links'];

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE source_hash = %s AND target_hash = %s",
				md5( $source_url ),
				md5( $target_url )
			)
		);

		return $count > 0;
	}

	/**
	 * @return string[] Distinct target_hash values with at least one
	 *                   current internal inbound link.
	 */
	public static function get_all_linked_target_hashes() {
		global $wpdb;
		$table = Schema::table_names( $wpdb )['links'];

		return $wpdb->get_col( "SELECT DISTINCT target_hash FROM {$table} WHERE link_type = 'internal'" );
	}
}
