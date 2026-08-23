<?php
/**
 * AI Prompt Studio: Add/Edit prompt template form.
 *
 * A template is a reusable starting point for the prompt form (role,
 * output format, requirements, constraints, goal placeholder) — it is
 * never itself a generated or saved prompt.
 *
 * @package Personal_Project_Tracker
 *
 * @var object|null $template Existing template row when editing, null when creating or not found.
 * @var bool        $is_edit  Whether this request is an "edit" (vs. "new").
 * @var array|null  $flash    Flashed validation errors + previously submitted values, if any.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ptp_not_found = ! empty( $is_edit ) && ! $template;
$ptp_data      = PTP_Prompt_Templates_Repository::get_template_data( $template );

$ptp_values = array(
	'name'                => $template->name ?? '',
	'category'            => $template->category ?? '',
	'role'                => $ptp_data['role'] ?? 'custom',
	'custom_role_label'   => $ptp_data['custom_role_label'] ?? '',
	'output_format'       => $ptp_data['output_format'] ?? 'plain_text',
	'custom_output_label' => $ptp_data['custom_output_label'] ?? '',
	'goal_placeholder'    => $ptp_data['goal_placeholder'] ?? '',
	'requirements'        => $ptp_data['requirements'] ?? '',
	'constraints'         => $ptp_data['constraints'] ?? '',
);

if ( ! empty( $flash['data'] ) ) {
	$ptp_values = wp_parse_args( $flash['data'], $ptp_values );
}
?>
<h1><?php echo ! empty( $is_edit ) ? esc_html__( 'Edit Template', 'personal-project-tracker' ) : esc_html__( 'New Prompt Template', 'personal-project-tracker' ); ?></h1>

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
	<div class="notice notice-error"><p><?php esc_html_e( 'That template could not be found.', 'personal-project-tracker' ); ?></p></div>
<?php else : ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ptp-card" id="ptp-template-form">
		<input type="hidden" name="action" value="ptp_save_prompt_template" />
		<?php if ( ! empty( $is_edit ) && $template ) : ?>
			<input type="hidden" name="id" value="<?php echo esc_attr( $template->id ); ?>" />
		<?php endif; ?>
		<?php PTP_Security::nonce_field(); ?>

		<div class="ptp-form-grid">
			<div class="ptp-form-field">
				<label for="ptp-name"><?php esc_html_e( 'Name', 'personal-project-tracker' ); ?></label>
				<input type="text" id="ptp-name" name="name" maxlength="255" required value="<?php echo esc_attr( $ptp_values['name'] ); ?>" />
			</div>

			<div class="ptp-form-field">
				<label for="ptp-category"><?php esc_html_e( 'Category', 'personal-project-tracker' ); ?></label>
				<input type="text" id="ptp-category" name="category" maxlength="100" value="<?php echo esc_attr( $ptp_values['category'] ); ?>" />
			</div>

			<div class="ptp-form-field">
				<label for="ptp-role"><?php esc_html_e( 'AI Role', 'personal-project-tracker' ); ?></label>
				<select id="ptp-role" name="role">
					<?php foreach ( PTP_Prompt_Generator::ROLES as $ptp_rkey => $ptp_rlabel ) : ?>
						<option value="<?php echo esc_attr( $ptp_rkey ); ?>" <?php selected( $ptp_values['role'], $ptp_rkey ); ?>><?php echo esc_html( $ptp_rlabel ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="ptp-form-field">
				<label for="ptp-custom-role-label"><?php esc_html_e( 'Custom Role Label', 'personal-project-tracker' ); ?></label>
				<input type="text" id="ptp-custom-role-label" name="custom_role_label" maxlength="255" value="<?php echo esc_attr( $ptp_values['custom_role_label'] ); ?>" />
			</div>

			<div class="ptp-form-field">
				<label for="ptp-output-format"><?php esc_html_e( 'Output Format', 'personal-project-tracker' ); ?></label>
				<select id="ptp-output-format" name="output_format">
					<?php foreach ( PTP_Prompt_Generator::OUTPUT_FORMATS as $ptp_okey => $ptp_olabel ) : ?>
						<option value="<?php echo esc_attr( $ptp_okey ); ?>" <?php selected( $ptp_values['output_format'], $ptp_okey ); ?>><?php echo esc_html( $ptp_olabel ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="ptp-form-field">
				<label for="ptp-custom-output-label"><?php esc_html_e( 'Custom Output Instruction', 'personal-project-tracker' ); ?></label>
				<input type="text" id="ptp-custom-output-label" name="custom_output_label" maxlength="255" value="<?php echo esc_attr( $ptp_values['custom_output_label'] ); ?>" />
			</div>

			<div class="ptp-form-field ptp-form-field-full">
				<label for="ptp-goal-placeholder"><?php esc_html_e( 'Goal Placeholder', 'personal-project-tracker' ); ?></label>
				<textarea id="ptp-goal-placeholder" name="goal_placeholder" rows="2"><?php echo esc_textarea( $ptp_values['goal_placeholder'] ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Suggested starting text for the Goal field when this template is used.', 'personal-project-tracker' ); ?></p>
			</div>

			<div class="ptp-form-field ptp-form-field-full">
				<label for="ptp-requirements"><?php esc_html_e( 'Requirements', 'personal-project-tracker' ); ?></label>
				<textarea id="ptp-requirements" name="requirements" rows="3"><?php echo esc_textarea( $ptp_values['requirements'] ); ?></textarea>
			</div>

			<div class="ptp-form-field ptp-form-field-full">
				<label for="ptp-constraints"><?php esc_html_e( 'Constraints', 'personal-project-tracker' ); ?></label>
				<textarea id="ptp-constraints" name="constraints" rows="3"><?php echo esc_textarea( $ptp_values['constraints'] ); ?></textarea>
			</div>
		</div>

		<p class="ptp-form-actions">
			<button type="submit" class="button button-primary">
				<?php echo ! empty( $is_edit ) ? esc_html__( 'Update Template', 'personal-project-tracker' ) : esc_html__( 'Create Template', 'personal-project-tracker' ); ?>
			</button>
			<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-ai-prompts', 'action' => 'templates' ), admin_url( 'admin.php' ) ) ); ?>">
				<?php esc_html_e( 'Cancel', 'personal-project-tracker' ); ?>
			</a>
		</p>
	</form>

<?php endif; ?>
