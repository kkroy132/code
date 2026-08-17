<?php
/**
 * Admin AJAX endpoints that drive the scanner UI.
 *
 * @package WP_Site_Toolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every handler verifies the shared nonce and the caller's capability.
 *
 * @since 1.0.0
 */
class WPSTK_Ajax {

	/**
	 * Registers the handlers.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_wpstk_start_scan', array( __CLASS__, 'start_scan' ) );
		add_action( 'wp_ajax_wpstk_scan_step', array( __CLASS__, 'scan_step' ) );
		add_action( 'wp_ajax_wpstk_scan_status', array( __CLASS__, 'scan_status' ) );
		add_action( 'wp_ajax_wpstk_cancel_scan', array( __CLASS__, 'cancel_scan' ) );
	}

	/**
	 * Starts a new audit.
	 *
	 * @return void
	 */
	public static function start_scan() {
		WPSTK_Security::verify_ajax( 'run' );

		$progress = wpstk()->audit()->start( 'manual' );

		if ( is_wp_error( $progress ) ) {
			wp_send_json_error(
				array(
					'message' => $progress->get_error_message(),
					'code'    => $progress->get_error_code(),
				),
				409
			);

			return;
		}

		wp_send_json_success( $progress );
	}

	/**
	 * Processes the next batch.
	 *
	 * @return void
	 */
	public static function scan_step() {
		WPSTK_Security::verify_ajax( 'run' );

		$progress = wpstk()->audit()->step();

		if ( is_wp_error( $progress ) ) {
			wp_send_json_error(
				array(
					'message' => $progress->get_error_message(),
					'code'    => $progress->get_error_code(),
				),
				409
			);

			return;
		}

		if ( ! empty( $progress['done'] ) ) {
			$progress['redirect'] = add_query_arg(
				array(
					'page'    => 'site-toolkit-reports',
					'scan_id' => (int) $progress['scan_id'],
				),
				admin_url( 'admin.php' )
			);
		}

		wp_send_json_success( $progress );
	}

	/**
	 * Returns the state of the running audit.
	 *
	 * @return void
	 */
	public static function scan_status() {
		WPSTK_Security::verify_ajax( 'view' );

		wp_send_json_success( wpstk()->audit()->status() );
	}

	/**
	 * Cancels the running audit.
	 *
	 * @return void
	 */
	public static function cancel_scan() {
		WPSTK_Security::verify_ajax( 'run' );

		$cancelled = wpstk()->audit()->cancel();

		wp_send_json_success(
			array(
				'cancelled' => (bool) $cancelled,
				'message'   => $cancelled
					? __( 'The audit was cancelled.', 'site-toolkit' )
					: __( 'There was no audit to cancel.', 'site-toolkit' ),
			)
		);
	}
}
