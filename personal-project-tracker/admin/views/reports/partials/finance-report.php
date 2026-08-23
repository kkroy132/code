<?php
/**
 * Finance Report partial. Only reached when the viewer holds
 * ptp_manage_finance — reports-page.php falls back to the Project Report
 * otherwise.
 *
 * Uses $ptp_filter_args from the including reports-page.php.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ptp_report = PTP_Reports_Service::get_finance_report( $ptp_filter_args );
?>
<div class="ptp-card">
	<h2><?php esc_html_e( 'Finance Report', 'personal-project-tracker' ); ?></h2>

	<?php if ( isset( $ptp_report['currency'] ) && ! isset( $ptp_report['by_currency'] ) ) : ?>
		<?php // Single-project summary shape (PTP_Finance_Service::get_project_summary()). ?>
		<div class="ptp-stats-grid">
			<div class="ptp-stat-tile">
				<span class="ptp-stat-value"><?php echo esc_html( ptp_format_currency( $ptp_report['revenue'], $ptp_report['currency'] ) ); ?></span>
				<span class="ptp-stat-label"><?php esc_html_e( 'Revenue', 'personal-project-tracker' ); ?></span>
			</div>
			<div class="ptp-stat-tile">
				<span class="ptp-stat-value"><?php echo esc_html( ptp_format_currency( $ptp_report['expenses'], $ptp_report['currency'] ) ); ?></span>
				<span class="ptp-stat-label"><?php esc_html_e( 'Expenses', 'personal-project-tracker' ); ?></span>
			</div>
			<div class="ptp-stat-tile <?php echo esc_attr( $ptp_report['profit'] < 0 ? 'ptp-stat-tile-warning' : '' ); ?>">
				<span class="ptp-stat-value"><?php echo esc_html( ptp_format_currency( $ptp_report['profit'], $ptp_report['currency'] ) ); ?></span>
				<span class="ptp-stat-label"><?php esc_html_e( 'Profit', 'personal-project-tracker' ); ?></span>
			</div>
			<div class="ptp-stat-tile">
				<span class="ptp-stat-value"><?php echo null !== $ptp_report['profit_margin'] ? esc_html( $ptp_report['profit_margin'] ) . '%' : '&#8212;'; ?></span>
				<span class="ptp-stat-label"><?php esc_html_e( 'Profit Margin', 'personal-project-tracker' ); ?></span>
			</div>
			<div class="ptp-stat-tile">
				<span class="ptp-stat-value"><?php echo null !== $ptp_report['budget'] ? esc_html( ptp_format_currency( $ptp_report['budget'], $ptp_report['currency'] ) ) : '&#8212;'; ?></span>
				<span class="ptp-stat-label"><?php esc_html_e( 'Budget', 'personal-project-tracker' ); ?></span>
			</div>
			<div class="ptp-stat-tile <?php echo esc_attr( $ptp_report['flags']['over_budget'] || $ptp_report['flags']['budget_warning'] ? 'ptp-stat-tile-warning' : '' ); ?>">
				<span class="ptp-stat-value"><?php echo null !== $ptp_report['budget_usage'] ? esc_html( $ptp_report['budget_usage'] ) . '%' : '&#8212;'; ?></span>
				<span class="ptp-stat-label"><?php esc_html_e( 'Budget Usage', 'personal-project-tracker' ); ?></span>
			</div>
		</div>
		<?php if ( ! empty( $ptp_report['mixed_currency'] ) ) : ?>
			<p class="description"><?php esc_html_e( 'Entries in a different currency are shown separately — amounts are never converted or combined.', 'personal-project-tracker' ); ?></p>
			<ul class="ptp-simple-list">
				<?php foreach ( $ptp_report['other_currencies'] as $ptp_other ) : ?>
					<li>
						<?php
						printf(
							/* translators: 1: currency code, 2: revenue, 3: expenses. */
							esc_html__( '%1$s — Revenue %2$s, Expenses %3$s', 'personal-project-tracker' ),
							esc_html( $ptp_other['currency'] ),
							esc_html( ptp_format_currency( $ptp_other['revenue'], $ptp_other['currency'] ) ),
							esc_html( ptp_format_currency( $ptp_other['expenses'], $ptp_other['currency'] ) )
						);
						?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

	<?php else : ?>
		<?php // Cross-project shape (PTP_Finance_Service::get_report()), grouped by currency. ?>
		<p class="description"><?php esc_html_e( 'Select a single project above to see its Budget and Budget Usage.', 'personal-project-tracker' ); ?></p>
		<div class="ptp-table-responsive">
			<table class="widefat striped ptp-report-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Currency', 'personal-project-tracker' ); ?></th>
						<th><?php esc_html_e( 'Revenue', 'personal-project-tracker' ); ?></th>
						<th><?php esc_html_e( 'Expenses', 'personal-project-tracker' ); ?></th>
						<th><?php esc_html_e( 'Profit', 'personal-project-tracker' ); ?></th>
						<th><?php esc_html_e( 'Profit Margin', 'personal-project-tracker' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $ptp_report['by_currency'] as $ptp_currency_row ) : ?>
						<tr>
							<td><?php echo esc_html( $ptp_currency_row['currency'] ); ?></td>
							<td><?php echo esc_html( ptp_format_currency( $ptp_currency_row['revenue'], $ptp_currency_row['currency'] ) ); ?></td>
							<td><?php echo esc_html( ptp_format_currency( $ptp_currency_row['expenses'], $ptp_currency_row['currency'] ) ); ?></td>
							<td>
								<span class="<?php echo esc_attr( $ptp_currency_row['profit'] < 0 ? 'ptp-text-danger' : '' ); ?>">
									<?php echo esc_html( ptp_format_currency( $ptp_currency_row['profit'], $ptp_currency_row['currency'] ) ); ?>
								</span>
							</td>
							<td><?php echo null !== $ptp_currency_row['profit_margin'] ? esc_html( $ptp_currency_row['profit_margin'] ) . '%' : '&#8212;'; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</div>
