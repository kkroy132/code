<?php
/**
 * Google OAuth for Search Console access — proxied through the vendor's
 * own backend rather than talking to Google's token endpoint directly
 * with an embedded client secret.
 *
 * Why: a Google OAuth client secret shipped inside a WordPress plugin
 * (even a paid one) is extractable by anyone who has a copy of the
 * plugin files — it can never actually be kept confidential client-side.
 * The standard, correct pattern (used by, among others, Google's own
 * Site Kit plugin) is a server-side proxy that holds the real secret and
 * performs the code/token exchange itself; the distributed plugin only
 * ever talks to that proxy, never to Google's token endpoint directly.
 *
 * SEODOC_PRO_API_BASE points at that proxy. It is not a live, deployed
 * backend yet — see docs/12 for why that's a placeholder and not
 * fabricated, the same reasoning applied to Action Scheduler (Step 4)
 * and the Freemius SDK (Step 14).
 *
 * @package SEODocPro
 */

namespace SEODocPro\Gsc;

use SEODoc\Http_Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Oauth {

	const OPTION_TOKENS = 'seodoc_pro_gsc_tokens';

	const STATE_TRANSIENT = 'seodoc_pro_gsc_oauth_state';

	/**
	 * @return string Base URL of the vendor's OAuth proxy, filterable for
	 *                self-hosted/white-label deployments.
	 */
	public static function api_base() {
		return apply_filters( 'seodoc_pro_api_base', 'https://api.wpseodoctor.com' );
	}

	/**
	 * @return string URL to send the site owner to, to start the Google
	 *                 consent flow.
	 */
	public static function get_authorize_url() {
		$state = wp_generate_password( 32, false );
		set_transient( self::STATE_TRANSIENT, $state, 10 * MINUTE_IN_SECONDS );

		return add_query_arg(
			array(
				'site'        => rawurlencode( home_url( '/' ) ),
				'return_url'  => rawurlencode( admin_url( 'admin.php?page=seodoc-gsc' ) ),
				'state'       => $state,
				'license_key' => rawurlencode( (string) get_option( 'seodoc_pro_license_key' ) ),
			),
			self::api_base() . '/oauth/google/start'
		);
	}

	/**
	 * Called when the proxy redirects back to the WP admin after
	 * completing the real Google exchange server-side, handing back a
	 * short-lived signed grant code — never a Google token directly at
	 * this stage.
	 *
	 * @param string $grant_code
	 * @param string $state
	 * @return true|\WP_Error
	 */
	public static function complete_connection( $grant_code, $state ) {
		$expected_state = get_transient( self::STATE_TRANSIENT );
		if ( ! $expected_state || ! hash_equals( $expected_state, (string) $state ) ) {
			return new \WP_Error(
				'seodoc_oauth_state_mismatch',
				__( 'This connection request could not be verified. Please try connecting again.', 'wp-seo-doctor-pro' )
			);
		}
		delete_transient( self::STATE_TRANSIENT );

		$response = Http_Client::get(
			add_query_arg( array( 'grant_code' => rawurlencode( $grant_code ) ), self::api_base() . '/oauth/google/exchange' )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['access_token'] ) || empty( $body['refresh_token'] ) ) {
			return new \WP_Error( 'seodoc_oauth_exchange_failed', __( 'Could not complete the Google connection.', 'wp-seo-doctor-pro' ) );
		}

		update_option(
			self::OPTION_TOKENS,
			array(
				'access_token'  => $body['access_token'],
				'refresh_token' => $body['refresh_token'],
				'expires_at'    => time() + ( isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3600 ),
				'property_url'  => isset( $body['property_url'] ) ? $body['property_url'] : home_url( '/' ),
			),
			false
		);

		return true;
	}

	public static function is_connected() {
		$tokens = get_option( self::OPTION_TOKENS );

		return ! empty( $tokens['refresh_token'] );
	}

	public static function disconnect() {
		delete_option( self::OPTION_TOKENS );
	}

	/**
	 * @return string|\WP_Error A valid (refreshed if necessary) access token.
	 */
	public static function get_valid_access_token() {
		$tokens = get_option( self::OPTION_TOKENS );

		if ( empty( $tokens['refresh_token'] ) ) {
			return new \WP_Error( 'seodoc_gsc_not_connected', __( 'Google Search Console is not connected.', 'wp-seo-doctor-pro' ) );
		}

		if ( ! empty( $tokens['access_token'] ) && ! empty( $tokens['expires_at'] ) && $tokens['expires_at'] > time() + 60 ) {
			return $tokens['access_token'];
		}

		// Refreshed via the same proxy — a refresh_token alone can't be
		// exchanged directly with Google without the client secret,
		// which by design never leaves the vendor's server.
		$response = Http_Client::get(
			add_query_arg( array( 'refresh_token' => rawurlencode( $tokens['refresh_token'] ) ), self::api_base() . '/oauth/google/refresh' )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['access_token'] ) ) {
			return new \WP_Error(
				'seodoc_gsc_refresh_failed',
				__( 'Could not refresh the Google Search Console connection. Please reconnect.', 'wp-seo-doctor-pro' )
			);
		}

		$tokens['access_token'] = $body['access_token'];
		$tokens['expires_at']   = time() + ( isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3600 );
		update_option( self::OPTION_TOKENS, $tokens, false );

		return $tokens['access_token'];
	}

	public static function get_property_url() {
		$tokens = get_option( self::OPTION_TOKENS );

		return isset( $tokens['property_url'] ) ? $tokens['property_url'] : home_url( '/' );
	}
}
