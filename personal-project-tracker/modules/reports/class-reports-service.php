<?php
/**
 * Centralized reporting service.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Reports_Service
 *
 * The single place report data is assembled. It never re-derives a
 * calculation another module already owns — it only calls each module's
 * existing (or newly added, additive) aggregate methods and shapes the
 * result for display/export:
 *  - Projects/Tasks/Milestones: their own get_stats()/get_report_counts().
 *  - Time: PTP_Time_Repository::get_report_totals().
 *  - Finance: PTP_Finance_Service (unchanged — already centralized in
 *    Phase 8, reused here rather than duplicated).
 *  - Activity: PTP_Activity_Log::get_report().
 * Every underlying query is an aggregate (COUNT/SUM/GROUP BY) bounded by
 * the given filters — this service never loads an unbounded set of raw
 * rows into PHP.
 */
class PTP_Reports_Service {

	/**
	 * Shared filter defaults every report accepts (a report ignores
	 * whichever of these don't apply to its own data — e.g. Time entries
	 * have no priority).
	 *
	 * @return array
	 */
	private static function default_args() {
		return array(
			'project_id' => 0,
			'status'     => '',
			'priority'   => '',
			'category'   => '',
			'date_from'  => '',
			'date_to'    => '',
		);
	}

	/**
	 * Project Report: progress, tasks, completed tasks, overdue tasks,
	 * milestones, tracked time, revenue, expenses, profit — per project.
	 * When project_id is given, returns that one project's row; otherwise
	 * returns a page of rows using the same bounded pagination as the
	 * Projects list everywhere else in the plugin.
	 *
	 * @param array $args Shared filters, plus paged/per_page when listing all projects.
	 * @return array{items: array, total: int, total_pages?: int, page?: int, per_page?: int}
	 */
	public static function get_project_report( array $args = array() ) {
		$args = wp_parse_args( $args, array_merge( self::default_args(), array( 'paged' => 1, 'per_page' => 20 ) ) );

		if ( $args['project_id'] ) {
			$project = PTP_Projects_Repository::get( $args['project_id'] );
			$row     = $project ? self::build_project_row( $project, $args ) : null;

			return array(
				'items' => $row ? array( $row ) : array(),
				'total' => $row ? 1 : 0,
			);
		}

		$list = PTP_Projects_Repository::get_list(
			array(
				'status'   => $args['status'],
				'priority' => $args['priority'],
				'paged'    => $args['paged'],
				'per_page' => $args['per_page'],
			)
		);

		$items = array();

		foreach ( $list['items'] as $project ) {
			$items[] = self::build_project_row( $project, $args );
		}

		return array(
			'items'       => $items,
			'total'       => $list['total'],
			'total_pages' => $list['total_pages'],
			'page'        => $list['page'],
			'per_page'    => $list['per_page'],
		);
	}

	/**
	 * @param object $project A project row.
	 * @param array  $args    Shared filters (date_from/date_to apply to tasks/milestones/time/finance).
	 * @return array
	 */
	private static function build_project_row( $project, array $args ) {
		$task_counts = PTP_Tasks_Repository::get_report_counts(
			array(
				'project_id' => $project->id,
				'date_from'  => $args['date_from'],
				'date_to'    => $args['date_to'],
				'view'       => 'all',
			)
		);

		$milestone_counts = PTP_Milestones_Repository::get_report_counts(
			array(
				'project_id' => $project->id,
				'date_from'  => $args['date_from'],
				'date_to'    => $args['date_to'],
				'view'       => 'all',
			)
		);

		$time_totals = PTP_Time_Repository::get_report_totals(
			array(
				'project_id' => $project->id,
				'date_from'  => $args['date_from'],
				'date_to'    => $args['date_to'],
			)
		);

		$finance = PTP_Finance_Service::get_project_summary(
			$project->id,
			array(
				'date_from' => $args['date_from'],
				'date_to'   => $args['date_to'],
			)
		);

		return array(
			'project_id'           => (int) $project->id,
			'title'                => $project->title,
			'status'               => $project->status,
			'progress'             => (int) $project->progress,
			'tasks_total'          => $task_counts['total'],
			'tasks_completed'      => $task_counts['completed'],
			'tasks_overdue'        => $task_counts['overdue'],
			'milestones_total'     => $milestone_counts['total'],
			'milestones_completed' => $milestone_counts['completed'],
			'tracked_seconds'      => $time_totals['total_seconds'],
			'currency'             => $finance['currency'] ?? null,
			'revenue'              => $finance['revenue'] ?? 0.0,
			'expenses'             => $finance['expenses'] ?? 0.0,
			'profit'               => $finance['profit'] ?? 0.0,
		);
	}

	/**
	 * Task Report: total, completed, in progress, overdue, completion rate.
	 *
	 * @param array $args Shared filters (project_id/priority/date range apply; status does not — this report already breaks totals down by status).
	 * @return array
	 */
	public static function get_task_report( array $args = array() ) {
		$args = wp_parse_args( $args, self::default_args() );

		return PTP_Tasks_Repository::get_report_counts(
			array(
				'project_id' => $args['project_id'],
				'priority'   => $args['priority'],
				'date_from'  => $args['date_from'],
				'date_to'    => $args['date_to'],
				'view'       => 'all',
			)
		);
	}

