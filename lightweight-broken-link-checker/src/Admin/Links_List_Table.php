<?php
/**
 * List table for discovered links.
 *
 * @package LWBLC
 */

namespace LWBLC\Admin;

use LWBLC\Database;
use WP_List_Table;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Renders stored links in the standard admin table.
 */
class Links_List_Table extends WP_List_Table {

	/**
	 * Status filter for the current request.
	 *
	 * @var string
	 */
	private $filter = 'all';

	/**
	 * Row counts per status.
	 *
	 * @var array<string,int>
	 */
	private $counts = array();

	/**
	 * Sets up the table labels.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'lwblc_link',
				'plural'   => 'lwblc_links',
				'ajax'     => false,
				'screen'   => 'lwblc-links',
			)
		);
	}

	/**
	 * Defines the table columns.
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'link_url'     => __( 'URL', 'lwblc' ),
			'source'       => __( 'Source', 'lwblc' ),
			'status'       => __( 'Status', 'lwblc' ),
			'http_code'    => __( 'HTTP code', 'lwblc' ),
			'last_checked' => __( 'Last checked', 'lwblc' ),
		);
	}

	/**
	 * Columns the user can sort by.
	 *
	 * @return array<string,array>
	 */
	protected function get_sortable_columns() {
		return array(
			'link_url'     => array( 'link_url', false ),
			'source'       => array( 'source_post_title', false ),
			'status'       => array( 'status', false ),
			'http_code'    => array( 'http_code', false ),
			'last_checked' => array( 'last_checked_at', false ),
		);
	}

	/**
	 * Column used as the primary (mobile) column.
	 *
	 * @return string
	 */
	protected function get_default_primary_column_name() {
		return 'link_url';
	}

	/**
	 * The status filter links above the table.
	 *
	 * @return array<string,string>
	 */
	protected function get_views() {
		$counts = $this->get_counts();
		$base   = admin_url( 'admin.php?page=' . Admin::PAGE_SLUG );

		$labels = array(
			'all'                     => __( 'All', 'lwblc' ),
			Database::STATUS_BROKEN   => __( 'Broken', 'lwblc' ),
			Database::STATUS_REDIRECT => __( 'Redirect', 'lwblc' ),
			Database::STATUS_PENDING  => __( 'Pending', 'lwblc' ),
			Database::STATUS_OK       => __( 'OK', 'lwblc' ),
		);

		$views = array();

		foreach ( $labels as $key => $label ) {
			$count = 'all' === $key ? array_sum( $counts ) : ( isset( $counts[ $key ] ) ? $counts[ $key ] : 0 );
			$url   = 'all' === $key ? $base : add_query_arg( array( 'status' => $key ), $base );

			$views[ $key ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( $url ),
				$this->filter === $key ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( $count ) )
			);
		}

