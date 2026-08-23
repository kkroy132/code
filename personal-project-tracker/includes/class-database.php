<?php
/**
 * Database schema and migration handling.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Database
 *
 * Owns the plugin's custom table schema and versioned migrations.
 * All queries elsewhere in the plugin must go through $wpdb->prepare()
 * and must resolve table names via ptp_table() / self::get_table() —
 * never a hard-coded prefix.
 */
class PTP_Database {

	/**
	 * Option name used to track the installed database schema version.
	 *
	 * @var string
	 */
	const DB_VERSION_OPTION = 'ptp_db_version';

	/**
	 * Get a fully-prefixed plugin table name.
	 *
	 * @param string $name Short table name, e.g. 'projects'.
	 * @return string
	 */
	public static function get_table( $name ) {
		return ptp_table( $name );
	}

	/**
	 * Install or upgrade all plugin tables to match the current schema.
	 *
	 * Safe to call repeatedly: dbDelta() only creates missing tables/columns
	 * and never drops existing data.
	 */
	public static function install() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = self::get_charset_collate();

		foreach ( self::get_schema( $charset_collate ) as $sql ) {
			dbDelta( $sql );
		}

		update_option( self::DB_VERSION_OPTION, PTP_DB_VERSION, false );
	}

	/**
	 * Run any migrations needed to bring an existing install up to PTP_DB_VERSION.
	 *
	 * Called on admin_init so upgrades apply automatically after a plugin update,
	 * without requiring a manual deactivate/reactivate.
	 */
	public static function maybe_upgrade() {
		$installed_version = (int) get_option( self::DB_VERSION_OPTION, 0 );

		if ( $installed_version < PTP_DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Get the current $wpdb charset/collation clause.
	 *
	 * @return string
	 */
	private static function get_charset_collate() {
		global $wpdb;

		return $wpdb->get_charset_collate();
	}

	/**
	 * Full CREATE TABLE statement set, keyed loosely by table for readability.
	 *
	 * dbDelta() is strict about formatting: two spaces after PRIMARY KEY,
	 * each field/key on its own line, no backticks around index names.
	 *
	 * Schema history:
	 * - v1: initial schema (all core tables).
	 * - v2: tasks.archived_at (Archive/Restore Task), subtasks.sort_order
	 *   + a (task_id, sort_order) index (Reorder Subtask). dbDelta() only
	 *   adds the new columns/keys to existing installs — no data is touched.
	 * - v3: milestones.start_date, milestones.archived_at (Archive/Restore
	 *   Milestone, independent of status — same pattern as Tasks).
	 * - v4: calendar_events.location, calendar_events.color,
	 *   calendar_events.reminder_minutes (an inert integration point for
	 *   the future Reminders/Notifications module — no cron or delivery
	 *   logic is implemented yet, just the data column to hang it off of).
	 * - v5: time_entries.user_id (each entry is owned by the user who
	 *   tracked it — needed to enforce "one active timer per user") and
	 *   time_entries.resumed_at (timestamp the current running segment
	 *   began; NULL while paused/stopped — lets Pause/Resume/Stop compute
	 *   accumulated duration without ever losing the entry's original
	 *   start_time).
	 * - v6: prompt_documents.role, prompt_documents.goal,
	 *   prompt_documents.output_format (AI Prompt Studio's structured
	 *   inputs) and prompt_documents.config (JSON-encoded context
	 *   selections, requirements/constraints, and custom role/output
	 *   labels — kept as one JSON blob rather than a column per option so
	 *   future context types don't require another migration).
	 * - v7: notifications.category (the preference bucket a notification
	 *   belongs to — tasks/milestones/projects/calendar/time/finance/
	 *   custom/smart_alerts — also used to hide finance-flavored alerts
	 *   from users without ptp_manage_finance, the same way Finance data
	 *   stays private everywhere else), notifications.dedup_key (a stable,
	 *   rule/occurrence-scoped string checked before every insert so a
	 *   reminder firing twice or a smart alert re-evaluating hourly never
	 *   creates duplicate rows), notifications.snoozed_until (Notification
	 *   Center snooze, independent of reminders.snoozed_until which snoozes
	 *   the *reminder*, not an already-delivered notification), and
	 *   notifications.updated_at.
	 * - v8: performance indexes only, no new columns — every one of these
	 *   backs a WHERE clause that repository code already runs without an
	 *   index behind it: tasks.archived_at and milestones.archived_at
	 *   (filtered on almost every list/report/dashboard query via the
	 *   default "active" view); notes/project_links/project_files.task_id
	 *   and .milestone_id, and project_files.note_id (each module's
	 *   detail-page "related records" section filters by one of these);
	 *   a (user_id, status) composite on time_entries (the "one active
	 *   timer per user" lookup); a (object_type, object_id) composite on
	 *   activity_logs (every detail page's Activity section); and
	 *   reminders.related_type (the Reminders list's Related Type filter).
	 *
	 * @param string $charset_collate Charset/collation clause.
	 * @return string[] List of CREATE TABLE statements.
	 */
	private static function get_schema( $charset_collate ) {
		global $wpdb;

		$prefix = $wpdb->prefix . 'ptp_';
		$sql    = array();

		$sql[] = "CREATE TABLE {$prefix}projects (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(255) NOT NULL,
			description LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'planning',
			priority VARCHAR(20) NOT NULL DEFAULT 'medium',
			start_date DATE NULL,
			deadline DATE NULL,
			budget DECIMAL(15,2) NULL,
			currency VARCHAR(10) NOT NULL DEFAULT 'USD',
			estimated_revenue DECIMAL(15,2) NULL,
			actual_revenue DECIMAL(15,2) NOT NULL DEFAULT 0,
			actual_expenses DECIMAL(15,2) NOT NULL DEFAULT 0,
			progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
			color VARCHAR(20) NULL,
			owner_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			archived_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY priority (priority),
			KEY owner_id (owner_id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$prefix}tasks (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			project_id BIGINT UNSIGNED NOT NULL,
			milestone_id BIGINT UNSIGNED NULL,
			title VARCHAR(255) NOT NULL,
			description LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'todo',
			priority VARCHAR(20) NOT NULL DEFAULT 'medium',
			due_date DATE NULL,
			start_date DATE NULL,
			estimated_time DECIMAL(10,2) NULL,
			actual_time DECIMAL(10,2) NULL,
			assigned_user BIGINT UNSIGNED NULL,
			tags VARCHAR(500) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			archived_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY project_id (project_id),
			KEY milestone_id (milestone_id),
			KEY status (status),
			KEY due_date (due_date),
			KEY archived_at (archived_at)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$prefix}subtasks (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			task_id BIGINT UNSIGNED NOT NULL,
			title VARCHAR(255) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'todo',
			priority VARCHAR(20) NOT NULL DEFAULT 'medium',
			due_date DATE NULL,
			completed TINYINT(1) NOT NULL DEFAULT 0,
			sort_order INT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY task_id (task_id),
			KEY task_sort (task_id, sort_order)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$prefix}milestones (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			project_id BIGINT UNSIGNED NOT NULL,
			title VARCHAR(255) NOT NULL,
			description LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'planning',
			start_date DATE NULL,
			due_date DATE NULL,
			progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
			priority VARCHAR(20) NOT NULL DEFAULT 'medium',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			archived_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY project_id (project_id),
			KEY status (status),
			KEY due_date (due_date),
			KEY archived_at (archived_at)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$prefix}calendar_events (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			project_id BIGINT UNSIGNED NULL,
			task_id BIGINT UNSIGNED NULL,
			milestone_id BIGINT UNSIGNED NULL,
			title VARCHAR(255) NOT NULL,
			description LONGTEXT NULL,
			event_type VARCHAR(30) NOT NULL DEFAULT 'custom',
			start_datetime DATETIME NOT NULL,
			end_datetime DATETIME NULL,
			all_day TINYINT(1) NOT NULL DEFAULT 0,
			location VARCHAR(255) NULL,
			color VARCHAR(20) NULL,
			reminder_minutes SMALLINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY project_id (project_id),
			KEY start_datetime (start_datetime),
			KEY event_type (event_type)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$prefix}time_entries (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			project_id BIGINT UNSIGNED NULL,
			task_id BIGINT UNSIGNED NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			description VARCHAR(500) NULL,
			start_time DATETIME NULL,
			end_time DATETIME NULL,
			resumed_at DATETIME NULL,
			duration INT UNSIGNED NULL,
			entry_date DATE NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'stopped',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY project_id (project_id),
			KEY task_id (task_id),
			KEY user_id (user_id),
			KEY entry_date (entry_date),
			KEY user_status (user_id, status)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$prefix}notes (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			project_id BIGINT UNSIGNED NULL,
			task_id BIGINT UNSIGNED NULL,
			milestone_id BIGINT UNSIGNED NULL,
			title VARCHAR(255) NULL,
			content LONGTEXT NULL,
			pinned TINYINT(1) NOT NULL DEFAULT 0,
			archived TINYINT(1) NOT NULL DEFAULT 0,
			tags VARCHAR(500) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY project_id (project_id),
			KEY task_id (task_id),
			KEY milestone_id (milestone_id),
			KEY pinned (pinned),
			KEY archived (archived)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$prefix}project_links (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			project_id BIGINT UNSIGNED NULL,
			task_id BIGINT UNSIGNED NULL,
			milestone_id BIGINT UNSIGNED NULL,
			title VARCHAR(255) NOT NULL,
			url VARCHAR(1000) NOT NULL,
			description TEXT NULL,
			category VARCHAR(100) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY project_id (project_id),
			KEY task_id (task_id),
			KEY milestone_id (milestone_id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$prefix}project_files (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			project_id BIGINT UNSIGNED NULL,
			task_id BIGINT UNSIGNED NULL,
			milestone_id BIGINT UNSIGNED NULL,
			note_id BIGINT UNSIGNED NULL,
			attachment_id BIGINT UNSIGNED NOT NULL,
			file_name VARCHAR(255) NULL,
			file_type VARCHAR(100) NULL,
			file_size BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY project_id (project_id),
			KEY task_id (task_id),
			KEY milestone_id (milestone_id),
			KEY note_id (note_id),
			KEY attachment_id (attachment_id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$prefix}expenses (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			project_id BIGINT UNSIGNED NULL,
			amount DECIMAL(15,2) NOT NULL DEFAULT 0,
			currency VARCHAR(10) NOT NULL DEFAULT 'USD',
			category VARCHAR(100) NULL,
			description VARCHAR(500) NULL,
			expense_date DATE NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY project_id (project_id),
			KEY expense_date (expense_date)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$prefix}revenues (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			project_id BIGINT UNSIGNED NULL,
			amount DECIMAL(15,2) NOT NULL DEFAULT 0,
			currency VARCHAR(10) NOT NULL DEFAULT 'USD',
			category VARCHAR(100) NULL,
			description VARCHAR(500) NULL,
			revenue_date DATE NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY project_id (project_id),
			KEY revenue_date (revenue_date)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$prefix}notifications (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			type VARCHAR(50) NOT NULL,
			title VARCHAR(255) NOT NULL,
			message TEXT NULL,
			related_type VARCHAR(50) NULL,
			related_id BIGINT UNSIGNED NULL,
			is_read TINYINT(1) NOT NULL DEFAULT 0,
			category VARCHAR(30) NULL,
			dedup_key VARCHAR(191) NULL,
			snoozed_until DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY is_read (is_read),
			KEY type (type),
			KEY category (category),
			KEY dedup_key (dedup_key)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$prefix}reminders (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(255) NOT NULL,
			related_type VARCHAR(50) NULL,
			related_id BIGINT UNSIGNED NULL,
			remind_at DATETIME NOT NULL,
			recurrence VARCHAR(20) NOT NULL DEFAULT 'none',
			recurrence_interval INT UNSIGNED NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			snoozed_until DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY remind_at (remind_at),
			KEY status (status),
			KEY related_type (related_type)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$prefix}prompt_documents (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(255) NOT NULL,
			content LONGTEXT NULL,
			context_type VARCHAR(50) NOT NULL DEFAULT 'custom',
			project_id BIGINT UNSIGNED NULL,
			favorite TINYINT(1) NOT NULL DEFAULT 0,
			role VARCHAR(255) NULL,
			goal TEXT NULL,
			output_format VARCHAR(50) NULL,
			config LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY project_id (project_id),
			KEY favorite (favorite),
			KEY context_type (context_type)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$prefix}prompt_templates (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL,
			template LONGTEXT NULL,
			category VARCHAR(100) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$prefix}activity_logs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			action VARCHAR(100) NOT NULL,
			object_type VARCHAR(50) NULL,
			object_id BIGINT UNSIGNED NULL,
			description VARCHAR(500) NULL,
			user_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY object_type (object_type),
			KEY created_at (created_at),
			KEY user_id (user_id),
			KEY object_type_id (object_type, object_id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$prefix}settings (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			setting_key VARCHAR(191) NOT NULL,
			setting_value LONGTEXT NULL,
			autoload TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY setting_key (setting_key)
		) {$charset_collate};";

		return $sql;
	}

	/**
	 * Short names of every table this plugin owns, used by uninstall/backup.
	 *
	 * @return string[]
	 */
	public static function get_table_names() {
		$names = array(
			'projects',
			'tasks',
			'subtasks',
			'milestones',
			'calendar_events',
			'time_entries',
			'notes',
			'project_links',
			'project_files',
			'expenses',
			'revenues',
			'notifications',
			'reminders',
			'prompt_documents',
			'prompt_templates',
			'activity_logs',
			'settings',
		);

		return array_map( array( __CLASS__, 'get_table' ), $names );
	}
}