	/**
	 * Milestone Report: total, completed, in progress, overdue.
	 *
	 * @param array $args Shared filters (project_id/priority/date range apply).
	 * @return array
	 */
	public static function get_milestone_report( array $args = array() ) {
		$args = wp_parse_args( $args, self::default_args() );

		return PTP_Milestones_Repository::get_report_counts(
			array(
				'project_id' => $args['project_id'],
				'priority'   => $args['priority'],
				'date_from'  => $args['date_from'],
				'date_to'    => $args['date_to'],
				'view'       => 'all',
			)
		);
	}

	/**
	 * Time Report: total, daily, weekly, monthly, per-project, per-task.
	 * Weekly/monthly are rolled up in PHP from the (small, date-range-
	 * bounded) daily buckets rather than issuing more queries.
	 *
	 * @param array $args Shared filters (project_id/date range apply; also accepts task_id).
	 * @return array
	 */
	public static function get_time_report( array $args = array() ) {
		$args = wp_parse_args( $args, array_merge( self::default_args(), array( 'task_id' => 0 ) ) );

		$totals = PTP_Time_Repository::get_report_totals(
			array(
				'project_id' => $args['project_id'],
				'task_id'    => $args['task_id'],
				'date_from'  => $args['date_from'],
				'date_to'    => $args['date_to'],
			)
		);

		return array(
			'total_seconds' => $totals['total_seconds'],
			'daily'         => $totals['by_day'],
			'weekly'        => self::rollup_by_week( $totals['by_day'] ),
			'monthly'       => self::rollup_by_month( $totals['by_day'] ),
			'by_project'    => $totals['by_project'],
			'by_task'       => $totals['by_task'],
		);
	}

	/**
	 * @param array<string,int> $by_day 'Y-m-d' => seconds.
	 * @return array<string,int> Week-start 'Y-m-d' => seconds, respecting the site's start_of_week option.
	 */
	private static function rollup_by_week( array $by_day ) {
		$start_of_week = (int) get_option( 'start_of_week', 0 );
		$weeks         = array();

		foreach ( $by_day as $date => $seconds ) {
			$dt  = new DateTime( $date );
			$dow = (int) $dt->format( 'w' );
			$dt->modify( '-' . ( ( $dow - $start_of_week + 7 ) % 7 ) . ' days' );
			$key           = $dt->format( 'Y-m-d' );
			$weeks[ $key ] = ( $weeks[ $key ] ?? 0 ) + $seconds;
		}

		ksort( $weeks );

		return $weeks;
	}

	/**
	 * @param array<string,int> $by_day 'Y-m-d' => seconds.
	 * @return array<string,int> 'Y-m' => seconds.
	 */
	private static function rollup_by_month( array $by_day ) {
		$months = array();

		foreach ( $by_day as $date => $seconds ) {
			$key            = substr( $date, 0, 7 );
			$months[ $key ] = ( $months[ $key ] ?? 0 ) + $seconds;
		}

		ksort( $months );

		return $months;
	}

	/**
	 * Finance Report: revenue, expenses, profit, profit margin, budget,
	 * budget usage. Delegates entirely to PTP_Finance_Service (Phase 8) —
	 * no financial calculation is re-derived here.
	 *
	 * @param array $args Shared filters (project_id/category/date range apply).
	 * @return array
	 */
	public static function get_finance_report( array $args = array() ) {
		$args = wp_parse_args( $args, self::default_args() );

		if ( $args['project_id'] ) {
			return PTP_Finance_Service::get_project_summary(
				$args['project_id'],
				array(
					'category'  => $args['category'],
					'date_from' => $args['date_from'],
					'date_to'   => $args['date_to'],
				)
			);
		}

		return PTP_Finance_Service::get_report( $args );
	}

	/**
	 * Productivity Report: a derived view combining the Task and Time
	 * reports — no new calculation beyond simple ratios of numbers those
	 * two reports already computed.
	 *
	 * @param array $args Shared filters (project_id/date range apply).
	 * @return array
	 */
	public static function get_productivity_report( array $args = array() ) {
		$args = wp_parse_args( $args, self::default_args() );

		$tasks = self::get_task_report( $args );
		$time  = self::get_time_report( $args );

		$active_days = count( $time['daily'] );

		return array(
			'tasks_total'                   => $tasks['total'],
			'tasks_completed'               => $tasks['completed'],
			'completion_rate'               => $tasks['completion_rate'],
			'tracked_seconds'               => $time['total_seconds'],
			'active_days'                   => $active_days,
			'avg_tasks_completed_per_day'   => $active_days > 0 ? round( $tasks['completed'] / $active_days, 2 ) : null,
			'avg_seconds_per_completed_task' => $tasks['completed'] > 0 ? (int) round( $time['total_seconds'] / $tasks['completed'] ) : null,
		);
	}

	/**
	 * Activity Report: a per-action count breakdown plus a small capped
	 * list of recent matching activity.
	 *
	 * @param array $args Shared filters (date range applies), plus object_type/limit.
	 * @return array
	 */
	public static function get_activity_report( array $args = array() ) {
		$args = wp_parse_args( $args, array_merge( self::default_args(), array( 'object_type' => '', 'limit' => 20 ) ) );

		return PTP_Activity_Log::get_report(
			array(
				'object_type' => $args['object_type'],
				'date_from'   => $args['date_from'],
				'date_to'     => $args['date_to'],
				'limit'       => $args['limit'],
			)
		);
	}
}
