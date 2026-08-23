<?php
/**
 * Admin-side handling for Backup / Export / Import / Restore actions.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Backup_Controller
 *
 * Every action here requires ptp_manage_settings; anything that reads or
 * writes Finance data (a full backup/restore always includes Expenses/
 * Revenue; a JSON export does unless scoped to a single non-Finance
 * module; a CSV export of the Expenses/Revenue module) additionally
 * requires ptp_manage_finance, the same rule Finance follows everywhere
 * else in the plugin. Replace-mode restore requires the user to type the
 * literal word "REPLACE" — a strong confirmation for a destructive,
 * irreversible action, checked server-side, never trusted from a JS confirm().
 */
class PTP_Backup_Controller {

	/**
	 * Handle POST admin-post.php?action=ptp_create_backup.
	 */
	public static function handle_create_backup() {
		PTP_Security::check_admin_referer();
		PTP_Security::require_capability( 'ptp_manage_settings' );
		PTP_Security::require_capability( 'ptp_manage_finance' );

		$result = PTP_Backup_Service::create_backup();

		self::redirect_to_backup_tab( is_wp_error( $result ) ? $result : null, is_wp_error( $result ) ? '' : 'backup_created' );
	}

	/**
	 * Handle POST admin-post.php?action=ptp_delete_backup.
	 */
	public static function handle_delete_backup() {
		PTP_Security::check_admin_referer();
		PTP_Security::require_capability( 'ptp_manage_settings' );
		PTP_Security::require_capability( 'ptp_manage_finance' );

		$id     = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$result = PTP_Backup_Service::delete_backup( $id );

		self::redirect_to_backup_tab( is_wp_error( $result ) ? $result : null, is_wp_error( $result ) ? '' : 'backup_deleted' );
	}

	/**
	 * Handle GET admin-post.php?action=ptp_download_backup (a plain link,
	 * nonce read from the query string like every other download in this
	 * plugin — see PTP_Reports_Controller).
	 */
	public static function handle_download_backup() {
		PTP_Security::check_admin_referer();
		PTP_Security::require_capability( 'ptp_manage_settings' );
		PTP_Security::require_capability( 'ptp_manage_finance' );

		$id   = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$path = PTP_Backup_Service::get_backup_path( $id );

		if ( is_wp_error( $path ) ) {
			wp_die( esc_html( $path->get_error_message() ), esc_html__( 'Not found', 'personal-project-tracker' ), array( 'response' => 404 ) );
		}

		$fs = PTP_Backup_Service::get_filesystem();

		if ( ! $fs || ! $fs->exists( $path ) ) {
			wp_die( esc_html__( 'Backup file not found.', 'personal-project-tracker' ), esc_html__( 'Not found', 'personal-project-tracker' ), array( 'response' => 404 ) );
		}

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
		echo $fs->get_contents( $path ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw JSON file content, not HTML markup.
		exit;
	}

	/**
	 * Handle GET admin-post.php?action=ptp_export_json.
	 */
	public static function handle_export_json() {
		PTP_Security::check_admin_referer();
		PTP_Security::require_capability( 'ptp_manage_data' );

		$args = self::export_args_from_request( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( self::scope_touches_finance( $args ) ) {
			PTP_Security::require_capability( 'ptp_manage_finance' );
		}

		$result = PTP_Export_Service::export_json( $args );

		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), esc_html__( 'Export failed', 'personal-project-tracker' ), array( 'response' => 400 ) );
		}

