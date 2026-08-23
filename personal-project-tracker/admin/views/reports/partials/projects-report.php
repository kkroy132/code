<?php
/**
 * Project Report partial.
 *
 * Uses $ptp_filter_args from the including reports-page.php.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ptp_paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_report = PTP_Reports_Service::get_project_report( array_merge( $ptp_filter_args, array( 'paged' => $ptp_paged, 'per_page' => 20 ) ), $ptp_can_view_finance );
?>
<div class="ptp-card">
	<h2><?php esc_html_e( 'Project Report', 'personal-project-tracker' ); ?></h2>

	<?php if ( empty( $ptp_report['items'] ) ) : ?>
		<div class="ptp-empty-state">
			<span class="dashicons dashicons-portfolio"></span>
			<p><?php esc_html_e( 'No projects match the current filters.', 'personal-project-tracker' ); ?></p>
		</div>
	<?php else : ?>
		<div class="ptp-table-responsive">
			<table class="widefat striped ptp-report-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Project', 'personal-project-tracker' ); ?></th>
						<th><?php esc_html_e( 'Progress', 'personal-project-tracker' ); ?></th>
						<th><?php esc_html_e( 'Tasks', 'personal-project-tracker' ); ?></th>
						<th><?php esc_html_e( 'Completed', 'personal-project-tracker' ); ?></th>
						<th><?php esc_html_e( 'Overdue', 'personal-project-tracker' ); ?></th>
						<th class="ptp-col-optional"><?php esc_html_e( 'Milestones', 'personal-project-tracker' ); ?></th>
						<th class="ptp-col-optional"><?php esc_html_e( 'Tracked Time', 'personal-project-tracker' ); ?></th>
						<th class="ptp-col-optional"><?php esc_html_e( 'Revenue', 'personal-project-tracker' ); ?></th>
						<th class="ptp-col-optional"><?php esc_html_e( 'Expenses', 'personal-project-tracker' ); ?></th>
						<th><?php esc_html_e( 'Profit', 'personal-project-tracker' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $ptp_report['items'] as $ptp_row ) : ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-projects', 'action' => 'view', 'id' => $ptp_row['project_id'] ), admin_url( 'admin.php' ) ) ); ?>">
									<?php echo esc_html( $ptp_row['title'] ); ?>
								</a>
							</td>
							<td><?php echo esc_html( $ptp_row['progress'] ); ?>%</td>
							<td><?php echo esc_html( $ptp_row['tasks_total'] ); ?></td>
							<td><?php echo esc_html( $ptp_row['tasks_completed'] ); ?></td>
							<td><span class="<?php echo esc_attr( $ptp_row['tasks_overdue'] > 0 ? 'ptp-text-danger' : '' ); ?>"><?php echo esc_html( $ptp_row['tasks_overdue'] ); ?></span></td>
							<td class="ptp-col-optional"><?php echo esc_html( $ptp_row['milestones_completed'] . ' / ' . $ptp_row['milestones_total'] ); ?></td>
							<td class="ptp-col-optional"><?php echo esc_html( ptp_format_duration( $ptp_row['tracked_seconds'] ) ); ?></td>
							<td class="ptp-col-optional"><?php echo $ptp_can_view_finance ? esc_html( ptp_format_currency( $ptp_row['revenue'], $ptp_row['currency'] ) ) : '&#8212;'; ?></td>
							<td class="ptp-col-optional"><?php echo $ptp_can_view_finance ? esc_html( ptp_format_currency( $ptp_row['expenses'], $ptp_row['currency'] ) ) : '&#8212;'; ?></td>
							<td>
								<?php if ( $ptp_can_view_finance ) : ?>
									<span class="<?php echo esc_attr( $ptp_row['profit'] < 0 ? 'ptp-text-danger' : '' ); ?>"><?php echo esc_html( ptp_format_currency( $ptp_row['profit'], $ptp_row['currency'] ) ); ?></span>
								<?php else : ?>
									&#8212;
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php if ( ! empty( $ptp_report['total_pages'] ) && $ptp_report['total_pages'] > 1 ) : ?>
			<div class="ptp-pagination">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'current'   => $ptp_report['page'],
							'total'     => $ptp_report['total_pages'],
							'prev_text' => __( '&laquo; Previous', 'personal-project-tracker' ),
							'next_text' => __( 'Next &raquo;', 'personal-project-tracker' ),
						)
					)
				);
				?>
			</div>
		<?php endif; ?>
	<?php endif; ?>
</div>
