<?php
/**
 * Revenue data access layer.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Revenue_Repository
 *
 * Owns all reads/writes to the revenues table. Mirrors
 * PTP_Expenses_Repository's shape exactly. CRUD only — every calculation
 * that uses revenue totals (profit, profit margin, reports) lives in
 * PTP_Finance_Service, which calls get_totals_by_currency() here rather
 * than re-summing rows itself, so the arithmetic exists in exactly one place.
 */
class PTP_Revenue_Repository {

	/**
	 * Columns that may be used to sort the revenue list.
	 *
	 * @var string[]
	 */
	private static $sortable_columns = array(
		'revenue_date',
		'amount',
		'category',
		'created_at',
	);

	/**
	 * Get the fully-prefixed revenues table name.
	 *
	 * @return string
	 */
	public static function get_table() {
		return ptp_table( 'revenues' );
	}

	/**
	 * @param string $value Raw currency code.
	 * @return string Normalized 3-10 letter uppercase code, or 'USD'.
	 */
	private static function sanitize_currency( $value ) {
		$currency = strtoupper( preg_replace( '/[^A-Za-z]/', '', wp_unslash( (string) $value ) ) );

		return $currency ? substr( $currency, 0, 10 ) : 'USD';
	}

	/**
	 * @param string $value Raw date string.
	 * @return string|null 'Y-m-d' formatted date, or null when empty/invalid.
	 */
	private static function sanitize_date( $value ) {
		$value = trim( wp_unslash( (string) $value ) );

		if ( '' === $value ) {
			return null;
		}

		$parsed = DateTime::createFromFormat( 'Y-m-d', $value );

		if ( ! $parsed || $parsed->format( 'Y-m-d' ) !== $value ) {
			return null;
		}

		return $value;
	}

	/**
	 * Sanitize and validate raw input into a safe, column-ready array. The
	 * only place revenue input is validated — both the admin form
	 * controller and the REST controller call this.
	 *
	 * @param array $raw Raw input, keyed by field name.
	 * @return array|WP_Error
	 */
	public static function prepare_fields( array $raw ) {
		$errors = new WP_Error();
		$data   = array();

		$project_id = isset( $raw['project_id'] ) ? absint( $raw['project_id'] ) : 0;
		$project    = null;

		if ( $project_id ) {
			$project = PTP_Projects_Repository::get( $project_id );

			if ( ! $project ) {
				$errors->add( 'project_invalid', __( 'The selected project does not exist.', 'personal-project-tracker' ) );
				$project_id = 0;
			}
		}

		$data['project_id'] = $project_id ? $project_id : null;

		$amount = isset( $raw['amount'] ) ? (float) $raw['amount'] : -1;

		if ( ! is_numeric( $raw['amount'] ?? null ) || $amount < 0 ) {
			$errors->add( 'amount_invalid', __( 'Amount must be zero or a positive number.', 'personal-project-tracker' ) );
			$amount = 0;
		}

		$data['amount'] = round( $amount, 2 );

		$default_currency = $project ? $project->currency : PTP_Settings::get( 'default_currency', 'USD' );
		$currency          = isset( $raw['currency'] ) && '' !== trim( (string) $raw['currency'] ) ? self::sanitize_currency( $raw['currency'] ) : self::sanitize_currency( $default_currency );
		$data['currency']  = $currency;

		$category          = isset( $raw['category'] ) ? PTP_Security::sanitize_text( $raw['category'] ) : '';
		$data['category']  = substr( $category, 0, 100 );

		$description          = isset( $raw['description'] ) ? PTP_Security::sanitize_text( $raw['description'] ) : '';
		$data['description']  = substr( $description, 0, 500 );

		$revenue_date = self::sanitize_date( $raw['revenue_date'] ?? '' );

		if ( ! $revenue_date ) {
			$errors->add( 'date_required', __( 'A valid revenue date is required.', 'personal-project-tracker' ) );
		}

		$data['revenue_date'] = $revenue_date;

		if ( $errors->has_errors() ) {
			return $errors;
		}

		return $data;
	}

