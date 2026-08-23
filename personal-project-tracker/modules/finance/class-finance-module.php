<?php
/**
 * Finance module bootstrap.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Finance_Module
 *
 * Registers everything the Finance module needs: the admin-post handlers
 * for the Expense/Revenue forms, its REST routes, its own admin script,
 * and a "Finance" section on the Project detail page. The Finance section
 * is gated on ptp_manage_finance specifically — a user who can view a
 * project's Overview/Dates/Activity (ptp_manage_projects) is not
 * necessarily allowed to see its financial data, which must stay private.
 */
class PTP_Finance_Module {

	/**
	 * Hook registration. Called from PTP_Plugin::load_modules().
	 */
	public function register() {
		add_action( 'admin_post_ptp_save_expense', array( 'PTP_Finance_Controller', 'handle_save_expense' ) );
		add_action( 'admin_post_ptp_save_revenue', array( 'PTP_Finance_Controller', 'handle_save_revenue' ) );
		add_action( 'ptp_register_rest_routes', array( 'PTP_Finance_REST', 'register_routes' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'ptp_project_detail_sections', array( __CLASS__, 'render_project_finance_section' ) );
	}

	/**
	 * Enqueue the Finance screen's JS (quick delete actions).
	 *
	 * @param string $hook_suffix Current admin page hook suffix (unused; we key off $_GET['page']).
	 */
	public static function enqueue_assets( $hook_suffix ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! isset( $_GET['page'] ) || 'ptp-finance' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		wp_enqueue_script(
			'ptp-finance',
			PTP_PLUGIN_URL . 'modules/finance/assets/finance.js',
			array( 'ptp-admin' ),
			PTP_VERSION,
			true
		);

		wp_localize_script(
			'ptp-finance',
			'ptpFinance',
			array(
				'confirmDeleteExpense' => __( 'Delete this expense? This cannot be undone.', 'personal-project-tracker' ),
				'confirmDeleteRevenue' => __( 'Delete this revenue entry? This cannot be undone.', 'personal-project-tracker' ),
				'loadingText'          => __( 'Working…', 'personal-project-tracker' ),
				'errorGeneric'         => __( 'Something went wrong. Please try again.', 'personal-project-tracker' ),
			)
		);
	}

	/**
	 * Render a "Finance" card on the Project detail page: budget, revenue,
	 * expenses, profit, profit margin, budget usage, and alert badges.
	 * Read-only — managing entries stays on the Finance screen.
	 *
	 * @param object $project Project being viewed.
	 */
	public static function render_project_finance_section( $project ) {
		if ( ! current_user_can( 'ptp_manage_finance' ) || ! class_exists( 'PTP_Finance_Service' ) ) {
			return;
		}

		$summary = PTP_Finance_Service::get_project_summary( $project->id );

		if ( ! $summary ) {
			return;
		}

		$list_url = add_query_arg( array( 'page' => 'ptp-finance', 'project_id' => $project->id ), admin_url( 'admin.php' ) );
		?>
		<div class="ptp-card">
			<div class="ptp-page-header">
				<h2><?php esc_html_e( 'Finance', 'personal-project-tracker' ); ?></h2>
				<a class="button button-small" href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'View in Finance &raquo;', 'personal-project-tracker' ); ?></a>
			</div>

			<?php if ( $summary['flags']['over_budget'] ) : ?>
				<p class="ptp-finance-alert ptp-finance-alert-danger"><?php esc_html_e( 'Over budget.', 'personal-project-tracker' ); ?></p>
			<?php elseif ( $summary['flags']['budget_warning'] ) : ?>
				<p class="ptp-finance-alert ptp-finance-alert-warning"><?php esc_html_e( 'Approaching budget limit.', 'personal-project-tracker' ); ?></p>
			<?php endif; ?>
			<?php if ( $summary['flags']['negative_profit'] ) : ?>
				<p class="ptp-finance-alert ptp-finance-alert-danger"><?php esc_html_e( 'This project is currently running at a loss.', 'personal-project-tracker' ); ?></p>
			<?php endif; ?>

			<table class="widefat striped ptp-status-table">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Budget', 'personal-project-tracker' ); ?></th>
						<td><?php echo null !== $summary['budget'] ? esc_html( ptp_format_currency( $summary['budget'], $summary['currency'] ) ) : '&#8212;'; ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Revenue', 'personal-project-tracker' ); ?></th>
						<td><?php echo esc_html( ptp_format_currency( $summary['revenue'], $summary['currency'] ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Expenses', 'personal-project-tracker' ); ?></th>
						<td><?php echo esc_html( ptp_format_currency( $summary['expenses'], $summary['currency'] ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Profit', 'personal-project-tracker' ); ?></th>
						<td>
							<span class="<?php echo esc_attr( $summary['profit'] < 0 ? 'ptp-text-danger' : '' ); ?>">
								<?php echo esc_html( ptp_format_currency( $summary['profit'], $summary['currency'] ) ); ?>
							</span>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Profit Margin', 'personal-project-tracker' ); ?></th>
						<td><?php echo null !== $summary['profit_margin'] ? esc_html( $summary['profit_margin'] ) . '%' : '&#8212;'; ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Budget Usage', 'personal-project-tracker' ); ?></th>
						<td><?php echo null !== $summary['budget_usage'] ? esc_html( $summary['budget_usage'] ) . '%' : '&#8212;'; ?></td>
					</tr>
				</tbody>
			</table>

			<?php if ( $summary['mixed_currency'] ) : ?>
				<p class="description">
					<?php esc_html_e( 'This project also has entries in a different currency, shown separately below (never converted or combined into the totals above).', 'personal-project-tracker' ); ?>
				</p>
				<ul class="ptp-simple-list">
					<?php foreach ( $summary['other_currencies'] as $ptp_other ) : ?>
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
		</div>
		<?php
	}
}
