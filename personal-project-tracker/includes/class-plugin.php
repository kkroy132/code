<?php
/**
 * Core plugin orchestrator.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Plugin
 *
 * Wires up the plugin's cross-cutting concerns (i18n, DB upgrades, REST,
 * admin menu). Feature modules hook themselves in independently in later
 * phases; this class intentionally does not know about individual modules.
 */
class PTP_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var PTP_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return PTP_Plugin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor — use get_instance().
	 */
	private function __construct() {}

	/**
	 * Register all plugin hooks. Called once from the main plugin file.
	 */
	public function run() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'admin_init', array( 'PTP_Database', 'maybe_upgrade' ) );

		$rest_api = new PTP_REST_API();
		$rest_api->register();

		$admin_menu = new PTP_Admin_Menu();
		$admin_menu->register();
	}

	/**
	 * Load plugin translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'personal-project-tracker',
			false,
			dirname( PTP_PLUGIN_BASENAME ) . '/languages'
		);
	}
}
