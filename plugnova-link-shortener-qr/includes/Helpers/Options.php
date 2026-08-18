<?php
/**
 * Centralized access to plugin settings stored via the WordPress Options API.
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Options
 */
final class Options {

	/**
	 * Default option values, applied on activation and used as get_option() fallbacks.
	 *
	 * @var array<string, mixed>
	 */
	private const DEFAULTS = array(
		'qlqr_url_prefix'          => 'go',
		'qlqr_bio_url_prefix'      => 'bio',
		'qlqr_default_redirect'    => 302,
		'qlqr_slug_length'         => 6,
		'qlqr_qr_default_size'     => 300,
		'qlqr_qr_default_fg'       => '#000000',
		'qlqr_qr_default_bg'       => '#ffffff',
		'qlqr_tracking_enabled'    => 1,
		'qlqr_rest_api_enabled'    => 1,
		'qlqr_rate_limit_per_min'  => 60,
		'qlqr_allowed_roles'       => array( 'administrator', 'editor' ),
		'qlqr_delete_on_uninstall' => 0,
		'qlqr_keyword_autolink_enabled' => 1,
		'qlqr_trust_proxy_headers' => 0,
		'qlqr_click_retention_months' => 12,
		'qlqr_exclude_admin_clicks' => 0,
		// Off by default: this makes a daily outbound request to every link's own destination_url
		// to check it's still reachable, which is a real request to a third-party server and must
		// be an explicit opt-in rather than something that starts happening on activation.
		'qlqr_broken_link_check_enabled' => 0,
	);

	/**
	 * Persist default options on activation. Uses add_option() so existing values are never overwritten.
	 */
	public static function set_defaults(): void {
		foreach ( self::DEFAULTS as $key => $value ) {
			add_option( $key, $value );
		}
	}

	/**
	 * Get a plugin option with a safe fallback to its documented default.
	 *
	 * @param string $key Option name (without needing the qlqr_ prefix repeated by callers).
	 * @return mixed
	 */
	public static function get( string $key ): mixed {
		$default = self::DEFAULTS[ $key ] ?? false;
		return get_option( $key, $default );
	}

	/**
	 * The documented default value for an option key, independent of whatever is currently
	 * stored. Used by sanitize callbacks that need a fallback that never reflects a bad stored
	 * value (e.g. rejecting an invalid submitted value should fall back to the shipped default,
	 * not to get_option()'s current, possibly-also-invalid value).
	 *
	 * @param string $key Option name.
	 * @return mixed Null if the key is not a known option.
	 */
	public static function default_value( string $key ): mixed {
		return self::DEFAULTS[ $key ] ?? null;
	}

	/**
	 * Update a plugin option.
	 *
	 * @param string $key   Option name.
	 * @param mixed  $value New value.
	 * @return bool
	 */
	public static function update( string $key, mixed $value ): bool {
		return update_option( $key, $value );
	}

	/**
	 * All option keys managed by this plugin (used by the settings page and uninstall routine).
	 *
	 * @return string[]
	 */
	public static function keys(): array {
		return array_keys( self::DEFAULTS );
	}
}
