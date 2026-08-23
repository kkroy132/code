<?php
/**
 * Data export: JSON (any scope) and CSV (six specific modules).
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Export_Service
 *
 * Builds export output on top of PTP_Backup_Service's table-reading
 * primitives (get_table_rows() and the scope collectors) — export and
 * backup share exactly one "read plugin data" implementation, never two.
 * CSV rendering itself reuses PTP_Reports_Export::to_csv() (Phase 9)
 * rather than a second CSV writer.
 */
class PTP_Export_Service {

	/**
	 * CSV export module => short DB table name. Exactly the six modules
	 * this phase's spec calls out for CSV.
	 *
	 * @var array<string, string>
	 */
	const MODULE_TABLES = array(
		'projects'   => 'projects',
		'tasks'       => 'tasks',
		'time'        => 'time_entries',
		'expenses'    => 'expenses',
		'revenue'     => 'revenues',
		'milestones'  => 'milestones',
	);

	/**
	 * Column order for each CSV module.
	 *
	 * @var array<string, string[]>
	 */
	const CSV_COLUMNS = array(
		'projects'   => array( 'id', 'title', 'status', 'priority', 'start_date', 'deadline', 'budget', 'currency', 'progress', 'created_at' ),
		'tasks'       => array( 'id', 'project_id', 'milestone_id', 'title', 'status', 'priority', 'due_date', 'estimated_time', 'created_at' ),
		'time'        => array( 'id', 'project_id', 'task_id', 'description', 'entry_date', 'duration', 'status' ),
		'expenses'    => array( 'id', 'project_id', 'amount', 'currency', 'category', 'expense_date' ),
		'revenue'     => array( 'id', 'project_id', 'amount', 'currency', 'category', 'revenue_date' ),
		'milestones'  => array( 'id', 'project_id', 'title', 'status', 'priority', 'due_date', 'progress', 'created_at' ),
	);

	/**
	 * Allowed scope values.
	 *
	 * @var string[]
	 */
	const SCOPES = array( 'all', 'project', 'date_range', 'module' );

	/**
	 * Export JSON for the given scope.
	 *
	 * @param array $args {
	 *     @type string $scope      'all', 'project', 'date_range', or 'module'.
	 *     @type int    $project_id Required when scope is 'project'.
	 *     @type string $date_from  'Y-m-d', used when scope is 'date_range'.
	 *     @type string $date_to    'Y-m-d', used when scope is 'date_range'.
	 *     @type string $module     Short table name (a PTP_Backup_Service::TABLES key), used when scope is 'module'.
	 * }
	 * @return array{data: array, count: int}|WP_Error
	 */
	public static function export_json( array $args ) {
		$scope = in_array( $args['scope'] ?? '', self::SCOPES, true ) ? $args['scope'] : 'all';

		switch ( $scope ) {
			case 'project':
				$project_id = (int) ( $args['project_id'] ?? 0 );

				if ( ! $project_id || ! PTP_Projects_Repository::exists( $project_id ) ) {
					return new WP_Error( 'ptp_project_required', __( 'A valid project is required for a Current Project export.', 'personal-project-tracker' ) );
				}

				$data = PTP_Backup_Service::collect_project_scoped( $project_id );
				break;

			case 'date_range':
				$data = PTP_Backup_Service::collect_date_range_scoped(
					self::sanitize_date( $args['date_from'] ?? '' ),
					self::sanitize_date( $args['date_to'] ?? '' )
				);
				break;

			case 'module':
				$module = isset( $args['module'] ) ? sanitize_key( $args['module'] ) : '';

				if ( ! isset( PTP_Backup_Service::TABLES[ $module ] ) ) {
					return new WP_Error( 'ptp_invalid_module', __( 'Unknown export module.', 'personal-project-tracker' ) );
				}

				$data = array( PTP_Backup_Service::TABLES[ $module ] => PTP_Backup_Service::collect_module_scoped( $module ) );
				break;

			default:
				$data = PTP_Backup_Service::collect_all_data();
				break;
		}

		$count = array_sum( array_map( 'count', $data ) );

		return array(
			'data'  => array(
				'plugin'         => 'personal-project-tracker',
				'plugin_version' => PTP_VERSION,
				'db_version'     => PTP_DB_VERSION,
				'exported_at'    => ptp_now(),
				'scope'          => $scope,
				'tables'         => $data,
			),
			'count' => $count,
		);
	}

	/**
	 * Export one module as CSV.
	 *
	 * @param string $module Key of self::MODULE_TABLES.
	 * @param array  $args   Same shape as export_json()'s $args (scope/project_id/date_from/date_to);
	 *                       'module' scope is implicit (the $module argument itself).
	 * @return string|WP_Error CSV content.
	 */
	public static function export_csv( $module, array $args ) {
		if ( ! isset( self::MODULE_TABLES[ $module ] ) ) {
			return new WP_Error( 'ptp_invalid_module', __( 'Unknown export module.', 'personal-project-tracker' ) );
		}

		$short_table = self::MODULE_TABLES[ $module ];
		$scope       = in_array( $args['scope'] ?? '', self::SCOPES, true ) ? $args['scope'] : 'all';

		if ( 'project' === $scope ) {
			$project_id = (int) ( $args['project_id'] ?? 0 );

			if ( ! $project_id || ! PTP_Projects_Repository::exists( $project_id ) ) {
				return new WP_Error( 'ptp_project_required', __( 'A valid project is required for a Current Project export.', 'personal-project-tracker' ) );
			}

			$column = 'projects' === $short_table ? 'id' : 'project_id';
			$rows   = PTP_Backup_Service::get_table_rows( $short_table, array( $column => $project_id ) );
		} elseif ( 'date_range' === $scope ) {
			$date_from = self::sanitize_date( $args['date_from'] ?? '' );
			$date_to   = self::sanitize_date( $args['date_to'] ?? '' );
			$column    = PTP_Backup_Service::DATE_COLUMNS[ $short_table ];
			$filters   = $date_from ? array( $column => array( 'op' => '>=', 'value' => $date_from . ' 00:00:00' ) ) : array();
			$rows      = PTP_Backup_Service::get_table_rows( $short_table, $filters );

			if ( $date_to ) {
				$rows = array_values( array_filter( $rows, fn( $row ) => ( $row[ $column ] ?? '' ) <= $date_to . ' 23:59:59' ) );
			}
		} else {
			$rows = PTP_Backup_Service::get_table_rows( $short_table );
		}

		return PTP_Reports_Export::to_csv( $rows, self::CSV_COLUMNS[ $module ] );
	}

	/**
	 * @param string $value Raw date string.
	 * @return string '' if empty/invalid, otherwise the validated 'Y-m-d' date.
	 */
	private static function sanitize_date( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		$parsed = DateTime::createFromFormat( 'Y-m-d', $value );

		return ( $parsed && $parsed->format( 'Y-m-d' ) === $value ) ? $value : '';
	}
}
