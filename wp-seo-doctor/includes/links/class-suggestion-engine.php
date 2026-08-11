<?php
/**
 * Generates internal-link suggestions: for a weakly-linked page, find
 * another page that shares a taxonomy term and doesn't already link to
 * it. Free-tier scope deliberately: shared-taxonomy matching, not
 * semantic/embedding-based relevance or Pro's topic-cluster/pillar-page
 * detection (brief §6/§36) — real and useful, just not the smartest
 * possible version, which is exactly what's appropriate for a first pass
 * of a Free feature Pro is meant to build further on.
 *
 * @package SEODoc
 */

namespace SEODoc\Links;

use SEODoc\DB\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Suggestion_Engine {

	const CANDIDATE_PAGE_SIZE = 200;

	/** A target with fewer internal inbound links than this is "weak". */
	const WEAK_LINK_THRESHOLD = 2;

	const DEFAULT_MONTHLY_QUOTA = 25;

	/**
	 * @return int Number of new suggestions created this run.
	 */
	public static function generate() {
		$remaining = self::remaining_quota();
		if ( $remaining <= 0 ) {
			return 0;
		}

		$created = 0;
		$paged   = 1;

		do {
			$query = new \WP_Query(
				array(
					'post_type'      => array_values( get_post_types( array( 'public' => true ) ) ),
					'post_status'    => 'publish',
					'posts_per_page' => self::CANDIDATE_PAGE_SIZE,
					'paged'          => $paged,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);

			foreach ( $query->posts as $target_id ) {
				if ( $created >= $remaining ) {
					break 2;
				}

				$target_url = get_permalink( $target_id );
				if ( ! $target_url || Link_Graph::get_incoming_count( $target_url ) >= self::WEAK_LINK_THRESHOLD ) {
					continue;
				}

				$match = self::find_source_candidate( $target_id, $target_url );
				if ( ! $match ) {
					continue;
				}

				if ( self::record_suggestion( $match['source_id'], $target_id, $match['shared_terms'] ) ) {
					++$created;
				}
			}

			$fetched = count( $query->posts );
			++$paged;
		} while ( self::CANDIDATE_PAGE_SIZE === $fetched && $created < $remaining );

		return $created;
	}

	/**
	 * @return array{source_id: int, shared_terms: int}|null
	 */
	private static function find_source_candidate( $target_id, $target_url ) {
		$post_type  = get_post_type( $target_id );
		$taxonomies = get_object_taxonomies( $post_type, 'names' );

		if ( empty( $taxonomies ) ) {
			return null;
		}

		$terms = wp_get_object_terms( $target_id, $taxonomies[0], array( 'fields' => 'ids' ) );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return null;
		}

		$candidates = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'post__not_in'   => array( $target_id ),
				'posts_per_page' => 5,
				'fields'         => 'ids',
				'tax_query'      => array(
					array(
						'taxonomy' => $taxonomies[0],
						'field'    => 'term_id',
						'terms'    => $terms,
					),
				),
			)
		);

		foreach ( $candidates as $candidate_id ) {
			$candidate_url = get_permalink( $candidate_id );
			if ( ! $candidate_url || Link_Graph::links_to( $candidate_url, $target_url ) ) {
				continue;
			}

			$candidate_terms = wp_get_object_terms( $candidate_id, $taxonomies[0], array( 'fields' => 'ids' ) );
			$shared          = is_wp_error( $candidate_terms ) ? 0 : count( array_intersect( $terms, $candidate_terms ) );

			return array(
				'source_id'    => $candidate_id,
				'shared_terms' => max( 1, $shared ),
			);
		}

		return null;
	}

	private static function record_suggestion( $source_id, $target_id, $shared_term_count ) {
		global $wpdb;
		$table = Schema::table_names( $wpdb )['suggestions'];

		$already_pending = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE source_object_id = %d AND target_object_id = %d AND status = 'pending'",
				$source_id,
				$target_id
			)
		);

		if ( $already_pending ) {
			return false;
		}

		$wpdb->insert(
			$table,
			array(
				'source_object_id' => $source_id,
				'source_url'       => get_permalink( $source_id ),
				'target_object_id' => $target_id,
				'target_url'       => get_permalink( $target_id ),
				'suggested_anchor' => get_the_title( $target_id ),
				'relevance_score'  => $shared_term_count,
				'reason'           => __( 'Shares a category/tag with the target page.', 'wp-seo-doctor' ),
				'status'           => 'pending',
				'month_bucket'     => gmdate( 'Y-m' ),
				'created_at'       => current_time( 'mysql' ),
			)
		);

		return true;
	}

	/**
	 * Free's cap of 25/month, filterable — Pro raises it rather than this
	 * plugin having any notion of "which tier is active" (Step 1 §3: Free
	 * never gates on Pro's presence, Pro only ever adds).
	 */
	private static function remaining_quota() {
		global $wpdb;
		$table = Schema::table_names( $wpdb )['suggestions'];

		$cap = (int) apply_filters( 'seodoc_suggestion_monthly_quota', self::DEFAULT_MONTHLY_QUOTA );

		$used = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE month_bucket = %s",
				gmdate( 'Y-m' )
			)
		);

		return max( 0, $cap - $used );
	}
}
