<?php
/**
 * Admin-side form handling for Reminders and Notification Preferences.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Notifications_Controller
 *
 * Handles the classic admin-post.php submissions for the Reminder form and
 * the Notification Preferences form. Notification Center quick actions
 * (read/unread/snooze/delete/mark-all-read) are REST+JS driven instead,
 * matching every other module's list-page quick-action pattern.
 */
class PTP_Notifications_Controller {

	/**
	 * Transient key prefix used to flash validation errors + submitted
	 * values back to the reminder form after a redirect.
	 *
	 * @var string
	 */
	const REMINDER_FLASH_KEY_PREFIX = 'ptp_reminder_form_flash_';

	/**
	 * Handle POST admin-post.php?action=ptp_save_reminder (create or update).
	 */
	public static function handle_save_reminder() {
		PTP_Security::check_admin_referer();
		PTP_Security::require_capability( 'ptp_manage_data' );

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		$raw = array(
			'title'               => $_POST['title'] ?? '',
			'related_type'        => $_POST['related_type'] ?? '',
			'related_id'          => $_POST['related_id'] ?? 0,
			'remind_at'           => $_POST['remind_at'] ?? '',
			'recurrence'          => $_POST['recurrence'] ?? 'none',
			'recurrence_interval' => $_POST['recurrence_interval'] ?? 0,
		);

		$result = $id ? PTP_Reminders_Repository::update( $id, $raw ) : PTP_Reminders_Repository::create( $raw );

		if ( is_wp_error( $result ) ) {
			self::set_flash( $result->get_error_messages(), $raw );

			$redirect = $id
				? add_query_arg( array( 'page' => 'ptp-notifications', 'action' => 'edit_reminder', 'id' => $id ), admin_url( 'admin.php' ) )
				: add_query_arg( array( 'page' => 'ptp-notifications', 'action' => 'new_reminder' ), admin_url( 'admin.php' ) );

			wp_safe_redirect( $redirect );
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'ptp-notifications',
					'action'      => 'reminders',
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
			self::REMINDER_FLASH_KEY_PREFIX . get_current_user_id(),
			array(
				'errors' => $errors,
				'data'   => $data,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Read and clear the current user's flashed reminder form state, if any.
	 *
	 * @return array{errors: string[], data: array}|null
	 */
	public static function get_reminder_flash() {
		$key   = self::REMINDER_FLASH_KEY_PREFIX . get_current_user_id();
		$flash = get_transient( $key );

		if ( $flash ) {
			delete_transient( $key );
		}

		return $flash ? $flash : null;
	}

	/**
	 * Handle POST admin-post.php?action=ptp_save_notification_preferences.
	 *
	 * Settings-form pattern (like every other preferences-style form in
	 * this plugin) rather than a repository — preferences live in
	 * PTP_Settings, not their own table.
	 */
	public static function handle_save_preferences() {
		PTP_Security::check_admin_referer();
		PTP_Security::require_capability( 'ptp_manage_data' );

		$notifications_enabled = ! empty( $_POST['notifications_enabled'] );

		$categories        = array();
		$submitted_categories = isset( $_POST['categories'] ) ? (array) $_POST['categories'] : array();

		foreach ( array_keys( PTP_Notifications_Repository::get_categories() ) as $category ) {
			$categories[ $category ] = in_array( $category, $submitted_categories, true );
		}

		$quiet_hours_enabled = ! empty( $_POST['quiet_hours_enabled'] );
		$quiet_hours_start   = self::sanitize_time( $_POST['quiet_hours_start'] ?? '', '22:00' );
		$quiet_hours_end     = self::sanitize_time( $_POST['quiet_hours_end'] ?? '', '07:00' );

		PTP_Settings::update_many(
			array(
				'notifications_enabled'   => $notifications_enabled,
				'notification_categories' => $categories,
				'quiet_hours_enabled'     => $quiet_hours_enabled,
				'quiet_hours_start'       => $quiet_hours_start,
				'quiet_hours_end'         => $quiet_hours_end,
			)
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'ptp-notifications',
					'action'      => 'preferences',
					'ptp_success' => 'updated',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * @param string $value   Raw 'H:i' time string.
	 * @param string $default_value Fallback when invalid/empty.
	 * @return string 'H:i'
	 */
	private static function sanitize_time( $value, $default_value ) {
		$value  = trim( wp_unslash( (string) $value ) );
		$parsed = DateTime::createFromFormat( 'H:i', $value );

		return ( $parsed && $parsed->format( 'H:i' ) === $value ) ? $value : $default_value;
	}
}
