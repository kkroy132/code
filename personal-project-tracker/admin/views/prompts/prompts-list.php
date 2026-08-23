<?php
/**
 * AI Prompt Studio: prompt list — search, filter, sort, paginate, quick actions.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ptp_search       = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_project_id   = isset( $_GET['project_id'] ) ? absint( $_GET['project_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_context_type = isset( $_GET['context_type'] ) ? sanitize_key( wp_unslash( $_GET['context_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_favorite     = isset( $_GET['favorite'] ) ? sanitize_key( wp_unslash( $_GET['favorite'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_orderby      = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'updated_at'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_order        = isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ? 'ASC' : 'DESC'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_paged        = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$ptp_result = PTP_Prompt_Documents_Repository::get_list(
	array(
		'search'       => $ptp_search,
		'project_id'   => $ptp_project_id,
		'context_type' => $ptp_context_type,
		'favorite'     => $ptp_favorite,
		'orderby'      => $ptp_orderby,
		'order'        => $ptp_order,
		'paged'        => $ptp_paged,
		'per_page'     => 20,
	)
);
$ptp_projects      = PTP_Projects_Repository::get_options_for_select();
$ptp_context_types = PTP_Prompt_Documents_Repository::get_context_types();
$ptp_has_filters   = $ptp_search || $ptp_project_id || $ptp_context_type || '' !== $ptp_favorite;
?>
<div class="ptp-page-header">
	<h1><?php esc_html_e( 'AI Prompt Studio', 'personal-project-tracker' ); ?></h1>
	<div class="ptp-quick-actions">
		<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-ai-prompts', 'action' => 'templates' ), admin_url( 'admin.php' ) ) ); ?>">
			<?php esc_html_e( 'Templates', 'personal-project-tracker' ); ?>
		</a>
		<a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-ai-prompts', 'action' => 'new' ), admin_url( 'admin.php' ) ) ); ?>">
			<?php esc_html_e( '+ New Prompt', 'personal-project-tracker' ); ?>
		</a>
	</div>
</div>

<p class="description"><?php esc_html_e( 'AI Prompt Studio generates structured prompts from your own project data. Nothing here calls an external AI service — copy the generated text into Gemini, Claude, ChatGPT, or any other tool.', 'personal-project-tracker' ); ?></p>

<div class="ptp-card">
	<form method="get" class="ptp-filter-bar">
		<input type="hidden" name="page" value="ptp-ai-prompts" />

		<input
			type="search"
			name="s"
			value="<?php echo esc_attr( $ptp_search ); ?>"
			placeholder="<?php esc_attr_e( 'Search prompts…', 'personal-project-tracker' ); ?>"
		/>

		<select name="project_id">
			<option value=""><?php esc_html_e( 'All projects', 'personal-project-tracker' ); ?></option>
			<?php foreach ( $ptp_projects as $ptp_pid => $ptp_ptitle ) : ?>
				<option value="<?php echo esc_attr( $ptp_pid ); ?>" <?php selected( $ptp_project_id, $ptp_pid ); ?>>
					<?php echo esc_html( $ptp_ptitle ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<select name="context_type">
			<option value=""><?php esc_html_e( 'All context types', 'personal-project-tracker' ); ?></option>
			<?php foreach ( $ptp_context_types as $ptp_ckey => $ptp_clabel ) : ?>
				<option value="<?php echo esc_attr( $ptp_ckey ); ?>" <?php selected( $ptp_context_type, $ptp_ckey ); ?>>
					<?php echo esc_html( $ptp_clabel ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<select name="favorite">
			<option value=""><?php esc_html_e( 'All prompts', 'personal-project-tracker' ); ?></option>
			<option value="1" <?php selected( $ptp_favorite, '1' ); ?>><?php esc_html_e( 'Favorites only', 'personal-project-tracker' ); ?></option>
		</select>

		<button type="submit" class="button"><?php esc_html_e( 'Filter', 'personal-project-tracker' ); ?></button>

		<?php if ( $ptp_has_filters ) : ?>
			<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-ai-prompts' ), admin_url( 'admin.php' ) ) ); ?>">
				<?php esc_html_e( 'Reset', 'personal-project-tracker' ); ?>
			</a>
		<?php endif; ?>
	</form>

	<?php if ( empty( $ptp_result['items'] ) ) : ?>

		<div class="ptp-empty-state">
			<span class="dashicons dashicons-format-chat"></span>
			<?php if ( $ptp_has_filters ) : ?>
				<p><?php esc_html_e( 'No prompts match your search or filters.', 'personal-project-tracker' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'No prompts yet. Create your first AI prompt to get started.', 'personal-project-tracker' ); ?></p>
			<?php endif; ?>
		</div>

	<?php else : ?>

		<div class="ptp-table-responsive">
			<table class="widefat striped ptp-prompts-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Title', 'personal-project-tracker' ); ?></th>
						<th class="ptp-col-optional"><?php esc_html_e( 'Context', 'personal-project-tracker' ); ?></th>
						<th class="ptp-col-optional"><?php esc_html_e( 'Project', 'personal-project-tracker' ); ?></th>
						<th class="ptp-col-optional"><?php esc_html_e( 'Updated', 'personal-project-tracker' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'personal-project-tracker' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $ptp_result['items'] as $ptp_prompt ) : ?>
						<?php
						$ptp_view_url = add_query_arg( array( 'page' => 'ptp-ai-prompts', 'action' => 'view', 'id' => $ptp_prompt->id ), admin_url( 'admin.php' ) );
						$ptp_edit_url = add_query_arg( array( 'page' => 'ptp-ai-prompts', 'action' => 'edit', 'id' => $ptp_prompt->id ), admin_url( 'admin.php' ) );
						?>
						<tr>
							<td>
								<?php if ( $ptp_prompt->favorite ) : ?>
									<span class="dashicons dashicons-star-filled" title="<?php esc_attr_e( 'Favorite', 'personal-project-tracker' ); ?>"></span>
								<?php endif; ?>
								<a href="<?php echo esc_url( $ptp_view_url ); ?>"><strong><?php echo esc_html( $ptp_prompt->title ); ?></strong></a>
							</td>
							<td class="ptp-col-optional"><?php echo esc_html( $ptp_context_types[ $ptp_prompt->context_type ] ?? $ptp_prompt->context_type ); ?></td>
							<td class="ptp-col-optional"><?php echo esc_html( $ptp_prompt->project_id && isset( $ptp_projects[ (int) $ptp_prompt->project_id ] ) ? $ptp_projects[ (int) $ptp_prompt->project_id ] : '—' ); ?></td>
							<td class="ptp-col-optional"><?php echo esc_html( mysql2date( get_option( 'date_format' ), $ptp_prompt->updated_at ) ); ?></td>
							<td>
								<div class="ptp-quick-actions">
									<a class="button button-small" href="<?php echo esc_url( $ptp_edit_url ); ?>"><?php esc_html_e( 'Edit', 'personal-project-tracker' ); ?></a>
									<?php if ( $ptp_prompt->favorite ) : ?>
										<button type="button" class="button button-small ptp-js-prompt-unfavorite" data-id="<?php echo esc_attr( $ptp_prompt->id ); ?>">
											<?php esc_html_e( 'Unfavorite', 'personal-project-tracker' ); ?>
										</button>
									<?php else : ?>
										<button type="button" class="button button-small ptp-js-prompt-favorite" data-id="<?php echo esc_attr( $ptp_prompt->id ); ?>">
											<?php esc_html_e( 'Favorite', 'personal-project-tracker' ); ?>
										</button>
									<?php endif; ?>
									<button type="button" class="button button-small ptp-js-prompt-duplicate" data-id="<?php echo esc_attr( $ptp_prompt->id ); ?>">
										<?php esc_html_e( 'Duplicate', 'personal-project-tracker' ); ?>
									</button>
									<button type="button" class="button button-small button-link-delete ptp-js-prompt-delete" data-id="<?php echo esc_attr( $ptp_prompt->id ); ?>">
										<?php esc_html_e( 'Delete', 'personal-project-tracker' ); ?>
									</button>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<?php if ( $ptp_result['total_pages'] > 1 ) : ?>
			<div class="ptp-pagination">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'current'   => $ptp_result['page'],
							'total'     => $ptp_result['total_pages'],
							'prev_text' => __( '&laquo; Previous', 'personal-project-tracker' ),
							'next_text' => __( 'Next &raquo;', 'personal-project-tracker' ),
						)
					)
				);
				?>
			</div>
		<?php endif; ?>

	<?php endif; ?>
</div>
