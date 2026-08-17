<?php
/**
 * Main plugin container.
 *
 * @package WP_Site_Toolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Loads dependencies and wires up the plugin.
 *
 * @since 1.0.0
 */
final class WPSTK_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var WPSTK_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Audit engine.
	 *
	 * @var WPSTK_Audit|null
	 */
	private $audit = null;

	/**
	 * Admin controller.
	 *
	 * @var WPSTK_Admin|null
	 */
	private $admin = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @return WPSTK_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {}

	/**
	 * Prevents cloning.
	 */
	private function __clone() {}

	/**
	 * Prevents unserialising.
	 *
	 * @throws Exception Always.
	 */
	public function __wakeup() {
		throw new Exception( 'WPSTK_Plugin cannot be unserialized.' );
	}

	/**
	 * Loads files and registers hooks.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		$this->load_files();

		add_action( 'plugins_loaded', array( 'WPSTK_Database', 'maybe_upgrade' ), 5 );

		WPSTK_Not_Found_Monitor::init();
		WPSTK_Redirects::init();
		WPSTK_Cron::init();
		WPSTK_Rest::init();

		if ( is_admin() ) {
			$this->admin = new WPSTK_Admin();
			$this->admin->init();

			WPSTK_Ajax::init();
			WPSTK_Export::init();
			WPSTK_Settings::init();
			WPSTK_Post_Insights::init();
		}

		/**
		 * Fires once Site Toolkit has loaded.
		 *
		 * @since 1.0.0
		 *
		 * @param WPSTK_Plugin $plugin Plugin instance.
		 */
		do_action( 'wpstk_loaded', $this );
	}

	/**
	 * Requires all plugin classes.
	 *
	 * @return void
	 */
	private function load_files() {
		$includes = array(
			'includes/class-wpstk-security.php',
			'includes/class-wpstk-database.php',
			'includes/class-wpstk-settings.php',
			'includes/class-wpstk-http.php',
			'includes/class-wpstk-html.php',
			'includes/class-wpstk-content.php',
			'includes/class-wpstk-check.php',
			'includes/class-wpstk-scan-store.php',
			'includes/class-wpstk-audit.php',
			'includes/class-wpstk-not-found-monitor.php',
			'includes/class-wpstk-redirects.php',
			'includes/class-wpstk-cron.php',
			'includes/class-wpstk-rest.php',
			'includes/class-wpstk-ajax.php',
			'includes/class-wpstk-export.php',
			'includes/class-wpstk-admin.php',
			'includes/class-wpstk-view.php',
			'includes/class-wpstk-post-insights.php',
			'includes/modules/class-wpstk-module.php',
			'includes/modules/class-wpstk-module-seo.php',
			'includes/modules/class-wpstk-module-links.php',
			'includes/modules/class-wpstk-module-images.php',
			'includes/modules/class-wpstk-module-performance.php',
			'includes/modules/class-wpstk-module-security.php',
			'includes/modules/class-wpstk-module-technical.php',
		);

		foreach ( $includes as $file ) {
			require_once WPSTK_DIR . $file;
		}
	}

	/**
	 * Returns the shared audit engine.
	 *
	 * @return WPSTK_Audit
	 */
	public function audit() {
		if ( null === $this->audit ) {
			$this->audit = new WPSTK_Audit();
		}

		return $this->audit;
	}

	/**
	 * Activation handler.
	 *
	 * @param bool $network_wide Whether the plugin was network activated.
	 *
	 * @return void
	 */
	public static function on_activate( $network_wide = false ) {
		require_once WPSTK_DIR . 'includes/class-wpstk-database.php';
		require_once WPSTK_DIR . 'includes/class-wpstk-settings.php';
		require_once WPSTK_DIR . 'includes/class-wpstk-cron.php';

		if ( is_multisite() && $network_wide ) {
			// Provision a bounded number of sites here so activation cannot time
			// out on a large network. Any remaining site installs itself the
			// first time the plugin loads on it, via WPSTK_Database::maybe_upgrade().
			$site_ids = get_sites(
				array(
					'fields'                 => 'ids',
					'number'                 => 200,
					'update_site_meta_cache' => false,
				)
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::install_site();
				restore_current_blog();
			}

			return;
		}

		self::install_site();
	}

	/**
	 * Installs tables, defaults and schedules for the current site.
	 *
	 * @return void
	 */
	public static function install_site() {
		WPSTK_Database::install();
		WPSTK_Settings::install_defaults();
		WPSTK_Cron::schedule_events();
	}

	/**
	 * Deactivation handler.
	 *
	 * @return void
	 */
	public static function on_deactivate() {
		require_once WPSTK_DIR . 'includes/class-wpstk-cron.php';

		WPSTK_Cron::clear_events();
	}
}
