<?php
/**
 * Plugin Name:       WP SEO Doctor
 * Plugin URI:        https://wpseodoctor.com
 * Description:       Find. Understand. Fix. Grow. Audits your WordPress site for technical SEO issues, broken links, orphan pages, indexing problems and internal-link opportunities — then helps you fix them from one dashboard. Works alongside Yoast SEO, Rank Math and AIOSEO.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            WP SEO Doctor
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-seo-doctor
 * Domain Path:       /languages
 *
 * @package SEODoc
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SEODOC_VERSION', '0.1.0' );
define( 'SEODOC_PLUGIN_FILE', __FILE__ );
define( 'SEODOC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SEODOC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SEODOC_MIN_PHP', '7.4' );
define( 'SEODOC_MIN_WP', '6.5' );

/**
 * Version guards run before anything else loads. A failed guard shows an
 * admin notice and stops here rather than fataling on an unsupported
 * environment.
 */
if ( version_compare( PHP_VERSION, SEODOC_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p>' . sprintf(
				/* translators: 1: required PHP version, 2: current PHP version. */
				esc_html__( 'WP SEO Doctor requires PHP %1$s or higher. Your site is running PHP %2$s, so the plugin has not been loaded.', 'wp-seo-doctor' ),
				esc_html( SEODOC_MIN_PHP ),
				esc_html( PHP_VERSION )
			) . '</p></div>';
		}
	);
	return;
}

if ( version_compare( $GLOBALS['wp_version'], SEODOC_MIN_WP, '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p>' . sprintf(
				/* translators: 1: required WordPress version, 2: current WordPress version. */
				esc_html__( 'WP SEO Doctor requires WordPress %1$s or higher. Your site is running %2$s, so the plugin has not been loaded.', 'wp-seo-doctor' ),
				esc_html( SEODOC_MIN_WP ),
				esc_html( $GLOBALS['wp_version'] )
			) . '</p></div>';
		}
	);
	return;
}

require_once SEODOC_PLUGIN_DIR . 'includes/class-autoloader.php';
\SEODoc\Autoloader::register();

require_once SEODOC_PLUGIN_DIR . 'includes/functions.php';

/**
 * Action Scheduler must be required directly, unconditionally, as early as
 * possible — this is the library's own documented loading pattern, not a
 * choice specific to this plugin. It self-deduplicates when multiple
 * active plugins (e.g. WooCommerce) bundle a copy, always running the
 * highest bundled version, so the class_exists() guard here is only to
 * avoid a fatal on a second `require` within this same plugin's load.
 */
if ( ! class_exists( 'ActionScheduler', false ) ) {
	$seodoc_action_scheduler_bootstrap = SEODOC_PLUGIN_DIR . 'vendor/action-scheduler/action-scheduler.php';
	if ( file_exists( $seodoc_action_scheduler_bootstrap ) ) {
		require_once $seodoc_action_scheduler_bootstrap;
	}
}

register_activation_hook( __FILE__, array( '\SEODoc\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\SEODoc\Deactivator', 'deactivate' ) );

add_action(
	'plugins_loaded',
	function () {
		seodoc()->boot();
	}
);
