<?php
/**
 * Attach File page: choose/upload via the Media Library picker, or a plain
 * file input as a no-JS fallback (handled by media_handle_upload()).
 *
 * @package Personal_Project_Tracker
 *
 * @var array|null $flash Flashed validation errors + previously submitted values, if any.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ptp_can_upload = current_user_can( 'upload_files' );
$ptp_projects   = PTP_Projects_Repository::get_options_for_select();
$ptp_milestones = PTP_Milestones_Repository::get_options_for_select();

$ptp_task_rows = PTP_Tasks_Repository::get_list(
	array(
		'view'     => 'active',
		'orderby'  => 'title',
		'order'    => 'ASC',
		'per_page' => 500,
	)
)['items'];

$ptp_tasks = array();
foreach ( $ptp_task_rows as $ptp_task_row ) {
	$ptp_tasks[ (int) $ptp_task_row->id ] = array(
		'title'      => $ptp_task_row->title,
		'project_id' => (int) $ptp_task_row->project_id,
	);
}

$ptp_note_rows = PTP_Notes_Repository::get_list(
	array(
		'view'     => 'active',
		'orderby'  => 'title',
		'order'    => 'ASC',
		'per_page' => 500,
	)
)['items'];

$ptp_notes = array();
foreach ( $ptp_note_rows as $ptp_note_row ) {
	$ptp_notes[ (int) $ptp_note_row->id ] = $ptp_note_row->title ? $ptp_note_row->title : __( '(untitled)', 'personal-project-tracker' );
}

$ptp_default_project_id   = isset( $_GET['project_id'] ) ? absint( $_GET['project_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_default_task_id      = isset( $_GET['task_id'] ) ? absint( $_GET['task_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_default_milestone_id = isset( $_GET['milestone_id'] ) ? absint( $_GET['milestone_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_default_note_id      = isset( $_GET['note_id'] ) ? absint( $_GET['note_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$ptp_values = array(
	'project_id'   => $ptp_default_project_id,
	'task_id'      => $ptp_default_task_id,
	'milestone_id' => $ptp_default_milestone_id,
	'note_id'      => $ptp_default_note_id,
);

if ( ! empty( $flash['data'] ) ) {
	$ptp_values = wp_parse_args( $flash['data'], $ptp_values );
}
?>
<h1><?php esc_html_e( 'Attach File', 'personal-project-tracker' ); ?></h1>

<?php if ( ! empty( $flash['errors'] ) ) : ?>
	<div class="notice notice-error">
		<ul class="ptp-error-list">
			<?php foreach ( $flash['errors'] as $ptp_error_message ) : ?>
				<li><?php echo esc_html( $ptp_error_message ); ?></li>
			<?php endforeach; ?>
		</ul>
	</div>
<?php endif; ?>

<?php if ( ! $ptp_can_upload ) : ?>
	<div class="notice notice-error"><p><?php esc_html_e( 'You do not have permission to upload files.', 'personal-project-tracker' ); ?></p></div>
<?php else : ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ptp-card ptp-file-attach-form" enctype="multipart/form-data" id="ptp-file-attach-form">
		<input type="hidden" name="action" value="ptp_attach_file" />
		<input type="hidden" name="attachment_id" id="ptp-attachment-id" value="" />
		<?php PTP_Security::nonce_field(); ?>

		<div class="ptp-form-grid">
			<div class="ptp-form-field ptp-form-field-full">
				<label><?php esc_html_e( 'File', 'personal-project-tracker' ); ?></label>
				<p>
					<button type="button" class="button" id="ptp-choose-file-button"><?php esc_html_e( 'Choose or Upload a File', 'personal-project-tracker' ); ?></button>
					<span id="ptp-chosen-file-name" class="description"></span>
				</p>
				<p class="description"><?php esc_html_e( 'Or, without JavaScript, upload a file directly:', 'personal-project-tracker' ); ?></p>
				<input type="file" name="new_file" />
			</div>

			<div class="ptp-form-field">
				<label for="ptp-project"><?php esc_html_e( 'Project', 'personal-project-tracker' ); ?></label>
				<select id="ptp-project" name="project_id">
					<option value=""><?php esc_html_e( '— None —', 'personal-project-tracker' ); ?></option>
					<?php foreach ( $ptp_projects as $ptp_pid => $ptp_ptitle ) : ?>
						<option value="<?php echo esc_attr( $ptp_pid ); ?>" <?php selected( (int) $ptp_values['project_id'], $ptp_pid ); ?>>
							<?php echo esc_html( $ptp_ptitle ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="ptp-form-field">
				<label for="ptp-task"><?php esc_html_e( 'Task', 'personal-project-tracker' ); ?></label>
				<select id="ptp-task" name="task_id">
					<option value=""><?php esc_html_e( '— None —', 'personal-project-tracker' ); ?></option>
					<?php foreach ( $ptp_tasks as $ptp_tid => $ptp_trow ) : ?>
						<option value="<?php echo esc_attr( $ptp_tid ); ?>" data-project="<?php echo esc_attr( $ptp_trow['project_id'] ); ?>" <?php selected( (int) $ptp_values['task_id'], $ptp_tid ); ?>>
							<?php echo esc_html( $ptp_trow['title'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="ptp-form-field">
				<label for="ptp-milestone"><?php esc_html_e( 'Milestone', 'personal-project-tracker' ); ?></label>
				<select id="ptp-milestone" name="milestone_id">
					<option value=""><?php esc_html_e( '— None —', 'personal-project-tracker' ); ?></option>
					<?php foreach ( $ptp_milestones as $ptp_mid => $ptp_mtitle ) : ?>
						<option value="<?php echo esc_attr( $ptp_mid ); ?>" <?php selected( (int) $ptp_values['milestone_id'], $ptp_mid ); ?>>
							<?php echo esc_html( $ptp_mtitle ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="ptp-form-field">
				<label for="ptp-note"><?php esc_html_e( 'Note', 'personal-project-tracker' ); ?></label>
				<select id="ptp-note" name="note_id">
					<option value=""><?php esc_html_e( '— None —', 'personal-project-tracker' ); ?></option>
					<?php foreach ( $ptp_notes as $ptp_nid => $ptp_ntitle ) : ?>
						<option value="<?php echo esc_attr( $ptp_nid ); ?>" <?php selected( (int) $ptp_values['note_id'], $ptp_nid ); ?>>
							<?php echo esc_html( $ptp_ntitle ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<p class="description ptp-form-field-full"><?php esc_html_e( 'Attach to at least one of Project, Task, Milestone, or Note.', 'personal-project-tracker' ); ?></p>
		</div>

		<p class="ptp-form-actions">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Attach File', 'personal-project-tracker' ); ?></button>
			<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-files' ), admin_url( 'admin.php' ) ) ); ?>">
				<?php esc_html_e( 'Cancel', 'personal-project-tracker' ); ?>
			</a>
		</p>
	</form>

<?php endif; ?>
