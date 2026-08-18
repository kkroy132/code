<?php
/**
 * Data access layer for the wp_qlqr_bio_socials table (the social-icon row on a Smart Bio Link page).
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Database;

use QuickLinkQRPro\Models\BioSocial;

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
 * Class BioSocialsRepository
 */
final class BioSocialsRepository {

	/**
	 * All social icons for a bio page, in display order.
	 *
	 * @param int  $bio_page_id Bio page ID.
	 * @param bool $active_only Whether to only return status = 'active' rows.
	 * @return BioSocial[]
	 */
	public function find_by_page( int $bio_page_id, bool $active_only = false ): array {
		global $wpdb;
		$table = Installer::bio_socials_table();

		// Two literal queries rather than one built up in a variable: $wpdb->prepare() has to
		// receive a string literal for its placeholders to be statically verifiable (the table name
		// is interpolated from $wpdb->prefix, never from user input).
		if ( $active_only ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM %i WHERE bio_page_id = %d AND status = 'active' ORDER BY position ASC, id ASC", $table, $bio_page_id ),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i WHERE bio_page_id = %d ORDER BY position ASC, id ASC', $table, $bio_page_id ),
				ARRAY_A
			);
		}

		return array_map( array( BioSocial::class, 'from_row' ), $rows ?: array() );
	}

	/**
	 * Replace all social icons for a bio page with a new set, in one go — mirrors
	 * BioLinksRepository::replace_for_page().
	 *
	 * @param int                               $bio_page_id Bio page ID.
	 * @param array<int, array<string, mixed>>  $socials {
	 *     @type string $platform One of Models\BioSocial::PLATFORMS' keys.
	 *     @type string $url      Required target URL.
	 *     @type string $status   active|disabled.
	 *     @type bool   $is_floating Whether this renders as a floating action button instead of
	 *                                an inline icon.
	 * }
	 * @return BioSocial[] The newly saved icons.
	 */
	public function replace_for_page( int $bio_page_id, array $socials ): array {
		global $wpdb;
		$table = Installer::bio_socials_table();

		$wpdb->delete( $table, array( 'bio_page_id' => $bio_page_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$position = 0;
		foreach ( $socials as $social ) {
			$url = trim( (string) ( $social['url'] ?? '' ) );
			if ( '' === $url ) {
				continue;
			}

			$platform = array_key_exists( $social['platform'] ?? '', \QuickLinkQRPro\Models\BioSocial::PLATFORMS ) ? $social['platform'] : 'website';

			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'bio_page_id' => $bio_page_id,
					'platform'    => $platform,
					'url'         => $url,
					'position'    => $position,
					'status'      => 'disabled' === ( $social['status'] ?? 'active' ) ? 'disabled' : 'active',
					'is_floating' => ! empty( $social['is_floating'] ) ? 1 : 0,
					'created_at'  => current_time( 'mysql' ),
					'updated_at'  => current_time( 'mysql' ),
				)
			);

			++$position;
		}

		return $this->find_by_page( $bio_page_id );
	}

	/**
	 * Delete all social icons for a bio page. Called when a bio page is permanently deleted.
	 *
	 * @param int $bio_page_id Bio page ID.
	 */
	public function delete_for_page( int $bio_page_id ): void {
		global $wpdb;
		$wpdb->delete( Installer::bio_socials_table(), array( 'bio_page_id' => $bio_page_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
