<?php
/**
 * Admin-side form handling for Expenses and Revenue (create/update).
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Finance_Controller
 *
 * Handles the classic admin-post.php submissions for the Add/Edit Expense
 * and Add/Edit Revenue forms, mirroring the other modules' controllers.
 * Both entity types are small enough to share one controller class without
 * duplicating the flash/redirect plumbing twice.
 */
class PTP_Finance_Controller {

	/**
	 * Transient key prefixes used to flash validation errors + submitted
	 * values back to the form after a redirect.
	 *
	 * @var string
	 */
	const EXPENSE_FLASH_KEY_PREFIX = 'ptp_expense_form_flash_';
	const REVENUE_FLASH_KEY_PREFIX = 'ptp_revenue_form_flash_';

	/**
	 * Handle POST admin-post.php?action=ptp_save_expense (create or update).
	 */
	public static function handle_save_expense() {
		PTP_Security::check_admin_referer();
		PTP_Security::require_capability( 'ptp_manage_finance' );

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		$raw = array(
			'project_id'   => $_POST['project_id'] ?? 0,
			'amount'       => $_POST['amount'] ?? '',
			'currency'     => $_POST['currency'] ?? '',
			'category'     => $_POST['category'] ?? '',
			'description'  => $_POST['description'] ?? '',
			'expense_date' => $_POST['expense_date'] ?? '',
		);

		$result = $id ? PTP_Expenses_Repository::update( $id, $raw ) : PTP_Expenses_Repository::create( $raw );

		if ( is_wp_error( $result ) ) {
			self::set_flash( self::EXPENSE_FLASH_KEY_PREFIX, $result->get_error_messages(), $raw );

			$redirect = $id
				? add_query_arg( array( 'page' => 'ptp-finance', 'action' => 'edit_expense', 'id' => $id ), admin_url( 'admin.php' ) )
				: add_query_arg( array( 'page' => 'ptp-finance', 'action' => 'new_expense' ), admin_url( 'admin.php' ) );

			wp_safe_redirect( $redirect );
			exit;
		}

		$new_id = $id ? $id : $result;

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'ptp-finance',
					'action'      => 'view_expense',
					'id'          => $new_id,
					'ptp_success' => $id ? 'updated' : 'created',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle POST admin-post.php?action=ptp_save_revenue (create or update).
	 */
	public static function handle_save_revenue() {
		PTP_Security::check_admin_referer();
		PTP_Security::require_capability( 'ptp_manage_finance' );

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		$raw = array(
			'project_id'   => $_POST['project_id'] ?? 0,
			'amount'       => $_POST['amount'] ?? '',
			'currency'     => $_POST['currency'] ?? '',
			'category'     => $_POST['category'] ?? '',
			'description'  => $_POST['description'] ?? '',
			'revenue_date' => $_POST['revenue_date'] ?? '',
		);

		$result = $id ? PTP_Revenue_Repository::update( $id, $raw ) : PTP_Revenue_Repository::create( $raw );

		if ( is_wp_error( $result ) ) {
			self::set_flash( self::REVENUE_FLASH_KEY_PREFIX, $result->get_error_messages(), $raw );

			$redirect = $id
				? add_query_arg( array( 'page' => 'ptp-finance', 'action' => 'edit_revenue', 'id' => $id ), admin_url( 'admin.php' ) )
				: add_query_arg( array( 'page' => 'ptp-finance', 'action' => 'new_revenue' ), admin_url( 'admin.php' ) );

			wp_safe_redirect( $redirect );
			exit;
		}

		$new_id = $id ? $id : $result;

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'ptp-finance',
					'action'      => 'view_revenue',
					'id'          => $new_id,
					'ptp_success' => $id ? 'updated' : 'created',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * @param string   $prefix Flash transient key prefix (expense or revenue).
	 * @param string[] $errors Error messages.
	 * @param array    $data   Raw submitted values.
	 */
	private static function set_flash( $prefix, array $errors, array $data ) {
		set_transient(
			$prefix . get_current_user_id(),
			array(
				'errors' => $errors,
				'data'   => $data,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Read and clear the current user's flashed expense form state, if any.
	 *
	 * @return array{errors: string[], data: array}|null
	 */
	public static function get_expense_flash() {
		return self::read_flash( self::EXPENSE_FLASH_KEY_PREFIX );
	}

	/**
	 * Read and clear the current user's flashed revenue form state, if any.
	 *
	 * @return array{errors: string[], data: array}|null
	 */
	public static function get_revenue_flash() {
		return self::read_flash( self::REVENUE_FLASH_KEY_PREFIX );
	}

	/**
	 * @param string $prefix Flash transient key prefix.
	 * @return array{errors: string[], data: array}|null
	 */
	private static function read_flash( $prefix ) {
		$key   = $prefix . get_current_user_id();
		$flash = get_transient( $key );

		if ( $flash ) {
			delete_transient( $key );
		}

		return $flash ? $flash : null;
	}
}
