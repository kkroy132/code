<?php
/**
 * Add/Edit Reminder form.
 *
 * @package Personal_Project_Tracker
 *
 * @var object|null $reminder Existing reminder row when editing, null when creating or not found.
 * @var bool        $is_edit  Whether this request is an "edit" (vs. "new").
 * @var array|null  $flash    Flashed validation errors + previously submitted values, if any.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ptp_not_found = ! empty( $is_edit ) && ! $reminder;

$ptp_projects   = PTP_Projects_Repository::get_options_for_select();
$ptp_tasks      = PTP_Tasks_Repository::get_options_for_select();
$ptp_milestones = PTP_Milestones_Repository::get_options_for_select();

$ptp_events = array();
foreach ( PTP_Calendar_Repository::get_events_with_reminders() as $ptp_event_row ) {
	$ptp_events[ (int) $ptp_event_row->id ] = $ptp_event_row->title;
}
// Also offer every event without a reminder configured, so a manual reminder can still target it.
foreach ( PTP_Calendar_Repository::get_options_for_select() as $ptp_event_id => $ptp_event_title ) {
	if ( ! isset( $ptp_events[ $ptp_event_id ] ) ) {
		$ptp_events[ $ptp_event_id ] = $ptp_event_title;
	}
}

$ptp_default_related_type = isset( $_GET['related_type'] ) ? sanitize_key( wp_unslash( $_GET['related_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_default_related_id   = isset( $_GET['related_id'] ) ? absint( $_GET['related_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$ptp_values = array(
	'title'               => $reminder->title ?? '',
	'related_type'        => $reminder->related_type ?? $ptp_default_related_type,
	'related_id'          => $reminder->related_id ?? $ptp_default_related_id,
	'remind_at'           => ( $reminder && $reminder->remind_at ) ? substr( str_replace( ' ', 'T', $reminder->remind_at ), 0, 16 ) : '',
	'recurrence'          => $reminder->recurrence ?? 'none',
	'recurrence_interval' => $reminder->recurrence_interval ?? 1,
);

if ( ! empty( $flash['data'] ) ) {
	$ptp_values = wp_parse_args( $flash['data'], $ptp_values );
}

$ptp_breadcrumbs = array(
	array( 'label' => __( 'Project Tracker', 'personal-project-tracker' ), 'url' => add_query_arg( array( 'page' => 'ptp-dashboard' ), admin_url( 'admin.php' ) ) ),
	array( 'label' => __( 'Notifications', 'personal-project-tracker' ), 'url' => add_query_arg( array( 'page' => 'ptp-notifications' ), admin_url( 'admin.php' ) ) ),
	array( 'label' => __( 'Reminders', 'personal-project-tracker' ), 'url' => add_query_arg( array( 'page' => 'ptp-notifications', 'action' => 'reminders' ), admin_url( 'admin.php' ) ) ),
	array( 'label' => ! empty( $is_edit ) ? __( 'Edit Reminder', 'personal-project-tracker' ) : __( 'New Reminder', 'personal-project-tracker' ) ),
);
require PTP_PLUGIN_DIR . 'admin/views/partials/breadcrumbs.php';
?>
<h1><?php echo ! empty( $is_edit ) ? esc_html__( 'Edit Reminder', 'personal-project-tracker' ) : esc_html__( 'New Reminder', 'personal-project-tracker' ); ?></h1>

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
	<div class="notice notice-error"><p><?php esc_html_e( 'That reminder could not be found.', 'personal-project-tracker' ); ?></p></div>
<?php else : ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ptp-card" id="ptp-reminder-form">
		<input type="hidden" name="action" value="ptp_save_reminder" />
		<?php if ( ! empty( $is_edit ) && $reminder ) : ?>
			<input type="hidden" name="id" value="<?php echo esc_attr( $reminder->id ); ?>" />
		<?php endif; ?>
		<?php PTP_Security::nonce_field(); ?>

		<div class="ptp-form-grid">
			<div class="ptp-form-field ptp-form-field-full">
				<label for="ptp-title"><?php esc_html_e( 'Title', 'personal-project-tracker' ); ?></label>
				<input type="text" id="ptp-title" name="title" maxlength="255" required value="<?php echo esc_attr( $ptp_values['title'] ); ?>" />
			</div>

			<div class="ptp-form-field">
				<label for="ptp-related-type"><?php esc_html_e( 'Related To', 'personal-project-tracker' ); ?></label>
				<select id="ptp-related-type" name="related_type">
					<option value=""><?php esc_html_e( 'Custom (not related to anything)', 'personal-project-tracker' ); ?></option>
					<?php foreach ( PTP_Reminders_Repository::get_related_types() as $ptp_rkey => $ptp_rlabel ) : ?>
						<option value="<?php echo esc_attr( $ptp_rkey ); ?>" <?php selected( $ptp_values['related_type'], $ptp_rkey ); ?>><?php echo esc_html( $ptp_rlabel ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php if ( $reminder && $reminder->related_type && $reminder->related_id ) : ?>
					<p class="description">
						<a href="<?php echo esc_url( PTP_Notifications_Service::get_deep_link( $reminder->related_type, $reminder->related_id ) ); ?>">
							<?php esc_html_e( 'View related record &raquo;', 'personal-project-tracker' ); ?>
						</a>
					</p>
				<?php endif; ?>
			</div>

			<div class="ptp-form-field ptp-js-related-id-field" data-type="project" <?php echo 'project' === $ptp_values['related_type'] ? '' : 'style="display:none;"'; ?>>
				<label for="ptp-related-project"><?php esc_html_e( 'Project', 'personal-project-tracker' ); ?></label>
				<select id="ptp-related-project" name="related_id_project" class="ptp-js-related-id" data-type="project">
					<option value=""><?php esc_html_e( '— Select —', 'personal-project-tracker' ); ?></option>
					<?php foreach ( $ptp_projects as $ptp_pid => $ptp_ptitle ) : ?>
						<option value="<?php echo esc_attr( $ptp_pid ); ?>" <?php selected( 'project' === $ptp_values['related_type'] ? (int) $ptp_values['related_id'] : 0, $ptp_pid ); ?>><?php echo esc_html( $ptp_ptitle ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="ptp-form-field ptp-js-related-id-field" data-type="task" <?php echo 'task' === $ptp_values['related_type'] ? '' : 'style="display:none;"'; ?>>
				<label for="ptp-related-task"><?php esc_html_e( 'Task', 'personal-project-tracker' ); ?></label>
				<select id="ptp-related-task" name="related_id_task" class="ptp-js-related-id" data-type="task">
					<option value=""><?php esc_html_e( '— Select —', 'personal-project-tracker' ); ?></option>
					<?php foreach ( $ptp_tasks as $ptp_tid => $ptp_ttitle ) : ?>
						<option value="<?php echo esc_attr( $ptp_tid ); ?>" <?php selected( 'task' === $ptp_values['related_type'] ? (int) $ptp_values['related_id'] : 0, $ptp_tid ); ?>><?php echo esc_html( $ptp_ttitle ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="ptp-form-field ptp-js-related-id-field" data-type="milestone" <?php echo 'milestone' === $ptp_values['related_type'] ? '' : 'style="display:none;"'; ?>>
				<label for="ptp-related-milestone"><?php esc_html_e( 'Milestone', 'personal-project-tracker' ); ?></label>
				<select id="ptp-related-milestone" name="related_id_milestone" class="ptp-js-related-id" data-type="milestone">
					<option value=""><?php esc_html_e( '— Select —', 'personal-project-tracker' ); ?></option>
					<?php foreach ( $ptp_milestones as $ptp_mid => $ptp_mtitle ) : ?>
						<option value="<?php echo esc_attr( $ptp_mid ); ?>" <?php selected( 'milestone' === $ptp_values['related_type'] ? (int) $ptp_values['related_id'] : 0, $ptp_mid ); ?>><?php echo esc_html( $ptp_mtitle ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="ptp-form-field ptp-js-related-id-field" data-type="calendar_event" <?php echo 'calendar_event' === $ptp_values['related_type'] ? '' : 'style="display:none;"'; ?>>
				<label for="ptp-related-event"><?php esc_html_e( 'Calendar Event', 'personal-project-tracker' ); ?></label>
				<select id="ptp-related-event" name="related_id_calendar_event" class="ptp-js-related-id" data-type="calendar_event">
					<option value=""><?php esc_html_e( '— Select —', 'personal-project-tracker' ); ?></option>
					<?php foreach ( $ptp_events as $ptp_eid => $ptp_etitle ) : ?>
						<option value="<?php echo esc_attr( $ptp_eid ); ?>" <?php selected( 'calendar_event' === $ptp_values['related_type'] ? (int) $ptp_values['related_id'] : 0, $ptp_eid ); ?>><?php echo esc_html( $ptp_etitle ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<input type="hidden" id="ptp-related-id" name="related_id" value="<?php echo esc_attr( $ptp_values['related_id'] ); ?>" />

			<div class="ptp-form-field">
				<label for="ptp-remind-at"><?php esc_html_e( 'Remind At', 'personal-project-tracker' ); ?></label>
				<input type="datetime-local" id="ptp-remind-at" name="remind_at" required value="<?php echo esc_attr( $ptp_values['remind_at'] ); ?>" />
			</div>

			<div class="ptp-form-field">
				<label for="ptp-recurrence"><?php esc_html_e( 'Repeat', 'personal-project-tracker' ); ?></label>
				<select id="ptp-recurrence" name="recurrence">
					<?php foreach ( PTP_Reminders_Repository::get_recurrences() as $ptp_rkey => $ptp_rlabel ) : ?>
						<option value="<?php echo esc_attr( $ptp_rkey ); ?>" <?php selected( $ptp_values['recurrence'], $ptp_rkey ); ?>><?php echo esc_html( $ptp_rlabel ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="ptp-form-field" id="ptp-recurrence-interval-field" <?php echo 'custom' === $ptp_values['recurrence'] ? '' : 'style="display:none;"'; ?>>
				<label for="ptp-recurrence-interval"><?php esc_html_e( 'Repeat Every (days)', 'personal-project-tracker' ); ?></label>
				<input type="number" id="ptp-recurrence-interval" name="recurrence_interval" min="1" value="<?php echo esc_attr( $ptp_values['recurrence_interval'] ); ?>" />
			</div>
		</div>

		<p class="ptp-form-actions">
			<button type="submit" class="button button-primary">
				<?php echo ! empty( $is_edit ) ? esc_html__( 'Update Reminder', 'personal-project-tracker' ) : esc_html__( 'Create Reminder', 'personal-project-tracker' ); ?>
			</button>
			<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-notifications', 'action' => 'reminders' ), admin_url( 'admin.php' ) ) ); ?>">
				<?php esc_html_e( 'Cancel', 'personal-project-tracker' ); ?>
			</a>
		</p>
	</form>

<?php endif; ?>
