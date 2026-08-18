<?php
/**
 * Data access layer for the wp_qlqr_targeting_rules table (per-link Geo/Device redirect rules).
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Database;

use QuickLinkQRPro\Models\TargetingRule;

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
 * Class TargetingRulesRepository
 */
final class TargetingRulesRepository {

	/**
	 * All targeting rules for a link, in evaluation order.
	 *
	 * @param int  $link_id     Link ID.
	 * @param bool $active_only Whether to only return status = 'active' rows.
	 * @return TargetingRule[]
	 */
	public function find_by_link( int $link_id, bool $active_only = false ): array {
		global $wpdb;
		$table = Installer::targeting_rules_table();

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

		return array_map( array( TargetingRule::class, 'from_row' ), $rows ?: array() );
	}

	/**
	 * Replace all targeting rules for a link with a new set, in one go — mirrors
	 * DestinationsRepository::replace_for_link(), the only write path used by the admin UI's
	 * repeater field.
	 *
	 * @param int                               $link_id Link ID.
	 * @param array<int, array<string, mixed>>  $rules {
	 *     @type string $rule_type       country|device.
	 *     @type string $match_value     Country code or device type.
	 *     @type string $destination_url Required destination URL.
	 *     @type string $status          active|disabled.
	 * }
	 * @return TargetingRule[] The newly saved rules.
	 */
	public function replace_for_link( int $link_id, array $rules ): array {
		global $wpdb;
		$table = Installer::targeting_rules_table();

		$wpdb->delete( $table, array( 'link_id' => $link_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$position = 0;
		foreach ( $rules as $rule ) {
			$url = trim( (string) ( $rule['destination_url'] ?? '' ) );
			if ( '' === $url ) {
				continue;
			}

			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'link_id'         => $link_id,
					'rule_type'       => 'device' === ( $rule['rule_type'] ?? 'country' ) ? 'device' : 'country',
					'match_value'     => (string) ( $rule['match_value'] ?? '' ),
					'destination_url' => $url,
					'position'        => $position,
					'status'          => 'disabled' === ( $rule['status'] ?? 'active' ) ? 'disabled' : 'active',
					'created_at'      => current_time( 'mysql' ),
					'updated_at'      => current_time( 'mysql' ),
				)
			);

			++$position;
		}

		return $this->find_by_link( $link_id );
	}

	/**
	 * Delete all targeting rules for a link. Called when a link is permanently deleted.
	 *
	 * @param int $link_id Link ID.
	 */
	public function delete_for_link( int $link_id ): void {
		global $wpdb;
		$wpdb->delete( Installer::targeting_rules_table(), array( 'link_id' => $link_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
