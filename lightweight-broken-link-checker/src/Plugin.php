<?php
/**
 * Plugin container.
 *
 * @package LWBLC
 */

namespace LWBLC;

use LWBLC\Admin\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin components together.
 */
class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Returns the shared instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor; use instance().
	 */
	private function __construct() {}

	/**
	 * Registers hooks for every component.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		Installer::maybe_upgrade();

		Scanner::init();
		Checker::init();

		if ( is_admin() ) {
			Admin::init();
			Ajax::init();
		}
	}

	/**
	 * Capability required to use the plugin screens and actions.
	 *
	 * @return string
	 */
	public static function capability() {
		/**
		 * Filters the capability required to manage broken links.
		 *
		 * @param string $capability Capability name.
		 */
		return (string) apply_filters( 'lwblc_capability', 'manage_options' );
	}

	/**
	 * Current site time as a MySQL datetime string (UTC).
	 *
	 * @return string
	 */
	public static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}
}
