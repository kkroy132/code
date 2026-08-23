<?php
/**
 * AI Prompt Studio module bootstrap.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Prompts_Module
 *
 * Registers everything AI Prompt Studio needs: admin-post handlers for the
 * prompt document and template forms, its REST routes, its own admin
 * script, and "Generate AI Prompt" quick-action cards on the Project and
 * Task detail pages via their existing extensibility hooks (Milestone's
 * quick action is a pre-existing placeholder edited in place instead, to
 * avoid a duplicate card there).
 */
class PTP_Prompts_Module {

	/**
	 * Hook registration. Called from PTP_Plugin::load_modules().
	 */
	public function register() {
		add_action( 'admin_post_ptp_save_prompt', array( 'PTP_Prompts_Controller', 'handle_save_prompt' ) );
		add_action( 'admin_post_ptp_save_prompt_template', array( 'PTP_Prompts_Controller', 'handle_save_template' ) );
		add_action( 'ptp_register_rest_routes', array( 'PTP_Prompts_REST', 'register_routes' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'ptp_project_detail_sections', array( __CLASS__, 'render_project_prompt_action' ) );
		add_action( 'ptp_task_detail_sections', array( __CLASS__, 'render_task_prompt_action' ) );
	}

	/**
	 * Enqueue the AI Prompt Studio screen's JS (Generate/Copy/Share/
	 * favorite/duplicate/delete/template-apply).
	 *
	 * @param string $hook_suffix Current admin page hook suffix (unused; we key off $_GET['page']).
	 */
	public static function enqueue_assets( $hook_suffix ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! isset( $_GET['page'] ) || 'ptp-ai-prompts' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		wp_enqueue_script(
			'ptp-prompts',
			PTP_PLUGIN_URL . 'modules/prompts/assets/prompts.js',
			array( 'ptp-admin' ),
			PTP_VERSION,
			true
		);

		wp_localize_script(
			'ptp-prompts',
			'ptpPrompts',
			array(
				'confirmDelete'  => __( 'Delete this prompt permanently? This cannot be undone.', 'personal-project-tracker' ),
				'loadingText'    => __( 'Working…', 'personal-project-tracker' ),
				'generatingText' => __( 'Generating…', 'personal-project-tracker' ),
				'errorGeneric'   => __( 'Something went wrong. Please try again.', 'personal-project-tracker' ),
				'copiedText'     => __( 'Copied to clipboard.', 'personal-project-tracker' ),
				'copyFailed'     => __( 'Could not copy automatically — please select and copy the text manually.', 'personal-project-tracker' ),
				'shareTitle'     => __( 'AI Prompt', 'personal-project-tracker' ),
			)
		);
	}

	/**
	 * @param object $project Project being viewed.
	 */
	public static function render_project_prompt_action( $project ) {
		self::render_quick_action_card(
			add_query_arg(
				array(
					'page'         => 'ptp-ai-prompts',
					'action'       => 'new',
					'context_type' => 'project',
					'project_id'   => $project->id,
				),
				admin_url( 'admin.php' )
			)
		);
	}

	/**
	 * @param object $task Task being viewed.
	 */
	public static function render_task_prompt_action( $task ) {
		self::render_quick_action_card(
			add_query_arg(
				array(
					'page'         => 'ptp-ai-prompts',
					'action'       => 'new',
					'context_type' => 'task',
					'project_id'   => $task->project_id,
					'task_ids'     => array( $task->id ),
				),
				admin_url( 'admin.php' )
			)
		);
	}

	/**
	 * Render a compact "AI Prompt Studio" card with a single "Generate AI
	 * Prompt" link, pre-filled via query args. Nothing is generated or
	 * saved here — the link only opens the prompt form with context
	 * preselected.
	 *
	 * @param string $new_prompt_url Pre-filled "new prompt" URL.
	 */
	private static function render_quick_action_card( $new_prompt_url ) {
		if ( ! current_user_can( 'ptp_manage_data' ) ) {
			return;
		}
		?>
		<div class="ptp-card">
			<h2><?php esc_html_e( 'AI Prompt Studio', 'personal-project-tracker' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Generate a structured prompt from this data for an external AI tool.', 'personal-project-tracker' ); ?></p>
			<a class="button" href="<?php echo esc_url( $new_prompt_url ); ?>">
				<?php esc_html_e( 'Generate AI Prompt', 'personal-project-tracker' ); ?>
			</a>
		</div>
		<?php
	}
}
