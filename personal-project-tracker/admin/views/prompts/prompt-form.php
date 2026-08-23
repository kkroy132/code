<?php
/**
 * AI Prompt Studio: Create/Edit prompt form.
 *
 * The basic feature never calls an external AI API — Generate assembles
 * text from this plugin's own data via PTP_Prompt_Generator, and Save
 * persists exactly whatever is currently in the content box (Generate and
 * Save are separate actions, so editing the generated text and saving
 * never triggers a silent regeneration).
 *
 * @package Personal_Project_Tracker
 *
 * @var object|null $prompt  Existing prompt document row when editing, null when creating or not found.
 * @var bool        $is_edit Whether this request is an "edit" (vs. "new").
 * @var array|null  $flash   Flashed validation errors + previously submitted values, if any.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ptp_not_found = ! empty( $is_edit ) && ! $prompt;
$ptp_config    = PTP_Prompt_Documents_Repository::get_config( $prompt );

$ptp_projects       = PTP_Projects_Repository::get_options_for_select();
$ptp_milestones_map = PTP_Milestones_Repository::get_options_for_select();
$ptp_can_finance    = current_user_can( 'ptp_manage_finance' );

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

$ptp_milestone_rows = array();
foreach ( PTP_Milestones_Repository::get_list( array( 'view' => 'active', 'orderby' => 'title', 'order' => 'ASC', 'per_page' => 500 ) )['items'] as $ptp_m_row ) {
	$ptp_milestone_rows[ (int) $ptp_m_row->id ] = array(
		'title'      => $ptp_m_row->title,
		'project_id' => (int) $ptp_m_row->project_id,
	);
}

$ptp_templates = PTP_Prompt_Templates_Repository::get_list( array( 'per_page' => 100 ) )['items'];

// Quick actions from a Project/Task/Milestone/Finance/Report page can preselect context.
$ptp_default_context_type = isset( $_GET['context_type'] ) ? sanitize_key( wp_unslash( $_GET['context_type'] ) ) : 'custom'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_default_project_id   = isset( $_GET['project_id'] ) ? absint( $_GET['project_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_default_task_ids     = isset( $_GET['task_ids'] ) ? array_map( 'absint', (array) $_GET['task_ids'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_default_milestone_ids = isset( $_GET['milestone_ids'] ) ? array_map( 'absint', (array) $_GET['milestone_ids'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_default_include_finance = isset( $_GET['include_finance'] ) && $ptp_can_finance; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_default_include_reports = isset( $_GET['include_reports'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

// A "Use" link from the Templates list applies a template's defaults server-side.
$ptp_template_from_query = array();
$ptp_template_id         = isset( $_GET['template_id'] ) ? absint( $_GET['template_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

if ( $ptp_template_id && ! $prompt ) {
	$ptp_template_row = PTP_Prompt_Templates_Repository::get( $ptp_template_id );

	if ( $ptp_template_row ) {
		$ptp_template_from_query = PTP_Prompt_Templates_Repository::get_template_data( $ptp_template_row );
	}
}

$ptp_values = array(
	'title'                => $prompt->title ?? '',
	'context_type'         => $prompt->context_type ?? $ptp_default_context_type,
	'project_id'           => $prompt->project_id ?? $ptp_default_project_id,
	'role'                 => $prompt->role ?? ( $ptp_template_from_query['role'] ?? 'software_engineer' ),
	'goal'                 => $prompt->goal ?? ( $ptp_template_from_query['goal_placeholder'] ?? '' ),
	'output_format'        => $prompt->output_format ?? ( $ptp_template_from_query['output_format'] ?? 'markdown' ),
	'content'              => $prompt->content ?? '',
	'task_ids'             => $ptp_config['task_ids'] ?? $ptp_default_task_ids,
	'milestone_ids'        => $ptp_config['milestone_ids'] ?? $ptp_default_milestone_ids,
	'include_time'         => $ptp_config['include_time'] ?? false,
	'include_notes'        => $ptp_config['include_notes'] ?? false,
	'include_links'        => $ptp_config['include_links'] ?? false,
	'include_files'        => $ptp_config['include_files'] ?? false,
	'include_finance'      => $ptp_config['include_finance'] ?? $ptp_default_include_finance,
	'include_reports'      => $ptp_config['include_reports'] ?? $ptp_default_include_reports,
	'include_activity'     => $ptp_config['include_activity'] ?? false,
	'requirements'         => $ptp_config['requirements'] ?? ( $ptp_template_from_query['requirements'] ?? '' ),
	'constraints'          => $ptp_config['constraints'] ?? ( $ptp_template_from_query['constraints'] ?? '' ),
	'custom_role_label'    => $ptp_config['custom_role_label'] ?? ( $ptp_template_from_query['custom_role_label'] ?? '' ),
	'custom_output_label'  => $ptp_config['custom_output_label'] ?? ( $ptp_template_from_query['custom_output_label'] ?? '' ),
	'ai_tool'              => $ptp_config['ai_tool'] ?? PTP_Settings::get( 'ai_prompt_default_tool', 'generic' ),
);

if ( ! empty( $flash['data'] ) ) {
	$ptp_flash_config = is_array( $flash['data']['config'] ?? null ) ? $flash['data']['config'] : array();
	$ptp_values        = wp_parse_args( array_merge( $flash['data'], $ptp_flash_config ), $ptp_values );
}
?>
<h1><?php echo ! empty( $is_edit ) ? esc_html__( 'Edit Prompt', 'personal-project-tracker' ) : esc_html__( 'New AI Prompt', 'personal-project-tracker' ); ?></h1>

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
	<div class="notice notice-error"><p><?php esc_html_e( 'That prompt could not be found.', 'personal-project-tracker' ); ?></p></div>
<?php else : ?>

	<?php if ( ! empty( $ptp_templates ) ) : ?>
		<div class="ptp-card">
			<label for="ptp-template-apply"><?php esc_html_e( 'Start from a template', 'personal-project-tracker' ); ?></label>
			<select id="ptp-template-apply">
				<option value=""><?php esc_html_e( '— Choose a template —', 'personal-project-tracker' ); ?></option>
				<?php foreach ( $ptp_templates as $ptp_template ) : ?>
					<option value="<?php echo esc_attr( $ptp_template->id ); ?>"><?php echo esc_html( $ptp_template->name ); ?></option>
				<?php endforeach; ?>
			</select>
			<span id="ptp-template-data" style="display:none;" data-templates="<?php echo esc_attr( wp_json_encode( array_map( function ( $t ) { return array_merge( array( 'id' => (int) $t->id ), PTP_Prompt_Templates_Repository::get_template_data( $t ) ); }, $ptp_templates ) ) ); ?>"></span>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ptp-card ptp-prompt-form" id="ptp-prompt-form">
		<input type="hidden" name="action" value="ptp_save_prompt" />
		<?php if ( ! empty( $is_edit ) && $prompt ) : ?>
			<input type="hidden" name="id" value="<?php echo esc_attr( $prompt->id ); ?>" />
		<?php endif; ?>
		<?php PTP_Security::nonce_field(); ?>

		<div class="ptp-form-grid">
			<div class="ptp-form-field ptp-form-field-full">
				<label for="ptp-title"><?php esc_html_e( 'Title', 'personal-project-tracker' ); ?></label>
				<input type="text" id="ptp-title" name="title" maxlength="255" placeholder="<?php esc_attr_e( 'Optional — derived from the goal if left blank', 'personal-project-tracker' ); ?>" value="<?php echo esc_attr( $ptp_values['title'] ); ?>" />
			</div>

			<div class="ptp-form-field">
				<label for="ptp-context-type"><?php esc_html_e( 'Context Type', 'personal-project-tracker' ); ?></label>
				<select id="ptp-context-type" name="context_type">
					<?php foreach ( PTP_Prompt_Documents_Repository::get_context_types() as $ptp_ckey => $ptp_clabel ) : ?>
						<option value="<?php echo esc_attr( $ptp_ckey ); ?>" <?php selected( $ptp_values['context_type'], $ptp_ckey ); ?>><?php echo esc_html( $ptp_clabel ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="ptp-form-field">
				<label for="ptp-role"><?php esc_html_e( 'AI Role', 'personal-project-tracker' ); ?></label>
				<select id="ptp-role" name="role">
					<?php foreach ( PTP_Prompt_Generator::ROLES as $ptp_rkey => $ptp_rlabel ) : ?>
						<option value="<?php echo esc_attr( $ptp_rkey ); ?>" <?php selected( $ptp_values['role'], $ptp_rkey ); ?>><?php echo esc_html( $ptp_rlabel ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="ptp-form-field" id="ptp-custom-role-field" <?php echo 'custom' === $ptp_values['role'] ? '' : 'style="display:none;"'; ?>>
				<label for="ptp-custom-role-label"><?php esc_html_e( 'Custom Role Label', 'personal-project-tracker' ); ?></label>
				<input type="text" id="ptp-custom-role-label" name="custom_role_label" maxlength="255" value="<?php echo esc_attr( $ptp_values['custom_role_label'] ); ?>" />
			</div>

			<div class="ptp-form-field ptp-form-field-full">
				<label for="ptp-goal"><?php esc_html_e( 'Goal — what should the AI help you with?', 'personal-project-tracker' ); ?></label>
				<textarea id="ptp-goal" name="goal" rows="3" required><?php echo esc_textarea( $ptp_values['goal'] ); ?></textarea>
			</div>

			<div class="ptp-form-field">
				<label for="ptp-output-format"><?php esc_html_e( 'Output Format', 'personal-project-tracker' ); ?></label>
				<select id="ptp-output-format" name="output_format">
					<?php foreach ( PTP_Prompt_Generator::OUTPUT_FORMATS as $ptp_okey => $ptp_olabel ) : ?>
						<option value="<?php echo esc_attr( $ptp_okey ); ?>" <?php selected( $ptp_values['output_format'], $ptp_okey ); ?>><?php echo esc_html( $ptp_olabel ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="ptp-form-field" id="ptp-custom-output-field" <?php echo 'custom' === $ptp_values['output_format'] ? '' : 'style="display:none;"'; ?>>
				<label for="ptp-custom-output-label"><?php esc_html_e( 'Custom Output Instruction', 'personal-project-tracker' ); ?></label>
				<input type="text" id="ptp-custom-output-label" name="custom_output_label" maxlength="255" value="<?php echo esc_attr( $ptp_values['custom_output_label'] ); ?>" />
			</div>

			<div class="ptp-form-field">
				<label for="ptp-ai-tool"><?php esc_html_e( 'Intended AI Tool', 'personal-project-tracker' ); ?></label>
				<select id="ptp-ai-tool" name="ai_tool">
					<?php
					$ptp_tools = array(
						'generic' => __( 'Any / Generic', 'personal-project-tracker' ),
						'gemini'  => __( 'Google Gemini', 'personal-project-tracker' ),
						'claude'  => __( 'Anthropic Claude', 'personal-project-tracker' ),
						'chatgpt' => __( 'OpenAI ChatGPT', 'personal-project-tracker' ),
						'other'   => __( 'Other', 'personal-project-tracker' ),
					);
					foreach ( $ptp_tools as $ptp_tkey => $ptp_tlabel ) :
						?>
						<option value="<?php echo esc_attr( $ptp_tkey ); ?>" <?php selected( $ptp_values['ai_tool'], $ptp_tkey ); ?>><?php echo esc_html( $ptp_tlabel ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'For your reference only — the prompt is plain text you copy and paste; nothing is sent anywhere from this plugin.', 'personal-project-tracker' ); ?></p>
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

			<div class="ptp-form-field ptp-form-field-full">
				<label><?php esc_html_e( 'Tasks', 'personal-project-tracker' ); ?></label>
				<div class="ptp-checkbox-list" id="ptp-task-checklist">
					<?php foreach ( $ptp_tasks as $ptp_tid => $ptp_trow ) : ?>
						<label class="ptp-checkbox-list-item" data-project="<?php echo esc_attr( $ptp_trow['project_id'] ); ?>">
							<input type="checkbox" name="task_ids[]" value="<?php echo esc_attr( $ptp_tid ); ?>" <?php checked( in_array( $ptp_tid, array_map( 'intval', (array) $ptp_values['task_ids'] ), true ) ); ?> />
							<?php echo esc_html( $ptp_trow['title'] ); ?>
						</label>
					<?php endforeach; ?>
					<?php if ( empty( $ptp_tasks ) ) : ?>
						<p class="description"><?php esc_html_e( 'No tasks yet.', 'personal-project-tracker' ); ?></p>
					<?php endif; ?>
				</div>
			</div>

			<div class="ptp-form-field ptp-form-field-full">
				<label><?php esc_html_e( 'Milestones', 'personal-project-tracker' ); ?></label>
				<div class="ptp-checkbox-list" id="ptp-milestone-checklist">
					<?php foreach ( $ptp_milestone_rows as $ptp_mid => $ptp_mrow ) : ?>
						<label class="ptp-checkbox-list-item" data-project="<?php echo esc_attr( $ptp_mrow['project_id'] ); ?>">
							<input type="checkbox" name="milestone_ids[]" value="<?php echo esc_attr( $ptp_mid ); ?>" <?php checked( in_array( $ptp_mid, array_map( 'intval', (array) $ptp_values['milestone_ids'] ), true ) ); ?> />
							<?php echo esc_html( $ptp_mrow['title'] ); ?>
						</label>
					<?php endforeach; ?>
					<?php if ( empty( $ptp_milestone_rows ) ) : ?>
						<p class="description"><?php esc_html_e( 'No milestones yet.', 'personal-project-tracker' ); ?></p>
					<?php endif; ?>
				</div>
			</div>

			<div class="ptp-form-field ptp-form-field-full">
				<label><?php esc_html_e( 'Include additional context', 'personal-project-tracker' ); ?></label>
				<div class="ptp-checkbox-inline-list">
					<label><input type="checkbox" name="include_time" value="1" <?php checked( $ptp_values['include_time'] ); ?> /> <?php esc_html_e( 'Time', 'personal-project-tracker' ); ?></label>
					<label><input type="checkbox" name="include_notes" value="1" <?php checked( $ptp_values['include_notes'] ); ?> /> <?php esc_html_e( 'Notes', 'personal-project-tracker' ); ?></label>
					<label><input type="checkbox" name="include_links" value="1" <?php checked( $ptp_values['include_links'] ); ?> /> <?php esc_html_e( 'Links', 'personal-project-tracker' ); ?></label>
					<label><input type="checkbox" name="include_files" value="1" <?php checked( $ptp_values['include_files'] ); ?> /> <?php esc_html_e( 'File Metadata', 'personal-project-tracker' ); ?></label>
					<?php if ( $ptp_can_finance ) : ?>
						<label><input type="checkbox" name="include_finance" value="1" <?php checked( $ptp_values['include_finance'] ); ?> /> <?php esc_html_e( 'Finance', 'personal-project-tracker' ); ?></label>
					<?php else : ?>
						<label class="ptp-checkbox-disabled" title="<?php esc_attr_e( 'Your account cannot view Finance.', 'personal-project-tracker' ); ?>"><input type="checkbox" disabled="disabled" /> <?php esc_html_e( 'Finance', 'personal-project-tracker' ); ?></label>
					<?php endif; ?>
					<label><input type="checkbox" name="include_reports" value="1" <?php checked( $ptp_values['include_reports'] ); ?> /> <?php esc_html_e( 'Reports', 'personal-project-tracker' ); ?></label>
					<label><input type="checkbox" name="include_activity" value="1" <?php checked( $ptp_values['include_activity'] ); ?> /> <?php esc_html_e( 'Activity', 'personal-project-tracker' ); ?></label>
				</div>
				<p class="description"><?php esc_html_e( 'Nothing is included automatically — pick exactly the context this prompt needs. Passwords, API keys, tokens, secrets, and authentication information are never included.', 'personal-project-tracker' ); ?></p>
			</div>

			<div class="ptp-form-field ptp-form-field-full">
				<label for="ptp-requirements"><?php esc_html_e( 'Requirements', 'personal-project-tracker' ); ?></label>
				<textarea id="ptp-requirements" name="requirements" rows="3"><?php echo esc_textarea( $ptp_values['requirements'] ); ?></textarea>
			</div>

			<div class="ptp-form-field ptp-form-field-full">
				<label for="ptp-constraints"><?php esc_html_e( 'Constraints', 'personal-project-tracker' ); ?></label>
				<textarea id="ptp-constraints" name="constraints" rows="3"><?php echo esc_textarea( $ptp_values['constraints'] ); ?></textarea>
			</div>

			<div class="ptp-form-field ptp-form-field-full">
				<div class="ptp-page-header">
					<label for="ptp-content"><?php esc_html_e( 'Generated Prompt', 'personal-project-tracker' ); ?></label>
					<div class="ptp-quick-actions">
						<button type="button" class="button button-primary" id="ptp-js-generate"><?php esc_html_e( 'Generate', 'personal-project-tracker' ); ?></button>
						<button type="button" class="button" id="ptp-js-copy"><?php esc_html_e( 'Copy', 'personal-project-tracker' ); ?></button>
						<button type="button" class="button" id="ptp-js-share"><?php esc_html_e( 'Share', 'personal-project-tracker' ); ?></button>
					</div>
				</div>
				<textarea id="ptp-content" name="content" rows="16"><?php echo esc_textarea( $ptp_values['content'] ); ?></textarea>
				<p class="description" id="ptp-generate-warnings"></p>
				<p class="description"><?php esc_html_e( 'Generate builds this text from your selections above; it never overwrites anything you already saved until you click Save again. Edit freely before saving or copying.', 'personal-project-tracker' ); ?></p>
			</div>
		</div>

		<p class="ptp-form-actions">
			<button type="submit" class="button button-primary">
				<?php echo ! empty( $is_edit ) ? esc_html__( 'Update Prompt', 'personal-project-tracker' ) : esc_html__( 'Save Prompt', 'personal-project-tracker' ); ?>
			</button>
			<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-ai-prompts' ), admin_url( 'admin.php' ) ) ); ?>">
				<?php esc_html_e( 'Cancel', 'personal-project-tracker' ); ?>
			</a>
		</p>
	</form>

<?php endif; ?>
