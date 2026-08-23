<?php
/**
 * Add/Edit Project form.
 *
 * @package Personal_Project_Tracker
 *
 * @var object|null $project Existing project row when editing, null when creating or not found.
 * @var bool        $is_edit Whether this request is an "edit" (vs. "new") — needed because
 *                            $project is null both for a fresh "new" form and for an edit
 *                            request whose ID no longer exists.
 * @var array|null  $flash   Flashed validation errors + previously submitted values, if any.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ptp_not_found = ! empty( $is_edit ) && ! $project;
$ptp_statuses = PTP_Projects_Repository::get_statuses();
$ptp_priorities = PTP_Projects_Repository::get_priorities();

// Flashed (invalid) submitted values take priority over the stored record so the user's input is never lost.
$ptp_values = array(
	'title'             => $project->title ?? '',
	'description'       => $project->description ?? '',
	'status'            => $project->status ?? PTP_Settings::get( 'default_project_status', 'planning' ),
	'priority'          => $project->priority ?? 'medium',
	'start_date'        => $project->start_date ?? '',
	'deadline'          => $project->deadline ?? '',
	'budget'            => $project->budget ?? '',
	'currency'          => $project->currency ?? 'USD',
	'estimated_revenue' => $project->estimated_revenue ?? '',
	'progress'          => $project->progress ?? 0,
	'color'             => $project->color ?? '#2271b1',
);

if ( ! empty( $flash['data'] ) ) {
	$ptp_values = wp_parse_args( $flash['data'], $ptp_values );
}

?>
<h1><?php echo ! empty( $is_edit ) ? esc_html__( 'Edit Project', 'personal-project-tracker' ) : esc_html__( 'Add New Project', 'personal-project-tracker' ); ?></h1>

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
	<div class="notice notice-error"><p><?php esc_html_e( 'That project could not be found.', 'personal-project-tracker' ); ?></p></div>
<?php else : ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ptp-card ptp-project-form">
		<input type="hidden" name="action" value="ptp_save_project" />
		<?php if ( ! empty( $is_edit ) && $project ) : ?>
			<input type="hidden" name="id" value="<?php echo esc_attr( $project->id ); ?>" />
		<?php endif; ?>
		<?php PTP_Security::nonce_field(); ?>

		<div class="ptp-form-grid">
			<div class="ptp-form-field ptp-form-field-full">
				<label for="ptp-title"><?php esc_html_e( 'Title', 'personal-project-tracker' ); ?> <span class="ptp-required" aria-hidden="true">*</span></label>
				<input type="text" id="ptp-title" name="title" maxlength="255" required value="<?php echo esc_attr( $ptp_values['title'] ); ?>" />
			</div>

			<div class="ptp-form-field ptp-form-field-full">
				<label for="ptp-description"><?php esc_html_e( 'Description', 'personal-project-tracker' ); ?></label>
				<textarea id="ptp-description" name="description" rows="5"><?php echo esc_textarea( $ptp_values['description'] ); ?></textarea>
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
				<label for="ptp-deadline"><?php esc_html_e( 'Deadline', 'personal-project-tracker' ); ?></label>
				<input type="date" id="ptp-deadline" name="deadline" value="<?php echo esc_attr( $ptp_values['deadline'] ); ?>" />
			</div>

			<div class="ptp-form-field">
				<label for="ptp-budget"><?php esc_html_e( 'Budget', 'personal-project-tracker' ); ?></label>
				<input type="number" id="ptp-budget" name="budget" min="0" step="0.01" value="<?php echo esc_attr( $ptp_values['budget'] ); ?>" />
			</div>

			<div class="ptp-form-field">
				<label for="ptp-currency"><?php esc_html_e( 'Currency', 'personal-project-tracker' ); ?></label>
				<input type="text" id="ptp-currency" name="currency" maxlength="10" placeholder="USD" value="<?php echo esc_attr( $ptp_values['currency'] ); ?>" />
			</div>

			<div class="ptp-form-field">
				<label for="ptp-estimated-revenue"><?php esc_html_e( 'Estimated Revenue', 'personal-project-tracker' ); ?></label>
				<input type="number" id="ptp-estimated-revenue" name="estimated_revenue" min="0" step="0.01" value="<?php echo esc_attr( $ptp_values['estimated_revenue'] ); ?>" />
			</div>

			<div class="ptp-form-field">
				<label for="ptp-progress"><?php esc_html_e( 'Progress (%)', 'personal-project-tracker' ); ?></label>
				<input type="number" id="ptp-progress" name="progress" min="0" max="100" step="1" value="<?php echo esc_attr( $ptp_values['progress'] ); ?>" />
			</div>

			<div class="ptp-form-field">
				<label for="ptp-color"><?php esc_html_e( 'Color', 'personal-project-tracker' ); ?></label>
				<input type="color" id="ptp-color" name="color" value="<?php echo esc_attr( $ptp_values['color'] ? $ptp_values['color'] : '#2271b1' ); ?>" />
			</div>
		</div>

		<p class="ptp-form-actions">
			<button type="submit" class="button button-primary">
				<?php echo ! empty( $is_edit ) ? esc_html__( 'Update Project', 'personal-project-tracker' ) : esc_html__( 'Create Project', 'personal-project-tracker' ); ?>
			</button>
			<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-projects' ), admin_url( 'admin.php' ) ) ); ?>">
				<?php esc_html_e( 'Cancel', 'personal-project-tracker' ); ?>
			</a>
		</p>
	</form>

<?php endif; ?>
