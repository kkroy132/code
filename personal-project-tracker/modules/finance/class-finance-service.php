<?php
/**
 * Centralized financial calculation service.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Finance_Service
 *
 * The single place profit, profit margin, and budget usage are computed.
 * Nothing else in the plugin re-derives these formulas — the Project
 * detail page, the Finance Reports page, and the REST API all call into
 * this service. It never converts between currencies: revenue/expense
 * totals are aggregated per currency by the repositories'
 * get_totals_by_currency() methods, and any amount recorded in a currency
 * other than a project's own is surfaced separately rather than combined.
 */
class PTP_Finance_Service {

	/**
	 * Profit = Revenue - Expenses.
	 *
	 * @param float $revenue  Total revenue.
	 * @param float $expenses Total expenses.
	 * @return float Rounded to 2 decimal places.
	 */
	public static function calculate_profit( $revenue, $expenses ) {
		return round( (float) $revenue - (float) $expenses, 2 );
	}

	/**
	 * Profit Margin = Profit / Revenue x 100.
	 *
	 * Undefined (null) when revenue is zero — there is nothing to take a
	 * margin of, and dividing by zero must never happen silently.
	 *
	 * @param float $revenue Total revenue.
	 * @param float $profit  Profit (revenue - expenses).
	 * @return float|null Percentage rounded to 2 decimal places, or null.
	 */
	public static function calculate_profit_margin( $revenue, $profit ) {
		$revenue = (float) $revenue;

		if ( $revenue <= 0.0 ) {
			return null;
		}

		return round( ( (float) $profit / $revenue ) * 100, 2 );
	}

	/**
	 * Budget Usage = Expenses / Budget x 100.
	 *
	 * Undefined (null) when no budget is set or the budget is zero.
	 *
	 * @param float      $expenses Total expenses.
	 * @param float|null $budget   Project budget, or null when not set.
	 * @return float|null Percentage rounded to 2 decimal places, or null.
	 */
	public static function calculate_budget_usage( $expenses, $budget ) {
		if ( null === $budget ) {
			return null;
		}

		$budget = (float) $budget;

		if ( $budget <= 0.0 ) {
			return null;
		}

		return round( ( (float) $expenses / $budget ) * 100, 2 );
	}

	/**
	 * Derive alert flags from an already-computed summary row. This is
	 * only an integration point for a future Notifications module — no
	 * notification, email, or persisted alert is created here.
	 *
	 * @param array $summary A summary array with at least 'profit' and 'budget_usage' keys.
	 * @return array{budget_warning: bool, over_budget: bool, negative_profit: bool}
	 */
	public static function get_alert_flags( array $summary ) {
		$flags = array(
			'budget_warning'  => false,
			'over_budget'     => false,
			'negative_profit' => false,
		);

		if ( null !== ( $summary['budget_usage'] ?? null ) ) {
			$threshold = (float) PTP_Settings::get( 'budget_warning_threshold', 80 );

			if ( $summary['budget_usage'] >= 100 ) {
				$flags['over_budget'] = true;
			} elseif ( $summary['budget_usage'] >= $threshold ) {
				$flags['budget_warning'] = true;
			}
		}

		if ( ( $summary['profit'] ?? 0 ) < 0 ) {
			$flags['negative_profit'] = true;
		}

		return $flags;
	}

	/**
	 * Full financial summary for a single project, in the project's own
	 * currency: revenue, expenses, profit, profit margin, budget usage,
	 * and alert flags. Amounts recorded in any other currency are listed
	 * separately under 'other_currencies' rather than folded in.
	 *
	 * @param int   $project_id Project ID.
	 * @param array $args       Optional date_from/date_to/category filters, same shape as the repositories' get_list().
	 * @return array|null Null when the project does not exist.
	 */
	public static function get_project_summary( $project_id, array $args = array() ) {
		$project = PTP_Projects_Repository::get( $project_id );

		if ( ! $project ) {
			return null;
		}

		$primary_currency = $project->currency;

		$revenue_totals = PTP_Revenue_Repository::get_totals_by_currency( $project_id, $args );
		$expense_totals = PTP_Expenses_Repository::get_totals_by_currency( $project_id, $args );

		$revenue  = $revenue_totals[ $primary_currency ] ?? 0.0;
		$expenses = $expense_totals[ $primary_currency ] ?? 0.0;
		$profit   = self::calculate_profit( $revenue, $expenses );
		$budget   = null !== $project->budget ? (float) $project->budget : null;

		$summary = array(
			'project_id'    => (int) $project_id,
			'currency'      => $primary_currency,
			'revenue'       => round( $revenue, 2 ),
			'expenses'      => round( $expenses, 2 ),
			'budget'        => $budget,
			'profit'        => $profit,
			'profit_margin' => self::calculate_profit_margin( $revenue, $profit ),
			'budget_usage'  => self::calculate_budget_usage( $expenses, $budget ),
		);

		$summary['flags'] = self::get_alert_flags( $summary );

		$other_currencies = array();

		foreach ( array_unique( array_merge( array_keys( $revenue_totals ), array_keys( $expense_totals ) ) ) as $currency ) {
			if ( $currency === $primary_currency ) {
				continue;
			}

			$other_revenue  = $revenue_totals[ $currency ] ?? 0.0;
			$other_expenses = $expense_totals[ $currency ] ?? 0.0;

			$other_currencies[ $currency ] = array(
				'currency' => $currency,
				'revenue'  => round( $other_revenue, 2 ),
				'expenses' => round( $other_expenses, 2 ),
				'profit'   => self::calculate_profit( $other_revenue, $other_expenses ),
			);
		}

		$summary['other_currencies'] = $other_currencies;
		$summary['mixed_currency']   = ! empty( $other_currencies );

		return $summary;
	}

