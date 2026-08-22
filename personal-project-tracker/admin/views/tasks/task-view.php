<?php
/**
 * Task detail page.
 *
 * Shows: Description, Project, Milestone, Status, Priority, Dates,
 * Subtasks (with completion %), placeholders for modules not yet built
 * (Time Tracking, Notes, Files, Links, Reminders), and Activity.
 *
 * @package Personal_Project_Tracker
 *
 * @var object|null $task Task row, or null when not found.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! $task ) :
	?>
	<h1><?php esc_html_e( 'Task Not Found', 'personal-project-tracker' ); ?></h1>
	<div class="ptp-card">
		<p><?php esc_html_e( 'That task does not exist or may have been deleted.', 'personal-project-tracker' ); ?></p>
		<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-tasks' ), admin_url( 'admin.php' ) ) ); ?>">
			<?php esc_html_e( '&laquo; Back to Tasks', 'personal-project-tracker' ); ?>
		</a>
	</div>
	<?php
	return;
endif;

$ptp_statuses   = PTP_Tasks_Repository::get_statuses();
$ptp_priorities = PTP_Tasks_Repository::get_priorities();
$ptp_project    = PTP_Projects_Repository::get( $task->project_id );
$ptp_list_url   = add_query_arg( array( 'page' => 'ptp-tasks' ), admin_url( 'admin.php' ) );
$ptp_edit_url   = add_query_arg( array( 'page' => 'ptp-tasks', 'action' => 'edit', 'id' => $task->id ), admin_url( 'admin.php' ) );
$ptp_is_overdue = $task->due_date && $task->due_date < current_time( 'Y-m-d' ) && ! in_array( $task->status, array( 'completed', 'cancelled' ), true );
$ptp_is_done    = 'completed' === $task->status;
$ptp_subtasks   = PTP_Subtasks_Repository::get_for_task( $task->id );
$ptp_completion = PTP_Subtasks_Repository::get_completion( $task->id );
$ptp_activity   = PTP_Activity_Log::get_for_object( 'task', $task->id, 15 );
?>
<div class="ptp-page-header">
	<h1><?php echo esc_html( $task->title ); ?></h1>
	<div class="ptp-quick-actions">
		<a class="button" href="<?php echo esc_url( $ptp_list_url ); ?>"><?php esc_html_e( '&laquo; Back to Tasks', 'personal-project-tracker' ); ?></a>
		<a class="button button-primary" href="<?php echo esc_url( $ptp_edit_url ); ?>"><?php esc_html_e( 'Edit', 'personal-project-tracker' ); ?></a>
		<?php if ( $ptp_is_done ) : ?>
			<button type="button" class="button ptp-js-task-reopen" data-id="<?php echo esc_attr( $task->id ); ?>">
				<?php esc_html_e( 'Reopen', 'personal-project-tracker' ); ?>
			</button>
		<?php else : ?>
			<button type="button" class="button ptp-js-task-complete" data-id="<?php echo esc_attr( $task->id ); ?>">
				<?php esc_html_e( 'Complete', 'personal-project-tracker' ); ?>
			</button>
		<?php endif; ?>
		<?php if ( $task->archived_at ) : ?>
			<button type="button" class="button ptp-js-task-restore" data-id="<?php echo esc_attr( $task->id ); ?>">
				<?php esc_html_e( 'Restore', 'personal-project-tracker' ); ?>
			</button>
		<?php else : ?>
			<button type="button" class="button ptp-js-task-archive" data-id="<?php echo esc_attr( $task->id ); ?>">
				<?php esc_html_e( 'Archive', 'personal-project-tracker' ); ?>
			</button>
		<?php endif; ?>
		<button type="button" class="button button-link-delete ptp-js-task-delete" data-id="<?php echo esc_attr( $task->id ); ?>" data-redirect="<?php echo esc_url( $ptp_list_url ); ?>">
			<?php esc_html_e( 'Delete', 'personal-project-tracker' ); ?>
		</button>
	</div>
</div>

<div class="ptp-detail-grid">
	<div class="ptp-detail-main">

		<div class="ptp-card">
			<h2><?php esc_html_e( 'Overview', 'personal-project-tracker' ); ?></h2>
			<?php if ( $task->description ) : ?>
				<div class="ptp-project-description"><?php echo wp_kses_post( wpautop( $task->description ) ); ?></div>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'No description provided.', 'personal-project-tracker' ); ?></p>
			<?php endif; ?>

			<table class="widefat striped ptp-status-table">
				<tbody>
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
						<th scope="row"><?php esc_html_e( 'Milestone', 'personal-project-tracker' ); ?></th>
						<td>
							<?php esc_html_e( 'Not assigned (Milestones module coming in a later phase)', 'personal-project-tracker' ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'personal-project-tracker' ); ?></th>
						<td><span class="ptp-badge ptp-badge-status-<?php echo esc_attr( $task->status ); ?>"><?php echo esc_html( $ptp_statuses[ $task->status ] ?? $task->status ); ?></span></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Priority', 'personal-project-tracker' ); ?></th>
						<td><span class="ptp-badge ptp-badge-priority-<?php echo esc_attr( $task->priority ); ?>"><?php echo esc_html( $ptp_priorities[ $task->priority ] ?? $task->priority ); ?></span></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Start Date', 'personal-project-tracker' ); ?></th>
						<td><?php echo $task->start_date ? esc_html( mysql2date( get_option( 'date_format' ), $task->start_date ) ) : '&#8212;'; ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Due Date', 'personal-project-tracker' ); ?></th>
						<td>
							<?php if ( $task->due_date ) : ?>
								<span class="<?php echo esc_attr( $ptp_is_overdue ? 'ptp-text-danger' : '' ); ?>">
									<?php echo esc_html( mysql2date( get_option( 'date_format' ), $task->due_date ) ); ?>
									<?php if ( $ptp_is_overdue ) : ?>
										<strong>(<?php esc_html_e( 'overdue', 'personal-project-tracker' ); ?>)</strong>
									<?php endif; ?>
								</span>
							<?php else : ?>
								&#8212;
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Estimated Time', 'personal-project-tracker' ); ?></th>
						<td><?php echo null !== $task->estimated_time ? esc_html( $task->estimated_time ) . ' ' . esc_html__( 'hours', 'personal-project-tracker' ) : '&#8212;'; ?></td>
					</tr>
					<?php if ( $task->tags ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Tags', 'personal-project-tracker' ); ?></th>
							<td><?php echo esc_html( $task->tags ); ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>

		<div class="ptp-card" id="ptp-subtasks-app" data-task-id="<?php echo esc_attr( $task->id ); ?>">
			<div class="ptp-page-header">
				<h2><?php esc_html_e( 'Subtasks', 'personal-project-tracker' ); ?></h2>
				<span class="ptp-subtask-completion" id="ptp-subtask-completion">
					<?php if ( null !== $ptp_completion['percentage'] ) : ?>
						<?php
						printf(
							/* translators: 1: completed count, 2: total count, 3: percentage. */
							esc_html__( '%1$d of %2$d complete (%3$d%%)', 'personal-project-tracker' ),
							(int) $ptp_completion['completed'],
							(int) $ptp_completion['total'],
							(int) $ptp_completion['percentage']
						);
						?>
					<?php endif; ?>
				</span>
			</div>

			<?php if ( null !== $ptp_completion['percentage'] ) : ?>
				<div class="ptp-progress ptp-progress-wide" id="ptp-subtask-progress-bar">
					<div class="ptp-progress-bar" style="width:<?php echo esc_attr( $ptp_completion['percentage'] ); ?>%"></div>
				</div>
			<?php endif; ?>

			<ul class="ptp-subtask-list" id="ptp-subtask-list">
				<?php foreach ( $ptp_subtasks as $ptp_subtask ) : ?>
					<li class="ptp-subtask-row" data-id="<?php echo esc_attr( $ptp_subtask->id ); ?>" data-status="<?php echo esc_attr( $ptp_subtask->status ); ?>" data-priority="<?php echo esc_attr( $ptp_subtask->priority ); ?>" data-due-date="<?php echo esc_attr( $ptp_subtask->due_date ); ?>">
						<input type="checkbox" class="ptp-js-subtask-toggle" data-id="<?php echo esc_attr( $ptp_subtask->id ); ?>" <?php checked( $ptp_subtask->completed, 1 ); ?> />
						<input type="text" class="ptp-subtask-title-input ptp-js-subtask-title" data-id="<?php echo esc_attr( $ptp_subtask->id ); ?>" value="<?php echo esc_attr( $ptp_subtask->title ); ?>" />
						<span class="ptp-subtask-controls">
							<button type="button" class="button-link ptp-js-subtask-up" data-id="<?php echo esc_attr( $ptp_subtask->id ); ?>" aria-label="<?php esc_attr_e( 'Move up', 'personal-project-tracker' ); ?>">&uarr;</button>
							<button type="button" class="button-link ptp-js-subtask-down" data-id="<?php echo esc_attr( $ptp_subtask->id ); ?>" aria-label="<?php esc_attr_e( 'Move down', 'personal-project-tracker' ); ?>">&darr;</button>
							<button type="button" class="button-link-delete ptp-js-subtask-delete" data-id="<?php echo esc_attr( $ptp_subtask->id ); ?>" aria-label="<?php esc_attr_e( 'Delete', 'personal-project-tracker' ); ?>">&times;</button>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>

			<form id="ptp-subtask-add-form" class="ptp-subtask-add-form">
				<input type="text" id="ptp-new-subtask-title" placeholder="<?php esc_attr_e( 'Add a subtask…', 'personal-project-tracker' ); ?>" maxlength="255" />
				<button type="submit" class="button"><?php esc_html_e( 'Add', 'personal-project-tracker' ); ?></button>
			</form>
			<p class="ptp-subtask-empty description" id="ptp-subtask-empty" <?php echo empty( $ptp_subtasks ) ? '' : 'style="display:none;"'; ?>>
				<?php esc_html_e( 'No subtasks yet.', 'personal-project-tracker' ); ?>
			</p>
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

	</div>

	<div class="ptp-detail-side">
		<div class="ptp-card">
			<h2><?php esc_html_e( 'Coming Soon', 'personal-project-tracker' ); ?></h2>
			<p class="description"><?php esc_html_e( 'These sections light up as their modules are built in later phases.', 'personal-project-tracker' ); ?></p>
			<ul class="ptp-coming-soon-list">
				<li><span class="dashicons dashicons-clock"></span> <?php esc_html_e( 'Time Tracking', 'personal-project-tracker' ); ?></li>
				<li><span class="dashicons dashicons-edit"></span> <?php esc_html_e( 'Notes', 'personal-project-tracker' ); ?></li>
				<li><span class="dashicons dashicons-media-default"></span> <?php esc_html_e( 'Files', 'personal-project-tracker' ); ?></li>
				<li><span class="dashicons dashicons-admin-links"></span> <?php esc_html_e( 'Links', 'personal-project-tracker' ); ?></li>
				<li><span class="dashicons dashicons-bell"></span> <?php esc_html_e( 'Reminders', 'personal-project-tracker' ); ?></li>
			</ul>
		</div>
	</div>
</div>
