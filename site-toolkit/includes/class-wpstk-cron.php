<?php
/**
 * Scheduled maintenance and optional automatic audits.
 *
 * @package WP_Site_Toolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's scheduled events.
 *
 * Nothing here scans the site unless the administrator explicitly turned
 * automatic audits on; by default only a short daily cleanup runs.
 *
 * @since 1.0.0
 */
class WPSTK_Cron {

	/**
	 * Daily cleanup hook.
	 */
	const MAINTENANCE_HOOK = 'wpstk_daily_maintenance';

	/**
	 * Optional automatic audit hook.
	 */
	const AUTO_SCAN_HOOK = 'wpstk_auto_scan';

	/**
	 * One-off hook used to continue an audit that is still running.
	 */
	const CONTINUE_HOOK = 'wpstk_continue_scan';

	/**
	 * Registers the callbacks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'register_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- Adds a monthly interval only.

		add_action( self::MAINTENANCE_HOOK, array( __CLASS__, 'run_maintenance' ) );
		add_action( self::AUTO_SCAN_HOOK, array( __CLASS__, 'run_auto_scan' ) );
		add_action( self::CONTINUE_HOOK, array( __CLASS__, 'continue_scan' ) );
	}

	/**
	 * Adds a monthly interval.
	 *
	 * @param array $schedules Existing schedules.
	 *
	 * @return array
	 */
	public static function register_schedules( $schedules ) {
		if ( ! isset( $schedules['wpstk_monthly'] ) ) {
			$schedules['wpstk_monthly'] = array(
				'interval' => 30 * DAY_IN_SECONDS,
				'display'  => __( 'Once a month (Site Toolkit)', 'site-toolkit' ),
			);
		}

		return $schedules;
	}

	/**
	 * Schedules the events that should be running.
	 *
	 * @return void
	 */
	public static function schedule_events() {
		if ( ! wp_next_scheduled( self::MAINTENANCE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::MAINTENANCE_HOOK );
		}

		$auto = WPSTK_Settings::get( 'auto_scan', 'disabled' );

		wp_clear_scheduled_hook( self::AUTO_SCAN_HOOK );

		if ( 'weekly' === $auto ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', self::AUTO_SCAN_HOOK );
		} elseif ( 'monthly' === $auto ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'wpstk_monthly', self::AUTO_SCAN_HOOK );
		}
	}

	/**
	 * Removes every scheduled event.
	 *
	 * @return void
	 */
	public static function clear_events() {
		wp_clear_scheduled_hook( self::MAINTENANCE_HOOK );
		wp_clear_scheduled_hook( self::AUTO_SCAN_HOOK );
		wp_clear_scheduled_hook( self::CONTINUE_HOOK );
	}

	/**
	 * Applies the retention settings.
	 *
	 * @return void
	 */
	public static function run_maintenance() {
		WPSTK_Scan_Store::prune();
		WPSTK_Not_Found_Monitor::prune();
	}

	/**
	 * Starts an automatic audit.
	 *
	 * @return void
	 */
	public static function run_auto_scan() {
		if ( 'disabled' === WPSTK_Settings::get( 'auto_scan', 'disabled' ) ) {
			return;
		}

		$audit = wpstk()->audit();

		if ( $audit->is_running() ) {
			return;
		}

		$started = $audit->start( 'cron' );

		if ( is_wp_error( $started ) ) {
			return;
		}

		self::continue_scan();
	}

	/**
	 * Runs as many batches as fit into a short time budget, then reschedules
	 * itself if the audit has not finished.
	 *
	 * @return void
	 */
	public static function continue_scan() {
		$audit = wpstk()->audit();

		if ( ! $audit->is_running() ) {
			return;
		}

		$progress = $audit->run_until( 20 );

		if ( is_wp_error( $progress ) || ! empty( $progress['done'] ) ) {
			return;
		}

		if ( ! wp_next_scheduled( self::CONTINUE_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CONTINUE_HOOK );
		}
	}
}
