<?php
/**
 * Finds published pages with zero current internal inbound links, using
 * Link_Graph's already-built edge table rather than re-crawling anything.
 *
 * @package SEODoc
 */

namespace SEODoc\Links;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Orphan_Detector {

	const PAGE_SIZE = 200;

	/**
	 * @return array<int, array{object_id: int, url: string}>
	 */
	public static function find_orphans() {
		$linked_hashes = array_flip( Link_Graph::get_all_linked_target_hashes() );
		$front_page    = trailingslashit( home_url( '/' ) );

		$orphans = array();
		$paged   = 1;

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
				$url = get_permalink( $post_id );

				// The homepage isn't expected to have inbound content
				// links — it's reached via primary navigation.
				if ( ! $url || $url === $front_page ) {
					continue;
				}

				if ( ! isset( $linked_hashes[ md5( $url ) ] ) ) {
					$orphans[] = array(
						'object_id' => $post_id,
						'url'       => $url,
					);
				}
			}

			$fetched = count( $query->posts );
			++$paged;
		} while ( self::PAGE_SIZE === $fetched );

		return $orphans;
	}
}
