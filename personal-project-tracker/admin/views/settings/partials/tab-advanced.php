<?php
/**
 * Settings > Advanced: Smart Alerts/Reminders tuning (these settings have
 * existed since Phase 11 but never had a UI until now) plus a read-only
 * system info block.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="ptp_save_settings" />
	<input type="hidden" name="tab" value="advanced" />
	<?php PTP_Security::nonce_field(); ?>

	<h2><?php esc_html_e( 'Smart Alerts &amp; Reminders', 'personal-project-tracker' ); ?></h2>

	<div class="ptp-checkbox-inline-list">
		<label><input type="checkbox" name="smart_alerts_enabled" value="1" <?php checked( PTP_Settings::get( 'smart_alerts_enabled', true ) ); ?> /> <?php esc_html_e( 'Enable Smart Alerts', 'personal-project-tracker' ); ?></label>
		<label><input type="checkbox" name="reminders_enabled" value="1" <?php checked( PTP_Settings::get( 'reminders_enabled', true ) ); ?> /> <?php esc_html_e( 'Enable Reminders', 'personal-project-tracker' ); ?></label>
		<label><input type="checkbox" name="overdue_task_alerts" value="1" <?php checked( PTP_Settings::get( 'overdue_task_alerts', true ) ); ?> /> <?php esc_html_e( 'Overdue task alerts', 'personal-project-tracker' ); ?></label>
	</div>

	<div class="ptp-form-grid">
		<div class="ptp-form-field">
			<label for="ptp-upcoming-deadline-days"><?php esc_html_e( 'Upcoming Deadline Window (days)', 'personal-project-tracker' ); ?></label>
			<input type="number" id="ptp-upcoming-deadline-days" name="upcoming_deadline_days" min="1" max="90" value="<?php echo esc_attr( PTP_Settings::get( 'upcoming_deadline_days', 3 ) ); ?>" />
		</div>
		<div class="ptp-form-field">
			<label for="ptp-project-inactivity-days"><?php esc_html_e( 'Project Inactivity Threshold (days)', 'personal-project-tracker' ); ?></label>
			<input type="number" id="ptp-project-inactivity-days" name="project_inactivity_days" min="1" max="365" value="<?php echo esc_attr( PTP_Settings::get( 'project_inactivity_days', 14 ) ); ?>" />
		</div>
		<div class="ptp-form-field">
			<label for="ptp-long-running-timer-hours"><?php esc_html_e( 'Long-Running Timer Threshold (hours)', 'personal-project-tracker' ); ?></label>
			<input type="number" id="ptp-long-running-timer-hours" name="long_running_timer_hours" min="1" max="72" value="<?php echo esc_attr( PTP_Settings::get( 'long_running_timer_hours', 4 ) ); ?>" />
		</div>
		<div class="ptp-form-field">
			<label for="ptp-too-many-overdue-threshold"><?php esc_html_e( '"Too Many Overdue Tasks" Threshold', 'personal-project-tracker' ); ?></label>
			<input type="number" id="ptp-too-many-overdue-threshold" name="too_many_overdue_threshold" min="1" max="100" value="<?php echo esc_attr( PTP_Settings::get( 'too_many_overdue_threshold', 5 ) ); ?>" />
		</div>
	</div>

	<p class="ptp-form-actions">
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Changes', 'personal-project-tracker' ); ?></button>
	</p>
</form>

<h2><?php esc_html_e( 'System Info', 'personal-project-tracker' ); ?></h2>
<table class="widefat striped ptp-status-table">
	<tbody>
		<tr>
			<th scope="row"><?php esc_html_e( 'Plugin Version', 'personal-project-tracker' ); ?></th>
			<td><?php echo esc_html( PTP_VERSION ); ?></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Database Schema Version', 'personal-project-tracker' ); ?></th>
			<td><?php echo esc_html( PTP_DB_VERSION ); ?></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Database Tables', 'personal-project-tracker' ); ?></th>
			<td><?php echo esc_html( count( PTP_Database::get_table_names() ) ); ?></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'PHP Version', 'personal-project-tracker' ); ?></th>
			<td><?php echo esc_html( PHP_VERSION ); ?></td>
		</tr>
	</tbody>
</table>
