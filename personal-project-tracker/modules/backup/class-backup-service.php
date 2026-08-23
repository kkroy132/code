<?php
/**
 * Backup creation/storage: the single place plugin data is read for a
 * backup or a scoped export, and the only place that writes to the
 * filesystem.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Backup_Service
 *
 * Backups are plain JSON files under wp-content/uploads/ptp-backups/,
 * written via the WordPress filesystem API (never fopen()/file_put_contents()
 * directly) and never web-servable — the directory carries both an empty
 * index.php and a deny-all .htaccess, and the only way to read one back is
 * through an authenticated, capability-gated admin-post download/import
 * handler. get_table_rows() is the one query primitive both backups and
 * PTP_Export_Service build on, so "read plugin data for output" exists in
 * exactly one place; every result also passes through redact_row(), which
 * reuses PTP_Prompt_Generator::redact() (Phase 10) rather than
 * re-implementing secret scrubbing.
 */
class PTP_Backup_Service {

	/**
	 * Short DB table name => backup/export JSON key. Matches this phase's
	 * explicit Backup "Include" list exactly — project_files, notifications,
	 * and activity_logs are deliberately not included (transient/derived
	 * data, not user-authored records), and the DB's unused `settings`
	 * table (real settings live in the ptp_settings *option*, see
	 * PTP_Settings) is backed up separately, not from this list.
	 *
	 * @var array<string, string>
	 */
	const TABLES = array(
		'projects'         => 'projects',
		'tasks'             => 'tasks',
		'subtasks'          => 'subtasks',
		'milestones'        => 'milestones',
		'calendar_events'   => 'calendar',
		'time_entries'      => 'time',
		'notes'             => 'notes',
		'project_links'     => 'links',
		'expenses'          => 'expenses',
		'revenues'          => 'revenue',
		'prompt_documents'  => 'prompts',
		'prompt_templates'  => 'templates',
		'reminders'         => 'reminders',
	);

	/**
	 * The date column each table's Date Range export scope filters on.
	 *
	 * @var array<string, string>
	 */
	const DATE_COLUMNS = array(
		'projects'        => 'created_at',
		'tasks'            => 'created_at',
		'subtasks'         => 'created_at',
		'milestones'       => 'created_at',
		'calendar_events'  => 'start_datetime',
		'time_entries'     => 'entry_date',
		'notes'            => 'created_at',
		'project_links'    => 'created_at',
		'expenses'         => 'expense_date',
		'revenues'         => 'revenue_date',
		'prompt_documents' => 'created_at',
		'prompt_templates' => 'created_at',
		'reminders'        => 'remind_at',
	);

	/**
	 * Free-text columns scrubbed via PTP_Prompt_Generator::redact() before
	 * a row ever reaches a backup or export file. Structured columns
	 * (status, dates, amounts, foreign keys) are never touched.
	 *
	 * @var array<string, string[]>
	 */
	const REDACT_COLUMNS = array(
		'projects'         => array( 'description' ),
		'tasks'             => array( 'description' ),
		'milestones'        => array( 'description' ),
		'notes'             => array( 'content' ),
		'project_links'     => array( 'description', 'url' ),
		'calendar_events'   => array( 'description' ),
		'expenses'          => array( 'description' ),
		'revenues'          => array( 'description' ),
		'prompt_documents'  => array( 'content', 'goal' ),
	);

	/**
	 * Per-table row cap for a full backup/export. This plugin is
	 * explicitly personal/small-team scale (see PTP_Settings' personal_mode
	 * default); a bound here keeps a backup from ever becoming an
	 * unbounded, memory-exhausting dump.
	 *
	 * @var int
	 */
	const MAX_ROWS_PER_TABLE = 5000;

