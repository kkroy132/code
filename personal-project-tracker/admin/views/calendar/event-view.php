<?php
/**
 * Custom calendar Event detail page.
 *
 * @package Personal_Project_Tracker
 *
 * @var object|null $event Event row, or null when not found.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! $event ) :
	?>
	<h1><?php esc_html_e( 'Event Not Found', 'personal-project-tracker' ); ?></h1>
	<div class="ptp-card">
		<p><?php esc_html_e( 'That event does not exist or may have been deleted.', 'personal-project-tracker' ); ?></p>
		<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-calendar' ), admin_url( 'admin.php' ) ) ); ?>">
			<?php esc_html_e( '&laquo; Back to Calendar', 'personal-project-tracker' ); ?>
		</a>
	</div>
	<?php
	return;
endif;

$ptp_list_url  = add_query_arg( array( 'page' => 'ptp-calendar' ), admin_url( 'admin.php' ) );
$ptp_edit_url  = add_query_arg( array( 'page' => 'ptp-calendar', 'action' => 'edit', 'id' => $event->id ), admin_url( 'admin.php' ) );
$ptp_project   = $event->project_id ? PTP_Projects_Repository::get( $event->project_id ) : null;
$ptp_task      = $event->task_id ? PTP_Tasks_Repository::get( $event->task_id ) : null;
$ptp_milestone = $event->milestone_id ? PTP_Milestones_Repository::get( $event->milestone_id ) : null;
$ptp_activity  = PTP_Activity_Log::get_for_object( 'calendar_event', $event->id, 15 );

$ptp_date_format = get_option( 'date_format' );
$ptp_time_format = get_option( 'time_format' );

$ptp_reminder_labels = array(
	0    => __( 'At time of event', 'personal-project-tracker' ),
	5    => __( '5 minutes before', 'personal-project-tracker' ),
	15   => __( '15 minutes before', 'personal-project-tracker' ),
	30   => __( '30 minutes before', 'personal-project-tracker' ),
	60   => __( '1 hour before', 'personal-project-tracker' ),
	1440 => __( '1 day before', 'personal-project-tracker' ),
);

$ptp_breadcrumbs = array(
	array( 'label' => __( 'Project Tracker', 'personal-project-tracker' ), 'url' => add_query_arg( array( 'page' => 'ptp-dashboard' ), admin_url( 'admin.php' ) ) ),
	array( 'label' => __( 'Calendar', 'personal-project-tracker' ), 'url' => $ptp_list_url ),
	array( 'label' => $event->title ),
);
require PTP_PLUGIN_DIR . 'admin/views/partials/breadcrumbs.php';
?>
<div class="ptp-page-header">
	<h1>
		<?php if ( $event->color ) : ?>
			<span class="ptp-color-dot" style="background:<?php echo esc_attr( $event->color ); ?>"></span>
		<?php endif; ?>
		<?php echo esc_html( $event->title ); ?>
	</h1>
	<div class="ptp-quick-actions">
		<a class="button" href="<?php echo esc_url( $ptp_list_url ); ?>"><?php esc_html_e( '&laquo; Back to Calendar', 'personal-project-tracker' ); ?></a>
		<a class="button button-primary" href="<?php echo esc_url( $ptp_edit_url ); ?>"><?php esc_html_e( 'Edit', 'personal-project-tracker' ); ?></a>
		<button type="button" class="button button-link-delete ptp-js-event-delete" data-id="<?php echo esc_attr( $event->id ); ?>" data-redirect="<?php echo esc_url( $ptp_list_url ); ?>">
			<?php esc_html_e( 'Delete', 'personal-project-tracker' ); ?>
		</button>
	</div>
</div>

<div class="ptp-detail-grid">
	<div class="ptp-detail-main">

		<div class="ptp-card">
			<h2><?php esc_html_e( 'Overview', 'personal-project-tracker' ); ?></h2>
			<?php if ( $event->description ) : ?>
				<div class="ptp-project-description"><?php echo wp_kses_post( wpautop( $event->description ) ); ?></div>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'No description provided.', 'personal-project-tracker' ); ?></p>
			<?php endif; ?>

			<table class="widefat striped ptp-status-table">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Start', 'personal-project-tracker' ); ?></th>
						<td>
							<?php
							echo esc_html( mysql2date( $ptp_date_format, $event->start_datetime ) );
							if ( ! $event->all_day ) {
								echo ' ' . esc_html( mysql2date( $ptp_time_format, $event->start_datetime ) );
							} else {
								echo ' (' . esc_html__( 'all day', 'personal-project-tracker' ) . ')';
							}
							?>
						</td>
					</tr>
					<?php if ( $event->end_datetime ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'End', 'personal-project-tracker' ); ?></th>
							<td>
								<?php
								echo esc_html( mysql2date( $ptp_date_format, $event->end_datetime ) );
								if ( ! $event->all_day ) {
									echo ' ' . esc_html( mysql2date( $ptp_time_format, $event->end_datetime ) );
								}
								?>
							</td>
						</tr>
					<?php endif; ?>
					<?php if ( $event->location ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Location', 'personal-project-tracker' ); ?></th>
							<td><?php echo esc_html( $event->location ); ?></td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Reminder', 'personal-project-tracker' ); ?></th>
						<td>
							<?php if ( null !== $event->reminder_minutes ) : ?>
								<?php echo esc_html( $ptp_reminder_labels[ (int) $event->reminder_minutes ] ?? sprintf( /* translators: %d: minutes. */ __( '%d minutes before', 'personal-project-tracker' ), (int) $event->reminder_minutes ) ); ?>
							<?php else : ?>
								&#8212;
							<?php endif; ?>
						</td>
					</tr>
					<?php if ( $ptp_project ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Project', 'personal-project-tracker' ); ?></th>
							<td><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-projects', 'action' => 'view', 'id' => $ptp_project->id ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $ptp_project->title ); ?></a></td>
						</tr>
					<?php endif; ?>
					<?php if ( $ptp_task ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Task', 'personal-project-tracker' ); ?></th>
							<td><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-tasks', 'action' => 'view', 'id' => $ptp_task->id ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $ptp_task->title ); ?></a></td>
						</tr>
					<?php endif; ?>
					<?php if ( $ptp_milestone ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Milestone', 'personal-project-tracker' ); ?></th>
							<td><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-milestones', 'action' => 'view', 'id' => $ptp_milestone->id ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $ptp_milestone->title ); ?></a></td>
						</tr>
					<?php endif; ?>
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
							<span class="ptp-activity-date"><?php echo esc_html( mysql2date( $ptp_date_format . ' ' . $ptp_time_format, $ptp_entry->created_at ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>

	</div>
</div>
