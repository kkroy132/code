<?php
/**
 * Plugin Name:       WP SEO Doctor Pro
 * Plugin URI:        https://wpseodoctor.com/pro
 * Description:       Unlocks unlimited internal-link suggestions, extra redirect types, Google Search Console insights, AI assistance, agency reporting and more on top of WP SEO Doctor.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  wp-seo-doctor
 * Author:            WP SEO Doctor
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-seo-doctor-pro
 * Domain Path:       /languages
 *
 * @package SEODocPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SEODOC_PRO_VERSION', '0.1.0' );
define( 'SEODOC_PRO_PLUGIN_FILE', __FILE__ );
define( 'SEODOC_PRO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SEODOC_PRO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * The `Requires Plugins` header above (WordPress 6.5+, which is also
 * Free's own minimum — see wp-seo-doctor/docs/08) already stops WP core
 * from activating this plugin without wp-seo-doctor active, with a
 * native admin UI explaining why. The function_exists() check below is
 * defense in depth, not the primary gate.
 *
 * Priority 20: Free's own plugins_loaded callback (default priority 10)
 * must have already run and finished Plugin::boot() — including firing
 * seodoc_register_modules and seodoc_loaded — before Pro touches
 * anything that reads Free's registries or calls seodoc_register_*()
 * itself. This isn't about function_exists('seodoc') timing (that
 * function is defined the moment Free's main file is included, before
 * any hook fires); it's about Free's boot sequence being complete.
 */
add_action(
	'plugins_loaded',
	function () {
		if ( ! function_exists( 'seodoc' ) ) {
			add_action( 'admin_notices', 'seodoc_pro_missing_free_notice' );
			return;
		}

		require_once SEODOC_PRO_PLUGIN_DIR . 'includes/class-autoloader.php';
		\SEODocPro\Autoloader::register();

		\SEODocPro\Pro_Plugin::instance()->boot();
	},
	20
);

function seodoc_pro_missing_free_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	echo '<div class="notice notice-error"><p>' . esc_html__(
		'WP SEO Doctor Pro requires the free WP SEO Doctor plugin to be installed and active.',
		'wp-seo-doctor-pro'
	) . '</p></div>';
}
