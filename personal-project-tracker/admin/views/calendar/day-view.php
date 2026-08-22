<?php
/**
 * Calendar day view: a simple chronological list for one day.
 *
 * Uses variables set by the including calendar-page.php: $ptp_date_dt,
 * $ptp_items_by_date.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ptp_day_key   = $ptp_date_dt->format( 'Y-m-d' );
$ptp_day_items = $ptp_items_by_date[ $ptp_day_key ] ?? array();

$ptp_all_day_items = array_values( array_filter( $ptp_day_items, fn( $item ) => $item['all_day'] ) );
$ptp_timed_items   = array_values( array_filter( $ptp_day_items, fn( $item ) => ! $item['all_day'] ) );

usort( $ptp_timed_items, fn( $a, $b ) => strcmp( (string) $a['datetime'], (string) $b['datetime'] ) );
?>
<?php if ( empty( $ptp_day_items ) ) : ?>
	<div class="ptp-empty-state">
		<span class="dashicons dashicons-calendar-alt"></span>
		<p><?php esc_html_e( 'Nothing scheduled for this day.', 'personal-project-tracker' ); ?></p>
	</div>
<?php else : ?>

	<?php if ( ! empty( $ptp_all_day_items ) ) : ?>
		<h3><?php esc_html_e( 'All Day', 'personal-project-tracker' ); ?></h3>
		<ul class="ptp-cal-day-list">
			<?php foreach ( $ptp_all_day_items as $ptp_item ) : ?>
				<li><?php ptp_calendar_render_item( $ptp_item, false ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php if ( ! empty( $ptp_timed_items ) ) : ?>
		<h3><?php esc_html_e( 'Timed', 'personal-project-tracker' ); ?></h3>
		<ul class="ptp-cal-day-list">
			<?php foreach ( $ptp_timed_items as $ptp_item ) : ?>
				<li><?php ptp_calendar_render_item( $ptp_item ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

<?php endif; ?>
