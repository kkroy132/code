<?php
/**
 * Admin-side form handling for manual Time Entries (create/update).
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Time_Controller
 *
 * Handles the classic admin-post.php submission for the Add/Edit Manual
 * Time Entry form, mirroring the other modules' controllers. Timer control
 * (start/pause/resume/stop) is handled by the REST controller instead and
 * driven by JS, since that widget must never trigger a full page reload.
 */
class PTP_Time_Controller {

	/**
	 * Transient key prefix used to flash validation errors + submitted
	 * values back to the form after a redirect.
	 *
	 * @var string
	 */
	const FLASH_KEY_PREFIX = 'ptp_time_form_flash_';

	/**
	 * Handle POST admin-post.php?action=ptp_save_time_entry (create or update).
	 */
	public static function handle_save() {
		PTP_Security::check_admin_referer();
		PTP_Security::require_capability( 'ptp_manage_tasks' );

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		$raw = array(
			'entry_date'  => $_POST['entry_date'] ?? '',
			'start_time'  => $_POST['start_time'] ?? '',
			'end_time'    => $_POST['end_time'] ?? '',
			'duration'    => $_POST['duration'] ?? 0,
			'project_id'  => $_POST['project_id'] ?? 0,
			'task_id'     => $_POST['task_id'] ?? 0,
			'description' => $_POST['description'] ?? '',
		);

		$result = $id ? PTP_Time_Repository::update_manual( $id, $raw ) : PTP_Time_Repository::create_manual( $raw );

		if ( is_wp_error( $result ) ) {
			self::set_flash( $result->get_error_messages(), $raw );

			$redirect = $id
				? add_query_arg(
					array(
						'page'   => 'ptp-time-tracking',
						'action' => 'edit',
						'id'     => $id,
					),
					admin_url( 'admin.php' )
				)
				: add_query_arg(
					array(
						'page'   => 'ptp-time-tracking',
						'action' => 'new',
					),
					admin_url( 'admin.php' )
				);

			wp_safe_redirect( $redirect );
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'ptp-time-tracking',
					'ptp_success' => $id ? 'updated' : 'created',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * @param string[] $errors Error messages.
	 * @param array    $data   Raw submitted values.
	 */
	private static function set_flash( array $errors, array $data ) {
		set_transient(
			self::FLASH_KEY_PREFIX . get_current_user_id(),
			array(
				'errors' => $errors,
				'data'   => $data,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Read and clear the current user's flashed form state, if any.
	 *
	 * @return array{errors: string[], data: array}|null
	 */
	public static function get_flash() {
		$key   = self::FLASH_KEY_PREFIX . get_current_user_id();
		$flash = get_transient( $key );

		if ( $flash ) {
			delete_transient( $key );
		}

		return $flash ? $flash : null;
	}
}
