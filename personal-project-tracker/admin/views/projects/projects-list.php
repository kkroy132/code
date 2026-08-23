<?php
/**
 * Projects list page: search, filter, sort, paginate, quick actions.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ptp_search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_status   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_priority = isset( $_GET['priority'] ) ? sanitize_key( wp_unslash( $_GET['priority'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_orderby  = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'updated_at'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_order    = isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ? 'ASC' : 'DESC'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$ptp_result   = PTP_Projects_Repository::get_list(
	array(
		'search'   => $ptp_search,
		'status'   => $ptp_status,
		'priority' => $ptp_priority,
		'orderby'  => $ptp_orderby,
		'order'    => $ptp_order,
		'paged'    => $ptp_paged,
		'per_page' => 20,
	)
);
$ptp_stats    = PTP_Projects_Repository::get_stats();
$ptp_statuses = PTP_Projects_Repository::get_statuses();
$ptp_priorities = PTP_Projects_Repository::get_priorities();

if ( ! function_exists( 'ptp_projects_sort_link' ) ) {
	/**
	 * Build a sortable column header link that preserves the current filters.
	 *
	 * This file is require()'d from inside a class method, so its top-level
	 * variables are local to that method's scope, not the true PHP global
	 * scope — the current orderby/order are passed in explicitly instead
	 * of read via `global`.
	 *
	 * @param string $column          Column key.
	 * @param string $label           Visible label.
	 * @param string $current_orderby Currently active sort column.
	 * @param string $current_order   Currently active sort direction ('ASC'|'DESC').
	 */
	function ptp_projects_sort_link( $column, $label, $current_orderby, $current_order ) {
		$next_order = ( $current_orderby === $column && 'ASC' === $current_order ) ? 'desc' : 'asc';
		$url        = add_query_arg(
			array(
				'orderby' => $column,
				'order'   => $next_order,
			)
		);

		$indicator = '';
		if ( $current_orderby === $column ) {
			$indicator = 'ASC' === $current_order ? ' &uarr;' : ' &darr;';
		}

		printf(
			'<a href="%1$s">%2$s%3$s</a>',
			esc_url( $url ),
			esc_html( $label ),
			wp_kses( $indicator, array() )
		);
	}
}
?>
<div class="ptp-page-header">
	<h1><?php esc_html_e( 'Projects', 'personal-project-tracker' ); ?></h1>
	<a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-projects', 'action' => 'new' ), admin_url( 'admin.php' ) ) ); ?>">
		<?php esc_html_e( '+ Add New Project', 'personal-project-tracker' ); ?>
	</a>
</div>

<div class="ptp-stats-grid">
	<div class="ptp-stat-tile">
		<span class="ptp-stat-value"><?php echo esc_html( number_format_i18n( $ptp_stats['total'] ) ); ?></span>
		<span class="ptp-stat-label"><?php esc_html_e( 'Total Projects', 'personal-project-tracker' ); ?></span>
	</div>
	<div class="ptp-stat-tile">
		<span class="ptp-stat-value"><?php echo esc_html( number_format_i18n( $ptp_stats['active'] ) ); ?></span>
		<span class="ptp-stat-label"><?php esc_html_e( 'Active', 'personal-project-tracker' ); ?></span>
	</div>
	<div class="ptp-stat-tile">
		<span class="ptp-stat-value"><?php echo esc_html( number_format_i18n( $ptp_stats['completed'] ) ); ?></span>
		<span class="ptp-stat-label"><?php esc_html_e( 'Completed', 'personal-project-tracker' ); ?></span>
	</div>
	<div class="ptp-stat-tile ptp-stat-tile-warning">
		<span class="ptp-stat-value"><?php echo esc_html( number_format_i18n( $ptp_stats['overdue'] ) ); ?></span>
		<span class="ptp-stat-label"><?php esc_html_e( 'Overdue', 'personal-project-tracker' ); ?></span>
	</div>
</div>

