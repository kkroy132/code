<?php
/**
 * Add/Edit Task form.
 *
 * @package Personal_Project_Tracker
 *
 * @var object|null $task    Existing task row when editing, null when creating or not found.
 * @var bool        $is_edit Whether this request is an "edit" (vs. "new").
 * @var array|null  $flash   Flashed validation errors + previously submitted values, if any.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ptp_not_found  = ! empty( $is_edit ) && ! $task;
$ptp_statuses   = PTP_Tasks_Repository::get_statuses();
$ptp_priorities = PTP_Tasks_Repository::get_priorities();
$ptp_projects   = PTP_Projects_Repository::get_options_for_select();

// A "+ New Task" link from a Project's detail page can preselect its project.
$ptp_default_project_id = isset( $_GET['project_id'] ) ? absint( $_GET['project_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$ptp_values = array(
	'title'          => $task->title ?? '',
	'description'    => $task->description ?? '',
	'project_id'     => $task->project_id ?? $ptp_default_project_id,
	'status'         => $task->status ?? PTP_Settings::get( 'default_task_status', 'todo' ),
	'priority'       => $task->priority ?? PTP_Settings::get( 'default_task_priority', 'medium' ),
	'start_date'     => $task->start_date ?? '',
	'due_date'       => $task->due_date ?? '',
	'estimated_time' => $task->estimated_time ?? '',
	'tags'           => $task->tags ?? '',
);

if ( ! empty( $flash['data'] ) ) {
	$ptp_values = wp_parse_args( $flash['data'], $ptp_values );
}
?>
<h1><?php echo ! empty( $is_edit ) ? esc_html__( 'Edit Task', 'personal-project-tracker' ) : esc_html__( 'Add New Task', 'personal-project-tracker' ); ?></h1>

<?php if ( ! empty( $flash['errors'] ) ) : ?>
	<div class="notice notice-error">
		<ul class="ptp-error-list">
			<?php foreach ( $flash['errors'] as $ptp_error_message ) : ?>
				<li><?php echo esc_html( $ptp_error_message ); ?></li>
			<?php endforeach; ?>
		</ul>
	</div>
<?php endif; ?>

<?php if ( $ptp_not_found ) : ?>
	<div class="notice notice-error"><p><?php esc_html_e( 'That task could not be found.', 'personal-project-tracker' ); ?></p></div>
<?php elseif ( empty( $ptp_projects ) ) : ?>
	<div class="notice notice-warning">
		<p>
			<?php esc_html_e( 'You need at least one project before you can create a task.', 'personal-project-tracker' ); ?>
			<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-projects', 'action' => 'new' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Create a project first &raquo;', 'personal-project-tracker' ); ?></a>
		</p>
	</div>
<?php else : ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ptp-card ptp-task-form">
		<input type="hidden" name="action" value="ptp_save_task" />
		<?php if ( ! empty( $is_edit ) && $task ) : ?>
			<input type="hidden" name="id" value="<?php echo esc_attr( $task->id ); ?>" />
		<?php endif; ?>
		<?php PTP_Security::nonce_field(); ?>

		<div class="ptp-form-grid">
			<div class="ptp-form-field ptp-form-field-full">
				<label for="ptp-title"><?php esc_html_e( 'Title', 'personal-project-tracker' ); ?> <span class="ptp-required">*</span></label>
				<input type="text" id="ptp-title" name="title" maxlength="255" required value="<?php echo esc_attr( $ptp_values['title'] ); ?>" />
			</div>

			<div class="ptp-form-field ptp-form-field-full">
				<label for="ptp-description"><?php esc_html_e( 'Description', 'personal-project-tracker' ); ?></label>
				<textarea id="ptp-description" name="description" rows="5"><?php echo esc_textarea( $ptp_values['description'] ); ?></textarea>
			</div>

			<div class="ptp-form-field">
				<label for="ptp-project"><?php esc_html_e( 'Project', 'personal-project-tracker' ); ?> <span class="ptp-required">*</span></label>
				<select id="ptp-project" name="project_id" required>
					<option value=""><?php esc_html_e( '— Select a project —', 'personal-project-tracker' ); ?></option>
					<?php foreach ( $ptp_projects as $ptp_pid => $ptp_ptitle ) : ?>
						<option value="<?php echo esc_attr( $ptp_pid ); ?>" <?php selected( (int) $ptp_values['project_id'], $ptp_pid ); ?>>
							<?php echo esc_html( $ptp_ptitle ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="ptp-form-field">
				<label><?php esc_html_e( 'Milestone', 'personal-project-tracker' ); ?></label>
				<p class="description"><?php esc_html_e( 'Available once the Milestones module is enabled.', 'personal-project-tracker' ); ?></p>
			</div>

			<div class="ptp-form-field">
				<label for="ptp-status"><?php esc_html_e( 'Status', 'personal-project-tracker' ); ?></label>
				<select id="ptp-status" name="status">
					<?php foreach ( $ptp_statuses as $ptp_key => $ptp_label ) : ?>
						<option value="<?php echo esc_attr( $ptp_key ); ?>" <?php selected( $ptp_values['status'], $ptp_key ); ?>><?php echo esc_html( $ptp_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="ptp-form-field">
				<label for="ptp-priority"><?php esc_html_e( 'Priority', 'personal-project-tracker' ); ?></label>
				<select id="ptp-priority" name="priority">
					<?php foreach ( $ptp_priorities as $ptp_key => $ptp_label ) : ?>
						<option value="<?php echo esc_attr( $ptp_key ); ?>" <?php selected( $ptp_values['priority'], $ptp_key ); ?>><?php echo esc_html( $ptp_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="ptp-form-field">
				<label for="ptp-start-date"><?php esc_html_e( 'Start Date', 'personal-project-tracker' ); ?></label>
				<input type="date" id="ptp-start-date" name="start_date" value="<?php echo esc_attr( $ptp_values['start_date'] ); ?>" />
			</div>

			<div class="ptp-form-field">
				<label for="ptp-due-date"><?php esc_html_e( 'Due Date', 'personal-project-tracker' ); ?></label>
				<input type="date" id="ptp-due-date" name="due_date" value="<?php echo esc_attr( $ptp_values['due_date'] ); ?>" />
			</div>

			<div class="ptp-form-field">
				<label for="ptp-estimated-time"><?php esc_html_e( 'Estimated Time (hours)', 'personal-project-tracker' ); ?></label>
				<input type="number" id="ptp-estimated-time" name="estimated_time" min="0" step="0.25" value="<?php echo esc_attr( $ptp_values['estimated_time'] ); ?>" />
			</div>

			<div class="ptp-form-field ptp-form-field-full">
				<label for="ptp-tags"><?php esc_html_e( 'Tags', 'personal-project-tracker' ); ?></label>
				<input type="text" id="ptp-tags" name="tags" maxlength="500" placeholder="<?php esc_attr_e( 'comma, separated, tags', 'personal-project-tracker' ); ?>" value="<?php echo esc_attr( $ptp_values['tags'] ); ?>" />
			</div>
		</div>

		<p class="ptp-form-actions">
			<button type="submit" class="button button-primary">
				<?php echo ! empty( $is_edit ) ? esc_html__( 'Update Task', 'personal-project-tracker' ) : esc_html__( 'Create Task', 'personal-project-tracker' ); ?>
			</button>
			<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-tasks' ), admin_url( 'admin.php' ) ) ); ?>">
				<?php esc_html_e( 'Cancel', 'personal-project-tracker' ); ?>
			</a>
		</p>
	</form>

<?php endif; ?>
