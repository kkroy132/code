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
		PTP_Security::require_capability( 'ptp_manage_projects' );

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		switch ( $action ) {
			case 'new':
				$this->render_view(
					'milestones/milestone-form',
					'ptp_manage_projects',
					array(
						'milestone' => null,
						'is_edit'   => false,
						'flash'     => PTP_Milestones_Controller::get_flash(),
					)
				);
				break;

			case 'edit':
				$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$this->render_view(
					'milestones/milestone-form',
					'ptp_manage_projects',
					array(
						'milestone' => $id ? PTP_Milestones_Repository::get( $id ) : null,
						'is_edit'   => true,
						'flash'     => PTP_Milestones_Controller::get_flash(),
					)
				);
				break;

			case 'view':
				$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$this->render_view(
					'milestones/milestone-view',
					'ptp_manage_projects',
					array( 'milestone' => $id ? PTP_Milestones_Repository::get( $id ) : null )
				);
				break;

			default:
				$this->render_view( 'milestones/milestones-list', 'ptp_manage_projects', array() );
				break;
		}
	}

	public function render_calendar() {
		PTP_Security::require_capability( 'ptp_manage_data' );

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		switch ( $action ) {
			case 'new':
				$this->render_view(
					'calendar/event-form',
					'ptp_manage_data',
					array(
						'event'   => null,
						'is_edit' => false,
						'flash'   => PTP_Calendar_Controller::get_flash(),
					)
				);
				break;

			case 'edit':
				$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$this->render_view(
					'calendar/event-form',
					'ptp_manage_data',
					array(
						'event'   => $id ? PTP_Calendar_Repository::get( $id ) : null,
						'is_edit' => true,
						'flash'   => PTP_Calendar_Controller::get_flash(),
					)
				);
				break;

			case 'view':
				$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$this->render_view(
					'calendar/event-view',
					'ptp_manage_data',
					array( 'event' => $id ? PTP_Calendar_Repository::get( $id ) : null )
				);
				break;

			default:
				$this->render_view( 'calendar/calendar-page', 'ptp_manage_data', array() );
				break;
		}
	}

	public function render_time_tracking() {
		PTP_Security::require_capability( 'ptp_manage_tasks' );

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		switch ( $action ) {
			case 'new':
				$this->render_view(
					'time/time-entry-form',
					'ptp_manage_tasks',
					array(
						'entry'   => null,
						'is_edit' => false,
						'flash'   => PTP_Time_Controller::get_flash(),
					)
				);
				break;

			case 'edit':
				$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$this->render_view(
					'time/time-entry-form',
					'ptp_manage_tasks',
					array(
						'entry'   => $id ? PTP_Time_Repository::get( $id ) : null,
						'is_edit' => true,
						'flash'   => PTP_Time_Controller::get_flash(),
					)
				);
				break;

			default:
				$this->render_view( 'time/time-page', 'ptp_manage_tasks', array() );
				break;
		}
	}

	public function render_notes() {
		PTP_Security::require_capability( 'ptp_manage_data' );

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		switch ( $action ) {
			case 'new':
				$this->render_view(
					'notes/note-form',
					'ptp_manage_data',
					array(
						'note'    => null,
						'is_edit' => false,
						'flash'   => PTP_Notes_Controller::get_flash(),
					)
				);
				break;

			case 'edit':
				$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$this->render_view(
					'notes/note-form',
					'ptp_manage_data',
					array(
						'note'    => $id ? PTP_Notes_Repository::get( $id ) : null,
						'is_edit' => true,
						'flash'   => PTP_Notes_Controller::get_flash(),
					)
				);
				break;

			case 'view':
				$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$this->render_view(
					'notes/note-view',
					'ptp_manage_data',
					array( 'note' => $id ? PTP_Notes_Repository::get( $id ) : null )
				);
				break;

			default:
				$this->render_view( 'notes/notes-list', 'ptp_manage_data', array() );
				break;
		}
	}

	public function render_files() {
		PTP_Security::require_capability( 'ptp_manage_data' );

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		switch ( $action ) {
			case 'new':
				$this->render_view(
					'files/file-attach',
					'ptp_manage_data',
					array( 'flash' => PTP_Files_Controller::get_flash() )
				);
				break;

			case 'view':
				$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$this->render_view(
					'files/file-view',
					'ptp_manage_data',
					array( 'file' => $id ? PTP_Files_Repository::get( $id ) : null )
				);
				break;

			default:
				$this->render_view( 'files/files-list', 'ptp_manage_data', array() );
				break;
		}
	}

	public function render_links() {
		PTP_Security::require_capability( 'ptp_manage_data' );

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		switch ( $action ) {
			case 'new':
				$this->render_view(
					'links/link-form',
					'ptp_manage_data',
					array(
						'link'    => null,
						'is_edit' => false,
						'flash'   => PTP_Links_Controller::get_flash(),
					)
				);
				break;

			case 'edit':
				$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$this->render_view(
					'links/link-form',
					'ptp_manage_data',
					array(
						'link'    => $id ? PTP_Links_Repository::get( $id ) : null,
						'is_edit' => true,
						'flash'   => PTP_Links_Controller::get_flash(),
					)
				);
				break;

			case 'view':
				$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$this->render_view(
					'links/link-view',
					'ptp_manage_data',
					array( 'link' => $id ? PTP_Links_Repository::get( $id ) : null )
				);
				break;

			default:
				$this->render_view( 'links/links-list', 'ptp_manage_data', array() );
				break;
		}
	}

	public function render_finance() {
		PTP_Security::require_capability( 'ptp_manage_finance' );

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		switch ( $action ) {
			case 'new_expense':
				$this->render_view(
					'finance/expense-form',
					'ptp_manage_finance',
					array(
						'expense' => null,
						'is_edit' => false,
						'flash'   => PTP_Finance_Controller::get_expense_flash(),
					)
				);
				break;

			case 'edit_expense':
				$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$this->render_view(
					'finance/expense-form',
					'ptp_manage_finance',
					array(
						'expense' => $id ? PTP_Expenses_Repository::get( $id ) : null,
						'is_edit' => true,
						'flash'   => PTP_Finance_Controller::get_expense_flash(),
					)
				);
				break;

			case 'view_expense':
				$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$this->render_view(
					'finance/expense-view',
					'ptp_manage_finance',
					array( 'expense' => $id ? PTP_Expenses_Repository::get( $id ) : null )
				);
				break;

			case 'new_revenue':
				$this->render_view(
					'finance/revenue-form',
					'ptp_manage_finance',
					array(
						'revenue' => null,
						'is_edit' => false,
						'flash'   => PTP_Finance_Controller::get_revenue_flash(),
					)
				);
				break;

			case 'edit_revenue':
				$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$this->render_view(
					'finance/revenue-form',
					'ptp_manage_finance',
					array(
						'revenue' => $id ? PTP_Revenue_Repository::get( $id ) : null,
						'is_edit' => true,
						'flash'   => PTP_Finance_Controller::get_revenue_flash(),
					)
				);
				break;

			case 'view_revenue':
				$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$this->render_view(
					'finance/revenue-view',
					'ptp_manage_finance',
					array( 'revenue' => $id ? PTP_Revenue_Repository::get( $id ) : null )
				);
				break;

			default:
				$this->render_view( 'finance/finance-page', 'ptp_manage_finance', array() );
				break;
		}
	}

	public function render_reports() {
		$this->render_view( 'reports/reports-page', 'ptp_manage_data', array() );
	}

	public function render_analytics() {
		$this->render_view( 'analytics/analytics-page', 'ptp_manage_data', array() );
	}

	public function render_ai_prompts() {
		PTP_Security::require_capability( 'ptp_manage_data' );

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		switch ( $action ) {
			case 'new':
				$this->render_view(
					'prompts/prompt-form',
					'ptp_manage_data',
					array(
						'prompt'  => null,
						'is_edit' => false,
						'flash'   => PTP_Prompts_Controller::get_prompt_flash(),
					)
				);
				break;

			case 'edit':
				$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$this->render_view(
					'prompts/prompt-form',
					'ptp_manage_data',
					array(
						'prompt'  => $id ? PTP_Prompt_Documents_Repository::get( $id ) : null,
						'is_edit' => true,
						'flash'   => PTP_Prompts_Controller::get_prompt_flash(),
					)
				);
				break;

			case 'view':
				$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$this->render_view(
					'prompts/prompt-view',
					'ptp_manage_data',
					array( 'prompt' => $id ? PTP_Prompt_Documents_Repository::get( $id ) : null )
				);
				break;

			case 'templates':
				$this->render_view( 'prompts/templates-list', 'ptp_manage_data', array() );
				break;

			case 'new_template':
				$this->render_view(
					'prompts/template-form',
					'ptp_manage_data',
					array(
						'template' => null,
						'is_edit'  => false,
						'flash'    => PTP_Prompts_Controller::get_template_flash(),
					)
				);
				break;

			case 'edit_template':
				$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$this->render_view(
					'prompts/template-form',
					'ptp_manage_data',
					array(
						'template' => $id ? PTP_Prompt_Templates_Repository::get( $id ) : null,
						'is_edit'  => true,
						'flash'    => PTP_Prompts_Controller::get_template_flash(),
					)
				);
				break;

			default:
				$this->render_view( 'prompts/prompts-list', 'ptp_manage_data', array() );
				break;
		}
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
