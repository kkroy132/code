<?php
/**
 * Time Tracking module bootstrap.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Time_Module
 *
 * Registers everything the Time Tracking module needs: the admin-post
 * handler for the manual entry form, its REST routes, its own admin script,
 * a "Time Tracking" section on the Project detail page (via the existing
 * ptp_project_detail_sections hook), and a "Time Tracking" section on the
 * Task detail page via a new ptp_task_detail_sections hook — added to
 * task-view.php the same additive way Projects already exposed its own
 * hook in Phase 2, so future modules can attach to Task detail too without
 * editing that file again.
 */
class PTP_Time_Module {

	/**
	 * Hook registration. Called from PTP_Plugin::load_modules().
	 */
	public function register() {
		add_action( 'admin_post_ptp_save_time_entry', array( 'PTP_Time_Controller', 'handle_save' ) );
		add_action( 'ptp_register_rest_routes', array( 'PTP_Time_REST', 'register_routes' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'ptp_project_detail_sections', array( __CLASS__, 'render_project_time_section' ) );
		add_action( 'ptp_task_detail_sections', array( __CLASS__, 'render_task_time_section' ) );
	}

	/**
	 * Enqueue the Time Tracking screen's JS (Active Timer widget, manual
	 * entry duration auto-calc, quick actions), plus the small "Start Timer"
	 * button script wherever it appears on the Task detail page.
	 *
	 * @param string $hook_suffix Current admin page hook suffix (unused; we key off $_GET['page']).
	 */
	public static function enqueue_assets( $hook_suffix ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $page, array( 'ptp-time-tracking', 'ptp-tasks' ), true ) ) {
			return;
		}

		wp_enqueue_script(
			'ptp-time',
			PTP_PLUGIN_URL . 'modules/time/assets/time.js',
			array( 'ptp-admin' ),
			PTP_VERSION,
			true
		);

		wp_localize_script(
			'ptp-time',
			'ptpTime',
			array(
				'confirmDelete' => __( 'Delete this time entry? This cannot be undone.', 'personal-project-tracker' ),
				'loadingText'   => __( 'Working…', 'personal-project-tracker' ),
				'errorGeneric'  => __( 'Something went wrong. Please try again.', 'personal-project-tracker' ),
				'timeTrackingUrl' => add_query_arg( array( 'page' => 'ptp-time-tracking' ), admin_url( 'admin.php' ) ),
			)
		);
	}

	/**
	 * Render a compact "Time Tracking" card on the Project detail page:
	 * total tracked time plus a link to the filtered entry list. Read-only —
	 * starting/managing timers stays on the Time Tracking screen and the
	 * Task detail page.
	 *
	 * @param object $project Project being viewed.
	 */
	public static function render_project_time_section( $project ) {
		if ( ! current_user_can( 'ptp_manage_tasks' ) || ! class_exists( 'PTP_Time_Repository' ) ) {
			return;
		}

		$total    = PTP_Time_Repository::get_project_total( $project->id );
		$list_url = add_query_arg(
			array(
				'page'       => 'ptp-time-tracking',
				'project_id' => $project->id,
			),
			admin_url( 'admin.php' )
		);
		?>
		<div class="ptp-card">
			<h2><?php esc_html_e( 'Time Tracking', 'personal-project-tracker' ); ?></h2>
			<table class="widefat striped ptp-status-table">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Total Tracked Time', 'personal-project-tracker' ); ?></th>
						<td><?php echo esc_html( ptp_format_duration( $total ) ); ?></td>
					</tr>
				</tbody>
			</table>
			<p><a href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'View time entries for this project &raquo;', 'personal-project-tracker' ); ?></a></p>
		</div>
		<?php
	}

	/**
	 * Render a compact "Time Tracking" card on the Task detail page: total
	 * tracked time plus a "Start Timer" button (disabled with an explanatory
	 * note if the current user already has an active timer running elsewhere).
	 *
	 * @param object $task Task being viewed.
	 */
	public static function render_task_time_section( $task ) {
		if ( ! current_user_can( 'ptp_manage_tasks' ) || ! class_exists( 'PTP_Time_Repository' ) ) {
			return;
		}

		$total          = PTP_Time_Repository::get_task_total( $task->id );
		$active         = PTP_Time_Repository::get_active_for_user();
		$is_this_active = $active && (int) $active->task_id === (int) $task->id;
		$list_url       = add_query_arg(
			array(
				'page'    => 'ptp-time-tracking',
				'task_id' => $task->id,
			),
			admin_url( 'admin.php' )
		);
		?>
		<div class="ptp-card">
			<h2><?php esc_html_e( 'Time Tracking', 'personal-project-tracker' ); ?></h2>
			<table class="widefat striped ptp-status-table">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Tracked Time', 'personal-project-tracker' ); ?></th>
						<td><?php echo esc_html( ptp_format_duration( $total ) ); ?></td>
					</tr>
				</tbody>
			</table>
			<?php if ( $is_this_active ) : ?>
				<p class="description"><?php esc_html_e( 'A timer for this task is already running.', 'personal-project-tracker' ); ?></p>
				<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-time-tracking' ), admin_url( 'admin.php' ) ) ); ?>">
					<?php esc_html_e( 'View Active Timer', 'personal-project-tracker' ); ?>
				</a>
			<?php elseif ( $active ) : ?>
				<p class="description"><?php esc_html_e( 'You already have an active timer running on another item.', 'personal-project-tracker' ); ?></p>
				<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-time-tracking' ), admin_url( 'admin.php' ) ) ); ?>">
					<?php esc_html_e( 'View Active Timer', 'personal-project-tracker' ); ?>
				</a>
			<?php else : ?>
				<button type="button" class="button button-primary ptp-js-start-task-timer" data-task-id="<?php echo esc_attr( $task->id ); ?>" data-project-id="<?php echo esc_attr( $task->project_id ); ?>">
					<?php esc_html_e( 'Start Timer', 'personal-project-tracker' ); ?>
				</button>
			<?php endif; ?>
			<p><a href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'View time entries for this task &raquo;', 'personal-project-tracker' ); ?></a></p>
		</div>
		<?php
	}
}
