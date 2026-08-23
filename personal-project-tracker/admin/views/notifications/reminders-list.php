<?php
/**
 * Reminders list: search, filter, quick actions.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ptp_search       = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_related_type = isset( $_GET['related_type'] ) ? sanitize_key( wp_unslash( $_GET['related_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_status       = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$ptp_result = PTP_Reminders_Repository::get_list(
	array(
		'search'       => $ptp_search,
		'related_type' => $ptp_related_type,
		'status'       => $ptp_status,
		'per_page'     => 50,
	)
);

$ptp_statuses      = PTP_Reminders_Repository::get_statuses();
$ptp_related_types = PTP_Reminders_Repository::get_related_types();
?>
<div class="ptp-page-header">
	<h1><?php esc_html_e( 'Reminders', 'personal-project-tracker' ); ?></h1>
	<div class="ptp-quick-actions">
		<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-notifications' ), admin_url( 'admin.php' ) ) ); ?>">
			<?php esc_html_e( '&laquo; Notifications', 'personal-project-tracker' ); ?>
		</a>
		<a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-notifications', 'action' => 'new_reminder' ), admin_url( 'admin.php' ) ) ); ?>">
			<?php esc_html_e( '+ New Reminder', 'personal-project-tracker' ); ?>
		</a>
	</div>
</div>

<div class="ptp-card">
	<form method="get" class="ptp-filter-bar">
		<input type="hidden" name="page" value="ptp-notifications" />
		<input type="hidden" name="action" value="reminders" />
		<input type="search" name="s" value="<?php echo esc_attr( $ptp_search ); ?>" placeholder="<?php esc_attr_e( 'Search reminders…', 'personal-project-tracker' ); ?>" />

		<select name="related_type">
			<option value=""><?php esc_html_e( 'All types', 'personal-project-tracker' ); ?></option>
			<?php foreach ( $ptp_related_types as $ptp_rkey => $ptp_rlabel ) : ?>
				<option value="<?php echo esc_attr( $ptp_rkey ); ?>" <?php selected( $ptp_related_type, $ptp_rkey ); ?>><?php echo esc_html( $ptp_rlabel ); ?></option>
			<?php endforeach; ?>
		</select>

		<select name="status">
			<option value=""><?php esc_html_e( 'All statuses', 'personal-project-tracker' ); ?></option>
			<?php foreach ( $ptp_statuses as $ptp_skey => $ptp_slabel ) : ?>
				<option value="<?php echo esc_attr( $ptp_skey ); ?>" <?php selected( $ptp_status, $ptp_skey ); ?>><?php echo esc_html( $ptp_slabel ); ?></option>
			<?php endforeach; ?>
		</select>

		<button type="submit" class="button"><?php esc_html_e( 'Filter', 'personal-project-tracker' ); ?></button>
	</form>

	<?php if ( empty( $ptp_result['items'] ) ) : ?>
		<div class="ptp-empty-state">
			<span class="dashicons dashicons-clock"></span>
			<p><?php esc_html_e( 'No reminders yet.', 'personal-project-tracker' ); ?></p>
		</div>
	<?php else : ?>
		<div class="ptp-table-responsive">
			<table class="widefat striped ptp-reminders-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Title', 'personal-project-tracker' ); ?></th>
						<th class="ptp-col-optional"><?php esc_html_e( 'Related To', 'personal-project-tracker' ); ?></th>
						<th><?php esc_html_e( 'Remind At', 'personal-project-tracker' ); ?></th>
						<th class="ptp-col-optional"><?php esc_html_e( 'Repeat', 'personal-project-tracker' ); ?></th>
						<th class="ptp-col-optional"><?php esc_html_e( 'Status', 'personal-project-tracker' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'personal-project-tracker' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $ptp_result['items'] as $ptp_reminder ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-notifications', 'action' => 'edit_reminder', 'id' => $ptp_reminder->id ), admin_url( 'admin.php' ) ) ); ?>"><strong><?php echo esc_html( $ptp_reminder->title ); ?></strong></a></td>
							<td class="ptp-col-optional"><?php echo esc_html( $ptp_reminder->related_type ? ( $ptp_related_types[ $ptp_reminder->related_type ] ?? $ptp_reminder->related_type ) : __( 'Custom', 'personal-project-tracker' ) ); ?></td>
							<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ptp_reminder->remind_at ) ); ?></td>
							<td class="ptp-col-optional"><?php echo esc_html( PTP_Reminders_Repository::get_recurrences()[ $ptp_reminder->recurrence ] ?? $ptp_reminder->recurrence ); ?></td>
							<td class="ptp-col-optional"><span class="ptp-badge"><?php echo esc_html( $ptp_statuses[ $ptp_reminder->status ] ?? $ptp_reminder->status ); ?></span></td>
							<td>
								<div class="ptp-quick-actions">
									<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-notifications', 'action' => 'edit_reminder', 'id' => $ptp_reminder->id ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Edit', 'personal-project-tracker' ); ?></a>
									<select class="ptp-js-reminder-snooze" data-id="<?php echo esc_attr( $ptp_reminder->id ); ?>">
										<option value=""><?php esc_html_e( 'Snooze…', 'personal-project-tracker' ); ?></option>
										<option value="10m"><?php esc_html_e( '10 minutes', 'personal-project-tracker' ); ?></option>
										<option value="30m"><?php esc_html_e( '30 minutes', 'personal-project-tracker' ); ?></option>
										<option value="1h"><?php esc_html_e( '1 hour', 'personal-project-tracker' ); ?></option>
										<option value="3h"><?php esc_html_e( '3 hours', 'personal-project-tracker' ); ?></option>
										<option value="tomorrow"><?php esc_html_e( 'Tomorrow', 'personal-project-tracker' ); ?></option>
										<option value="custom"><?php esc_html_e( 'Custom…', 'personal-project-tracker' ); ?></option>
									</select>
									<button type="button" class="button button-small button-link-delete ptp-js-reminder-delete" data-id="<?php echo esc_attr( $ptp_reminder->id ); ?>"><?php esc_html_e( 'Delete', 'personal-project-tracker' ); ?></button>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</div>
