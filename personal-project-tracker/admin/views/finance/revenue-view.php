<?php
/**
 * Revenue detail page.
 *
 * @package Personal_Project_Tracker
 *
 * @var object|null $revenue Revenue row, or null when not found.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! $revenue ) :
	?>
	<h1><?php esc_html_e( 'Revenue Not Found', 'personal-project-tracker' ); ?></h1>
	<div class="ptp-card">
		<p><?php esc_html_e( 'That revenue entry does not exist or may have been deleted.', 'personal-project-tracker' ); ?></p>
		<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-finance' ), admin_url( 'admin.php' ) ) ); ?>">
			<?php esc_html_e( '&laquo; Back to Finance', 'personal-project-tracker' ); ?>
		</a>
	</div>
	<?php
	return;
endif;

$ptp_project  = $revenue->project_id ? PTP_Projects_Repository::get( $revenue->project_id ) : null;
$ptp_list_url = add_query_arg( array( 'page' => 'ptp-finance' ), admin_url( 'admin.php' ) );
$ptp_edit_url = add_query_arg( array( 'page' => 'ptp-finance', 'action' => 'edit_revenue', 'id' => $revenue->id ), admin_url( 'admin.php' ) );
$ptp_activity = PTP_Activity_Log::get_for_object( 'revenue', $revenue->id, 15 );

$ptp_breadcrumbs = array(
	array( 'label' => __( 'Project Tracker', 'personal-project-tracker' ), 'url' => add_query_arg( array( 'page' => 'ptp-dashboard' ), admin_url( 'admin.php' ) ) ),
	array( 'label' => __( 'Finance', 'personal-project-tracker' ), 'url' => $ptp_list_url ),
	array( 'label' => ptp_format_currency( $revenue->amount, $revenue->currency ) ),
);
require PTP_PLUGIN_DIR . 'admin/views/partials/breadcrumbs.php';
?>
<div class="ptp-page-header">
	<h1><?php echo esc_html( ptp_format_currency( $revenue->amount, $revenue->currency ) ); ?></h1>
	<div class="ptp-quick-actions">
		<a class="button" href="<?php echo esc_url( $ptp_list_url ); ?>"><?php esc_html_e( '&laquo; Back to Finance', 'personal-project-tracker' ); ?></a>
		<a class="button button-primary" href="<?php echo esc_url( $ptp_edit_url ); ?>"><?php esc_html_e( 'Edit', 'personal-project-tracker' ); ?></a>
		<button type="button" class="button button-link-delete ptp-js-revenue-delete" data-id="<?php echo esc_attr( $revenue->id ); ?>" data-redirect="<?php echo esc_url( $ptp_list_url ); ?>">
			<?php esc_html_e( 'Delete', 'personal-project-tracker' ); ?>
		</button>
	</div>
</div>

<div class="ptp-detail-grid">
	<div class="ptp-detail-main">

		<div class="ptp-card">
			<h2><?php esc_html_e( 'Overview', 'personal-project-tracker' ); ?></h2>
			<?php if ( $revenue->description ) : ?>
				<div class="ptp-project-description"><?php echo esc_html( $revenue->description ); ?></div>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'No description provided.', 'personal-project-tracker' ); ?></p>
			<?php endif; ?>

			<table class="widefat striped ptp-status-table">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Date', 'personal-project-tracker' ); ?></th>
						<td><?php echo esc_html( mysql2date( get_option( 'date_format' ), $revenue->revenue_date ) ); ?></td>
					</tr>
					<?php if ( $revenue->category ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Category', 'personal-project-tracker' ); ?></th>
							<td><?php echo esc_html( $revenue->category ); ?></td>
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
