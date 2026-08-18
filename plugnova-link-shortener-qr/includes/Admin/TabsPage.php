<?php
/**
 * Renders the single unified admin page: a horizontal tab bar (Dashboard / Links / Bio Links /
 * Analytics / Activity Log / Settings) with every section rendered into its own panel. Tab
 * switching is pure client-side show/hide (see assets/js/admin.js) — no page reload.
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TabsPage
 */
final class TabsPage {

	/**
	 * Dashboard section renderer.
	 *
	 * @var DashboardPage
	 */
	private DashboardPage $dashboard_page;

	/**
	 * Links section renderer.
	 *
	 * @var LinksPage
	 */
	private LinksPage $links_page;

	/**
	 * Bio Links section renderer.
	 *
	 * @var BioLinksPage
	 */
	private BioLinksPage $bio_links_page;

	/**
	 * Analytics section renderer.
	 *
	 * @var AnalyticsPage
	 */
	private AnalyticsPage $analytics_page;

	/**
	 * Activity Log section renderer.
	 *
	 * @var ActivityLogPage
	 */
	private ActivityLogPage $activity_log_page;

	/**
	 * Settings section renderer.
	 *
	 * @var SettingsPage
	 */
	private SettingsPage $settings_page;

	/**
	 * Constructor. Runs during the admin_menu hook (when AdminMenu builds the page callback),
	 * which is early enough that SettingsPage's constructor can still attach its admin_init
	 * hooks — those must be registered on EVERY admin request (including options.php saves),
	 * not just when the Settings tab happens to be rendered.
	 */
	public function __construct() {
		$this->dashboard_page = new DashboardPage();
		$this->links_page     = new LinksPage();
		$this->bio_links_page = new BioLinksPage();
		$this->analytics_page    = new AnalyticsPage();
		$this->activity_log_page = new ActivityLogPage();
		$this->settings_page     = new SettingsPage();
	}

	/**
	 * Render the tab bar and all panels. Every panel's content is rendered up front (hidden via
	 * CSS) so switching tabs is instant and reload-free; each section's own render() keeps doing
	 * its data preparation and capability checks exactly as before.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_qlqr_links' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'plugnova-link-shortener-qr' ) );
		}

		$panels = array(
			'dashboard' => array(
				'label'    => __( 'Dashboard', 'plugnova-link-shortener-qr' ),
				'renderer' => array( $this->dashboard_page, 'render' ),
			),
			'links'     => array(
				'label'    => __( 'Links', 'plugnova-link-shortener-qr' ),
				'renderer' => array( $this->links_page, 'render' ),
			),
			'bio-links' => array(
				'label'    => __( 'Bio Links', 'plugnova-link-shortener-qr' ),
				'renderer' => array( $this->bio_links_page, 'render' ),
			),
			// Loaded on demand. Every panel below is rendered up front so switching tabs needs no
			// reload, but Analytics is the one panel whose render() runs real queries (seven of
			// them) and it is never the tab showing on arrival — so rendering it eagerly spent
			// those queries on every single admin page load, including Settings. The panel now
			// ships as an empty shell and fetches itself the first time its tab is opened.
			'analytics' => array(
				'label'       => __( 'Analytics', 'plugnova-link-shortener-qr' ),
				'renderer'    => array( $this->analytics_page, 'render' ),
				'lazy_action' => 'qlqr_analytics_panel',
			),
			'activity-log' => array(
				'label'    => __( 'Activity Log', 'plugnova-link-shortener-qr' ),
				'renderer' => array( $this->activity_log_page, 'render' ),
			),
		);

		// The Settings section requires manage_options (its render() wp_die()s otherwise), so for
		// editors the tab is omitted entirely rather than rendering a dead panel.
		if ( current_user_can( 'manage_options' ) ) {
			$panels['settings'] = array(
				'label'    => __( 'Settings', 'plugnova-link-shortener-qr' ),
				'renderer' => array( $this->settings_page, 'render' ),
			);
		}

		include QLQR_PLUGIN_DIR . 'templates/admin/tabs.php';
	}
}
