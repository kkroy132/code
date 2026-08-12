<?php
/**
 * Detects Yoast SEO / Rank Math / AIOSEO so Checks (Step 6) can defer to
 * their canonical/title/meta output instead of computing a competing
 * value, and so the admin UI can explain it's running in complementary
 * mode (Step 1 §10).
 *
 * @package SEODoc
 */

namespace SEODoc\Compat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin_Detector {

	const YOAST    = 'yoast';
	const RANKMATH = 'rankmath';
	const AIOSEO   = 'aioseo';

	/** @var string[] */
	private $active = array();

	public function __construct() {
		// Other plugins' main files (and their top-level constants) have
		// already loaded by the time any plugin's own code runs, so
		// detection is safe to run immediately rather than deferring to
		// a later hook.
		$this->detect();

		add_action( 'admin_notices', array( $this, 'maybe_show_complementary_notice' ) );
	}

	private function detect() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			$this->active[] = self::YOAST;
		}

		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$this->active[] = self::RANKMATH;
		}

		if ( defined( 'AIOSEO_VERSION' ) || class_exists( 'AIOSEO\\Plugin\\AIOSEO', false ) ) {
			$this->active[] = self::AIOSEO;
		}
	}

	public function has_active_seo_plugin() {
		return ! empty( $this->active );
	}

	/**
	 * @return string[]
	 */
	public function get_active_plugins() {
		return $this->active;
	}

	/**
	 * Shown once, only on WP SEO Doctor's own admin screens — never
	 * site-wide — and actually dismissible: marked is-dismissible, with
	 * an inline script persisting the dismissal via REST
	 * (Notices_Controller) so it doesn't reappear on the next page load.
	 * WP core's own dismiss-button behavior only hides the notice in the
	 * DOM for that one page view; without this it would have silently
	 * come back forever despite looking dismissible — the exact failure
	 * mode the Step 17 compliance audit was checking for.
	 */
	public function maybe_show_complementary_notice() {
		if ( ! $this->has_active_seo_plugin() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( $screen->id, 'seodoc' ) ) {
			return;
		}

		if ( get_option( 'seodoc_dismissed_compat_notice' ) ) {
			return;
		}

		echo '<div class="notice notice-info is-dismissible" data-seodoc-notice="compat"><p>' . esc_html__(
			'WP SEO Doctor detected another SEO plugin on this site and is running in complementary mode: it will not duplicate title/meta/canonical management, and focuses on audit, links, monitoring and diagnostics instead.',
			'wp-seo-doctor'
		) . '</p></div>';

		// Attached to the already-enqueued admin bundle (Admin\Assets),
		// so it only ever runs after wp-api-fetch has loaded, on the
		// same pages this notice can appear on.
		wp_add_inline_script(
			'seodoc-admin',
			"document.addEventListener('click',function(e){" .
			"var b=e.target.closest('[data-seodoc-notice=\"compat\"] .notice-dismiss');" .
			"if(b&&window.wp&&wp.apiFetch){wp.apiFetch({path:'/seodoc/v1/notices/dismiss-compat',method:'POST'});}" .
			'});',
			'after'
		);
	}
}
