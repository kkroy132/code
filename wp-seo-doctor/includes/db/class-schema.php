<?php
/**
 * Custom table definitions and versioned install/upgrade routine.
 *
 * @package SEODoc
 */

namespace SEODoc\DB;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and upgrades the wp_seodoc_* tables via dbDelta.
 *
 * Bump self::DB_VERSION whenever a CREATE TABLE string below changes;
 * maybe_upgrade() re-runs dbDelta() for every table when the stored
 * option doesn't match, which is safe/idempotent by design of dbDelta.
 */
class Schema {

	/**
	 * 1.1.0 (Step 16 performance audit): added issues.object_lookup
	 * (object_id, object_type) — Issue_Engine::resolve_missing() filters
	 * on exactly that pair for every scanned post, every scan, and had
	 * no supporting index, meaning a full table scan per post once a
	 * site accumulates any meaningful number of issue rows.
	 */
	const DB_VERSION = '1.1.0';

	const VERSION_OPTION = 'seodoc_db_version';

	/**
	 * Runs all CREATE TABLE statements and records the schema version.
	 * Called on plugin activation.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		foreach ( self::get_table_definitions( $wpdb, $charset_collate ) as $sql ) {
			dbDelta( $sql );
		}

		update_option( self::VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Hooked to plugins_loaded; re-installs only when the stored version
	 * is stale, so this is cheap on every normal request.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * @param \wpdb  $wpdb
	 * @param string $charset_collate
	 * @return string[] dbDelta-formatted CREATE TABLE statements.
	 */
	private static function get_table_definitions( $wpdb, $charset_collate ) {
		$prefix = $wpdb->prefix . 'seodoc_';

		$tables = array();

		// ---------------------------------------------------------------
		// scans: one row per scan run.
		// ---------------------------------------------------------------
		$tables[] = "CREATE TABLE {$prefix}scans (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			scan_type VARCHAR(20) NOT NULL DEFAULT 'full',
			trigger_source VARCHAR(20) NOT NULL DEFAULT 'manual',
			status VARCHAR(20) NOT NULL DEFAULT 'queued',
			total_items INT UNSIGNED NOT NULL DEFAULT 0,
			processed_items INT UNSIGNED NOT NULL DEFAULT 0,
			health_score SMALLINT UNSIGNED DEFAULT NULL,
			previous_health_score SMALLINT UNSIGNED DEFAULT NULL,
			initiated_by BIGINT UNSIGNED DEFAULT NULL,
			meta LONGTEXT DEFAULT NULL,
			started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			finished_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY started_at (started_at)
		) {$charset_collate};";

