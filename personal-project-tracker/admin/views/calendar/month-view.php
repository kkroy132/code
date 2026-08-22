<?php
/**
 * Calendar month grid view.
 *
 * Uses variables set by the including calendar-page.php: $ptp_range_start,
 * $ptp_range_end, $ptp_date_dt, $ptp_items_by_date, $ptp_start_of_week.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ptp_weekday_labels = array();
$ptp_wd_cursor       = clone $ptp_range_start;

for ( $ptp_i = 0; $ptp_i < 7; $ptp_i++ ) {
	$ptp_weekday_labels[] = $ptp_wd_cursor->format( 'D' );
	$ptp_wd_cursor->modify( '+1 day' );
}

$ptp_today   = current_time( 'Y-m-d' );
$ptp_month   = $ptp_date_dt->format( 'Y-m' );
$ptp_cursor  = clone $ptp_range_start;
?>
<div class="ptp-table-responsive">
	<table class="ptp-calendar-month">
		<thead>
			<tr>
				<?php foreach ( $ptp_weekday_labels as $ptp_wd_label ) : ?>
					<th><?php echo esc_html( $ptp_wd_label ); ?></th>
				<?php endforeach; ?>
			</tr>
		</thead>
		<tbody>
			<?php while ( $ptp_cursor <= $ptp_range_end ) : ?>
				<tr>
					<?php for ( $ptp_i = 0; $ptp_i < 7; $ptp_i++ ) : ?>
						<?php
						$ptp_day_key      = $ptp_cursor->format( 'Y-m-d' );
						$ptp_in_month     = $ptp_cursor->format( 'Y-m' ) === $ptp_month;
						$ptp_is_today     = $ptp_day_key === $ptp_today;
						$ptp_day_items    = $ptp_items_by_date[ $ptp_day_key ] ?? array();
						$ptp_visible      = array_slice( $ptp_day_items, 0, 4 );
						$ptp_overflow     = count( $ptp_day_items ) - count( $ptp_visible );
						?>
						<td class="ptp-cal-day <?php echo esc_attr( $ptp_in_month ? '' : 'ptp-cal-day-outside' ); ?> <?php echo esc_attr( $ptp_is_today ? 'ptp-cal-day-today' : '' ); ?>">
							<a class="ptp-cal-day-number" href="<?php echo esc_url( ptp_calendar_nav_url( $ptp_day_key, $ptp_view, $ptp_filter, $ptp_project_id, array( 'view' => 'day' ) ) ); ?>">
								<?php echo esc_html( $ptp_cursor->format( 'j' ) ); ?>
							</a>
							<div class="ptp-cal-day-items">
								<?php foreach ( $ptp_visible as $ptp_item ) : ?>
									<?php ptp_calendar_render_item( $ptp_item ); ?>
								<?php endforeach; ?>
								<?php if ( $ptp_overflow > 0 ) : ?>
									<a class="ptp-cal-more" href="<?php echo esc_url( ptp_calendar_nav_url( $ptp_day_key, $ptp_view, $ptp_filter, $ptp_project_id, array( 'view' => 'day' ) ) ); ?>">
										<?php
										printf(
											/* translators: %d: number of additional items. */
											esc_html__( '+%d more', 'personal-project-tracker' ),
											(int) $ptp_overflow
										);
										?>
									</a>
								<?php endif; ?>
							</div>
						</td>
						<?php $ptp_cursor->modify( '+1 day' ); ?>
					<?php endfor; ?>
				</tr>
			<?php endwhile; ?>
		</tbody>
	</table>
</div>

<?php if ( empty( $ptp_items_by_date ) ) : ?>
	<div class="ptp-empty-state">
		<span class="dashicons dashicons-calendar-alt"></span>
		<p><?php esc_html_e( 'Nothing on the calendar this month.', 'personal-project-tracker' ); ?></p>
	</div>
<?php endif; ?>
