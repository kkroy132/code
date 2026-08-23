<?php
/**
 * Add/Edit Link form.
 *
 * @package Personal_Project_Tracker
 *
 * @var object|null $link    Existing link row when editing, null when creating or not found.
 * @var bool        $is_edit Whether this request is an "edit" (vs. "new").
 * @var array|null  $flash   Flashed validation errors + previously submitted values, if any.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ptp_not_found  = ! empty( $is_edit ) && ! $link;
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

$ptp_default_project_id   = isset( $_GET['project_id'] ) ? absint( $_GET['project_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_default_task_id      = isset( $_GET['task_id'] ) ? absint( $_GET['task_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_default_milestone_id = isset( $_GET['milestone_id'] ) ? absint( $_GET['milestone_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$ptp_values = array(
	'title'        => $link->title ?? '',
	'url'          => $link->url ?? '',
	'description'  => $link->description ?? '',
	'category'     => $link->category ?? '',
	'project_id'   => $link->project_id ?? $ptp_default_project_id,
	'task_id'      => $link->task_id ?? $ptp_default_task_id,
	'milestone_id' => $link->milestone_id ?? $ptp_default_milestone_id,
);

if ( ! empty( $flash['data'] ) ) {
	$ptp_values = wp_parse_args( $flash['data'], $ptp_values );
}
?>
<h1><?php echo ! empty( $is_edit ) ? esc_html__( 'Edit Link', 'personal-project-tracker' ) : esc_html__( 'Add New Link', 'personal-project-tracker' ); ?></h1>

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
	<div class="notice notice-error"><p><?php esc_html_e( 'That link could not be found.', 'personal-project-tracker' ); ?></p></div>
<?php else : ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ptp-card ptp-link-form">
		<input type="hidden" name="action" value="ptp_save_link" />
		<?php if ( ! empty( $is_edit ) && $link ) : ?>
			<input type="hidden" name="id" value="<?php echo esc_attr( $link->id ); ?>" />
		<?php endif; ?>
		<?php PTP_Security::nonce_field(); ?>

		<div class="ptp-form-grid">
			<div class="ptp-form-field ptp-form-field-full">
				<label for="ptp-title"><?php esc_html_e( 'Title', 'personal-project-tracker' ); ?> <span class="ptp-required">*</span></label>
				<input type="text" id="ptp-title" name="title" maxlength="255" required value="<?php echo esc_attr( $ptp_values['title'] ); ?>" />
			</div>

			<div class="ptp-form-field ptp-form-field-full">
				<label for="ptp-url"><?php esc_html_e( 'URL', 'personal-project-tracker' ); ?> <span class="ptp-required">*</span></label>
				<input type="url" id="ptp-url" name="url" maxlength="1000" required placeholder="https://example.com" value="<?php echo esc_attr( $ptp_values['url'] ); ?>" />
			</div>

			<div class="ptp-form-field ptp-form-field-full">
				<label for="ptp-description"><?php esc_html_e( 'Description', 'personal-project-tracker' ); ?></label>
				<textarea id="ptp-description" name="description" rows="3"><?php echo esc_textarea( $ptp_values['description'] ); ?></textarea>
			</div>

			<div class="ptp-form-field">
				<label for="ptp-category"><?php esc_html_e( 'Category', 'personal-project-tracker' ); ?></label>
				<input type="text" id="ptp-category" name="category" maxlength="100" value="<?php echo esc_attr( $ptp_values['category'] ); ?>" />
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
		</div>

		<p class="ptp-form-actions">
			<button type="submit" class="button button-primary">
				<?php echo ! empty( $is_edit ) ? esc_html__( 'Update Link', 'personal-project-tracker' ) : esc_html__( 'Create Link', 'personal-project-tracker' ); ?>
			</button>
			<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-links' ), admin_url( 'admin.php' ) ) ); ?>">
				<?php esc_html_e( 'Cancel', 'personal-project-tracker' ); ?>
			</a>
		</p>
	</form>

<?php endif; ?>
