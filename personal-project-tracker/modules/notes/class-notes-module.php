<?php
/**
 * Notes module bootstrap.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Notes_Module
 *
 * Registers everything the Notes module needs: the admin-post handler for
 * the create/edit form, its REST routes, its own admin script, and a
 * "Notes" section on the Project/Task/Milestone detail pages via their
 * existing/added extensibility hooks — added without modifying those
 * modules' repository or controller files.
 */
class PTP_Notes_Module {

	/**
	 * Hook registration. Called from PTP_Plugin::load_modules().
	 */
	public function register() {
		add_action( 'admin_post_ptp_save_note', array( 'PTP_Notes_Controller', 'handle_save' ) );
		add_action( 'ptp_register_rest_routes', array( 'PTP_Notes_REST', 'register_routes' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'ptp_project_detail_sections', array( __CLASS__, 'render_project_notes' ) );
		add_action( 'ptp_task_detail_sections', array( __CLASS__, 'render_task_notes' ) );
		add_action( 'ptp_milestone_detail_sections', array( __CLASS__, 'render_milestone_notes' ) );
	}

	/**
	 * Enqueue the Notes screen's JS (pin/archive/restore/delete quick actions).
	 *
	 * @param string $hook_suffix Current admin page hook suffix (unused; we key off $_GET['page']).
	 */
	public static function enqueue_assets( $hook_suffix ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! isset( $_GET['page'] ) || 'ptp-notes' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		wp_enqueue_script(
			'ptp-notes',
			PTP_PLUGIN_URL . 'modules/notes/assets/notes.js',
			array( 'ptp-admin' ),
			PTP_VERSION,
			true
		);

		wp_localize_script(
			'ptp-notes',
			'ptpNotes',
			array(
				'confirmDelete' => __( 'Delete this note permanently? This cannot be undone.', 'personal-project-tracker' ),
				'loadingText'   => __( 'Working…', 'personal-project-tracker' ),
				'errorGeneric'  => __( 'Something went wrong. Please try again.', 'personal-project-tracker' ),
			)
		);
	}

	/**
	 * @param object $project Project being viewed.
	 */
	public static function render_project_notes( $project ) {
		self::render_related_notes( 'project_id', $project->id );
	}

	/**
	 * @param object $task Task being viewed.
	 */
	public static function render_task_notes( $task ) {
		self::render_related_notes( 'task_id', $task->id );
	}

	/**
	 * @param object $milestone Milestone being viewed.
	 */
	public static function render_milestone_notes( $milestone ) {
		self::render_related_notes( 'milestone_id', $milestone->id );
	}

	/**
	 * Render a compact "Notes" card scoped to one relationship column.
	 *
	 * @param string $fk_column One of 'project_id', 'task_id', 'milestone_id'.
	 * @param int    $fk_value  The entity's ID.
	 */
	private static function render_related_notes( $fk_column, $fk_value ) {
		if ( ! current_user_can( 'ptp_manage_data' ) || ! class_exists( 'PTP_Notes_Repository' ) ) {
			return;
		}

		$result = PTP_Notes_Repository::get_list(
			array(
				$fk_column => $fk_value,
				'view'     => 'active',
				'orderby'  => 'updated_at',
				'order'    => 'DESC',
				'per_page' => 5,
			)
		);

		$new_url = add_query_arg(
			array(
				'page'     => 'ptp-notes',
				'action'   => 'new',
				$fk_column => $fk_value,
			),
			admin_url( 'admin.php' )
		);
		$list_url = add_query_arg(
			array(
				'page'     => 'ptp-notes',
				$fk_column => $fk_value,
			),
			admin_url( 'admin.php' )
		);
		?>
		<div class="ptp-card">
			<div class="ptp-page-header">
				<h2><?php esc_html_e( 'Notes', 'personal-project-tracker' ); ?></h2>
				<a class="button button-small" href="<?php echo esc_url( $new_url ); ?>"><?php esc_html_e( '+ New Note', 'personal-project-tracker' ); ?></a>
			</div>
			<?php if ( empty( $result['items'] ) ) : ?>
				<p class="description"><?php esc_html_e( 'No notes yet.', 'personal-project-tracker' ); ?></p>
			<?php else : ?>
				<ul class="ptp-simple-list">
					<?php foreach ( $result['items'] as $ptp_note ) : ?>
						<li>
							<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-notes', 'action' => 'view', 'id' => $ptp_note->id ), admin_url( 'admin.php' ) ) ); ?>">
								<?php echo esc_html( $ptp_note->title ? $ptp_note->title : __( '(untitled)', 'personal-project-tracker' ) ); ?>
							</a>
							<?php if ( $ptp_note->pinned ) : ?>
								<span class="dashicons dashicons-sticky" title="<?php esc_attr_e( 'Pinned', 'personal-project-tracker' ); ?>"></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( ! empty( $result['items'] ) ) : ?>
				<p><a href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'View all notes &raquo;', 'personal-project-tracker' ); ?></a></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
