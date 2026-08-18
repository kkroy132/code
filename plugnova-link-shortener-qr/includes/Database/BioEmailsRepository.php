<?php
/**
 * Data access layer for the wp_qlqr_bio_emails table (addresses collected via a bio page's
 * optional email-capture gate).
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Database;

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
 * Class BioEmailsRepository
 */
final class BioEmailsRepository {

	/**
	 * Record a captured email address.
	 *
	 * @param int    $bio_page_id Bio page ID.
	 * @param string $email       Valid, already-sanitized email address.
	 */
	public function insert( int $bio_page_id, string $email ): void {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Installer::bio_emails_table(),
			array(
				'bio_page_id' => $bio_page_id,
				'email'       => $email,
				'created_at'  => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * All captured emails for a bio page, newest first — used for the CSV export.
	 *
	 * @param int $bio_page_id Bio page ID.
	 * @return array<int, array{email:string, created_at:string}>
	 */
	public function find_by_page( int $bio_page_id ): array {
		global $wpdb;
		$table = Installer::bio_emails_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT email, created_at FROM %i WHERE bio_page_id = %d ORDER BY created_at DESC', $table, $bio_page_id ),
			ARRAY_A
		);

		return $rows ?: array();
	}

	/**
	 * Count of captured emails for a bio page (shown in the admin list without loading every row).
	 *
	 * @param int $bio_page_id Bio page ID.
	 * @return int
	 */
	public function count_for_page( int $bio_page_id ): int {
		global $wpdb;
		$table = Installer::bio_emails_table();

		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(id) FROM %i WHERE bio_page_id = %d', $table, $bio_page_id )
		);
	}

	/**
	 * Delete all captured emails for a bio page. Called when a bio page is permanently deleted.
	 *
	 * @param int $bio_page_id Bio page ID.
	 */
	public function delete_for_page( int $bio_page_id ): void {
		global $wpdb;
		$wpdb->delete( Installer::bio_emails_table(), array( 'bio_page_id' => $bio_page_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
