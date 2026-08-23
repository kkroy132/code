<?php
/**
 * Time Report partial.
 *
 * Uses $ptp_filter_args and $ptp_projects from the including reports-page.php.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ptp_report = PTP_Reports_Service::get_time_report( $ptp_filter_args );

$ptp_task_titles = array();
foreach ( PTP_Tasks_Repository::get_list( array( 'view' => 'all', 'per_page' => 500 ) )['items'] as $ptp_task_row ) {
	$ptp_task_titles[ (int) $ptp_task_row->id ] = $ptp_task_row->title;
}
?>
<div class="ptp-card">
	<h2><?php esc_html_e( 'Time Report', 'personal-project-tracker' ); ?></h2>
	<div class="ptp-stats-grid">
		<div class="ptp-stat-tile">
			<span class="ptp-stat-value"><?php echo esc_html( ptp_format_duration( $ptp_report['total_seconds'] ) ); ?></span>
			<span class="ptp-stat-label"><?php esc_html_e( 'Total Time', 'personal-project-tracker' ); ?></span>
		</div>
		<div class="ptp-stat-tile">
			<span class="ptp-stat-value"><?php echo esc_html( count( $ptp_report['daily'] ) ); ?></span>
			<span class="ptp-stat-label"><?php esc_html_e( 'Days With Tracked Time', 'personal-project-tracker' ); ?></span>
		</div>
	</div>
</div>

<div class="ptp-detail-grid">
	<div class="ptp-detail-main">
		<div class="ptp-card">
			<h2><?php esc_html_e( 'Daily Time', 'personal-project-tracker' ); ?></h2>
			<?php if ( empty( $ptp_report['daily'] ) ) : ?>
				<p class="description"><?php esc_html_e( 'No tracked time in this range.', 'personal-project-tracker' ); ?></p>
			<?php else : ?>
				<table class="widefat striped ptp-status-table">
					<tbody>
						<?php foreach ( $ptp_report['daily'] as $ptp_date => $ptp_seconds ) : ?>
							<tr>
								<th scope="row"><?php echo esc_html( mysql2date( get_option( 'date_format' ), $ptp_date ) ); ?></th>
								<td><?php echo esc_html( ptp_format_duration( $ptp_seconds ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<div class="ptp-card">
			<h2><?php esc_html_e( 'Weekly Time', 'personal-project-tracker' ); ?></h2>
			<?php if ( empty( $ptp_report['weekly'] ) ) : ?>
				<p class="description"><?php esc_html_e( 'No tracked time in this range.', 'personal-project-tracker' ); ?></p>
			<?php else : ?>
				<table class="widefat striped ptp-status-table">
					<tbody>
						<?php foreach ( $ptp_report['weekly'] as $ptp_week_start => $ptp_seconds ) : ?>
							<tr>
								<th scope="row">
									<?php
									printf(
										/* translators: %s: week start date. */
										esc_html__( 'Week of %s', 'personal-project-tracker' ),
										esc_html( mysql2date( get_option( 'date_format' ), $ptp_week_start ) )
									);
									?>
								</th>
								<td><?php echo esc_html( ptp_format_duration( $ptp_seconds ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<div class="ptp-card">
			<h2><?php esc_html_e( 'Monthly Time', 'personal-project-tracker' ); ?></h2>
			<?php if ( empty( $ptp_report['monthly'] ) ) : ?>
				<p class="description"><?php esc_html_e( 'No tracked time in this range.', 'personal-project-tracker' ); ?></p>
			<?php else : ?>
				<table class="widefat striped ptp-status-table">
					<tbody>
						<?php foreach ( $ptp_report['monthly'] as $ptp_month => $ptp_seconds ) : ?>
							<tr>
								<th scope="row"><?php echo esc_html( mysql2date( 'F Y', $ptp_month . '-01' ) ); ?></th>
								<td><?php echo esc_html( ptp_format_duration( $ptp_seconds ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</div>

	<div class="ptp-detail-side">
		<div class="ptp-card">
			<h2><?php esc_html_e( 'Project Time', 'personal-project-tracker' ); ?></h2>
			<?php if ( empty( $ptp_report['by_project'] ) ) : ?>
				<p class="description"><?php esc_html_e( 'No tracked time attributed to a project.', 'personal-project-tracker' ); ?></p>
			<?php else : ?>
				<ul class="ptp-simple-list">
					<?php foreach ( $ptp_report['by_project'] as $ptp_pid => $ptp_seconds ) : ?>
						<li>
							<?php echo esc_html( $ptp_projects[ $ptp_pid ] ?? '#' . $ptp_pid ); ?>
							<span><?php echo esc_html( ptp_format_duration( $ptp_seconds ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>

		<div class="ptp-card">
			<h2><?php esc_html_e( 'Task Time', 'personal-project-tracker' ); ?></h2>
			<?php if ( empty( $ptp_report['by_task'] ) ) : ?>
				<p class="description"><?php esc_html_e( 'No tracked time attributed to a task.', 'personal-project-tracker' ); ?></p>
			<?php else : ?>
				<ul class="ptp-simple-list">
					<?php foreach ( $ptp_report['by_task'] as $ptp_tid => $ptp_seconds ) : ?>
						<li>
							<?php echo esc_html( $ptp_task_titles[ $ptp_tid ] ?? '#' . $ptp_tid ); ?>
							<span><?php echo esc_html( ptp_format_duration( $ptp_seconds ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	</div>
</div>
