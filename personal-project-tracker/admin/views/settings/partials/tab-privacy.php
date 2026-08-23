<?php
/**
 * Settings > Privacy.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="ptp_save_settings" />
	<input type="hidden" name="tab" value="privacy" />
	<?php PTP_Security::nonce_field(); ?>

	<h2><?php esc_html_e( 'Privacy', 'personal-project-tracker' ); ?></h2>

	<p>
		<label>
			<input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( PTP_Settings::get( 'delete_data_on_uninstall', false ) ); ?> />
			<?php esc_html_e( 'Delete all plugin data when the plugin is uninstalled', 'personal-project-tracker' ); ?>
		</label>
	</p>
	<p class="description"><?php esc_html_e( 'Off by default. When on, deleting (not just deactivating) the plugin permanently removes every table it owns, its settings, its backup files, and its capabilities. This cannot be undone — make a backup first.', 'personal-project-tracker' ); ?></p>

	<p class="description">
		<?php esc_html_e( 'Finance data stays private: everywhere in this plugin, financial figures and Finance-category alerts are hidden from any account without Finance access. AI Prompt Studio and Backup/Export both run a best-effort scrub for password/token/API-key/secret-shaped text before it reaches a generated prompt or an export file.', 'personal-project-tracker' ); ?>
	</p>

	<p class="ptp-form-actions">
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Changes', 'personal-project-tracker' ); ?></button>
	</p>
</form>
