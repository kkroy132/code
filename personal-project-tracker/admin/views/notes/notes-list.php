<?php
/**
 * Notes list page: search, filter, sort, paginate, quick actions.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ptp_search       = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_project_id   = isset( $_GET['project_id'] ) ? absint( $_GET['project_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_task_id      = isset( $_GET['task_id'] ) ? absint( $_GET['task_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_milestone_id = isset( $_GET['milestone_id'] ) ? absint( $_GET['milestone_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_pinned       = isset( $_GET['pinned'] ) ? sanitize_key( wp_unslash( $_GET['pinned'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_view         = isset( $_GET['view'] ) && 'archived' === sanitize_key( wp_unslash( $_GET['view'] ) ) ? 'archived' : 'active'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_orderby      = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'updated_at'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_order        = isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ? 'ASC' : 'DESC'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_paged        = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$ptp_result = PTP_Notes_Repository::get_list(
	array(
		'search'       => $ptp_search,
		'project_id'   => $ptp_project_id,
		'task_id'      => $ptp_task_id,
		'milestone_id' => $ptp_milestone_id,
		'pinned'       => $ptp_pinned,
		'view'         => $ptp_view,
		'orderby'      => $ptp_orderby,
		'order'        => $ptp_order,
		'paged'        => $ptp_paged,
		'per_page'     => 20,
	)
);
$ptp_projects    = PTP_Projects_Repository::get_options_for_select();
$ptp_has_filters = $ptp_search || $ptp_project_id || $ptp_task_id || $ptp_milestone_id || '' !== $ptp_pinned || 'archived' === $ptp_view;
?>
<div class="ptp-page-header">
	<h1><?php esc_html_e( 'Notes', 'personal-project-tracker' ); ?></h1>
	<a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-notes', 'action' => 'new' ), admin_url( 'admin.php' ) ) ); ?>">
		<?php esc_html_e( '+ Add New Note', 'personal-project-tracker' ); ?>
	</a>
</div>

<div class="ptp-card">
	<form method="get" class="ptp-filter-bar">
		<input type="hidden" name="page" value="ptp-notes" />

		<input
			type="search"
			name="s"
			value="<?php echo esc_attr( $ptp_search ); ?>"
			placeholder="<?php esc_attr_e( 'Search notes…', 'personal-project-tracker' ); ?>"
		/>

		<select name="project_id">
			<option value=""><?php esc_html_e( 'All projects', 'personal-project-tracker' ); ?></option>
			<?php foreach ( $ptp_projects as $ptp_pid => $ptp_ptitle ) : ?>
				<option value="<?php echo esc_attr( $ptp_pid ); ?>" <?php selected( $ptp_project_id, $ptp_pid ); ?>>
					<?php echo esc_html( $ptp_ptitle ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<select name="pinned">
			<option value=""><?php esc_html_e( 'Pinned + Unpinned', 'personal-project-tracker' ); ?></option>
			<option value="1" <?php selected( $ptp_pinned, '1' ); ?>><?php esc_html_e( 'Pinned only', 'personal-project-tracker' ); ?></option>
		</select>

		<select name="view">
			<option value="active" <?php selected( $ptp_view, 'active' ); ?>><?php esc_html_e( 'Active', 'personal-project-tracker' ); ?></option>
			<option value="archived" <?php selected( $ptp_view, 'archived' ); ?>><?php esc_html_e( 'Archived', 'personal-project-tracker' ); ?></option>
		</select>

		<button type="submit" class="button"><?php esc_html_e( 'Filter', 'personal-project-tracker' ); ?></button>

		<?php if ( $ptp_has_filters ) : ?>
			<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-notes' ), admin_url( 'admin.php' ) ) ); ?>">
				<?php esc_html_e( 'Reset', 'personal-project-tracker' ); ?>
			</a>
		<?php endif; ?>
	</form>

	<?php if ( empty( $ptp_result['items'] ) ) : ?>

		<div class="ptp-empty-state">
			<span class="dashicons dashicons-edit" aria-hidden="true"></span>
			<?php if ( $ptp_has_filters ) : ?>
				<p><?php esc_html_e( 'No notes match your search or filters.', 'personal-project-tracker' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'No notes yet. Create your first note to get started.', 'personal-project-tracker' ); ?></p>
			<?php endif; ?>
		</div>

	<?php else : ?>

		<div class="ptp-table-responsive">
			<table class="widefat striped ptp-notes-table ptp-responsive-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Title', 'personal-project-tracker' ); ?></th>
						<th class="ptp-col-optional"><?php esc_html_e( 'Project', 'personal-project-tracker' ); ?></th>
						<th class="ptp-col-optional"><?php esc_html_e( 'Tags', 'personal-project-tracker' ); ?></th>
						<th class="ptp-col-optional"><?php esc_html_e( 'Updated', 'personal-project-tracker' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'personal-project-tracker' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $ptp_result['items'] as $ptp_note ) : ?>
						<?php
						$ptp_view_url = add_query_arg( array( 'page' => 'ptp-notes', 'action' => 'view', 'id' => $ptp_note->id ), admin_url( 'admin.php' ) );
						$ptp_edit_url = add_query_arg( array( 'page' => 'ptp-notes', 'action' => 'edit', 'id' => $ptp_note->id ), admin_url( 'admin.php' ) );
						?>
						<tr>
							<td data-label="<?php esc_attr_e( 'Title', 'personal-project-tracker' ); ?>">
								<?php if ( $ptp_note->pinned ) : ?>
									<span class="dashicons dashicons-sticky" title="<?php esc_attr_e( 'Pinned', 'personal-project-tracker' ); ?>"></span>
								<?php endif; ?>
								<a href="<?php echo esc_url( $ptp_view_url ); ?>"><strong><?php echo esc_html( $ptp_note->title ? $ptp_note->title : __( '(untitled)', 'personal-project-tracker' ) ); ?></strong></a>
							</td>
							<td class="ptp-col-optional" data-label="<?php esc_attr_e( 'Project', 'personal-project-tracker' ); ?>"><?php echo esc_html( $ptp_note->project_id && isset( $ptp_projects[ (int) $ptp_note->project_id ] ) ? $ptp_projects[ (int) $ptp_note->project_id ] : '—' ); ?></td>
							<td class="ptp-col-optional" data-label="<?php esc_attr_e( 'Tags', 'personal-project-tracker' ); ?>"><?php echo esc_html( $ptp_note->tags ? $ptp_note->tags : '—' ); ?></td>
							<td class="ptp-col-optional" data-label="<?php esc_attr_e( 'Updated', 'personal-project-tracker' ); ?>"><?php echo esc_html( mysql2date( get_option( 'date_format' ), $ptp_note->updated_at ) ); ?></td>
							<td class="ptp-td-actions">
								<div class="ptp-quick-actions">
									<a class="button button-small" href="<?php echo esc_url( $ptp_edit_url ); ?>"><?php esc_html_e( 'Edit', 'personal-project-tracker' ); ?></a>
									<?php if ( $ptp_note->pinned ) : ?>
										<button type="button" class="button button-small ptp-js-note-unpin" data-id="<?php echo esc_attr( $ptp_note->id ); ?>">
											<?php esc_html_e( 'Unpin', 'personal-project-tracker' ); ?>
										</button>
									<?php else : ?>
										<button type="button" class="button button-small ptp-js-note-pin" data-id="<?php echo esc_attr( $ptp_note->id ); ?>">
											<?php esc_html_e( 'Pin', 'personal-project-tracker' ); ?>
										</button>
									<?php endif; ?>
									<?php if ( 'archived' === $ptp_view ) : ?>
										<button type="button" class="button button-small ptp-js-note-restore" data-id="<?php echo esc_attr( $ptp_note->id ); ?>">
											<?php esc_html_e( 'Restore', 'personal-project-tracker' ); ?>
										</button>
									<?php else : ?>
										<button type="button" class="button button-small ptp-js-note-archive" data-id="<?php echo esc_attr( $ptp_note->id ); ?>">
											<?php esc_html_e( 'Archive', 'personal-project-tracker' ); ?>
										</button>
									<?php endif; ?>
									<button type="button" class="button button-small button-link-delete ptp-js-note-delete" data-id="<?php echo esc_attr( $ptp_note->id ); ?>">
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
