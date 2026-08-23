<?php
/**
 * Analytics dashboard: a compact set of metrics plus one lightweight
 * Time Trends bar chart (deliberately not more than one chart, per the
 * phase spec's "avoid excessive charts").
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ptp_date_to     = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_date_from   = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_can_finance = current_user_can( 'ptp_manage_finance' );

$ptp_dashboard = PTP_Analytics_Service::get_dashboard(
	array(
		'date_from' => $ptp_date_from,
		'date_to'   => $ptp_date_to,
	),
	$ptp_can_finance
);

$ptp_max_daily = ! empty( $ptp_dashboard['time_trends']['daily'] ) ? max( $ptp_dashboard['time_trends']['daily'] ) : 0;
?>
<div class="ptp-page-header">
	<h1><?php esc_html_e( 'Analytics', 'personal-project-tracker' ); ?></h1>
</div>

<div class="ptp-card">
	<form method="get" class="ptp-filter-bar">
		<input type="hidden" name="page" value="ptp-analytics" />
		<input type="date" name="date_from" value="<?php echo esc_attr( $ptp_dashboard['date_from'] ); ?>" aria-label="<?php esc_attr_e( 'From date', 'personal-project-tracker' ); ?>" />
		<input type="date" name="date_to" value="<?php echo esc_attr( $ptp_dashboard['date_to'] ); ?>" aria-label="<?php esc_attr_e( 'To date', 'personal-project-tracker' ); ?>" />
		<button type="submit" class="button"><?php esc_html_e( 'Update Range', 'personal-project-tracker' ); ?></button>
	</form>
</div>

<div class="ptp-stats-grid">
	<div class="ptp-stat-tile">
		<span class="ptp-stat-value"><?php echo null !== $ptp_dashboard['project_progress']['average_percent'] ? esc_html( $ptp_dashboard['project_progress']['average_percent'] ) . '%' : '&#8212;'; ?></span>
		<span class="ptp-stat-label"><?php esc_html_e( 'Avg. Project Progress', 'personal-project-tracker' ); ?></span>
	</div>
	<div class="ptp-stat-tile">
		<span class="ptp-stat-value"><?php echo null !== $ptp_dashboard['task_completion']['completion_rate'] ? esc_html( $ptp_dashboard['task_completion']['completion_rate'] ) . '%' : '&#8212;'; ?></span>
		<span class="ptp-stat-label"><?php esc_html_e( 'Task Completion Rate', 'personal-project-tracker' ); ?></span>
	</div>
	<div class="ptp-stat-tile ptp-stat-tile-warning">
		<span class="ptp-stat-value"><?php echo esc_html( number_format_i18n( $ptp_dashboard['overdue_tasks'] ) ); ?></span>
		<span class="ptp-stat-label"><?php esc_html_e( 'Overdue Tasks', 'personal-project-tracker' ); ?></span>
	</div>
	<div class="ptp-stat-tile">
		<span class="ptp-stat-value"><?php echo esc_html( ptp_format_duration( $ptp_dashboard['time_trends']['total_seconds'] ) ); ?></span>
		<span class="ptp-stat-label"><?php esc_html_e( 'Time Tracked (range)', 'personal-project-tracker' ); ?></span>
	</div>
	<?php if ( $ptp_can_finance && isset( $ptp_dashboard['finance'] ) ) : ?>
		<?php $ptp_finance = $ptp_dashboard['finance']; ?>
		<?php if ( isset( $ptp_finance['by_currency'] ) ) : ?>
			<?php foreach ( $ptp_finance['by_currency'] as $ptp_currency_row ) : ?>
				<div class="ptp-stat-tile">
					<span class="ptp-stat-value"><?php echo esc_html( ptp_format_currency( $ptp_currency_row['revenue'], $ptp_currency_row['currency'] ) ); ?></span>
					<span class="ptp-stat-label"><?php echo esc_html( $ptp_currency_row['currency'] ); ?> <?php esc_html_e( 'Revenue', 'personal-project-tracker' ); ?></span>
				</div>
				<div class="ptp-stat-tile">
					<span class="ptp-stat-value"><?php echo esc_html( ptp_format_currency( $ptp_currency_row['expenses'], $ptp_currency_row['currency'] ) ); ?></span>
					<span class="ptp-stat-label"><?php echo esc_html( $ptp_currency_row['currency'] ); ?> <?php esc_html_e( 'Expenses', 'personal-project-tracker' ); ?></span>
				</div>
				<div class="ptp-stat-tile <?php echo esc_attr( $ptp_currency_row['profit'] < 0 ? 'ptp-stat-tile-warning' : '' ); ?>">
					<span class="ptp-stat-value"><?php echo esc_html( ptp_format_currency( $ptp_currency_row['profit'], $ptp_currency_row['currency'] ) ); ?></span>
					<span class="ptp-stat-label"><?php echo esc_html( $ptp_currency_row['currency'] ); ?> <?php esc_html_e( 'Profit', 'personal-project-tracker' ); ?></span>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>
	<?php endif; ?>
</div>

<div class="ptp-card">
	<h2><?php esc_html_e( 'Time Trends', 'personal-project-tracker' ); ?></h2>
	<?php if ( empty( $ptp_dashboard['time_trends']['daily'] ) ) : ?>
		<p class="description"><?php esc_html_e( 'No tracked time in this range.', 'personal-project-tracker' ); ?></p>
	<?php else : ?>
		<div class="ptp-bar-chart" role="img" aria-label="<?php esc_attr_e( 'Daily tracked time bar chart', 'personal-project-tracker' ); ?>">
			<?php foreach ( $ptp_dashboard['time_trends']['daily'] as $ptp_date => $ptp_seconds ) : ?>
				<?php $ptp_pct = $ptp_max_daily > 0 ? max( 2, round( ( $ptp_seconds / $ptp_max_daily ) * 100 ) ) : 0; ?>
				<div class="ptp-bar-chart-col">
					<div class="ptp-bar-chart-bar" style="height:<?php echo esc_attr( $ptp_pct ); ?>%" title="<?php echo esc_attr( mysql2date( get_option( 'date_format' ), $ptp_date ) . ': ' . ptp_format_duration( $ptp_seconds ) ); ?>"></div>
					<span class="ptp-bar-chart-label"><?php echo esc_html( mysql2date( 'j M', $ptp_date ) ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>

<div class="ptp-card">
	<h2><?php esc_html_e( 'Task Completion', 'personal-project-tracker' ); ?></h2>
	<?php if ( empty( $ptp_dashboard['task_completion']['by_status'] ) ) : ?>
		<p class="description"><?php esc_html_e( 'No tasks in this range.', 'personal-project-tracker' ); ?></p>
	<?php else : ?>
		<?php $ptp_statuses = PTP_Tasks_Repository::get_statuses(); ?>
		<table class="widefat striped ptp-status-table">
			<tbody>
				<?php foreach ( $ptp_dashboard['task_completion']['by_status'] as $ptp_status_key => $ptp_count ) : ?>
					<tr>
						<th scope="row"><span class="ptp-badge ptp-badge-status-<?php echo esc_attr( $ptp_status_key ); ?>"><?php echo esc_html( $ptp_statuses[ $ptp_status_key ] ?? $ptp_status_key ); ?></span></th>
						<td><?php echo esc_html( number_format_i18n( $ptp_count ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>

<p class="description">
	<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-reports' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'View detailed Reports &raquo;', 'personal-project-tracker' ); ?></a>
</p>
