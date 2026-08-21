<?php
/**
 * Admin screens.
 *
 * @package LWBLC
 */

namespace LWBLC\Admin;

use LWBLC\Ajax;
use LWBLC\Database;
use LWBLC\Plugin;
use LWBLC\Scheduler;

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
			__( 'Broken Links', 'lightweight-broken-link-checker' ),
			__( 'Broken Links', 'lightweight-broken-link-checker' ),
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
				'label'   => __( 'Links per page', 'lightweight-broken-link-checker' ),
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

		wp_localize_script(
			'lwblc-admin',
			'lwblcAdmin',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( Ajax::NONCE_ACTION ),
				'pollInterval' => 3000,
				'progress'     => Ajax::progress_payload(),
				'i18n'         => array(
					'scanning'  => __( 'Scanning…', 'lightweight-broken-link-checker' ),
					'scanNow'   => __( 'Scan Now', 'lightweight-broken-link-checker' ),
					'cancel'    => __( 'Cancel scan', 'lightweight-broken-link-checker' ),
					'confirm'   => __( 'Start a new scan of all published content?', 'lightweight-broken-link-checker' ),
					'error'     => __( 'Something went wrong. Please try again.', 'lightweight-broken-link-checker' ),
					'rechecked' => __( 'Link queued for rechecking.', 'lightweight-broken-link-checker' ),
				),
			)
		);
	}

	/**
	 * Renders the Broken Links page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( Plugin::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'lightweight-broken-link-checker' ) );
		}

		$table = self::get_list_table();
		$table->prepare_items();

		$progress  = Ajax::progress_payload();
		$scanning  = ! empty( $progress['running'] );
		$scheduler = Scheduler::is_available();

		?>
		<div class="wrap lwblc-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Broken Links', 'lightweight-broken-link-checker' ); ?></h1>
			<hr class="wp-header-end" />

			<?php if ( ! $scheduler ) : ?>
				<div class="notice notice-error lwblc-notice">
					<p><?php esc_html_e( 'Action Scheduler could not be loaded, so scanning and checking are disabled. Reinstall the plugin to restore the bundled library.', 'lightweight-broken-link-checker' ); ?></p>
				</div>
			<?php endif; ?>

			<div class="lwblc-summary" id="lwblc-summary">
				<h2><?php esc_html_e( 'Overview', 'lightweight-broken-link-checker' ); ?></h2>

				<div class="lwblc-summary-grid">
					<?php foreach ( self::summary_items( $progress ) as $item ) : ?>
						<div class="lwblc-summary-item">
							<strong id="<?php echo esc_attr( $item['id'] ); ?>"><?php echo esc_html( $item['value'] ); ?></strong>
							<span><?php echo esc_html( $item['label'] ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>

				<div class="lwblc-progress">
					<div class="lwblc-progress-bar" id="lwblc-progress-bar" style="width: <?php echo esc_attr( (string) (int) $progress['percent'] ); ?>%"></div>
				</div>
				<p class="lwblc-progress-text" id="lwblc-progress-text"><?php echo esc_html( $progress['text'] ); ?></p>

				<p class="lwblc-actions">
					<button
						type="button"
						class="button button-primary"
						id="lwblc-scan-button"
						<?php disabled( ! $scheduler ); ?>
					>
						<?php echo $scanning ? esc_html__( 'Scanning…', 'lightweight-broken-link-checker' ) : esc_html__( 'Scan Now', 'lightweight-broken-link-checker' ); ?>
					</button>
					<button
						type="button"
						class="button"
						id="lwblc-cancel-button"
						<?php echo $scanning ? '' : ' style="display:none"'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Both branches are literals. ?>
					>
						<?php esc_html_e( 'Cancel scan', 'lightweight-broken-link-checker' ); ?>
					</button>
					<span class="spinner" id="lwblc-spinner"></span>
				</p>
			</div>

			<?php $table->views(); ?>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<?php
				$status_filter = self::current_status_filter();

				if ( '' !== $status_filter ) {
					printf(
						'<input type="hidden" name="status" value="%s" />',
						esc_attr( $status_filter )
					);
				}

				$table->search_box( __( 'Search links', 'lightweight-broken-link-checker' ), 'lwblc-search' );
				$table->display();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Returns the validated status filter from the query string.
	 *
	 * @return string Empty string when no valid filter is active.
	 */
	public static function current_status_filter() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read only filter on an admin screen.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

		return in_array( $status, Database::statuses(), true ) ? $status : '';
	}

	/**
	 * Builds the numbers shown in the summary box.
	 *
	 * @param array<string,mixed> $progress Progress payload.
	 * @return array<int,array{id:string,label:string,value:string}>
	 */
	private static function summary_items( array $progress ) {
		$counts = isset( $progress['counts'] ) ? (array) $progress['counts'] : array();

		$items = array(
			array(
				'id'    => 'lwblc-count-total',
				'label' => __( 'Links tracked', 'lightweight-broken-link-checker' ),
				'value' => number_format_i18n( (int) $progress['total_links'] ),
			),
			array(
				'id'    => 'lwblc-count-broken',
				'label' => __( 'Broken', 'lightweight-broken-link-checker' ),
				'value' => number_format_i18n( (int) ( $counts[ Database::STATUS_BROKEN ] ?? 0 ) ),
			),
			array(
				'id'    => 'lwblc-count-redirect',
				'label' => __( 'Redirects', 'lightweight-broken-link-checker' ),
				'value' => number_format_i18n( (int) ( $counts[ Database::STATUS_REDIRECT ] ?? 0 ) ),
			),
			array(
				'id'    => 'lwblc-count-pending',
				'label' => __( 'Waiting to be checked', 'lightweight-broken-link-checker' ),
				'value' => number_format_i18n( (int) ( $counts[ Database::STATUS_PENDING ] ?? 0 ) ),
			),
			array(
				'id'    => 'lwblc-next-check',
				'label' => __( 'Next link check', 'lightweight-broken-link-checker' ),
				'value' => (string) $progress['next_check'],
			),
		);

		return $items;
	}
}
