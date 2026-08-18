<?php
/**
 * Data access layer for the wp_qlqr_bio_links table (buttons shown on a Smart Bio Link page).
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Database;

use QuickLinkQRPro\Models\BioLink;

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
 * Class BioLinksRepository
 */
final class BioLinksRepository {

	/**
	 * All buttons for a bio page, in display order.
	 *
	 * @param int  $bio_page_id Bio page ID.
	 * @param bool $active_only Whether to only return status = 'active' rows.
	 * @return BioLink[]
	 */
	public function find_by_page( int $bio_page_id, bool $active_only = false ): array {
		global $wpdb;
		$table = Installer::bio_links_table();

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

		return array_map( array( BioLink::class, 'from_row' ), $rows ?: array() );
	}

	/**
	 * Find a single button by ID, scoped to a specific bio page (so a click-redirect request for
	 * one page can never trigger/redirect through a button that belongs to a different page).
	 *
	 * @param int $id          Button ID.
	 * @param int $bio_page_id Owning bio page ID.
	 * @return BioLink|null
	 */
	public function find( int $id, int $bio_page_id ): ?BioLink {
		global $wpdb;
		$table = Installer::bio_links_table();

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND bio_page_id = %d LIMIT 1', $table, $id, $bio_page_id ),
			ARRAY_A
		);

		return $row ? BioLink::from_row( $row ) : null;
	}

	/**
	 * Replace all buttons for a bio page with a new set, in one go — mirrors
	 * DestinationsRepository::replace_for_link(), the only write path used by the admin UI's
	 * repeater field. Existing per-button click counts are preserved by URL+label matching where
	 * possible; genuinely new rows start at 0 clicks.
	 *
	 * @param int                               $bio_page_id Bio page ID.
	 * @param array<int, array<string, mixed>>  $links {
	 *     @type string $label  Required button text (for a group header, the group's title).
	 *     @type string $url    Required destination URL (ignored for a group header).
	 *     @type string $icon   Optional emoji, shown when image_url is empty.
	 *     @type string $image_url Optional thumbnail image URL ("product card" style button).
	 *     @type string $status active|disabled.
	 *     @type string|null $starts_at MySQL datetime string, or null for "always".
	 *     @type string|null $ends_at   MySQL datetime string, or null for "always".
	 *     @type string $item_type link|group.
	 *     @type string|null $group_key A group header's own stable identifier.
	 *     @type string|null $parent_group_key The group_key of the group a link belongs to.
	 *     @type string|null $visible_countries Comma-separated ISO country codes, or null for "all".
	 *     @type string|null $visible_devices Comma-separated device types, or null for "all".
	 * }
	 * @return BioLink[] The newly saved buttons.
	 */
	public function replace_for_page( int $bio_page_id, array $links ): array {
		global $wpdb;
		$table = Installer::bio_links_table();

		$existing      = $this->find_by_page( $bio_page_id );
		$clicks_by_key = array();
		foreach ( $existing as $link ) {
			$clicks_by_key[ $link->label . '|' . $link->url ] = $link->clicks;
		}

		$wpdb->delete( $table, array( 'bio_page_id' => $bio_page_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$position = 0;
		foreach ( $links as $link ) {
			$is_group = 'group' === ( $link['item_type'] ?? 'link' );
			$label    = trim( (string) ( $link['label'] ?? '' ) );
			$url      = $is_group ? '' : trim( (string) ( $link['url'] ?? '' ) );

			if ( '' === $label || ( ! $is_group && '' === $url ) ) {
				continue;
			}

			$key = $label . '|' . $url;

			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'bio_page_id'       => $bio_page_id,
					'label'             => $label,
					'url'               => $url,
					'icon'              => ! empty( $link['icon'] ) ? (string) $link['icon'] : null,
					'image_url'         => ( ! $is_group && ! empty( $link['image_url'] ) ) ? (string) $link['image_url'] : null,
					'position'          => $position,
					'clicks'            => $clicks_by_key[ $key ] ?? 0,
					'status'            => 'disabled' === ( $link['status'] ?? 'active' ) ? 'disabled' : 'active',
					'starts_at'         => ( ! $is_group && ! empty( $link['starts_at'] ) ) ? (string) $link['starts_at'] : null,
					'ends_at'           => ( ! $is_group && ! empty( $link['ends_at'] ) ) ? (string) $link['ends_at'] : null,
					'item_type'         => $is_group ? 'group' : 'link',
					'group_key'         => ( $is_group && ! empty( $link['group_key'] ) ) ? (string) $link['group_key'] : null,
					'parent_group_key'  => ( ! $is_group && ! empty( $link['parent_group_key'] ) ) ? (string) $link['parent_group_key'] : null,
					'visible_countries' => ( ! $is_group && ! empty( $link['visible_countries'] ) ) ? (string) $link['visible_countries'] : null,
					'visible_devices'   => ( ! $is_group && ! empty( $link['visible_devices'] ) ) ? (string) $link['visible_devices'] : null,
					'created_at'        => current_time( 'mysql' ),
					'updated_at'        => current_time( 'mysql' ),
				)
			);

			++$position;
		}

		return $this->find_by_page( $bio_page_id );
	}

	/**
	 * Increment a single button's click counter. Fired by the public click-redirect controller.
	 *
	 * @param int $id Button row ID.
	 */
	public function increment_click( int $id ): void {
		global $wpdb;
		$table = Installer::bio_links_table();

		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET clicks = clicks + 1 WHERE id = %d', $table, $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Delete all buttons for a bio page. Called when a bio page is permanently deleted.
	 *
	 * @param int $bio_page_id Bio page ID.
	 */
	public function delete_for_page( int $bio_page_id ): void {
		global $wpdb;
		$wpdb->delete( Installer::bio_links_table(), array( 'bio_page_id' => $bio_page_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
