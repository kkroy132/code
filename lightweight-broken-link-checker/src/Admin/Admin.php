<?php
/**
 * Admin screens.
 *
 * @package LWBLC
 */

namespace LWBLC\Admin;

use LWBLC\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Broken Links admin page.
 */
class Admin {

	/**
	 * Admin page slug.
	 */
	const PAGE_SLUG = 'lwblc-links';

	/**
	 * Hook suffix of the registered page.
	 *
	 * @var string
	 */
	private static $hook_suffix = '';

	/**
	 * List table instance for the current request.
	 *
	 * @var Links_List_Table|null
	 */
	private static $list_table = null;

	/**
	 * Registers admin hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_filter( 'set-screen-option', array( __CLASS__, 'save_screen_option' ), 10, 3 );
	}

	/**
	 * Adds the top level menu page.
	 *
	 * @return void
	 */
	public static function register_menu() {
		self::$hook_suffix = add_menu_page(
			__( 'Broken Links', 'lwblc' ),
			__( 'Broken Links', 'lwblc' ),
			Plugin::capability(),
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-editor-unlink',
			76
		);

		if ( self::$hook_suffix ) {
			add_action( 'load-' . self::$hook_suffix, array( __CLASS__, 'on_load_page' ) );
		}
	}

	/**
	 * Returns the hook suffix of the plugin page.
	 *
	 * @return string
	 */
	public static function hook_suffix() {
		return self::$hook_suffix;
	}

	/**
	 * Prepares screen options and the list table before the page renders.
	 *
	 * @return void
	 */
	public static function on_load_page() {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Links per page', 'lwblc' ),
				'default' => 20,
				'option'  => 'lwblc_links_per_page',
			)
		);

		self::$list_table = self::get_list_table();
	}

	/**
	 * Builds (once) the list table instance.
	 *
	 * @return Links_List_Table
	 */
	public static function get_list_table() {
		if ( null === self::$list_table ) {
			if ( ! class_exists( 'WP_List_Table' ) ) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
			}

			self::$list_table = new Links_List_Table();
		}

		return self::$list_table;
	}

	/**
	 * Keeps the per page screen option.
	 *
	 * @param mixed  $status Screen option value, false by default.
	 * @param string $option Option name.
	 * @param mixed  $value  Submitted value.
	 * @return mixed
	 */
	public static function save_screen_option( $status, $option, $value ) {
		return 'lwblc_links_per_page' === $option ? (int) $value : $status;
	}

	/**
	 * Enqueues admin assets on the plugin page only.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( ! self::$hook_suffix || $hook_suffix !== self::$hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'lwblc-admin',
			LWBLC_URL . 'assets/css/admin.css',
			array(),
			LWBLC_VERSION
		);

		wp_enqueue_script(
			'lwblc-admin',
			LWBLC_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			LWBLC_VERSION,
			true
		);
	}

	/**
	 * Renders the Broken Links page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( Plugin::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'lwblc' ) );
		}

		$table = self::get_list_table();
		$table->prepare_items();

		?>
		<div class="wrap lwblc-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Broken Links', 'lwblc' ); ?></h1>
			<hr class="wp-header-end" />

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}
}
