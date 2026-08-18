<?php
/**
 * Diagnoses and fixes common database problems: missing/outdated tables, and rows left behind
 * in child tables after their parent link/bio page was permanently deleted (which can happen if
 * a request was interrupted mid-delete, or after a manual database edit).
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Helpers;

use QuickLinkQRPro\Database\Installer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * This file is the data-access layer for the plugin's own custom tables, so three findings are
 * structural here rather than defects, and are declared once at file level instead of being
 * repeated on every query:
 *
 *  - DirectDatabaseQuery.DirectQuery  — custom tables have no WP_Query/get_posts() equivalent;
 *    direct $wpdb access is the only way to read them.
 *  - DirectDatabaseQuery.NoCaching    — these back click/view analytics that must reflect writes
 *    immediately; a stale object-cache read would report wrong numbers.
 *  - PreparedSQL.InterpolatedNotPrepared and PluginCheck DirectDB.UnescapedDBParameter — the only
 *    interpolated values are table names from Installer::*_table(), i.e. $wpdb->prefix plus a
 *    hardcoded suffix. A table name cannot be a bound parameter in MySQL, and no caller-supplied
 *    value is ever interpolated: those all travel through %s/%d placeholders.
 *
 * PreparedSQL.NotPrepared is deliberately NOT disabled — it is the check that catches a raw
 * variable being handed to a query, which is the mistake that would actually matter here.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter

/**
 * Class DatabaseRepair
 */
final class DatabaseRepair {

	/**
	 * Child table => {parent table, foreign key column} pairs checked for orphaned rows (a row
	 * whose parent no longer exists). Every plugin table with a foreign key to links or bio pages.
	 *
	 * @return array<int, array{child: string, parent: string, fk: string}>
	 */
	private static function orphan_checks(): array {
		return array(
			array(
				'child'  => Installer::clicks_table(),
				'parent' => Installer::links_table(),
				'fk'     => 'link_id',
			),
			array(
				'child'  => Installer::destinations_table(),
				'parent' => Installer::links_table(),
				'fk'     => 'link_id',
			),
			array(
				'child'  => Installer::targeting_rules_table(),
				'parent' => Installer::links_table(),
				'fk'     => 'link_id',
			),
			array(
				'child'  => Installer::keywords_table(),
				'parent' => Installer::links_table(),
				'fk'     => 'link_id',
			),
			array(
				'child'  => Installer::bio_links_table(),
				'parent' => Installer::bio_pages_table(),
				'fk'     => 'bio_page_id',
			),
			array(
				'child'  => Installer::bio_socials_table(),
				'parent' => Installer::bio_pages_table(),
				'fk'     => 'bio_page_id',
			),
			array(
				'child'  => Installer::bio_emails_table(),
				'parent' => Installer::bio_pages_table(),
				'fk'     => 'bio_page_id',
			),
			array(
				'child'  => Installer::bio_views_table(),
				'parent' => Installer::bio_pages_table(),
				'fk'     => 'bio_page_id',
			),
		);
	}

	/**
	 * Every table the plugin owns, table name => human-readable label. Shared by the
	 * "missing table" check and the repair's OPTIMIZE TABLE pass.
	 *
	 * @return array<string, string>
	 */
	private static function all_tables(): array {
		return array(
			Installer::links_table()           => __( 'Links', 'plugnova-link-shortener-qr' ),
			Installer::clicks_table()          => __( 'Clicks', 'plugnova-link-shortener-qr' ),
			Installer::destinations_table()    => __( 'Destinations', 'plugnova-link-shortener-qr' ),
			Installer::targeting_rules_table() => __( 'Targeting Rules', 'plugnova-link-shortener-qr' ),
			Installer::keywords_table()        => __( 'Keywords', 'plugnova-link-shortener-qr' ),
			Installer::bio_pages_table()       => __( 'Bio Pages', 'plugnova-link-shortener-qr' ),
			Installer::bio_links_table()       => __( 'Bio Links', 'plugnova-link-shortener-qr' ),
			Installer::bio_socials_table()     => __( 'Bio Socials', 'plugnova-link-shortener-qr' ),
			Installer::bio_emails_table()      => __( 'Bio Emails', 'plugnova-link-shortener-qr' ),
			Installer::bio_views_table()       => __( 'Bio Views', 'plugnova-link-shortener-qr' ),
			Installer::activity_log_table()    => __( 'Activity Log', 'plugnova-link-shortener-qr' ),
		);
	}

