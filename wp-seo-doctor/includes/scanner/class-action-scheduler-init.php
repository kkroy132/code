<?php
/**
 * Reports whether the bundled Action Scheduler library (required directly
 * from the main plugin file — see wp-seo-doctor.php) actually loaded, and
 * surfaces a notice if it didn't. The Step 5 batch processor checks
 * is_available() before relying on `as_schedule_*` functions.
 *
 * @package SEODoc
 */

namespace SEODoc\Scanner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Action_Scheduler_Init {

	public static function is_available() {
		return function_exists( 'as_schedule_single_action' ) && class_exists( 'ActionScheduler', false );
	}

	public function __construct() {
		add_action( 'admin_notices', array( $this, 'maybe_show_missing_notice' ) );
	}

	public function maybe_show_missing_notice() {
		if ( self::is_available() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>' . esc_html__(
			'WP SEO Doctor: the background job library (Action Scheduler) did not load, so scheduled scans and batch processing are unavailable until this is resolved.',
			'wp-seo-doctor'
		) . '</p></div>';
	}
}
