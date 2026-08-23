<?php
/**
 * Add/Edit Manual Time Entry form.
 *
 * @package Personal_Project_Tracker
 *
 * @var object|null $entry   Existing time entry row when editing, null when creating or not found.
 * @var bool        $is_edit Whether this request is an "edit" (vs. "new").
 * @var array|null  $flash   Flashed validation errors + previously submitted values, if any.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ptp_not_found  = ! empty( $is_edit ) && ! $entry;
$ptp_is_active  = $entry && in_array( $entry->status, array( 'running', 'paused' ), true );
$ptp_projects   = PTP_Projects_Repository::get_options_for_select();

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

// A "Start Timer"/"+ New Entry" link from elsewhere can preselect a project/task.
$ptp_default_project_id = isset( $_GET['project_id'] ) ? absint( $_GET['project_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_default_task_id    = isset( $_GET['task_id'] ) ? absint( $_GET['task_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$ptp_values = array(
	'entry_date'  => $entry->entry_date ?? current_time( 'Y-m-d' ),
	'start_time'  => $entry && $entry->start_time ? mysql2date( 'H:i', $entry->start_time ) : '',
	'end_time'    => $entry && $entry->end_time ? mysql2date( 'H:i', $entry->end_time ) : '',
	'duration'    => $entry ? (int) round( (int) $entry->duration / 60 ) : '',
	'project_id'  => $entry->project_id ?? $ptp_default_project_id,
	'task_id'     => $entry->task_id ?? $ptp_default_task_id,
	'description' => $entry->description ?? '',
);

if ( ! empty( $flash['data'] ) ) {
	$ptp_values = wp_parse_args( $flash['data'], $ptp_values );
}
?>
<h1><?php echo ! empty( $is_edit ) ? esc_html__( 'Edit Time Entry', 'personal-project-tracker' ) : esc_html__( 'Log Time Manually', 'personal-project-tracker' ); ?></h1>

<?php if ( ! empty( $flash['errors'] ) ) : ?>
	<div class="notice notice-error" role="alert">
		<ul class="ptp-error-list">
			<?php foreach ( $flash['errors'] as $ptp_error_message ) : ?>
				<li><?php echo esc_html( $ptp_error_message ); ?></li>
			<?php endforeach; ?>
		</ul>
	</div>
<?php endif; ?>

<?php if ( $ptp_not_found ) : ?>
	<div class="notice notice-error"><p><?php esc_html_e( 'That time entry could not be found.', 'personal-project-tracker' ); ?></p></div>
<?php elseif ( $ptp_is_active ) : ?>
	<div class="notice notice-warning">
		<p>
			<?php esc_html_e( 'This is an active timer and cannot be edited here. Stop it first from the Time Tracking page.', 'personal-project-tracker' ); ?>
			<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-time-tracking' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Go to Time Tracking &raquo;', 'personal-project-tracker' ); ?></a>
		</p>
	</div>
<?php else : ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ptp-card ptp-time-entry-form" id="ptp-time-entry-form">
		<input type="hidden" name="action" value="ptp_save_time_entry" />
		<?php if ( ! empty( $is_edit ) && $entry ) : ?>
			<input type="hidden" name="id" value="<?php echo esc_attr( $entry->id ); ?>" />
		<?php endif; ?>
		<?php PTP_Security::nonce_field(); ?>

		<div class="ptp-form-grid">
			<div class="ptp-form-field">
				<label for="ptp-entry-date"><?php esc_html_e( 'Date', 'personal-project-tracker' ); ?> <span class="ptp-required" aria-hidden="true">*</span></label>
				<input type="date" id="ptp-entry-date" name="entry_date" required value="<?php echo esc_attr( $ptp_values['entry_date'] ); ?>" />
			</div>

			<div class="ptp-form-field">
				<label for="ptp-project"><?php esc_html_e( 'Project', 'personal-project-tracker' ); ?></label>
				<select id="ptp-project" name="project_id">
					<option value=""><?php esc_html_e( '— No project —', 'personal-project-tracker' ); ?></option>
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
					<option value=""><?php esc_html_e( '— No task —', 'personal-project-tracker' ); ?></option>
					<?php foreach ( $ptp_tasks as $ptp_tid => $ptp_trow ) : ?>
						<option value="<?php echo esc_attr( $ptp_tid ); ?>" data-project="<?php echo esc_attr( $ptp_trow['project_id'] ); ?>" <?php selected( (int) $ptp_values['task_id'], $ptp_tid ); ?>>
							<?php echo esc_html( $ptp_trow['title'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="ptp-form-field">
				<label for="ptp-start-time"><?php esc_html_e( 'Start', 'personal-project-tracker' ); ?></label>
				<input type="time" id="ptp-start-time" name="start_time" value="<?php echo esc_attr( $ptp_values['start_time'] ); ?>" />
			</div>

			<div class="ptp-form-field">
				<label for="ptp-end-time"><?php esc_html_e( 'End', 'personal-project-tracker' ); ?></label>
				<input type="time" id="ptp-end-time" name="end_time" value="<?php echo esc_attr( $ptp_values['end_time'] ); ?>" />
			</div>

			<div class="ptp-form-field">
				<label for="ptp-duration"><?php esc_html_e( 'Duration (minutes)', 'personal-project-tracker' ); ?></label>
				<input type="number" id="ptp-duration" name="duration" min="0" step="1" value="<?php echo esc_attr( $ptp_values['duration'] ); ?>" />
				<p class="description"><?php esc_html_e( 'Filled in automatically when both a start and end time are set. Otherwise, enter it directly.', 'personal-project-tracker' ); ?></p>
			</div>

			<div class="ptp-form-field ptp-form-field-full">
				<label for="ptp-description"><?php esc_html_e( 'Description', 'personal-project-tracker' ); ?></label>
				<textarea id="ptp-description" name="description" rows="3" maxlength="500"><?php echo esc_textarea( $ptp_values['description'] ); ?></textarea>
			</div>
		</div>

		<p class="ptp-form-actions">
			<button type="submit" class="button button-primary">
				<?php echo ! empty( $is_edit ) ? esc_html__( 'Update Entry', 'personal-project-tracker' ) : esc_html__( 'Save Entry', 'personal-project-tracker' ); ?>
			</button>
			<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-time-tracking' ), admin_url( 'admin.php' ) ) ); ?>">
				<?php esc_html_e( 'Cancel', 'personal-project-tracker' ); ?>
			</a>
		</p>
	</form>

<?php endif; ?>
