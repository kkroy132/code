<?php
/**
 * Centralized security helpers: nonces, sanitization, and permission gates.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Security
 *
 * Every form submission, AJAX call, and REST request in this plugin routes
 * its nonce/capability checks through here so the rules stay in one place.
 */
class PTP_Security {

	/**
	 * Nonce action used for the plugin's admin-side forms and AJAX calls.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'ptp_nonce_action';

	/**
	 * Nonce field/query-arg name.
	 *
	 * @var string
	 */
	const NONCE_NAME = 'ptp_nonce';

	/**
	 * Output a nonce hidden field for an admin form.
	 */
	public static function nonce_field() {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
	}

	/**
	 * Create a nonce for use in AJAX requests / REST headers.
	 *
	 * @return string
	 */
	public static function create_nonce() {
		return wp_create_nonce( self::NONCE_ACTION );
	}

	/**
	 * Verify a nonce value coming from $_POST/$_GET/$_REQUEST.
	 *
	 * @param string $nonce Nonce value to check.
	 * @return bool
	 */
	public static function verify_nonce( $nonce ) {
		return (bool) wp_verify_nonce( $nonce, self::NONCE_ACTION );
	}

	/**
	 * Verify the request nonce and die with a friendly error if it fails.
	 *
	 * Intended for classic admin-post/admin-ajax handlers.
	 */
	public static function check_admin_referer() {
		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );
	}

	/**
	 * Require a capability, or die with a friendly error.
	 *
	 * @param string $capability Capability to require.
	 */
	public static function require_capability( $capability ) {
		if ( ! current_user_can( $capability ) ) {
			wp_die(
				esc_html__( 'You do not have permission to perform this action.', 'personal-project-tracker' ),
				esc_html__( 'Permission denied', 'personal-project-tracker' ),
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * REST API permission callback factory: require a specific capability.
	 *
	 * Usage: 'permission_callback' => PTP_Security::rest_permission( 'ptp_manage_projects' )
	 *
	 * @param string $capability Capability required for the endpoint.
	 * @return callable
	 */
	public static function rest_permission( $capability ) {
		return function () use ( $capability ) {
			return current_user_can( $capability );
		};
	}

	/**
	 * Sanitize a plain single-line text field.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function sanitize_text( $value ) {
		return sanitize_text_field( wp_unslash( (string) $value ) );
	}

	/**
	 * Sanitize a multi-line plain text field (no HTML).
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function sanitize_textarea( $value ) {
		return sanitize_textarea_field( wp_unslash( (string) $value ) );
	}

	/**
	 * Sanitize rich text content that may legitimately contain limited HTML
	 * (e.g. note bodies, project descriptions).
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function sanitize_rich_text( $value ) {
		return wp_kses_post( wp_unslash( (string) $value ) );
	}

	/**
	 * Sanitize and validate a URL field.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function sanitize_url( $value ) {
		return esc_url_raw( wp_unslash( (string) $value ) );
	}

	/**
	 * Sanitize an email address field.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function sanitize_email_field( $value ) {
		return sanitize_email( wp_unslash( (string) $value ) );
	}

	/**
	 * Sanitize a numeric (int) field.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function sanitize_int( $value ) {
		return (int) $value;
	}

	/**
	 * Sanitize a numeric (float/decimal) field, e.g. money amounts.
	 *
	 * @param mixed $value Raw value.
	 * @return float
	 */
	public static function sanitize_float( $value ) {
		return (float) wp_unslash( (string) $value );
	}

	/**
	 * Restrict a value to an allow-list, falling back to a safe default.
	 *
	 * Used for enum-like fields such as status/priority so unexpected values
	 * never reach the database or a SQL WHERE clause.
	 *
	 * @param string $value    Raw value.
	 * @param array  $allowed  Allowed values.
	 * @param string $default_value Fallback value if $value is not allowed.
	 * @return string
	 */
	public static function sanitize_enum( $value, array $allowed, $default_value ) {
		return in_array( $value, $allowed, true ) ? $value : $default_value;
	}
}
