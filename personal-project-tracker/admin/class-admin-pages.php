<?php
/**
 * Admin page render callbacks.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Admin_Pages
 *
 * One render_* method per submenu page, each capability-gated and each
 * delegating to a view file for markup. Feature modules replace the
 * placeholder views with real UI as their phase is implemented; the menu
 * wiring and capability checks here do not change.
 */
class PTP_Admin_Pages {

	/**
	 * Dashboard (top-level page).
	 */
	public function render_dashboard() {
		$this->render_view(
			'dashboard-page',
			'ptp_manage_data',
			array()
		);
	}

	public function render_projects() {
		PTP_Security::require_capability( 'ptp_manage_projects' );

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		switch ( $action ) {
			case 'new':
				$this->render_view(
					'projects/project-form',
					'ptp_manage_projects',
					array(
						'project' => null,
						'is_edit' => false,
						'flash'   => PTP_Projects_Controller::get_flash(),
					)
				);
				break;

			case 'edit':
				$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$this->render_view(
					'projects/project-form',
					'ptp_manage_projects',
					array(
						'project' => $id ? PTP_Projects_Repository::get( $id ) : null,
						'is_edit' => true,
						'flash'   => PTP_Projects_Controller::get_flash(),
					)
				);
				break;

			case 'view':
				$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$this->render_view(
					'projects/project-view',
					'ptp_manage_projects',
					array( 'project' => $id ? PTP_Projects_Repository::get( $id ) : null )
				);
				break;

			default:
				$this->render_view( 'projects/projects-list', 'ptp_manage_projects', array() );
				break;
		}
	}

	public function render_tasks() {
		PTP_Security::require_capability( 'ptp_manage_tasks' );

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		switch ( $action ) {
			case 'new':
				$this->render_view(
					'tasks/task-form',
					'ptp_manage_tasks',
					array(
						'task'    => null,
						'is_edit' => false,
						'flash'   => PTP_Tasks_Controller::get_flash(),
					)
				);
				break;

			case 'edit':
				$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$this->render_view(
					'tasks/task-form',
					'ptp_manage_tasks',
					array(
						'task'    => $id ? PTP_Tasks_Repository::get( $id ) : null,
						'is_edit' => true,
						'flash'   => PTP_Tasks_Controller::get_flash(),
					)
				);
				break;

			case 'view':
				$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$this->render_view(
					'tasks/task-view',
					'ptp_manage_tasks',
					array( 'task' => $id ? PTP_Tasks_Repository::get( $id ) : null )
				);
				break;

			default:
				$this->render_view( 'tasks/tasks-list', 'ptp_manage_tasks', array() );
				break;
		}
	}

	public function render_milestones() {
		$this->render_placeholder( __( 'Milestones', 'personal-project-tracker' ), 'ptp_manage_projects' );
	}

	public function render_calendar() {
		$this->render_placeholder( __( 'Calendar', 'personal-project-tracker' ), 'ptp_manage_data' );
	}

	public function render_time_tracking() {
		$this->render_placeholder( __( 'Time Tracking', 'personal-project-tracker' ), 'ptp_manage_tasks' );
	}

	public function render_notes() {
		$this->render_placeholder( __( 'Notes', 'personal-project-tracker' ), 'ptp_manage_data' );
	}

	public function render_files() {
		$this->render_placeholder( __( 'Files', 'personal-project-tracker' ), 'ptp_manage_data' );
	}

	public function render_links() {
		$this->render_placeholder( __( 'Links', 'personal-project-tracker' ), 'ptp_manage_data' );
	}

	public function render_finance() {
		$this->render_placeholder( __( 'Finance', 'personal-project-tracker' ), 'ptp_manage_finance' );
	}

	public function render_reports() {
		$this->render_placeholder( __( 'Reports', 'personal-project-tracker' ), 'ptp_manage_data' );
	}

	public function render_analytics() {
		$this->render_placeholder( __( 'Analytics', 'personal-project-tracker' ), 'ptp_manage_data' );
	}

	public function render_ai_prompts() {
		$this->render_placeholder( __( 'AI Prompt Studio', 'personal-project-tracker' ), 'ptp_manage_data' );
	}

	public function render_notifications() {
		$this->render_placeholder( __( 'Notifications', 'personal-project-tracker' ), 'ptp_manage_data' );
	}

	public function render_search() {
		$this->render_placeholder( __( 'Search', 'personal-project-tracker' ), 'ptp_manage_data' );
	}

	public function render_settings() {
		$this->render_placeholder( __( 'Settings', 'personal-project-tracker' ), 'ptp_manage_settings' );
	}

	/**
	 * Render a "module coming soon" placeholder page.
	 *
	 * @param string $title      Page title.
	 * @param string $capability Capability required to view it.
	 */
	private function render_placeholder( $title, $capability ) {
		$this->render_view(
			'placeholder-page',
			$capability,
			array( 'title' => $title )
		);
	}

	/**
	 * Capability-gate and render a view file with the shared header/footer.
	 *
	 * @param string               $view       View file name (no extension) under admin/views/.
	 * @param string               $capability Capability required to view this page.
	 * @param array<string, mixed> $vars       Variables to extract into the view's scope.
	 */
	private function render_view( $view, $capability, array $vars = array() ) {
		if ( ! current_user_can( $capability ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access this page.', 'personal-project-tracker' ),
				esc_html__( 'Permission denied', 'personal-project-tracker' ),
				array( 'response' => 403 )
			);
		}

		extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract

		require PTP_PLUGIN_DIR . 'admin/views/partials/header.php';
		require PTP_PLUGIN_DIR . 'admin/views/' . $view . '.php';
		require PTP_PLUGIN_DIR . 'admin/views/partials/footer.php';
	}
}
