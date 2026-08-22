<?php
/**
 * Calendar week view: one row of 7 fuller-height day columns.
 *
 * Uses variables set by the including calendar-page.php: $ptp_range_start,
 * $ptp_range_end, $ptp_items_by_date.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ptp_today  = current_time( 'Y-m-d' );
$ptp_cursor = clone $ptp_range_start;
$ptp_days   = array();

while ( $ptp_cursor <= $ptp_range_end ) {
	$ptp_days[] = clone $ptp_cursor;
	$ptp_cursor->modify( '+1 day' );
}
?>
<div class="ptp-table-responsive">
	<table class="ptp-calendar-week">
		<thead>
			<tr>
				<?php foreach ( $ptp_days as $ptp_day ) : ?>
					<th class="<?php echo esc_attr( $ptp_day->format( 'Y-m-d' ) === $ptp_today ? 'ptp-cal-day-today' : '' ); ?>">
						<?php echo esc_html( $ptp_day->format( 'D' ) ); ?><br />
						<a href="<?php echo esc_url( ptp_calendar_nav_url( $ptp_day->format( 'Y-m-d' ), $ptp_view, $ptp_filter, $ptp_project_id, array( 'view' => 'day' ) ) ); ?>">
							<?php echo esc_html( $ptp_day->format( 'j M' ) ); ?>
						</a>
					</th>
				<?php endforeach; ?>
			</tr>
		</thead>
		<tbody>
			<tr>
				<?php foreach ( $ptp_days as $ptp_day ) : ?>
					<?php $ptp_day_items = $ptp_items_by_date[ $ptp_day->format( 'Y-m-d' ) ] ?? array(); ?>
					<td class="ptp-cal-day ptp-cal-day-week <?php echo esc_attr( $ptp_day->format( 'Y-m-d' ) === $ptp_today ? 'ptp-cal-day-today' : '' ); ?>">
						<div class="ptp-cal-day-items">
							<?php foreach ( $ptp_day_items as $ptp_item ) : ?>
								<?php ptp_calendar_render_item( $ptp_item ); ?>
							<?php endforeach; ?>
							<?php if ( empty( $ptp_day_items ) ) : ?>
								<span class="description">&#8212;</span>
							<?php endif; ?>
						</div>
					</td>
				<?php endforeach; ?>
			</tr>
		</tbody>
	</table>
</div>
