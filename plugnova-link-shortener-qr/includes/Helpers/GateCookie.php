<?php
/**
 * Remembers, per visitor, that a soft gate has been passed — a password-protected link/bio page
 * has been unlocked, or a bio page's email-capture form has been submitted.
 *
 * Uses a signed browser cookie rather than a native PHP session (which is discouraged on
 * WordPress and conflicts with full-page caching). The cookie value is a keyed hash the visitor
 * cannot forge without the site's secret salt, and — for password gates — it is bound to the
 * stored password hash, so changing a link's password automatically invalidates every visitor's
 * existing unlock cookie. Mirrors the mechanism WordPress core uses for post-password protection.
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GateCookie
 */
final class GateCookie {

	/**
	 * Prefix for every cookie this class sets, so they're easy to spot and namespace-collision-free.
	 */
	private const PREFIX = 'qlqr_gate_';

	/**
	 * Whether the visitor's request carries a valid cookie proving they've passed the given gate.
	 *
	 * @param string $key    Stable gate identifier, e.g. "bio_pw_5", "link_pw_5", "bio_email_5".
	 * @param string $secret Extra value bound into the token — the stored password hash for a
	 *                       password gate (so a password change invalidates old cookies), or '' for
	 *                       gates with nothing to bind to (e.g. the email-capture gate).
	 * @return bool
	 */
	public static function is_passed( string $key, string $secret = '' ): bool {
		$name = self::PREFIX . $key;

		if ( empty( $_COOKIE[ $name ] ) ) {
			return false;
		}

		$provided = sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) );

		return hash_equals( self::token( $key, $secret ), $provided );
	}

	/**
	 * Record that the visitor has passed the given gate, by sending a signed cookie. Also updates
	 * $_COOKIE for the current request so an is_passed() check later in the same request sees it
	 * immediately (setcookie() otherwise only affects subsequent requests).
	 *
	 * Must be called before any output is sent (all callers run on template_redirect, before the
	 * page is rendered, so this holds).
	 *
	 * @param string $key    Stable gate identifier (see is_passed()).
	 * @param string $secret Extra value bound into the token (see is_passed()).
	 */
	public static function mark_passed( string $key, string $secret = '' ): void {
		$name  = self::PREFIX . $key;
		$token = self::token( $key, $secret );

		// A session cookie (expires = 0): the gate stays passed until the browser is closed, matching
		// the behaviour of the PHP session this replaces.
		if ( ! headers_sent() ) {
			setcookie(
				$name,
				$token,
				array(
					'expires'  => 0,
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}

		$_COOKIE[ $name ] = $token;
	}

	/**
	 * Build the unforgeable cookie value for a gate. wp_hash() keys the value with the site's
	 * AUTH_SALT, so it cannot be reproduced by a visitor.
	 *
	 * @param string $key    Gate identifier.
	 * @param string $secret Bound secret (password hash, or '').
	 * @return string
	 */
	private static function token( string $key, string $secret ): string {
		return wp_hash( 'qlqr_gate|' . $key . '|' . $secret );
	}
}
