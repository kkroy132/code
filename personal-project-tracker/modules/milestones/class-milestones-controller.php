<?php
/**
 * Admin-side form handling for Milestones (create/update).
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Milestones_Controller
 *
 * Handles the classic admin-post.php submission for the Add/Edit
 * Milestone form, mirroring PTP_Tasks_Controller. Quick actions
 * (complete/reopen/archive/restore/delete, and task attach/detach) are
 * handled by the REST controller instead and driven by JS.
 */
class PTP_Milestones_Controller {

	/**
	 * Transient key prefix used to flash validation errors + submitted
	 * values back to the form after a redirect.
	 *
	 * @var string
	 */
	const FLASH_KEY_PREFIX = 'ptp_milestone_form_flash_';

	/**
	 * Handle POST admin-post.php?action=ptp_save_milestone (create or update).
	 */
	public static function handle_save() {
		PTP_Security::check_admin_referer();
		PTP_Security::require_capability( 'ptp_manage_projects' );

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		$raw = array(
			'title'       => $_POST['title'] ?? '',
			'description' => $_POST['description'] ?? '',
			'project_id'  => $_POST['project_id'] ?? 0,
			'status'      => $_POST['status'] ?? '',
			'priority'    => $_POST['priority'] ?? '',
			'start_date'  => $_POST['start_date'] ?? '',
			'due_date'    => $_POST['due_date'] ?? '',
			'progress'    => $_POST['progress'] ?? 0,
		);

		$result = $id ? PTP_Milestones_Repository::update( $id, $raw ) : PTP_Milestones_Repository::create( $raw );

		if ( is_wp_error( $result ) ) {
			self::set_flash( $result->get_error_messages(), $raw );

			$redirect = $id
				? add_query_arg(
					array(
						'page'   => 'ptp-milestones',
						'action' => 'edit',
						'id'     => $id,
					),
					admin_url( 'admin.php' )
				)
				: add_query_arg(
					array(
						'page'   => 'ptp-milestones',
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
					'page'        => 'ptp-milestones',
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