	/**
	 * Reduced version of get_project_summary() — currency/revenue/expenses/
	 * profit only, in each project's own currency, for many projects in a
	 * single pair of aggregate queries. Used by the Reports project listing
	 * (PTP_Reports_Service::get_project_report()), which previously called
	 * get_project_summary() once per project (each of those a project
	 * re-fetch plus two more aggregate queries). Budget/profit margin/alert
	 * flags/other-currency breakdowns are intentionally omitted — the
	 * project listing never displays them (get_project_summary() remains
	 * the one place that computes them, for the single-project case).
	 *
	 * @param object[] $projects Project rows (already fetched by the caller — never re-fetched here).
	 * @param array    $args     Optional date_from/date_to filters.
	 * @return array<int, array{currency: string, revenue: float, expenses: float, profit: float}> Keyed by project_id.
	 */
	public static function get_project_summaries( array $projects, array $args = array() ) {
		$project_ids = wp_list_pluck( $projects, 'id' );

		if ( empty( $project_ids ) ) {
			return array();
		}

		$revenue_by_project  = PTP_Revenue_Repository::get_totals_by_currency_by_projects( $project_ids, $args );
		$expenses_by_project = PTP_Expenses_Repository::get_totals_by_currency_by_projects( $project_ids, $args );

		$summaries = array();

		foreach ( $projects as $project ) {
			$project_id       = (int) $project->id;
			$primary_currency = $project->currency;
			$revenue          = $revenue_by_project[ $project_id ][ $primary_currency ] ?? 0.0;
			$expenses         = $expenses_by_project[ $project_id ][ $primary_currency ] ?? 0.0;

			$summaries[ $project_id ] = array(
				'currency' => $primary_currency,
				'revenue'  => round( $revenue, 2 ),
				'expenses' => round( $expenses, 2 ),
				'profit'   => self::calculate_profit( $revenue, $expenses ),
			);
		}

		return $summaries;
	}

	/**
	 * Cross-project totals for the Finance Reports page, grouped by
	 * currency (never combined). Budget/budget usage are intentionally
	 * omitted here — a budget belongs to a single project, so it is only
	 * meaningful in get_project_summary() for one specific project.
	 *
	 * @param array $args {
	 *     @type int    $project_id 0 for all projects.
	 *     @type string $category   Category filter.
	 *     @type string $date_from  'Y-m-d' range start.
	 *     @type string $date_to    'Y-m-d' range end.
	 * }
	 * @return array{by_currency: array, mixed_currency: bool}
	 */
	public static function get_report( array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'project_id' => 0,
				'category'   => '',
				'date_from'  => '',
				'date_to'    => '',
			)
		);

		$revenue_totals = PTP_Revenue_Repository::get_totals_by_currency( $args['project_id'], $args );
		$expense_totals = PTP_Expenses_Repository::get_totals_by_currency( $args['project_id'], $args );

		$currencies = array_unique( array_merge( array_keys( $revenue_totals ), array_keys( $expense_totals ) ) );

		if ( empty( $currencies ) ) {
			$currencies = array( PTP_Settings::get( 'default_currency', 'USD' ) );
		}

		$by_currency = array();

		foreach ( $currencies as $currency ) {
			$revenue  = $revenue_totals[ $currency ] ?? 0.0;
			$expenses = $expense_totals[ $currency ] ?? 0.0;
			$profit   = self::calculate_profit( $revenue, $expenses );

			$by_currency[ $currency ] = array(
				'currency'      => $currency,
				'revenue'       => round( $revenue, 2 ),
				'expenses'      => round( $expenses, 2 ),
				'profit'        => $profit,
				'profit_margin' => self::calculate_profit_margin( $revenue, $profit ),
			);
		}

		return array(
			'by_currency'    => $by_currency,
			'mixed_currency' => count( $by_currency ) > 1,
		);
	}
}
