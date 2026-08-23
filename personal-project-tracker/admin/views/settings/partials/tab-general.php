<?php
/**
 * Settings > General.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ptp_project_statuses = PTP_Projects_Repository::get_statuses();
$ptp_task_statuses    = PTP_Tasks_Repository::get_statuses();
$ptp_priorities       = PTP_Tasks_Repository::get_priorities();
$ptp_week_start       = (int) get_option( 'start_of_week', 0 );

$ptp_days = array(
	0 => __( 'Sunday', 'personal-project-tracker' ),
	1 => __( 'Monday', 'personal-project-tracker' ),
	2 => __( 'Tuesday', 'personal-project-tracker' ),
	3 => __( 'Wednesday', 'personal-project-tracker' ),
	4 => __( 'Thursday', 'personal-project-tracker' ),
	5 => __( 'Friday', 'personal-project-tracker' ),
	6 => __( 'Saturday', 'personal-project-tracker' ),
);
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="ptp_save_settings" />
	<input type="hidden" name="tab" value="general" />
	<?php PTP_Security::nonce_field(); ?>

	<h2><?php esc_html_e( 'General', 'personal-project-tracker' ); ?></h2>

	<div class="ptp-form-grid">
		<div class="ptp-form-field">
			<label for="ptp-default-project-status"><?php esc_html_e( 'Default Project Status', 'personal-project-tracker' ); ?></label>
			<select id="ptp-default-project-status" name="default_project_status">
				<?php foreach ( $ptp_project_statuses as $ptp_key => $ptp_label ) : ?>
					<option value="<?php echo esc_attr( $ptp_key ); ?>" <?php selected( PTP_Settings::get( 'default_project_status' ), $ptp_key ); ?>><?php echo esc_html( $ptp_label ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>

		<div class="ptp-form-field">
			<label for="ptp-default-task-status"><?php esc_html_e( 'Default Task Status', 'personal-project-tracker' ); ?></label>
			<select id="ptp-default-task-status" name="default_task_status">
				<?php foreach ( $ptp_task_statuses as $ptp_key => $ptp_label ) : ?>
					<option value="<?php echo esc_attr( $ptp_key ); ?>" <?php selected( PTP_Settings::get( 'default_task_status' ), $ptp_key ); ?>><?php echo esc_html( $ptp_label ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>

		<div class="ptp-form-field">
			<label for="ptp-default-task-priority"><?php esc_html_e( 'Default Task Priority', 'personal-project-tracker' ); ?></label>
			<select id="ptp-default-task-priority" name="default_task_priority">
				<?php foreach ( $ptp_priorities as $ptp_key => $ptp_label ) : ?>
					<option value="<?php echo esc_attr( $ptp_key ); ?>" <?php selected( PTP_Settings::get( 'default_task_priority' ), $ptp_key ); ?>><?php echo esc_html( $ptp_label ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>

		<div class="ptp-form-field">
			<label for="ptp-default-currency"><?php esc_html_e( 'Currency', 'personal-project-tracker' ); ?></label>
			<input type="text" id="ptp-default-currency" name="default_currency" maxlength="10" value="<?php echo esc_attr( PTP_Settings::get( 'default_currency' ) ); ?>" />
		</div>

		<div class="ptp-form-field">
			<label for="ptp-date-format"><?php esc_html_e( 'Date Format', 'personal-project-tracker' ); ?></label>
			<input type="text" id="ptp-date-format" name="date_format" value="<?php echo esc_attr( PTP_Settings::get( 'date_format' ) ); ?>" />
			<p class="description"><?php echo esc_html( sprintf( /* translators: %s: PHP date() format placeholder. */ __( 'PHP date() format, e.g. %s', 'personal-project-tracker' ), 'Y-m-d' ) ); ?></p>
		</div>

		<div class="ptp-form-field">
			<label for="ptp-time-format"><?php esc_html_e( 'Time Format', 'personal-project-tracker' ); ?></label>
			<input type="text" id="ptp-time-format" name="time_format" value="<?php echo esc_attr( PTP_Settings::get( 'time_format' ) ); ?>" />
		</div>

		<div class="ptp-form-field">
			<label for="ptp-week-start"><?php esc_html_e( 'Week Start', 'personal-project-tracker' ); ?></label>
			<select id="ptp-week-start" name="week_start">
				<?php foreach ( $ptp_days as $ptp_day_num => $ptp_day_label ) : ?>
					<option value="<?php echo esc_attr( $ptp_day_num ); ?>" <?php selected( $ptp_week_start, $ptp_day_num ); ?>><?php echo esc_html( $ptp_day_label ); ?></option>
				<?php endforeach; ?>
			</select>
			<p class="description"><?php esc_html_e( 'This is the WordPress site-wide "Week Starts On" setting — changing it here also affects Settings > General.', 'personal-project-tracker' ); ?></p>
		</div>
	</div>

	<p class="ptp-form-actions">
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Changes', 'personal-project-tracker' ); ?></button>
	</p>
</form>
