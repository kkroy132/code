<?php
/**
 * Links list page: search, filter, sort, paginate, quick actions.
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
$ptp_category     = isset( $_GET['category'] ) ? sanitize_text_field( wp_unslash( $_GET['category'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_orderby      = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'created_at'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_order        = isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ? 'ASC' : 'DESC'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_paged        = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$ptp_result = PTP_Links_Repository::get_list(
	array(
		'search'       => $ptp_search,
		'project_id'   => $ptp_project_id,
		'task_id'      => $ptp_task_id,
		'milestone_id' => $ptp_milestone_id,
		'category'     => $ptp_category,
		'orderby'      => $ptp_orderby,
		'order'        => $ptp_order,
		'paged'        => $ptp_paged,
		'per_page'     => 20,
	)
);
$ptp_projects    = PTP_Projects_Repository::get_options_for_select();
$ptp_has_filters = $ptp_search || $ptp_project_id || $ptp_task_id || $ptp_milestone_id || $ptp_category;
?>
<div class="ptp-page-header">
	<h1><?php esc_html_e( 'Links', 'personal-project-tracker' ); ?></h1>
	<a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-links', 'action' => 'new' ), admin_url( 'admin.php' ) ) ); ?>">
		<?php esc_html_e( '+ Add New Link', 'personal-project-tracker' ); ?>
	</a>
</div>

<div class="ptp-card">
	<form method="get" class="ptp-filter-bar">
		<input type="hidden" name="page" value="ptp-links" />

		<input
			type="search"
			name="s"
			value="<?php echo esc_attr( $ptp_search ); ?>"
			placeholder="<?php esc_attr_e( 'Search links…', 'personal-project-tracker' ); ?>"
		/>

		<select name="project_id">
			<option value=""><?php esc_html_e( 'All projects', 'personal-project-tracker' ); ?></option>
			<?php foreach ( $ptp_projects as $ptp_pid => $ptp_ptitle ) : ?>
				<option value="<?php echo esc_attr( $ptp_pid ); ?>" <?php selected( $ptp_project_id, $ptp_pid ); ?>>
					<?php echo esc_html( $ptp_ptitle ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<input
			type="text"
			name="category"
			value="<?php echo esc_attr( $ptp_category ); ?>"
			placeholder="<?php esc_attr_e( 'Category', 'personal-project-tracker' ); ?>"
		/>

		<button type="submit" class="button"><?php esc_html_e( 'Filter', 'personal-project-tracker' ); ?></button>

		<?php if ( $ptp_has_filters ) : ?>
			<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-links' ), admin_url( 'admin.php' ) ) ); ?>">
				<?php esc_html_e( 'Reset', 'personal-project-tracker' ); ?>
			</a>
		<?php endif; ?>
	</form>

	<?php if ( empty( $ptp_result['items'] ) ) : ?>

		<div class="ptp-empty-state">
			<span class="dashicons dashicons-admin-links"></span>
			<?php if ( $ptp_has_filters ) : ?>
				<p><?php esc_html_e( 'No links match your search or filters.', 'personal-project-tracker' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'No links yet. Add your first link to get started.', 'personal-project-tracker' ); ?></p>
			<?php endif; ?>
		</div>

	<?php else : ?>

		<div class="ptp-table-responsive">
			<table class="widefat striped ptp-links-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Title', 'personal-project-tracker' ); ?></th>
						<th class="ptp-col-optional"><?php esc_html_e( 'Project', 'personal-project-tracker' ); ?></th>
						<th class="ptp-col-optional"><?php esc_html_e( 'Category', 'personal-project-tracker' ); ?></th>
						<th class="ptp-col-optional"><?php esc_html_e( 'Added', 'personal-project-tracker' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'personal-project-tracker' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $ptp_result['items'] as $ptp_link ) : ?>
						<?php
						$ptp_view_url = add_query_arg( array( 'page' => 'ptp-links', 'action' => 'view', 'id' => $ptp_link->id ), admin_url( 'admin.php' ) );
						$ptp_edit_url = add_query_arg( array( 'page' => 'ptp-links', 'action' => 'edit', 'id' => $ptp_link->id ), admin_url( 'admin.php' ) );
						?>
						<tr>
							<td>
								<a href="<?php echo esc_url( $ptp_view_url ); ?>"><strong><?php echo esc_html( $ptp_link->title ); ?></strong></a>
								<br />
								<a href="<?php echo esc_url( $ptp_link->url ); ?>" target="_blank" rel="noopener noreferrer" class="description">
									<?php echo esc_html( $ptp_link->url ); ?>
								</a>
							</td>
							<td class="ptp-col-optional"><?php echo esc_html( $ptp_link->project_id && isset( $ptp_projects[ (int) $ptp_link->project_id ] ) ? $ptp_projects[ (int) $ptp_link->project_id ] : '—' ); ?></td>
							<td class="ptp-col-optional"><?php echo esc_html( $ptp_link->category ? $ptp_link->category : '—' ); ?></td>
							<td class="ptp-col-optional"><?php echo esc_html( mysql2date( get_option( 'date_format' ), $ptp_link->created_at ) ); ?></td>
							<td>
								<div class="ptp-quick-actions">
									<a class="button button-small" href="<?php echo esc_url( $ptp_edit_url ); ?>"><?php esc_html_e( 'Edit', 'personal-project-tracker' ); ?></a>
									<button type="button" class="button button-small button-link-delete ptp-js-link-delete" data-id="<?php echo esc_attr( $ptp_link->id ); ?>">
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
