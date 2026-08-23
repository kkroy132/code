<?php
/**
 * Settings > Finance. Only reachable if the viewer can already manage
 * Finance — see admin/views/settings/settings-page.php.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="ptp_save_settings" />
	<input type="hidden" name="tab" value="finance" />
	<?php PTP_Security::nonce_field(); ?>

	<h2><?php esc_html_e( 'Finance', 'personal-project-tracker' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Currency defaults live under General. This tab only covers Finance-specific behavior.', 'personal-project-tracker' ); ?></p>

	<div class="ptp-form-field">
		<label for="ptp-budget-warning-threshold"><?php esc_html_e( 'Budget Warning Threshold (%)', 'personal-project-tracker' ); ?></label>
		<input type="number" id="ptp-budget-warning-threshold" name="budget_warning_threshold" min="1" max="100" value="<?php echo esc_attr( PTP_Settings::get( 'budget_warning_threshold', 80 ) ); ?>" />
		<p class="description"><?php esc_html_e( 'A project is flagged "approaching budget limit" once its spend crosses this percentage of its budget.', 'personal-project-tracker' ); ?></p>
	</div>

	<p class="ptp-form-actions">
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Changes', 'personal-project-tracker' ); ?></button>
	</p>
</form>
