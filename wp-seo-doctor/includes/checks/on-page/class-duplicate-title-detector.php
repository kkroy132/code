<?php
/**
 * Cross-object check — can't run per-object since it needs every post's
 * title compared against every other's. Runs once per completed scan.
 *
 * Known scaling limitation for very large sites: this loads one title per
 * published post into memory to group them. Free's MVP scope accepts
 * that; a chunked/SQL-side comparison is a candidate improvement for
 * Pro's advanced crawl (Step 12) if it proves necessary in practice.
 *
 * @package SEODoc
 */

namespace SEODoc\Checks\On_Page;

use SEODoc\Checks\Scan_Level_Check;
use SEODoc\Checks\Scan_Context;
use SEODoc\Checks\Seo_Meta_Reader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Duplicate_Title_Detector extends Scan_Level_Check {

	const PAGE_SIZE = 200;

	public function get_id() {
		return 'duplicate-seo-title';
	}

	public function get_category() {
		return 'on-page';
	}

	public function run() {
		$groups = array();
		$paged  = 1;

		do {
			$query = new \WP_Query(
				array(
					'post_type'      => array_values( get_post_types( array( 'public' => true ) ) ),
					'post_status'    => 'publish',
					'posts_per_page' => self::PAGE_SIZE,
					'paged'          => $paged,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);

			foreach ( $query->posts as $post_id ) {
				$context = Scan_Context::for_object( 'post', $post_id );
				if ( ! $context ) {
					continue;
				}

				$title = trim( (string) Seo_Meta_Reader::get_title( $context ) );
				if ( '' === $title ) {
					continue; // Missing_Title_Check already flags this.
				}

				$groups[ strtolower( $title ) ][] = $context;
			}

			$fetched = count( $query->posts );
			++$paged;
		} while ( self::PAGE_SIZE === $fetched );

		$issues = array();

		foreach ( $groups as $contexts ) {
			if ( count( $contexts ) < 2 ) {
				continue;
			}

			foreach ( $contexts as $context ) {
				$issues[] = $this->issue(
					self::SEVERITY_MEDIUM,
					sprintf(
						/* translators: %d: number of pages sharing this title. */
						__( 'This SEO title is duplicated across %d pages.', 'wp-seo-doctor' ),
						count( $contexts )
					),
					$context->get_permalink(),
					'post',
					$context->get_object_id(),
					array( 'duplicate_count' => count( $contexts ) )
				);
			}
		}

		return $issues;
	}
}
