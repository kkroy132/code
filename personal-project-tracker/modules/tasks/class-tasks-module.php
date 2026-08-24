<?php
/**
 * Tasks module bootstrap.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Tasks_Module
 *
 * Registers everything the Tasks module needs: the admin-post handler for
 * the create/edit form, the Tasks + Subtasks REST routes, the module's own
 * admin script, and a "Tasks" section on the Project detail page via the
 * ptp_project_detail_sections hook Projects already exposes — added there
 * without modifying a single Projects file.
 */
class PTP_Tasks_Module {

	/**
	 * Hook registration. Called from PTP_Plugin::load_modules().
	 */
	public function register() {
		add_action( 'admin_post_ptp_save_task', array( 'PTP_Tasks_Controller', 'handle_save' ) );
		add_action( 'ptp_register_rest_routes', array( 'PTP_Tasks_REST', 'register_routes' ) );
		add_action( 'ptp_register_rest_routes', array( 'PTP_Subtasks_REST', 'register_routes' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'ptp_project_detail_sections', array( __CLASS__, 'render_project_tasks_section' ) );
	}

	/**
	 * Enqueue the Tasks screen's JS (form helpers, quick actions, subtasks).
	 *
	 * @param string $hook_suffix Current admin page hook suffix (unused; we key off $_GET['page']).
	 */
	public static function enqueue_assets( $hook_suffix ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Also needed on the Projects screen: the Tasks section rendered
		// there (render_project_tasks_section()) includes the same
		// Complete/Reopen quick-action buttons the Tasks list uses.
		if ( ! in_array( $page, array( 'ptp-tasks', 'ptp-projects' ), true ) ) {
			return;
		}

		wp_enqueue_script(
			'ptp-tasks',
			PTP_PLUGIN_URL . 'modules/tasks/assets/tasks.js',
			array( 'ptp-admin' ),
			PTP_VERSION,
			true
		);

		wp_localize_script(
			'ptp-tasks',
			'ptpTasks',
			array(
				'confirmDelete'  => __( 'Delete this task permanently? This cannot be undone.', 'personal-project-tracker' ),
				'confirmArchive' => __( 'Archive this task?', 'personal-project-tracker' ),
				'confirmSubtaskDelete' => __( 'Delete this subtask?', 'personal-project-tracker' ),
				'loadingText'    => __( 'Working…', 'personal-project-tracker' ),
				'errorGeneric'   => __( 'Something went wrong. Please try again.', 'personal-project-tracker' ),
				'newSubtaskPlaceholder' => __( 'Add a subtask…', 'personal-project-tracker' ),
			)
		);
	}

	/**
	 * Render a compact "Tasks" card on the Project detail page, listing
	 * this project's open tasks with a Complete/Reopen quick action per
	 * row (the same .ptp-js-task-complete/-reopen buttons and REST calls
	 * the Tasks list uses — see tasks.js). Full management (edit, dates,
	 * subtasks, etc.) still stays on the Tasks screen.
	 *
	 * @param object $project Project being viewed.
	 */
	public static function render_project_tasks_section( $project ) {
		if ( ! current_user_can( 'ptp_manage_tasks' ) || ! class_exists( 'PTP_Tasks_Repository' ) ) {
			return;
		}

		$result   = PTP_Tasks_Repository::get_list(
			array(
				'project_id' => $project->id,
				'view'       => 'active',
				'orderby'    => 'due_date',
				'order'      => 'ASC',
				'per_page'   => 5,
			)
		);
		$statuses = PTP_Tasks_Repository::get_statuses();
		$new_url  = add_query_arg(
			array(
				'page'       => 'ptp-tasks',
				'action'     => 'new',
				'project_id' => $project->id,
			),
			admin_url( 'admin.php' )
		);
		$list_url = add_query_arg(
			array(
				'page'       => 'ptp-tasks',
				'project_id' => $project->id,
			),
			admin_url( 'admin.php' )
		);
		?>
		<div class="ptp-card">
			<div class="ptp-page-header">
				<h2><?php esc_html_e( 'Tasks', 'personal-project-tracker' ); ?></h2>
				<a class="button button-small" href="<?php echo esc_url( $new_url ); ?>"><?php esc_html_e( '+ New Task', 'personal-project-tracker' ); ?></a>
			</div>
			<?php if ( empty( $result['items'] ) ) : ?>
				<p class="description"><?php esc_html_e( 'No tasks yet for this project.', 'personal-project-tracker' ); ?></p>
			<?php else : ?>
				<ul class="ptp-simple-list">
					<?php foreach ( $result['items'] as $ptp_task ) : ?>
						<?php $ptp_task_done = 'completed' === $ptp_task->status; ?>
						<li>
							<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-tasks', 'action' => 'view', 'id' => $ptp_task->id ), admin_url( 'admin.php' ) ) ); ?>">
								<?php echo esc_html( $ptp_task->title ); ?>
							</a>
							<span class="ptp-simple-list-actions">
								<span class="ptp-badge ptp-badge-status-<?php echo esc_attr( $ptp_task->status ); ?>"><?php echo esc_html( $statuses[ $ptp_task->status ] ?? $ptp_task->status ); ?></span>
								<?php if ( $ptp_task_done ) : ?>
									<button type="button" class="button button-small ptp-js-task-reopen" data-id="<?php echo esc_attr( $ptp_task->id ); ?>">
										<?php esc_html_e( 'Reopen', 'personal-project-tracker' ); ?>
									</button>
								<?php else : ?>
									<button type="button" class="button button-small ptp-js-task-complete" data-id="<?php echo esc_attr( $ptp_task->id ); ?>">
										<?php esc_html_e( 'Complete', 'personal-project-tracker' ); ?>
									</button>
								<?php endif; ?>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( $result['total'] > count( $result['items'] ) || ! empty( $result['items'] ) ) : ?>
				<p><a href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'View all tasks for this project &raquo;', 'personal-project-tracker' ); ?></a></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
