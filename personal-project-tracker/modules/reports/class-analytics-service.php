<?php
/**
 * Centralized analytics dashboard service.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Analytics_Service
 *
 * The Analytics dashboard is a compact, curated read over the same
 * aggregate data PTP_Reports_Service already assembles — it never
 * recomputes a figure. Financial figures (revenue/expenses/profit) are
 * only included when the caller explicitly confirms the viewer holds
 * ptp_manage_finance (checked by the REST controller / admin view, not
 * here, so this service stays free of capability-check side effects);
 * pass $include_finance = false to omit them.
 */
class PTP_Analytics_Service {

	/**
	 * Build the Analytics dashboard payload: Project Progress, Task
	 * Completion, Time Trends, Overdue Tasks, and (optionally) Revenue/
	 * Expenses/Profit.
	 *
	 * @param array $args {
	 *     @type string $date_from Defaults to 13 days before $date_to.
	 *     @type string $date_to   Defaults to today.
	 * }
	 * @param bool  $include_finance Whether to include the finance section.
	 * @return array
	 */
	public static function get_dashboard( array $args = array(), $include_finance = true ) {
		$date_to   = self::sanitize_date( $args['date_to'] ?? '' ) ?: current_time( 'Y-m-d' );
		$date_from = self::sanitize_date( $args['date_from'] ?? '' ) ?: gmdate( 'Y-m-d', strtotime( $date_to . ' -13 days' ) );

		$range = array(
			'date_from' => $date_from,
			'date_to'   => $date_to,
		);

		$task_report = PTP_Reports_Service::get_task_report( $range );
		$time_report = PTP_Reports_Service::get_time_report( $range );

		$dashboard = array(
			'date_from'        => $date_from,
			'date_to'          => $date_to,
			'project_progress' => array(
				'average_percent' => PTP_Projects_Repository::get_average_progress(),
				'total_projects'  => PTP_Projects_Repository::get_stats()['total'],
			),
			'task_completion'  => array(
				'total'           => $task_report['total'],
				'completed'       => $task_report['completed'],
				'completion_rate' => $task_report['completion_rate'],
				'by_status'       => $task_report['by_status'],
			),
			'overdue_tasks'    => $task_report['overdue'],
			'time_trends'      => array(
				'total_seconds' => $time_report['total_seconds'],
				'daily'         => $time_report['daily'],
			),
		);

		if ( $include_finance ) {
			$dashboard['finance'] = PTP_Reports_Service::get_finance_report( $range );
		}

		return $dashboard;
	}

	/**
	 * @param string $value Raw date string.
	 * @return string|null 'Y-m-d' formatted date, or null when empty/invalid.
	 */
	private static function sanitize_date( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return null;
		}

		$parsed = DateTime::createFromFormat( 'Y-m-d', $value );

		return ( $parsed && $parsed->format( 'Y-m-d' ) === $value ) ? $value : null;
	}
}
