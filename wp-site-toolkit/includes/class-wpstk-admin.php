<?php
/**
 * Admin menus, assets and screen rendering.
 *
 * @package WP_Site_Toolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the WP Site Toolkit admin area.
 *
 * @since 1.0.0
 */
class WPSTK_Admin {

	/**
	 * Parent menu slug.
	 */
	const MENU_SLUG = 'wp-site-toolkit';

	/**
	 * Hook suffixes of the plugin screens.
	 *
	 * @var string[]
	 */
	private $screens = array();

	/**
	 * Registers the admin hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . WPSTK_BASENAME, array( $this, 'plugin_action_links' ) );
	}

	/**
	 * Returns the admin pages this plugin provides.
	 *
	 * @return array[]
	 */
	public function get_pages() {
		$audit = wpstk()->audit();

		$pages = array(
			array(
				'slug'  => self::MENU_SLUG,
				'title' => __( 'Dashboard', 'wp-site-toolkit' ),
				'view'  => 'dashboard',
			),
			array(
				'slug'  => self::MENU_SLUG . '-audit',
				'title' => __( 'Full Audit', 'wp-site-toolkit' ),
				'view'  => 'audit',
			),
		);

		foreach ( $audit->get_modules() as $module_id => $module ) {
			$pages[] = array(
				'slug'   => self::MENU_SLUG . '-' . $module_id,
				'title'  => $module->get_label(),
				'view'   => 'module',
				'module' => $module_id,
			);
		}

		$pages[] = array(
			'slug'  => self::MENU_SLUG . '-reports',
			'title' => __( 'Reports', 'wp-site-toolkit' ),
			'view'  => 'reports',
		);

		$pages[] = array(
			'slug'  => self::MENU_SLUG . '-settings',
			'title' => __( 'Settings', 'wp-site-toolkit' ),
			'view'  => 'settings',
		);

		return $pages;
	}

	/**
	 * Registers the menu and submenus.
	 *
	 * @return void
	 */
	public function register_menu() {
		$capability = WPSTK_Security::capability( 'view' );

		$this->screens[] = add_menu_page(
			__( 'WP Site Toolkit', 'wp-site-toolkit' ),
			__( 'Site Toolkit', 'wp-site-toolkit' ),
			$capability,
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'dashicons-shield-alt',
			81
		);

		foreach ( $this->get_pages() as $page ) {
			$this->screens[] = add_submenu_page(
				self::MENU_SLUG,
				$page['title'],
				$page['title'],
				$capability,
				$page['slug'],
				array( $this, 'render_page' )
			);
		}
	}

	/**
	 * Adds a settings shortcut to the plugin list.
	 *
	 * @param array $links Existing links.
	 *
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		if ( ! WPSTK_Security::can( 'view' ) ) {
			return $links;
		}

		$dashboard = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ),
			esc_html__( 'Dashboard', 'wp-site-toolkit' )
		);

		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-settings' ) ),
			esc_html__( 'Settings', 'wp-site-toolkit' )
		);

		array_unshift( $links, $dashboard, $settings );

		return $links;
	}

	/**
	 * Whether the given admin screen belongs to this plugin.
	 *
	 * @param string $hook_suffix Current screen hook.
	 *
	 * @return bool
	 */
	private function is_plugin_screen( $hook_suffix ) {
		return in_array( $hook_suffix, $this->screens, true );
	}

