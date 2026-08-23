<?php
/**
 * Rule-based Smart Alerts engine.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Smart_Alerts_Service
 *
 * Ten fixed, rule-based checks — plain comparisons against data every
 * other module already owns (get_overdue(), get_deadlines_in_range(),
 * PTP_Finance_Service's alert flags, ...). Nothing here is AI: every rule
 * is a deterministic threshold check run on a schedule (ptp_smart_alerts_check,
 * hourly). Each rule computes a stable dedup_key per object and a cooldown
 * window, then calls PTP_Notifications_Service::maybe_notify() — so a
 * condition that is still true on the next hourly run does not re-notify
 * until its cooldown has elapsed, and never notifies at all while it is
 * false.
 */
class PTP_Smart_Alerts_Service {

	/**
	 * Default cooldown (hours) for a rule re-check: roughly once per day.
	 *
	 * @var int
	 */
	const DEFAULT_COOLDOWN_HOURS = 20;

	/**
	 * Run every rule. Safe to call repeatedly (e.g. from the hourly cron,
	 * or a manual "check now") — cooldowns and category/quiet-hours gating
	 * prevent duplicate or unwanted notifications either way.
	 */
	public static function run_all() {
		if ( ! PTP_Settings::get( 'smart_alerts_enabled', true ) ) {
			return;
		}

		self::check_tasks_overdue();
		self::check_tasks_deadline_approaching();
		self::check_milestones_deadline_approaching();
		self::check_projects_deadline_approaching();
		self::check_project_inactivity();
		self::check_budget_warning_and_over_budget();
		self::check_negative_profit();
		self::check_long_running_timers();
		self::check_too_many_overdue_tasks();
	}

	/**
	 * Rule: Task overdue.
	 */
	private static function check_tasks_overdue() {
		if ( ! PTP_Settings::get( 'overdue_task_alerts', true ) ) {
			return;
		}

		foreach ( PTP_Tasks_Repository::get_overdue() as $task ) {
			PTP_Notifications_Service::maybe_notify(
				array(
					'type'           => 'task_overdue',
					'title'          => __( 'Task overdue', 'personal-project-tracker' ),
					/* translators: 1: task title, 2: due date. */
					'message'        => sprintf( __( 'Task "%1$s" was due on %2$s.', 'personal-project-tracker' ), $task->title, $task->due_date ),
					'category'       => 'smart_alerts',
					'related_type'   => 'task',
					'related_id'     => $task->id,
					'dedup_key'      => 'smart_task_overdue_' . $task->id,
					'cooldown_hours' => self::DEFAULT_COOLDOWN_HOURS,
				)
			);
		}
	}

	/**
	 * Rule: Task deadline approaching.
	 */
	private static function check_tasks_deadline_approaching() {
		$days  = max( 1, (int) PTP_Settings::get( 'upcoming_deadline_days', 3 ) );
		$today = current_time( 'Y-m-d' );
		$until = gmdate( 'Y-m-d', strtotime( $today . ' +' . $days . ' days' ) );

		foreach ( PTP_Tasks_Repository::get_deadlines_in_range( $today, $until ) as $task ) {
			if ( in_array( $task->status, array( 'completed', 'cancelled' ), true ) ) {
				continue;
			}

			PTP_Notifications_Service::maybe_notify(
				array(
					'type'           => 'task_deadline_approaching',
					'title'          => __( 'Task deadline approaching', 'personal-project-tracker' ),
					/* translators: 1: task title, 2: due date. */
					'message'        => sprintf( __( 'Task "%1$s" is due on %2$s.', 'personal-project-tracker' ), $task->title, $task->due_date ),
					'category'       => 'smart_alerts',
					'related_type'   => 'task',
					'related_id'     => $task->id,
					'dedup_key'      => 'smart_task_upcoming_' . $task->id . '_' . $task->due_date,
					'cooldown_hours' => self::DEFAULT_COOLDOWN_HOURS,
				)
			);
		}
	}

