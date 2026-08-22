<?php
/**
 * Milestones module bootstrap.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Milestones_Module
 *
 * Registers everything the Milestones module needs: the admin-post
 * handler for the create/edit form, its REST routes, its own admin
 * script, and a "Milestones" section on the Project detail page via the
 * ptp_project_detail_sections hook — added without modifying any
 * Projects file, the same way Tasks did in Phase 3.
 */
class PTP_Milestones_Module {

	/**
	 * Hook registration. Called from PTP_Plugin::load_modules().
	 */
	public function register() {
		add_action( 'admin_post_ptp_save_milestone', array( 'PTP_Milestones_Controller', 'handle_save' ) );
		add_action( 'ptp_register_rest_routes', array( 'PTP_Milestones_REST', 'register_routes' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'ptp_project_detail_sections', array( __CLASS__, 'render_project_milestones_section' ) );
	}

	/**
	 * Enqueue the Milestones screen's JS (quick actions + task relation).
	 *
	 * @param string $hook_suffix Current admin page hook suffix (unused; we key off $_GET['page']).
	 */
	public static function enqueue_assets( $hook_suffix ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! isset( $_GET['page'] ) || 'ptp-milestones' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		wp_enqueue_script(
			'ptp-milestones',
			PTP_PLUGIN_URL . 'modules/milestones/assets/milestones.js',
			array( 'ptp-admin' ),
			PTP_VERSION,
			true
		);

		wp_localize_script(
			'ptp-milestones',
			'ptpMilestones',
			array(
				'confirmDelete'  => __( 'Delete this milestone? Its tasks will be kept but unassigned from it. This cannot be undone.', 'personal-project-tracker' ),
				'confirmArchive' => __( 'Archive this milestone?', 'personal-project-tracker' ),
				'confirmRemoveTask' => __( 'Remove this task from the milestone? The task itself will not be deleted.', 'personal-project-tracker' ),
				'loadingText'    => __( 'Working…', 'personal-project-tracker' ),
				'errorGeneric'   => __( 'Something went wrong. Please try again.', 'personal-project-tracker' ),
			)
		);
	}

	/**
	 * Render a compact "Milestones" card on the Project detail page.
	 *
	 * @param object $project Project being viewed.
	 */
	public static function render_project_milestones_section( $project ) {
		if ( ! current_user_can( 'ptp_manage_projects' ) || ! class_exists( 'PTP_Milestones_Repository' ) ) {
			return;
		}

		$result   = PTP_Milestones_Repository::get_list(
			array(
				'project_id' => $project->id,
				'view'       => 'active',
				'orderby'    => 'due_date',
				'order'      => 'ASC',
				'per_page'   => 5,
			)
		);
		$statuses = PTP_Milestones_Repository::get_statuses();
		$new_url  = add_query_arg(
			array(
				'page'       => 'ptp-milestones',
				'action'     => 'new',
				'project_id' => $project->id,
			),
			admin_url( 'admin.php' )
		);
		$list_url = add_query_arg(
			array(
				'page'       => 'ptp-milestones',
				'project_id' => $project->id,
			),
			admin_url( 'admin.php' )
		);
		?>
		<div class="ptp-card">
			<div class="ptp-page-header">
				<h2><?php esc_html_e( 'Milestones', 'personal-project-tracker' ); ?></h2>
				<a class="button button-small" href="<?php echo esc_url( $new_url ); ?>"><?php esc_html_e( '+ New Milestone', 'personal-project-tracker' ); ?></a>
			</div>
			<?php if ( empty( $result['items'] ) ) : ?>
				<p class="description"><?php esc_html_e( 'No milestones yet for this project.', 'personal-project-tracker' ); ?></p>
			<?php else : ?>
				<ul class="ptp-simple-list">
					<?php foreach ( $result['items'] as $ptp_milestone ) : ?>
						<li>
							<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-milestones', 'action' => 'view', 'id' => $ptp_milestone->id ), admin_url( 'admin.php' ) ) ); ?>">
								<?php echo esc_html( $ptp_milestone->title ); ?>
							</a>
							<span class="ptp-badge ptp-badge-status-<?php echo esc_attr( $ptp_milestone->status ); ?>"><?php echo esc_html( $statuses[ $ptp_milestone->status ] ?? $ptp_milestone->status ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( ! empty( $result['items'] ) ) : ?>
				<p><a href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'View all milestones for this project &raquo;', 'personal-project-tracker' ); ?></a></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
