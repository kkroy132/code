<?php
/**
 * Runs once on plugin activation.
 *
 * @package SEODoc
 */

namespace SEODoc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Activator {

	public static function activate() {
		DB\Schema::install();

		if ( ! wp_next_scheduled( 'seodoc_daily_maintenance' ) ) {
			wp_schedule_event( time(), 'daily', 'seodoc_daily_maintenance' );
		}

		set_transient( 'seodoc_activation_redirect', true, 30 );
	}
}
