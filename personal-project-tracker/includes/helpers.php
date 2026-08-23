<?php
/**
 * General-purpose helper functions used across the plugin.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'ptp_table' ) ) {
	/**
	 * Get the fully-prefixed name of a plugin database table.
	 *
	 * Never hard-code the WordPress table prefix; always resolve it through $wpdb.
	 *
	 * @param string $name Short table name, e.g. 'projects'.
	 * @return string Fully-prefixed table name.
	 */
	function ptp_table( $name ) {
		global $wpdb;

		return $wpdb->prefix . 'ptp_' . $name;
	}
}

if ( ! function_exists( 'ptp_now' ) ) {
	/**
	 * Current site datetime, MySQL format, respecting the WordPress timezone setting.
	 *
	 * @return string
	 */
	function ptp_now() {
		return current_time( 'mysql' );
	}
}

if ( ! function_exists( 'ptp_current_user_id' ) ) {
	/**
	 * Current user ID, safe to call before init.
	 *
	 * @return int
	 */
	function ptp_current_user_id() {
		return get_current_user_id();
	}
}

if ( ! function_exists( 'ptp_format_currency' ) ) {
	/**
	 * Format an amount for display using the plugin's configured currency.
	 *
	 * @param float  $amount   Amount to format.
	 * @param string $currency Optional. ISO currency code. Defaults to the plugin setting.
	 * @return string
	 */
	function ptp_format_currency( $amount, $currency = '' ) {
		$amount   = is_numeric( $amount ) ? (float) $amount : 0.0;
		$currency = $currency ? $currency : PTP_Settings::get( 'default_currency', 'USD' );

		$symbols = array(
			'USD' => '$',
			'EUR' => '€',
			'GBP' => '£',
			'BDT' => '৳',
			'INR' => '₹',
			'JPY' => '¥',
		);

		$symbol = isset( $symbols[ $currency ] ) ? $symbols[ $currency ] : $currency . ' ';

		return $symbol . number_format_i18n( $amount, 2 );
	}
}

if ( ! function_exists( 'ptp_safe_redirect_admin' ) ) {
	/**
	 * Redirect back to a plugin admin page and stop execution.
	 *
	 * @param string $page  Submenu page slug (e.g. 'ptp-projects').
	 * @param array  $args  Extra query args to append.
	 */
	function ptp_safe_redirect_admin( $page, $args = array() ) {
		$url = add_query_arg(
			array_merge( array( 'page' => $page ), $args ),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}
}

if ( ! function_exists( 'ptp_format_duration' ) ) {
	/**
	 * Format a duration in seconds as a compact "Xh Ym" (or "Ym") string.
	 *
	 * @param int $seconds Duration in seconds.
	 * @return string
	 */
	function ptp_format_duration( $seconds ) {
		$seconds = max( 0, (int) $seconds );
		$hours   = (int) floor( $seconds / 3600 );
		$minutes = (int) floor( ( $seconds % 3600 ) / 60 );

		if ( $hours > 0 ) {
			/* translators: 1: hours, 2: minutes. */
			return sprintf( __( '%1$dh %2$dm', 'personal-project-tracker' ), $hours, $minutes );
		}

		/* translators: %d: minutes. */
		return sprintf( __( '%dm', 'personal-project-tracker' ), $minutes );
	}
}

if ( ! function_exists( 'ptp_log_error' ) ) {
	/**
	 * Log a technical error for debugging without exposing details to end users.
	 *
	 * Only writes when WP_DEBUG_LOG is enabled, and never logs sensitive values
	 * such as passwords, tokens, API keys, or secrets.
	 *
	 * @param string $message Message to log.
	 */
	function ptp_log_error( $message ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( '[Personal Project Tracker] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
