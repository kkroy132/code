<?php
/**
 * Central notification delivery gating: preferences, quiet hours, deep
 * links, and snooze-duration resolution.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Notifications_Service
 *
 * The single place that decides whether a would-be notification actually
 * gets created. Every notification source (reminders firing, smart alerts)
 * must go through maybe_notify() rather than calling
 * PTP_Notifications_Repository::create_deduped() directly, so the global
 * ON/OFF switch, per-category preferences, and Quiet Hours are enforced
 * consistently in one place. During Quiet Hours a would-be notification is
 * never dropped — it is simply not created yet; the reminder/alert that
 * would have produced it remains due and is picked up again on the next
 * cron pass once Quiet Hours end, so nothing is silently lost.
 */
class PTP_Notifications_Service {

	/**
	 * Whether $category is currently allowed to notify, per the global
	 * switch and the per-category preference. Does not consider Quiet
	 * Hours — call in_quiet_hours() separately where deferral matters.
	 *
	 * @param string $category One of PTP_Notifications_Repository::get_categories().
	 * @return bool
	 */
	public static function category_enabled( $category ) {
		if ( ! PTP_Settings::get( 'notifications_enabled', true ) ) {
			return false;
		}

		$categories = PTP_Settings::get( 'notification_categories', array() );

		return ! isset( $categories[ $category ] ) || (bool) $categories[ $category ];
	}

	/**
	 * Whether right now falls inside the configured Quiet Hours window.
	 * Handles a window that spans midnight (e.g. 22:00-07:00).
	 *
	 * @return bool
	 */
	public static function in_quiet_hours() {
		if ( ! PTP_Settings::get( 'quiet_hours_enabled', false ) ) {
			return false;
		}

		$start = (string) PTP_Settings::get( 'quiet_hours_start', '22:00' );
		$end   = (string) PTP_Settings::get( 'quiet_hours_end', '07:00' );
		$now   = current_time( 'H:i' );

		if ( $start === $end ) {
			return false;
		}

		if ( $start < $end ) {
			return $now >= $start && $now < $end;
		}

		// Overnight window, e.g. 22:00 to 07:00.
		return $now >= $start || $now < $end;
	}

	/**
	 * Create a notification if — and only if — its category is enabled and
	 * we are not currently in Quiet Hours. This is the single entry point
	 * every notification source should call.
	 *
	 * @param array $data See PTP_Notifications_Repository::create_deduped().
	 * @return int|false|'quiet_hours' New notification ID; false if the
	 *                                 category is disabled or this exact
	 *                                 occurrence was already notified
	 *                                 (safe to treat as "handled"); or the
	 *                                 string 'quiet_hours' if deferred —
	 *                                 callers driving a reminder's
	 *                                 lifecycle must NOT advance/complete
	 *                                 it in that case, so it is retried
	 *                                 on the next cron pass instead of lost.
	 */
	public static function maybe_notify( array $data ) {
		$category = $data['category'] ?? 'custom';

		if ( ! self::category_enabled( $category ) ) {
			return false;
		}

		if ( self::in_quiet_hours() ) {
			return 'quiet_hours';
		}

		return PTP_Notifications_Repository::create_deduped( $data );
	}