	/**
	 * Loads the stylesheet and script on plugin screens only.
	 *
	 * @param string $hook_suffix Current screen hook.
	 *
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( ! $this->is_plugin_screen( $hook_suffix ) ) {
			return;
		}

		wp_enqueue_style(
			'wpstk-admin',
			WPSTK_URL . 'admin/css/wpstk-admin.css',
			array(),
			WPSTK_VERSION
		);

		wp_enqueue_script(
			'wpstk-admin',
			WPSTK_URL . 'admin/js/wpstk-admin.js',
			array(),
			WPSTK_VERSION,
			true
		);

		wp_localize_script(
			'wpstk-admin',
			'wpstkData',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wpstk_ajax' ),
				'canRun'  => WPSTK_Security::can( 'run' ),
				'i18n'    => array(
					'starting'    => __( 'Starting the audit…', 'wp-site-toolkit' ),
					'running'     => __( 'Running', 'wp-site-toolkit' ),
					'finished'    => __( 'Audit complete. Loading the report…', 'wp-site-toolkit' ),
					'cancelled'   => __( 'The audit was cancelled.', 'wp-site-toolkit' ),
					'failed'      => __( 'The audit stopped unexpectedly.', 'wp-site-toolkit' ),
					'confirm'     => __( 'Cancel the running audit?', 'wp-site-toolkit' ),
					'networkFail' => __( 'The connection to WordPress was lost. The audit can be resumed from this screen.', 'wp-site-toolkit' ),
					'step'        => __( 'Step %1$d of %2$d', 'wp-site-toolkit' ),
				),
			)
		);
	}

	/**
	 * Renders the current plugin screen.
	 *
	 * @return void
	 */
	public function render_page() {
		WPSTK_Security::require_cap( 'view' );

		$slug = WPSTK_Security::get_query_arg( 'page', self::MENU_SLUG );
		$page = null;

		foreach ( $this->get_pages() as $candidate ) {
			if ( $candidate['slug'] === $slug ) {
				$page = $candidate;

				break;
			}
		}

		if ( null === $page ) {
			$page = array(
				'slug'  => self::MENU_SLUG,
				'title' => __( 'Dashboard', 'wp-site-toolkit' ),
				'view'  => 'dashboard',
			);
		}

		if ( ! WPSTK_Database::tables_exist() ) {
			WPSTK_Database::install();
		}

		$latest = WPSTK_Scan_Store::get_latest();

		$view = array(
			'page'      => $page,
			'pages'     => $this->get_pages(),
			'latest'    => $latest,
			'audit'     => wpstk()->audit(),
			'running'   => wpstk()->audit()->status(),
			'settings'  => WPSTK_Settings::get_all(),
			'module_id' => isset( $page['module'] ) ? $page['module'] : '',
			'notice'    => $this->get_notice(),
		);

		echo '<div class="wrap wpstk-wrap">';

		$this->render_view( 'partials/header', $view );
		$this->render_view( $page['view'], $view );

		echo '</div>';
	}

	/**
	 * Resolves the notice requested through the query string.
	 *
	 * @return array|null
	 */
	private function get_notice() {
		$code = WPSTK_Security::get_query_arg( 'wpstk_notice' );

		if ( '' === $code ) {
			return null;
		}

		$messages = array(
			'scan-deleted'   => array( 'success', __( 'The scan was deleted.', 'wp-site-toolkit' ) ),
			'entry-deleted'  => array( 'success', __( 'The entry was removed from the 404 log.', 'wp-site-toolkit' ) ),
			'log-cleared'    => array( 'success', __( 'The 404 log was cleared.', 'wp-site-toolkit' ) ),
			'data-cleared'   => array( 'success', __( 'All stored audit data was removed.', 'wp-site-toolkit' ) ),
			'settings-reset' => array( 'success', __( 'Settings were restored to their defaults.', 'wp-site-toolkit' ) ),
		);

		if ( ! isset( $messages[ $code ] ) ) {
			return null;
		}

		return array(
			'type'    => $messages[ $code ][0],
			'message' => $messages[ $code ][1],
		);
	}

	/**
	 * Includes a view file.
	 *
	 * @param string $name View name, relative to admin/views.
	 * @param array  $view Data made available to the view as `$view`.
	 *
	 * @return void
	 */
	public function render_view( $name, $view ) {
		$file = WPSTK_DIR . 'admin/views/' . $name . '.php';

		if ( ! file_exists( $file ) ) {
			return;
		}

		include $file;
	}

	/**
	 * Returns the admin URL of a plugin page.
	 *
	 * @param string $slug Page slug.
	 * @param array  $args Extra query arguments.
	 *
	 * @return string
	 */
	public static function page_url( $slug, $args = array() ) {
		return add_query_arg(
			array_merge( array( 'page' => $slug ), $args ),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Returns the CSS modifier for a status.
	 *
	 * @param string $status Status key.
	 *
	 * @return string
	 */
	public static function status_class( $status ) {
		$known = array( 'critical', 'warning', 'recommendation', 'passed', 'skipped' );

		return in_array( $status, $known, true ) ? $status : 'skipped';
	}

	/**
	 * Returns the CSS modifier for a score.
	 *
	 * @param int|null $score Score.
	 *
	 * @return string
	 */
	public static function score_class( $score ) {
		$grade = WPSTK_Check::grade( $score );

		return $grade['key'];
	}
}
