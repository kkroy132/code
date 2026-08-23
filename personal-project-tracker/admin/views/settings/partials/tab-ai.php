<?php
/**
 * Settings > AI.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ptp_tools = array(
	'generic' => __( 'Any / Generic', 'personal-project-tracker' ),
	'gemini'  => __( 'Google Gemini', 'personal-project-tracker' ),
	'claude'  => __( 'Anthropic Claude', 'personal-project-tracker' ),
	'chatgpt' => __( 'OpenAI ChatGPT', 'personal-project-tracker' ),
	'other'   => __( 'Other', 'personal-project-tracker' ),
);
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="ptp_save_settings" />
	<input type="hidden" name="tab" value="ai" />
	<?php PTP_Security::nonce_field(); ?>

	<h2><?php esc_html_e( 'AI', 'personal-project-tracker' ); ?></h2>
	<p class="description"><?php esc_html_e( 'AI Prompt Studio never calls an external AI API — it only generates plain text you copy into whichever tool you choose. This just sets which tool a new prompt is labeled for by default.', 'personal-project-tracker' ); ?></p>

	<div class="ptp-form-field">
		<label for="ptp-ai-default-tool"><?php esc_html_e( 'Default AI Tool', 'personal-project-tracker' ); ?></label>
		<select id="ptp-ai-default-tool" name="ai_prompt_default_tool">
			<?php foreach ( $ptp_tools as $ptp_key => $ptp_label ) : ?>
				<option value="<?php echo esc_attr( $ptp_key ); ?>" <?php selected( PTP_Settings::get( 'ai_prompt_default_tool', 'generic' ), $ptp_key ); ?>><?php echo esc_html( $ptp_label ); ?></option>
			<?php endforeach; ?>
		</select>
	</div>

	<p class="ptp-form-actions">
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Changes', 'personal-project-tracker' ); ?></button>
	</p>
</form>
