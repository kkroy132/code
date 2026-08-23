<?php
/**
 * Import/Restore: validation, preview, and applying an (already-validated)
 * backup/export payload back into the plugin's own tables.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Import_Service
 *
 * Two-step flow: validate_payload() parses + checks a raw JSON string
 * (file type, version, schema, relationships, data integrity) and returns
 * a preview summary without writing anything; apply() then takes that
 * SAME already-validated, cached payload (never raw re-trusted request
 * input) and writes it, row by row, through each table's own repository
 * create()/update() — so every normal validation rule, activity-log entry,
 * and business invariant still applies exactly as if the data had been
 * entered by hand. Foreign keys are remapped through an ID map built as
 * parent tables are imported first (projects/milestones before tasks,
 * tasks before subtasks, etc.), so a fresh install importing a full
 * backup ends up with the same relationships, not reused-and-wrong IDs.
 *
 * Duplicate detection has no cross-install stable ID to key off of (every
 * table uses a local auto-increment id), so it uses a natural key per
 * table (e.g. Projects: title; Tasks: project + title) instead. Two
 * tables — Time entries and Finance (Expenses/Revenue) — have no
 * meaningful natural key (many rows can legitimately share every visible
 * field), so those are always inserted as new rows regardless of the
 * chosen duplicate strategy; this is a deliberate, documented scope
 * decision, not an oversight.
 */
class PTP_Import_Service {

	/**
	 * Transient key prefix for a validated-and-cached pending import/restore.
	 *
	 * @var string
	 */
	const TRANSIENT_PREFIX = 'ptp_import_';

	/**
	 * How long a validated preview stays available to confirm.
	 *
	 * @var int
	 */
	const PREVIEW_TTL = 900; // 15 minutes.

	/**
	 * Max accepted upload size (bytes).
	 *
	 * @var int
	 */
	const MAX_BYTES = 20971520; // 20MB.

	/**
	 * Tables with no usable natural key for duplicate detection — always inserted as new.
	 *
	 * @var string[]
	 */
	const NO_DEDUP_TABLES = array( 'time_entries', 'expenses', 'revenues' );

	/**
	 * Import processing order: every table's parents are imported (and
	 * their ID map populated) before it. milestones before tasks because
	 * tasks.milestone_id references milestones.
	 *
	 * @var string[]
	 */
	const TABLE_ORDER = array(
		'projects',
		'milestones',
		'tasks',
		'subtasks',
		'calendar_events',
		'time_entries',
		'notes',
		'project_links',
		'expenses',
		'revenues',
		'prompt_templates',
		'prompt_documents',
		'reminders',
	);

