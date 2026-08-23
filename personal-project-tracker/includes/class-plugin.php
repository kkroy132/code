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
 * admin menu) and boots each feature module. Modules own their own hooks,
 * admin-post handlers, and REST routes — this class only knows their
 * bootstrap class name, listed in load_modules().
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

		$this->load_modules();
	}

	/**
	 * Instantiate and register every feature module.
	 *
	 * Each entry is a module bootstrap class implementing register().
	 * Later phases append to this list; nothing else here changes.
	 */
	private function load_modules() {
		$modules = array(
			'PTP_Projects_Module',
			'PTP_Tasks_Module',
			'PTP_Milestones_Module',
			'PTP_Calendar_Module',
			'PTP_Time_Module',
			'PTP_Notes_Module',
			'PTP_Links_Module',
			'PTP_Files_Module',
			'PTP_Finance_Module',
			'PTP_Reports_Module',
			'PTP_Prompts_Module',
		);

		foreach ( $modules as $module_class ) {
			if ( class_exists( $module_class ) ) {
				( new $module_class() )->register();
			}
		}
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
