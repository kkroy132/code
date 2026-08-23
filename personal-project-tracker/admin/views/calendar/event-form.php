<?php
/**
 * Add/Edit custom calendar Event form.
 *
 * @package Personal_Project_Tracker
 *
 * @var object|null $event   Existing event row when editing, null when creating or not found.
 * @var bool        $is_edit Whether this request is an "edit" (vs. "new").
 * @var array|null  $flash   Flashed validation errors + previously submitted values, if any.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ptp_not_found = ! empty( $is_edit ) && ! $event;
$ptp_projects  = PTP_Projects_Repository::get_options_for_select();
$ptp_tasks     = PTP_Tasks_Repository::get_options_for_select();
$ptp_milestones = PTP_Milestones_Repository::get_options_for_select();

$ptp_reminder_options = array(
	''     => __( 'No reminder', 'personal-project-tracker' ),
	'0'    => __( 'At time of event', 'personal-project-tracker' ),
	'5'    => __( '5 minutes before', 'personal-project-tracker' ),
	'15'   => __( '15 minutes before', 'personal-project-tracker' ),
	'30'   => __( '30 minutes before', 'personal-project-tracker' ),
	'60'   => __( '1 hour before', 'personal-project-tracker' ),
	'1440' => __( '1 day before', 'personal-project-tracker' ),
);

// A "+ New Event" link from the calendar can preselect the clicked date.
$ptp_default_start_date = isset( $_GET['start_date'] ) ? sanitize_text_field( wp_unslash( $_GET['start_date'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$ptp_values = array(
	'title'            => $event->title ?? '',
	'description'      => $event->description ?? '',
	'all_day'          => $event->all_day ?? 0,
	'start_date'       => isset( $event->start_datetime ) ? substr( $event->start_datetime, 0, 10 ) : $ptp_default_start_date,
	'start_time'       => isset( $event->start_datetime ) ? substr( $event->start_datetime, 11, 5 ) : '',
	'end_date'         => isset( $event->end_datetime ) ? substr( (string) $event->end_datetime, 0, 10 ) : '',
	'end_time'         => isset( $event->end_datetime ) ? substr( (string) $event->end_datetime, 11, 5 ) : '',
	'project_id'       => $event->project_id ?? '',
	'task_id'          => $event->task_id ?? '',
	'milestone_id'     => $event->milestone_id ?? '',
	'location'         => $event->location ?? '',
	'color'             => $event->color ?? '#2271b1',
	'reminder_minutes' => isset( $event->reminder_minutes ) ? (string) $event->reminder_minutes : '',
);

if ( ! empty( $flash['data'] ) ) {
	$ptp_values = wp_parse_args( $flash['data'], $ptp_values );
}
?>
<h1><?php echo ! empty( $is_edit ) ? esc_html__( 'Edit Event', 'personal-project-tracker' ) : esc_html__( 'Add New Event', 'personal-project-tracker' ); ?></h1>

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
	<div class="notice notice-error"><p><?php esc_html_e( 'That event could not be found.', 'personal-project-tracker' ); ?></p></div>
<?php else : ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ptp-card ptp-event-form">
		<input type="hidden" name="action" value="ptp_save_event" />
		<?php if ( ! empty( $is_edit ) && $event ) : ?>
			<input type="hidden" name="id" value="<?php echo esc_attr( $event->id ); ?>" />
		<?php endif; ?>
		<?php PTP_Security::nonce_field(); ?>

		<div class="ptp-form-grid">
			<div class="ptp-form-field ptp-form-field-full">
				<label for="ptp-title"><?php esc_html_e( 'Title', 'personal-project-tracker' ); ?> <span class="ptp-required" aria-hidden="true">*</span></label>
				<input type="text" id="ptp-title" name="title" maxlength="255" required value="<?php echo esc_attr( $ptp_values['title'] ); ?>" />
			</div>

			<div class="ptp-form-field ptp-form-field-full">
				<label for="ptp-description"><?php esc_html_e( 'Description', 'personal-project-tracker' ); ?></label>
				<textarea id="ptp-description" name="description" rows="4"><?php echo esc_textarea( $ptp_values['description'] ); ?></textarea>
			</div>

			<div class="ptp-form-field ptp-form-field-full">
				<label>
					<input type="checkbox" id="ptp-all-day" name="all_day" value="1" <?php checked( $ptp_values['all_day'], 1 ); ?> />
					<?php esc_html_e( 'All day', 'personal-project-tracker' ); ?>
				</label>
			</div>

			<div class="ptp-form-field">
				<label for="ptp-start-date"><?php esc_html_e( 'Start Date', 'personal-project-tracker' ); ?> <span class="ptp-required" aria-hidden="true">*</span></label>
				<input type="date" id="ptp-start-date" name="start_date" required value="<?php echo esc_attr( $ptp_values['start_date'] ); ?>" />
			</div>

			<div class="ptp-form-field ptp-event-time-field">
				<label for="ptp-start-time"><?php esc_html_e( 'Start Time', 'personal-project-tracker' ); ?></label>
				<input type="time" id="ptp-start-time" name="start_time" value="<?php echo esc_attr( $ptp_values['start_time'] ); ?>" />
			</div>

			<div class="ptp-form-field">
				<label for="ptp-end-date"><?php esc_html_e( 'End Date', 'personal-project-tracker' ); ?></label>
				<input type="date" id="ptp-end-date" name="end_date" value="<?php echo esc_attr( $ptp_values['end_date'] ); ?>" />
			</div>

			<div class="ptp-form-field ptp-event-time-field">
				<label for="ptp-end-time"><?php esc_html_e( 'End Time', 'personal-project-tracker' ); ?></label>
				<input type="time" id="ptp-end-time" name="end_time" value="<?php echo esc_attr( $ptp_values['end_time'] ); ?>" />
			</div>

			<div class="ptp-form-field">
				<label for="ptp-project"><?php esc_html_e( 'Project', 'personal-project-tracker' ); ?></label>
				<select id="ptp-project" name="project_id">
					<option value=""><?php esc_html_e( '— None —', 'personal-project-tracker' ); ?></option>
					<?php foreach ( $ptp_projects as $ptp_pid => $ptp_ptitle ) : ?>
						<option value="<?php echo esc_attr( $ptp_pid ); ?>" <?php selected( (string) $ptp_values['project_id'], (string) $ptp_pid ); ?>>
							<?php echo esc_html( $ptp_ptitle ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="ptp-form-field">
				<label for="ptp-task"><?php esc_html_e( 'Task', 'personal-project-tracker' ); ?></label>
				<select id="ptp-task" name="task_id">
					<option value=""><?php esc_html_e( '— None —', 'personal-project-tracker' ); ?></option>
					<?php foreach ( $ptp_tasks as $ptp_tid => $ptp_ttitle ) : ?>
						<option value="<?php echo esc_attr( $ptp_tid ); ?>" <?php selected( (string) $ptp_values['task_id'], (string) $ptp_tid ); ?>>
							<?php echo esc_html( $ptp_ttitle ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="ptp-form-field">
				<label for="ptp-milestone"><?php esc_html_e( 'Milestone', 'personal-project-tracker' ); ?></label>
				<select id="ptp-milestone" name="milestone_id">
					<option value=""><?php esc_html_e( '— None —', 'personal-project-tracker' ); ?></option>
					<?php foreach ( $ptp_milestones as $ptp_mid => $ptp_mtitle ) : ?>
						<option value="<?php echo esc_attr( $ptp_mid ); ?>" <?php selected( (string) $ptp_values['milestone_id'], (string) $ptp_mid ); ?>>
							<?php echo esc_html( $ptp_mtitle ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="ptp-form-field">
				<label for="ptp-location"><?php esc_html_e( 'Location', 'personal-project-tracker' ); ?></label>
				<input type="text" id="ptp-location" name="location" maxlength="255" value="<?php echo esc_attr( $ptp_values['location'] ); ?>" />
			</div>

			<div class="ptp-form-field">
				<label for="ptp-color"><?php esc_html_e( 'Color', 'personal-project-tracker' ); ?></label>
				<input type="color" id="ptp-color" name="color" value="<?php echo esc_attr( $ptp_values['color'] ? $ptp_values['color'] : '#2271b1' ); ?>" />
			</div>

			<div class="ptp-form-field">
				<label for="ptp-reminder"><?php esc_html_e( 'Reminder', 'personal-project-tracker' ); ?></label>
				<select id="ptp-reminder" name="reminder_minutes">
					<?php foreach ( $ptp_reminder_options as $ptp_rval => $ptp_rlabel ) : ?>
						<option value="<?php echo esc_attr( $ptp_rval ); ?>" <?php selected( (string) $ptp_values['reminder_minutes'], $ptp_rval ); ?>>
							<?php echo esc_html( $ptp_rlabel ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Saved for the upcoming Notifications module — no reminder is sent yet.', 'personal-project-tracker' ); ?></p>
			</div>
		</div>

		<p class="ptp-form-actions">
			<button type="submit" class="button button-primary">
				<?php echo ! empty( $is_edit ) ? esc_html__( 'Update Event', 'personal-project-tracker' ) : esc_html__( 'Create Event', 'personal-project-tracker' ); ?>
			</button>
			<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-calendar' ), admin_url( 'admin.php' ) ) ); ?>">
				<?php esc_html_e( 'Cancel', 'personal-project-tracker' ); ?>
			</a>
		</p>
	</form>

<?php endif; ?>
