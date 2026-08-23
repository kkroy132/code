<?php
/**
 * Admin-side form handling for the Settings page's tabs.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Settings_Controller
 *
 * One admin-post action (ptp_save_settings) shared by every tab — the
 * submitted 'tab' field decides which sanitize routine runs, so adding a
 * tab never means adding a new admin-post hook. The Notifications tab has
 * no form of its own here (it links to the Phase 11 Preferences page,
 * which already owns notifications_enabled/notification_categories/
 * quiet_hours_*); the Backup tab's actions (create/delete/download backup,
 * export, import, restore) are handled by PTP_Backup_Controller instead,
 * since they act on files, not PTP_Settings values.
 */
class PTP_Settings_Controller {

	/**
	 * Handle POST admin-post.php?action=ptp_save_settings.
	 */
	public static function handle_save_settings() {
		PTP_Security::check_admin_referer();
		PTP_Security::require_capability( 'ptp_manage_settings' );

		$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'general';

		$handlers = array(
			'general'    => array( __CLASS__, 'save_general' ),
			'appearance' => array( __CLASS__, 'save_appearance' ),
			'finance'    => array( __CLASS__, 'save_finance' ),
			'ai'         => array( __CLASS__, 'save_ai' ),
			'privacy'    => array( __CLASS__, 'save_privacy' ),
			'advanced'   => array( __CLASS__, 'save_advanced' ),
		);

		if ( isset( $handlers[ $tab ] ) ) {
			call_user_func( $handlers[ $tab ] );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'ptp-settings',
					'tab'         => $tab,
					'ptp_success' => 'settings_saved',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * General: defaults that seed new records (Project Status, Task
	 * Priority, Task Status), plus Currency/Date Format/Time Format (all
	 * pre-existing PTP_Settings keys — this only adds the UI) and Week
	 * Start, which is WordPress core's own start_of_week option, edited
	 * here directly rather than duplicated as a second plugin setting.
	 */
	private static function save_general() {
		$status_keys   = array_keys( PTP_Projects_Repository::get_statuses() );
		$task_statuses = array_keys( PTP_Tasks_Repository::get_statuses() );
		$priorities    = array_keys( PTP_Tasks_Repository::get_priorities() );

		PTP_Settings::update_many(
			array(
				'default_currency'      => isset( $_POST['default_currency'] ) ? strtoupper( substr( PTP_Security::sanitize_text( $_POST['default_currency'] ), 0, 10 ) ) : PTP_Settings::get( 'default_currency' ),
				'date_format'           => isset( $_POST['date_format'] ) ? PTP_Security::sanitize_text( $_POST['date_format'] ) : PTP_Settings::get( 'date_format' ),
				'time_format'           => isset( $_POST['time_format'] ) ? PTP_Security::sanitize_text( $_POST['time_format'] ) : PTP_Settings::get( 'time_format' ),
				'default_project_status' => PTP_Security::sanitize_enum( isset( $_POST['default_project_status'] ) ? sanitize_key( wp_unslash( $_POST['default_project_status'] ) ) : '', $status_keys, 'planning' ),
				'default_task_status'   => PTP_Security::sanitize_enum( isset( $_POST['default_task_status'] ) ? sanitize_key( wp_unslash( $_POST['default_task_status'] ) ) : '', $task_statuses, 'todo' ),
				'default_task_priority' => PTP_Security::sanitize_enum( isset( $_POST['default_task_priority'] ) ? sanitize_key( wp_unslash( $_POST['default_task_priority'] ) ) : '', $priorities, 'medium' ),
			)
		);

		$week_start = isset( $_POST['week_start'] ) ? absint( $_POST['week_start'] ) : 0;
		update_option( 'start_of_week', max( 0, min( 6, $week_start ) ) );
	}

	/**
	 * Appearance: Light / Dark / System.
	 */
	private static function save_appearance() {
		$mode = isset( $_POST['appearance_mode'] ) ? sanitize_key( wp_unslash( $_POST['appearance_mode'] ) ) : 'system';

		PTP_Settings::update( 'appearance_mode', PTP_Security::sanitize_enum( $mode, array( 'light', 'dark', 'system' ), 'system' ) );
	}

	/**
	 * Finance: requires ptp_manage_finance in addition to ptp_manage_settings —
	 * the same rule that keeps Finance private everywhere else in the plugin.
	 */
	private static function save_finance() {
		PTP_Security::require_capability( 'ptp_manage_finance' );

		$threshold = isset( $_POST['budget_warning_threshold'] ) ? absint( $_POST['budget_warning_threshold'] ) : 80;

		PTP_Settings::update( 'budget_warning_threshold', max( 1, min( 100, $threshold ) ) );
	}

	/**
	 * AI: which external tool a saved prompt is labeled for by default.
	 * Purely a label — see PTP_Prompt_Generator, this never calls an API.
	 */
	private static function save_ai() {
		$tool = isset( $_POST['ai_prompt_default_tool'] ) ? sanitize_key( wp_unslash( $_POST['ai_prompt_default_tool'] ) ) : 'generic';

		PTP_Settings::update( 'ai_prompt_default_tool', PTP_Security::sanitize_enum( $tool, array( 'generic', 'gemini', 'claude', 'chatgpt', 'other' ), 'generic' ) );
	}

	/**
	 * Privacy: whether uninstalling the plugin also deletes all plugin data.
	 */
	private static function save_privacy() {
		PTP_Settings::update( 'delete_data_on_uninstall', ! empty( $_POST['delete_data_on_uninstall'] ) );
	}

	/**
	 * Advanced: Smart Alerts/Reminders tuning — these settings have existed
	 * since Phase 11 but never had a UI until now.
	 */
	private static function save_advanced() {
		PTP_Settings::update_many(
			array(
				'smart_alerts_enabled'       => ! empty( $_POST['smart_alerts_enabled'] ),
				'reminders_enabled'          => ! empty( $_POST['reminders_enabled'] ),
				'overdue_task_alerts'        => ! empty( $_POST['overdue_task_alerts'] ),
				'upcoming_deadline_days'     => max( 1, min( 90, isset( $_POST['upcoming_deadline_days'] ) ? absint( $_POST['upcoming_deadline_days'] ) : 3 ) ),
				'project_inactivity_days'    => max( 1, min( 365, isset( $_POST['project_inactivity_days'] ) ? absint( $_POST['project_inactivity_days'] ) : 14 ) ),
				'long_running_timer_hours'   => max( 1, min( 72, isset( $_POST['long_running_timer_hours'] ) ? absint( $_POST['long_running_timer_hours'] ) : 4 ) ),
				'too_many_overdue_threshold' => max( 1, min( 100, isset( $_POST['too_many_overdue_threshold'] ) ? absint( $_POST['too_many_overdue_threshold'] ) : 5 ) ),
			)
		);
	}
}
