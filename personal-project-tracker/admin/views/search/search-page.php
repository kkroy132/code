<?php
/**
 * Global Search page: one search box across every module, with
 * Type/Project/Status/Priority/Date filters, pagination, and a
 * click-through to each result's own detail page.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ptp_search    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_type      = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_project_id = isset( $_GET['project_id'] ) ? absint( $_GET['project_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_status    = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_priority  = isset( $_GET['priority'] ) ? sanitize_key( wp_unslash( $_GET['priority'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_date_to   = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_paged     = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$ptp_has_query = '' !== $ptp_search || $ptp_type || $ptp_project_id || $ptp_status || $ptp_priority || $ptp_date_from || $ptp_date_to;

$ptp_result = $ptp_has_query
	? PTP_Search_Service::search(
		array(
			'search'     => $ptp_search,
			'type'       => $ptp_type,
			'project_id' => $ptp_project_id,
			'status'     => $ptp_status,
			'priority'   => $ptp_priority,
			'date_from'  => $ptp_date_from,
			'date_to'    => $ptp_date_to,
			'paged'      => $ptp_paged,
			'per_page'   => 20,
		)
	)
	: array( 'items' => array(), 'total' => 0, 'total_pages' => 0, 'page' => 1, 'per_page' => 20 );

$ptp_type_labels = array(
	'project'   => __( 'Project', 'personal-project-tracker' ),
	'task'      => __( 'Task', 'personal-project-tracker' ),
	'subtask'   => __( 'Subtask', 'personal-project-tracker' ),
	'milestone' => __( 'Milestone', 'personal-project-tracker' ),
	'note'      => __( 'Note', 'personal-project-tracker' ),
	'link'      => __( 'Link', 'personal-project-tracker' ),
	'file'      => __( 'File', 'personal-project-tracker' ),
	'finance'   => __( 'Finance', 'personal-project-tracker' ),
	'prompt'    => __( 'Prompt', 'personal-project-tracker' ),
	'reminder'  => __( 'Reminder', 'personal-project-tracker' ),
	'activity'  => __( 'Activity', 'personal-project-tracker' ),
);

$ptp_projects   = PTP_Projects_Repository::get_options_for_select();
$ptp_statuses   = PTP_Projects_Repository::get_statuses() + PTP_Tasks_Repository::get_statuses() + PTP_Milestones_Repository::get_statuses() + PTP_Reminders_Repository::get_statuses();
$ptp_priorities = PTP_Tasks_Repository::get_priorities(); // Shared vocabulary across Projects/Tasks/Milestones.

$ptp_breadcrumbs = array(
	array(
		'label' => __( 'Project Tracker', 'personal-project-tracker' ),
		'url'   => add_query_arg( array( 'page' => 'ptp-dashboard' ), admin_url( 'admin.php' ) ),
	),
	array( 'label' => __( 'Search', 'personal-project-tracker' ) ),
);
require PTP_PLUGIN_DIR . 'admin/views/partials/breadcrumbs.php';
?>
<div class="ptp-page-header">
	<h1><?php esc_html_e( 'Search', 'personal-project-tracker' ); ?></h1>
</div>

<div class="ptp-card">
	<form method="get" class="ptp-filter-bar ptp-search-filter-bar">
		<input type="hidden" name="page" value="ptp-search" />

		<input
			type="search"
			name="s"
			value="<?php echo esc_attr( $ptp_search ); ?>"
			placeholder="<?php esc_attr_e( 'Search projects, tasks, notes, finance, and more…', 'personal-project-tracker' ); ?>"
			autofocus="autofocus"
		/>

		<select name="type">
			<option value=""><?php esc_html_e( 'All types', 'personal-project-tracker' ); ?></option>
			<?php foreach ( $ptp_type_labels as $ptp_type_key => $ptp_type_label ) : ?>
				<option value="<?php echo esc_attr( $ptp_type_key ); ?>" <?php selected( $ptp_type, $ptp_type_key ); ?>>
					<?php echo esc_html( $ptp_type_label ); ?>
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

		<input type="date" name="date_from" value="<?php echo esc_attr( $ptp_date_from ); ?>" aria-label="<?php esc_attr_e( 'From date', 'personal-project-tracker' ); ?>" />
		<input type="date" name="date_to" value="<?php echo esc_attr( $ptp_date_to ); ?>" aria-label="<?php esc_attr_e( 'To date', 'personal-project-tracker' ); ?>" />

		<button type="submit" class="button button-primary"><?php esc_html_e( 'Search', 'personal-project-tracker' ); ?></button>

		<?php if ( $ptp_has_query ) : ?>
			<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-search' ), admin_url( 'admin.php' ) ) ); ?>">
				<?php esc_html_e( 'Reset', 'personal-project-tracker' ); ?>
			</a>
		<?php endif; ?>
	</form>

	<?php if ( ! $ptp_has_query ) : ?>

		<div class="ptp-empty-state">
			<span class="dashicons dashicons-search" aria-hidden="true"></span>
			<p><?php esc_html_e( 'Search across Projects, Tasks, Subtasks, Milestones, Notes, Links, Files, Finance, Prompts, Reminders, and Activity.', 'personal-project-tracker' ); ?></p>
		</div>

	<?php elseif ( empty( $ptp_result['items'] ) ) : ?>

		<div class="ptp-empty-state">
			<span class="dashicons dashicons-search" aria-hidden="true"></span>
			<p><?php esc_html_e( 'No results match your search or filters.', 'personal-project-tracker' ); ?></p>
		</div>

	<?php else : ?>

		<p class="description">
			<?php
			printf(
				/* translators: %s: number of matching results. */
				esc_html( _n( '%s result', '%s results', $ptp_result['total'], 'personal-project-tracker' ) ),
				esc_html( number_format_i18n( $ptp_result['total'] ) )
			);
			?>
		</p>

		<div class="ptp-table-responsive">
			<table class="widefat striped ptp-search-table ptp-responsive-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Type', 'personal-project-tracker' ); ?></th>
						<th><?php esc_html_e( 'Title', 'personal-project-tracker' ); ?></th>
						<th class="ptp-col-optional"><?php esc_html_e( 'Project', 'personal-project-tracker' ); ?></th>
						<th class="ptp-col-optional"><?php esc_html_e( 'Status', 'personal-project-tracker' ); ?></th>
						<th><?php esc_html_e( 'Date', 'personal-project-tracker' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $ptp_result['items'] as $ptp_item ) : ?>
						<tr>
							<td data-label="<?php esc_attr_e( 'Type', 'personal-project-tracker' ); ?>"><span class="ptp-badge"><?php echo esc_html( $ptp_type_labels[ $ptp_item['type'] ] ?? $ptp_item['type'] ); ?></span></td>
							<td data-label="<?php esc_attr_e( 'Title', 'personal-project-tracker' ); ?>"><a href="<?php echo esc_url( $ptp_item['url'] ); ?>"><strong><?php echo esc_html( $ptp_item['title'] ); ?></strong></a></td>
							<td class="ptp-col-optional" data-label="<?php esc_attr_e( 'Project', 'personal-project-tracker' ); ?>">
								<?php if ( 'project' !== $ptp_item['type'] && $ptp_item['project_id'] && isset( $ptp_projects[ $ptp_item['project_id'] ] ) ) : ?>
									<?php echo esc_html( $ptp_projects[ $ptp_item['project_id'] ] ); ?>
								<?php else : ?>
									&#8212;
								<?php endif; ?>
							</td>
							<td class="ptp-col-optional" data-label="<?php esc_attr_e( 'Status', 'personal-project-tracker' ); ?>">
								<?php if ( $ptp_item['status_label'] ) : ?>
									<span class="ptp-badge ptp-badge-status-<?php echo esc_attr( $ptp_item['status'] ); ?>"><?php echo esc_html( $ptp_item['status_label'] ); ?></span>
								<?php else : ?>
									&#8212;
								<?php endif; ?>
							</td>
							<td data-label="<?php esc_attr_e( 'Date', 'personal-project-tracker' ); ?>"><?php echo esc_html( mysql2date( get_option( 'date_format' ), $ptp_item['date'] ) ); ?></td>
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
