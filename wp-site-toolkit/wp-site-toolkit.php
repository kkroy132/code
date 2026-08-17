<?php
/**
 * Plugin Name:       WP Site Toolkit – Free
 * Description:       A free all-in-one WordPress website health, SEO, link, image, technical and security audit toolkit.
 * Version:           1.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            WP Site Toolkit Contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-site-toolkit
 * Domain Path:       /languages
 *
 * WP Site Toolkit is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * WP Site Toolkit is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * @package WP_Site_Toolkit
 */

defined( 'ABSPATH' ) || exit;

define( 'WPSTK_VERSION', '1.1.0' );
define( 'WPSTK_DB_VERSION', '2' );
define( 'WPSTK_FILE', __FILE__ );
define( 'WPSTK_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPSTK_URL', plugin_dir_url( __FILE__ ) );
define( 'WPSTK_BASENAME', plugin_basename( __FILE__ ) );
define( 'WPSTK_SLUG', 'wp-site-toolkit' );

require_once WPSTK_DIR . 'includes/class-wpstk-plugin.php';

/**
 * Returns the main plugin instance.
 *
 * @since 1.0.0
 *
 * @return WPSTK_Plugin Plugin instance.
 */
function wpstk() {
	return WPSTK_Plugin::instance();
}

wpstk()->boot();

register_activation_hook( __FILE__, array( 'WPSTK_Plugin', 'on_activate' ) );
register_deactivation_hook( __FILE__, array( 'WPSTK_Plugin', 'on_deactivate' ) );
