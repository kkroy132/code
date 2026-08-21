<?php
/**
 * Action Scheduler integration.
 *
 * Every background job in this plugin goes through Action Scheduler; native
 * wp-cron is never used. Action Scheduler is bundled in
 * `vendor/action-scheduler/` and registers itself on `plugins_loaded` at
 * priority 0, so self::load_library() must run while the main plugin file is
 * still being included.
 *
 * @package LWBLC
 */

namespace LWBLC;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around the Action Scheduler API.
 */
class Scheduler {

	/**
	 * Group all actions of this plugin share.
	 */
	const GROUP = 'lwblc';

	/**
	 * Hook that processes one batch of posts during a scan.
	 */
	const HOOK_SCAN_BATCH = 'lwblc_scan_batch';

	/**
	 * Hook that rescans a single post after it was saved.
	 */
	const HOOK_SCAN_POST = 'lwblc_scan_post';

	/**
	 * Hook that continues an unfinished schema migration.
	 */
	const HOOK_MIGRATE = 'lwblc_migrate';

	/**
	 * Hook that checks one batch of links.
	 */
	const HOOK_CHECK_BATCH = 'lwblc_check_batch';

	/**
	 * Loads the bundled Action Scheduler library.
	 *
	 * Safe to call when another plugin already loaded its own copy: Action
	 * Scheduler keeps a version registry and boots the newest one only.
	 *
	 * @return void
	 */
	public static function load_library() {
		$library = LWBLC_DIR . 'vendor/action-scheduler/action-scheduler.php';

		if ( is_readable( $library ) ) {
			require_once $library;
		}
	}

	/**
	 * Whether the Action Scheduler API is usable.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return function_exists( 'as_schedule_single_action' )
			&& function_exists( 'as_schedule_recurring_action' )
			&& function_exists( 'as_unschedule_all_actions' )
			&& function_exists( 'as_has_scheduled_action' );
	}

	/**
	 * Schedules a one-off action.
	 *
	 * @param int    $timestamp Unix timestamp (UTC) to run at.
	 * @param string $hook      Hook name.
	 * @param array  $args      Callback arguments.
	 * @param bool   $unique    Skip scheduling when the same action is already pending.
	 * @return int Action ID, or 0 on failure.
	 */
	public static function schedule_single( $timestamp, $hook, $args = array(), $unique = false ) {
		if ( ! self::is_available() ) {
			return 0;
		}

		return (int) as_schedule_single_action( $timestamp, $hook, $args, self::GROUP, (bool) $unique );
	}

	/**
	 * Schedules a recurring action if it is not scheduled already.
	 *
	 * @param int    $timestamp Unix timestamp (UTC) of the first run.
	 * @param int    $interval  Interval in seconds.
	 * @param string $hook      Hook name.
	 * @param array  $args      Callback arguments.
	 * @return int Action ID, or 0 when unavailable or already scheduled.
	 */
	public static function schedule_recurring( $timestamp, $interval, $hook, $args = array() ) {
		if ( ! self::is_available() || self::has_scheduled( $hook, $args ) ) {
			return 0;
		}

		return (int) as_schedule_recurring_action( $timestamp, $interval, $hook, $args, self::GROUP );
	}

	/**
	 * Whether a pending or running action exists for a hook.
	 *
	 * @param string $hook Hook name.
	 * @param array  $args Callback arguments, or null for any.
	 * @return bool
	 */
	public static function has_scheduled( $hook, $args = array() ) {
		if ( ! self::is_available() ) {
			return false;
		}

		return (bool) as_has_scheduled_action( $hook, $args, self::GROUP );
	}

	/**
	 * Timestamp of the next run of a hook.
	 *
	 * @param string $hook Hook name.
	 * @return int Unix timestamp (UTC), or 0 when nothing is scheduled.
	 */
	public static function next_run( $hook ) {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return 0;
		}

		$next = as_next_scheduled_action( $hook, null, self::GROUP );

		return is_numeric( $next ) ? (int) $next : 0;
	}

	/**
	 * Cancels every scheduled action for a hook.
	 *
	 * @param string $hook Hook name.
	 * @return void
	 */
	public static function unschedule( $hook ) {
		if ( ! self::is_available() ) {
			return;
		}

		as_unschedule_all_actions( $hook, null, self::GROUP );
	}

	/**
	 * Cancels every scheduled action this plugin owns.
	 *
	 * @return void
	 */
	public static function unschedule_all() {
		self::unschedule( self::HOOK_SCAN_BATCH );
		self::unschedule( self::HOOK_SCAN_POST );
		self::unschedule( self::HOOK_CHECK_BATCH );
		self::unschedule( self::HOOK_MIGRATE );
	}
}
