<?php
/**
 * Plugin Name:       Lightweight Broken Link Checker
 * Description:       Finds broken links in your content using controlled, resource-friendly background batches instead of continuous scanning.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Lightweight Plugins
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lightweight-broken-link-checker
 * Domain Path:       /languages
 *
 * @package LWBLC
 */

defined( 'ABSPATH' ) || exit;

define( 'LWBLC_VERSION', '1.0.0' );
define( 'LWBLC_DB_VERSION', '1.0.0' );
define( 'LWBLC_FILE', __FILE__ );
define( 'LWBLC_DIR', plugin_dir_path( __FILE__ ) );
define( 'LWBLC_URL', plugin_dir_url( __FILE__ ) );
define( 'LWBLC_BASENAME', plugin_basename( __FILE__ ) );

require_once LWBLC_DIR . 'includes/autoloader.php';

/*
 * Action Scheduler registers itself on `plugins_loaded` at priority 0, so the
 * library has to be pulled in here, while this file is still being included.
 */
LWBLC\Scheduler::load_library();

/*
 * Freemius placeholder. Kept in a separate file so the SDK can be dropped in
 * later without touching plugin code. It fails silently when the SDK is absent.
 */
require_once LWBLC_DIR . 'freemius-bootstrap.php';

register_activation_hook( __FILE__, array( 'LWBLC\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'LWBLC\\Installer', 'deactivate' ) );

/**
 * Boots the plugin once all other plugins are loaded.
 *
 * @return void
 */
function lwblc_boot() {
	LWBLC\Plugin::instance()->boot();
}
add_action( 'plugins_loaded', 'lwblc_boot' );

/*
 * Translations are not registered explicitly: the text domain matches the
 * plugin slug, so WordPress loads them from `languages/` (and from
 * wp-content/languages/plugins/) on its own, only when a string is used.
 */
