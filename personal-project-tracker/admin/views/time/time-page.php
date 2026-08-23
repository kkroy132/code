<?php
/**
 * Time Tracking page: Active Timer widget, reporting summary, filtered/
 * paginated manual entry list.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ptp_search     = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_project_id = isset( $_GET['project_id'] ) ? absint( $_GET['project_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_task_id    = isset( $_GET['task_id'] ) ? absint( $_GET['task_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_status     = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_date_from  = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_date_to    = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_orderby    = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'entry_date'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_order      = isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ? 'ASC' : 'DESC'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_paged      = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$ptp_result = PTP_Time_Repository::get_list(
	array(
		'search'     => $ptp_search,
		'project_id' => $ptp_project_id,
		'task_id'    => $ptp_task_id,
		'status'     => $ptp_status,
		'date_from'  => $ptp_date_from,
		'date_to'    => $ptp_date_to,
		'orderby'    => $ptp_orderby,
		'order'      => $ptp_order,
		'paged'      => $ptp_paged,
		'per_page'   => 20,
	)
);

$ptp_statuses    = PTP_Time_Repository::get_statuses();
$ptp_summary     = PTP_Time_Repository::get_summary_for_user();
$ptp_active      = PTP_Time_Repository::get_active_for_user();
$ptp_projects    = PTP_Projects_Repository::get_options_for_select();
$ptp_has_filters = $ptp_search || $ptp_project_id || $ptp_task_id || $ptp_status || $ptp_date_from || $ptp_date_to;

// Tasks with their owning project, so the Start Timer / filter selects can
// cascade client-side without a repository method beyond the existing
// generic get_list() — reused as-is, not duplicated.
$ptp_task_rows = PTP_Tasks_Repository::get_list(
	array(
		'view'     => 'active',
		'orderby'  => 'title',
		'order'    => 'ASC',
		'per_page' => 500,
	)
)['items'];

$ptp_tasks = array();
foreach ( $ptp_task_rows as $ptp_task_row ) {
	$ptp_tasks[ (int) $ptp_task_row->id ] = array(
		'title'      => $ptp_task_row->title,
		'project_id' => (int) $ptp_task_row->project_id,
	);
}
?>
<div class="ptp-page-header">
	<h1><?php esc_html_e( 'Time Tracking', 'personal-project-tracker' ); ?></h1>
	<a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-time-tracking', 'action' => 'new' ), admin_url( 'admin.php' ) ) ); ?>">
		<?php esc_html_e( '+ Log Time Manually', 'personal-project-tracker' ); ?>
	</a>
</div>

<div
	id="ptp-timer-app"
	class="ptp-card ptp-timer-widget"
	data-active="<?php echo $ptp_active ? '1' : '0'; ?>"
	data-id="<?php echo esc_attr( $ptp_active->id ?? '' ); ?>"
	data-status="<?php echo esc_attr( $ptp_active->status ?? '' ); ?>"
	data-seconds="<?php echo esc_attr( PTP_Time_Repository::get_live_duration( $ptp_active ) ); ?>"
	data-tasks="<?php echo esc_attr( wp_json_encode( $ptp_tasks ) ); ?>"
>
	<?php if ( $ptp_active ) : ?>
		<div class="ptp-timer-display" id="ptp-timer-display">00:00:00</div>
		<div class="ptp-timer-meta">
			<span class="ptp-badge ptp-badge-status-<?php echo esc_attr( $ptp_active->status ); ?>" id="ptp-timer-status-badge">
				<?php echo esc_html( $ptp_statuses[ $ptp_active->status ] ?? $ptp_active->status ); ?>
			</span>
			<?php if ( $ptp_active->project_id && isset( $ptp_projects[ (int) $ptp_active->project_id ] ) ) : ?>
				<span><strong><?php esc_html_e( 'Project:', 'personal-project-tracker' ); ?></strong> <?php echo esc_html( $ptp_projects[ (int) $ptp_active->project_id ] ); ?></span>
			<?php endif; ?>
			<?php if ( $ptp_active->task_id && isset( $ptp_tasks[ (int) $ptp_active->task_id ] ) ) : ?>
				<span><strong><?php esc_html_e( 'Task:', 'personal-project-tracker' ); ?></strong> <?php echo esc_html( $ptp_tasks[ (int) $ptp_active->task_id ]['title'] ); ?></span>
			<?php endif; ?>
			<?php if ( $ptp_active->description ) : ?>
				<span class="ptp-timer-description"><?php echo esc_html( $ptp_active->description ); ?></span>
			<?php endif; ?>
		</div>
		<div class="ptp-quick-actions">
			<button type="button" class="button ptp-js-timer-pause" <?php echo 'paused' === $ptp_active->status ? 'style="display:none;"' : ''; ?>>
				<?php esc_html_e( 'Pause', 'personal-project-tracker' ); ?>
			</button>
			<button type="button" class="button ptp-js-timer-resume" <?php echo 'running' === $ptp_active->status ? 'style="display:none;"' : ''; ?>>
				<?php esc_html_e( 'Resume', 'personal-project-tracker' ); ?>
			</button>
			<button type="button" class="button button-primary ptp-js-timer-stop">
				<?php esc_html_e( 'Stop', 'personal-project-tracker' ); ?>
			</button>
		</div>
	<?php else : ?>
		<h2><?php esc_html_e( 'Start a Timer', 'personal-project-tracker' ); ?></h2>
		<form id="ptp-start-timer-form" class="ptp-form-grid">
			<div class="ptp-form-field">
				<label for="ptp-timer-project"><?php esc_html_e( 'Project', 'personal-project-tracker' ); ?></label>
				<select id="ptp-timer-project">
					<option value=""><?php esc_html_e( '— No project —', 'personal-project-tracker' ); ?></option>
					<?php foreach ( $ptp_projects as $ptp_pid => $ptp_ptitle ) : ?>
						<option value="<?php echo esc_attr( $ptp_pid ); ?>"><?php echo esc_html( $ptp_ptitle ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="ptp-form-field">
				<label for="ptp-timer-task"><?php esc_html_e( 'Task', 'personal-project-tracker' ); ?></label>
				<select id="ptp-timer-task">
					<option value=""><?php esc_html_e( '— No task —', 'personal-project-tracker' ); ?></option>
					<?php foreach ( $ptp_tasks as $ptp_tid => $ptp_trow ) : ?>
						<option value="<?php echo esc_attr( $ptp_tid ); ?>" data-project="<?php echo esc_attr( $ptp_trow['project_id'] ); ?>"><?php echo esc_html( $ptp_trow['title'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="ptp-form-field ptp-form-field-full">
				<label for="ptp-timer-description"><?php esc_html_e( 'Description', 'personal-project-tracker' ); ?></label>
				<input type="text" id="ptp-timer-description" maxlength="500" placeholder="<?php esc_attr_e( 'What are you working on?', 'personal-project-tracker' ); ?>" />
			</div>
			<p class="ptp-form-actions">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Start Timer', 'personal-project-tracker' ); ?></button>
			</p>
		</form>
	<?php endif; ?>
</div>

<div class="ptp-stats-grid">
	<div class="ptp-stat-tile">
		<span class="ptp-stat-value"><?php echo esc_html( ptp_format_duration( $ptp_summary['today'] ) ); ?></span>
		<span class="ptp-stat-label"><?php esc_html_e( 'Today', 'personal-project-tracker' ); ?></span>
	</div>
	<div class="ptp-stat-tile">
		<span class="ptp-stat-value"><?php echo esc_html( ptp_format_duration( $ptp_summary['this_week'] ) ); ?></span>
		<span class="ptp-stat-label"><?php esc_html_e( 'This Week', 'personal-project-tracker' ); ?></span>
	</div>
	<div class="ptp-stat-tile">
		<span class="ptp-stat-value"><?php echo esc_html( ptp_format_duration( $ptp_summary['this_month'] ) ); ?></span>
		<span class="ptp-stat-label"><?php esc_html_e( 'This Month', 'personal-project-tracker' ); ?></span>
	</div>
	<?php if ( $ptp_project_id && isset( $ptp_projects[ $ptp_project_id ] ) ) : ?>
		<div class="ptp-stat-tile">
			<span class="ptp-stat-value"><?php echo esc_html( ptp_format_duration( PTP_Time_Repository::get_project_total( $ptp_project_id ) ) ); ?></span>
			<span class="ptp-stat-label"><?php esc_html_e( 'Project Total', 'personal-project-tracker' ); ?></span>
		</div>
	<?php endif; ?>
	<?php if ( $ptp_task_id && isset( $ptp_tasks[ $ptp_task_id ] ) ) : ?>
		<div class="ptp-stat-tile">
			<span class="ptp-stat-value"><?php echo esc_html( ptp_format_duration( PTP_Time_Repository::get_task_total( $ptp_task_id ) ) ); ?></span>
			<span class="ptp-stat-label"><?php esc_html_e( 'Task Total', 'personal-project-tracker' ); ?></span>
		</div>
	<?php endif; ?>
</div>

<div class="ptp-card">
	<form method="get" class="ptp-filter-bar">
		<input type="hidden" name="page" value="ptp-time-tracking" />

		<input
			type="search"
			name="s"
			value="<?php echo esc_attr( $ptp_search ); ?>"
			placeholder="<?php esc_attr_e( 'Search descriptions…', 'personal-project-tracker' ); ?>"
		/>

		<select name="project_id">
			<option value=""><?php esc_html_e( 'All projects', 'personal-project-tracker' ); ?></option>
			<?php foreach ( $ptp_projects as $ptp_pid => $ptp_ptitle ) : ?>
				<option value="<?php echo esc_attr( $ptp_pid ); ?>" <?php selected( $ptp_project_id, $ptp_pid ); ?>>
					<?php echo esc_html( $ptp_ptitle ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<select name="task_id">
			<option value=""><?php esc_html_e( 'All tasks', 'personal-project-tracker' ); ?></option>
			<?php foreach ( $ptp_tasks as $ptp_tid => $ptp_trow ) : ?>
				<option value="<?php echo esc_attr( $ptp_tid ); ?>" <?php selected( $ptp_task_id, $ptp_tid ); ?>>
					<?php echo esc_html( $ptp_trow['title'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<select name="status">
			<option value=""><?php esc_html_e( 'All statuses', 'personal-project-tracker' ); ?></option>
			<?php foreach ( $ptp_statuses as $ptp_status_key => $ptp_status_label ) : ?>
				<option value="<?php echo esc_attr( $ptp_status_key ); ?>" <?php selected( $ptp_status, $ptp_status_key ); ?>>
					<?php echo esc_html( $ptp_status_label ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<input type="date" name="date_from" value="<?php echo esc_attr( $ptp_date_from ); ?>" aria-label="<?php esc_attr_e( 'From date', 'personal-project-tracker' ); ?>" />
		<input type="date" name="date_to" value="<?php echo esc_attr( $ptp_date_to ); ?>" aria-label="<?php esc_attr_e( 'To date', 'personal-project-tracker' ); ?>" />

		<button type="submit" class="button"><?php esc_html_e( 'Filter', 'personal-project-tracker' ); ?></button>

		<?php if ( $ptp_has_filters ) : ?>
			<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-time-tracking' ), admin_url( 'admin.php' ) ) ); ?>">
				<?php esc_html_e( 'Reset', 'personal-project-tracker' ); ?>
			</a>
		<?php endif; ?>
	</form>

	<?php if ( empty( $ptp_result['items'] ) ) : ?>

		<div class="ptp-empty-state">
			<span class="dashicons dashicons-clock"></span>
			<?php if ( $ptp_has_filters ) : ?>
				<p><?php esc_html_e( 'No time entries match your search or filters.', 'personal-project-tracker' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'No time entries yet. Start a timer above or log time manually.', 'personal-project-tracker' ); ?></p>
			<?php endif; ?>
		</div>

	<?php else : ?>

		<div class="ptp-table-responsive">
			<table class="widefat striped ptp-time-entries-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'personal-project-tracker' ); ?></th>
						<th class="ptp-col-optional"><?php esc_html_e( 'Project', 'personal-project-tracker' ); ?></th>
						<th class="ptp-col-optional"><?php esc_html_e( 'Task', 'personal-project-tracker' ); ?></th>
						<th class="ptp-col-optional"><?php esc_html_e( 'Description', 'personal-project-tracker' ); ?></th>
						<th class="ptp-col-optional"><?php esc_html_e( 'Start', 'personal-project-tracker' ); ?></th>
						<th class="ptp-col-optional"><?php esc_html_e( 'End', 'personal-project-tracker' ); ?></th>
						<th><?php esc_html_e( 'Duration', 'personal-project-tracker' ); ?></th>
						<th><?php esc_html_e( 'Status', 'personal-project-tracker' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'personal-project-tracker' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $ptp_result['items'] as $ptp_entry ) : ?>
						<?php
						$ptp_is_active = in_array( $ptp_entry->status, array( 'running', 'paused' ), true );
						$ptp_edit_url  = add_query_arg( array( 'page' => 'ptp-time-tracking', 'action' => 'edit', 'id' => $ptp_entry->id ), admin_url( 'admin.php' ) );
						?>
						<tr>
							<td><?php echo esc_html( mysql2date( get_option( 'date_format' ), $ptp_entry->entry_date ) ); ?></td>
							<td class="ptp-col-optional"><?php echo esc_html( $ptp_entry->project_id && isset( $ptp_projects[ (int) $ptp_entry->project_id ] ) ? $ptp_projects[ (int) $ptp_entry->project_id ] : '—' ); ?></td>
							<td class="ptp-col-optional"><?php echo esc_html( $ptp_entry->task_id && isset( $ptp_tasks[ (int) $ptp_entry->task_id ] ) ? $ptp_tasks[ (int) $ptp_entry->task_id ]['title'] : '—' ); ?></td>
							<td class="ptp-col-optional"><?php echo esc_html( $ptp_entry->description ? $ptp_entry->description : '—' ); ?></td>
							<td class="ptp-col-optional"><?php echo esc_html( $ptp_entry->start_time ? mysql2date( get_option( 'time_format' ), $ptp_entry->start_time ) : '—' ); ?></td>
							<td class="ptp-col-optional"><?php echo esc_html( $ptp_entry->end_time ? mysql2date( get_option( 'time_format' ), $ptp_entry->end_time ) : '—' ); ?></td>
							<td><?php echo esc_html( ptp_format_duration( PTP_Time_Repository::get_live_duration( $ptp_entry ) ) ); ?></td>
							<td><span class="ptp-badge ptp-badge-status-<?php echo esc_attr( $ptp_entry->status ); ?>"><?php echo esc_html( $ptp_statuses[ $ptp_entry->status ] ?? $ptp_entry->status ); ?></span></td>
							<td>
								<div class="ptp-quick-actions">
									<?php if ( $ptp_is_active ) : ?>
										<span class="description"><?php esc_html_e( 'Active — use the timer above', 'personal-project-tracker' ); ?></span>
									<?php else : ?>
										<a class="button button-small" href="<?php echo esc_url( $ptp_edit_url ); ?>"><?php esc_html_e( 'Edit', 'personal-project-tracker' ); ?></a>
									<?php endif; ?>
									<button type="button" class="button button-small button-link-delete ptp-js-time-delete" data-id="<?php echo esc_attr( $ptp_entry->id ); ?>">
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
