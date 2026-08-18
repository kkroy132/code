<?php
/**
 * Data access layer for the wp_qlqr_destinations table (multi-destination URL rotation).
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Database;

use QuickLinkQRPro\Models\Destination;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * This file is the data-access layer for the plugin's own custom tables, so two findings are
 * structural here rather than defects, and are declared once at file level instead of being
 * repeated on every query:
 *
 *  - DirectDatabaseQuery.DirectQuery  — custom tables have no WP_Query/get_posts() equivalent;
 *    direct $wpdb access is the only way to read them.
 *  - DirectDatabaseQuery.NoCaching    — these back click/view analytics that must reflect writes
 *    immediately; a stale object-cache read would report wrong numbers.
 *
 * Table names (from Installer::*_table(), i.e. $wpdb->prefix plus a hardcoded suffix — never
 * caller-supplied) are bound through prepare()'s %i identifier placeholder rather than
 * interpolated into the query string, so every query here is genuinely prepared and no
 * PreparedSQL suppression is needed.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Class DestinationsRepository
 */
final class DestinationsRepository {

	/**
	 * All destinations for a link, in display/round-robin order.
	 *
	 * @param int  $link_id      Link ID.
	 * @param bool $active_only  Whether to only return status = 'active' rows.
	 * @return Destination[]
	 */
	public function find_by_link( int $link_id, bool $active_only = false ): array {
		global $wpdb;
		$table = Installer::destinations_table();

		// Two literal queries rather than one built up in a variable: $wpdb->prepare() has to
		// receive a string literal for its placeholders to be statically verifiable (the table name
		// is interpolated from $wpdb->prefix, never from user input).
		if ( $active_only ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM %i WHERE link_id = %d AND status = 'active' ORDER BY position ASC, id ASC", $table, $link_id ),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i WHERE link_id = %d ORDER BY position ASC, id ASC', $table, $link_id ),
				ARRAY_A
			);
		}

		return array_map( array( Destination::class, 'from_row' ), $rows ?: array() );
	}

	/**
	 * Replace all destinations for a link with a new set, in one go. This is the only write
	 * path used by the admin UI's repeater field — simpler and safer than diffing individual
	 * add/edit/remove/reorder operations, since the whole list is always submitted together.
	 * Existing per-destination click counts are preserved by URL+position matching where
	 * possible; genuinely new rows start at 0 clicks.
	 *
	 * @param int                          $link_id      Link ID.
	 * @param array<int, array<string, mixed>> $destinations {
	 *     @type string $destination_url Required destination URL.
	 *     @type int    $weight          Relative weight (>= 1).
	 *     @type string $status          active|disabled.
	 * }
	 * @return Destination[] The newly saved destinations.
	 */
	public function replace_for_link( int $link_id, array $destinations ): array {
		global $wpdb;
		$table = Installer::destinations_table();

		// Preserve existing click counts for destinations whose URL didn't change, so editing
		// the rotation list (reordering, adjusting weights) doesn't reset analytics to zero.
		$existing        = $this->find_by_link( $link_id );
		$clicks_by_url   = array();
		foreach ( $existing as $destination ) {
			$clicks_by_url[ $destination->destination_url ] = $destination->clicks;
		}

		$wpdb->delete( $table, array( 'link_id' => $link_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$position = 0;
		foreach ( $destinations as $destination ) {
			$url = trim( (string) ( $destination['destination_url'] ?? '' ) );
			if ( '' === $url ) {
				continue;
			}

			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'link_id'         => $link_id,
					'destination_url' => $url,
					'weight'          => max( 1, (int) ( $destination['weight'] ?? 1 ) ),
					'position'        => $position,
					'clicks'          => $clicks_by_url[ $url ] ?? 0,
					'status'          => 'disabled' === ( $destination['status'] ?? 'active' ) ? 'disabled' : 'active',
					'created_at'      => current_time( 'mysql' ),
					'updated_at'      => current_time( 'mysql' ),
				)
			);

			++$position;
		}

		return $this->find_by_link( $link_id );
	}

	/**
	 * Increment a single destination's click counter. Fired after a rotation pick, alongside
	 * the link's own total_clicks counter (see Database\LinksRepository::increment_click_count()).
	 *
	 * @param int $destination_id Destination row ID.
	 */
	public function increment_click( int $destination_id ): void {
		global $wpdb;
		$table = Installer::destinations_table();

		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET clicks = clicks + 1 WHERE id = %d', $table, $destination_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Delete all destinations for a link. Called when a link is permanently deleted.
	 *
	 * @param int $link_id Link ID.
	 */
	public function delete_for_link( int $link_id ): void {
		global $wpdb;
		$wpdb->delete( Installer::destinations_table(), array( 'link_id' => $link_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
