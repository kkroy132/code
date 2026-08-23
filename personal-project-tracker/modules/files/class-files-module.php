<?php
/**
 * Files module bootstrap.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Files_Module
 *
 * Registers everything the Files module needs: the admin-post handler for
 * the Attach File form, its REST routes, its own admin script (plus
 * wp_enqueue_media() so the native WordPress Media Library picker is
 * available), and a "Files" section on the Project/Task/Milestone detail
 * pages with a quick "Attach File" button.
 */
class PTP_Files_Module {

	/**
	 * Admin pages the WordPress Media Library picker needs to be available on.
	 *
	 * @var string[]
	 */
	const MEDIA_PAGES = array( 'ptp-files', 'ptp-projects', 'ptp-tasks', 'ptp-milestones', 'ptp-notes' );

	/**
	 * Hook registration. Called from PTP_Plugin::load_modules().
	 */
	public function register() {
		add_action( 'admin_post_ptp_attach_file', array( 'PTP_Files_Controller', 'handle_attach' ) );
		add_action( 'ptp_register_rest_routes', array( 'PTP_Files_REST', 'register_routes' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'ptp_project_detail_sections', array( __CLASS__, 'render_project_files' ) );
		add_action( 'ptp_task_detail_sections', array( __CLASS__, 'render_task_files' ) );
		add_action( 'ptp_milestone_detail_sections', array( __CLASS__, 'render_milestone_files' ) );
	}

	/**
	 * Enqueue the WordPress Media Library picker + the Files screen's JS,
	 * on the Files page and everywhere a "Files" section/Attach button appears.
	 *
	 * @param string $hook_suffix Current admin page hook suffix (unused; we key off $_GET['page']).
	 */
	public static function enqueue_assets( $hook_suffix ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $page, self::MEDIA_PAGES, true ) ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_script(
			'ptp-files',
			PTP_PLUGIN_URL . 'modules/files/assets/files.js',
			array( 'ptp-admin', 'media-editor' ),
			PTP_VERSION,
			true
		);

		wp_localize_script(
			'ptp-files',
			'ptpFiles',
			array(
				'confirmDetach' => __( 'Detach this file? The file itself will remain in your Media Library.', 'personal-project-tracker' ),
				'loadingText'   => __( 'Working…', 'personal-project-tracker' ),
				'errorGeneric'  => __( 'Something went wrong. Please try again.', 'personal-project-tracker' ),
				'mediaTitle'    => __( 'Choose or Upload a File', 'personal-project-tracker' ),
				'mediaButton'   => __( 'Use This File', 'personal-project-tracker' ),
			)
		);
	}

	/**
	 * @param object $project Project being viewed.
	 */
	public static function render_project_files( $project ) {
		self::render_related_files( 'project_id', $project->id );
	}

	/**
	 * @param object $task Task being viewed.
	 */
	public static function render_task_files( $task ) {
		self::render_related_files( 'task_id', $task->id );
	}

	/**
	 * @param object $milestone Milestone being viewed.
	 */
	public static function render_milestone_files( $milestone ) {
		self::render_related_files( 'milestone_id', $milestone->id );
	}

	/**
	 * Render a compact "Files" card scoped to one relationship column, with
	 * a quick "Attach File" button that opens the Media Library picker and
	 * attaches immediately via REST (no page navigation required).
	 *
	 * @param string $fk_column One of 'project_id', 'task_id', 'milestone_id'.
	 * @param int    $fk_value  The entity's ID.
	 */
	private static function render_related_files( $fk_column, $fk_value ) {
		if ( ! current_user_can( 'ptp_manage_data' ) || ! class_exists( 'PTP_Files_Repository' ) ) {
			return;
		}

		$result = PTP_Files_Repository::get_list(
			array(
				$fk_column => $fk_value,
				'orderby'  => 'created_at',
				'order'    => 'DESC',
				'per_page' => 5,
			)
		);

		$list_url = add_query_arg(
			array(
				'page'     => 'ptp-files',
				$fk_column => $fk_value,
			),
			admin_url( 'admin.php' )
		);

		$data_attr = 'project_id' === $fk_column ? 'data-project' : ( 'task_id' === $fk_column ? 'data-task' : 'data-milestone' );
		?>
		<div class="ptp-card">
			<div class="ptp-page-header">
				<h2><?php esc_html_e( 'Files', 'personal-project-tracker' ); ?></h2>
				<?php if ( current_user_can( 'upload_files' ) ) : ?>
					<button type="button" class="button button-small ptp-js-quick-attach" <?php echo esc_attr( $data_attr ); ?>="<?php echo esc_attr( $fk_value ); ?>">
						<?php esc_html_e( '+ Attach File', 'personal-project-tracker' ); ?>
					</button>
				<?php endif; ?>
			</div>
			<?php if ( empty( $result['items'] ) ) : ?>
				<p class="description"><?php esc_html_e( 'No files attached yet.', 'personal-project-tracker' ); ?></p>
			<?php else : ?>
				<ul class="ptp-simple-list">
					<?php foreach ( $result['items'] as $ptp_file ) : ?>
						<li>
							<a href="<?php echo esc_url( (string) wp_get_attachment_url( $ptp_file->attachment_id ) ); ?>" target="_blank" rel="noopener noreferrer">
								<?php echo esc_html( $ptp_file->file_name ? $ptp_file->file_name : __( '(file)', 'personal-project-tracker' ) ); ?>
							</a>
							<span class="description"><?php echo esc_html( null !== $ptp_file->file_size ? size_format( (int) $ptp_file->file_size ) : '' ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( ! empty( $result['items'] ) ) : ?>
				<p><a href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'View all files &raquo;', 'personal-project-tracker' ); ?></a></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
