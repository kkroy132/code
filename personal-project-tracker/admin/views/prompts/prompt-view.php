<?php
/**
 * AI Prompt Studio: prompt detail page.
 *
 * @package Personal_Project_Tracker
 *
 * @var object|null $prompt Prompt document row, or null when not found.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! $prompt ) :
	?>
	<h1><?php esc_html_e( 'Prompt Not Found', 'personal-project-tracker' ); ?></h1>
	<div class="ptp-card">
		<p><?php esc_html_e( 'That prompt does not exist or may have been deleted.', 'personal-project-tracker' ); ?></p>
		<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-ai-prompts' ), admin_url( 'admin.php' ) ) ); ?>">
			<?php esc_html_e( '&laquo; Back to AI Prompt Studio', 'personal-project-tracker' ); ?>
		</a>
	</div>
	<?php
	return;
endif;

$ptp_project       = $prompt->project_id ? PTP_Projects_Repository::get( $prompt->project_id ) : null;
$ptp_list_url      = add_query_arg( array( 'page' => 'ptp-ai-prompts' ), admin_url( 'admin.php' ) );
$ptp_edit_url      = add_query_arg( array( 'page' => 'ptp-ai-prompts', 'action' => 'edit', 'id' => $prompt->id ), admin_url( 'admin.php' ) );
$ptp_context_types = PTP_Prompt_Documents_Repository::get_context_types();
$ptp_role_label    = PTP_Prompt_Generator::ROLES[ $prompt->role ] ?? $prompt->role;
$ptp_format_label  = PTP_Prompt_Generator::OUTPUT_FORMATS[ $prompt->output_format ] ?? $prompt->output_format;

$ptp_breadcrumbs = array(
	array( 'label' => __( 'Project Tracker', 'personal-project-tracker' ), 'url' => add_query_arg( array( 'page' => 'ptp-dashboard' ), admin_url( 'admin.php' ) ) ),
	array( 'label' => __( 'AI Prompt Studio', 'personal-project-tracker' ), 'url' => $ptp_list_url ),
	array( 'label' => $prompt->title ),
);
require PTP_PLUGIN_DIR . 'admin/views/partials/breadcrumbs.php';
?>
<div class="ptp-page-header">
	<h1>
		<?php if ( $prompt->favorite ) : ?>
			<span class="dashicons dashicons-star-filled" title="<?php esc_attr_e( 'Favorite', 'personal-project-tracker' ); ?>"></span>
		<?php endif; ?>
		<?php echo esc_html( $prompt->title ); ?>
	</h1>
	<div class="ptp-quick-actions">
		<a class="button" href="<?php echo esc_url( $ptp_list_url ); ?>"><?php esc_html_e( '&laquo; Back', 'personal-project-tracker' ); ?></a>
		<a class="button button-primary" href="<?php echo esc_url( $ptp_edit_url ); ?>"><?php esc_html_e( 'Edit', 'personal-project-tracker' ); ?></a>
		<button type="button" class="button" id="ptp-js-copy" data-id="<?php echo esc_attr( $prompt->id ); ?>"><?php esc_html_e( 'Copy', 'personal-project-tracker' ); ?></button>
		<button type="button" class="button" id="ptp-js-share" data-id="<?php echo esc_attr( $prompt->id ); ?>"><?php esc_html_e( 'Share', 'personal-project-tracker' ); ?></button>
		<button type="button" class="button ptp-js-prompt-duplicate" data-id="<?php echo esc_attr( $prompt->id ); ?>" data-redirect-view="1"><?php esc_html_e( 'Duplicate', 'personal-project-tracker' ); ?></button>
		<?php if ( $prompt->favorite ) : ?>
			<button type="button" class="button ptp-js-prompt-unfavorite" data-id="<?php echo esc_attr( $prompt->id ); ?>"><?php esc_html_e( 'Unfavorite', 'personal-project-tracker' ); ?></button>
		<?php else : ?>
			<button type="button" class="button ptp-js-prompt-favorite" data-id="<?php echo esc_attr( $prompt->id ); ?>"><?php esc_html_e( 'Favorite', 'personal-project-tracker' ); ?></button>
		<?php endif; ?>
		<button type="button" class="button button-link-delete ptp-js-prompt-delete" data-id="<?php echo esc_attr( $prompt->id ); ?>" data-redirect="<?php echo esc_url( $ptp_list_url ); ?>">
			<?php esc_html_e( 'Delete', 'personal-project-tracker' ); ?>
		</button>
	</div>
</div>

<div class="ptp-detail-grid">
	<div class="ptp-detail-main">

		<div class="ptp-card">
			<h2><?php esc_html_e( 'Prompt', 'personal-project-tracker' ); ?></h2>
			<textarea id="ptp-content" readonly="readonly" rows="18" class="ptp-prompt-readonly"><?php echo esc_textarea( $prompt->content ); ?></textarea>
		</div>

	</div>

	<div class="ptp-detail-side">
		<div class="ptp-card">
			<h2><?php esc_html_e( 'Details', 'personal-project-tracker' ); ?></h2>
			<table class="widefat striped ptp-status-table">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Context Type', 'personal-project-tracker' ); ?></th>
						<td><?php echo esc_html( $ptp_context_types[ $prompt->context_type ] ?? $prompt->context_type ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Project', 'personal-project-tracker' ); ?></th>
						<td>
							<?php if ( $ptp_project ) : ?>
								<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-projects', 'action' => 'view', 'id' => $ptp_project->id ), admin_url( 'admin.php' ) ) ); ?>">
									<?php echo esc_html( $ptp_project->title ); ?>
								</a>
							<?php else : ?>
								&#8212;
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'AI Role', 'personal-project-tracker' ); ?></th>
						<td><?php echo esc_html( $ptp_role_label ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Output Format', 'personal-project-tracker' ); ?></th>
						<td><?php echo esc_html( $ptp_format_label ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Goal', 'personal-project-tracker' ); ?></th>
						<td><?php echo esc_html( $prompt->goal ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Updated', 'personal-project-tracker' ); ?></th>
						<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $prompt->updated_at ) ); ?></td>
					</tr>
				</tbody>
			</table>
		</div>
	</div>
</div>
