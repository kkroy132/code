<?php
/**
 * Notifications + Reminders + Smart Alerts module bootstrap.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Notifications_Module
 *
 * Registers the module's admin-post handlers, REST routes, admin script,
 * and — the only module in the plugin that needs it — WP-Cron: a custom
 * five-minute schedule for reminder delivery (WP core only ships hourly/
 * twicedaily/daily) and the built-in hourly schedule for Smart Alerts.
 * Both cron hooks are scheduled from admin_init behind a wp_next_scheduled()
 * guard, the same idempotent-check pattern PTP_Database::maybe_upgrade()
 * already uses — cheap (one options lookup) and prevents duplicate cron
 * events (re-activating, or a stray reactivation, never double-schedules).
 */
class PTP_Notifications_Module {

	/**
	 * Reminder delivery cron hook: checks due reminders every 5 minutes.
	 * Precise-timing reminders (10-minute snoozes, exact remind_at times)
	 * would be too coarse on WP core's hourly minimum schedule.
	 *
	 * @var string
	 */
	const REMINDER_HOOK = 'ptp_reminder_check';

	/**
	 * Smart Alerts cron hook: rule-based checks, hourly — cheap enough to
	 * run every hour, and frequent enough that a condition (an overdue
	 * task, a budget crossed) is never stale for long. Never run on a page
	 * load; only this cron event triggers PTP_Smart_Alerts_Service::run_all().
	 *
	 * @var string
	 */
	const SMART_ALERTS_HOOK = 'ptp_smart_alerts_check';

	/**
	 * Custom cron schedule name added for REMINDER_HOOK.
	 *
	 * @var string
	 */
	const FIVE_MINUTE_SCHEDULE = 'ptp_five_minutes';

	/**
	 * Hook registration. Called from PTP_Plugin::load_modules().
	 */
	public function register() {
		add_filter( 'cron_schedules', array( __CLASS__, 'register_cron_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		add_action( 'admin_init', array( __CLASS__, 'maybe_schedule_cron' ) );
		add_action( self::REMINDER_HOOK, array( __CLASS__, 'run_reminder_check' ) );
		add_action( self::SMART_ALERTS_HOOK, array( 'PTP_Smart_Alerts_Service', 'run_all' ) );

		add_action( 'admin_post_ptp_save_reminder', array( 'PTP_Notifications_Controller', 'handle_save_reminder' ) );
		add_action( 'admin_post_ptp_save_notification_preferences', array( 'PTP_Notifications_Controller', 'handle_save_preferences' ) );
		add_action( 'ptp_register_rest_routes', array( 'PTP_Notifications_REST', 'register_routes' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * @param array $schedules Existing cron schedules.
	 * @return array
	 */
	public static function register_cron_schedule( $schedules ) {
		$schedules[ self::FIVE_MINUTE_SCHEDULE ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 Minutes (Personal Project Tracker)', 'personal-project-tracker' ),
		);

		return $schedules;
	}

	/**
	 * Schedule both cron events if — and only if — they are not already
	 * scheduled. This single guard is what "prevents duplicate cron
	 * events": calling this on every admin_init is cheap (wp_next_scheduled()
	 * is one options lookup) and wp_schedule_event() is never reached once
	 * an event already exists.
	 */
	public static function maybe_schedule_cron() {
		if ( ! wp_next_scheduled( self::REMINDER_HOOK ) ) {
			wp_schedule_event( time(), self::FIVE_MINUTE_SCHEDULE, self::REMINDER_HOOK );
		}

		if ( ! wp_next_scheduled( self::SMART_ALERTS_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::SMART_ALERTS_HOOK );
		}
	}

	/**
	 * The ptp_reminder_check cron handler: syncs Calendar's reminder_minutes
	 * events into `reminders` rows, then fires every due reminder. A
	 * reminder is only advanced/completed once it has actually been
	 * delivered (or definitively skipped by preference) — see
	 * PTP_Notifications_Service::maybe_notify()'s 'quiet_hours' sentinel.
	 */
	public static function run_reminder_check() {
		if ( ! PTP_Settings::get( 'reminders_enabled', true ) ) {
			return;
		}

		PTP_Notifications_Service::sync_calendar_reminders();

		foreach ( PTP_Reminders_Repository::get_due() as $reminder ) {
			$occurrence_key = 'reminder_' . $reminder->id . '_' . ( $reminder->snoozed_until ? $reminder->snoozed_until : $reminder->remind_at );

			$result = PTP_Notifications_Service::maybe_notify(
				array(
					'type'          => 'reminder',
					'title'         => $reminder->title,
					'message'       => self::reminder_message( $reminder ),
					'category'      => PTP_Notifications_Service::category_for_related_type( $reminder->related_type ),
					'related_type'  => $reminder->related_type ? $reminder->related_type : 'reminder',
					'related_id'    => $reminder->related_type ? $reminder->related_id : $reminder->id,
					'dedup_key'     => $occurrence_key,
					'cooldown_hours' => 0,
				)
			);

			if ( 'quiet_hours' === $result ) {
				continue;
			}

			PTP_Reminders_Repository::advance( $reminder );
		}
	}

	/**
	 * @param object $reminder Reminder row.
	 * @return string
	 */
	private static function reminder_message( $reminder ) {
		if ( ! $reminder->related_type ) {
			return __( 'Custom reminder.', 'personal-project-tracker' );
		}

		$labels = PTP_Reminders_Repository::get_related_types();

		/* translators: %s: related item type, e.g. "Task". */
		return sprintf( __( 'Reminder for %s.', 'personal-project-tracker' ), $labels[ $reminder->related_type ] ?? $reminder->related_type );
	}

	/**
	 * Enqueue the Notifications screen's JS.
	 *
	 * @param string $hook_suffix Current admin page hook suffix (unused; we key off $_GET['page']).
	 */
	public static function enqueue_assets( $hook_suffix ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! isset( $_GET['page'] ) || 'ptp-notifications' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		wp_enqueue_script(
			'ptp-notifications',
			PTP_PLUGIN_URL . 'modules/notifications/assets/notifications.js',
			array( 'ptp-admin' ),
			PTP_VERSION,
			true
		);

		wp_localize_script(
			'ptp-notifications',
			'ptpNotifications',
			array(
				'confirmDelete' => __( 'Delete this notification? This cannot be undone.', 'personal-project-tracker' ),
				'loadingText'   => __( 'Working…', 'personal-project-tracker' ),
				'errorGeneric'  => __( 'Something went wrong. Please try again.', 'personal-project-tracker' ),
			)
		);
	}
}