<div class="ptp-card">
	<form method="get" class="ptp-filter-bar">
		<input type="hidden" name="page" value="ptp-projects" />

		<input
			type="search"
			name="s"
			value="<?php echo esc_attr( $ptp_search ); ?>"
			placeholder="<?php esc_attr_e( 'Search projects…', 'personal-project-tracker' ); ?>"
		/>

		<select name="status">
			<option value=""><?php esc_html_e( 'All statuses', 'personal-project-tracker' ); ?></option>
			<?php foreach ( $ptp_statuses as $ptp_status_key => $ptp_status_label ) : ?>
				<option value="<?php echo esc_attr( $ptp_status_key ); ?>" <?php selected( $ptp_status, $ptp_status_key ); ?>>
					<?php echo esc_html( $ptp_status_label ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<select name="priority">
			<option value=""><?php esc_html_e( 'All priorities', 'personal-project-tracker' ); ?></option>
			<?php foreach ( $ptp_priorities as $ptp_priority_key => $ptp_priority_label ) : ?>
				<option value="<?php echo esc_attr( $ptp_priority_key ); ?>" <?php selected( $ptp_priority, $ptp_priority_key ); ?>>
					<?php echo esc_html( $ptp_priority_label ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<button type="submit" class="button"><?php esc_html_e( 'Filter', 'personal-project-tracker' ); ?></button>

		<?php if ( $ptp_search || $ptp_status || $ptp_priority ) : ?>
			<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-projects' ), admin_url( 'admin.php' ) ) ); ?>">
				<?php esc_html_e( 'Reset', 'personal-project-tracker' ); ?>
			</a>
		<?php endif; ?>
	</form>

	<?php if ( empty( $ptp_result['items'] ) ) : ?>

		<div class="ptp-empty-state">
			<span class="dashicons dashicons-portfolio" aria-hidden="true"></span>
			<?php if ( $ptp_search || $ptp_status || $ptp_priority ) : ?>
				<p><?php esc_html_e( 'No projects match your search or filters.', 'personal-project-tracker' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'No projects yet. Create your first project to get started.', 'personal-project-tracker' ); ?></p>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-projects', 'action' => 'new' ), admin_url( 'admin.php' ) ) ); ?>">
						<?php esc_html_e( '+ Add New Project', 'personal-project-tracker' ); ?>
					</a>
				</p>
			<?php endif; ?>
		</div>

	<?php else : ?>

		<div class="ptp-table-responsive">
			<table class="widefat striped ptp-projects-table ptp-responsive-table">
				<thead>
					<tr>
						<th><?php ptp_projects_sort_link( 'title', __( 'Title', 'personal-project-tracker' ), $ptp_orderby, $ptp_order ); ?></th>
						<th><?php ptp_projects_sort_link( 'status', __( 'Status', 'personal-project-tracker' ), $ptp_orderby, $ptp_order ); ?></th>
						<th><?php ptp_projects_sort_link( 'priority', __( 'Priority', 'personal-project-tracker' ), $ptp_orderby, $ptp_order ); ?></th>
						<th class="ptp-col-optional"><?php ptp_projects_sort_link( 'deadline', __( 'Deadline', 'personal-project-tracker' ), $ptp_orderby, $ptp_order ); ?></th>
						<th class="ptp-col-optional"><?php ptp_projects_sort_link( 'progress', __( 'Progress', 'personal-project-tracker' ), $ptp_orderby, $ptp_order ); ?></th>
						<th class="ptp-col-optional"><?php ptp_projects_sort_link( 'budget', __( 'Budget', 'personal-project-tracker' ), $ptp_orderby, $ptp_order ); ?></th>
						<th><?php esc_html_e( 'Actions', 'personal-project-tracker' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $ptp_result['items'] as $ptp_project ) : ?>
						<?php
						$ptp_view_url = add_query_arg( array( 'page' => 'ptp-projects', 'action' => 'view', 'id' => $ptp_project->id ), admin_url( 'admin.php' ) );
						$ptp_edit_url = add_query_arg( array( 'page' => 'ptp-projects', 'action' => 'edit', 'id' => $ptp_project->id ), admin_url( 'admin.php' ) );
						$ptp_is_overdue = $ptp_project->deadline && $ptp_project->deadline < current_time( 'Y-m-d' ) && ! in_array( $ptp_project->status, array( 'completed', 'cancelled', 'archived' ), true );
						?>
						<tr>
							<td data-label="<?php esc_attr_e( 'Title', 'personal-project-tracker' ); ?>">
								<?php if ( $ptp_project->color ) : ?>
									<span class="ptp-color-dot" style="background:<?php echo esc_attr( $ptp_project->color ); ?>"></span>
								<?php endif; ?>
								<a href="<?php echo esc_url( $ptp_view_url ); ?>"><strong><?php echo esc_html( $ptp_project->title ); ?></strong></a>
							</td>
							<td data-label="<?php esc_attr_e( 'Status', 'personal-project-tracker' ); ?>"><span class="ptp-badge ptp-badge-status-<?php echo esc_attr( $ptp_project->status ); ?>"><?php echo esc_html( $ptp_statuses[ $ptp_project->status ] ?? $ptp_project->status ); ?></span></td>
							<td data-label="<?php esc_attr_e( 'Priority', 'personal-project-tracker' ); ?>"><span class="ptp-badge ptp-badge-priority-<?php echo esc_attr( $ptp_project->priority ); ?>"><?php echo esc_html( $ptp_priorities[ $ptp_project->priority ] ?? $ptp_project->priority ); ?></span></td>
							<td class="ptp-col-optional" data-label="<?php esc_attr_e( 'Deadline', 'personal-project-tracker' ); ?>">
								<?php if ( $ptp_project->deadline ) : ?>
									<span class="<?php echo esc_attr( $ptp_is_overdue ? 'ptp-text-danger' : '' ); ?>">
										<?php echo esc_html( mysql2date( get_option( 'date_format' ), $ptp_project->deadline ) ); ?>
										<?php if ( $ptp_is_overdue ) : ?>
											<em>(<?php esc_html_e( 'overdue', 'personal-project-tracker' ); ?>)</em>
										<?php endif; ?>
									</span>
								<?php else : ?>
									&#8212;
								<?php endif; ?>
							</td>
							<td class="ptp-col-optional" data-label="<?php esc_attr_e( 'Progress', 'personal-project-tracker' ); ?>">
								<div class="ptp-progress" aria-hidden="true">
									<div class="ptp-progress-bar" style="width:<?php echo esc_attr( (int) $ptp_project->progress ); ?>%"></div>
								</div>
								<span class="ptp-progress-label"><?php echo esc_html( (int) $ptp_project->progress ); ?>%</span>
							</td>
							<td class="ptp-col-optional" data-label="<?php esc_attr_e( 'Budget', 'personal-project-tracker' ); ?>">
								<?php echo $ptp_project->budget ? esc_html( ptp_format_currency( $ptp_project->budget, $ptp_project->currency ) ) : '&#8212;'; ?>
							</td>
							<td class="ptp-td-actions">
								<div class="ptp-quick-actions">
									<a class="button button-small" href="<?php echo esc_url( $ptp_edit_url ); ?>"><?php esc_html_e( 'Edit', 'personal-project-tracker' ); ?></a>
									<?php if ( 'archived' === $ptp_project->status ) : ?>
										<button type="button" class="button button-small ptp-js-restore" data-id="<?php echo esc_attr( $ptp_project->id ); ?>">
											<?php esc_html_e( 'Restore', 'personal-project-tracker' ); ?>
										</button>
									<?php else : ?>
										<button type="button" class="button button-small ptp-js-archive" data-id="<?php echo esc_attr( $ptp_project->id ); ?>">
											<?php esc_html_e( 'Archive', 'personal-project-tracker' ); ?>
										</button>
									<?php endif; ?>
									<button type="button" class="button button-small button-link-delete ptp-js-delete" data-id="<?php echo esc_attr( $ptp_project->id ); ?>">
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