	/**
	 * Rule: Milestone deadline approaching.
	 */
	private static function check_milestones_deadline_approaching() {
		$days  = max( 1, (int) PTP_Settings::get( 'upcoming_deadline_days', 3 ) );
		$today = current_time( 'Y-m-d' );
		$until = gmdate( 'Y-m-d', strtotime( $today . ' +' . $days . ' days' ) );

		foreach ( PTP_Milestones_Repository::get_deadlines_in_range( $today, $until ) as $milestone ) {
			if ( in_array( $milestone->status, array( 'completed', 'cancelled' ), true ) ) {
				continue;
			}

			PTP_Notifications_Service::maybe_notify(
				array(
					'type'           => 'milestone_deadline_approaching',
					'title'          => __( 'Milestone deadline approaching', 'personal-project-tracker' ),
					/* translators: 1: milestone title, 2: due date. */
					'message'        => sprintf( __( 'Milestone "%1$s" is due on %2$s.', 'personal-project-tracker' ), $milestone->title, $milestone->due_date ),
					'category'       => 'smart_alerts',
					'related_type'   => 'milestone',
					'related_id'     => $milestone->id,
					'dedup_key'      => 'smart_milestone_upcoming_' . $milestone->id . '_' . $milestone->due_date,
					'cooldown_hours' => self::DEFAULT_COOLDOWN_HOURS,
				)
			);
		}
	}

	/**
	 * Rule: Project deadline approaching.
	 */
	private static function check_projects_deadline_approaching() {
		$days  = max( 1, (int) PTP_Settings::get( 'upcoming_deadline_days', 3 ) );
		$today = current_time( 'Y-m-d' );
		$until = gmdate( 'Y-m-d', strtotime( $today . ' +' . $days . ' days' ) );

		foreach ( PTP_Projects_Repository::get_deadlines_in_range( $today, $until ) as $project ) {
			if ( in_array( $project->status, array( 'completed', 'cancelled', 'archived' ), true ) ) {
				continue;
			}

			PTP_Notifications_Service::maybe_notify(
				array(
					'type'           => 'project_deadline_approaching',
					'title'          => __( 'Project deadline approaching', 'personal-project-tracker' ),
					/* translators: 1: project title, 2: deadline. */
					'message'        => sprintf( __( 'Project "%1$s" is due on %2$s.', 'personal-project-tracker' ), $project->title, $project->deadline ),
					'category'       => 'smart_alerts',
					'related_type'   => 'project',
					'related_id'     => $project->id,
					'dedup_key'      => 'smart_project_upcoming_' . $project->id . '_' . $project->deadline,
					'cooldown_hours' => self::DEFAULT_COOLDOWN_HOURS,
				)
			);
		}
	}

	/**
	 * Rule: Project inactivity.
	 */
	private static function check_project_inactivity() {
		$days = max( 1, (int) PTP_Settings::get( 'project_inactivity_days', 14 ) );

		foreach ( PTP_Projects_Repository::get_inactive( $days ) as $project ) {
			PTP_Notifications_Service::maybe_notify(
				array(
					'type'           => 'project_inactivity',
					'title'          => __( 'Project inactive', 'personal-project-tracker' ),
					/* translators: 1: project title, 2: number of days. */
					'message'        => sprintf( __( 'Project "%1$s" has had no updates in %2$d+ days.', 'personal-project-tracker' ), $project->title, $days ),
					'category'       => 'smart_alerts',
					'related_type'   => 'project',
					'related_id'     => $project->id,
					'dedup_key'      => 'smart_project_inactive_' . $project->id,
					'cooldown_hours' => self::DEFAULT_COOLDOWN_HOURS * 7,
				)
			);
		}
	}

