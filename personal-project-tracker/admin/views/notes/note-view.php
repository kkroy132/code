<?php
/**
 * Note detail page.
 *
 * @package Personal_Project_Tracker
 *
 * @var object|null $note Note row, or null when not found.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! $note ) :
	?>
	<h1><?php esc_html_e( 'Note Not Found', 'personal-project-tracker' ); ?></h1>
	<div class="ptp-card">
		<p><?php esc_html_e( 'That note does not exist or may have been deleted.', 'personal-project-tracker' ); ?></p>
		<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-notes' ), admin_url( 'admin.php' ) ) ); ?>">
			<?php esc_html_e( '&laquo; Back to Notes', 'personal-project-tracker' ); ?>
		</a>
	</div>
	<?php
	return;
endif;

$ptp_project   = $note->project_id ? PTP_Projects_Repository::get( $note->project_id ) : null;
$ptp_task      = $note->task_id ? PTP_Tasks_Repository::get( $note->task_id ) : null;
$ptp_milestone = $note->milestone_id ? PTP_Milestones_Repository::get( $note->milestone_id ) : null;
$ptp_list_url  = add_query_arg( array( 'page' => 'ptp-notes' ), admin_url( 'admin.php' ) );
$ptp_edit_url  = add_query_arg( array( 'page' => 'ptp-notes', 'action' => 'edit', 'id' => $note->id ), admin_url( 'admin.php' ) );
$ptp_activity  = PTP_Activity_Log::get_for_object( 'note', $note->id, 15 );

$ptp_breadcrumbs = array(
	array( 'label' => __( 'Project Tracker', 'personal-project-tracker' ), 'url' => add_query_arg( array( 'page' => 'ptp-dashboard' ), admin_url( 'admin.php' ) ) ),
	array( 'label' => __( 'Notes', 'personal-project-tracker' ), 'url' => $ptp_list_url ),
	array( 'label' => $note->title ? $note->title : __( '(untitled)', 'personal-project-tracker' ) ),
);
require PTP_PLUGIN_DIR . 'admin/views/partials/breadcrumbs.php';
?>
<div class="ptp-page-header">
	<h1>
		<?php if ( $note->pinned ) : ?>
			<span class="dashicons dashicons-sticky" title="<?php esc_attr_e( 'Pinned', 'personal-project-tracker' ); ?>"></span>
		<?php endif; ?>
		<?php echo esc_html( $note->title ? $note->title : __( '(untitled)', 'personal-project-tracker' ) ); ?>
	</h1>
	<div class="ptp-quick-actions">
		<a class="button" href="<?php echo esc_url( $ptp_list_url ); ?>"><?php esc_html_e( '&laquo; Back to Notes', 'personal-project-tracker' ); ?></a>
		<a class="button button-primary" href="<?php echo esc_url( $ptp_edit_url ); ?>"><?php esc_html_e( 'Edit', 'personal-project-tracker' ); ?></a>
		<?php if ( $note->pinned ) : ?>
			<button type="button" class="button ptp-js-note-unpin" data-id="<?php echo esc_attr( $note->id ); ?>">
				<?php esc_html_e( 'Unpin', 'personal-project-tracker' ); ?>
			</button>
		<?php else : ?>
			<button type="button" class="button ptp-js-note-pin" data-id="<?php echo esc_attr( $note->id ); ?>">
				<?php esc_html_e( 'Pin', 'personal-project-tracker' ); ?>
			</button>
		<?php endif; ?>
		<?php if ( $note->archived ) : ?>
			<button type="button" class="button ptp-js-note-restore" data-id="<?php echo esc_attr( $note->id ); ?>">
				<?php esc_html_e( 'Restore', 'personal-project-tracker' ); ?>
			</button>
		<?php else : ?>
			<button type="button" class="button ptp-js-note-archive" data-id="<?php echo esc_attr( $note->id ); ?>">
				<?php esc_html_e( 'Archive', 'personal-project-tracker' ); ?>
			</button>
		<?php endif; ?>
		<button type="button" class="button button-link-delete ptp-js-note-delete" data-id="<?php echo esc_attr( $note->id ); ?>" data-redirect="<?php echo esc_url( $ptp_list_url ); ?>">
			<?php esc_html_e( 'Delete', 'personal-project-tracker' ); ?>
		</button>
	</div>
</div>

<div class="ptp-detail-grid">
	<div class="ptp-detail-main">

		<div class="ptp-card">
			<h2><?php esc_html_e( 'Content', 'personal-project-tracker' ); ?></h2>
			<?php if ( $note->content ) : ?>
				<div class="ptp-project-description"><?php echo wp_kses_post( wpautop( $note->content ) ); ?></div>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'No content.', 'personal-project-tracker' ); ?></p>
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
						<th scope="row"><?php esc_html_e( 'Task', 'personal-project-tracker' ); ?></th>
						<td>
							<?php if ( $ptp_task ) : ?>
								<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-tasks', 'action' => 'view', 'id' => $ptp_task->id ), admin_url( 'admin.php' ) ) ); ?>">
									<?php echo esc_html( $ptp_task->title ); ?>
								</a>
							<?php else : ?>
								&#8212;
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Milestone', 'personal-project-tracker' ); ?></th>
						<td>
							<?php if ( $ptp_milestone ) : ?>
								<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-milestones', 'action' => 'view', 'id' => $ptp_milestone->id ), admin_url( 'admin.php' ) ) ); ?>">
									<?php echo esc_html( $ptp_milestone->title ); ?>
								</a>
							<?php else : ?>
								&#8212;
							<?php endif; ?>
						</td>
					</tr>
					<?php if ( $note->tags ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Tags', 'personal-project-tracker' ); ?></th>
							<td><?php echo esc_html( $note->tags ); ?></td>
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
							<span class="ptp-activity-date"><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ptp_entry->created_at ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>

	</div>
</div>
