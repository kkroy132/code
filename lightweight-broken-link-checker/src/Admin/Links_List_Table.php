<?php
/**
 * List table for discovered links.
 *
 * @package LWBLC
 */

namespace LWBLC\Admin;

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
			'link_url'    => __( 'URL', 'lwblc' ),
			'source'      => __( 'Source', 'lwblc' ),
			'status'      => __( 'Status', 'lwblc' ),
			'http_code'   => __( 'HTTP code', 'lwblc' ),
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
	 * Loads the rows to display.
	 *
	 * Filled with real data in the dashboard phase; the structure is set up
	 * here so the page renders correctly from the start.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			$this->get_sortable_columns(),
			$this->get_default_primary_column_name(),
		);

		$this->items = array();

		$this->set_pagination_args(
			array(
				'total_items' => 0,
				'per_page'    => $this->get_per_page(),
				'total_pages' => 0,
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
		esc_html_e( 'No links found yet. Run a scan to collect the links in your content.', 'lwblc' );
	}
}
