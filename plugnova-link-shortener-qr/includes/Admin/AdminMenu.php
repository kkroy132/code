<?php
/**
 * Registers the wp-admin menu, pages, and admin-only assets/AJAX handlers.
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Admin;

use QuickLinkQRPro\Helpers\AssetVersion;
use QuickLinkQRPro\Security\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AdminMenu
 */
final class AdminMenu {

	/**
	 * Register admin hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_pages' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_gd_missing_notice' ) );
		add_filter( 'admin_footer_text', array( $this, 'maybe_show_version_footer' ) );

		( new AjaxHandler() )->register();
	}

	/**
	 * Show "Plugnova Link Shortener & QR vX.X.X" in the wp-admin footer, but only on this plugin's own pages.
	 * Makes it trivial to confirm exactly which version is actually running on a given site —
	 * useful when troubleshooting whether an update actually took effect.
	 *
	 * @param string $text Default footer text.
	 * @return string
	 */
	public function maybe_show_version_footer( string $text ): string {
		$screen = get_current_screen();
		if ( ! $screen || ! str_contains( (string) $screen->id, 'plugnova-link-shortener-qr' ) ) {
			return $text;
		}

		$build = AssetVersion::build_stamp();

		return 'Plugnova Link Shortener & QR v' . esc_html( QLQR_VERSION )
			. ( '' === $build ? '' : ' &middot; build ' . esc_html( $build ) );
	}

	/**
	 * Warn on this plugin's own admin pages if the GD extension isn't available, since PNG QR
	 * codes silently fall back to SVG in that case (still fully functional, just a different format).
	 */
	public function maybe_show_gd_missing_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! str_contains( (string) $screen->id, 'plugnova-link-shortener-qr' ) ) {
			return;
		}

		if ( extension_loaded( 'gd' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'The PHP GD extension is not enabled on this server, so Plugnova Link Shortener & QR is generating QR codes as SVG instead of PNG. Both formats scan fine; if you specifically need PNG downloads, ask your host to enable the GD extension.', 'plugnova-link-shortener-qr' )
		);
	}

	/**
	 * Register the single top-level menu page. All sections (Dashboard, Links, Bio Links,
	 * Analytics, Settings) live on this one page as horizontal tabs switched client-side without
	 * a reload (see Admin\TabsPage), so no submenu pages are registered anymore.
	 *
	 * Note: TabsPage's constructor (which runs right here, during admin_menu) instantiates
	 * SettingsPage, whose own constructor attaches the Settings API registration to admin_init —
	 * admin_menu fires before admin_init, so that wiring keeps working on every admin request,
	 * including the options.php save request.
	 */
	public function add_menu_pages(): void {
		add_menu_page(
			__( 'Plugnova Link Shortener & QR', 'plugnova-link-shortener-qr' ),
			__( 'Plugnova Link Shortener & QR', 'plugnova-link-shortener-qr' ),
			Capabilities::MANAGE_LINKS,
			'plugnova-link-shortener-qr',
			array( new TabsPage(), 'render' ),
			'dashicons-admin-links',
			26
		);
	}

	/**
	 * Enqueue admin CSS/JS only on this plugin's own admin pages.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function maybe_enqueue_assets( string $hook_suffix ): void {
		if ( ! str_contains( $hook_suffix, 'plugnova-link-shortener-qr' ) ) {
			return;
		}

		wp_enqueue_style( 'qlqr-admin', QLQR_PLUGIN_URL . 'assets/css/admin.css', array(), AssetVersion::for_file( 'assets/css/admin.css' ) );

		// The WP Media Library uploader/picker, used by the Create/Edit Link modal's QR logo field.
		wp_enqueue_media();

		wp_enqueue_script(
			'chart-js',
			QLQR_PLUGIN_URL . 'assets/js/vendor/chart.umd.min.js',
			array(),
			'4.5.1',
			true
		);

		wp_enqueue_script(
			'qlqr-admin',
			QLQR_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery', 'jquery-ui-sortable', 'chart-js', 'wp-i18n' ),
			AssetVersion::for_file( 'assets/js/admin.js' ),
			true
		);

		// Required for the __() calls in admin.js to resolve: without this WordPress never loads the
		// script's translation file, so wp-i18n would just echo the English source strings back.
		wp_set_script_translations( 'qlqr-admin', 'plugnova-link-shortener-qr', QLQR_PLUGIN_DIR . 'languages' );

		wp_localize_script(
			'qlqr-admin',
			'QLQR',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'qlqr_admin_nonce' ),
				'restUrl'    => esc_url_raw( rest_url( 'qlqr/v1' ) ),
				'restNonce'  => wp_create_nonce( 'wp_rest' ),
				// Lets admin.js disable the analytics day-range <select>'s 30/90-day options for a
				// free-plan account without a round trip — the server still enforces the same cap
				// regardless of what the client sends (see Helpers\AnalyticsDays).
				'isPro'      => qlqr_fs()->can_use_premium_code(),
				'i18n'       => array(
					'confirmDelete'  => __( 'Move this link to Trash?', 'plugnova-link-shortener-qr' ),
					'confirmPermDel' => __( 'Permanently delete this link? This cannot be undone.', 'plugnova-link-shortener-qr' ),
					'copied'         => __( 'Copied to clipboard!', 'plugnova-link-shortener-qr' ),
					'genericError'   => __( 'Something went wrong. Please try again.', 'plugnova-link-shortener-qr' ),
				),
			)
		);
	}
}