	/**
	 * Validate an uploaded file ($_FILES-shaped array): file type, size,
	 * then delegates to validate_payload() for content checks. Reads the
	 * file via the WordPress filesystem API, never fopen()/file_get_contents().
	 *
	 * @param array $file A single $_FILES entry.
	 * @return array|WP_Error See validate_payload().
	 */
	public static function validate_upload( array $file ) {
		if ( empty( $file['name'] ) || ( isset( $file['error'] ) && UPLOAD_ERR_OK !== $file['error'] ) ) {
			return new WP_Error( 'ptp_upload_error', __( 'No file was uploaded, or the upload failed.', 'personal-project-tracker' ) );
		}

		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'ptp_upload_invalid', __( 'That does not look like a genuine file upload.', 'personal-project-tracker' ) );
		}

		$extension = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );

		if ( 'json' !== $extension ) {
			return new WP_Error( 'ptp_invalid_file_type', __( 'Only .json backup/export files can be imported.', 'personal-project-tracker' ) );
		}

		if ( ( $file['size'] ?? 0 ) > self::MAX_BYTES ) {
			return new WP_Error( 'ptp_file_too_large', __( 'That file is too large to import.', 'personal-project-tracker' ) );
		}

		$fs = PTP_Backup_Service::get_filesystem();
		$raw = $fs ? $fs->get_contents( $file['tmp_name'] ) : false;

		if ( false === $raw ) {
			return new WP_Error( 'ptp_upload_unreadable', __( 'Could not read the uploaded file.', 'personal-project-tracker' ) );
		}

		return self::validate_payload( $raw );
	}

	/**
	 * The testable core: parse + validate a raw JSON string. File type
	 * (JSON well-formedness), version, schema, relationships, and data
	 * integrity are all checked here; nothing is written to the database.
	 *
	 * @param string $raw Raw JSON string.
	 * @return array{token: string, summary: array}|WP_Error
	 */
	public static function validate_payload( $raw ) {
		$decoded = json_decode( (string) $raw, true );

		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'ptp_invalid_json', __( 'That file is not valid JSON.', 'personal-project-tracker' ) );
		}

		if ( 'personal-project-tracker' !== ( $decoded['plugin'] ?? '' ) || ! isset( $decoded['tables'] ) || ! is_array( $decoded['tables'] ) ) {
			return new WP_Error( 'ptp_invalid_schema', __( 'That file is not a Personal Project Tracker backup/export.', 'personal-project-tracker' ) );
		}

		$db_version = isset( $decoded['db_version'] ) ? (int) $decoded['db_version'] : 0;

		if ( $db_version <= 0 ) {
			return new WP_Error( 'ptp_invalid_schema', __( 'That file is missing its schema version.', 'personal-project-tracker' ) );
		}

		if ( $db_version > PTP_DB_VERSION ) {
			return new WP_Error(
				'ptp_version_too_new',
				sprintf(
					/* translators: 1: file's db version, 2: current db version. */
					__( 'This file was exported from a newer version of the plugin (schema v%1$d) than this site is running (schema v%2$d). Update the plugin before importing it.', 'personal-project-tracker' ),
					$db_version,
					PTP_DB_VERSION
				)
			);
		}

		$warnings = array();

		if ( $db_version < PTP_DB_VERSION ) {
			/* translators: %d: the file's schema version. */
			$warnings[] = sprintf( __( 'This file is from an older plugin version (schema v%d). Fields added since then will use their defaults.', 'personal-project-tracker' ), $db_version );
		}

		$reverse_keys = array_flip( PTP_Backup_Service::TABLES );
		$counts       = array();
		$invalid      = array();

		foreach ( $decoded['tables'] as $key => $rows ) {
			if ( ! isset( $reverse_keys[ $key ] ) || ! is_array( $rows ) ) {
				continue;
			}

			$short_table    = $reverse_keys[ $key ];
			$counts[ $key ] = count( $rows );

			$bad = 0;

			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) || ! self::row_has_required_fields( $short_table, $row ) ) {
					$bad++;
				}
			}

			if ( $bad > 0 ) {
				$invalid[ $key ] = $bad;
				/* translators: 1: number of rows, 2: table label. */
				$warnings[] = sprintf( __( '%1$d row(s) in "%2$s" are missing required fields and will be skipped.', 'personal-project-tracker' ), $bad, $key );
			}
		}

		list( $fk_warnings, $fk_invalid ) = self::check_relationships( $decoded['tables'] );
		$warnings                          = array_merge( $warnings, $fk_warnings );

		foreach ( $fk_invalid as $key => $count ) {
			$invalid[ $key ] = ( $invalid[ $key ] ?? 0 ) + $count;
		}

		if ( empty( $counts ) ) {
			return new WP_Error( 'ptp_empty_import', __( 'That file does not contain any recognized data.', 'personal-project-tracker' ) );
		}

		$token   = wp_generate_password( 32, false, false );
		$summary = array(
			'plugin_version' => $decoded['plugin_version'] ?? __( 'unknown', 'personal-project-tracker' ),
			'db_version'     => $db_version,
			'created_at'     => $decoded['created_at'] ?? $decoded['exported_at'] ?? '',
			'counts'         => $counts,
			'invalid'        => $invalid,
			'warnings'       => $warnings,
		);

		// The summary is cached alongside the payload so the preview page
		// never needs to re-validate (and re-generate a throwaway token)
		// just to render itself.
		set_transient(
			self::TRANSIENT_PREFIX . $token,
			array(
				'user_id' => get_current_user_id(),
				'payload' => $decoded,
				'summary' => $summary,
			),
			self::PREVIEW_TTL
		);

		return array(
			'token'   => $token,
			'summary' => $summary,
		);
	}

	/**
	 * @param string $short_table Short table name.
	 * @param array  $row         Raw imported row.
	 * @return bool
	 */
	private static function row_has_required_fields( $short_table, array $row ) {
		$required = array(
			'projects'         => array( 'title' ),
			'tasks'             => array( 'title' ),
			'subtasks'          => array( 'title' ),
			'milestones'        => array( 'title' ),
			'calendar_events'   => array( 'title', 'start_datetime' ),
			'time_entries'      => array( 'entry_date' ),
			'notes'             => array(),
			'project_links'     => array( 'title', 'url' ),
			'expenses'          => array( 'amount', 'expense_date' ),
			'revenues'          => array( 'amount', 'revenue_date' ),
			'prompt_templates'  => array( 'name' ),
			'prompt_documents'  => array( 'goal' ),
			'reminders'         => array( 'title', 'remind_at' ),
		);

		foreach ( $required[ $short_table ] ?? array() as $field ) {
			if ( ! isset( $row[ $field ] ) || '' === trim( (string) $row[ $field ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Foreign-key sanity check: for every FK column, its value must either
	 * match an id already in the database or an id present among the same
	 * import batch's rows for that parent table — otherwise the row would
	 * import as an orphan. This never mutates anything; it only counts
	 * problems for the preview. The row-level import step (import_row())
	 * re-resolves the same relationship for real at write time.
	 *
	 * @param array $tables Decoded payload's 'tables' array, keyed by backup/export key.
	 * @return array{0: string[], 1: array<string,int>} [warnings, invalid-row counts by key]
	 */
	private static function check_relationships( array $tables ) {
		$fk_map = array(
			'milestones'      => array( 'project_id' => 'projects' ),
			'tasks'            => array( 'project_id' => 'projects', 'milestone_id' => 'milestones' ),
			'subtasks'         => array( 'task_id' => 'tasks' ),
			'calendar_events'  => array( 'project_id' => 'projects' ),
			'time_entries'     => array( 'project_id' => 'projects', 'task_id' => 'tasks' ),
			'notes'            => array( 'project_id' => 'projects', 'task_id' => 'tasks', 'milestone_id' => 'milestones' ),
			'project_links'    => array( 'project_id' => 'projects', 'task_id' => 'tasks', 'milestone_id' => 'milestones' ),
			'expenses'         => array( 'project_id' => 'projects' ),
			'revenues'         => array( 'project_id' => 'projects' ),
			'prompt_documents' => array( 'project_id' => 'projects' ),
		);

		$reverse_keys = array_flip( PTP_Backup_Service::TABLES );
		$ids_in_batch = array();

		foreach ( $tables as $key => $rows ) {
			if ( isset( $reverse_keys[ $key ] ) && is_array( $rows ) ) {
				$ids_in_batch[ $reverse_keys[ $key ] ] = array_column( array_filter( $rows, 'is_array' ), 'id' );
			}
		}

		$warnings = array();
		$invalid  = array();

		foreach ( $fk_map as $short_table => $fks ) {
			$key = PTP_Backup_Service::TABLES[ $short_table ];

			if ( empty( $tables[ $key ] ) || ! is_array( $tables[ $key ] ) ) {
				continue;
			}

			$bad = 0;

			foreach ( $tables[ $key ] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				foreach ( $fks as $column => $parent_table ) {
					if ( empty( $row[ $column ] ) ) {
						continue; // Optional FK, not set.
					}

					$fk_id       = (int) $row[ $column ];
					$in_batch    = in_array( $fk_id, $ids_in_batch[ $parent_table ] ?? array(), true );
					$parent_repo = self::repository_for( $parent_table );
					$in_db       = $parent_repo && $parent_repo::exists( $fk_id );

					if ( ! $in_batch && ! $in_db ) {
						$bad++;
						break;
					}
				}
			}

			if ( $bad > 0 ) {
				$invalid[ $key ] = $bad;
				/* translators: 1: number of rows, 2: table label. */
				$warnings[] = sprintf( __( '%1$d row(s) in "%2$s" reference a project/task/milestone that does not exist and will be skipped.', 'personal-project-tracker' ), $bad, $key );
			}
		}

		return array( $warnings, $invalid );
	}

	/**
	 * @param string $short_table Short table name.
	 * @return string|null Repository class name.
	 */
	private static function repository_for( $short_table ) {
		$map = array(
			'projects'         => 'PTP_Projects_Repository',
			'tasks'             => 'PTP_Tasks_Repository',
			'subtasks'          => 'PTP_Subtasks_Repository',
			'milestones'        => 'PTP_Milestones_Repository',
			'calendar_events'   => 'PTP_Calendar_Repository',
			'time_entries'      => 'PTP_Time_Repository',
			'notes'             => 'PTP_Notes_Repository',
			'project_links'     => 'PTP_Links_Repository',
			'expenses'          => 'PTP_Expenses_Repository',
			'revenues'          => 'PTP_Revenue_Repository',
			'prompt_templates'  => 'PTP_Prompt_Templates_Repository',
			'prompt_documents'  => 'PTP_Prompt_Documents_Repository',
			'reminders'         => 'PTP_Reminders_Repository',
		);

		return $map[ $short_table ] ?? null;
	}

	/**
	 * Load a previously-validated preview, scoped to the current user (a
	 * pending import token is never confirmable by a different user).
	 *
	 * @param string $token Preview token.
	 * @return array|WP_Error {'user_id', 'payload'}
	 */
	public static function load_pending( $token ) {
		$pending = get_transient( self::TRANSIENT_PREFIX . sanitize_text_field( $token ) );

		if ( ! is_array( $pending ) || (int) ( $pending['user_id'] ?? 0 ) !== get_current_user_id() ) {
			return new WP_Error( 'ptp_import_expired', __( 'That import preview has expired or is invalid. Please upload the file again.', 'personal-project-tracker' ) );
		}

		return $pending;
	}

	/**
	 * @param string $token Preview token, to invalidate it after use.
	 */
	public static function forget_pending( $token ) {
		delete_transient( self::TRANSIENT_PREFIX . sanitize_text_field( $token ) );
	}

	/**
	 * Apply an already-validated payload — the Import flow. Duplicate
	 * handling per row: Skip Existing (default) leaves the existing row
	 * untouched; Update Existing overwrites it with the imported values;
	 * Create New always inserts a new row even if a match is found.
	 *
	 * @param array  $payload            Decoded, already-validated backup/export payload.
	 * @param string $duplicate_strategy 'skip_existing' (default), 'update_existing', or 'create_new'.
	 * @return array<string, array{created:int, updated:int, skipped:int, invalid:int}>
	 */
	public static function apply_import( array $payload, $duplicate_strategy = 'skip_existing' ) {
		if ( ! in_array( $duplicate_strategy, array( 'skip_existing', 'update_existing', 'create_new' ), true ) ) {
			$duplicate_strategy = 'skip_existing';
		}

		$tables  = $payload['tables'] ?? array();
		$id_maps = array();
		$stats   = array();

		foreach ( self::TABLE_ORDER as $short_table ) {
			$key  = PTP_Backup_Service::TABLES[ $short_table ];
			$rows = is_array( $tables[ $key ] ?? null ) ? $tables[ $key ] : array();

			$stats[ $short_table ] = self::import_rows( $short_table, $rows, $duplicate_strategy, $id_maps );
		}

		PTP_Activity_Log::log( 'data_imported', 'import', 0, __( 'Imported data from a file', 'personal-project-tracker' ) );

		return $stats;
	}

	/**
	 * Restore — same engine as Import, but with the two Restore-specific
	 * modes: Merge (identical semantics to Import's Skip Existing — never
	 * overwrite what is already there) or Replace (wipe every table this
	 * backup covers, then insert its rows fresh). Replace is deliberately
	 * not exposed as a duplicate_strategy on Import — it is a distinct,
	 * destructive, all-or-nothing action gated by its own strong
	 * confirmation in the controller, never a per-row choice.
	 *
	 * @param array  $payload Decoded, already-validated backup payload.
	 * @param string $mode    'merge' (default) or 'replace'.
	 * @return array<string, array{created:int, updated:int, skipped:int, invalid:int}>
	 */
	public static function apply_restore( array $payload, $mode = 'merge' ) {
		if ( 'replace' !== $mode ) {
			$mode = 'merge';
		}

		if ( 'replace' === $mode ) {
			self::truncate_backed_up_tables();
		}

		$stats = self::apply_import( $payload, 'skip_existing' );

		PTP_Activity_Log::log(
			'data_restored',
			'restore',
			0,
			/* translators: %s: 'merge' or 'replace'. */
			sprintf( __( 'Restored from a backup (%s)', 'personal-project-tracker' ), $mode )
		);

		return $stats;
	}

	/**
	 * Delete every row of every table this plugin backs up (see
	 * PTP_Backup_Service::TABLES) — only ever called for Replace restore,
	 * after the controller's strong confirmation has already been checked.
	 * Settings and every other option this plugin owns are left alone;
	 * Replace restores data, it does not reset configuration.
	 */
	private static function truncate_backed_up_tables() {
		global $wpdb;

		foreach ( array_keys( PTP_Backup_Service::TABLES ) as $short_table ) {
			$table = ptp_table( $short_table );
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- fixed, plugin-owned table name from a constant list, not user input.
		}
	}

	/**
	 * @param string $short_table Short table name.
	 * @param array  $rows        Raw imported rows for this table.
	 * @param string $strategy    Duplicate strategy.
	 * @param array  $id_maps     By-reference old-id => new-id maps, keyed by short table name; populated as parents are processed.
	 * @return array{created:int, updated:int, skipped:int, invalid:int}
	 */
	private static function import_rows( $short_table, array $rows, $strategy, array &$id_maps ) {
		$stats = array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'invalid' => 0 );

		if ( ! isset( $id_maps[ $short_table ] ) ) {
			$id_maps[ $short_table ] = array();
		}

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! self::row_has_required_fields( $short_table, $row ) ) {
				$stats['invalid']++;
				continue;
			}

			$result = self::import_row( $short_table, $row, $strategy, $id_maps );

			if ( null === $result ) {
				$stats['invalid']++;
				continue;
			}

			$stats[ $result ]++;
		}

		return $stats;
	}

	/**
	 * Import a single row: remap its foreign keys, find an existing match
	 * (unless this table has no natural key), then create/update/skip per
	 * the chosen strategy — always through the owning repository, never a
	 * raw INSERT, so every normal validation rule still applies.
	 *
	 * @param string $short_table Short table name.
	 * @param array  $row         Raw imported row.
	 * @param string $strategy    Duplicate strategy.
	 * @param array  $id_maps     By-reference id maps.
	 * @return string|null 'created'|'updated'|'skipped', or null if the row was rejected.
	 */
	private static function import_row( $short_table, array $row, $strategy, array &$id_maps ) {
		$old_id = isset( $row['id'] ) ? (int) $row['id'] : 0;
		$raw    = self::adapt_row( $short_table, $row, $id_maps );

		if ( null === $raw ) {
			return null;
		}

		$repository = self::repository_for( $short_table );
		$no_dedup   = in_array( $short_table, self::NO_DEDUP_TABLES, true );
		$match_id   = $no_dedup ? 0 : self::find_match( $short_table, $raw );

		// PTP_Time_Repository has no create()/update() — it exposes
		// create_manual()/update_manual() instead (its live-timer
		// start()/pause()/resume()/stop() own the plain create()/update()
		// naming for running timers). Time entries are also always in
		// NO_DEDUP_TABLES, so $match_id is never set here and the update()
		// branch below is unreachable for this table.
		if ( 'time_entries' === $short_table ) {
			$new_id = PTP_Time_Repository::create_manual( $raw );

			if ( is_wp_error( $new_id ) ) {
				return null;
			}

			if ( $old_id ) {
				$id_maps[ $short_table ][ $old_id ] = $new_id;
			}

			return 'created';
		}

		if ( $match_id && 'create_new' !== $strategy ) {
			if ( 'update_existing' === $strategy ) {
				$repository::update( $match_id, $raw );
			}

			if ( $old_id ) {
				$id_maps[ $short_table ][ $old_id ] = $match_id;
			}

			return $match_id && 'update_existing' === $strategy ? 'updated' : 'skipped';
		}

		$new_id = $repository::create( $raw );

		if ( is_wp_error( $new_id ) ) {
			return null;
		}

		if ( $old_id ) {
			$id_maps[ $short_table ][ $old_id ] = $new_id;
		}

		return 'created';
	}

	/**
	 * Remap an imported row's foreign keys through $id_maps and shape it
	 * into the raw input array each table's repository::create()/update()
	 * expects — every table's repository has its own bespoke raw-field
	 * contract (e.g. Calendar wants start_date+start_time, not the stored
	 * start_datetime column), so this is a genuine per-table adapter, not
	 * a pass-through.
	 *
	 * @param string $short_table Short table name.
	 * @param array  $row         Raw imported row (as stored in the DB/backup).
	 * @param array  $id_maps     Old-id => new-id maps built so far.
	 * @return array|null Raw input for the repository, or null if a required parent is unresolvable.
	 */
	private static function adapt_row( $short_table, array $row, array $id_maps ) {
		$fk = function ( $short_parent, $old_id ) use ( $id_maps ) {
			if ( ! $old_id ) {
				return 0;
			}

			if ( isset( $id_maps[ $short_parent ][ (int) $old_id ] ) ) {
				return $id_maps[ $short_parent ][ (int) $old_id ];
			}

			$repository = self::repository_for( $short_parent );

			return ( $repository && $repository::exists( (int) $old_id ) ) ? (int) $old_id : 0;
		};

		switch ( $short_table ) {
			case 'projects':
				return array(
					'title'             => $row['title'] ?? '',
					'description'       => $row['description'] ?? '',
					'status'            => $row['status'] ?? 'planning',
					'priority'          => $row['priority'] ?? 'medium',
					'start_date'        => $row['start_date'] ?? '',
					'deadline'          => $row['deadline'] ?? '',
					'budget'            => $row['budget'] ?? null,
					'currency'          => $row['currency'] ?? 'USD',
					'estimated_revenue' => $row['estimated_revenue'] ?? null,
					'progress'          => $row['progress'] ?? 0,
					'color'             => $row['color'] ?? '',
				);

			case 'milestones':
				$project_id = $fk( 'projects', $row['project_id'] ?? 0 );

				if ( ! $project_id ) {
					return null;
				}

				return array(
					'title'       => $row['title'] ?? '',
					'description' => $row['description'] ?? '',
					'project_id'  => $project_id,
					'status'      => $row['status'] ?? 'planning',
					'priority'    => $row['priority'] ?? 'medium',
					'start_date'  => $row['start_date'] ?? '',
					'due_date'    => $row['due_date'] ?? '',
					'progress'    => $row['progress'] ?? 0,
				);

			case 'tasks':
				$project_id = $fk( 'projects', $row['project_id'] ?? 0 );

				if ( ! $project_id ) {
					return null;
				}

				return array(
					'title'          => $row['title'] ?? '',
					'description'    => $row['description'] ?? '',
					'project_id'     => $project_id,
					'milestone_id'   => $fk( 'milestones', $row['milestone_id'] ?? 0 ),
					'status'         => $row['status'] ?? 'todo',
					'priority'       => $row['priority'] ?? 'medium',
					'due_date'       => $row['due_date'] ?? '',
					'start_date'     => $row['start_date'] ?? '',
					'estimated_time' => $row['estimated_time'] ?? null,
					'tags'           => $row['tags'] ?? '',
				);

			case 'subtasks':
				$task_id = $fk( 'tasks', $row['task_id'] ?? 0 );

				if ( ! $task_id ) {
					return null;
				}

				return array(
					'task_id'  => $task_id,
					'title'    => $row['title'] ?? '',
					'priority' => $row['priority'] ?? 'medium',
					'due_date' => $row['due_date'] ?? '',
				);

			case 'calendar_events':
				// PTP_Calendar_Repository::sanitize_datetime() wants a bare
				// 'H:i' time, but the stored column is a full
				// 'Y-m-d H:i:s' datetime — split on the space, then trim the
				// time part to H:i (dropping seconds), same fix as time_entries above.
				$parts = explode( ' ', (string) ( $row['start_datetime'] ?? '' ), 2 );
				$ends  = ! empty( $row['end_datetime'] ) ? explode( ' ', (string) $row['end_datetime'], 2 ) : array( '', '' );

				return array(
					'title'            => $row['title'] ?? '',
					'description'      => $row['description'] ?? '',
					'all_day'          => ! empty( $row['all_day'] ),
					'start_date'       => $parts[0] ?? '',
					'start_time'       => substr( (string) ( $parts[1] ?? '' ), 0, 5 ),
					'end_date'         => $ends[0] ?? '',
					'end_time'         => substr( (string) ( $ends[1] ?? '' ), 0, 5 ),
					'project_id'       => $fk( 'projects', $row['project_id'] ?? 0 ),
					'task_id'          => $fk( 'tasks', $row['task_id'] ?? 0 ),
					'milestone_id'     => $fk( 'milestones', $row['milestone_id'] ?? 0 ),
					'location'         => $row['location'] ?? '',
					'color'            => $row['color'] ?? '',
					'reminder_minutes' => $row['reminder_minutes'] ?? '',
				);

			case 'time_entries':
				// Deliberately duration-only: the stored start_time/end_time
				// columns are full 'Y-m-d H:i:s' datetimes, but
				// PTP_Time_Repository::prepare_manual_fields() expects a bare
				// 'H:i' time-of-day combined with entry_date — reusing the
				// stored duration (converted seconds -> minutes) sidesteps
				// that mismatch entirely and is well-supported on its own.
				return array(
					'entry_date'  => $row['entry_date'] ?? '',
					'description' => $row['description'] ?? '',
					'project_id'  => $fk( 'projects', $row['project_id'] ?? 0 ),
					'task_id'     => $fk( 'tasks', $row['task_id'] ?? 0 ),
					'duration'    => isset( $row['duration'] ) ? (int) round( ( (int) $row['duration'] ) / 60 ) : 0,
				);

			case 'notes':
				return array(
					'title'        => $row['title'] ?? '',
					'content'      => $row['content'] ?? '',
					'project_id'   => $fk( 'projects', $row['project_id'] ?? 0 ),
					'task_id'      => $fk( 'tasks', $row['task_id'] ?? 0 ),
					'milestone_id' => $fk( 'milestones', $row['milestone_id'] ?? 0 ),
					'tags'         => $row['tags'] ?? '',
				);

			case 'project_links':
				return array(
					'title'        => $row['title'] ?? '',
					'url'          => $row['url'] ?? '',
					'description'  => $row['description'] ?? '',
					'category'     => $row['category'] ?? '',
					'project_id'   => $fk( 'projects', $row['project_id'] ?? 0 ),
					'task_id'      => $fk( 'tasks', $row['task_id'] ?? 0 ),
					'milestone_id' => $fk( 'milestones', $row['milestone_id'] ?? 0 ),
				);

			case 'expenses':
			case 'revenues':
				$date_field = 'expenses' === $short_table ? 'expense_date' : 'revenue_date';

				return array(
					'project_id'  => $fk( 'projects', $row['project_id'] ?? 0 ),
					'amount'      => $row['amount'] ?? 0,
					'currency'    => $row['currency'] ?? 'USD',
					'category'    => $row['category'] ?? '',
					'description' => $row['description'] ?? '',
					$date_field   => $row[ $date_field ] ?? '',
				);

			case 'prompt_templates':
				$template = is_array( $row['template'] ?? null ) ? $row['template'] : json_decode( (string) ( $row['template'] ?? '' ), true );
				$template = is_array( $template ) ? $template : array();

				return array_merge(
					array(
						'name'     => $row['name'] ?? '',
						'category' => $row['category'] ?? '',
					),
					$template
				);

			case 'prompt_documents':
				$config = is_array( $row['config'] ?? null ) ? $row['config'] : json_decode( (string) ( $row['config'] ?? '' ), true );
				$config = is_array( $config ) ? $config : array();

				return array(
					'title'         => $row['title'] ?? '',
					'goal'          => $row['goal'] ?? '',
					'content'       => $row['content'] ?? '',
					'context_type'  => $row['context_type'] ?? 'custom',
					'role'          => $row['role'] ?? 'custom',
					'output_format' => $row['output_format'] ?? 'plain_text',
					'project_id'    => $fk( 'projects', $row['project_id'] ?? 0 ),
					'config'        => $config,
				);

			case 'reminders':
				$related_type = $row['related_type'] ?? '';
				$related_id   = 0;

				if ( $related_type && ! empty( $row['related_id'] ) ) {
					$parent_map = array( 'project' => 'projects', 'task' => 'tasks', 'milestone' => 'milestones', 'calendar_event' => 'calendar_events' );
					$related_id = isset( $parent_map[ $related_type ] ) ? $fk( $parent_map[ $related_type ], $row['related_id'] ) : 0;
				}

				return array(
					'title'               => $row['title'] ?? '',
					'related_type'        => $related_id ? $related_type : '',
					'related_id'          => $related_id,
					'remind_at'           => $row['remind_at'] ?? '',
					'recurrence'          => $row['recurrence'] ?? 'none',
					'recurrence_interval' => $row['recurrence_interval'] ?? 0,
				);

			default:
				return null;
		}
	}

	/**
	 * Natural-key duplicate lookup among rows already in the database.
	 *
	 * @param string $short_table Short table name.
	 * @param array  $raw         Adapted raw row (post-FK-remap).
	 * @return int Existing row ID, or 0 if no match.
	 */
	private static function find_match( $short_table, array $raw ) {
		$signatures = array(
			'projects'         => array( 'title' ),
			'milestones'        => array( 'project_id', 'title' ),
			'tasks'             => array( 'project_id', 'title' ),
			'subtasks'          => array( 'task_id', 'title' ),
			'calendar_events'   => array( 'title' ),
			'notes'             => array( 'project_id', 'title' ),
			'project_links'     => array( 'project_id', 'url' ),
			'prompt_templates'  => array( 'name' ),
			'prompt_documents'  => array( 'project_id', 'title' ),
			'reminders'         => array( 'title', 'remind_at' ),
		);

		if ( ! isset( $signatures[ $short_table ] ) ) {
			return 0;
		}

		$filters = array();

		foreach ( $signatures[ $short_table ] as $field ) {
			if ( 'name' === $field ) {
				$filters['name'] = (string) ( $raw['name'] ?? '' );
			} elseif ( in_array( $field, array( 'project_id', 'task_id' ), true ) ) {
				if ( ! empty( $raw[ $field ] ) ) {
					$filters[ $field ] = (int) $raw[ $field ];
				}
			} elseif ( 'url' === $field ) {
				$filters['url'] = (string) ( $raw['url'] ?? '' );
			} elseif ( 'remind_at' === $field ) {
				$filters['remind_at'] = (string) ( $raw['remind_at'] ?? '' );
			} else {
				$filters['title'] = (string) ( $raw['title'] ?? '' );
			}
		}

		foreach ( PTP_Backup_Service::get_table_rows( $short_table, $filters, 5 ) as $candidate ) {
			return (int) $candidate['id'];
		}

		return 0;
	}
}
