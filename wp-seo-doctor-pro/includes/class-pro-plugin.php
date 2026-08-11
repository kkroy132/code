<?php
/**
 * Pro's own singleton, mirroring Free's SEODoc\Plugin pattern exactly —
 * same seodoc_pro_core_modules filter-extensible module list, same
 * "self-registering hook in each module's constructor" convention.
 *
 * @package SEODocPro
 */

namespace SEODocPro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pro_Plugin {

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

	public function boot() {
		$this->load_textdomain();

		$this->modules = $this->boot_core_modules();

		/**
		 * Fires once Pro's own modules are booted. This is where Pro's
		 * seodoc_register_check() / _scanner_stage() / _admin_page() /
		 * _rest_controllers-filter calls belong, so they run after
		 * Free's own seodoc_register_modules has already fired (Free's
		 * Plugin::boot() completed at plugins_loaded priority 10, this
		 * file only runs at priority 20 — see wp-seo-doctor-pro.php).
		 */
		do_action( 'seodoc_pro_loaded', $this );
	}

	/**
	 * @return array<string, object>
	 */
	private function boot_core_modules() {
		$classes = apply_filters(
			'seodoc_pro_core_modules',
			array(
				Feature_Gates::class,
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
			'wp-seo-doctor-pro',
			false,
			dirname( plugin_basename( SEODOC_PRO_PLUGIN_FILE ) ) . '/languages'
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
