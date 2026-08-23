<?php
/**
 * File detail page.
 *
 * @package Personal_Project_Tracker
 *
 * @var object|null $file File attachment-association row, or null when not found.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! $file ) :
	?>
	<h1><?php esc_html_e( 'File Not Found', 'personal-project-tracker' ); ?></h1>
	<div class="ptp-card">
		<p><?php esc_html_e( 'That file attachment does not exist or may have been detached.', 'personal-project-tracker' ); ?></p>
		<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-files' ), admin_url( 'admin.php' ) ) ); ?>">
			<?php esc_html_e( '&laquo; Back to Files', 'personal-project-tracker' ); ?>
		</a>
	</div>
	<?php
	return;
endif;

$ptp_project   = $file->project_id ? PTP_Projects_Repository::get( $file->project_id ) : null;
$ptp_task      = $file->task_id ? PTP_Tasks_Repository::get( $file->task_id ) : null;
$ptp_milestone = $file->milestone_id ? PTP_Milestones_Repository::get( $file->milestone_id ) : null;
$ptp_note      = $file->note_id ? PTP_Notes_Repository::get( $file->note_id ) : null;
$ptp_list_url  = add_query_arg( array( 'page' => 'ptp-files' ), admin_url( 'admin.php' ) );
$ptp_file_url  = wp_get_attachment_url( $file->attachment_id );
$ptp_is_image  = $file->file_type && 0 === strpos( (string) $file->file_type, 'image/' );
?>
<div class="ptp-page-header">
	<h1><?php echo esc_html( $file->file_name ? $file->file_name : __( '(file)', 'personal-project-tracker' ) ); ?></h1>
	<div class="ptp-quick-actions">
		<a class="button" href="<?php echo esc_url( $ptp_list_url ); ?>"><?php esc_html_e( '&laquo; Back to Files', 'personal-project-tracker' ); ?></a>
		<?php if ( $ptp_file_url ) : ?>
			<a class="button button-primary" href="<?php echo esc_url( $ptp_file_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Download', 'personal-project-tracker' ); ?></a>
		<?php endif; ?>
		<button type="button" class="button button-link-delete ptp-js-file-detach" data-id="<?php echo esc_attr( $file->id ); ?>" data-redirect="<?php echo esc_url( $ptp_list_url ); ?>">
			<?php esc_html_e( 'Detach', 'personal-project-tracker' ); ?>
		</button>
	</div>
</div>

<div class="ptp-detail-grid">
	<div class="ptp-detail-main">

		<div class="ptp-card">
			<h2><?php esc_html_e( 'Overview', 'personal-project-tracker' ); ?></h2>
			<?php if ( $ptp_is_image && $ptp_file_url ) : ?>
				<p><img src="<?php echo esc_url( $ptp_file_url ); ?>" alt="" class="ptp-file-preview" /></p>
			<?php endif; ?>
			<table class="widefat striped ptp-status-table">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Type', 'personal-project-tracker' ); ?></th>
						<td><?php echo esc_html( $file->file_type ? $file->file_type : '—' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Size', 'personal-project-tracker' ); ?></th>
						<td><?php echo esc_html( null !== $file->file_size ? size_format( (int) $file->file_size ) : '—' ); ?></td>
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
					<tr>
						<th scope="row"><?php esc_html_e( 'Note', 'personal-project-tracker' ); ?></th>
						<td>
							<?php if ( $ptp_note ) : ?>
								<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-notes', 'action' => 'view', 'id' => $ptp_note->id ), admin_url( 'admin.php' ) ) ); ?>">
									<?php echo esc_html( $ptp_note->title ? $ptp_note->title : __( '(untitled)', 'personal-project-tracker' ) ); ?>
								</a>
							<?php else : ?>
								&#8212;
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Attached', 'personal-project-tracker' ); ?></th>
						<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $file->created_at ) ); ?></td>
					</tr>
				</tbody>
			</table>
		</div>

	</div>
</div>