	/**
	 * @param string $short_table Short table name, e.g. 'tasks'.
	 * @param array  $filters     Column => value (equality), or column => array of values (IN),
	 *                            or column => array{'op': '>='|'<=', 'value': string} (range).
	 * @param int    $limit       Row cap.
	 * @return array[] Associative rows, redacted.
	 */
	public static function get_table_rows( $short_table, array $filters = array(), $limit = self::MAX_ROWS_PER_TABLE ) {
		global $wpdb;

		$table  = ptp_table( $short_table );
		$where  = array( '1=1' );
		$params = array();

		foreach ( $filters as $column => $value ) {
			$column = preg_replace( '/[^a-z_]/', '', (string) $column );

			if ( is_array( $value ) && isset( $value['op'], $value['value'] ) ) {
				$op       = in_array( $value['op'], array( '>=', '<=' ), true ) ? $value['op'] : '=';
				$where[]  = "{$column} {$op} %s"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$params[] = $value['value'];
			} elseif ( is_array( $value ) ) {
				if ( empty( $value ) ) {
					$where[] = '1=0';
					continue;
				}

				$placeholders = implode( ',', array_fill( 0, count( $value ), is_int( $value[0] ) ? '%d' : '%s' ) );
				$where[]      = "{$column} IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$params        = array_merge( $params, array_values( $value ) );
			} else {
				$where[]  = is_int( $value ) ? "{$column} = %d" : "{$column} = %s"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$params[] = $value;
			}
		}

		$where_sql = implode( ' AND ', $where );
		$sql       = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id ASC LIMIT %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$params[]  = max( 1, (int) $limit );

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map( fn( $row ) => self::redact_row( $short_table, $row ), $rows );
	}

	/**
	 * @param string $short_table Short table name.
	 * @param array  $row         Associative row.
	 * @return array
	 */
	public static function redact_row( $short_table, array $row ) {
		if ( ! class_exists( 'PTP_Prompt_Generator' ) || empty( self::REDACT_COLUMNS[ $short_table ] ) ) {
			return $row;
		}

		foreach ( self::REDACT_COLUMNS[ $short_table ] as $column ) {
			if ( isset( $row[ $column ] ) && is_string( $row[ $column ] ) ) {
				$row[ $column ] = PTP_Prompt_Generator::redact( $row[ $column ] );
			}
		}

		return $row;
	}

	/**
	 * Settings never currently hold a password/token/key/secret, but this
	 * is a defensive backstop, not a promise about today's field list — any
	 * setting whose KEY name looks credential-shaped has its value scrubbed
	 * before it ever reaches a backup file, so a future setting added
	 * without redaction in mind still can't leak.
	 *
	 * @param array $settings PTP_Settings::get_all() result.
	 * @return array
	 */
	public static function redact_settings( array $settings ) {
		foreach ( $settings as $key => $value ) {
			if ( is_string( $value ) && preg_match( '/password|token|api[_-]?key|secret/i', (string) $key ) ) {
				$settings[ $key ] = '[REDACTED]';
			}
		}

		return $settings;
	}

	/**
	 * Full, unscoped dump of every backed-up table.
	 *
	 * @return array<string, array[]>
	 */
	public static function collect_all_data() {
		$out = array();

		foreach ( self::TABLES as $short_table => $key ) {
			$out[ $key ] = self::get_table_rows( $short_table );
		}

		return $out;
	}

	/**
	 * Dump scoped to a single project: every table with a direct
	 * project_id column filtered to it, subtasks resolved via their parent
	 * task's project_id (subtasks has no project_id column of its own),
	 * and reminders limited to ones directly related to the project itself
	 * (not transitively via its tasks/milestones).
	 *
	 * prompt_templates is a global, reusable resource with no project
	 * relationship and is intentionally omitted from a project-scoped export.
	 *
	 * @param int $project_id Project ID.
	 * @return array<string, array[]>
	 */
	public static function collect_project_scoped( $project_id ) {
		$project_id = (int) $project_id;
		$out        = array();

		$out['projects'] = self::get_table_rows( 'projects', array( 'id' => $project_id ) );

		$fk_tables = self::TABLES;
		unset( $fk_tables['projects'], $fk_tables['subtasks'], $fk_tables['prompt_templates'] );

		$task_ids = array();

		foreach ( $fk_tables as $short_table => $key ) {
			if ( 'reminders' === $short_table ) {
				continue;
			}

			$rows          = self::get_table_rows( $short_table, array( 'project_id' => $project_id ) );
			$out[ $key ]   = $rows;

			if ( 'tasks' === $short_table ) {
				$task_ids = wp_list_pluck( $rows, 'id' );
			}
		}

		$out['subtasks']  = $task_ids ? self::get_table_rows( 'subtasks', array( 'task_id' => array_map( 'intval', $task_ids ) ) ) : array();
		$out['reminders'] = self::get_table_rows( 'reminders', array( 'related_type' => 'project', 'related_id' => $project_id ) );

		return $out;
	}

