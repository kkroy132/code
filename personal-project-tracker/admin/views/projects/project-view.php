<?php
/**
 * Project detail page.
 *
 * Sections: Overview, Status, Priority, Dates, Budget, Revenue, Progress,
 * Recent Activity. Future modules can attach their own sections via the
 * 'ptp_project_detail_sections' action instead of editing this file.
 *
 * @package Personal_Project_Tracker
 *
 * @var object|null $project Project row, or null when not found.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! $project ) :
	?>
	<h1><?php esc_html_e( 'Project Not Found', 'personal-project-tracker' ); ?></h1>
	<div class="ptp-card">
		<p><?php esc_html_e( 'That project does not exist or may have been deleted.', 'personal-project-tracker' ); ?></p>
		<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-projects' ), admin_url( 'admin.php' ) ) ); ?>">
			<?php esc_html_e( '&laquo; Back to Projects', 'personal-project-tracker' ); ?>
		</a>
	</div>
	<?php
	return;
endif;

$ptp_statuses   = PTP_Projects_Repository::get_statuses();
$ptp_priorities = PTP_Projects_Repository::get_priorities();
$ptp_edit_url   = add_query_arg( array( 'page' => 'ptp-projects', 'action' => 'edit', 'id' => $project->id ), admin_url( 'admin.php' ) );
$ptp_list_url   = add_query_arg( array( 'page' => 'ptp-projects' ), admin_url( 'admin.php' ) );
$ptp_is_overdue = $project->deadline && $project->deadline < current_time( 'Y-m-d' ) && ! in_array( $project->status, array( 'completed', 'cancelled', 'archived' ), true );
$ptp_activity   = PTP_Activity_Log::get_for_object( 'project', $project->id, 10 );

$ptp_breadcrumbs = array(
	array( 'label' => __( 'Project Tracker', 'personal-project-tracker' ), 'url' => add_query_arg( array( 'page' => 'ptp-dashboard' ), admin_url( 'admin.php' ) ) ),
	array( 'label' => __( 'Projects', 'personal-project-tracker' ), 'url' => $ptp_list_url ),
	array( 'label' => $project->title ),
);
require PTP_PLUGIN_DIR . 'admin/views/partials/breadcrumbs.php';
?>
<div class="ptp-page-header">
	<h1>
		<?php if ( $project->color ) : ?>
			<span class="ptp-color-dot" style="background:<?php echo esc_attr( $project->color ); ?>"></span>
		<?php endif; ?>
		<?php echo esc_html( $project->title ); ?>
	</h1>
	<div class="ptp-quick-actions">
		<a class="button" href="<?php echo esc_url( $ptp_list_url ); ?>"><?php esc_html_e( '&laquo; Back to Projects', 'personal-project-tracker' ); ?></a>
		<a class="button button-primary" href="<?php echo esc_url( $ptp_edit_url ); ?>"><?php esc_html_e( 'Edit', 'personal-project-tracker' ); ?></a>
		<?php if ( 'archived' === $project->status ) : ?>
			<button type="button" class="button ptp-js-restore" data-id="<?php echo esc_attr( $project->id ); ?>">
				<?php esc_html_e( 'Restore', 'personal-project-tracker' ); ?>
			</button>
		<?php else : ?>
			<button type="button" class="button ptp-js-archive" data-id="<?php echo esc_attr( $project->id ); ?>">
				<?php esc_html_e( 'Archive', 'personal-project-tracker' ); ?>
			</button>
		<?php endif; ?>
		<button type="button" class="button button-link-delete ptp-js-delete" data-id="<?php echo esc_attr( $project->id ); ?>" data-redirect="<?php echo esc_url( $ptp_list_url ); ?>">
			<?php esc_html_e( 'Delete', 'personal-project-tracker' ); ?>
		</button>
	</div>
</div>

<div class="ptp-detail-grid">
	<div class="ptp-detail-main">

		<div class="ptp-card">
			<h2><?php esc_html_e( 'Overview', 'personal-project-tracker' ); ?></h2>
			<?php if ( $project->description ) : ?>
				<div class="ptp-project-description"><?php echo wp_kses_post( wpautop( $project->description ) ); ?></div>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'No description provided.', 'personal-project-tracker' ); ?></p>
			<?php endif; ?>

			<table class="widefat striped ptp-status-table">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'personal-project-tracker' ); ?></th>
						<td><span class="ptp-badge ptp-badge-status-<?php echo esc_attr( $project->status ); ?>"><?php echo esc_html( $ptp_statuses[ $project->status ] ?? $project->status ); ?></span></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Priority', 'personal-project-tracker' ); ?></th>
						<td><span class="ptp-badge ptp-badge-priority-<?php echo esc_attr( $project->priority ); ?>"><?php echo esc_html( $ptp_priorities[ $project->priority ] ?? $project->priority ); ?></span></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Progress', 'personal-project-tracker' ); ?></th>
						<td>
							<div class="ptp-progress" aria-hidden="true"><div class="ptp-progress-bar" style="width:<?php echo esc_attr( (int) $project->progress ); ?>%"></div></div>
							<span class="ptp-progress-label"><?php echo esc_html( (int) $project->progress ); ?>%</span>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<div class="ptp-card">
			<h2><?php esc_html_e( 'Dates', 'personal-project-tracker' ); ?></h2>
			<table class="widefat striped ptp-status-table">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Start Date', 'personal-project-tracker' ); ?></th>
						<td><?php echo $project->start_date ? esc_html( mysql2date( get_option( 'date_format' ), $project->start_date ) ) : '&#8212;'; ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Deadline', 'personal-project-tracker' ); ?></th>
						<td>
							<?php if ( $project->deadline ) : ?>
								<span class="<?php echo esc_attr( $ptp_is_overdue ? 'ptp-text-danger' : '' ); ?>">
									<?php echo esc_html( mysql2date( get_option( 'date_format' ), $project->deadline ) ); ?>
									<?php if ( $ptp_is_overdue ) : ?>
										<strong>(<?php esc_html_e( 'overdue', 'personal-project-tracker' ); ?>)</strong>
									<?php endif; ?>
								</span>
							<?php else : ?>
								&#8212;
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<div class="ptp-card">
			<h2><?php esc_html_e( 'Recent Activity', 'personal-project-tracker' ); ?></h2>
			<?php if ( empty( $ptp_activity ) ) : ?>
				<p class="description"><?php esc_html_e( 'No activity recorded yet.', 'personal-project-tracker' ); ?></p>
			<?php else : ?>
				<ul class="ptp-activity-list">
					<?php foreach ( $ptp_activity as $ptp_entry ) : ?>
						<li>
							<span class="ptp-activity-desc"><?php echo esc_html( $ptp_entry->description ? $ptp_entry->description : $ptp_entry->action ); ?></span>
							<span class="ptp-activity-date"><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ptp_entry->created_at ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>

		<?php
		/**
		 * Fires after the built-in Project detail sections, so future modules
		 * (Tasks, Milestones, Notes, Finance, ...) can attach their own
		 * sections to this page without modifying this file.
		 *
		 * @param object $project The project being viewed.
		 */
		do_action( 'ptp_project_detail_sections', $project );
		?>
	</div>

	<div class="ptp-detail-side">
		<div class="ptp-card">
			<h2><?php esc_html_e( 'Budget', 'personal-project-tracker' ); ?></h2>
			<table class="widefat striped ptp-status-table">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Budget', 'personal-project-tracker' ); ?></th>
						<td><?php echo null !== $project->budget ? esc_html( ptp_format_currency( $project->budget, $project->currency ) ) : '&#8212;'; ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Currency', 'personal-project-tracker' ); ?></th>
						<td><?php echo esc_html( $project->currency ); ?></td>
					</tr>
				</tbody>
			</table>
		</div>

		<div class="ptp-card">
			<h2><?php esc_html_e( 'Revenue', 'personal-project-tracker' ); ?></h2>
			<table class="widefat striped ptp-status-table">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Estimated Revenue', 'personal-project-tracker' ); ?></th>
						<td><?php echo null !== $project->estimated_revenue ? esc_html( ptp_format_currency( $project->estimated_revenue, $project->currency ) ) : '&#8212;'; ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Actual Revenue', 'personal-project-tracker' ); ?></th>
						<td><?php echo esc_html( ptp_format_currency( $project->actual_revenue, $project->currency ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Actual Expenses', 'personal-project-tracker' ); ?></th>
						<td><?php echo esc_html( ptp_format_currency( $project->actual_expenses, $project->currency ) ); ?></td>
					</tr>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'Actual revenue and expenses are tracked by the Finance module.', 'personal-project-tracker' ); ?></p>
		</div>
	</div>
</div>
