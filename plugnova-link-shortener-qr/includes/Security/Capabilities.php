<?php
/**
 * Custom capability + role wiring for the plugin.
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Capabilities
 */
final class Capabilities {

	/**
	 * The custom capability required to manage links/settings for this plugin.
	 */
	public const MANAGE_LINKS = 'manage_qlqr_links';

	/**
	 * Grant the plugin's custom capability to Administrator and Editor roles on activation.
	 * Site owners can further restrict this via the Settings > Allowed Roles option, which
	 * is enforced at runtime rather than by removing the underlying WordPress capability.
	 */
	public static function add_roles(): void {
		foreach ( array( 'administrator', 'editor' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role && ! $role->has_cap( self::MANAGE_LINKS ) ) {
				$role->add_cap( self::MANAGE_LINKS );
			}
		}
	}

	/**
	 * Whether the current user is allowed to manage links, honoring both the WP capability
	 * and the plugin's configurable "Allowed Roles" setting.
	 *
	 * @return bool
	 */
	public static function current_user_can_manage(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		if ( ! current_user_can( self::MANAGE_LINKS ) ) {
			return false;
		}

		$allowed_roles = (array) get_option( 'qlqr_allowed_roles', array( 'administrator', 'editor' ) );
		$user          = wp_get_current_user();

		return (bool) array_intersect( $allowed_roles, (array) $user->roles );
	}
}
