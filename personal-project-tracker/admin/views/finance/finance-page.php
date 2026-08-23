<?php
/**
 * Finance page: filterable Financial Summary/Reports, plus the Expense and
 * Revenue lists.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ptp_project_id  = isset( $_GET['project_id'] ) ? absint( $_GET['project_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_category    = isset( $_GET['category'] ) ? sanitize_text_field( wp_unslash( $_GET['category'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_date_from   = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_date_to     = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_exp_paged   = isset( $_GET['expense_paged'] ) ? max( 1, absint( $_GET['expense_paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_rev_paged   = isset( $_GET['revenue_paged'] ) ? max( 1, absint( $_GET['revenue_paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_has_filters = $ptp_project_id || $ptp_category || $ptp_date_from || $ptp_date_to;

$ptp_report_args = array(
	'project_id' => $ptp_project_id,
	'category'   => $ptp_category,
	'date_from'  => $ptp_date_from,
	'date_to'    => $ptp_date_to,
);

$ptp_projects = PTP_Projects_Repository::get_options_for_select();

$ptp_summary = $ptp_project_id ? PTP_Finance_Service::get_project_summary( $ptp_project_id, $ptp_report_args ) : null;
$ptp_report  = PTP_Finance_Service::get_report( $ptp_report_args );

$ptp_expenses = PTP_Expenses_Repository::get_list( array_merge( $ptp_report_args, array( 'paged' => $ptp_exp_paged, 'per_page' => 10 ) ) );
$ptp_revenue  = PTP_Revenue_Repository::get_list( array_merge( $ptp_report_args, array( 'paged' => $ptp_rev_paged, 'per_page' => 10 ) ) );
?>
<div class="ptp-page-header">
	<h1><?php esc_html_e( 'Finance', 'personal-project-tracker' ); ?></h1>
	<div class="ptp-quick-actions">
		<a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-finance', 'action' => 'new_expense' ), admin_url( 'admin.php' ) ) ); ?>">
			<?php esc_html_e( '+ Add Expense', 'personal-project-tracker' ); ?>
		</a>
		<a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-finance', 'action' => 'new_revenue' ), admin_url( 'admin.php' ) ) ); ?>">
			<?php esc_html_e( '+ Add Revenue', 'personal-project-tracker' ); ?>
		</a>
		<?php if ( $ptp_project_id && current_user_can( 'ptp_manage_data' ) ) : ?>
			<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-ai-prompts', 'action' => 'new', 'context_type' => 'finance', 'project_id' => $ptp_project_id, 'include_finance' => 1 ), admin_url( 'admin.php' ) ) ); ?>">
				<?php esc_html_e( 'Generate Financial Analysis Prompt', 'personal-project-tracker' ); ?>
			</a>
		<?php endif; ?>
	</div>
</div>

<div class="ptp-card">
	<form method="get" class="ptp-filter-bar">
		<input type="hidden" name="page" value="ptp-finance" />

		<select name="project_id">
			<option value=""><?php esc_html_e( 'All projects', 'personal-project-tracker' ); ?></option>
			<?php foreach ( $ptp_projects as $ptp_pid => $ptp_ptitle ) : ?>
				<option value="<?php echo esc_attr( $ptp_pid ); ?>" <?php selected( $ptp_project_id, $ptp_pid ); ?>>
					<?php echo esc_html( $ptp_ptitle ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<input type="date" name="date_from" value="<?php echo esc_attr( $ptp_date_from ); ?>" aria-label="<?php esc_attr_e( 'From date', 'personal-project-tracker' ); ?>" />
		<input type="date" name="date_to" value="<?php echo esc_attr( $ptp_date_to ); ?>" aria-label="<?php esc_attr_e( 'To date', 'personal-project-tracker' ); ?>" />

		<input
			type="text"
			name="category"
			value="<?php echo esc_attr( $ptp_category ); ?>"
			placeholder="<?php esc_attr_e( 'Category', 'personal-project-tracker' ); ?>"
		/>

		<button type="submit" class="button"><?php esc_html_e( 'Filter', 'personal-project-tracker' ); ?></button>

		<?php if ( $ptp_has_filters ) : ?>
			<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-finance' ), admin_url( 'admin.php' ) ) ); ?>">
				<?php esc_html_e( 'Reset', 'personal-project-tracker' ); ?>
			</a>
		<?php endif; ?>
	</form>
</div>

<?php if ( $ptp_summary ) : ?>

	<?php if ( $ptp_summary['flags']['over_budget'] ) : ?>
		<div class="notice notice-error"><p><?php esc_html_e( 'This project is over budget.', 'personal-project-tracker' ); ?></p></div>
	<?php elseif ( $ptp_summary['flags']['budget_warning'] ) : ?>
		<div class="notice notice-warning"><p><?php esc_html_e( 'This project is approaching its budget limit.', 'personal-project-tracker' ); ?></p></div>
	<?php endif; ?>
	<?php if ( $ptp_summary['flags']['negative_profit'] ) : ?>
		<div class="notice notice-error"><p><?php esc_html_e( 'This project is currently running at a loss.', 'personal-project-tracker' ); ?></p></div>
	<?php endif; ?>

	<div class="ptp-stats-grid">
		<div class="ptp-stat-tile">
			<span class="ptp-stat-value"><?php echo esc_html( ptp_format_currency( $ptp_summary['revenue'], $ptp_summary['currency'] ) ); ?></span>
			<span class="ptp-stat-label"><?php esc_html_e( 'Revenue', 'personal-project-tracker' ); ?></span>
		</div>
		<div class="ptp-stat-tile">
			<span class="ptp-stat-value"><?php echo esc_html( ptp_format_currency( $ptp_summary['expenses'], $ptp_summary['currency'] ) ); ?></span>
			<span class="ptp-stat-label"><?php esc_html_e( 'Expenses', 'personal-project-tracker' ); ?></span>
		</div>
		<div class="ptp-stat-tile <?php echo esc_attr( $ptp_summary['profit'] < 0 ? 'ptp-stat-tile-warning' : '' ); ?>">
			<span class="ptp-stat-value"><?php echo esc_html( ptp_format_currency( $ptp_summary['profit'], $ptp_summary['currency'] ) ); ?></span>
			<span class="ptp-stat-label"><?php esc_html_e( 'Profit', 'personal-project-tracker' ); ?></span>
		</div>
		<div class="ptp-stat-tile">
			<span class="ptp-stat-value"><?php echo null !== $ptp_summary['profit_margin'] ? esc_html( $ptp_summary['profit_margin'] ) . '%' : '&#8212;'; ?></span>
			<span class="ptp-stat-label"><?php esc_html_e( 'Profit Margin', 'personal-project-tracker' ); ?></span>
		</div>
		<div class="ptp-stat-tile <?php echo esc_attr( $ptp_summary['flags']['over_budget'] || $ptp_summary['flags']['budget_warning'] ? 'ptp-stat-tile-warning' : '' ); ?>">
			<span class="ptp-stat-value"><?php echo null !== $ptp_summary['budget_usage'] ? esc_html( $ptp_summary['budget_usage'] ) . '%' : '&#8212;'; ?></span>
			<span class="ptp-stat-label"><?php esc_html_e( 'Budget Usage', 'personal-project-tracker' ); ?></span>
		</div>
	</div>

	<?php if ( $ptp_summary['mixed_currency'] ) : ?>
		<div class="ptp-card">
			<p class="description"><?php esc_html_e( 'Entries in a different currency are shown separately — amounts are never converted or combined.', 'personal-project-tracker' ); ?></p>
			<ul class="ptp-simple-list">
				<?php foreach ( $ptp_summary['other_currencies'] as $ptp_other ) : ?>
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
		</div>
	<?php endif; ?>

<?php else : ?>

	<div class="ptp-stats-grid">
		<?php foreach ( $ptp_report['by_currency'] as $ptp_currency_row ) : ?>
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
			<div class="ptp-stat-tile">
				<span class="ptp-stat-value"><?php echo null !== $ptp_currency_row['profit_margin'] ? esc_html( $ptp_currency_row['profit_margin'] ) . '%' : '&#8212;'; ?></span>
				<span class="ptp-stat-label"><?php echo esc_html( $ptp_currency_row['currency'] ); ?> <?php esc_html_e( 'Margin', 'personal-project-tracker' ); ?></span>
			</div>
		<?php endforeach; ?>
	</div>
	<p class="description"><?php esc_html_e( 'Select a single project above to see its Budget and Budget Usage.', 'personal-project-tracker' ); ?></p>

<?php endif; ?>

<div class="ptp-card">
	<div class="ptp-page-header">
		<h2><?php esc_html_e( 'Expenses', 'personal-project-tracker' ); ?></h2>
	</div>
	<?php if ( empty( $ptp_expenses['items'] ) ) : ?>
		<div class="ptp-empty-state">
			<span class="dashicons dashicons-money-alt" aria-hidden="true"></span>
			<p><?php esc_html_e( 'No expenses recorded yet.', 'personal-project-tracker' ); ?></p>
		</div>
	<?php else : ?>
		<div class="ptp-table-responsive">
			<table class="widefat striped ptp-expenses-table ptp-responsive-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'personal-project-tracker' ); ?></th>
						<th><?php esc_html_e( 'Amount', 'personal-project-tracker' ); ?></th>
						<th class="ptp-col-optional"><?php esc_html_e( 'Category', 'personal-project-tracker' ); ?></th>
						<th class="ptp-col-optional"><?php esc_html_e( 'Project', 'personal-project-tracker' ); ?></th>
						<th class="ptp-col-optional"><?php esc_html_e( 'Description', 'personal-project-tracker' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'personal-project-tracker' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $ptp_expenses['items'] as $ptp_expense ) : ?>
						<tr>
							<td data-label="<?php esc_attr_e( 'Date', 'personal-project-tracker' ); ?>"><?php echo esc_html( mysql2date( get_option( 'date_format' ), $ptp_expense->expense_date ) ); ?></td>
							<td data-label="<?php esc_attr_e( 'Amount', 'personal-project-tracker' ); ?>"><?php echo esc_html( ptp_format_currency( $ptp_expense->amount, $ptp_expense->currency ) ); ?></td>
							<td class="ptp-col-optional" data-label="<?php esc_attr_e( 'Category', 'personal-project-tracker' ); ?>"><?php echo esc_html( $ptp_expense->category ? $ptp_expense->category : '—' ); ?></td>
							<td class="ptp-col-optional" data-label="<?php esc_attr_e( 'Project', 'personal-project-tracker' ); ?>"><?php echo esc_html( $ptp_expense->project_id && isset( $ptp_projects[ (int) $ptp_expense->project_id ] ) ? $ptp_projects[ (int) $ptp_expense->project_id ] : '—' ); ?></td>
							<td class="ptp-col-optional" data-label="<?php esc_attr_e( 'Description', 'personal-project-tracker' ); ?>"><?php echo esc_html( $ptp_expense->description ? $ptp_expense->description : '—' ); ?></td>
							<td class="ptp-td-actions">
								<div class="ptp-quick-actions">
									<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-finance', 'action' => 'edit_expense', 'id' => $ptp_expense->id ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Edit', 'personal-project-tracker' ); ?></a>
									<button type="button" class="button button-small button-link-delete ptp-js-expense-delete" data-id="<?php echo esc_attr( $ptp_expense->id ); ?>">
										<?php esc_html_e( 'Delete', 'personal-project-tracker' ); ?>
									</button>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php if ( $ptp_expenses['total_pages'] > 1 ) : ?>
			<div class="ptp-pagination">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( 'expense_paged', '%#%' ),
							'format'    => '',
							'current'   => $ptp_expenses['page'],
							'total'     => $ptp_expenses['total_pages'],
							'prev_text' => __( '&laquo; Previous', 'personal-project-tracker' ),
							'next_text' => __( 'Next &raquo;', 'personal-project-tracker' ),
						)
					)
				);
				?>
			</div>
		<?php endif; ?>
	<?php endif; ?>
</div>

<div class="ptp-card">
	<div class="ptp-page-header">
		<h2><?php esc_html_e( 'Revenue', 'personal-project-tracker' ); ?></h2>
	</div>
	<?php if ( empty( $ptp_revenue['items'] ) ) : ?>
		<div class="ptp-empty-state">
			<span class="dashicons dashicons-chart-line" aria-hidden="true"></span>
			<p><?php esc_html_e( 'No revenue recorded yet.', 'personal-project-tracker' ); ?></p>
		</div>
	<?php else : ?>
		<div class="ptp-table-responsive">
			<table class="widefat striped ptp-revenue-table ptp-responsive-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'personal-project-tracker' ); ?></th>
						<th><?php esc_html_e( 'Amount', 'personal-project-tracker' ); ?></th>
						<th class="ptp-col-optional"><?php esc_html_e( 'Category', 'personal-project-tracker' ); ?></th>
						<th class="ptp-col-optional"><?php esc_html_e( 'Project', 'personal-project-tracker' ); ?></th>
						<th class="ptp-col-optional"><?php esc_html_e( 'Description', 'personal-project-tracker' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'personal-project-tracker' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $ptp_revenue['items'] as $ptp_rev ) : ?>
						<tr>
							<td data-label="<?php esc_attr_e( 'Date', 'personal-project-tracker' ); ?>"><?php echo esc_html( mysql2date( get_option( 'date_format' ), $ptp_rev->revenue_date ) ); ?></td>
							<td data-label="<?php esc_attr_e( 'Amount', 'personal-project-tracker' ); ?>"><?php echo esc_html( ptp_format_currency( $ptp_rev->amount, $ptp_rev->currency ) ); ?></td>
							<td class="ptp-col-optional" data-label="<?php esc_attr_e( 'Category', 'personal-project-tracker' ); ?>"><?php echo esc_html( $ptp_rev->category ? $ptp_rev->category : '—' ); ?></td>
							<td class="ptp-col-optional" data-label="<?php esc_attr_e( 'Project', 'personal-project-tracker' ); ?>"><?php echo esc_html( $ptp_rev->project_id && isset( $ptp_projects[ (int) $ptp_rev->project_id ] ) ? $ptp_projects[ (int) $ptp_rev->project_id ] : '—' ); ?></td>
							<td class="ptp-col-optional" data-label="<?php esc_attr_e( 'Description', 'personal-project-tracker' ); ?>"><?php echo esc_html( $ptp_rev->description ? $ptp_rev->description : '—' ); ?></td>
							<td class="ptp-td-actions">
								<div class="ptp-quick-actions">
									<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-finance', 'action' => 'edit_revenue', 'id' => $ptp_rev->id ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Edit', 'personal-project-tracker' ); ?></a>
									<button type="button" class="button button-small button-link-delete ptp-js-revenue-delete" data-id="<?php echo esc_attr( $ptp_rev->id ); ?>">
										<?php esc_html_e( 'Delete', 'personal-project-tracker' ); ?>
									</button>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php if ( $ptp_revenue['total_pages'] > 1 ) : ?>
			<div class="ptp-pagination">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( 'revenue_paged', '%#%' ),
							'format'    => '',
							'current'   => $ptp_revenue['page'],
							'total'     => $ptp_revenue['total_pages'],
							'prev_text' => __( '&laquo; Previous', 'personal-project-tracker' ),
							'next_text' => __( 'Next &raquo;', 'personal-project-tracker' ),
						)
					)
				);
				?>
			</div>
		<?php endif; ?>
	<?php endif; ?>
</div>
