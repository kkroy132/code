<?php
/**
 * Links module bootstrap.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Links_Module
 *
 * Registers everything the Links module needs: the admin-post handler for
 * the create/edit form, its REST routes, its own admin script, and a
 * "Links" section on the Project/Task/Milestone detail pages.
 */
class PTP_Links_Module {

	/**
	 * Hook registration. Called from PTP_Plugin::load_modules().
	 */
	public function register() {
		add_action( 'admin_post_ptp_save_link', array( 'PTP_Links_Controller', 'handle_save' ) );
		add_action( 'ptp_register_rest_routes', array( 'PTP_Links_REST', 'register_routes' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'ptp_project_detail_sections', array( __CLASS__, 'render_project_links' ) );
		add_action( 'ptp_task_detail_sections', array( __CLASS__, 'render_task_links' ) );
		add_action( 'ptp_milestone_detail_sections', array( __CLASS__, 'render_milestone_links' ) );
	}

	/**
	 * Enqueue the Links screen's JS (quick delete).
	 *
	 * @param string $hook_suffix Current admin page hook suffix (unused; we key off $_GET['page']).
	 */
	public static function enqueue_assets( $hook_suffix ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! isset( $_GET['page'] ) || 'ptp-links' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		wp_enqueue_script(
			'ptp-links',
			PTP_PLUGIN_URL . 'modules/links/assets/links.js',
			array( 'ptp-admin' ),
			PTP_VERSION,
			true
		);

		wp_localize_script(
			'ptp-links',
			'ptpLinks',
			array(
				'confirmDelete' => __( 'Delete this link? This cannot be undone.', 'personal-project-tracker' ),
				'loadingText'   => __( 'Working…', 'personal-project-tracker' ),
				'errorGeneric'  => __( 'Something went wrong. Please try again.', 'personal-project-tracker' ),
			)
		);
	}

	/**
	 * @param object $project Project being viewed.
	 */
	public static function render_project_links( $project ) {
		self::render_related_links( 'project_id', $project->id );
	}

	/**
	 * @param object $task Task being viewed.
	 */
	public static function render_task_links( $task ) {
		self::render_related_links( 'task_id', $task->id );
	}

	/**
	 * @param object $milestone Milestone being viewed.
	 */
	public static function render_milestone_links( $milestone ) {
		self::render_related_links( 'milestone_id', $milestone->id );
	}

	/**
	 * Render a compact "Links" card scoped to one relationship column.
	 *
	 * @param string $fk_column One of 'project_id', 'task_id', 'milestone_id'.
	 * @param int    $fk_value  The entity's ID.
	 */
	private static function render_related_links( $fk_column, $fk_value ) {
		if ( ! current_user_can( 'ptp_manage_data' ) || ! class_exists( 'PTP_Links_Repository' ) ) {
			return;
		}

		$result = PTP_Links_Repository::get_list(
			array(
				$fk_column => $fk_value,
				'orderby'  => 'created_at',
				'order'    => 'DESC',
				'per_page' => 5,
			)
		);

		$new_url = add_query_arg(
			array(
				'page'     => 'ptp-links',
				'action'   => 'new',
				$fk_column => $fk_value,
			),
			admin_url( 'admin.php' )
		);
		$list_url = add_query_arg(
			array(
				'page'     => 'ptp-links',
				$fk_column => $fk_value,
			),
			admin_url( 'admin.php' )
		);
		?>
		<div class="ptp-card">
			<div class="ptp-page-header">
				<h2><?php esc_html_e( 'Links', 'personal-project-tracker' ); ?></h2>
				<a class="button button-small" href="<?php echo esc_url( $new_url ); ?>"><?php esc_html_e( '+ New Link', 'personal-project-tracker' ); ?></a>
			</div>
			<?php if ( empty( $result['items'] ) ) : ?>
				<p class="description"><?php esc_html_e( 'No links yet.', 'personal-project-tracker' ); ?></p>
			<?php else : ?>
				<ul class="ptp-simple-list">
					<?php foreach ( $result['items'] as $ptp_link ) : ?>
						<li>
							<a href="<?php echo esc_url( $ptp_link->url ); ?>" target="_blank" rel="noopener noreferrer">
								<?php echo esc_html( $ptp_link->title ); ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( ! empty( $result['items'] ) ) : ?>
				<p><a href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'View all links &raquo;', 'personal-project-tracker' ); ?></a></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
