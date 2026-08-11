<?php
/**
 * Core singleton: wires every module together. Steps 5+ add their own
 * bootstrap classes to the seodoc_core_modules filter below rather than
 * this file growing bespoke instantiation logic per feature.
 *
 * @package SEODoc
 */

namespace SEODoc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {

	private static $instance = null;

	/** @var array<string, object> */
	private $modules = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Runs on plugins_loaded. Order matters: the schema upgrade must run
	 * before any module that queries the DB, and seodoc_register_modules
	 * must fire before anything that consumes the Module_Registry.
	 */
	public function boot() {
		$this->load_textdomain();

		DB\Schema::maybe_upgrade();

		$this->modules = $this->boot_core_modules();

		/**
		 * Fires after Core's own modules are booted. Free's own
		 * self-registering checks (Step 6) and the Pro add-on both hook
		 * here to call seodoc_register_check() / _scanner_stage() /
		 * _admin_page() before anything reads the registry.
		 */
		do_action( 'seodoc_register_modules' );

		do_action( 'seodoc_loaded', $this );
	}

	/**
	 * @return array<string, object>
	 */
	private function boot_core_modules() {
		$classes = apply_filters(
			'seodoc_core_modules',
			array(
				Compat\Plugin_Detector::class,
				Scanner\Action_Scheduler_Init::class,
				Scanner\Batch_Processor::class,
				Checks\Check_Registry::class,
			)
		);

		$instances = array();
		foreach ( $classes as $class ) {
			if ( class_exists( $class ) ) {
				$instances[ $class ] = new $class();
			}
		}
		return $instances;
	}

	private function load_textdomain() {
		load_plugin_textdomain(
			'wp-seo-doctor',
			false,
			dirname( plugin_basename( SEODOC_PLUGIN_FILE ) ) . '/languages'
		);
	}

	/**
	 * @param string $class
	 * @return object|null
	 */
	public function get_module( $class ) {
		return isset( $this->modules[ $class ] ) ? $this->modules[ $class ] : null;
	}
}
