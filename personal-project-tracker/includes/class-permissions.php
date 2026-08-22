<?php
/**
 * Capability registration and permission checks.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Permissions
 *
 * Defines the plugin's custom capabilities and centralizes permission checks.
 * In personal single-user mode the Administrator role receives every
 * capability; the capability layer exists so multi-user roles can be
 * introduced later without touching call sites throughout the plugin.
 */
class PTP_Permissions {

	/**
	 * All capabilities introduced by this plugin.
	 *
	 * @return string[]
	 */
	public static function get_capabilities() {
		return array(
			'ptp_manage_projects',
			'ptp_manage_tasks',
			'ptp_manage_finance',
			'ptp_manage_settings',
			'ptp_manage_data',
		);
	}

	/**
	 * Grant all plugin capabilities to the Administrator role.
	 *
	 * Called on activation. Safe to call repeatedly — add_cap() is idempotent.
	 */
	public static function add_capabilities() {
		$role = get_role( 'administrator' );

		if ( ! $role ) {
			return;
		}

		foreach ( self::get_capabilities() as $cap ) {
			$role->add_cap( $cap );
		}
	}

	/**
	 * Remove all plugin capabilities from the Administrator role.
	 *
	 * Intentionally not called on deactivation (deactivation must not alter
	 * user data or permissions); reserved for an explicit uninstall cleanup.
	 */
	public static function remove_capabilities() {
		$role = get_role( 'administrator' );

		if ( ! $role ) {
			return;
		}

		foreach ( self::get_capabilities() as $cap ) {
			$role->remove_cap( $cap );
		}
	}

	/**
	 * Check whether the current user holds a given plugin capability.
	 *
	 * @param string $capability One of the capabilities from get_capabilities().
	 * @return bool
	 */
	public static function current_user_can( $capability ) {
		return current_user_can( $capability );
	}

	/**
	 * Whether the current user may access the plugin admin area at all.
	 *
	 * Used to gate the top-level menu and as a baseline REST/AJAX check.
	 *
	 * @return bool
	 */
	public static function can_access_plugin() {
		foreach ( self::get_capabilities() as $cap ) {
			if ( current_user_can( $cap ) ) {
				return true;
			}
		}

		return false;
	}
}