	/**
	 * @param array $data Sanitized data.
	 * @return string[]
	 */
	private static function formats_for( array $data ) {
		$map = array(
			'project_id'   => '%d',
			'amount'       => '%f',
			'currency'     => '%s',
			'category'     => '%s',
			'description'  => '%s',
			'revenue_date' => '%s',
			'created_at'   => '%s',
			'updated_at'   => '%s',
		);

		$formats = array();

		foreach ( array_keys( $data ) as $key ) {
			$formats[] = $map[ $key ] ?? '%s';
		}

		return $formats;
	}

	/**
	 * @param int $id Revenue ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = self::get_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? $row : null;
	}

	/**
	 * @param int $id Revenue ID.
	 * @return bool
	 */
	public static function exists( $id ) {
		return null !== self::get( $id );
	}

	/**
	 * @param array $raw Raw input.
	 * @return int|WP_Error
	 */
	public static function create( array $raw ) {
		$data = self::prepare_fields( $raw );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		global $wpdb;

		$now                = ptp_now();
		$data['created_at'] = $now;
		$data['updated_at'] = $now;

		$inserted = $wpdb->insert( self::get_table(), $data, self::formats_for( $data ) );

		if ( false === $inserted ) {
			ptp_log_error( 'Failed to insert revenue: ' . $wpdb->last_error );

			return new WP_Error( 'ptp_db_error', __( 'Could not save the revenue. Please try again.', 'personal-project-tracker' ) );
		}

		$id = (int) $wpdb->insert_id;

		PTP_Activity_Log::log(
			'revenue_created',
			'revenue',
			$id,
			/* translators: %s: formatted amount. */
			sprintf( __( 'Logged revenue of %s', 'personal-project-tracker' ), ptp_format_currency( $data['amount'], $data['currency'] ) )
		);

		return $id;
	}

	/**
	 * @param int   $id  Revenue ID.
	 * @param array $raw Raw input.
	 * @return true|WP_Error
	 */
	public static function update( $id, array $raw ) {
		$id       = (int) $id;
		$existing = self::get( $id );

		if ( ! $existing ) {
			return new WP_Error( 'ptp_not_found', __( 'Revenue not found.', 'personal-project-tracker' ) );
		}

		$data = self::prepare_fields( $raw );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		global $wpdb;

		$data['updated_at'] = ptp_now();

		$updated = $wpdb->update( self::get_table(), $data, array( 'id' => $id ), self::formats_for( $data ), array( '%d' ) );

		if ( false === $updated ) {
			ptp_log_error( 'Failed to update revenue ' . $id . ': ' . $wpdb->last_error );

			return new WP_Error( 'ptp_db_error', __( 'Could not update the revenue. Please try again.', 'personal-project-tracker' ) );
		}

		PTP_Activity_Log::log(
			'revenue_updated',
			'revenue',
			$id,
			/* translators: %s: formatted amount. */
			sprintf( __( 'Updated revenue (%s)', 'personal-project-tracker' ), ptp_format_currency( $data['amount'], $data['currency'] ) )
		);

		return true;
	}