	/**
	 * Rules: Budget warning + Over budget. Financial figures are never put
	 * in the notification text; category is 'finance' so both the
	 * preference toggle and the ptp_manage_finance visibility gate apply —
	 * the same privacy rule Finance data follows everywhere else.
	 */
	private static function check_budget_warning_and_over_budget() {
		if ( ! class_exists( 'PTP_Finance_Service' ) ) {
			return;
		}

		foreach ( PTP_Projects_Repository::get_list( array( 'per_page' => 200 ) )['items'] as $project ) {
			if ( in_array( $project->status, array( 'completed', 'cancelled', 'archived' ), true ) ) {
				continue;
			}

			$summary = PTP_Finance_Service::get_project_summary( $project->id );

			if ( ! $summary ) {
				continue;
			}

			if ( $summary['flags']['over_budget'] ) {
				PTP_Notifications_Service::maybe_notify(
					array(
						'type'           => 'over_budget',
						'title'          => __( 'Project over budget', 'personal-project-tracker' ),
						/* translators: %s: project title. */
						'message'        => sprintf( __( 'Project "%s" has gone over its budget. View Finance for details.', 'personal-project-tracker' ), $project->title ),
						'category'       => 'finance',
						'related_type'   => 'project',
						'related_id'     => $project->id,
						'dedup_key'      => 'smart_over_budget_' . $project->id,
						'cooldown_hours' => self::DEFAULT_COOLDOWN_HOURS,
					)
				);
			} elseif ( $summary['flags']['budget_warning'] ) {
				PTP_Notifications_Service::maybe_notify(
					array(
						'type'           => 'budget_warning',
						'title'          => __( 'Approaching budget limit', 'personal-project-tracker' ),
						/* translators: %s: project title. */
						'message'        => sprintf( __( 'Project "%s" is approaching its budget limit. View Finance for details.', 'personal-project-tracker' ), $project->title ),
						'category'       => 'finance',
						'related_type'   => 'project',
						'related_id'     => $project->id,
						'dedup_key'      => 'smart_budget_warning_' . $project->id,
						'cooldown_hours' => self::DEFAULT_COOLDOWN_HOURS,
					)
				);
			}
		}
	}

	/**
	 * Rule: Negative profit.
	 */
	private static function check_negative_profit() {
		if ( ! class_exists( 'PTP_Finance_Service' ) ) {
			return;
		}

		foreach ( PTP_Projects_Repository::get_list( array( 'per_page' => 200 ) )['items'] as $project ) {
			if ( in_array( $project->status, array( 'completed', 'cancelled', 'archived' ), true ) ) {
				continue;
			}

			$summary = PTP_Finance_Service::get_project_summary( $project->id );

			if ( $summary && $summary['flags']['negative_profit'] ) {
				PTP_Notifications_Service::maybe_notify(
					array(
						'type'           => 'negative_profit',
						'title'          => __( 'Project running at a loss', 'personal-project-tracker' ),
						/* translators: %s: project title. */
						'message'        => sprintf( __( 'Project "%s" is currently running at a loss. View Finance for details.', 'personal-project-tracker' ), $project->title ),
						'category'       => 'finance',
						'related_type'   => 'project',
						'related_id'     => $project->id,
						'dedup_key'      => 'smart_negative_profit_' . $project->id,
						'cooldown_hours' => self::DEFAULT_COOLDOWN_HOURS,
					)
				);
			}
		}
	}

	/**
	 * Rule: Long-running timer.
	 */
	private static function check_long_running_timers() {
		$hours = max( 1, (int) PTP_Settings::get( 'long_running_timer_hours', 4 ) );

		foreach ( PTP_Time_Repository::get_long_running( $hours ) as $entry ) {
			PTP_Notifications_Service::maybe_notify(
				array(
					'type'           => 'long_running_timer',
					'title'          => __( 'Timer still running', 'personal-project-tracker' ),
					/* translators: %d: hours. */
					'message'        => sprintf( __( 'A timer has been running for over %d hours.', 'personal-project-tracker' ), $hours ),
					'category'       => 'time',
					'related_type'   => 'task',
					'related_id'     => $entry->task_id ? (int) $entry->task_id : 0,
					'dedup_key'      => 'smart_long_timer_' . $entry->id,
					'cooldown_hours' => $hours,
				)
			);
		}
	}

	/**
	 * Rule: Too many overdue tasks (a single, non-object-scoped alert).
	 */
	private static function check_too_many_overdue_tasks() {
		$threshold = max( 1, (int) PTP_Settings::get( 'too_many_overdue_threshold', 5 ) );
		$overdue   = PTP_Tasks_Repository::get_stats()['overdue'];

		if ( $overdue < $threshold ) {
			return;
		}

		PTP_Notifications_Service::maybe_notify(
			array(
				'type'           => 'too_many_overdue_tasks',
				'title'          => __( 'Too many overdue tasks', 'personal-project-tracker' ),
				/* translators: %d: number of overdue tasks. */
				'message'        => sprintf( __( 'You have %d overdue tasks.', 'personal-project-tracker' ), $overdue ),
				'category'       => 'smart_alerts',
				'related_type'   => '',
				'related_id'     => 0,
				'dedup_key'      => 'smart_too_many_overdue',
				'cooldown_hours' => self::DEFAULT_COOLDOWN_HOURS,
			)
		);
	}
}
