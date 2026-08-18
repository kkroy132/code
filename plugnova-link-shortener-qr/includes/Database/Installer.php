<?php
/**
 * Handles creation and versioned upgrades of custom database tables.
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
 * Every runtime query below (drop_columns_if_present(), drop_tables()) binds its table/column
 * names through prepare()'s %i identifier placeholder rather than interpolating them, so those
 * are genuinely prepared and need no PreparedSQL suppression. The one exception is install()'s
 * dbDelta() schema definitions further down: dbDelta() requires a literal CREATE TABLE string
 * (it parses the SQL text itself, line by line) and cannot accept a %i-prepared query, so that
 * section keeps its own narrowly-scoped PreparedSQL.InterpolatedNotPrepared suppression rather
 * than one at file level.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Class Installer
 */
final class Installer {

	/**
	 * Create or upgrade the plugin's custom database tables using dbDelta().
	 * dbDelta() is idempotent and safe to call on every version change.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$links_table  = self::links_table();
		$clicks_table = self::clicks_table();

		// dbDelta() parses each of these as a literal CREATE TABLE string — it cannot accept a
		// %i-prepared query — so the table name and $charset_collate are necessarily interpolated
		// directly here. Both come only from self::*_table() (an internal fixed suffix appended to
		// $wpdb->prefix) and $wpdb->get_charset_collate(); neither is ever caller-supplied.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql_links = "CREATE TABLE {$links_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(255) NOT NULL DEFAULT '',
			destination_url TEXT NOT NULL,
			short_slug VARCHAR(190) NOT NULL,
			qr_image VARCHAR(255) NULL,
			qr_style VARCHAR(20) NOT NULL DEFAULT 'square',
			qr_fg_color VARCHAR(7) NOT NULL DEFAULT '#000000',
			qr_bg_color VARCHAR(7) NOT NULL DEFAULT '#ffffff',
			qr_logo_id BIGINT UNSIGNED NULL,
			qr_caption_text VARCHAR(255) NULL,
			destination_type VARCHAR(10) NOT NULL DEFAULT 'single',
			rotation_method VARCHAR(20) NOT NULL DEFAULT 'round_robin',
			rotation_cursor INT UNSIGNED NOT NULL DEFAULT 0,
			fallback_url TEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			password VARCHAR(255) NULL,
			expires_at DATETIME NULL,
			click_limit BIGINT UNSIGNED NULL,
			redirect_type SMALLINT UNSIGNED NOT NULL DEFAULT 301,
			utm_source VARCHAR(190) NULL,
			utm_medium VARCHAR(190) NULL,
			utm_campaign VARCHAR(190) NULL,
			tags VARCHAR(255) NULL,
			category VARCHAR(100) NULL,
			notes TEXT NULL,
			is_favorite TINYINT(1) NOT NULL DEFAULT 0,
			nofollow TINYINT(1) NOT NULL DEFAULT 0,
			sponsored TINYINT(1) NOT NULL DEFAULT 0,
			new_tab TINYINT(1) NOT NULL DEFAULT 1,
			has_targeting_rules TINYINT(1) NOT NULL DEFAULT 0,
			link_status VARCHAR(20) NOT NULL DEFAULT 'unknown',
			http_status SMALLINT NULL,
			response_time_ms INT UNSIGNED NULL,
			meta_title VARCHAR(255) NULL,
			has_redirect_loop TINYINT(1) NOT NULL DEFAULT 0,
			color_label VARCHAR(20) NULL,
			last_checked_at DATETIME NULL,
			total_clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			deleted_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY short_slug (short_slug),
			KEY status (status),
			KEY created_by (created_by),
			KEY deleted_at (deleted_at),
			KEY category (category),
			KEY link_status (link_status)
		) {$charset_collate};";

		/*
		 * link_clicked_at is a composite index for the shape nearly every analytics query actually
		 * has: "this link, within this date window". With only single-column keys on link_id and
		 * clicked_at, MySQL can use one of them and must then filter the remaining matches row by
		 * row — fine at a few thousand clicks, progressively worse as the table grows. It also
		 * serves the retention sweep, which deletes by clicked_at.
		 *
		 * Note for anyone editing the SQL below: dbDelta() parses the body line by line and reads
		 * each line as a column or key definition, so comments must stay outside the string.
		 */
		$sql_clicks = "CREATE TABLE {$clicks_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			link_id BIGINT UNSIGNED NOT NULL,
			country VARCHAR(2) NULL,
			device VARCHAR(20) NULL,
			browser VARCHAR(60) NULL,
			operating_system VARCHAR(60) NULL,
			referrer VARCHAR(255) NULL,
			utm_source VARCHAR(190) NULL,
			utm_medium VARCHAR(190) NULL,
			utm_campaign VARCHAR(190) NULL,
			source VARCHAR(10) NOT NULL DEFAULT 'link',
			language VARCHAR(20) NULL,
			ip_hash CHAR(64) NULL,
			user_agent VARCHAR(255) NULL,
			is_bot TINYINT(1) NOT NULL DEFAULT 0,
			is_unique TINYINT(1) NOT NULL DEFAULT 0,
			clicked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY link_id (link_id),
			KEY clicked_at (clicked_at),
			KEY link_clicked_at (link_id, clicked_at),
			KEY country (country),
			KEY device (device),
			KEY is_bot (is_bot),
			KEY source (source)
		) {$charset_collate};";

		$destinations_table = self::destinations_table();

		$sql_destinations = "CREATE TABLE {$destinations_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			link_id BIGINT UNSIGNED NOT NULL,
			destination_url TEXT NOT NULL,
			weight INT UNSIGNED NOT NULL DEFAULT 1,
			position INT UNSIGNED NOT NULL DEFAULT 0,
			clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(10) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY link_id (link_id),
			KEY status (status)
		) {$charset_collate};";

		$targeting_rules_table = self::targeting_rules_table();

		// Per-link Geo/Device targeting rules: when a link has any active row here, the redirect
		// controller checks them (in position order, first match wins) before falling back to the
		// link's normal single/multi-destination resolution. See Controllers\RedirectController.
		$sql_targeting_rules = "CREATE TABLE {$targeting_rules_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			link_id BIGINT UNSIGNED NOT NULL,
			rule_type VARCHAR(10) NOT NULL DEFAULT 'country',
			match_value VARCHAR(20) NOT NULL,
			destination_url TEXT NOT NULL,
			position INT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(10) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY link_id (link_id),
			KEY status (status)
		) {$charset_collate};";

		$keywords_table = self::keywords_table();

		// Keyword auto-linking: the first (or first N) occurrence of "keyword" in post content
		// gets automatically wrapped in a link to the matching link_id. See
		// Frontend\KeywordLinker, hooked on 'the_content'.
		$sql_keywords = "CREATE TABLE {$keywords_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			link_id BIGINT UNSIGNED NOT NULL,
			keyword VARCHAR(190) NOT NULL,
			case_sensitive TINYINT(1) NOT NULL DEFAULT 0,
			max_replacements INT UNSIGNED NOT NULL DEFAULT 1,
			status VARCHAR(10) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY link_id (link_id),
			KEY status (status),
			KEY keyword (keyword)
		) {$charset_collate};";

		$bio_pages_table = self::bio_pages_table();

		$sql_bio_pages = "CREATE TABLE {$bio_pages_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			slug VARCHAR(190) NOT NULL,
			title VARCHAR(255) NOT NULL DEFAULT '',
			bio_text TEXT NULL,
			avatar_url VARCHAR(500) NULL,
			theme_color VARCHAR(7) NOT NULL DEFAULT '#2271b1',
			theme_preset VARCHAR(20) NOT NULL DEFAULT 'light',
			button_style VARCHAR(20) NOT NULL DEFAULT 'rounded',
			email_capture_enabled TINYINT(1) NOT NULL DEFAULT 0,
			email_capture_heading VARCHAR(255) NULL,
			qr_image VARCHAR(255) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			password VARCHAR(255) NULL,
			countdown_enabled TINYINT(1) NOT NULL DEFAULT 0,
			countdown_label VARCHAR(190) NULL,
			countdown_target_at DATETIME NULL,
			announcement_enabled TINYINT(1) NOT NULL DEFAULT 0,
			announcement_text VARCHAR(255) NULL,
			announcement_bg_color VARCHAR(7) NOT NULL DEFAULT '#2271b1',
			announcement_text_color VARCHAR(7) NOT NULL DEFAULT '#ffffff',
			announcement_url VARCHAR(500) NULL,
			starts_at DATETIME NULL,
			ends_at DATETIME NULL,
			total_views BIGINT UNSIGNED NOT NULL DEFAULT 0,
			qr_views BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			deleted_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY status (status),
			KEY deleted_at (deleted_at)
		) {$charset_collate};";

		$bio_links_table = self::bio_links_table();

		$sql_bio_links = "CREATE TABLE {$bio_links_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			bio_page_id BIGINT UNSIGNED NOT NULL,
			label VARCHAR(190) NOT NULL,
			url TEXT NOT NULL,
			icon VARCHAR(10) NULL,
			image_url VARCHAR(500) NULL,
			position INT UNSIGNED NOT NULL DEFAULT 0,
			clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(10) NOT NULL DEFAULT 'active',
			starts_at DATETIME NULL,
			ends_at DATETIME NULL,
			item_type VARCHAR(10) NOT NULL DEFAULT 'link',
			group_key VARCHAR(40) NULL,
			parent_group_key VARCHAR(40) NULL,
			visible_countries VARCHAR(500) NULL,
			visible_devices VARCHAR(60) NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY bio_page_id (bio_page_id),
			KEY status (status)
		) {$charset_collate};";

		$bio_socials_table = self::bio_socials_table();

		// Separate from wp_qlqr_bio_links: these render as a row of small circular icon buttons
		// (platform icon only, no label) near the top of the bio page, the way Linktree's social
		// row works, rather than as full-width buttons in the main link list. A row can also be
		// flagged is_floating, in which case it's excluded from that inline row and instead
		// rendered as a fixed-position floating action button (see templates/bio-page.php).
		$sql_bio_socials = "CREATE TABLE {$bio_socials_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			bio_page_id BIGINT UNSIGNED NOT NULL,
			platform VARCHAR(30) NOT NULL,
			url TEXT NOT NULL,
			position INT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(10) NOT NULL DEFAULT 'active',
			is_floating TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY bio_page_id (bio_page_id)
		) {$charset_collate};";

		$bio_emails_table = self::bio_emails_table();

		$sql_bio_emails = "CREATE TABLE {$bio_emails_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			bio_page_id BIGINT UNSIGNED NOT NULL,
			email VARCHAR(190) NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY bio_page_id (bio_page_id)
		) {$charset_collate};";

		$bio_views_table = self::bio_views_table();

		// One row per public page view (mirrors wp_qlqr_clicks' shape — including, as of the
		// browser/operating_system/referrer/utm_* columns below, the same breakdown dimensions a
		// short link's analytics gets), so the bio page analytics modal can show a real "views
		// over time" chart and device/country/browser/referrer/campaign breakdowns instead of
		// just the running total_views/qr_views counters on wp_qlqr_bio_pages.
		// page_viewed_at mirrors wp_qlqr_clicks' link_clicked_at; see the note above that table.
		$sql_bio_views = "CREATE TABLE {$bio_views_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			bio_page_id BIGINT UNSIGNED NOT NULL,
			country VARCHAR(2) NULL,
			device VARCHAR(20) NULL,
			browser VARCHAR(60) NULL,
			operating_system VARCHAR(60) NULL,
			referrer VARCHAR(255) NULL,
			utm_source VARCHAR(190) NULL,
			utm_medium VARCHAR(190) NULL,
			utm_campaign VARCHAR(190) NULL,
			source VARCHAR(10) NOT NULL DEFAULT 'link',
			ip_hash CHAR(64) NULL,
			is_bot TINYINT(1) NOT NULL DEFAULT 0,
			viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY bio_page_id (bio_page_id),
			KEY viewed_at (viewed_at),
			KEY page_viewed_at (bio_page_id, viewed_at),
			KEY is_bot (is_bot)
		) {$charset_collate};";

		$activity_log_table = self::activity_log_table();

		// One row per meaningful admin action (link/bio page/keyword created, updated, trashed,
		// deleted, status toggled, etc.) — an audit trail shown on the Activity Log admin tab.
		// object_id is nullable because bulk-scope entries (e.g. "Bulk trashed 5 links") are not
		// tied to a single row.
		$sql_activity_log = "CREATE TABLE {$activity_log_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			object_type VARCHAR(20) NOT NULL,
			object_id BIGINT UNSIGNED NULL,
			action VARCHAR(40) NOT NULL,
			description VARCHAR(500) NOT NULL DEFAULT '',
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_name VARCHAR(190) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY object_type (object_type),
			KEY object_id (object_id),
			KEY user_id (user_id),
			KEY created_at (created_at)
		) {$charset_collate};";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		dbDelta( $sql_links );
		dbDelta( $sql_clicks );
		dbDelta( $sql_destinations );
		dbDelta( $sql_targeting_rules );
		dbDelta( $sql_keywords );
		dbDelta( $sql_bio_pages );
		dbDelta( $sql_bio_links );
		dbDelta( $sql_bio_socials );
		dbDelta( $sql_bio_emails );
		dbDelta( $sql_bio_views );
		dbDelta( $sql_activity_log );

		self::drop_unused_columns();

		update_option( 'qlqr_db_version', QLQR_DB_VERSION, true );
	}

	/**
	 * Drop columns the plugin no longer defines.
	 *
	 * dbDelta() creates and widens columns but never removes one, so a column dropped from the
	 * schema above lingers forever on sites that already had it. city and region were declared when
	 * the clicks table was first designed and never written to: the bundled GeoLocator resolves
	 * country only, because city-level data would mean a far larger database to download. Two
	 * permanently NULL columns cost almost nothing in storage, but they mislead — the next person
	 * to read the schema reasonably concludes the data went missing.
	 *
	 * Each column is checked before being dropped, so this is safe to run on every version change
	 * and on installs that never had them.
	 */
	private static function drop_unused_columns(): void {
		global $wpdb;

		self::drop_columns_if_present( self::clicks_table(), array( 'city', 'region' ) );

		// custom_css let an admin inject raw CSS into the public bio page's own <style> block —
		// removed outright (not just unused going forward) since the plugin directory guidelines
		// no longer permit arbitrary CSS/JS/PHP paste-through, even from an admin-only field.
		self::drop_columns_if_present( self::bio_pages_table(), array( 'custom_css' ) );
	}

	/**
	 * Drop each named column from a table if it currently exists. Shared by drop_unused_columns()
	 * for every table/column pair it needs to clean up on an existing install; dbDelta() only ever
	 * adds or widens columns, never removes them, so this is the only way a column actually leaves
	 * the schema once shipped.
	 *
	 * @param string   $table   Fully-qualified table name (from one of the self::*_table() helpers).
	 * @param string[] $columns Column names to drop if present.
	 */
	private static function drop_columns_if_present( string $table, array $columns ): void {
		global $wpdb;

		foreach ( $columns as $column ) {
			$exists = $wpdb->get_var(
				$wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $column )
			);

			if ( ! $exists ) {
				continue;
			}

			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN %i', $table, $column ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
		}
	}

	/**
	 * Fully remove plugin tables. Only called from uninstall.php when the user opted in.
	 */
	public static function drop_tables(): void {
		global $wpdb;

		// Child tables first, then their parents. Every name comes from self::*_table(), i.e. the
		// trusted $wpdb->prefix plus a hardcoded suffix — never user input. A direct query is
		// required here: dbDelta() cannot drop tables, and DDL statements accept no wpdb::prepare()
		// placeholders (a table name can never be a bound parameter).
		$tables = array(
			self::destinations_table(),
			self::targeting_rules_table(),
			self::keywords_table(),
			self::clicks_table(),
			self::links_table(),
			self::bio_views_table(),
			self::bio_emails_table(),
			self::bio_socials_table(),
			self::bio_links_table(),
			self::bio_pages_table(),
			self::activity_log_table(),
		);

		foreach ( $tables as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
		}
	}

	/**
	 * Fully qualified links table name (with $wpdb prefix).
	 *
	 * @return string
	 */
	public static function links_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'qlqr_links';
	}

	/**
	 * Fully qualified clicks table name (with $wpdb prefix).
	 *
	 * @return string
	 */
	public static function clicks_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'qlqr_clicks';
	}

	/**
	 * Fully qualified destinations table name (with $wpdb prefix). Stores the individual URLs
	 * for a multi-destination rotating link (see Helpers\DestinationRotator).
	 *
	 * @return string
	 */
	public static function destinations_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'qlqr_destinations';
	}

	/**
	 * Fully qualified targeting rules table name (with $wpdb prefix). Stores per-link Geo/Device
	 * redirect rules (see Helpers\GeoLocator / Helpers\DeviceDetector for how visitors are matched).
	 *
	 * @return string
	 */
	public static function targeting_rules_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'qlqr_targeting_rules';
	}

	/**
	 * Fully qualified keywords table name (with $wpdb prefix). Stores keyword-auto-linking rules.
	 *
	 * @return string
	 */
	public static function keywords_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'qlqr_keywords';
	}

	/**
	 * Fully qualified bio pages table name (with $wpdb prefix). Stores Smart Bio Link landing pages.
	 *
	 * @return string
	 */
	public static function bio_pages_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'qlqr_bio_pages';
	}

	/**
	 * Fully qualified bio links table name (with $wpdb prefix). Stores the individual buttons
	 * shown on a Smart Bio Link page.
	 *
	 * @return string
	 */
	public static function bio_links_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'qlqr_bio_links';
	}

	/**
	 * Fully qualified bio socials table name (with $wpdb prefix). Stores the small circular
	 * social-platform icon row shown on a Smart Bio Link page.
	 *
	 * @return string
	 */
	public static function bio_socials_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'qlqr_bio_socials';
	}

	/**
	 * Fully qualified bio emails table name (with $wpdb prefix). Stores addresses collected via
	 * a bio page's optional email-capture gate.
	 *
	 * @return string
	 */
	public static function bio_emails_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'qlqr_bio_emails';
	}

	/**
	 * Fully qualified bio views table name (with $wpdb prefix). Stores one row per public bio
	 * page view, powering the bio page analytics modal's "views over time" chart.
	 *
	 * @return string
	 */
	public static function bio_views_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'qlqr_bio_views';
	}

	/**
	 * Fully qualified activity log table name (with $wpdb prefix). Stores the audit trail of
	 * admin actions shown on the Activity Log admin tab.
	 *
	 * @return string
	 */
	public static function activity_log_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'qlqr_activity_log';
	}
}
