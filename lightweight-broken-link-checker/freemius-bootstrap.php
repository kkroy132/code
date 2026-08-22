<?php
/**
 * Freemius bootstrap placeholder.
 *
 * The Freemius SDK is intentionally NOT bundled yet. This file isolates every
 * Freemius touch point behind a single function, so dropping the SDK into
 * `vendor/freemius/` and filling in the IDs below is the only change needed
 * later. No other plugin file may call Freemius directly — always go through
 * `lwblc_fs()` and always null-check the return value.
 *
 * The SDK itself is not namespaced, so it is kept out of the LWBLC namespace
 * and out of the PSR-4 autoloader path (`src/`) on purpose. Nothing here loads
 * unless the SDK file physically exists.
 *
 * @package LWBLC
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lwblc_fs' ) ) {

	/**
	 * Returns the Freemius instance for this plugin, or null when the SDK is absent.
	 *
	 * Usage: `$fs = lwblc_fs(); if ( $fs && $fs->is_paying() ) { ... }`
	 *
	 * @return \Freemius|null
	 */
	function lwblc_fs() {
		static $instance = null;
		static $resolved = false;

		if ( $resolved ) {
			return $instance;
		}

		$resolved = true;

		/**
		 * Filters the absolute path to the Freemius SDK entry file.
		 *
		 * @param string $path Path to `start.php`.
		 */
		$sdk_path = apply_filters( 'lwblc_freemius_sdk_path', LWBLC_DIR . 'vendor/freemius/start.php' );

		if ( ! is_readable( $sdk_path ) ) {
			return $instance;
		}

		require_once $sdk_path;

		if ( ! function_exists( 'fs_dynamic_init' ) ) {
			return $instance;
		}

		/*
		 * Replace `id`, `public_key` and `menu.slug` with the real values from
		 * the Freemius dashboard when the SDK is added. `menu.slug` must match
		 * the admin page slug registered in LWBLC\Admin\Admin.
		 */
		$instance = fs_dynamic_init(
			array(
				'id'             => '0000',
				'slug'           => 'lightweight-broken-link-checker',
				'type'           => 'plugin',
				'public_key'     => 'pk_00000000000000000000000000000',
				'is_premium'     => false,
				'has_addons'     => false,
				'has_paid_plans' => false,
				'menu'           => array(
					'slug'    => 'lwblc-links',
					'support' => false,
				),
			)
		);

		do_action( 'lwblc_freemius_loaded', $instance );

		return $instance;
	}
}
