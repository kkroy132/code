<?php
/**
 * Productivity Report partial.
 *
 * Uses $ptp_filter_args from the including reports-page.php.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ptp_report = PTP_Reports_Service::get_productivity_report( $ptp_filter_args );
?>
<div class="ptp-card">
	<h2><?php esc_html_e( 'Productivity Report', 'personal-project-tracker' ); ?></h2>
	<div class="ptp-stats-grid">
		<div class="ptp-stat-tile">
			<span class="ptp-stat-value"><?php echo esc_html( number_format_i18n( $ptp_report['tasks_completed'] ) ); ?></span>
			<span class="ptp-stat-label"><?php esc_html_e( 'Tasks Completed', 'personal-project-tracker' ); ?></span>
		</div>
		<div class="ptp-stat-tile">
			<span class="ptp-stat-value"><?php echo null !== $ptp_report['completion_rate'] ? esc_html( $ptp_report['completion_rate'] ) . '%' : '&#8212;'; ?></span>
			<span class="ptp-stat-label"><?php esc_html_e( 'Completion Rate', 'personal-project-tracker' ); ?></span>
		</div>
		<div class="ptp-stat-tile">
			<span class="ptp-stat-value"><?php echo esc_html( ptp_format_duration( $ptp_report['tracked_seconds'] ) ); ?></span>
			<span class="ptp-stat-label"><?php esc_html_e( 'Tracked Time', 'personal-project-tracker' ); ?></span>
		</div>
		<div class="ptp-stat-tile">
			<span class="ptp-stat-value"><?php echo esc_html( number_format_i18n( $ptp_report['active_days'] ) ); ?></span>
			<span class="ptp-stat-label"><?php esc_html_e( 'Active Days', 'personal-project-tracker' ); ?></span>
		</div>
		<div class="ptp-stat-tile">
			<span class="ptp-stat-value"><?php echo null !== $ptp_report['avg_tasks_completed_per_day'] ? esc_html( $ptp_report['avg_tasks_completed_per_day'] ) : '&#8212;'; ?></span>
			<span class="ptp-stat-label"><?php esc_html_e( 'Avg. Tasks Completed / Day', 'personal-project-tracker' ); ?></span>
		</div>
		<div class="ptp-stat-tile">
			<span class="ptp-stat-value"><?php echo null !== $ptp_report['avg_seconds_per_completed_task'] ? esc_html( ptp_format_duration( $ptp_report['avg_seconds_per_completed_task'] ) ) : '&#8212;'; ?></span>
			<span class="ptp-stat-label"><?php esc_html_e( 'Avg. Time / Completed Task', 'personal-project-tracker' ); ?></span>
		</div>
	</div>
</div>
