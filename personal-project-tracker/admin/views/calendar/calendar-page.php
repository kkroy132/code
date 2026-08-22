<?php
/**
 * Calendar page: Month / Week / Day / Agenda views over one unified,
 * normalized item list (custom events + live project/task/milestone
 * deadlines — see PTP_Calendar_Repository::get_unified_items()).
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ptp_view       = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'month'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_view       = in_array( $ptp_view, array( 'month', 'week', 'day', 'agenda' ), true ) ? $ptp_view : 'month';
$ptp_filter     = isset( $_GET['filter'] ) ? sanitize_key( wp_unslash( $_GET['filter'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_filter     = in_array( $ptp_filter, array( 'all', 'projects', 'tasks', 'milestones', 'custom' ), true ) ? $ptp_filter : 'all';
$ptp_project_id = isset( $_GET['project_id'] ) ? absint( $_GET['project_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$ptp_date_raw = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_date_dt  = $ptp_date_raw ? DateTime::createFromFormat( 'Y-m-d', $ptp_date_raw ) : false;

if ( ! $ptp_date_dt || $ptp_date_dt->format( 'Y-m-d' ) !== $ptp_date_raw ) {
	// Invalid or missing date falls back to "today" in the site's configured
	// timezone, matching the rest of the plugin's current_time() convention.
	$ptp_date_dt = new DateTime( current_time( 'Y-m-d' ) );
}

$ptp_start_of_week = (int) get_option( 'start_of_week', 0 );

if ( ! function_exists( 'ptp_calendar_period_bounds' ) ) {
	/**
	 * Compute the [start, end] DateTime bounds for a view + anchor date.
	 *
	 * @param string   $view View slug.
	 * @param DateTime $date Anchor date.
	 * @param int      $start_of_week 0 (Sunday) - 6 (Saturday), from WP's own setting.
	 * @return DateTime[] [start, end]
	 */
	function ptp_calendar_period_bounds( $view, DateTime $date, $start_of_week ) {
		switch ( $view ) {
			case 'day':
				$start = clone $date;
				$end   = clone $date;
				break;

			case 'week':
				$dow   = (int) $date->format( 'w' );
				$back  = ( $dow - $start_of_week + 7 ) % 7;
				$start = clone $date;
				$start->modify( "-{$back} days" );
				$end = clone $start;
				$end->modify( '+6 days' );
				break;

			case 'agenda':
				$start = clone $date;
				$end   = clone $date;
				$end->modify( '+29 days' );
				break;

			default: // month
				$first = new DateTime( $date->format( 'Y-m-01' ) );
				$last  = clone $first;
				$last->modify( 'last day of this month' );

				$dow_first = (int) $first->format( 'w' );
				$back      = ( $dow_first - $start_of_week + 7 ) % 7;
				$start     = clone $first;
				$start->modify( "-{$back} days" );

				$dow_last = (int) $last->format( 'w' );
				$forward  = ( $start_of_week + 6 - $dow_last + 7 ) % 7;
				$end      = clone $last;
				$end->modify( "+{$forward} days" );
				break;
		}

		return array( $start, $end );
	}
}

if ( ! function_exists( 'ptp_calendar_nav_url' ) ) {
	/**
	 * Build a navigation URL preserving view/filter/project, with a new date.
	 *
	 * This file is require()'d from inside a class method (PTP_Admin_Pages::
	 * render_view()), so its top-level variables are local to that method's
	 * scope, not the true PHP global scope — the current view/filter/project
	 * are passed in explicitly instead of read via `global` (see the same
	 * fix applied to ptp_projects_sort_link() in Phase 2).
	 *
	 * @param string $date       New date, 'Y-m-d'.
	 * @param string $view       Current view slug.
	 * @param string $filter     Current type filter.
	 * @param int    $project_id Current project filter (0 = none).
	 * @param array  $overrides  Additional query args to override.
	 * @return string
	 */
	function ptp_calendar_nav_url( $date, $view, $filter, $project_id, array $overrides = array() ) {
		$args = array_merge(
			array(
				'page'       => 'ptp-calendar',
				'view'       => $view,
				'date'       => $date,
				'filter'     => $filter,
				'project_id' => $project_id ?: null,
			),
			$overrides
		);

		return add_query_arg( array_filter( $args, fn( $v ) => null !== $v && '' !== $v ), admin_url( 'admin.php' ) );
	}
}