	/**
	 * Run every check without changing anything.
	 *
	 * @return array<int, array{label: string, status: string, detail: string}> status is 'ok'|'warning'|'error'.
	 */
	public static function check(): array {
		global $wpdb;

		$results = array();

		foreach ( self::all_tables() as $table => $label ) {
			// Table names are built from a trusted prefix, never user input.
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders,WordPress.DB.DirectDatabaseQuery

			$results[] = array(
				/* translators: %s: human-readable table name, e.g. "Links" or "Clicks" */
				'label'  => sprintf( __( 'Table: %s', 'plugnova-link-shortener-qr' ), $label ),
				'status' => $exists ? 'ok' : 'error',
				'detail' => $exists
					? __( 'Exists.', 'plugnova-link-shortener-qr' )
					: __( 'Missing — click "Repair Now" to recreate it.', 'plugnova-link-shortener-qr' ),
			);
		}

		$installed_version = get_option( 'qlqr_db_version', '' );
		$results[]          = array(
			'label'  => __( 'Database Schema Version', 'plugnova-link-shortener-qr' ),
			'status' => $installed_version === QLQR_DB_VERSION ? 'ok' : 'warning',
			'detail' => $installed_version === QLQR_DB_VERSION
				/* translators: %s: the installed database schema version, e.g. "1.0.0" */
				? sprintf( __( 'Up to date (%s).', 'plugnova-link-shortener-qr' ), $installed_version )
				/* translators: 1: the database schema version currently installed (or "none"), 2: the schema version this plugin expects */
				: sprintf( __( 'Installed version is %1$s, plugin expects %2$s — click "Repair Now" to upgrade.', 'plugnova-link-shortener-qr' ), $installed_version ?: __( 'none', 'plugnova-link-shortener-qr' ), QLQR_DB_VERSION ),
		);

		foreach ( self::orphan_checks() as $check ) {
			$count = self::count_orphans( $check['child'], $check['parent'], $check['fk'] );

			if ( null === $count ) {
				continue; // Table doesn't exist yet — already reported above.
			}

			$results[] = array(
				/* translators: %s: database table name, e.g. "wp_qlqr_clicks" */
				'label'  => sprintf( __( 'Orphaned rows in %s', 'plugnova-link-shortener-qr' ), $check['child'] ),
				'status' => $count > 0 ? 'warning' : 'ok',
				'detail' => $count > 0
					/* translators: %d: number of rows whose parent row no longer exists */
					? sprintf( _n( '%d row with no matching parent — click "Repair Now" to remove it.', '%d rows with no matching parent — click "Repair Now" to remove them.', $count, 'plugnova-link-shortener-qr' ), $count )
					: __( 'No orphaned rows found.', 'plugnova-link-shortener-qr' ),
			);
		}

		return $results;
	}

	/**
	 * Count rows in $child whose $fk value has no matching row in $parent.id.
	 *
	 * @param string $child  Child table name.
	 * @param string $parent Parent table name.
	 * @param string $fk     Foreign key column on the child table.
	 * @return int|null Null if either table doesn't currently exist.
	 */
	private static function count_orphans( string $child, string $parent, string $fk ): ?int {
		global $wpdb;

		$child_exists  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $child ) ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders,WordPress.DB.DirectDatabaseQuery
		$parent_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $parent ) ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders,WordPress.DB.DirectDatabaseQuery

		if ( ! $child_exists || ! $parent_exists ) {
			return null;
		}

		// Table/column names here are always our own trusted, hardcoded strings — never user input.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$child} c LEFT JOIN {$parent} p ON p.id = c.{$fk} WHERE p.id IS NULL" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Fix everything check() flagged: re-run dbDelta (creates missing tables/columns and bumps
	 * the stored schema version), delete orphaned child rows, then OPTIMIZE every plugin table to
	 * reclaim space freed by the deletes.
	 *
	 * @return array{tables_synced: bool, orphans_removed: int, tables_optimized: int}
	 */
	public static function repair(): array {
		global $wpdb;

		Installer::install();

		$orphans_removed = 0;
		foreach ( self::orphan_checks() as $check ) {
			$child_exists  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $check['child'] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders,WordPress.DB.DirectDatabaseQuery
			$parent_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $check['parent'] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders,WordPress.DB.DirectDatabaseQuery

			if ( ! $child_exists || ! $parent_exists ) {
				continue;
			}

			$child  = $check['child'];
			$parent = $check['parent'];
			$fk     = $check['fk'];

			// Table/column names here are always our own trusted, hardcoded strings — never user input.
			$deleted = $wpdb->query( "DELETE c FROM {$child} c LEFT JOIN {$parent} p ON p.id = c.{$fk} WHERE p.id IS NULL" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			$orphans_removed += false !== $deleted ? (int) $deleted : 0;
		}

		$tables_optimized = 0;
		foreach ( array_keys( self::all_tables() ) as $table ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders,WordPress.DB.DirectDatabaseQuery

			if ( $exists ) {
				$wpdb->query( "OPTIMIZE TABLE {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				++$tables_optimized;
			}
		}

		return array(
			'tables_synced'    => true,
			'orphans_removed'  => $orphans_removed,
			'tables_optimized' => $tables_optimized,
		);
	}
}
