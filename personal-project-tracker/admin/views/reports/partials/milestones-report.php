<?php
/**
 * Milestone Report partial.
 *
 * Uses $ptp_filter_args from the including reports-page.php.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ptp_report = PTP_Reports_Service::get_milestone_report( $ptp_filter_args );
?>
<div class="ptp-card">
	<h2><?php esc_html_e( 'Milestone Report', 'personal-project-tracker' ); ?></h2>
	<div class="ptp-stats-grid">
		<div class="ptp-stat-tile">
			<span class="ptp-stat-value"><?php echo esc_html( number_format_i18n( $ptp_report['total'] ) ); ?></span>
			<span class="ptp-stat-label"><?php esc_html_e( 'Total', 'personal-project-tracker' ); ?></span>
		</div>
		<div class="ptp-stat-tile">
			<span class="ptp-stat-value"><?php echo esc_html( number_format_i18n( $ptp_report['completed'] ) ); ?></span>
			<span class="ptp-stat-label"><?php esc_html_e( 'Completed', 'personal-project-tracker' ); ?></span>
		</div>
		<div class="ptp-stat-tile">
			<span class="ptp-stat-value"><?php echo esc_html( number_format_i18n( $ptp_report['in_progress'] ) ); ?></span>
			<span class="ptp-stat-label"><?php esc_html_e( 'In Progress', 'personal-project-tracker' ); ?></span>
		</div>
		<div class="ptp-stat-tile ptp-stat-tile-warning">
			<span class="ptp-stat-value"><?php echo esc_html( number_format_i18n( $ptp_report['overdue'] ) ); ?></span>
			<span class="ptp-stat-label"><?php esc_html_e( 'Overdue', 'personal-project-tracker' ); ?></span>
		</div>
	</div>

	<?php if ( ! empty( $ptp_report['by_status'] ) ) : ?>
		<table class="widefat striped ptp-status-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Status', 'personal-project-tracker' ); ?></th>
					<th><?php esc_html_e( 'Count', 'personal-project-tracker' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php $ptp_statuses = PTP_Milestones_Repository::get_statuses(); ?>
				<?php foreach ( $ptp_report['by_status'] as $ptp_status_key => $ptp_count ) : ?>
					<tr>
						<td><span class="ptp-badge ptp-badge-status-<?php echo esc_attr( $ptp_status_key ); ?>"><?php echo esc_html( $ptp_statuses[ $ptp_status_key ] ?? $ptp_status_key ); ?></span></td>
						<td><?php echo esc_html( number_format_i18n( $ptp_count ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
