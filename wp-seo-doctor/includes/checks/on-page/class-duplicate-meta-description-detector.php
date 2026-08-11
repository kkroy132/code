<?php
/**
 * Same cross-object approach as Duplicate_Title_Detector, for meta
 * descriptions.
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

class Duplicate_Meta_Description_Detector extends Scan_Level_Check {

	const PAGE_SIZE = 200;

	public function get_id() {
		return 'duplicate-meta-description';
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

				$description = trim( (string) Seo_Meta_Reader::get_description( $context ) );
				if ( '' === $description ) {
					continue; // Missing_Meta_Description_Check already flags this.
				}

				$groups[ strtolower( $description ) ][] = $context;
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
						/* translators: %d: number of pages sharing this description. */
						__( 'This meta description is duplicated across %d pages.', 'wp-seo-doctor' ),
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