list( $ptp_range_start, $ptp_range_end ) = ptp_calendar_period_bounds( $ptp_view, $ptp_date_dt, $ptp_start_of_week );

$ptp_items = PTP_Calendar_Repository::get_unified_items(
	array(
		'start'      => $ptp_range_start->format( 'Y-m-d' ),
		'end'        => $ptp_range_end->format( 'Y-m-d' ),
		'filter'     => $ptp_filter,
		'project_id' => $ptp_project_id,
	)
);

// Group items by every day they cover within the visible range, so a
// multi-day custom event shows up on each day it spans, not only its start.
$ptp_items_by_date = array();

foreach ( $ptp_items as $ptp_item ) {
	$ptp_item_start = max( $ptp_item['date'], $ptp_range_start->format( 'Y-m-d' ) );
	$ptp_item_end   = min( $ptp_item['end_date'] ? $ptp_item['end_date'] : $ptp_item['date'], $ptp_range_end->format( 'Y-m-d' ) );

	$ptp_cursor = new DateTime( $ptp_item_start );
	$ptp_last   = new DateTime( $ptp_item_end );

	while ( $ptp_cursor <= $ptp_last ) {
		$ptp_items_by_date[ $ptp_cursor->format( 'Y-m-d' ) ][] = $ptp_item;
		$ptp_cursor->modify( '+1 day' );
	}
}

if ( ! function_exists( 'ptp_calendar_render_item' ) ) {
	/**
	 * Render one calendar item as a small colored link, shared by every view.
	 *
	 * @param array $item   Normalized item from PTP_Calendar_Repository::get_unified_items().
	 * @param bool  $with_time Whether to show a time prefix for timed (non-all-day) custom events.
	 */
	function ptp_calendar_render_item( array $item, $with_time = true ) {
		$type_labels = array(
			'custom'    => __( 'Event', 'personal-project-tracker' ),
			'project'   => __( 'Project deadline', 'personal-project-tracker' ),
			'task'      => __( 'Task due', 'personal-project-tracker' ),
			'milestone' => __( 'Milestone due', 'personal-project-tracker' ),
		);
		$label = $type_labels[ $item['type'] ] ?? $item['type'];
		$time  = ( $with_time && ! $item['all_day'] && $item['datetime'] ) ? mysql2date( get_option( 'time_format' ), $item['datetime'] ) . ' ' : '';
		?>
		<a href="<?php echo esc_url( $item['url'] ); ?>" class="ptp-cal-item ptp-cal-item-<?php echo esc_attr( $item['type'] ); ?>" style="border-left-color:<?php echo esc_attr( $item['color'] ); ?>" title="<?php echo esc_attr( $label . ': ' . $item['title'] ); ?>">
			<?php if ( $time ) : ?><span class="ptp-cal-item-time"><?php echo esc_html( $time ); ?></span><?php endif; ?>
			<span class="ptp-cal-item-title"><?php echo esc_html( $item['title'] ); ?></span>
		</a>
		<?php
	}
}

$ptp_projects = PTP_Projects_Repository::get_options_for_select();

$ptp_period_labels = array(
	'month'  => $ptp_date_dt->format( 'F Y' ),
	/* translators: 1: week start date, 2: week end date. */
	'week'   => sprintf( __( '%1$s – %2$s', 'personal-project-tracker' ), wp_date( get_option( 'date_format' ), $ptp_range_start->getTimestamp() ), wp_date( get_option( 'date_format' ), $ptp_range_end->getTimestamp() ) ),
	'day'    => wp_date( get_option( 'date_format' ), $ptp_date_dt->getTimestamp() ),
	/* translators: 1: agenda start date, 2: agenda end date. */
	'agenda' => sprintf( __( '%1$s – %2$s', 'personal-project-tracker' ), wp_date( get_option( 'date_format' ), $ptp_range_start->getTimestamp() ), wp_date( get_option( 'date_format' ), $ptp_range_end->getTimestamp() ) ),
);

$ptp_nav_step = array(
	'month'  => '1 month',
	'week'   => '7 days',
	'day'    => '1 day',
	'agenda' => '30 days',
);