		return $views;
	}

	/**
	 * Row counts per status, loaded once per request.
	 *
	 * @return array<string,int>
	 */
	private function get_counts() {
		if ( empty( $this->counts ) ) {
			$this->counts = Database::status_counts();
		}

		return $this->counts;
	}

	/**
	 * Reads and validates the requested status filter.
	 *
	 * @return string `all` or a valid status.
	 */
	private function read_filter() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read only filter on an admin screen.
		$requested = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all';

		return in_array( $requested, Database::statuses(), true ) ? $requested : 'all';
	}

	/**
	 * Reads and validates the search term.
	 *
	 * @return string
	 */
	private function read_search() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read only filter on an admin screen.
		return isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
	}

	/**
	 * Reads and validates the sort column and direction.
	 *
	 * @return array{orderby:string,order:string}
	 */
	private function read_order() {
		$allowed = array( 'link_url', 'source_post_title', 'status', 'http_code', 'last_checked_at', 'post_modified_date', 'id' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read only sorting on an admin screen.
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read only sorting on an admin screen.
		$order = isset( $_GET['order'] ) ? strtoupper( sanitize_key( wp_unslash( $_GET['order'] ) ) ) : '';

		return array(
			'orderby' => in_array( $orderby, $allowed, true ) ? $orderby : 'id',
			'order'   => 'ASC' === $order ? 'ASC' : 'DESC',
		);
	}

	/**
	 * Loads the rows to display.
	 *
	 * @return void
	 */
	public function prepare_items() {
		global $wpdb;

		$this->filter          = $this->read_filter();
		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			$this->get_sortable_columns(),
			$this->get_default_primary_column_name(),
		);

		$per_page = $this->get_per_page();
		$page     = max( 1, (int) $this->get_pagenum() );
		$offset   = ( $page - 1 ) * $per_page;

		if ( ! Database::table_exists() ) {
			$this->items = array();
			$this->set_pagination_args(
				array(
					'total_items' => 0,
					'per_page'    => $per_page,
					'total_pages' => 0,
				)
			);
			return;
		}

		$table  = Database::table();
		$search = $this->read_search();
		$sort   = $this->read_order();

		$where  = array( '1=1' );
		$params = array();

		if ( 'all' !== $this->filter ) {
			$where[]  = 'status = %s';
			$params[] = $this->filter;
		}

		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '( link_url LIKE %s OR link_text LIKE %s OR source_post_title LIKE %s )';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = implode( ' AND ', $where );

		// $where_sql, $sort and $table are built from validated, non-user values.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders only; values are passed to prepare().
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";

		if ( ! empty( $params ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared right here.
			$count_sql = $wpdb->prepare( $count_sql, $params );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Query prepared above.
		$total_items = (int) $wpdb->get_var( $count_sql );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Sort column and direction are whitelisted in read_order().
		$rows_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$sort['orderby']} {$sort['order']} LIMIT %d OFFSET %d";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared right here.
		$rows_sql = $wpdb->prepare( $rows_sql, array_merge( $params, array( $per_page, $offset ) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Query prepared above.
		$items = $wpdb->get_results( $rows_sql, ARRAY_A );

		$this->items = is_array( $items ) ? $items : array();

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total_items / $per_page ),
			)
		);
	}

	/**
	 * Rows per page, honouring the screen option.
	 *
	 * @return int
	 */
	protected function get_per_page() {
		$per_page = (int) get_user_option( 'lwblc_links_per_page' );

		if ( $per_page < 1 ) {
			$per_page = 20;
		}

		return min( $per_page, 200 );
	}

	/**
	 * Renders the URL column plus the row actions.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_link_url( $item ) {
		$url = (string) $item['link_url'];

		$title = '' !== trim( (string) $item['link_text'] )
			? $item['link_text']
			: __( '(no anchor text)', 'lwblc' );

		$actions = array(
			'recheck' => sprintf(
				'<a href="#" class="lwblc-recheck" data-link-id="%1$d">%2$s</a>',
				(int) $item['id'],
				esc_html__( 'Recheck now', 'lwblc' )
			),
			'visit'   => sprintf(
				'<a href="%1$s" target="_blank" rel="noopener noreferrer nofollow">%2$s</a>',
				esc_url( $url ),
				esc_html__( 'Open link', 'lwblc' )
			),
		);

		$edit_link = get_edit_post_link( (int) $item['source_post_id'] );

		if ( $edit_link ) {
			$actions['edit'] = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $edit_link ),
				esc_html__( 'Edit source post', 'lwblc' )
			);
		}

		return sprintf(
			'<strong class="lwblc-url"><a href="%1$s" target="_blank" rel="noopener noreferrer nofollow">%2$s</a></strong><div class="lwblc-anchor-text">%3$s</div>%4$s',
			esc_url( $url ),
			esc_html( $url ),
			esc_html( $title ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * Renders the source post column.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_source( $item ) {
		$post_id = (int) $item['source_post_id'];

		$title = '' !== trim( (string) $item['source_post_title'] )
			? (string) $item['source_post_title']
			/* translators: %d: post ID. */
			: sprintf( __( 'Post #%d', 'lwblc' ), $post_id );

		$edit_link = get_edit_post_link( $post_id );

		$output = $edit_link
			? sprintf( '<a href="%1$s">%2$s</a>', esc_url( $edit_link ), esc_html( $title ) )
			: esc_html( $title );

		if ( ! empty( $item['post_modified_date'] ) ) {
			$output .= sprintf(
				'<div class="lwblc-muted">%s</div>',
				esc_html(
					sprintf(
						/* translators: %s: formatted date. */
						__( 'Modified %s', 'lwblc' ),
						$this->format_date( (string) $item['post_modified_date'] )
					)
				)
			);
		}

		return $output;
	}

	/**
	 * Renders the status badge.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_status( $item ) {
		$status = Database::sanitize_status( $item['status'] );

		$labels = array(
			Database::STATUS_OK       => __( 'OK', 'lwblc' ),
			Database::STATUS_BROKEN   => __( 'Broken', 'lwblc' ),
			Database::STATUS_REDIRECT => __( 'Redirect', 'lwblc' ),
			Database::STATUS_PENDING  => __( 'Pending', 'lwblc' ),
		);

		$badge = sprintf(
			'<span class="lwblc-badge lwblc-badge-%1$s">%2$s</span>',
			esc_attr( $status ),
			esc_html( isset( $labels[ $status ] ) ? $labels[ $status ] : $status )
		);

		$fail_count = (int) $item['fail_count'];

		if ( $fail_count > 0 && Database::STATUS_BROKEN !== $status ) {
			$badge .= sprintf(
				'<div class="lwblc-muted">%s</div>',
				esc_html(
					sprintf(
						/* translators: %d: number of consecutive failures. */
						_n( '%d failed attempt', '%d failed attempts', $fail_count, 'lwblc' ),
						$fail_count
					)
				)
			);
		}

		return $badge;
	}

	/**
	 * Renders the HTTP code column.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_http_code( $item ) {
		$code = (int) $item['http_code'];

		if ( $code < 1 ) {
			return '<span class="lwblc-muted">' . esc_html__( 'n/a', 'lwblc' ) . '</span>';
		}

		return esc_html( (string) $code );
	}

	/**
	 * Renders the last checked column.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_last_checked( $item ) {
		if ( empty( $item['last_checked_at'] ) ) {
			return '<span class="lwblc-muted">' . esc_html__( 'Never', 'lwblc' ) . '</span>';
		}

		$timestamp = strtotime( $item['last_checked_at'] . ' UTC' );

		if ( ! $timestamp ) {
			return '<span class="lwblc-muted">' . esc_html__( 'Never', 'lwblc' ) . '</span>';
		}

		return sprintf(
			'<span title="%1$s">%2$s</span>',
			esc_attr( $this->format_date( (string) $item['last_checked_at'] ) ),
			esc_html(
				sprintf(
					/* translators: %s: human readable time difference. */
					__( '%s ago', 'lwblc' ),
					human_time_diff( $timestamp, time() )
				)
			)
		);
	}

	/**
	 * Formats a stored UTC datetime in the site timezone.
	 *
	 * @param string $datetime MySQL datetime in UTC.
	 * @return string
	 */
	private function format_date( $datetime ) {
		$timestamp = strtotime( $datetime . ' UTC' );

		if ( ! $timestamp ) {
			return '';
		}

		// Dates are stored in UTC; wp_date() renders them in the site timezone.
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/**
	 * Fallback renderer for columns without a dedicated method.
	 *
	 * @param array  $item        Row data.
	 * @param string $column_name Column key.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? esc_html( (string) $item[ $column_name ] ) : '';
	}

	/**
	 * Message shown when there is nothing to list.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No links found. Run a scan to collect the links in your content.', 'lwblc' );
	}
}
