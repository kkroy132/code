<?php
/**
 * Admin-side form handling for Prompt Documents and Prompt Templates.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Prompts_Controller
 *
 * Handles the classic admin-post.php submissions for the prompt document
 * and prompt template forms, mirroring the Finance module's dual-entity
 * single-controller pattern. Save is a distinct action from Generate — the
 * form submits exactly the content the user last saw/edited on the page,
 * never re-running the generator, so a saved prompt is never silently
 * clobbered.
 */
class PTP_Prompts_Controller {

	/**
	 * Transient key prefixes used to flash validation errors + submitted
	 * values back to a form after a redirect.
	 *
	 * @var string
	 */
	const DOCUMENT_FLASH_KEY_PREFIX = 'ptp_prompt_form_flash_';
	const TEMPLATE_FLASH_KEY_PREFIX = 'ptp_prompt_template_form_flash_';

	/**
	 * Handle POST admin-post.php?action=ptp_save_prompt (create or update).
	 */
	public static function handle_save_prompt() {
		PTP_Security::check_admin_referer();
		PTP_Security::require_capability( 'ptp_manage_data' );

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		$raw = self::collect_prompt_raw();

		$result = $id ? PTP_Prompt_Documents_Repository::update( $id, $raw ) : PTP_Prompt_Documents_Repository::create( $raw );

		if ( is_wp_error( $result ) ) {
			self::set_flash( self::DOCUMENT_FLASH_KEY_PREFIX, $result->get_error_messages(), $raw );

			$redirect = $id
				? add_query_arg( array( 'page' => 'ptp-ai-prompts', 'action' => 'edit', 'id' => $id ), admin_url( 'admin.php' ) )
				: add_query_arg( array( 'page' => 'ptp-ai-prompts', 'action' => 'new' ), admin_url( 'admin.php' ) );

			wp_safe_redirect( $redirect );
			exit;
		}

		$new_id = $id ? $id : $result;

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'ptp-ai-prompts',
					'action'      => 'view',
					'id'          => $new_id,
					'ptp_success' => $id ? 'updated' : 'created',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Gather and lightly shape the raw $_POST fields for a prompt document
	 * submission, including the nested config sub-fields.
	 *
	 * @return array
	 */
	private static function collect_prompt_raw() {
		return array(
			'title'                => $_POST['title'] ?? '',
			'content'              => $_POST['content'] ?? '',
			'context_type'         => $_POST['context_type'] ?? 'custom',
			'project_id'           => $_POST['project_id'] ?? 0,
			'role'                 => $_POST['role'] ?? 'custom',
			'goal'                 => $_POST['goal'] ?? '',
			'output_format'        => $_POST['output_format'] ?? 'plain_text',
			'config'               => array(
				'task_ids'            => isset( $_POST['task_ids'] ) ? (array) $_POST['task_ids'] : array(),
				'milestone_ids'       => isset( $_POST['milestone_ids'] ) ? (array) $_POST['milestone_ids'] : array(),
				'include_time'        => ! empty( $_POST['include_time'] ),
				'include_notes'       => ! empty( $_POST['include_notes'] ),
				'include_links'       => ! empty( $_POST['include_links'] ),
				'include_files'       => ! empty( $_POST['include_files'] ),
				'include_finance'     => ! empty( $_POST['include_finance'] ),
				'include_reports'     => ! empty( $_POST['include_reports'] ),
				'include_activity'    => ! empty( $_POST['include_activity'] ),
				'requirements'        => $_POST['requirements'] ?? '',
				'constraints'         => $_POST['constraints'] ?? '',
				'custom_role_label'   => $_POST['custom_role_label'] ?? '',
				'custom_output_label' => $_POST['custom_output_label'] ?? '',
				'ai_tool'             => $_POST['ai_tool'] ?? 'generic',
			),
		);
	}

	/**
	 * Handle POST admin-post.php?action=ptp_save_prompt_template (create or update).
	 */
	public static function handle_save_template() {
		PTP_Security::check_admin_referer();
		PTP_Security::require_capability( 'ptp_manage_data' );

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		$raw = array(
			'name'                => $_POST['name'] ?? '',
			'category'            => $_POST['category'] ?? '',
			'role'                => $_POST['role'] ?? 'custom',
			'custom_role_label'   => $_POST['custom_role_label'] ?? '',
			'output_format'       => $_POST['output_format'] ?? 'plain_text',
			'custom_output_label' => $_POST['custom_output_label'] ?? '',
			'goal_placeholder'    => $_POST['goal_placeholder'] ?? '',
			'requirements'        => $_POST['requirements'] ?? '',
			'constraints'         => $_POST['constraints'] ?? '',
		);

		$result = $id ? PTP_Prompt_Templates_Repository::update( $id, $raw ) : PTP_Prompt_Templates_Repository::create( $raw );

		if ( is_wp_error( $result ) ) {
			self::set_flash( self::TEMPLATE_FLASH_KEY_PREFIX, $result->get_error_messages(), $raw );

			$redirect = $id
				? add_query_arg( array( 'page' => 'ptp-ai-prompts', 'action' => 'edit_template', 'id' => $id ), admin_url( 'admin.php' ) )
				: add_query_arg( array( 'page' => 'ptp-ai-prompts', 'action' => 'new_template' ), admin_url( 'admin.php' ) );

			wp_safe_redirect( $redirect );
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'ptp-ai-prompts',
					'action'      => 'templates',
					'ptp_success' => $id ? 'updated' : 'created',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * @param string   $prefix Flash transient key prefix (document or template).
	 * @param string[] $errors Error messages.
	 * @param array    $data   Raw submitted values.
	 */
	private static function set_flash( $prefix, array $errors, array $data ) {
		set_transient(
			$prefix . get_current_user_id(),
			array(
				'errors' => $errors,
				'data'   => $data,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Read and clear the current user's flashed prompt document form state, if any.
	 *
	 * @return array{errors: string[], data: array}|null
	 */
	public static function get_prompt_flash() {
		return self::read_flash( self::DOCUMENT_FLASH_KEY_PREFIX );
	}

	/**
	 * Read and clear the current user's flashed prompt template form state, if any.
	 *
	 * @return array{errors: string[], data: array}|null
	 */
	public static function get_template_flash() {
		return self::read_flash( self::TEMPLATE_FLASH_KEY_PREFIX );
	}

	/**
	 * @param string $prefix Flash transient key prefix.
	 * @return array{errors: string[], data: array}|null
	 */
	private static function read_flash( $prefix ) {
		$key   = $prefix . get_current_user_id();
		$flash = get_transient( $key );

		if ( $flash ) {
			delete_transient( $key );
		}

		return $flash ? $flash : null;
	}
}
