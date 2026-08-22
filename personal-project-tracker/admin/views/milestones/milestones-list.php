<?php
/**
 * Milestones list page: search, filter, sort, paginate, quick actions.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ptp_search     = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_status     = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_priority   = isset( $_GET['priority'] ) ? sanitize_key( wp_unslash( $_GET['priority'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_project_id = isset( $_GET['project_id'] ) ? absint( $_GET['project_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_due_filter = isset( $_GET['due_filter'] ) ? sanitize_key( wp_unslash( $_GET['due_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_view       = isset( $_GET['view'] ) && 'archived' === sanitize_key( wp_unslash( $_GET['view'] ) ) ? 'archived' : 'active'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_orderby    = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'due_date'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_order      = isset( $_GET['order'] ) && 'desc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ? 'DESC' : 'ASC'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_paged      = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$ptp_result = PTP_Milestones_Repository::get_list(
	array(
		'search'     => $ptp_search,
		'status'     => $ptp_status,
		'priority'   => $ptp_priority,
		'project_id' => $ptp_project_id,
		'due_filter' => $ptp_due_filter,
		'view'       => $ptp_view,
		'orderby'    => $ptp_orderby,
		'order'      => $ptp_order,
		'paged'      => $ptp_paged,
		'per_page'   => 20,
	)
);
$ptp_stats      = PTP_Milestones_Repository::get_stats();
$ptp_statuses   = PTP_Milestones_Repository::get_statuses();
$ptp_priorities = PTP_Milestones_Repository::get_priorities();
$ptp_projects   = PTP_Projects_Repository::get_options_for_select();
$ptp_has_filters = $ptp_search || $ptp_status || $ptp_priority || $ptp_project_id || $ptp_due_filter || 'archived' === $ptp_view;

if ( ! function_exists( 'ptp_milestones_sort_link' ) ) {
	/**
	 * Build a sortable column header link that preserves the current filters.
	 *
	 * @param string $column          Column key.
	 * @param string $label           Visible label.
	 * @param string $current_orderby Currently active sort column.
	 * @param string $current_order   Currently active sort direction ('ASC'|'DESC').
	 */
	function ptp_milestones_sort_link( $column, $label, $current_orderby, $current_order ) {
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
	<h1><?php esc_html_e( 'Milestones', 'personal-project-tracker' ); ?></h1>
	<a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-milestones', 'action' => 'new' ), admin_url( 'admin.php' ) ) ); ?>">
		<?php esc_html_e( '+ Add New Milestone', 'personal-project-tracker' ); ?>
	</a>
</div>

<div class="ptp-stats-grid">
	<div class="ptp-stat-tile">
		<span class="ptp-stat-value"><?php echo esc_html( number_format_i18n( $ptp_stats['total'] ) ); ?></span>
		<span class="ptp-stat-label"><?php esc_html_e( 'Total Milestones', 'personal-project-tracker' ); ?></span>
	</div>
	<div class="ptp-stat-tile">
		<span class="ptp-stat-value"><?php echo esc_html( number_format_i18n( $ptp_stats['upcoming'] ) ); ?></span>
		<span class="ptp-stat-label"><?php esc_html_e( 'Upcoming (14 days)', 'personal-project-tracker' ); ?></span>
	</div>
	<div class="ptp-stat-tile ptp-stat-tile-warning">
		<span class="ptp-stat-value"><?php echo esc_html( number_format_i18n( $ptp_stats['overdue'] ) ); ?></span>
		<span class="ptp-stat-label"><?php esc_html_e( 'Overdue', 'personal-project-tracker' ); ?></span>
	</div>
	<div class="ptp-stat-tile">
		<span class="ptp-stat-value"><?php echo esc_html( number_format_i18n( $ptp_stats['completed'] ) ); ?></span>
		<span class="ptp-stat-label"><?php esc_html_e( 'Completed', 'personal-project-tracker' ); ?></span>
	</div>
</div>

<div class="ptp-card">
	<form method="get" class="ptp-filter-bar">
		<input type="hidden" name="page" value="ptp-milestones" />

		<input
			type="search"
			name="s"
			value="<?php echo esc_attr( $ptp_search ); ?>"
			placeholder="<?php esc_attr_e( 'Search milestones…', 'personal-project-tracker' ); ?>"
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

		<select name="project_id">
			<option value=""><?php esc_html_e( 'All projects', 'personal-project-tracker' ); ?></option>
			<?php foreach ( $ptp_projects as $ptp_pid => $ptp_ptitle ) : ?>
				<option value="<?php echo esc_attr( $ptp_pid ); ?>" <?php selected( $ptp_project_id, $ptp_pid ); ?>>
					<?php echo esc_html( $ptp_ptitle ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<select name="due_filter">
			<option value=""><?php esc_html_e( 'Any due date', 'personal-project-tracker' ); ?></option>
			<option value="overdue" <?php selected( $ptp_due_filter, 'overdue' ); ?>><?php esc_html_e( 'Overdue', 'personal-project-tracker' ); ?></option>
			<option value="today" <?php selected( $ptp_due_filter, 'today' ); ?>><?php esc_html_e( 'Due Today', 'personal-project-tracker' ); ?></option>
			<option value="week" <?php selected( $ptp_due_filter, 'week' ); ?>><?php esc_html_e( 'Due This Week', 'personal-project-tracker' ); ?></option>
			<option value="none" <?php selected( $ptp_due_filter, 'none' ); ?>><?php esc_html_e( 'No Due Date', 'personal-project-tracker' ); ?></option>
		</select>

		<select name="view">
			<option value="active" <?php selected( $ptp_view, 'active' ); ?>><?php esc_html_e( 'Active', 'personal-project-tracker' ); ?></option>
			<option value="archived" <?php selected( $ptp_view, 'archived' ); ?>><?php esc_html_e( 'Archived', 'personal-project-tracker' ); ?></option>
		</select>

		<button type="submit" class="button"><?php esc_html_e( 'Filter', 'personal-project-tracker' ); ?></button>

		<?php if ( $ptp_has_filters ) : ?>
			<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-milestones' ), admin_url( 'admin.php' ) ) ); ?>">
				<?php esc_html_e( 'Reset', 'personal-project-tracker' ); ?>
			</a>
		<?php endif; ?>
	</form>

	<?php if ( empty( $ptp_result['items'] ) ) : ?>

		<div class="ptp-empty-state">
			<span class="dashicons dashicons-flag"></span>
			<?php if ( $ptp_has_filters ) : ?>
				<p><?php esc_html_e( 'No milestones match your search or filters.', 'personal-project-tracker' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'No milestones yet. Create your first milestone to get started.', 'personal-project-tracker' ); ?></p>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-milestones', 'action' => 'new' ), admin_url( 'admin.php' ) ) ); ?>">
						<?php esc_html_e( '+ Add New Milestone', 'personal-project-tracker' ); ?>
					</a>
				</p>
			<?php endif; ?>
		</div>

	<?php else : ?>

		<div class="ptp-table-responsive">
			<table class="widefat striped ptp-milestones-table">
				<thead>
					<tr>
						<th><?php ptp_milestones_sort_link( 'title', __( 'Title', 'personal-project-tracker' ), $ptp_orderby, $ptp_order ); ?></th>
						<th class="ptp-col-optional"><?php esc_html_e( 'Project', 'personal-project-tracker' ); ?></th>
						<th><?php ptp_milestones_sort_link( 'status', __( 'Status', 'personal-project-tracker' ), $ptp_orderby, $ptp_order ); ?></th>
						<th><?php ptp_milestones_sort_link( 'priority', __( 'Priority', 'personal-project-tracker' ), $ptp_orderby, $ptp_order ); ?></th>
						<th class="ptp-col-optional"><?php ptp_milestones_sort_link( 'due_date', __( 'Due Date', 'personal-project-tracker' ), $ptp_orderby, $ptp_order ); ?></th>
						<th class="ptp-col-optional"><?php ptp_milestones_sort_link( 'progress', __( 'Progress', 'personal-project-tracker' ), $ptp_orderby, $ptp_order ); ?></th>
						<th><?php esc_html_e( 'Actions', 'personal-project-tracker' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $ptp_result['items'] as $ptp_milestone ) : ?>
						<?php
						$ptp_view_url  = add_query_arg( array( 'page' => 'ptp-milestones', 'action' => 'view', 'id' => $ptp_milestone->id ), admin_url( 'admin.php' ) );
						$ptp_edit_url  = add_query_arg( array( 'page' => 'ptp-milestones', 'action' => 'edit', 'id' => $ptp_milestone->id ), admin_url( 'admin.php' ) );
						$ptp_is_overdue = $ptp_milestone->due_date && $ptp_milestone->due_date < current_time( 'Y-m-d' ) && ! in_array( $ptp_milestone->status, array( 'completed', 'cancelled' ), true );
						$ptp_is_done   = 'completed' === $ptp_milestone->status;
						?>
						<tr>
							<td>
								<a href="<?php echo esc_url( $ptp_view_url ); ?>"><strong><?php echo esc_html( $ptp_milestone->title ); ?></strong></a>
							</td>
							<td class="ptp-col-optional"><?php echo esc_html( $ptp_projects[ (int) $ptp_milestone->project_id ] ?? '—' ); ?></td>
							<td><span class="ptp-badge ptp-badge-status-<?php echo esc_attr( $ptp_milestone->status ); ?>"><?php echo esc_html( $ptp_statuses[ $ptp_milestone->status ] ?? $ptp_milestone->status ); ?></span></td>
							<td><span class="ptp-badge ptp-badge-priority-<?php echo esc_attr( $ptp_milestone->priority ); ?>"><?php echo esc_html( $ptp_priorities[ $ptp_milestone->priority ] ?? $ptp_milestone->priority ); ?></span></td>
							<td class="ptp-col-optional">
								<?php if ( $ptp_milestone->due_date ) : ?>
									<span class="<?php echo esc_attr( $ptp_is_overdue ? 'ptp-text-danger' : '' ); ?>">
										<?php echo esc_html( mysql2date( get_option( 'date_format' ), $ptp_milestone->due_date ) ); ?>
										<?php if ( $ptp_is_overdue ) : ?>
											<em>(<?php esc_html_e( 'overdue', 'personal-project-tracker' ); ?>)</em>
										<?php endif; ?>
									</span>
								<?php else : ?>
									&#8212;
								<?php endif; ?>
							</td>
							<td class="ptp-col-optional">
								<div class="ptp-progress" aria-hidden="true">
									<div class="ptp-progress-bar" style="width:<?php echo esc_attr( (int) $ptp_milestone->progress ); ?>%"></div>
								</div>
								<span class="ptp-progress-label"><?php echo esc_html( (int) $ptp_milestone->progress ); ?>%</span>
							</td>
							<td>
								<div class="ptp-quick-actions">
									<a class="button button-small" href="<?php echo esc_url( $ptp_edit_url ); ?>"><?php esc_html_e( 'Edit', 'personal-project-tracker' ); ?></a>
									<?php if ( $ptp_is_done ) : ?>
										<button type="button" class="button button-small ptp-js-milestone-reopen" data-id="<?php echo esc_attr( $ptp_milestone->id ); ?>">
											<?php esc_html_e( 'Reopen', 'personal-project-tracker' ); ?>
										</button>
									<?php else : ?>
										<button type="button" class="button button-small ptp-js-milestone-complete" data-id="<?php echo esc_attr( $ptp_milestone->id ); ?>">
											<?php esc_html_e( 'Complete', 'personal-project-tracker' ); ?>
										</button>
									<?php endif; ?>
									<?php if ( 'archived' === $ptp_view ) : ?>
										<button type="button" class="button button-small ptp-js-milestone-restore" data-id="<?php echo esc_attr( $ptp_milestone->id ); ?>">
											<?php esc_html_e( 'Restore', 'personal-project-tracker' ); ?>
										</button>
									<?php else : ?>
										<button type="button" class="button button-small ptp-js-milestone-archive" data-id="<?php echo esc_attr( $ptp_milestone->id ); ?>">
											<?php esc_html_e( 'Archive', 'personal-project-tracker' ); ?>
										</button>
									<?php endif; ?>
									<button type="button" class="button button-small button-link-delete ptp-js-milestone-delete" data-id="<?php echo esc_attr( $ptp_milestone->id ); ?>">
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
