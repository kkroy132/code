<?php
/**
 * Milestone detail page.
 *
 * Sections: Overview, Progress, Related Tasks (view/add/remove existing
 * tasks — never duplicates task records), Deadline, Project, Activity,
 * plus a "Generate AI Prompt" quick action that opens AI Prompt Studio
 * pre-filled with this milestone's context.
 *
 * @package Personal_Project_Tracker
 *
 * @var object|null $milestone Milestone row, or null when not found.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! $milestone ) :
	?>
	<h1><?php esc_html_e( 'Milestone Not Found', 'personal-project-tracker' ); ?></h1>
	<div class="ptp-card">
		<p><?php esc_html_e( 'That milestone does not exist or may have been deleted.', 'personal-project-tracker' ); ?></p>
		<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-milestones' ), admin_url( 'admin.php' ) ) ); ?>">
			<?php esc_html_e( '&laquo; Back to Milestones', 'personal-project-tracker' ); ?>
		</a>
	</div>
	<?php
	return;
endif;

$ptp_statuses    = PTP_Milestones_Repository::get_statuses();
$ptp_priorities  = PTP_Milestones_Repository::get_priorities();
$ptp_project     = PTP_Projects_Repository::get( $milestone->project_id );
$ptp_list_url    = add_query_arg( array( 'page' => 'ptp-milestones' ), admin_url( 'admin.php' ) );
$ptp_edit_url    = add_query_arg( array( 'page' => 'ptp-milestones', 'action' => 'edit', 'id' => $milestone->id ), admin_url( 'admin.php' ) );
$ptp_is_overdue  = $milestone->due_date && $milestone->due_date < current_time( 'Y-m-d' ) && ! in_array( $milestone->status, array( 'completed', 'cancelled' ), true );
$ptp_is_done     = 'completed' === $milestone->status;
$ptp_related     = PTP_Tasks_Repository::get_by_milestone( $milestone->id );
$ptp_related_ids = wp_list_pluck( $ptp_related, 'id' );
$ptp_task_options = PTP_Tasks_Repository::get_options_for_project( $milestone->project_id );
$ptp_task_statuses = PTP_Tasks_Repository::get_statuses();
$ptp_activity    = PTP_Activity_Log::get_for_object( 'milestone', $milestone->id, 15 );

$ptp_breadcrumbs = array(
	array( 'label' => __( 'Project Tracker', 'personal-project-tracker' ), 'url' => add_query_arg( array( 'page' => 'ptp-dashboard' ), admin_url( 'admin.php' ) ) ),
	array( 'label' => __( 'Projects', 'personal-project-tracker' ), 'url' => add_query_arg( array( 'page' => 'ptp-projects' ), admin_url( 'admin.php' ) ) ),
);
if ( $ptp_project ) {
	$ptp_breadcrumbs[] = array( 'label' => $ptp_project->title, 'url' => add_query_arg( array( 'page' => 'ptp-projects', 'action' => 'view', 'id' => $ptp_project->id ), admin_url( 'admin.php' ) ) );
}
$ptp_breadcrumbs[] = array( 'label' => __( 'Milestones', 'personal-project-tracker' ), 'url' => $ptp_list_url );
$ptp_breadcrumbs[] = array( 'label' => $milestone->title );
require PTP_PLUGIN_DIR . 'admin/views/partials/breadcrumbs.php';
?>
<div class="ptp-page-header">
	<h1><?php echo esc_html( $milestone->title ); ?></h1>
	<div class="ptp-quick-actions">
		<a class="button" href="<?php echo esc_url( $ptp_list_url ); ?>"><?php esc_html_e( '&laquo; Back to Milestones', 'personal-project-tracker' ); ?></a>
		<a class="button button-primary" href="<?php echo esc_url( $ptp_edit_url ); ?>"><?php esc_html_e( 'Edit', 'personal-project-tracker' ); ?></a>
		<?php if ( $ptp_is_done ) : ?>
			<button type="button" class="button ptp-js-milestone-reopen" data-id="<?php echo esc_attr( $milestone->id ); ?>">
				<?php esc_html_e( 'Reopen', 'personal-project-tracker' ); ?>
			</button>
		<?php else : ?>
			<button type="button" class="button ptp-js-milestone-complete" data-id="<?php echo esc_attr( $milestone->id ); ?>">
				<?php esc_html_e( 'Complete', 'personal-project-tracker' ); ?>
			</button>
		<?php endif; ?>
		<?php if ( $milestone->archived_at ) : ?>
			<button type="button" class="button ptp-js-milestone-restore" data-id="<?php echo esc_attr( $milestone->id ); ?>">
				<?php esc_html_e( 'Restore', 'personal-project-tracker' ); ?>
			</button>
		<?php else : ?>
			<button type="button" class="button ptp-js-milestone-archive" data-id="<?php echo esc_attr( $milestone->id ); ?>">
				<?php esc_html_e( 'Archive', 'personal-project-tracker' ); ?>
			</button>
		<?php endif; ?>
		<button type="button" class="button button-link-delete ptp-js-milestone-delete" data-id="<?php echo esc_attr( $milestone->id ); ?>" data-redirect="<?php echo esc_url( $ptp_list_url ); ?>">
			<?php esc_html_e( 'Delete', 'personal-project-tracker' ); ?>
		</button>
	</div>
</div>

<div class="ptp-detail-grid">
	<div class="ptp-detail-main">

		<div class="ptp-card">
			<h2><?php esc_html_e( 'Overview', 'personal-project-tracker' ); ?></h2>
			<?php if ( $milestone->description ) : ?>
				<div class="ptp-project-description"><?php echo wp_kses_post( wpautop( $milestone->description ) ); ?></div>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'No description provided.', 'personal-project-tracker' ); ?></p>
			<?php endif; ?>

			<table class="widefat striped ptp-status-table">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'personal-project-tracker' ); ?></th>
						<td><span class="ptp-badge ptp-badge-status-<?php echo esc_attr( $milestone->status ); ?>"><?php echo esc_html( $ptp_statuses[ $milestone->status ] ?? $milestone->status ); ?></span></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Priority', 'personal-project-tracker' ); ?></th>
						<td><span class="ptp-badge ptp-badge-priority-<?php echo esc_attr( $milestone->priority ); ?>"><?php echo esc_html( $ptp_priorities[ $milestone->priority ] ?? $milestone->priority ); ?></span></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Progress', 'personal-project-tracker' ); ?></th>
						<td>
							<div class="ptp-progress" aria-hidden="true"><div class="ptp-progress-bar" style="width:<?php echo esc_attr( (int) $milestone->progress ); ?>%"></div></div>
							<span class="ptp-progress-label"><?php echo esc_html( (int) $milestone->progress ); ?>%</span>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Project', 'personal-project-tracker' ); ?></th>
						<td>
							<?php if ( $ptp_project ) : ?>
								<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-projects', 'action' => 'view', 'id' => $ptp_project->id ), admin_url( 'admin.php' ) ) ); ?>">
									<?php echo esc_html( $ptp_project->title ); ?>
								</a>
							<?php else : ?>
								&#8212;
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Start Date', 'personal-project-tracker' ); ?></th>
						<td><?php echo $milestone->start_date ? esc_html( mysql2date( get_option( 'date_format' ), $milestone->start_date ) ) : '&#8212;'; ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Deadline', 'personal-project-tracker' ); ?></th>
						<td>
							<?php if ( $milestone->due_date ) : ?>
								<span class="<?php echo esc_attr( $ptp_is_overdue ? 'ptp-text-danger' : '' ); ?>">
									<?php echo esc_html( mysql2date( get_option( 'date_format' ), $milestone->due_date ) ); ?>
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

		<div class="ptp-card" id="ptp-milestone-tasks-app" data-milestone-id="<?php echo esc_attr( $milestone->id ); ?>">
			<h2><?php esc_html_e( 'Related Tasks', 'personal-project-tracker' ); ?></h2>

			<ul class="ptp-simple-list" id="ptp-milestone-task-list">
				<?php foreach ( $ptp_related as $ptp_task ) : ?>
					<li data-id="<?php echo esc_attr( $ptp_task->id ); ?>">
						<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-tasks', 'action' => 'view', 'id' => $ptp_task->id ), admin_url( 'admin.php' ) ) ); ?>">
							<?php echo esc_html( $ptp_task->title ); ?>
						</a>
						<span>
							<span class="ptp-badge ptp-badge-status-<?php echo esc_attr( $ptp_task->status ); ?>"><?php echo esc_html( $ptp_task_statuses[ $ptp_task->status ] ?? $ptp_task->status ); ?></span>
							<button type="button" class="button-link-delete ptp-js-milestone-task-remove" data-id="<?php echo esc_attr( $ptp_task->id ); ?>">
								<?php esc_html_e( 'Remove', 'personal-project-tracker' ); ?>
							</button>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="description" id="ptp-milestone-tasks-empty" <?php echo empty( $ptp_related ) ? '' : 'style="display:none;"'; ?>>
				<?php esc_html_e( 'No tasks assigned to this milestone yet.', 'personal-project-tracker' ); ?>
			</p>

			<?php
			$ptp_addable = array_diff_key( $ptp_task_options, array_flip( $ptp_related_ids ) );
			?>
			<form id="ptp-milestone-task-add-form" class="ptp-subtask-add-form">
				<select id="ptp-milestone-task-select" <?php disabled( empty( $ptp_addable ) ); ?>>
					<option value=""><?php esc_html_e( '— Select an existing task to add —', 'personal-project-tracker' ); ?></option>
					<?php foreach ( $ptp_addable as $ptp_tid => $ptp_ttitle ) : ?>
						<option value="<?php echo esc_attr( $ptp_tid ); ?>"><?php echo esc_html( $ptp_ttitle ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button" <?php disabled( empty( $ptp_addable ) ); ?>><?php esc_html_e( 'Add', 'personal-project-tracker' ); ?></button>
			</form>
			<?php if ( empty( $ptp_task_options ) ) : ?>
				<p class="description"><?php esc_html_e( 'This project has no tasks yet.', 'personal-project-tracker' ); ?></p>
			<?php endif; ?>
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
		 * Fires after the built-in Milestone detail sections, so future
		 * modules (Notes, Files, Links, ...) can attach their own sections
		 * to this page without modifying this file. Mirrors the
		 * ptp_project_detail_sections / ptp_task_detail_sections hooks.
		 *
		 * @param object $milestone The milestone being viewed.
		 */
		do_action( 'ptp_milestone_detail_sections', $milestone );
		?>

	</div>

	<div class="ptp-detail-side">
		<?php if ( current_user_can( 'ptp_manage_data' ) ) : ?>
			<div class="ptp-card">
				<h2><?php esc_html_e( 'AI Prompt Studio', 'personal-project-tracker' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Generate a structured prompt from this milestone for an external AI tool.', 'personal-project-tracker' ); ?></p>
				<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-ai-prompts', 'action' => 'new', 'context_type' => 'milestone', 'project_id' => $milestone->project_id, 'milestone_ids' => array( $milestone->id ) ), admin_url( 'admin.php' ) ) ); ?>">
					<?php esc_html_e( 'Generate AI Prompt', 'personal-project-tracker' ); ?>
				</a>
			</div>
		<?php endif; ?>
	</div>
</div>