	/**
	 * Map a related_type/related_id pair to the admin URL a notification
	 * click should open.
	 *
	 * @param string|null $related_type Related object type.
	 * @param int|null    $related_id   Related object ID.
	 * @return string
	 */
	public static function get_deep_link( $related_type, $related_id ) {
		$notifications_url = add_query_arg( array( 'page' => 'ptp-notifications' ), admin_url( 'admin.php' ) );

		if ( ! $related_type || ! $related_id ) {
			return $notifications_url;
		}

		$map = array(
			'project'        => array( 'page' => 'ptp-projects', 'action' => 'view' ),
			'task'           => array( 'page' => 'ptp-tasks', 'action' => 'view' ),
			'milestone'      => array( 'page' => 'ptp-milestones', 'action' => 'view' ),
			'calendar_event' => array( 'page' => 'ptp-calendar', 'action' => 'view' ),
			'expense'        => array( 'page' => 'ptp-finance', 'action' => 'view_expense' ),
			'revenue'        => array( 'page' => 'ptp-finance', 'action' => 'view_revenue' ),
			'reminder'       => array( 'page' => 'ptp-notifications', 'action' => 'edit_reminder' ),
		);

		if ( ! isset( $map[ $related_type ] ) ) {
			return $notifications_url;
		}

		return add_query_arg(
			array_merge( $map[ $related_type ], array( 'id' => (int) $related_id ) ),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Resolve a SNOOZE preset (or a custom minute count) to an absolute
	 * 'Y-m-d H:i:s' datetime.
	 *
	 * @param string $preset          One of '10m','30m','1h','3h','tomorrow','custom'.
	 * @param int    $custom_minutes  Minutes to snooze for when $preset is 'custom'.
	 * @param string $from            'Y-m-d H:i:s' to snooze relative to (defaults to now); 'tomorrow' uses this datetime's own time-of-day on the next day.
	 * @return string 'Y-m-d H:i:s'
	 */
	public static function resolve_snooze_until( $preset, $custom_minutes = 0, $from = '' ) {
		$from = $from ? $from : current_time( 'mysql' );

		$minute_presets = array(
			'10m' => 10,
			'30m' => 30,
			'1h'  => 60,
			'3h'  => 180,
		);

		if ( isset( $minute_presets[ $preset ] ) ) {
			return gmdate( 'Y-m-d H:i:s', strtotime( $from . ' +' . $minute_presets[ $preset ] . ' minutes' ) );
		}

		if ( 'tomorrow' === $preset ) {
			return gmdate( 'Y-m-d H:i:s', strtotime( $from . ' +1 day' ) );
		}

		// 'custom' (or any unrecognized preset) falls back to an explicit minute count.
		$minutes = max( 1, (int) $custom_minutes );

		return gmdate( 'Y-m-d H:i:s', strtotime( $from . ' +' . $minutes . ' minutes' ) );
	}

	/**
	 * Ensure every calendar event with reminder_minutes configured has a
	 * matching `reminders` row. Read-only against Calendar (which owns
	 * event data); this is the sync step that activates the reminder_minutes
	 * integration point Calendar has carried since Phase 5. Idempotent: an
	 * event's [id, computed remind_at] pair is checked before insert, so
	 * re-running this never creates duplicate reminders, and rescheduling
	 * an event (changing start_datetime) naturally produces a fresh
	 * reminder at the new time without touching the old (already-fired or
	 * now-irrelevant) one.
	 */
	public static function sync_calendar_reminders() {
		if ( ! class_exists( 'PTP_Calendar_Repository' ) ) {
			return;
		}

		foreach ( PTP_Calendar_Repository::get_events_with_reminders() as $event ) {
			$remind_at = gmdate( 'Y-m-d H:i:s', strtotime( $event->start_datetime . ' -' . (int) $event->reminder_minutes . ' minutes' ) );

			if ( self::calendar_reminder_exists( $event->id, $remind_at ) ) {
				continue;
			}

			PTP_Reminders_Repository::create(
				array(
					'title'        => $event->title,
					'related_type' => 'calendar_event',
					'related_id'   => $event->id,
					'remind_at'    => $remind_at,
					'recurrence'   => 'none',
				)
			);
		}
	}

	/**
	 * @param int    $event_id  Calendar event ID.
	 * @param string $remind_at 'Y-m-d H:i:s' computed reminder time.
	 * @return bool
	 */
	private static function calendar_reminder_exists( $event_id, $remind_at ) {
		$existing = PTP_Reminders_Repository::get_list(
			array(
				'related_type' => 'calendar_event',
				'per_page'     => 100,
			)
		);

		foreach ( $existing['items'] as $reminder ) {
			if ( (int) $reminder->related_id === (int) $event_id && $reminder->remind_at === $remind_at ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Map a reminder's related_type to a notification category, for
	 * reminders that fire into an actual notification.
	 *
	 * @param string|null $related_type Reminder's related_type.
	 * @return string
	 */
	public static function category_for_related_type( $related_type ) {
		$map = array(
			'project'        => 'projects',
			'task'           => 'tasks',
			'milestone'      => 'milestones',
			'calendar_event' => 'calendar',
		);

		return $map[ $related_type ] ?? 'custom';
	}
}
