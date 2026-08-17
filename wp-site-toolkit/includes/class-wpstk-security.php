<?php
/**
 * Capability and request helpers.
 *
 * @package WP_Site_Toolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Centralises capability checks so site owners can delegate access with a single filter.
 *
 * @since 1.0.0
 */
class WPSTK_Security {

	/**
	 * Returns the capability required for a context.
	 *
	 * Contexts:
	 * - `view`   Reading dashboards, audit results and reports.
	 * - `run`    Starting or cancelling an audit.
	 * - `manage` Changing settings or deleting stored data.
	 *
	 * @param string $context Capability context.
	 *
	 * @return string Capability name.
	 */
	public static function capability( $context = 'view' ) {
		$defaults = array(
			'view'   => 'manage_options',
			'run'    => 'manage_options',
			'manage' => 'manage_options',
		);

		$capability = isset( $defaults[ $context ] ) ? $defaults[ $context ] : 'manage_options';

		/**
		 * Filters the capability required by WP Site Toolkit.
		 *
		 * Use this to give trusted editors read access, for example:
		 * `add_filter( 'wpstk_capability', function ( $cap, $context ) {
		 *     return 'view' === $context ? 'edit_others_posts' : $cap;
		 * }, 10, 2 );`
		 *
		 * @since 1.0.0
		 *
		 * @param string $capability Capability name.
		 * @param string $context    Requested context: view, run or manage.
		 */
		$capability = apply_filters( 'wpstk_capability', $capability, $context );

		return is_string( $capability ) && '' !== $capability ? $capability : 'manage_options';
	}

	/**
	 * Whether the current user may act in the given context.
	 *
	 * @param string $context Capability context.
	 *
	 * @return bool
	 */
	public static function can( $context = 'view' ) {
		return current_user_can( self::capability( $context ) );
	}

	/**
	 * Stops execution when the current user lacks the capability.
	 *
	 * @param string $context Capability context.
	 *
	 * @return void
	 */
	public static function require_cap( $context = 'view' ) {
		if ( ! self::can( $context ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access WP Site Toolkit on this site.', 'wp-site-toolkit' ),
				esc_html__( 'Permission denied', 'wp-site-toolkit' ),
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Verifies a nonce and capability for an AJAX request.
	 *
	 * Sends a JSON error and exits when the request is not valid.
	 *
	 * @param string $context Capability context.
	 *
	 * @return void
	 */
	public static function verify_ajax( $context = 'run' ) {
		if ( ! check_ajax_referer( 'wpstk_ajax', 'nonce', false ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Your session has expired. Please reload the page and try again.', 'wp-site-toolkit' ) ),
				403
			);

			return;
		}

		if ( ! self::can( $context ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to perform this action.', 'wp-site-toolkit' ) ),
				403
			);
		}
	}

	/**
	 * Returns a sanitised, unslashed GET value.
	 *
	 * The caller is responsible for nonce verification where the value triggers an action.
	 *
	 * @param string $key     Query argument name.
	 * @param string $default Fallback value.
	 *
	 * @return string
	 */
	public static function get_query_arg( $key, $default = '' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen routing; actions verify their own nonces.
		if ( ! isset( $_GET[ $key ] ) ) {
			return $default;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
		return sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
	}
}
