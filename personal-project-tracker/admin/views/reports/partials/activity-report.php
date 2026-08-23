<?php
/**
 * Activity Report partial.
 *
 * Uses $ptp_filter_args from the including reports-page.php.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ptp_report = PTP_Reports_Service::get_activity_report( $ptp_filter_args );
?>
<div class="ptp-detail-grid">
	<div class="ptp-detail-main">
		<div class="ptp-card">
			<h2><?php esc_html_e( 'Recent Activity', 'personal-project-tracker' ); ?></h2>
			<?php if ( empty( $ptp_report['recent'] ) ) : ?>
				<p class="description"><?php esc_html_e( 'No activity recorded in this range.', 'personal-project-tracker' ); ?></p>
			<?php else : ?>
				<ul class="ptp-activity-list">
					<?php foreach ( $ptp_report['recent'] as $ptp_entry ) : ?>
						<li>
							<span class="ptp-activity-desc"><?php echo esc_html( $ptp_entry->description ? $ptp_entry->description : $ptp_entry->action ); ?></span>
							<span class="ptp-activity-date"><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ptp_entry->created_at ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	</div>

	<div class="ptp-detail-side">
		<div class="ptp-card">
			<h2><?php esc_html_e( 'By Action', 'personal-project-tracker' ); ?></h2>
			<?php if ( empty( $ptp_report['by_action'] ) ) : ?>
				<p class="description"><?php esc_html_e( 'No activity recorded in this range.', 'personal-project-tracker' ); ?></p>
			<?php else : ?>
				<ul class="ptp-simple-list">
					<?php foreach ( $ptp_report['by_action'] as $ptp_action => $ptp_count ) : ?>
						<li>
							<?php echo esc_html( $ptp_action ); ?>
							<span><?php echo esc_html( number_format_i18n( $ptp_count ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	</div>
</div>
