<?php
/**
 * Admin-side report export handling (CSV download).
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Reports_Controller
 *
 * Handles admin-post.php?action=ptp_export_report_csv, a plain GET-style
 * download link (secured the same way every other admin-post action in
 * this plugin is — check_admin_referer() reads the nonce from $_REQUEST,
 * so a query-string nonce works exactly like a form field). JSON export
 * needs no separate handler — the REST report endpoints already return
 * JSON. PDF is not wired up anywhere yet (see PTP_Reports_Export::to_pdf()).
 */
class PTP_Reports_Controller {

	/**
	 * Report types this controller knows how to shape for CSV, and the
	 * column order for each.
	 *
	 * @var array<string, string[]>
	 */
	const COLUMNS = array(
		'projects'     => array( 'project_id', 'title', 'status', 'progress', 'tasks_total', 'tasks_completed', 'tasks_overdue', 'milestones_total', 'milestones_completed', 'tracked_seconds', 'currency', 'revenue', 'expenses', 'profit' ),
		'tasks'        => array( 'total', 'completed', 'in_progress', 'overdue', 'completion_rate' ),
		'milestones'   => array( 'total', 'completed', 'in_progress', 'overdue' ),
		'time'         => array( 'date', 'seconds' ),
		'finance'      => array( 'currency', 'revenue', 'expenses', 'profit', 'profit_margin', 'budget', 'budget_usage' ),
		'productivity' => array( 'tasks_total', 'tasks_completed', 'completion_rate', 'tracked_seconds', 'active_days', 'avg_tasks_completed_per_day', 'avg_seconds_per_completed_task' ),
		'activity'     => array( 'action', 'object_type', 'object_id', 'description', 'created_at' ),
	);

	/**
	 * Handle the CSV export download.
	 */
	public static function handle_export_csv() {
		PTP_Security::check_admin_referer();
		PTP_Security::require_capability( 'ptp_manage_data' );

		$report = isset( $_GET['report'] ) ? sanitize_key( wp_unslash( $_GET['report'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! array_key_exists( $report, self::COLUMNS ) ) {
			wp_die( esc_html__( 'Unknown report type.', 'personal-project-tracker' ), esc_html__( 'Invalid request', 'personal-project-tracker' ), array( 'response' => 400 ) );
		}

		if ( 'finance' === $report ) {
			PTP_Security::require_capability( 'ptp_manage_finance' );
		}

		$args = array(
			'project_id' => isset( $_GET['project_id'] ) ? absint( $_GET['project_id'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'status'     => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'priority'   => isset( $_GET['priority'] ) ? sanitize_key( wp_unslash( $_GET['priority'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'category'   => isset( $_GET['category'] ) ? sanitize_text_field( wp_unslash( $_GET['category'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'date_from'  => isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'date_to'    => isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);

		list( $rows, $columns ) = self::rows_for_export( $report, $args );

		$csv = PTP_Reports_Export::to_csv( $rows, $columns );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ptp-' . $report . '-report-' . gmdate( 'Y-m-d' ) . '.csv"' );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw CSV content, not HTML markup.
		exit;
	}

	/**
	 * @param string $report Report type key.
	 * @param array  $args   Shared filters.
	 * @return array{0: array, 1: string[]} [rows, columns]
	 */
	private static function rows_for_export( $report, array $args ) {
		switch ( $report ) {
			case 'projects':
				// The Projects report includes revenue/expenses/profit —
				// Finance data that must stay private per ptp_manage_finance
				// everywhere else in the plugin (the 'finance' report type
				// above already requires it; this export must too, since the
				// export itself is only gated at ptp_manage_data).
				$data = PTP_Reports_Service::get_project_report( array_merge( $args, array( 'per_page' => 100 ) ), current_user_can( 'ptp_manage_finance' ) );
				return array( $data['items'], self::COLUMNS['projects'] );

			case 'tasks':
				return array( array( PTP_Reports_Service::get_task_report( $args ) ), self::COLUMNS['tasks'] );

			case 'milestones':
				return array( array( PTP_Reports_Service::get_milestone_report( $args ) ), self::COLUMNS['milestones'] );

			case 'time':
				$data = PTP_Reports_Service::get_time_report( $args );
				$rows = array();

				foreach ( $data['daily'] as $date => $seconds ) {
					$rows[] = array(
						'date'    => $date,
						'seconds' => $seconds,
					);
				}

				return array( $rows, self::COLUMNS['time'] );

			case 'finance':
				$data = PTP_Reports_Service::get_finance_report( $args );

				if ( isset( $data['by_currency'] ) ) {
					return array( array_values( $data['by_currency'] ), array( 'currency', 'revenue', 'expenses', 'profit', 'profit_margin' ) );
				}

				return array( array( $data ), self::COLUMNS['finance'] );

			case 'productivity':
				return array( array( PTP_Reports_Service::get_productivity_report( $args ) ), self::COLUMNS['productivity'] );

			case 'activity':
				$data = PTP_Reports_Service::get_activity_report( $args );
				return array( $data['recent'], self::COLUMNS['activity'] );

			default:
				return array( array(), array() );
		}
	}
}
