<?php
/**
 * Admin-side form handling for Files (upload/attach).
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Files_Controller
 *
 * Handles the classic admin-post.php submission for the Attach File form.
 * Two paths, both ending at PTP_Files_Repository::create():
 *  - JS path (preferred): the wp.media() picker already uploaded/selected
 *    an attachment and populated a hidden attachment_id field.
 *  - No-JS fallback: a plain <input type="file"> is posted and handed to
 *    WordPress's own media_handle_upload(), which validates, sanitizes,
 *    and stores it as a Media Library attachment exactly as if it had been
 *    uploaded from wp-admin/media-new.php.
 */
class PTP_Files_Controller {

	/**
	 * Transient key prefix used to flash validation errors + submitted
	 * values back to the form after a redirect.
	 *
	 * @var string
	 */
	const FLASH_KEY_PREFIX = 'ptp_file_form_flash_';

	/**
	 * Handle POST admin-post.php?action=ptp_attach_file.
	 */
	public static function handle_attach() {
		PTP_Security::check_admin_referer();
		PTP_Security::require_capability( 'ptp_manage_data' );

		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;

		if ( ! $attachment_id && ! empty( $_FILES['new_file']['name'] ) ) {
			if ( ! current_user_can( 'upload_files' ) ) {
				wp_die(
					esc_html__( 'You do not have permission to upload files.', 'personal-project-tracker' ),
					esc_html__( 'Permission denied', 'personal-project-tracker' ),
					array( 'response' => 403 )
				);
			}

			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';

			$uploaded = media_handle_upload( 'new_file', 0 );

			if ( is_wp_error( $uploaded ) ) {
				self::set_flash( array( $uploaded->get_error_message() ), $_POST );
				wp_safe_redirect( add_query_arg( array( 'page' => 'ptp-files', 'action' => 'new' ), admin_url( 'admin.php' ) ) );
				exit;
			}

			$attachment_id = $uploaded;
		}

		$raw = array(
			'attachment_id' => $attachment_id,
			'project_id'    => $_POST['project_id'] ?? 0,
			'task_id'       => $_POST['task_id'] ?? 0,
			'milestone_id'  => $_POST['milestone_id'] ?? 0,
			'note_id'       => $_POST['note_id'] ?? 0,
		);

		$result = PTP_Files_Repository::create( $raw );

		if ( is_wp_error( $result ) ) {
			self::set_flash( $result->get_error_messages(), $raw );
			wp_safe_redirect( add_query_arg( array( 'page' => 'ptp-files', 'action' => 'new' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'ptp-files',
					'ptp_success' => 'attached',
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
