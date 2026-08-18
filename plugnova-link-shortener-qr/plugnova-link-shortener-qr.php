<?php
/**
 * Plugin Name:       Plugnova Link Shortener & QR
 * Description:       Professional URL Shortener with Automatic QR Code Generator, Click Analytics, REST API and Affiliate Marketing support.
 * Version:           1.0.0
 * Requires at least: 6.8
 * Requires PHP:      8.1
 * Author:            Plugnova
 * Author URI:        https://profiles.wordpress.org/plugnova
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       plugnova-link-shortener-qr
 * Domain Path:       /languages
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

require_once __DIR__ . '/freemius-bootstrap.php';

/**
 * Core plugin constants.
 */
define( 'QLQR_VERSION', '1.0.0' );
define( 'QLQR_PLUGIN_FILE', __FILE__ );
define( 'QLQR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'QLQR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'QLQR_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
// Bumped independently of the plugin version: Plugin::maybe_upgrade_database() re-runs the
// installer whenever this changes, which is how an existing site picks up new indexes.
define( 'QLQR_DB_VERSION', '1.0.2' );
define( 'QLQR_MIN_PHP', '8.1' );
define( 'QLQR_MIN_WP', '6.8' );

/**
 * PSR-4-style autoloader for the QuickLinkQRPro\ namespace.
 *
 * Maps QuickLinkQRPro\Admin\Dashboard  -> includes/Admin/Dashboard.php
 * Maps QuickLinkQRPro\Models\Link      -> includes/Models/Link.php
 */
spl_autoload_register(
	static function ( string $class ): void {
		$prefix = __NAMESPACE__ . '\\';

		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}

		$relative_class = substr( $class, strlen( $prefix ) );
		$relative_path  = str_replace( '\\', DIRECTORY_SEPARATOR, $relative_class ) . '.php';
		$file           = QLQR_PLUGIN_DIR . 'includes' . DIRECTORY_SEPARATOR . $relative_path;

		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

/**
 * Verify the runtime environment meets minimum requirements before booting.
 *
 * @return bool
 */
function qlqr_environment_is_compatible(): bool {
	if ( version_compare( PHP_VERSION, QLQR_MIN_PHP, '<' ) ) {
		return false;
	}

	global $wp_version;
	if ( isset( $wp_version ) && version_compare( $wp_version, QLQR_MIN_WP, '<' ) ) {
		return false;
	}

	return true;
}

/**
 * Show an admin notice if the environment is not compatible.
 */
function qlqr_environment_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: 1: required PHP version, 2: required WordPress version */
				__( 'Plugnova Link Shortener & QR requires PHP %1$s+ and WordPress %2$s+. The plugin has been deactivated.', 'plugnova-link-shortener-qr' ),
				QLQR_MIN_PHP,
				QLQR_MIN_WP
			)
		)
	);
}

