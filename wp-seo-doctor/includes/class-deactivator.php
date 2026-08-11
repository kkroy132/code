<?php
/**
 * Runs on plugin deactivation. Intentionally does not touch stored data —
 * that's an opt-in choice handled by uninstall.php, not deactivation.
 *
 * @package SEODoc
 */

namespace SEODoc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Deactivator {

	public static function deactivate() {
		wp_clear_scheduled_hook( 'seodoc_daily_maintenance' );
	}
}
