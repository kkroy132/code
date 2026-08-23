<?php
/**
 * Calendar agenda/list view: a flat chronological list grouped by date,
 * over a 30-day rolling window — the most mobile-friendly view.
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
$ptp_any    = false;
?>
<ul class="ptp-cal-agenda">
	<?php while ( $ptp_cursor <= $ptp_range_end ) : ?>
		<?php
		$ptp_day_key   = $ptp_cursor->format( 'Y-m-d' );
		$ptp_day_items = $ptp_items_by_date[ $ptp_day_key ] ?? array();
		?>
		<?php if ( ! empty( $ptp_day_items ) ) : ?>
			<?php $ptp_any = true; ?>
			<li class="ptp-cal-agenda-day">
				<div class="ptp-cal-agenda-date <?php echo esc_attr( $ptp_day_key === $ptp_today ? 'ptp-cal-day-today' : '' ); ?>">
					<?php echo esc_html( wp_date( get_option( 'date_format' ), $ptp_cursor->getTimestamp() ) ); ?>
				</div>
				<ul class="ptp-cal-day-list">
					<?php foreach ( $ptp_day_items as $ptp_item ) : ?>
						<li><?php ptp_calendar_render_item( $ptp_item ); ?></li>
					<?php endforeach; ?>
				</ul>
			</li>
		<?php endif; ?>
		<?php $ptp_cursor->modify( '+1 day' ); ?>
	<?php endwhile; ?>
</ul>

<?php if ( ! $ptp_any ) : ?>
	<div class="ptp-empty-state">
		<span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
		<p><?php esc_html_e( 'Nothing scheduled in the next 30 days.', 'personal-project-tracker' ); ?></p>
	</div>
<?php endif; ?>
