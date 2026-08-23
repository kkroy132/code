<?php
/**
 * Notification Center: Today / Yesterday / Earlier, Read/Unread/Mark All
 * Read/Delete/Snooze.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ptp_status      = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_category    = isset( $_GET['category'] ) ? sanitize_key( wp_unslash( $_GET['category'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_can_finance = current_user_can( 'ptp_manage_finance' );

$ptp_result = PTP_Notifications_Repository::get_list(
	array(
		'status'          => in_array( $ptp_status, array( 'read', 'unread' ), true ) ? $ptp_status : '',
		'category'        => $ptp_category,
		'include_finance' => $ptp_can_finance,
		'per_page'        => 100,
	)
);

$ptp_today     = current_time( 'Y-m-d' );
$ptp_yesterday = gmdate( 'Y-m-d', strtotime( $ptp_today . ' -1 day' ) );

$ptp_buckets = array(
	'today'     => array(),
	'yesterday' => array(),
	'earlier'   => array(),
);

foreach ( $ptp_result['items'] as $ptp_notification ) {
	$ptp_date = substr( $ptp_notification->created_at, 0, 10 );

	if ( $ptp_date === $ptp_today ) {
		$ptp_buckets['today'][] = $ptp_notification;
	} elseif ( $ptp_date === $ptp_yesterday ) {
		$ptp_buckets['yesterday'][] = $ptp_notification;
	} else {
		$ptp_buckets['earlier'][] = $ptp_notification;
	}
}

$ptp_bucket_labels = array(
	'today'     => __( 'Today', 'personal-project-tracker' ),
	'yesterday' => __( 'Yesterday', 'personal-project-tracker' ),
	'earlier'   => __( 'Earlier', 'personal-project-tracker' ),
);

$ptp_categories = PTP_Notifications_Repository::get_categories();
?>
<div class="ptp-page-header">
	<h1><?php esc_html_e( 'Notifications', 'personal-project-tracker' ); ?></h1>
	<div class="ptp-quick-actions">
		<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-notifications', 'action' => 'reminders' ), admin_url( 'admin.php' ) ) ); ?>">
			<?php esc_html_e( 'Reminders', 'personal-project-tracker' ); ?>
		</a>
		<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-notifications', 'action' => 'preferences' ), admin_url( 'admin.php' ) ) ); ?>">
			<?php esc_html_e( 'Preferences', 'personal-project-tracker' ); ?>
		</a>
		<button type="button" class="button button-primary" id="ptp-js-mark-all-read">
			<?php esc_html_e( 'Mark All Read', 'personal-project-tracker' ); ?>
		</button>
	</div>
</div>

<div class="ptp-card">
	<form method="get" class="ptp-filter-bar">
		<input type="hidden" name="page" value="ptp-notifications" />

		<select name="status">
			<option value=""><?php esc_html_e( 'All', 'personal-project-tracker' ); ?></option>
			<option value="unread" <?php selected( $ptp_status, 'unread' ); ?>><?php esc_html_e( 'Unread', 'personal-project-tracker' ); ?></option>
			<option value="read" <?php selected( $ptp_status, 'read' ); ?>><?php esc_html_e( 'Read', 'personal-project-tracker' ); ?></option>
		</select>

		<select name="category">
			<option value=""><?php esc_html_e( 'All categories', 'personal-project-tracker' ); ?></option>
			<?php foreach ( $ptp_categories as $ptp_ckey => $ptp_clabel ) : ?>
				<?php if ( 'finance' === $ptp_ckey && ! $ptp_can_finance ) { continue; } ?>
				<option value="<?php echo esc_attr( $ptp_ckey ); ?>" <?php selected( $ptp_category, $ptp_ckey ); ?>><?php echo esc_html( $ptp_clabel ); ?></option>
			<?php endforeach; ?>
		</select>

		<button type="submit" class="button"><?php esc_html_e( 'Filter', 'personal-project-tracker' ); ?></button>
	</form>

	<?php if ( empty( $ptp_result['items'] ) ) : ?>

		<div class="ptp-empty-state">
			<span class="dashicons dashicons-bell"></span>
			<p><?php esc_html_e( 'No notifications yet.', 'personal-project-tracker' ); ?></p>
		</div>

	<?php else : ?>

		<?php foreach ( $ptp_buckets as $ptp_bucket_key => $ptp_bucket_items ) : ?>
			<?php if ( empty( $ptp_bucket_items ) ) { continue; } ?>
			<h2 class="ptp-notification-bucket-heading"><?php echo esc_html( $ptp_bucket_labels[ $ptp_bucket_key ] ); ?></h2>
			<ul class="ptp-notification-list">
				<?php foreach ( $ptp_bucket_items as $ptp_notification ) : ?>
					<?php $ptp_deep_link = PTP_Notifications_Service::get_deep_link( $ptp_notification->related_type, $ptp_notification->related_id ); ?>
					<li class="ptp-notification-item <?php echo $ptp_notification->is_read ? 'ptp-is-read' : 'ptp-is-unread'; ?>" data-id="<?php echo esc_attr( $ptp_notification->id ); ?>">
						<div class="ptp-notification-main">
							<span class="ptp-badge ptp-badge-category-<?php echo esc_attr( $ptp_notification->category ); ?>"><?php echo esc_html( $ptp_categories[ $ptp_notification->category ] ?? $ptp_notification->category ); ?></span>
							<a class="ptp-notification-title" href="<?php echo esc_url( $ptp_deep_link ); ?>"><?php echo esc_html( $ptp_notification->title ); ?></a>
							<?php if ( $ptp_notification->message ) : ?>
								<p class="ptp-notification-message"><?php echo esc_html( $ptp_notification->message ); ?></p>
							<?php endif; ?>
							<span class="ptp-notification-date"><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ptp_notification->created_at ) ); ?></span>
						</div>
						<div class="ptp-quick-actions">
							<?php if ( $ptp_notification->is_read ) : ?>
								<button type="button" class="button button-small ptp-js-notification-unread" data-id="<?php echo esc_attr( $ptp_notification->id ); ?>"><?php esc_html_e( 'Mark Unread', 'personal-project-tracker' ); ?></button>
							<?php else : ?>
								<button type="button" class="button button-small ptp-js-notification-read" data-id="<?php echo esc_attr( $ptp_notification->id ); ?>"><?php esc_html_e( 'Mark Read', 'personal-project-tracker' ); ?></button>
							<?php endif; ?>
							<select class="ptp-js-notification-snooze" data-id="<?php echo esc_attr( $ptp_notification->id ); ?>">
								<option value=""><?php esc_html_e( 'Snooze…', 'personal-project-tracker' ); ?></option>
								<option value="10m"><?php esc_html_e( '10 minutes', 'personal-project-tracker' ); ?></option>
								<option value="30m"><?php esc_html_e( '30 minutes', 'personal-project-tracker' ); ?></option>
								<option value="1h"><?php esc_html_e( '1 hour', 'personal-project-tracker' ); ?></option>
								<option value="3h"><?php esc_html_e( '3 hours', 'personal-project-tracker' ); ?></option>
								<option value="tomorrow"><?php esc_html_e( 'Tomorrow', 'personal-project-tracker' ); ?></option>
								<option value="custom"><?php esc_html_e( 'Custom…', 'personal-project-tracker' ); ?></option>
							</select>
							<button type="button" class="button button-small button-link-delete ptp-js-notification-delete" data-id="<?php echo esc_attr( $ptp_notification->id ); ?>"><?php esc_html_e( 'Delete', 'personal-project-tracker' ); ?></button>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endforeach; ?>

	<?php endif; ?>
</div>
