<?php
/**
 * Files list page: search, filter, paginate, download/detach.
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
$ptp_note_id      = isset( $_GET['note_id'] ) ? absint( $_GET['note_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_paged        = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$ptp_result = PTP_Files_Repository::get_list(
	array(
		'search'       => $ptp_search,
		'project_id'   => $ptp_project_id,
		'task_id'      => $ptp_task_id,
		'milestone_id' => $ptp_milestone_id,
		'note_id'      => $ptp_note_id,
		'paged'        => $ptp_paged,
		'per_page'     => 20,
	)
);
$ptp_projects    = PTP_Projects_Repository::get_options_for_select();
$ptp_has_filters = $ptp_search || $ptp_project_id || $ptp_task_id || $ptp_milestone_id || $ptp_note_id;
?>
<div class="ptp-page-header">
	<h1><?php esc_html_e( 'Files', 'personal-project-tracker' ); ?></h1>
	<?php if ( current_user_can( 'upload_files' ) ) : ?>
		<a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-files', 'action' => 'new' ), admin_url( 'admin.php' ) ) ); ?>">
			<?php esc_html_e( '+ Attach File', 'personal-project-tracker' ); ?>
		</a>
	<?php endif; ?>
</div>

<div class="ptp-card">
	<form method="get" class="ptp-filter-bar">
		<input type="hidden" name="page" value="ptp-files" />

		<input
			type="search"
			name="s"
			value="<?php echo esc_attr( $ptp_search ); ?>"
			placeholder="<?php esc_attr_e( 'Search file name or type…', 'personal-project-tracker' ); ?>"
		/>

		<select name="project_id">
			<option value=""><?php esc_html_e( 'All projects', 'personal-project-tracker' ); ?></option>
			<?php foreach ( $ptp_projects as $ptp_pid => $ptp_ptitle ) : ?>
				<option value="<?php echo esc_attr( $ptp_pid ); ?>" <?php selected( $ptp_project_id, $ptp_pid ); ?>>
					<?php echo esc_html( $ptp_ptitle ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<button type="submit" class="button"><?php esc_html_e( 'Filter', 'personal-project-tracker' ); ?></button>

		<?php if ( $ptp_has_filters ) : ?>
			<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-files' ), admin_url( 'admin.php' ) ) ); ?>">
				<?php esc_html_e( 'Reset', 'personal-project-tracker' ); ?>
			</a>
		<?php endif; ?>
	</form>

	<?php if ( empty( $ptp_result['items'] ) ) : ?>

		<div class="ptp-empty-state">
			<span class="dashicons dashicons-media-default"></span>
			<?php if ( $ptp_has_filters ) : ?>
				<p><?php esc_html_e( 'No files match your search or filters.', 'personal-project-tracker' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'No files attached yet.', 'personal-project-tracker' ); ?></p>
			<?php endif; ?>
		</div>

	<?php else : ?>

		<div class="ptp-table-responsive">
			<table class="widefat striped ptp-files-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'File', 'personal-project-tracker' ); ?></th>
						<th class="ptp-col-optional"><?php esc_html_e( 'Type', 'personal-project-tracker' ); ?></th>
						<th class="ptp-col-optional"><?php esc_html_e( 'Size', 'personal-project-tracker' ); ?></th>
						<th class="ptp-col-optional"><?php esc_html_e( 'Project', 'personal-project-tracker' ); ?></th>
						<th class="ptp-col-optional"><?php esc_html_e( 'Attached', 'personal-project-tracker' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'personal-project-tracker' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $ptp_result['items'] as $ptp_file ) : ?>
						<?php $ptp_file_url = wp_get_attachment_url( $ptp_file->attachment_id ); ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-files', 'action' => 'view', 'id' => $ptp_file->id ), admin_url( 'admin.php' ) ) ); ?>">
									<strong><?php echo esc_html( $ptp_file->file_name ? $ptp_file->file_name : __( '(file)', 'personal-project-tracker' ) ); ?></strong>
								</a>
							</td>
							<td class="ptp-col-optional"><?php echo esc_html( $ptp_file->file_type ? $ptp_file->file_type : '—' ); ?></td>
							<td class="ptp-col-optional"><?php echo esc_html( null !== $ptp_file->file_size ? size_format( (int) $ptp_file->file_size ) : '—' ); ?></td>
							<td class="ptp-col-optional"><?php echo esc_html( $ptp_file->project_id && isset( $ptp_projects[ (int) $ptp_file->project_id ] ) ? $ptp_projects[ (int) $ptp_file->project_id ] : '—' ); ?></td>
							<td class="ptp-col-optional"><?php echo esc_html( mysql2date( get_option( 'date_format' ), $ptp_file->created_at ) ); ?></td>
							<td>
								<div class="ptp-quick-actions">
									<?php if ( $ptp_file_url ) : ?>
										<a class="button button-small" href="<?php echo esc_url( $ptp_file_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Download', 'personal-project-tracker' ); ?></a>
									<?php endif; ?>
									<button type="button" class="button button-small button-link-delete ptp-js-file-detach" data-id="<?php echo esc_attr( $ptp_file->id ); ?>">
										<?php esc_html_e( 'Detach', 'personal-project-tracker' ); ?>
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