		$json = wp_json_encode( $result['data'] );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ptp-export-' . $args['scope'] . '-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw JSON content, not HTML markup.
		exit;
	}

	/**
	 * Handle GET admin-post.php?action=ptp_export_csv.
	 */
	public static function handle_export_csv() {
		PTP_Security::check_admin_referer();
		PTP_Security::require_capability( 'ptp_manage_data' );

		$module = isset( $_GET['module'] ) ? sanitize_key( wp_unslash( $_GET['module'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( in_array( $module, array( 'expenses', 'revenue' ), true ) ) {
			PTP_Security::require_capability( 'ptp_manage_finance' );
		}

		$args = self::export_args_from_request( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$csv  = PTP_Export_Service::export_csv( $module, $args );

		if ( is_wp_error( $csv ) ) {
			wp_die( esc_html( $csv->get_error_message() ), esc_html__( 'Export failed', 'personal-project-tracker' ), array( 'response' => 400 ) );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ptp-' . $module . '-' . gmdate( 'Y-m-d' ) . '.csv"' );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw CSV content, not HTML markup.
		exit;
	}

	/**
	 * @param array $request $_GET (or an equivalent array).
	 * @return array export_json()/export_csv() $args.
	 */
	private static function export_args_from_request( array $request ) {
		return array(
			'scope'      => isset( $request['scope'] ) ? sanitize_key( wp_unslash( $request['scope'] ) ) : 'all',
			'project_id' => isset( $request['project_id'] ) ? absint( $request['project_id'] ) : 0,
			'date_from'  => isset( $request['date_from'] ) ? sanitize_text_field( wp_unslash( $request['date_from'] ) ) : '',
			'date_to'    => isset( $request['date_to'] ) ? sanitize_text_field( wp_unslash( $request['date_to'] ) ) : '',
			'module'     => isset( $request['export_module'] ) ? sanitize_key( wp_unslash( $request['export_module'] ) ) : '',
		);
	}

	/**
	 * @param array $args export_json() $args.
	 * @return bool
	 */
	private static function scope_touches_finance( array $args ) {
		if ( 'module' === ( $args['scope'] ?? '' ) ) {
			return in_array( $args['module'] ?? '', array( 'expenses', 'revenues' ), true );
		}

		return true; // all/project/date_range scopes always include the Finance tables.
	}

	/**
	 * Handle POST admin-post.php?action=ptp_import_upload — validates the
	 * uploaded file and, on success, redirects to the preview step. Nothing
	 * is written to the database here.
	 */
	public static function handle_import_upload() {
		PTP_Security::check_admin_referer();
		PTP_Security::require_capability( 'ptp_manage_settings' );
		PTP_Security::require_capability( 'ptp_manage_finance' );

		$file = $_FILES['import_file'] ?? array();

		$result = PTP_Import_Service::validate_upload( $file );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'     => 'ptp-settings',
						'tab'      => 'backup',
						'ptp_error' => $result->get_error_message(),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => 'ptp-settings',
					'tab'    => 'backup',
					'action' => 'import_preview',
					'token'  => $result['token'],
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle POST admin-post.php?action=ptp_import_confirm — applies a
	 * previously validated (and still-cached) import.
	 */
	public static function handle_import_confirm() {
		PTP_Security::check_admin_referer();
		PTP_Security::require_capability( 'ptp_manage_settings' );
		PTP_Security::require_capability( 'ptp_manage_finance' );

		$token    = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$strategy = isset( $_POST['duplicate_strategy'] ) ? sanitize_key( wp_unslash( $_POST['duplicate_strategy'] ) ) : 'skip_existing';

		$pending = PTP_Import_Service::load_pending( $token );

		if ( is_wp_error( $pending ) ) {
			self::redirect_to_backup_tab( $pending );
		}

		PTP_Import_Service::apply_import( $pending['payload'], $strategy );
		PTP_Import_Service::forget_pending( $token );

		self::redirect_to_backup_tab( null, 'import_completed' );
	}

	/**
	 * Handle POST admin-post.php?action=ptp_restore_backup. Merge is the
	 * default; Replace requires typing the literal word "REPLACE" — the
	 * strong confirmation this phase's spec requires — checked here,
	 * server-side, not merely a JS confirm() dialog.
	 */
	public static function handle_restore_backup() {
		PTP_Security::check_admin_referer();
		PTP_Security::require_capability( 'ptp_manage_settings' );
		PTP_Security::require_capability( 'ptp_manage_finance' );

		$id   = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$mode = isset( $_POST['mode'] ) && 'replace' === $_POST['mode'] ? 'replace' : 'merge';

		if ( 'replace' === $mode ) {
			$confirmation = isset( $_POST['confirm_replace'] ) ? trim( wp_unslash( $_POST['confirm_replace'] ) ) : '';

			if ( 'REPLACE' !== $confirmation ) {
				self::redirect_to_backup_tab( new WP_Error( 'ptp_confirmation_required', __( 'Type REPLACE exactly to confirm — nothing was changed.', 'personal-project-tracker' ) ) );
			}
		}

		$payload = PTP_Backup_Service::read_backup( $id );

		if ( is_wp_error( $payload ) ) {
			self::redirect_to_backup_tab( $payload );
		}

		PTP_Import_Service::apply_restore( $payload, $mode );

		self::redirect_to_backup_tab( null, 'restore_completed' );
	}

	/**
	 * @param WP_Error|null $error       Error to redirect with, or null on success.
	 * @param string        $success_code Success code for the notices partial.
	 */
	private static function redirect_to_backup_tab( $error, $success_code = '' ) {
		$args = array( 'page' => 'ptp-settings', 'tab' => 'backup' );

		if ( $error ) {
			$args['ptp_error'] = $error->get_error_message();
		} elseif ( $success_code ) {
			$args['ptp_success'] = $success_code;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
