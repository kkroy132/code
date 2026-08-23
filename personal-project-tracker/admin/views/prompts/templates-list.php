<?php
/**
 * AI Prompt Studio: prompt template list.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ptp_search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$ptp_result = PTP_Prompt_Templates_Repository::get_list(
	array(
		'search'   => $ptp_search,
		'per_page' => 50,
	)
);
?>
<div class="ptp-page-header">
	<h1><?php esc_html_e( 'Prompt Templates', 'personal-project-tracker' ); ?></h1>
	<div class="ptp-quick-actions">
		<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-ai-prompts' ), admin_url( 'admin.php' ) ) ); ?>">
			<?php esc_html_e( '&laquo; Back to Prompts', 'personal-project-tracker' ); ?>
		</a>
		<a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-ai-prompts', 'action' => 'new_template' ), admin_url( 'admin.php' ) ) ); ?>">
			<?php esc_html_e( '+ New Template', 'personal-project-tracker' ); ?>
		</a>
	</div>
</div>

<div class="ptp-card">
	<form method="get" class="ptp-filter-bar">
		<input type="hidden" name="page" value="ptp-ai-prompts" />
		<input type="hidden" name="action" value="templates" />
		<input type="search" name="s" value="<?php echo esc_attr( $ptp_search ); ?>" placeholder="<?php esc_attr_e( 'Search templates…', 'personal-project-tracker' ); ?>" />
		<button type="submit" class="button"><?php esc_html_e( 'Filter', 'personal-project-tracker' ); ?></button>
	</form>

	<?php if ( empty( $ptp_result['items'] ) ) : ?>
		<div class="ptp-empty-state">
			<span class="dashicons dashicons-media-text" aria-hidden="true"></span>
			<p><?php esc_html_e( 'No templates yet. Save your first prompt setup as a template to reuse it later.', 'personal-project-tracker' ); ?></p>
		</div>
	<?php else : ?>
		<div class="ptp-table-responsive">
			<table class="widefat striped ptp-prompt-templates-table ptp-responsive-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'personal-project-tracker' ); ?></th>
						<th class="ptp-col-optional"><?php esc_html_e( 'Category', 'personal-project-tracker' ); ?></th>
						<th class="ptp-col-optional"><?php esc_html_e( 'Role', 'personal-project-tracker' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'personal-project-tracker' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $ptp_result['items'] as $ptp_template ) : ?>
						<?php
						$ptp_data      = PTP_Prompt_Templates_Repository::get_template_data( $ptp_template );
						$ptp_role_slug = $ptp_data['role'] ?? 'custom';
						$ptp_use_url   = add_query_arg( array( 'page' => 'ptp-ai-prompts', 'action' => 'new', 'template_id' => $ptp_template->id ), admin_url( 'admin.php' ) );
						?>
						<tr>
							<td data-label="<?php esc_attr_e( 'Name', 'personal-project-tracker' ); ?>"><strong><?php echo esc_html( $ptp_template->name ); ?></strong></td>
							<td class="ptp-col-optional" data-label="<?php esc_attr_e( 'Category', 'personal-project-tracker' ); ?>"><?php echo esc_html( $ptp_template->category ? $ptp_template->category : '—' ); ?></td>
							<td class="ptp-col-optional" data-label="<?php esc_attr_e( 'Role', 'personal-project-tracker' ); ?>"><?php echo esc_html( PTP_Prompt_Generator::ROLES[ $ptp_role_slug ] ?? $ptp_role_slug ); ?></td>
							<td class="ptp-td-actions">
								<div class="ptp-quick-actions">
									<a class="button button-small" href="<?php echo esc_url( $ptp_use_url ); ?>"><?php esc_html_e( 'Use', 'personal-project-tracker' ); ?></a>
									<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-ai-prompts', 'action' => 'edit_template', 'id' => $ptp_template->id ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Edit', 'personal-project-tracker' ); ?></a>
									<button type="button" class="button button-small button-link-delete ptp-js-template-delete" data-id="<?php echo esc_attr( $ptp_template->id ); ?>">
										<?php esc_html_e( 'Delete', 'personal-project-tracker' ); ?>
									</button>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</div>
