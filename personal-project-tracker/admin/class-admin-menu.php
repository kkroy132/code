<?php
/**
 * Admin menu registration.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Admin_Menu
 *
 * Registers the single "Project Tracker" top-level menu and its submenus.
 * Page rendering itself lives in PTP_Admin_Pages so this class stays
 * focused on menu/asset wiring.
 */
class PTP_Admin_Menu {

	/**
	 * Menu slug for the Dashboard (also the top-level menu slug).
	 *
	 * @var string
	 */
	const MENU_SLUG = 'ptp-dashboard';

	/**
	 * Capability required to see the top-level menu.
	 *
	 * Individual submenu pages may additionally require a more specific
	 * capability (e.g. Finance requires ptp_manage_finance); the menu-level
	 * check just gates whether the user can enter the plugin at all.
	 *
	 * @var string
	 */
	const MENU_CAPABILITY = 'ptp_manage_data';

	/**
	 * Hook registration.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu_pages' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register the top-level menu and submenu pages.
	 */
	public function add_menu_pages() {
		$pages = new PTP_Admin_Pages();

		add_menu_page(
			esc_html__( 'Project Tracker', 'personal-project-tracker' ),
			esc_html__( 'Project Tracker', 'personal-project-tracker' ),
			self::MENU_CAPABILITY,
			self::MENU_SLUG,
			array( $pages, 'render_dashboard' ),
			'dashicons-clipboard',
			26
		);

		foreach ( $this->get_submenu_items() as $item ) {
			add_submenu_page(
				self::MENU_SLUG,
				esc_html( $item['title'] ),
				esc_html( $item['title'] ),
				$item['capability'],
				$item['slug'],
				array( $pages, $item['callback'] )
			);
		}

		// Rename the auto-added first submenu item (duplicate of the top-level) to "Dashboard".
		global $submenu;

		if ( isset( $submenu[ self::MENU_SLUG ] ) && isset( $submenu[ self::MENU_SLUG ][0] ) ) {
			$submenu[ self::MENU_SLUG ][0][0] = esc_html__( 'Dashboard', 'personal-project-tracker' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}

	/**
	 * Definition of every submenu page (excluding Dashboard, added separately).
	 *
	 * Each entry's callback wires it to its feature module's render method.
	 *
	 * @return array<int, array{slug: string, title: string, capability: string, callback: string}>
	 */
	private function get_submenu_items() {
		return array(
			array(
				'slug'       => 'ptp-projects',
				'title'      => __( 'Projects', 'personal-project-tracker' ),
				'capability' => 'ptp_manage_projects',
				'callback'   => 'render_projects',
			),
			array(
				'slug'       => 'ptp-tasks',
				'title'      => __( 'Tasks', 'personal-project-tracker' ),
				'capability' => 'ptp_manage_tasks',
				'callback'   => 'render_tasks',
			),
			array(
				'slug'       => 'ptp-milestones',
				'title'      => __( 'Milestones', 'personal-project-tracker' ),
				'capability' => 'ptp_manage_projects',
				'callback'   => 'render_milestones',
			),
			array(
				'slug'       => 'ptp-calendar',
				'title'      => __( 'Calendar', 'personal-project-tracker' ),
				'capability' => 'ptp_manage_data',
				'callback'   => 'render_calendar',
			),
			array(
				'slug'       => 'ptp-time-tracking',
				'title'      => __( 'Time Tracking', 'personal-project-tracker' ),
				'capability' => 'ptp_manage_tasks',
				'callback'   => 'render_time_tracking',
			),
			array(
				'slug'       => 'ptp-notes',
				'title'      => __( 'Notes', 'personal-project-tracker' ),
				'capability' => 'ptp_manage_data',
				'callback'   => 'render_notes',
			),
			array(
				'slug'       => 'ptp-files',
				'title'      => __( 'Files', 'personal-project-tracker' ),
				'capability' => 'ptp_manage_data',
				'callback'   => 'render_files',
			),
			array(
				'slug'       => 'ptp-links',
				'title'      => __( 'Links', 'personal-project-tracker' ),
				'capability' => 'ptp_manage_data',
				'callback'   => 'render_links',
			),
			array(
				'slug'       => 'ptp-finance',
				'title'      => __( 'Finance', 'personal-project-tracker' ),
				'capability' => 'ptp_manage_finance',
				'callback'   => 'render_finance',
			),
			array(
				'slug'       => 'ptp-reports',
				'title'      => __( 'Reports', 'personal-project-tracker' ),
				'capability' => 'ptp_manage_data',
				'callback'   => 'render_reports',
			),
			array(
				'slug'       => 'ptp-analytics',
				'title'      => __( 'Analytics', 'personal-project-tracker' ),
				'capability' => 'ptp_manage_data',
				'callback'   => 'render_analytics',
			),
			array(
				'slug'       => 'ptp-ai-prompts',
				'title'      => __( 'AI Prompt Studio', 'personal-project-tracker' ),
				'capability' => 'ptp_manage_data',
				'callback'   => 'render_ai_prompts',
			),
			array(
				'slug'       => 'ptp-notifications',
				'title'      => __( 'Notifications', 'personal-project-tracker' ),
				'capability' => 'ptp_manage_data',
				'callback'   => 'render_notifications',
			),
			array(
				'slug'       => 'ptp-search',
				'title'      => __( 'Search', 'personal-project-tracker' ),
				'capability' => 'ptp_manage_data',
				'callback'   => 'render_search',
			),
			array(
				'slug'       => 'ptp-settings',
				'title'      => __( 'Settings', 'personal-project-tracker' ),
				'capability' => 'ptp_manage_settings',
				'callback'   => 'render_settings',
			),
		);
	}

	/**
	 * Enqueue admin CSS/JS, only on the plugin's own screens.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( strpos( (string) $hook_suffix, self::MENU_SLUG ) === false && strpos( (string) $hook_suffix, 'ptp-' ) === false ) {
			return;
		}

		wp_enqueue_style(
			'ptp-admin',
			PTP_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			PTP_VERSION
		);

		wp_enqueue_script(
			'ptp-admin',
			PTP_PLUGIN_URL . 'assets/js/admin.js',
			array(),
			PTP_VERSION,
			true
		);

		wp_localize_script(
			'ptp-admin',
			'ptpAdmin',
			array(
				'restUrl' => esc_url_raw( rest_url( PTP_REST_API::NAMESPACE_NAME ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);
	}
}