if ( ! qlqr_environment_is_compatible() ) {
	add_action( 'admin_notices', __NAMESPACE__ . '\\qlqr_environment_notice' );

	add_action(
		'admin_init',
		static function (): void {
			if ( current_user_can( 'activate_plugins' ) ) {
				deactivate_plugins( QLQR_PLUGIN_BASENAME );
				if ( isset( $_GET['activate'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- mirrors core's own deactivation flow; the value is only unset, never read
					unset( $_GET['activate'] );
				}
			}
		}
	);

	return;
}

/**
 * Activation callback: install database tables and default options.
 */
function qlqr_activate(): void {
	Database\Installer::install();
	Helpers\Options::set_defaults();
	Security\Capabilities::add_roles();

	// Register the /{prefix}/{slug} and /{bio_prefix}/{slug} rewrite rules right now, in this same
	// request, before flushing. (Simply calling ->register() would only *schedule* the rules for
	// the next 'init' hook, which runs on the following request — by which time flush_rewrite_rules()
	// below would already have flushed an empty rule set, leaving links 404ing until a manual
	// Settings > Permalinks > Save.)
	( new Controllers\RedirectController() )->add_rewrite_rule();
	( new Controllers\BioController() )->add_rewrite_rule();

	flush_rewrite_rules();

	// Off by default (see Helpers\Options::DEFAULTS) — each run is an outbound request to every
	// link's own third-party destination server, so the cron only gets scheduled here if the admin
	// had already turned the setting on before a reactivation. Turning it on for the first time
	// schedules it via Admin\SettingsPage::reschedule_broken_link_check() instead.
	if ( get_option( 'qlqr_broken_link_check_enabled', 0 ) && ! wp_next_scheduled( Helpers\BrokenLinkChecker::CRON_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Helpers\BrokenLinkChecker::CRON_HOOK );
	}
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\\qlqr_activate' );

/**
 * Deactivation callback: flush rewrite rules and unschedule cron. Data is preserved.
 */
function qlqr_deactivate(): void {
	flush_rewrite_rules();
	wp_clear_scheduled_hook( Helpers\BrokenLinkChecker::CRON_HOOK );
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\qlqr_deactivate' );

/**
 * Uninstall cleanup: drop custom tables, delete options/capabilities/generated QR images, clear
 * rate-limiter transients and cron. Registered on Freemius's own 'after_uninstall' action (see
 * freemius-bootstrap.php) rather than register_uninstall_hook()/uninstall.php — Freemius's SDK
 * handles the uninstall confirmation flow itself and fires this while the plugin is still fully
 * loaded, unlike WP_UNINSTALL_PLUGIN's isolated context, so this relies on the normal autoloader
 * instead of manually requiring class files.
 *
 * Only removes data if the site owner explicitly opted in via Settings > Uninstall > "Delete all
 * data on uninstall".
 */
function qlqr_uninstall_cleanup(): void {
	if ( ! get_option( 'qlqr_delete_on_uninstall' ) ) {
		return;
	}

	// Drop custom database tables.
	Database\Installer::drop_tables();

	// Remove all plugin options. Options::keys() only covers the settings exposed on the Settings
	// screen, so the two internal bookkeeping options are named explicitly alongside it — otherwise
	// they would survive an uninstall and confuse a later reinstall (a stale
	// qlqr_rewrite_flushed_version in particular would suppress the rewrite-rule flush the fresh
	// install needs, leaving every short link 404ing).
	foreach ( Helpers\Options::keys() as $key ) {
		delete_option( $key );
	}
	delete_option( 'qlqr_db_version' );
	delete_option( 'qlqr_rewrite_flushed_version' );
	delete_option( 'qlqr_default_redirect_migrated' );

	// Drop the plugin's custom capability from every role that has it. add_roles() granted it at
	// activation; leaving it behind would keep a meaningless capability on the site forever.
	$roles = wp_roles();
	foreach ( array_keys( $roles->roles ) as $role_name ) {
		$role = get_role( $role_name );
		if ( $role instanceof \WP_Role && $role->has_cap( 'manage_qlqr_links' ) ) {
			$role->remove_cap( 'manage_qlqr_links' );
		}
	}

	// Remove generated QR code images.
	$upload_dir = wp_upload_dir();
	$qr_dir     = trailingslashit( $upload_dir['basedir'] ) . 'plugnova-link-shortener-qr';

	if ( is_dir( $qr_dir ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();

		global $wp_filesystem;
		if ( $wp_filesystem ) {
			$wp_filesystem->delete( $qr_dir, true );
		}
	}

	// Clear any transients created by the rate limiter (best-effort; they also expire naturally).
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_qlqr_rl_%' OR option_name LIKE '_transient_timeout_qlqr_rl_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders

	// Unschedule the broken-link-check cron event.
	wp_clear_scheduled_hook( Helpers\BrokenLinkChecker::CRON_HOOK );
}

if ( function_exists( 'qlqr_fs' ) ) {
	qlqr_fs()->add_action( 'after_uninstall', __NAMESPACE__ . '\\qlqr_uninstall_cleanup' );
}

/**
 * Boot the plugin once all plugins are loaded.
 *
 * No load_plugin_textdomain() call: since WordPress 4.6 translations for plugins hosted on
 * WordPress.org are loaded automatically under the plugin slug, and since WordPress 6.7 calling it
 * this early (on plugins_loaded, before 'init') triggers a "translation loading was triggered too
 * early" notice. Letting core handle it avoids both.
 */
function qlqr_boot(): void {
	Plugin::instance()->run();
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\qlqr_boot' );
