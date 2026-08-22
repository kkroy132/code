<?php
/**
 * Plugin Name:       Personal Project Tracker
 * Plugin URI:         https://example.com/personal-project-tracker
 * Description:       A private, all-in-one project management and personal work tracking system for WordPress: projects, tasks, milestones, calendar, time tracking, finance, reports, analytics, an AI Prompt Studio, notifications and reminders.
 * Version:            1.0.0
 * Requires at least:  6.0
 * Requires PHP:       8.1
 * Author:             Personal Project Tracker
 * License:            GPL v2 or later
 * License URI:        https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:        personal-project-tracker
 * Domain Path:        /languages
 *
 * @package Personal_Project_Tracker
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core plugin constants.
 */
define( 'PTP_VERSION', '1.0.0' );
define( 'PTP_DB_VERSION', 1 );
define( 'PTP_TEXT_DOMAIN', 'personal-project-tracker' );
define( 'PTP_PLUGIN_FILE', __FILE__ );
define( 'PTP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PTP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PTP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Minimum requirements check.
 *
 * We keep this defensive check dependency-free (no class autoloading yet)
 * because it must run before we know PHP 8.1 language features are safe to use.
 */
function ptp_meets_requirements() {
	global $wp_version;

	$php_ok = version_compare( PHP_VERSION, '8.1', '>=' );
	$wp_ok  = version_compare( $wp_version, '6.0', '>=' );

	return $php_ok && $wp_ok;
}

/**
 * Show an admin notice when requirements are not met and stop loading the plugin.
 */
function ptp_requirements_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			echo esc_html__(
				'Personal Project Tracker requires PHP 8.1+ and WordPress 6.0+. The plugin has been disabled until these requirements are met.',
				'personal-project-tracker'
			);
			?>
		</p>
	</div>
	<?php
}

if ( ! ptp_meets_requirements() ) {
	add_action( 'admin_notices', 'ptp_requirements_notice' );
	return;
}

require_once PTP_PLUGIN_DIR . 'includes/helpers.php';
require_once PTP_PLUGIN_DIR . 'includes/class-database.php';
require_once PTP_PLUGIN_DIR . 'includes/class-permissions.php';
require_once PTP_PLUGIN_DIR . 'includes/class-security.php';
require_once PTP_PLUGIN_DIR . 'includes/class-settings.php';
require_once PTP_PLUGIN_DIR . 'includes/class-activator.php';
require_once PTP_PLUGIN_DIR . 'includes/class-deactivator.php';
require_once PTP_PLUGIN_DIR . 'includes/class-rest-api.php';
require_once PTP_PLUGIN_DIR . 'includes/class-plugin.php';
require_once PTP_PLUGIN_DIR . 'admin/class-admin-menu.php';
require_once PTP_PLUGIN_DIR . 'admin/class-admin-pages.php';

register_activation_hook( __FILE__, array( 'PTP_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'PTP_Deactivator', 'deactivate' ) );

/**
 * Boot the plugin.
 */
function ptp_run_plugin() {
	$plugin = PTP_Plugin::get_instance();
	$plugin->run();
}
ptp_run_plugin();