	/**
	 * Dump scoped to a date range, using each table's own natural date
	 * column (see DATE_COLUMNS).
	 *
	 * @param string $date_from 'Y-m-d' inclusive start, or '' for no lower bound.
	 * @param string $date_to   'Y-m-d' inclusive end, or '' for no upper bound.
	 * @return array<string, array[]>
	 */
	public static function collect_date_range_scoped( $date_from, $date_to ) {
		$out = array();

		foreach ( self::TABLES as $short_table => $key ) {
			$filters = array();
			$column  = self::DATE_COLUMNS[ $short_table ];

			if ( $date_from ) {
				$filters[ $column ] = array( 'op' => '>=', 'value' => $date_from . ' 00:00:00' );
			}

			if ( $date_to ) {
				// A second filter on the same column: get_table_rows() keys
				// filters by column name, so a from+to pair needs two calls
				// merged, not one array key overwritten by the other.
				$rows = self::get_table_rows( $short_table, $filters );
				$rows = array_filter( $rows, fn( $row ) => ( $row[ $column ] ?? '' ) <= $date_to . ' 23:59:59' );
				$out[ $key ] = array_values( $rows );
				continue;
			}

			$out[ $key ] = self::get_table_rows( $short_table, $filters );
		}

		return $out;
	}

	/**
	 * Dump a single module/table only.
	 *
	 * @param string $short_table Short table name (must be a key of self::TABLES).
	 * @return array[]
	 */
	public static function collect_module_scoped( $short_table ) {
		if ( ! isset( self::TABLES[ $short_table ] ) ) {
			return array();
		}

		return self::get_table_rows( $short_table );
	}

	/**
	 * @return \WP_Filesystem_Base|null
	 */
	public static function get_filesystem() {
		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		return $wp_filesystem ?: null;
	}

	/**
	 * @return string Absolute path to the (not-yet-guaranteed-to-exist) backup directory.
	 */
	public static function get_backup_dir() {
		$upload_dir = wp_upload_dir();

		return trailingslashit( $upload_dir['basedir'] ) . 'ptp-backups';
	}

	/**
	 * Create the backup directory (if missing) and drop index.php/.htaccess
	 * so it is never directory-listable or otherwise web-servable.
	 *
	 * @return string|WP_Error Directory path, or an error if it could not be prepared.
	 */
	private static function ensure_backup_dir() {
		$fs = self::get_filesystem();

		if ( ! $fs ) {
			return new WP_Error( 'ptp_filesystem_unavailable', __( 'Could not access the filesystem.', 'personal-project-tracker' ) );
		}

		$dir = self::get_backup_dir();

		if ( ! $fs->is_dir( $dir ) && ! $fs->mkdir( $dir, defined( 'FS_CHMOD_DIR' ) ? FS_CHMOD_DIR : 0755 ) ) {
			return new WP_Error( 'ptp_backup_dir_failed', __( 'Could not create the backup directory.', 'personal-project-tracker' ) );
		}

		$index_file = trailingslashit( $dir ) . 'index.php';

		if ( ! $fs->exists( $index_file ) ) {
			$fs->put_contents( $index_file, "<?php\n// Silence is golden.\n" );
		}

		$htaccess_file = trailingslashit( $dir ) . '.htaccess';

		if ( ! $fs->exists( $htaccess_file ) ) {
			// "Deny from all" alone is Apache 2.2 syntax and is silently a
			// no-op on Apache 2.4 (the standard since ~2012) unless the
			// legacy mod_access_compat module is still enabled — include
			// both the 2.4 (Require all denied) and 2.2 syntax so this
			// actually blocks direct access on any Apache version. This is
			// still inert on non-Apache servers (nginx, IIS don't read
			// .htaccess at all); the unpredictable per-file random suffix
			// in create_backup()'s filename is the mitigation that holds
			// regardless of server software.
			$fs->put_contents(
				$htaccess_file,
				"<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n"
			);
		}

		return $dir;
	}

	/**
	 * Build the full backup payload (without writing anything) — also used
	 * by the Backup tab's "preview" and by tests, independent of the
	 * filesystem.
	 *
	 * @return array
	 */
	public static function build_payload() {
		return array(
			'plugin'         => 'personal-project-tracker',
			'plugin_version' => PTP_VERSION,
			'db_version'     => PTP_DB_VERSION,
			'created_at'     => ptp_now(),
			'tables'         => self::collect_all_data(),
			'settings'       => self::redact_settings( PTP_Settings::get_all() ),
		);
	}

