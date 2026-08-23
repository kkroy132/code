<?php
/**
 * Settings > Appearance.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ptp_modes = array(
	'light'  => __( 'Light', 'personal-project-tracker' ),
	'dark'   => __( 'Dark', 'personal-project-tracker' ),
	'system' => __( 'System', 'personal-project-tracker' ),
);
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="ptp_save_settings" />
	<input type="hidden" name="tab" value="appearance" />
	<?php PTP_Security::nonce_field(); ?>

	<h2><?php esc_html_e( 'Appearance', 'personal-project-tracker' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Only this plugin\'s own screens are affected — the WordPress admin menu and toolbar keep your site-wide admin color scheme.', 'personal-project-tracker' ); ?></p>

	<div class="ptp-checkbox-inline-list">
		<?php foreach ( $ptp_modes as $ptp_key => $ptp_label ) : ?>
			<label>
				<input type="radio" name="appearance_mode" value="<?php echo esc_attr( $ptp_key ); ?>" <?php checked( PTP_Settings::get( 'appearance_mode', 'system' ), $ptp_key ); ?> />
				<?php echo esc_html( $ptp_label ); ?>
			</label>
		<?php endforeach; ?>
	</div>

	<p class="ptp-form-actions">
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Changes', 'personal-project-tracker' ); ?></button>
	</p>
</form>
