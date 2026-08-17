<?php
/**
 * CSV export, the redirect manager form, and other admin-post actions.
 *
 * @package WP_Site_Toolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles form submissions that are not part of the Settings API.
 *
 * @since 1.0.0
 */
class WPSTK_Export {

	/**
	 * Registers the handlers.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_wpstk_export_csv', array( __CLASS__, 'export_csv' ) );
		add_action( 'admin_post_wpstk_delete_scan', array( __CLASS__, 'delete_scan' ) );
		add_action( 'admin_post_wpstk_delete_404', array( __CLASS__, 'delete_not_found' ) );
		add_action( 'admin_post_wpstk_clear_404', array( __CLASS__, 'clear_not_found' ) );
		add_action( 'admin_post_wpstk_clear_data', array( __CLASS__, 'clear_data' ) );
		add_action( 'admin_post_wpstk_reset_settings', array( __CLASS__, 'reset_settings' ) );
		add_action( 'admin_post_wpstk_save_redirect', array( __CLASS__, 'save_redirect' ) );
		add_action( 'admin_post_wpstk_delete_redirect', array( __CLASS__, 'delete_redirect' ) );
		add_action( 'admin_post_wpstk_toggle_redirect', array( __CLASS__, 'toggle_redirect' ) );
	}

	/**
	 * Streams the checks of one scan as a CSV file.
	 *
	 * @return void
	 */
	public static function export_csv() {
		check_admin_referer( 'wpstk_export_csv' );
		WPSTK_Security::require_cap( 'view' );

		$scan_id = isset( $_GET['scan_id'] ) ? absint( wp_unslash( $_GET['scan_id'] ) ) : 0;
		$scan    = WPSTK_Scan_Store::get( $scan_id );

		if ( ! $scan ) {
			wp_die(
				esc_html__( 'That scan could not be found.', 'wp-site-toolkit' ),
				esc_html__( 'Export failed', 'wp-site-toolkit' ),
				array( 'response' => 404 )
			);
		}

		$checks   = WPSTK_Scan_Store::sort_by_severity( WPSTK_Scan_Store::get_checks( $scan_id ) );
		$filename = sprintf( 'wp-site-toolkit-scan-%d-%s.csv', $scan_id, gmdate( 'Ymd-His' ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$output = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Writing to the output stream, not the filesystem.

		if ( false === $output ) {
			wp_die( esc_html__( 'The export could not be created.', 'wp-site-toolkit' ) );
		}

		self::write_row(
			$output,
			array(
				__( 'Section', 'wp-site-toolkit' ),
				__( 'Check', 'wp-site-toolkit' ),
				__( 'Severity', 'wp-site-toolkit' ),
				__( 'Finding', 'wp-site-toolkit' ),
				__( 'Why it matters', 'wp-site-toolkit' ),
				__( 'Recommended action', 'wp-site-toolkit' ),
				__( 'Affected items', 'wp-site-toolkit' ),
				__( 'Examples', 'wp-site-toolkit' ),
			)
		);

		$audit = wpstk()->audit();

		foreach ( $checks as $check ) {
			$module   = $audit->get_module( $check['module'] );
			$examples = array();

			foreach ( (array) $check['items'] as $item ) {
				$examples[] = trim( $item['label'] . ( '' !== $item['url'] ? ' <' . $item['url'] . '>' : '' ) );
			}

			self::write_row(
				$output,
				array(
					self::cell( $module ? $module->get_label() : $check['module'] ),
					self::cell( $check['label'] ),
					self::cell( WPSTK_Check::status_label( $check['status'] ) ),
					self::cell( $check['summary'] ),
					self::cell( $check['why'] ),
					self::cell( $check['action'] ),
					self::cell( (string) (int) $check['items_total'] ),
					self::cell( implode( ' | ', $examples ) ),
				)
			);
		}

		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Output stream.

		exit;
	}

	/**
	 * Writes one CSV row.
	 *
	 * The separator, enclosure and escape characters are always passed
	 * explicitly: PHP 8.4 deprecates relying on the default escape character.
	 *
	 * @param resource $handle Output stream.
	 * @param array    $row    Row values.
	 *
	 * @return void
	 */
	private static function write_row( $handle, $row ) {
		fputcsv( $handle, $row, ',', '"', '' );
	}

	/**
	 * Neutralises spreadsheet formula injection in a CSV cell.
	 *
	 * @param string $value Cell value.
	 *
	 * @return string
	 */
	private static function cell( $value ) {
		$value = (string) $value;

		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}

		return $value;
	}

	/**
	 * Deletes one stored scan.
	 *
	 * @return void
	 */
	public static function delete_scan() {
		check_admin_referer( 'wpstk_delete_scan' );
		WPSTK_Security::require_cap( 'manage' );

		$scan_id = isset( $_GET['scan_id'] ) ? absint( wp_unslash( $_GET['scan_id'] ) ) : 0;

		if ( $scan_id > 0 ) {
			WPSTK_Scan_Store::delete( $scan_id );
		}

		self::redirect( 'wp-site-toolkit-reports', 'scan-deleted' );
	}

	/**
	 * Deletes one 404 log row.
	 *
	 * @return void
	 */
	public static function delete_not_found() {
		check_admin_referer( 'wpstk_delete_404' );
		WPSTK_Security::require_cap( 'manage' );

		$row_id = isset( $_GET['row_id'] ) ? absint( wp_unslash( $_GET['row_id'] ) ) : 0;

		if ( $row_id > 0 ) {
			WPSTK_Not_Found_Monitor::delete( $row_id );
		}

		self::redirect( 'wp-site-toolkit-links', 'entry-deleted', array( 'tab' => '404' ) );
	}

	/**
	 * Empties the 404 log.
	 *
	 * @return void
	 */
	public static function clear_not_found() {
		check_admin_referer( 'wpstk_clear_404' );
		WPSTK_Security::require_cap( 'manage' );

		WPSTK_Not_Found_Monitor::clear();

		self::redirect( 'wp-site-toolkit-links', 'log-cleared', array( 'tab' => '404' ) );
	}

	/**
	 * Removes every scan, check and 404 row.
	 *
	 * @return void
	 */
	public static function clear_data() {
		check_admin_referer( 'wpstk_clear_data' );
		WPSTK_Security::require_cap( 'manage' );

		WPSTK_Database::truncate_all();
		delete_option( WPSTK_Audit::STATE_OPTION );

		self::redirect( 'wp-site-toolkit-settings', 'data-cleared' );
	}

	/**
	 * Restores the default settings.
	 *
	 * @return void
	 */
	public static function reset_settings() {
		check_admin_referer( 'wpstk_reset_settings' );
		WPSTK_Security::require_cap( 'manage' );

		WPSTK_Settings::reset();
		WPSTK_Cron::schedule_events();

		self::redirect( 'wp-site-toolkit-settings', 'settings-reset' );
	}

	/**
	 * Creates or updates a redirect.
	 *
	 * @return void
	 */
	public static function save_redirect() {
		check_admin_referer( 'wpstk_save_redirect' );
		WPSTK_Security::require_cap( 'manage' );

		$redirect_id = isset( $_POST['redirect_id'] ) ? absint( wp_unslash( $_POST['redirect_id'] ) ) : 0;
		$source      = isset( $_POST['source_path'] ) ? sanitize_text_field( wp_unslash( $_POST['source_path'] ) ) : '';
		$target      = isset( $_POST['target_url'] ) ? sanitize_text_field( wp_unslash( $_POST['target_url'] ) ) : '';
		$status_code = isset( $_POST['status_code'] ) ? absint( wp_unslash( $_POST['status_code'] ) ) : 301;
		$enabled     = ! empty( $_POST['enabled'] );

		if ( $redirect_id > 0 ) {
			$result = WPSTK_Redirects::update( $redirect_id, $source, $target, $status_code, $enabled );
		} else {
			$result = WPSTK_Redirects::create( $source, $target, $status_code );
		}

		if ( is_wp_error( $result ) ) {
			self::redirect(
				'wp-site-toolkit-links',
				'redirect-error',
				array(
					'tab'         => 'redirects',
					'wpstk_error' => rawurlencode( $result->get_error_message() ),
				)
			);
		}

		self::redirect(
			'wp-site-toolkit-links',
			$redirect_id > 0 ? 'redirect-updated' : 'redirect-created',
			array( 'tab' => 'redirects' )
		);
	}

	/**
	 * Deletes a redirect.
	 *
	 * @return void
	 */
	public static function delete_redirect() {
		check_admin_referer( 'wpstk_delete_redirect' );
		WPSTK_Security::require_cap( 'manage' );

		$redirect_id = isset( $_GET['redirect_id'] ) ? absint( wp_unslash( $_GET['redirect_id'] ) ) : 0;

		if ( $redirect_id > 0 ) {
			WPSTK_Redirects::delete( $redirect_id );
		}

		self::redirect( 'wp-site-toolkit-links', 'redirect-deleted', array( 'tab' => 'redirects' ) );
	}

	/**
	 * Enables or disables a redirect.
	 *
	 * @return void
	 */
	public static function toggle_redirect() {
		check_admin_referer( 'wpstk_toggle_redirect' );
		WPSTK_Security::require_cap( 'manage' );

		$redirect_id = isset( $_GET['redirect_id'] ) ? absint( wp_unslash( $_GET['redirect_id'] ) ) : 0;
		$enabled     = isset( $_GET['enabled'] ) ? absint( wp_unslash( $_GET['enabled'] ) ) : 0;

		if ( $redirect_id > 0 ) {
			WPSTK_Redirects::toggle( $redirect_id, (bool) $enabled );
		}

		self::redirect( 'wp-site-toolkit-links', 'redirect-updated', array( 'tab' => 'redirects' ) );
	}

	/**
	 * Sends the browser back to an admin screen with a notice code.
	 *
	 * @param string $page   Admin page slug.
	 * @param string $notice Notice code.
	 * @param array  $extra  Extra query arguments.
	 *
	 * @return void
	 */
	private static function redirect( $page, $notice, $extra = array() ) {
		$args = array_merge(
			array(
				'page'         => $page,
				'wpstk_notice' => $notice,
			),
			$extra
		);

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
