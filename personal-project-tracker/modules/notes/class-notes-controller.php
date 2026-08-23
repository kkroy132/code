<?php
/**
 * Admin-side form handling for Notes (create/update).
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Notes_Controller
 *
 * Handles the classic admin-post.php submission for the Add/Edit Note
 * form, mirroring the other modules' controllers. Pin/Unpin/Archive/
 * Restore/Delete are quick actions handled by the REST controller instead
 * and driven by JS.
 */
class PTP_Notes_Controller {

	/**
	 * Transient key prefix used to flash validation errors + submitted
	 * values back to the form after a redirect.
	 *
	 * @var string
	 */
	const FLASH_KEY_PREFIX = 'ptp_note_form_flash_';

	/**
	 * Handle POST admin-post.php?action=ptp_save_note (create or update).
	 */
	public static function handle_save() {
		PTP_Security::check_admin_referer();
		PTP_Security::require_capability( 'ptp_manage_data' );

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		$raw = array(
			'title'        => $_POST['title'] ?? '',
			'content'      => $_POST['content'] ?? '',
			'project_id'   => $_POST['project_id'] ?? 0,
			'task_id'      => $_POST['task_id'] ?? 0,
			'milestone_id' => $_POST['milestone_id'] ?? 0,
			'tags'         => $_POST['tags'] ?? '',
		);

		$result = $id ? PTP_Notes_Repository::update( $id, $raw ) : PTP_Notes_Repository::create( $raw );

		if ( is_wp_error( $result ) ) {
			self::set_flash( $result->get_error_messages(), $raw );

			$redirect = $id
				? add_query_arg(
					array(
						'page'   => 'ptp-notes',
						'action' => 'edit',
						'id'     => $id,
					),
					admin_url( 'admin.php' )
				)
				: add_query_arg(
					array(
						'page'   => 'ptp-notes',
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
					'page'        => 'ptp-notes',
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
