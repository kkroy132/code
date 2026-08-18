<?php
/**
 * Data access layer for the wp_qlqr_keywords table (keyword auto-linking rules).
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Database;

use QuickLinkQRPro\Models\Keyword;

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
 * Class KeywordsRepository
 */
final class KeywordsRepository {

	/**
	 * Every keyword rule attached to one link, oldest first. Powers the Keywords repeater inside
	 * the link Edit modal, which is the only place keyword rules are edited.
	 *
	 * @param int $link_id Link ID.
	 * @return Keyword[]
	 */
	public function find_by_link( int $link_id ): array {
		global $wpdb;
		$table = Installer::keywords_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE link_id = %d ORDER BY id ASC', $table, $link_id ),
			ARRAY_A
		);

		return array_map( array( Keyword::class, 'from_row' ), $rows ?: array() );
	}

	/**
	 * Replace all keyword rules for a link with a new set, in one go — mirrors
	 * TargetingRulesRepository::replace_for_link(), since keywords are now edited through the same
	 * kind of repeater field inside the link modal rather than as standalone rows.
	 *
	 * @param int                              $link_id  Link ID.
	 * @param array<int, array<string, mixed>> $keywords {
	 *     @type string $keyword          Required keyword/phrase to match in post content.
	 *     @type bool   $case_sensitive   Whether matching is case-sensitive.
	 *     @type int    $max_replacements Max occurrences replaced per page/post.
	 *     @type string $status           active|disabled.
	 * }
	 * @return Keyword[] The newly saved rules.
	 */
	public function replace_for_link( int $link_id, array $keywords ): array {
		global $wpdb;
		$table = Installer::keywords_table();

		$wpdb->delete( $table, array( 'link_id' => $link_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		foreach ( $keywords as $keyword ) {
			$text = trim( (string) ( $keyword['keyword'] ?? '' ) );
			if ( '' === $text ) {
				continue;
			}

			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'link_id'          => $link_id,
					'keyword'          => $text,
					'case_sensitive'   => empty( $keyword['case_sensitive'] ) ? 0 : 1,
					'max_replacements' => max( 1, min( 50, (int) ( $keyword['max_replacements'] ?? 1 ) ) ),
					'status'           => 'disabled' === ( $keyword['status'] ?? 'active' ) ? 'disabled' : 'active',
					'created_at'       => current_time( 'mysql' ),
					'updated_at'       => current_time( 'mysql' ),
				)
			);
		}

		return $this->find_by_link( $link_id );
	}

	/**
	 * Delete every keyword rule pointing at a link. Called when a link is permanently deleted.
	 *
	 * @param int $link_id Link ID.
	 */
	public function delete_for_link( int $link_id ): void {
		global $wpdb;
		$wpdb->delete( Installer::keywords_table(), array( 'link_id' => $link_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * All active keyword rules, joined with each link's short_slug/status/deleted_at so
	 * Frontend\KeywordLinker can skip rules pointing at a disabled/trashed link without an
	 * extra query per rule. Cached for the lifetime of the request by the caller.
	 *
	 * @return Keyword[]
	 */
	public function find_all_linkable(): array {
		global $wpdb;
		$table       = Installer::keywords_table();
		$links_table = Installer::links_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT k.*, l.title AS link_title, l.short_slug AS link_short_slug, l.nofollow AS link_nofollow, l.sponsored AS link_sponsored, l.new_tab AS link_new_tab
				FROM %i k
				INNER JOIN %i l ON l.id = k.link_id
				WHERE k.status = 'active' AND l.status = 'active' AND l.deleted_at IS NULL
				ORDER BY CHAR_LENGTH(k.keyword) DESC",
				$table,
				$links_table
			),
			ARRAY_A
		);

		return array_map( array( Keyword::class, 'from_row' ), $rows ?: array() );
	}
}