	/**
	 * Create a new backup file and record it in the manifest.
	 *
	 * @return array|WP_Error Manifest entry, or an error.
	 */
	public static function create_backup() {
		$dir = self::ensure_backup_dir();

		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$fs      = self::get_filesystem();
		$payload = self::build_payload();
		$json    = wp_json_encode( $payload );

		if ( false === $json ) {
			return new WP_Error( 'ptp_backup_encode_failed', __( 'Could not encode backup data.', 'personal-project-tracker' ) );
		}

		// The backup directory sits under wp-content/uploads (see
		// get_backup_dir()) and is only protected from direct web access by
		// index.php + .htaccess (see ensure_backup_dir()), which is inert on
		// non-Apache servers (nginx, IIS don't read .htaccess). A wide,
		// unpredictable filename is the one mitigation that still holds
		// regardless of server software — wp_generate_password() draws on
		// PHP's CSPRNG, unlike the previous 8-hex-char md5(uniqid()) suffix
		// (uniqid() is time-seeded and only 32 bits, guessable).
		$filename = 'ptp-backup-' . gmdate( 'Y-m-d-His' ) . '-' . wp_generate_password( 32, false, false ) . '.json';
		$path     = trailingslashit( $dir ) . $filename;

		if ( ! $fs->put_contents( $path, $json, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 ) ) {
			return new WP_Error( 'ptp_backup_write_failed', __( 'Could not write the backup file.', 'personal-project-tracker' ) );
		}

		$entry = array(
			'id'             => uniqid( 'ptp_backup_', true ),
			'filename'       => $filename,
			'created_at'     => $payload['created_at'],
			'plugin_version' => PTP_VERSION,
			'db_version'     => PTP_DB_VERSION,
			'size'           => strlen( $json ),
		);

		PTP_Backup_Repository::add( $entry );

		PTP_Activity_Log::log( 'backup_created', 'backup', 0, __( 'Created a backup', 'personal-project-tracker' ) );

		return $entry;
	}

	/**
	 * @param string $id Manifest entry ID.
	 * @return array|WP_Error Decoded backup payload.
	 */
	public static function read_backup( $id ) {
		$entry = PTP_Backup_Repository::get( $id );

		if ( ! $entry ) {
			return new WP_Error( 'ptp_not_found', __( 'Backup not found.', 'personal-project-tracker' ) );
		}

		return self::read_backup_file( self::get_backup_dir() . '/' . $entry['filename'] );
	}

	/**
	 * @param string $path Absolute path to a backup JSON file.
	 * @return array|WP_Error
	 */
	public static function read_backup_file( $path ) {
		$fs = self::get_filesystem();

		if ( ! $fs || ! $fs->exists( $path ) ) {
			return new WP_Error( 'ptp_not_found', __( 'Backup file not found.', 'personal-project-tracker' ) );
		}

		$contents = $fs->get_contents( $path );
		$decoded  = json_decode( (string) $contents, true );

		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'ptp_invalid_backup', __( 'That file is not a valid backup.', 'personal-project-tracker' ) );
		}

		return $decoded;
	}

	/**
	 * @param string $id Manifest entry ID.
	 * @return true|WP_Error
	 */
	public static function delete_backup( $id ) {
		$entry = PTP_Backup_Repository::get( $id );

		if ( ! $entry ) {
			return new WP_Error( 'ptp_not_found', __( 'Backup not found.', 'personal-project-tracker' ) );
		}

		$fs   = self::get_filesystem();
		$path = self::get_backup_dir() . '/' . $entry['filename'];

		if ( $fs && $fs->exists( $path ) ) {
			$fs->delete( $path );
		}

		PTP_Backup_Repository::delete( $id );

		PTP_Activity_Log::log( 'backup_deleted', 'backup', 0, __( 'Deleted a backup', 'personal-project-tracker' ) );

		return true;
	}

	/**
	 * @param string $id Manifest entry ID.
	 * @return string|WP_Error Absolute file path.
	 */
	public static function get_backup_path( $id ) {
		$entry = PTP_Backup_Repository::get( $id );

		if ( ! $entry ) {
			return new WP_Error( 'ptp_not_found', __( 'Backup not found.', 'personal-project-tracker' ) );
		}

		return self::get_backup_dir() . '/' . $entry['filename'];
	}
}
