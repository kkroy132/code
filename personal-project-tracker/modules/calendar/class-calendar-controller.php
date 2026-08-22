<?php
/**
 * Admin-side form handling for custom Calendar events (create/update).
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Calendar_Controller
 *
 * Handles the classic admin-post.php submission for the Add/Edit Event
 * form, mirroring the other modules' controllers. Only custom events are
 * ever created/edited/deleted here — task/project/milestone deadlines are
 * edited on their own module's page, reached via the calendar item's link.
 */
class PTP_Calendar_Controller {

	/**
	 * Transient key prefix used to flash validation errors + submitted
	 * values back to the form after a redirect.
	 *
	 * @var string
	 */
	const FLASH_KEY_PREFIX = 'ptp_event_form_flash_';

	/**
	 * Handle POST admin-post.php?action=ptp_save_event (create or update).
	 */
	public static function handle_save() {
		PTP_Security::check_admin_referer();
		PTP_Security::require_capability( 'ptp_manage_data' );

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		$raw = array(
			'title'            => $_POST['title'] ?? '',
			'description'      => $_POST['description'] ?? '',
			'all_day'          => isset( $_POST['all_day'] ),
			'start_date'       => $_POST['start_date'] ?? '',
			'start_time'       => $_POST['start_time'] ?? '',
			'end_date'         => $_POST['end_date'] ?? '',
			'end_time'         => $_POST['end_time'] ?? '',
			'project_id'       => $_POST['project_id'] ?? 0,
			'task_id'          => $_POST['task_id'] ?? 0,
			'milestone_id'     => $_POST['milestone_id'] ?? 0,
			'location'         => $_POST['location'] ?? '',
			'color'            => $_POST['color'] ?? '',
			'reminder_minutes' => $_POST['reminder_minutes'] ?? '',
		);

		$result = $id ? PTP_Calendar_Repository::update( $id, $raw ) : PTP_Calendar_Repository::create( $raw );

		if ( is_wp_error( $result ) ) {
			self::set_flash( $result->get_error_messages(), $raw );

			$redirect = $id
				? add_query_arg(
					array(
						'page'   => 'ptp-calendar',
						'action' => 'edit',
						'id'     => $id,
					),
					admin_url( 'admin.php' )
				)
				: add_query_arg(
					array(
						'page'   => 'ptp-calendar',
						'action' => 'new',
					),
					admin_url( 'admin.php' )
				);

			wp_safe_redirect( $redirect );
			exit;
		}

		$new_id = $id ? $id : $result;

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'ptp-calendar',
					'action'      => 'view',
					'id'          => $new_id,
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