$ptp_prev_dt = clone $ptp_date_dt;
$ptp_prev_dt->modify( '-' . $ptp_nav_step[ $ptp_view ] );
$ptp_next_dt = clone $ptp_date_dt;
$ptp_next_dt->modify( '+' . $ptp_nav_step[ $ptp_view ] );
?>
<div class="ptp-page-header">
	<h1><?php esc_html_e( 'Calendar', 'personal-project-tracker' ); ?></h1>
	<a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-calendar', 'action' => 'new', 'start_date' => $ptp_date_dt->format( 'Y-m-d' ) ), admin_url( 'admin.php' ) ) ); ?>">
		<?php esc_html_e( '+ New Event', 'personal-project-tracker' ); ?>
	</a>
</div>

<div class="ptp-card">
	<div class="ptp-calendar-toolbar">
		<div class="ptp-calendar-nav">
			<a class="button" href="<?php echo esc_url( ptp_calendar_nav_url( $ptp_prev_dt->format( 'Y-m-d' ), $ptp_view, $ptp_filter, $ptp_project_id ) ); ?>">&laquo; <?php esc_html_e( 'Previous', 'personal-project-tracker' ); ?></a>
			<a class="button" href="<?php echo esc_url( ptp_calendar_nav_url( current_time( 'Y-m-d' ), $ptp_view, $ptp_filter, $ptp_project_id ) ); ?>"><?php esc_html_e( 'Today', 'personal-project-tracker' ); ?></a>
			<a class="button" href="<?php echo esc_url( ptp_calendar_nav_url( $ptp_next_dt->format( 'Y-m-d' ), $ptp_view, $ptp_filter, $ptp_project_id ) ); ?>"><?php esc_html_e( 'Next', 'personal-project-tracker' ); ?> &raquo;</a>
			<strong class="ptp-calendar-period-label"><?php echo esc_html( $ptp_period_labels[ $ptp_view ] ); ?></strong>
		</div>

		<div class="ptp-calendar-views">
			<?php foreach ( array( 'month' => __( 'Month', 'personal-project-tracker' ), 'week' => __( 'Week', 'personal-project-tracker' ), 'day' => __( 'Day', 'personal-project-tracker' ), 'agenda' => __( 'Agenda', 'personal-project-tracker' ) ) as $ptp_v => $ptp_label ) : ?>
				<a class="button <?php echo $ptp_view === $ptp_v ? 'button-primary' : ''; ?>" href="<?php echo esc_url( ptp_calendar_nav_url( $ptp_date_dt->format( 'Y-m-d' ), $ptp_view, $ptp_filter, $ptp_project_id, array( 'view' => $ptp_v ) ) ); ?>">
					<?php echo esc_html( $ptp_label ); ?>
				</a>
			<?php endforeach; ?>
		</div>
	</div>

	<form method="get" class="ptp-filter-bar">
		<input type="hidden" name="page" value="ptp-calendar" />
		<input type="hidden" name="view" value="<?php echo esc_attr( $ptp_view ); ?>" />
		<input type="hidden" name="date" value="<?php echo esc_attr( $ptp_date_dt->format( 'Y-m-d' ) ); ?>" />

		<select name="filter">
			<option value="all" <?php selected( $ptp_filter, 'all' ); ?>><?php esc_html_e( 'All', 'personal-project-tracker' ); ?></option>
			<option value="projects" <?php selected( $ptp_filter, 'projects' ); ?>><?php esc_html_e( 'Projects', 'personal-project-tracker' ); ?></option>
			<option value="tasks" <?php selected( $ptp_filter, 'tasks' ); ?>><?php esc_html_e( 'Tasks', 'personal-project-tracker' ); ?></option>
			<option value="milestones" <?php selected( $ptp_filter, 'milestones' ); ?>><?php esc_html_e( 'Milestones', 'personal-project-tracker' ); ?></option>
			<option value="custom" <?php selected( $ptp_filter, 'custom' ); ?>><?php esc_html_e( 'Custom Events', 'personal-project-tracker' ); ?></option>
		</select>

		<select name="project_id">
			<option value=""><?php esc_html_e( 'All projects', 'personal-project-tracker' ); ?></option>
			<?php foreach ( $ptp_projects as $ptp_pid => $ptp_ptitle ) : ?>
				<option value="<?php echo esc_attr( $ptp_pid ); ?>" <?php selected( $ptp_project_id, $ptp_pid ); ?>>
					<?php echo esc_html( $ptp_ptitle ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<button type="submit" class="button"><?php esc_html_e( 'Filter', 'personal-project-tracker' ); ?></button>
	</form>

	<?php require PTP_PLUGIN_DIR . 'admin/views/calendar/' . $ptp_view . '-view.php'; ?>
</div>