		// ---------------------------------------------------------------
		// queue: scanner work units for in-progress scans.
		// ---------------------------------------------------------------
		$tables[] = "CREATE TABLE {$prefix}queue (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			scan_id BIGINT UNSIGNED NOT NULL,
			object_type VARCHAR(20) NOT NULL DEFAULT 'post',
			object_id BIGINT UNSIGNED DEFAULT NULL,
			url VARCHAR(767) DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
			claimed_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY scan_status (scan_id, status),
			KEY claimed_at (claimed_at)
		) {$charset_collate};";

		// ---------------------------------------------------------------
		// issues: detected problems, upserted across scans.
		// ---------------------------------------------------------------
		$tables[] = "CREATE TABLE {$prefix}issues (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			check_id VARCHAR(100) NOT NULL,
			category VARCHAR(20) NOT NULL,
			severity VARCHAR(20) NOT NULL,
			object_type VARCHAR(20) NOT NULL DEFAULT 'post',
			object_id BIGINT UNSIGNED DEFAULT NULL,
			url VARCHAR(767) DEFAULT NULL,
			url_hash CHAR(32) NOT NULL,
			title VARCHAR(255) NOT NULL,
			details LONGTEXT DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'open',
			scan_id BIGINT UNSIGNED DEFAULT NULL,
			first_detected DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			last_detected DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			resolved_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY check_url (check_id, url_hash),
			KEY status_severity (status, severity),
			KEY category (category),
			KEY object_lookup (object_id, object_type)
		) {$charset_collate};";

		// ---------------------------------------------------------------
		// links: internal/external link graph edges; is_broken doubles
		// this table as the broken-link intelligence table.
		// ---------------------------------------------------------------
		$tables[] = "CREATE TABLE {$prefix}links (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source_object_type VARCHAR(20) NOT NULL DEFAULT 'post',
			source_object_id BIGINT UNSIGNED DEFAULT NULL,
			source_url VARCHAR(767) DEFAULT NULL,
			source_hash CHAR(32) NOT NULL,
			target_url VARCHAR(767) DEFAULT NULL,
			target_hash CHAR(32) NOT NULL,
			anchor_text VARCHAR(255) DEFAULT NULL,
			anchor_hash CHAR(32) NOT NULL,
			link_type VARCHAR(10) NOT NULL DEFAULT 'internal',
			rel_attributes VARCHAR(100) DEFAULT NULL,
			is_broken TINYINT UNSIGNED NOT NULL DEFAULT 0,
			http_status SMALLINT UNSIGNED DEFAULT NULL,
			redirect_target VARCHAR(767) DEFAULT NULL,
			occurrences INT UNSIGNED NOT NULL DEFAULT 1,
			first_detected DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			last_checked DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY source_target_anchor (source_hash, target_hash, anchor_hash),
			KEY target_hash (target_hash),
			KEY is_broken (is_broken)
		) {$charset_collate};";

		// ---------------------------------------------------------------
		// 404: aggregated hit counts per URL (not one row per hit).
		// ---------------------------------------------------------------
		$tables[] = "CREATE TABLE {$prefix}404 (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			url VARCHAR(767) NOT NULL,
			url_hash CHAR(32) NOT NULL,
			hit_count BIGINT UNSIGNED NOT NULL DEFAULT 1,
			last_referrer VARCHAR(767) DEFAULT NULL,
			last_user_agent VARCHAR(255) DEFAULT NULL,
			suggested_redirect VARCHAR(767) DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'open',
			first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY url_hash (url_hash),
			KEY status_hits (status, hit_count)
		) {$charset_collate};";

		// ---------------------------------------------------------------
		// redirects: redirect rules. Uniqueness for exact-match rules is
		// enforced at the application layer (partial/conditional unique
		// indexes aren't portable across MySQL/MariaDB), so source_hash
		// is a plain index here, not UNIQUE.
		// ---------------------------------------------------------------
		$tables[] = "CREATE TABLE {$prefix}redirects (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source_path VARCHAR(767) NOT NULL,
			source_hash CHAR(32) NOT NULL,
			destination_url VARCHAR(767) NOT NULL,
			redirect_type SMALLINT UNSIGNED NOT NULL DEFAULT 301,
			is_regex TINYINT UNSIGNED NOT NULL DEFAULT 0,
			group_name VARCHAR(100) DEFAULT NULL,
			hit_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			created_by BIGINT UNSIGNED DEFAULT NULL,
			last_hit_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY source_hash (source_hash),
			KEY status (status)
		) {$charset_collate};";

		// ---------------------------------------------------------------
		// suggestions: internal-link suggestions, incl. Free's monthly
		// quota bucket (25/month).
		// ---------------------------------------------------------------
		$tables[] = "CREATE TABLE {$prefix}suggestions (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source_object_id BIGINT UNSIGNED NOT NULL,
			source_url VARCHAR(767) DEFAULT NULL,
			target_object_id BIGINT UNSIGNED NOT NULL,
			target_url VARCHAR(767) DEFAULT NULL,
			suggested_anchor VARCHAR(255) DEFAULT NULL,
			relevance_score DECIMAL(5,2) NOT NULL DEFAULT 0.00,
			reason VARCHAR(255) DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			month_bucket CHAR(7) NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			resolved_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY status_month (status, month_bucket),
			KEY source_object_id (source_object_id)
		) {$charset_collate};";

		// ---------------------------------------------------------------
		// reports: generated CSV/email/agency report records.
		// ---------------------------------------------------------------
		$tables[] = "CREATE TABLE {$prefix}reports (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			report_type VARCHAR(20) NOT NULL,
			period_start DATE DEFAULT NULL,
			period_end DATE DEFAULT NULL,
			summary LONGTEXT DEFAULT NULL,
			file_path VARCHAR(255) DEFAULT NULL,
			recipient VARCHAR(255) DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'generated',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY type_created (report_type, created_at)
		) {$charset_collate};";

		return $tables;
	}

	/**
	 * Table names, keyed by short identifier, for use by every module
	 * instead of re-concatenating $wpdb->prefix . 'seodoc_...' ad hoc.
	 *
	 * @param \wpdb $wpdb
	 * @return array<string, string>
	 */
	public static function table_names( $wpdb ) {
		$prefix = $wpdb->prefix . 'seodoc_';

		return array(
			'scans'       => $prefix . 'scans',
			'queue'       => $prefix . 'queue',
			'issues'      => $prefix . 'issues',
			'links'       => $prefix . 'links',
			'404'         => $prefix . '404',
			'redirects'   => $prefix . 'redirects',
			'suggestions' => $prefix . 'suggestions',
			'reports'     => $prefix . 'reports',
		);
	}
}
