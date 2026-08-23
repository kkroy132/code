<?php
/**
 * Notification Preferences: global ON/OFF, per-category toggles, Quiet Hours.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ptp_notifications_enabled = (bool) PTP_Settings::get( 'notifications_enabled', true );
$ptp_categories            = (array) PTP_Settings::get( 'notification_categories', array() );
$ptp_quiet_hours_enabled   = (bool) PTP_Settings::get( 'quiet_hours_enabled', false );
$ptp_quiet_hours_start     = (string) PTP_Settings::get( 'quiet_hours_start', '22:00' );
$ptp_quiet_hours_end       = (string) PTP_Settings::get( 'quiet_hours_end', '07:00' );
$ptp_can_finance           = current_user_can( 'ptp_manage_finance' );
?>
<div class="ptp-page-header">
	<h1><?php esc_html_e( 'Notification Preferences', 'personal-project-tracker' ); ?></h1>
	<div class="ptp-quick-actions">
		<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-notifications' ), admin_url( 'admin.php' ) ) ); ?>">
			<?php esc_html_e( '&laquo; Notifications', 'personal-project-tracker' ); ?>
		</a>
	</div>
</div>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ptp-card">
	<input type="hidden" name="action" value="ptp_save_notification_preferences" />
	<?php PTP_Security::nonce_field(); ?>

	<h2><?php esc_html_e( 'Global', 'personal-project-tracker' ); ?></h2>
	<p>
		<label>
			<input type="checkbox" name="notifications_enabled" value="1" <?php checked( $ptp_notifications_enabled ); ?> />
			<?php esc_html_e( 'Enable notifications', 'personal-project-tracker' ); ?>
		</label>
	</p>
	<p class="description"><?php esc_html_e( 'When off, nothing below is created — reminders and Smart Alerts still track their conditions, but never turn into a notification until this is back on.', 'personal-project-tracker' ); ?></p>

	<h2><?php esc_html_e( 'Categories', 'personal-project-tracker' ); ?></h2>
	<div class="ptp-checkbox-inline-list">
		<?php foreach ( PTP_Notifications_Repository::get_categories() as $ptp_ckey => $ptp_clabel ) : ?>
			<?php if ( 'finance' === $ptp_ckey && ! $ptp_can_finance ) { continue; } ?>
			<label>
				<input type="checkbox" name="categories[]" value="<?php echo esc_attr( $ptp_ckey ); ?>" <?php checked( ! empty( $ptp_categories[ $ptp_ckey ] ) ); ?> />
				<?php echo esc_html( $ptp_clabel ); ?>
			</label>
		<?php endforeach; ?>
	</div>
	<p class="description"><?php esc_html_e( 'Smart Alerts covers the ten rule-based checks (overdue tasks, approaching deadlines, project inactivity, and so on). Finance covers budget/profit alerts and requires Finance access to view.', 'personal-project-tracker' ); ?></p>

	<h2><?php esc_html_e( 'Quiet Hours', 'personal-project-tracker' ); ?></h2>
	<p>
		<label>
			<input type="checkbox" id="ptp-quiet-hours-enabled" name="quiet_hours_enabled" value="1" <?php checked( $ptp_quiet_hours_enabled ); ?> />
			<?php esc_html_e( 'Enable Quiet Hours', 'personal-project-tracker' ); ?>
		</label>
	</p>
	<div class="ptp-form-grid" id="ptp-quiet-hours-fields" <?php echo $ptp_quiet_hours_enabled ? '' : 'style="display:none;"'; ?>>
		<div class="ptp-form-field">
			<label for="ptp-quiet-hours-start"><?php esc_html_e( 'Start', 'personal-project-tracker' ); ?></label>
			<input type="time" id="ptp-quiet-hours-start" name="quiet_hours_start" value="<?php echo esc_attr( $ptp_quiet_hours_start ); ?>" />
		</div>
		<div class="ptp-form-field">
			<label for="ptp-quiet-hours-end"><?php esc_html_e( 'End', 'personal-project-tracker' ); ?></label>
			<input type="time" id="ptp-quiet-hours-end" name="quiet_hours_end" value="<?php echo esc_attr( $ptp_quiet_hours_end ); ?>" />
		</div>
	</div>
	<p class="description"><?php esc_html_e( 'During Quiet Hours, nothing new is created — but nothing is lost either. A reminder or Smart Alert due during Quiet Hours simply waits and delivers as soon as Quiet Hours end.', 'personal-project-tracker' ); ?></p>

	<p class="ptp-form-actions">
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Preferences', 'personal-project-tracker' ); ?></button>
	</p>
</form>
