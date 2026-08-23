<?php
/**
 * Link detail page.
 *
 * @package Personal_Project_Tracker
 *
 * @var object|null $link Link row, or null when not found.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! $link ) :
	?>
	<h1><?php esc_html_e( 'Link Not Found', 'personal-project-tracker' ); ?></h1>
	<div class="ptp-card">
		<p><?php esc_html_e( 'That link does not exist or may have been deleted.', 'personal-project-tracker' ); ?></p>
		<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-links' ), admin_url( 'admin.php' ) ) ); ?>">
			<?php esc_html_e( '&laquo; Back to Links', 'personal-project-tracker' ); ?>
		</a>
	</div>
	<?php
	return;
endif;

$ptp_project   = $link->project_id ? PTP_Projects_Repository::get( $link->project_id ) : null;
$ptp_task      = $link->task_id ? PTP_Tasks_Repository::get( $link->task_id ) : null;
$ptp_milestone = $link->milestone_id ? PTP_Milestones_Repository::get( $link->milestone_id ) : null;
$ptp_list_url  = add_query_arg( array( 'page' => 'ptp-links' ), admin_url( 'admin.php' ) );
$ptp_edit_url  = add_query_arg( array( 'page' => 'ptp-links', 'action' => 'edit', 'id' => $link->id ), admin_url( 'admin.php' ) );
$ptp_activity  = PTP_Activity_Log::get_for_object( 'link', $link->id, 15 );

$ptp_breadcrumbs = array(
	array( 'label' => __( 'Project Tracker', 'personal-project-tracker' ), 'url' => add_query_arg( array( 'page' => 'ptp-dashboard' ), admin_url( 'admin.php' ) ) ),
	array( 'label' => __( 'Links', 'personal-project-tracker' ), 'url' => $ptp_list_url ),
	array( 'label' => $link->title ),
);
require PTP_PLUGIN_DIR . 'admin/views/partials/breadcrumbs.php';
?>
<div class="ptp-page-header">
	<h1><?php echo esc_html( $link->title ); ?></h1>
	<div class="ptp-quick-actions">
		<a class="button" href="<?php echo esc_url( $ptp_list_url ); ?>"><?php esc_html_e( '&laquo; Back to Links', 'personal-project-tracker' ); ?></a>
		<a class="button button-primary" href="<?php echo esc_url( $ptp_edit_url ); ?>"><?php esc_html_e( 'Edit', 'personal-project-tracker' ); ?></a>
		<a class="button" href="<?php echo esc_url( $link->url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open Link', 'personal-project-tracker' ); ?></a>
		<button type="button" class="button button-link-delete ptp-js-link-delete" data-id="<?php echo esc_attr( $link->id ); ?>" data-redirect="<?php echo esc_url( $ptp_list_url ); ?>">
			<?php esc_html_e( 'Delete', 'personal-project-tracker' ); ?>
		</button>
	</div>
</div>

<div class="ptp-detail-grid">
	<div class="ptp-detail-main">

		<div class="ptp-card">
			<h2><?php esc_html_e( 'Overview', 'personal-project-tracker' ); ?></h2>
			<?php if ( $link->description ) : ?>
				<div class="ptp-project-description"><?php echo esc_html( $link->description ); ?></div>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'No description provided.', 'personal-project-tracker' ); ?></p>
			<?php endif; ?>

			<table class="widefat striped ptp-status-table">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'URL', 'personal-project-tracker' ); ?></th>
						<td><a href="<?php echo esc_url( $link->url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $link->url ); ?></a></td>
					</tr>
					<?php if ( $link->category ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Category', 'personal-project-tracker' ); ?></th>
							<td><?php echo esc_html( $link->category ); ?></td>
						</tr>
					<?php endif; ?>
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
						<th scope="row"><?php esc_html_e( 'Task', 'personal-project-tracker' ); ?></th>
						<td>
							<?php if ( $ptp_task ) : ?>
								<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-tasks', 'action' => 'view', 'id' => $ptp_task->id ), admin_url( 'admin.php' ) ) ); ?>">
									<?php echo esc_html( $ptp_task->title ); ?>
								</a>
							<?php else : ?>
								&#8212;
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Milestone', 'personal-project-tracker' ); ?></th>
						<td>
							<?php if ( $ptp_milestone ) : ?>
								<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-milestones', 'action' => 'view', 'id' => $ptp_milestone->id ), admin_url( 'admin.php' ) ) ); ?>">
									<?php echo esc_html( $ptp_milestone->title ); ?>
								</a>
							<?php else : ?>
								&#8212;
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<div class="ptp-card">
			<h2><?php esc_html_e( 'Recent Activity', 'personal-project-tracker' ); ?></h2>
			<?php if ( empty( $ptp_activity ) ) : ?>
				<p class="description"><?php esc_html_e( 'No activity recorded yet.', 'personal-project-tracker' ); ?></p>
			<?php else : ?>
				<ul class="ptp-activity-list">
					<?php foreach ( $ptp_activity as $ptp_entry ) : ?>
						<li>
							<span class="ptp-activity-desc"><?php echo esc_html( $ptp_entry->description ? $ptp_entry->description : $ptp_entry->action ); ?></span>
							<span class="ptp-activity-date"><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ptp_entry->created_at ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>

	</div>
</div>