	/**
	 * @param int $id Revenue ID.
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		$id       = (int) $id;
		$existing = self::get( $id );

		if ( ! $existing ) {
			return new WP_Error( 'ptp_not_found', __( 'Revenue not found.', 'personal-project-tracker' ) );
		}

		global $wpdb;

		$deleted = $wpdb->delete( self::get_table(), array( 'id' => $id ), array( '%d' ) );

		if ( ! $deleted ) {
			ptp_log_error( 'Failed to delete revenue ' . $id . ': ' . $wpdb->last_error );

			return new WP_Error( 'ptp_db_error', __( 'Could not delete the revenue. Please try again.', 'personal-project-tracker' ) );
		}

		PTP_Activity_Log::log(
			'revenue_deleted',
			'revenue',
			$id,
			/* translators: %s: formatted amount. */
			sprintf( __( 'Deleted revenue (%s)', 'personal-project-tracker' ), ptp_format_currency( $existing->amount, $existing->currency ) )
		);

		return true;
	}

	/**
	 * Get a filtered, sorted, paginated list of revenue entries.
	 *
	 * @param array $args {
	 *     @type string $search     Free-text search against description/category.
	 *     @type int    $project_id Project filter.
	 *     @type string $category   Exact category filter.
	 *     @type string $date_from  'Y-m-d' revenue_date range start (inclusive).
	 *     @type string $date_to    'Y-m-d' revenue_date range end (inclusive).
	 *     @type string $orderby    Column to sort by.
	 *     @type string $order      'ASC' or 'DESC'.
	 *     @type int    $paged      1-indexed page number.
	 *     @type int    $per_page   Results per page (max 100).
	 * }
	 * @return array{items: object[], total: int, total_pages: int, page: int, per_page: int}
	 */
	public static function get_list( array $args = array() ) {
		global $wpdb;

		$table = self::get_table();

		$args = wp_parse_args(
			$args,
			array(
				'search'     => '',
				'project_id' => 0,
				'category'   => '',
				'date_from'  => '',
				'date_to'    => '',
				'orderby'    => 'revenue_date',
				'order'      => 'DESC',
				'paged'      => 1,
				'per_page'   => 20,
			)
		);

		list( $where, $params ) = self::build_where( $args );

		$orderby = in_array( $args['orderby'], self::$sortable_columns, true ) ? $args['orderby'] : 'revenue_date';
		$order   = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';

		$per_page = max( 1, min( 100, (int) $args['per_page'] ) );
		$paged    = max( 1, (int) $args['paged'] );
		$offset   = ( $paged - 1 ) * $per_page;

		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total     = $params
			? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$list_sql    = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$list_params = array_merge( $params, array( $per_page, $offset ) );
		$items       = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'items'       => $items,
			'total'       => $total,
			'total_pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 0,
			'page'        => $paged,
			'per_page'    => $per_page,
		);
	}

	/**
	 * Sum revenue amounts grouped by currency — never combined across
	 * currencies. The one place revenue totals are aggregated;
	 * PTP_Finance_Service builds every calculation on top of this.
	 *
	 * @param int   $project_id 0 for all projects.
	 * @param array $args       Optional date_from/date_to/category filters (same shape as get_list()).
	 * @return array<string, float> Currency code => total amount.
	 */
	public static function get_totals_by_currency( $project_id = 0, array $args = array() ) {
		global $wpdb;

		$table = self::get_table();
		$args  = wp_parse_args(
			$args,
			array(
				'project_id' => $project_id,
				'category'   => '',
				'date_from'  => '',
				'date_to'    => '',
			)
		);
		$args['project_id'] = $project_id ? $project_id : $args['project_id'];

		list( $where, $params ) = self::build_where( $args );

		$where_sql = implode( ' AND ', $where );
		$sql       = "SELECT currency, SUM(amount) as total FROM {$table} WHERE {$where_sql} GROUP BY currency"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$rows = $params
			? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$totals = array();

		foreach ( $rows as $row ) {
			$totals[ $row['currency'] ] = (float) $row['total'];
		}

		return $totals;
	}

	/**
	 * Shared WHERE-clause builder for get_list() and get_totals_by_currency().
	 *
	 * @param array $args search/project_id/category/date_from/date_to.
	 * @return array{0: string[], 1: array} [where fragments, bound params]
	 */
	private static function build_where( array $args ) {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();

		$search = trim( (string) ( $args['search'] ?? '' ) );

		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(description LIKE %s OR category LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		$project_id = (int) ( $args['project_id'] ?? 0 );

		if ( $project_id > 0 ) {
			$where[]  = 'project_id = %d';
			$params[] = $project_id;
		}

		$category = trim( (string) ( $args['category'] ?? '' ) );

		if ( '' !== $category ) {
			$where[]  = 'category = %s';
			$params[] = $category;
		}

		$date_from = self::sanitize_date( $args['date_from'] ?? '' );

		if ( $date_from ) {
			$where[]  = 'revenue_date >= %s';
			$params[] = $date_from;
		}

		$date_to = self::sanitize_date( $args['date_to'] ?? '' );

		if ( $date_to ) {
			$where[]  = 'revenue_date <= %s';
			$params[] = $date_to;
		}

		return array( $where, $params );
	}
}
