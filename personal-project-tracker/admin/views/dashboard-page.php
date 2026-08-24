<?php
/**
 * Dashboard view.
 *
 * Feature widgets from each module sit above the System Status / Database
 * Tables cards.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$tables = PTP_Database::get_table_names();
?>
<h1><?php esc_html_e( 'Project Tracker', 'personal-project-tracker' ); ?></h1>
<p class="description ptp-dashboard-intro">
	<?php esc_html_e( 'Your private project management workspace.', 'personal-project-tracker' ); ?>
</p>

<?php if ( current_user_can( 'ptp_manage_projects' ) && class_exists( 'PTP_Projects_Repository' ) ) : ?>

	<div class="ptp-dashboard-section">

	<?php
	$ptp_dashboard_stats   = PTP_Projects_Repository::get_stats();
	$ptp_dashboard_recent  = PTP_Projects_Repository::get_recent( 5 );
	$ptp_dashboard_statuses = PTP_Projects_Repository::get_statuses();
	?>

	<div class="ptp-page-header">
		<h2><?php esc_html_e( 'Projects', 'personal-project-tracker' ); ?></h2>
		<a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-projects', 'action' => 'new' ), admin_url( 'admin.php' ) ) ); ?>">
			<?php esc_html_e( '+ New Project', 'personal-project-tracker' ); ?>
		</a>
	</div>

	<div class="ptp-stats-grid">
		<div class="ptp-stat-tile">
			<span class="ptp-stat-value"><?php echo esc_html( number_format_i18n( $ptp_dashboard_stats['total'] ) ); ?></span>
			<span class="ptp-stat-label"><?php esc_html_e( 'Total Projects', 'personal-project-tracker' ); ?></span>
		</div>
		<div class="ptp-stat-tile">
			<span class="ptp-stat-value"><?php echo esc_html( number_format_i18n( $ptp_dashboard_stats['active'] ) ); ?></span>
			<span class="ptp-stat-label"><?php esc_html_e( 'Active Projects', 'personal-project-tracker' ); ?></span>
		</div>
		<div class="ptp-stat-tile">
			<span class="ptp-stat-value"><?php echo esc_html( number_format_i18n( $ptp_dashboard_stats['completed'] ) ); ?></span>
			<span class="ptp-stat-label"><?php esc_html_e( 'Completed Projects', 'personal-project-tracker' ); ?></span>
		</div>
		<div class="ptp-stat-tile ptp-stat-tile-warning">
			<span class="ptp-stat-value"><?php echo esc_html( number_format_i18n( $ptp_dashboard_stats['overdue'] ) ); ?></span>
			<span class="ptp-stat-label"><?php esc_html_e( 'Overdue Projects', 'personal-project-tracker' ); ?></span>
		</div>
	</div>

	<div class="ptp-card">
		<h2><?php esc_html_e( 'Recent Projects', 'personal-project-tracker' ); ?></h2>
		<?php if ( empty( $ptp_dashboard_recent ) ) : ?>
			<div class="ptp-empty-state">
				<span class="dashicons dashicons-portfolio" aria-hidden="true"></span>
				<p><?php esc_html_e( 'No projects yet. Create your first project to get started.', 'personal-project-tracker' ); ?></p>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-projects', 'action' => 'new' ), admin_url( 'admin.php' ) ) ); ?>">
						<?php esc_html_e( '+ Add New Project', 'personal-project-tracker' ); ?>
					</a>
				</p>
			</div>
		<?php else : ?>
			<div class="ptp-table-responsive">
				<table class="widefat striped ptp-projects-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Title', 'personal-project-tracker' ); ?></th>
							<th><?php esc_html_e( 'Status', 'personal-project-tracker' ); ?></th>
							<th class="ptp-col-optional"><?php esc_html_e( 'Progress', 'personal-project-tracker' ); ?></th>
							<th class="ptp-col-optional"><?php esc_html_e( 'Updated', 'personal-project-tracker' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $ptp_dashboard_recent as $ptp_recent_project ) : ?>
							<tr>
								<td>
									<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-projects', 'action' => 'view', 'id' => $ptp_recent_project->id ), admin_url( 'admin.php' ) ) ); ?>">
										<strong><?php echo esc_html( $ptp_recent_project->title ); ?></strong>
									</a>
								</td>
								<td><span class="ptp-badge ptp-badge-status-<?php echo esc_attr( $ptp_recent_project->status ); ?>"><?php echo esc_html( $ptp_dashboard_statuses[ $ptp_recent_project->status ] ?? $ptp_recent_project->status ); ?></span></td>
								<td class="ptp-col-optional"><?php echo esc_html( (int) $ptp_recent_project->progress ); ?>%</td>
								<td class="ptp-col-optional"><?php echo esc_html( mysql2date( get_option( 'date_format' ), $ptp_recent_project->updated_at ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<p>
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-projects' ), admin_url( 'admin.php' ) ) ); ?>">
					<?php esc_html_e( 'View all projects &raquo;', 'personal-project-tracker' ); ?>
				</a>
			</p>
		<?php endif; ?>
	</div>

	</div>
<?php endif; ?>

<?php if ( current_user_can( 'ptp_manage_tasks' ) && class_exists( 'PTP_Tasks_Repository' ) ) : ?>

	<div class="ptp-dashboard-section">

	<?php $ptp_task_stats = PTP_Tasks_Repository::get_stats(); ?>

	<div class="ptp-page-header">
		<h2><?php esc_html_e( 'Tasks', 'personal-project-tracker' ); ?></h2>
		<a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-tasks', 'action' => 'new' ), admin_url( 'admin.php' ) ) ); ?>">
			<?php esc_html_e( '+ New Task', 'personal-project-tracker' ); ?>
		</a>
	</div>

	<div class="ptp-stats-grid">
		<div class="ptp-stat-tile">
			<span class="ptp-stat-value"><?php echo esc_html( number_format_i18n( $ptp_task_stats['today'] ) ); ?></span>
			<span class="ptp-stat-label"><?php esc_html_e( 'Tasks Today', 'personal-project-tracker' ); ?></span>
		</div>
		<div class="ptp-stat-tile ptp-stat-tile-warning">
			<span class="ptp-stat-value"><?php echo esc_html( number_format_i18n( $ptp_task_stats['overdue'] ) ); ?></span>
			<span class="ptp-stat-label"><?php esc_html_e( 'Overdue Tasks', 'personal-project-tracker' ); ?></span>
		</div>
		<div class="ptp-stat-tile">
			<span class="ptp-stat-value"><?php echo esc_html( number_format_i18n( $ptp_task_stats['in_progress'] ) ); ?></span>
			<span class="ptp-stat-label"><?php esc_html_e( 'Tasks In Progress', 'personal-project-tracker' ); ?></span>
		</div>
		<div class="ptp-stat-tile">
			<span class="ptp-stat-value"><?php echo esc_html( number_format_i18n( $ptp_task_stats['completed'] ) ); ?></span>
			<span class="ptp-stat-label"><?php esc_html_e( 'Completed Tasks', 'personal-project-tracker' ); ?></span>
		</div>
	</div>

	</div>
<?php endif; ?>

<?php if ( current_user_can( 'ptp_manage_projects' ) && class_exists( 'PTP_Milestones_Repository' ) ) : ?>

	<div class="ptp-dashboard-section">

	<?php
	$ptp_milestone_stats = PTP_Milestones_Repository::get_stats();
	$ptp_upcoming_milestones = PTP_Milestones_Repository::get_upcoming( 5 );
	$ptp_milestone_statuses  = PTP_Milestones_Repository::get_statuses();
	?>

	<div class="ptp-page-header">
		<h2><?php esc_html_e( 'Milestones', 'personal-project-tracker' ); ?></h2>
		<a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-milestones', 'action' => 'new' ), admin_url( 'admin.php' ) ) ); ?>">
			<?php esc_html_e( '+ New Milestone', 'personal-project-tracker' ); ?>
		</a>
	</div>

	<div class="ptp-stats-grid">
		<div class="ptp-stat-tile">
			<span class="ptp-stat-value"><?php echo esc_html( number_format_i18n( $ptp_milestone_stats['upcoming'] ) ); ?></span>
			<span class="ptp-stat-label"><?php esc_html_e( 'Upcoming Milestones', 'personal-project-tracker' ); ?></span>
		</div>
		<div class="ptp-stat-tile ptp-stat-tile-warning">
			<span class="ptp-stat-value"><?php echo esc_html( number_format_i18n( $ptp_milestone_stats['overdue'] ) ); ?></span>
			<span class="ptp-stat-label"><?php esc_html_e( 'Overdue Milestones', 'personal-project-tracker' ); ?></span>
		</div>
		<div class="ptp-stat-tile">
			<span class="ptp-stat-value"><?php echo esc_html( number_format_i18n( $ptp_milestone_stats['completed'] ) ); ?></span>
			<span class="ptp-stat-label"><?php esc_html_e( 'Completed Milestones', 'personal-project-tracker' ); ?></span>
		</div>
	</div>

	<?php if ( ! empty( $ptp_upcoming_milestones ) ) : ?>
		<div class="ptp-card">
			<h2><?php esc_html_e( 'Upcoming Milestones', 'personal-project-tracker' ); ?></h2>
			<ul class="ptp-simple-list">
				<?php foreach ( $ptp_upcoming_milestones as $ptp_upcoming ) : ?>
					<li>
						<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-milestones', 'action' => 'view', 'id' => $ptp_upcoming->id ), admin_url( 'admin.php' ) ) ); ?>">
							<?php echo esc_html( $ptp_upcoming->title ); ?>
						</a>
						<span>
							<span class="ptp-badge ptp-badge-status-<?php echo esc_attr( $ptp_upcoming->status ); ?>"><?php echo esc_html( $ptp_milestone_statuses[ $ptp_upcoming->status ] ?? $ptp_upcoming->status ); ?></span>
							<?php echo esc_html( mysql2date( get_option( 'date_format' ), $ptp_upcoming->due_date ) ); ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	</div>
<?php endif; ?>

<div class="ptp-card">
	<h2><?php esc_html_e( 'System Status', 'personal-project-tracker' ); ?></h2>
	<table class="widefat striped ptp-status-table">
		<tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Plugin Version', 'personal-project-tracker' ); ?></th>
				<td><?php echo esc_html( PTP_VERSION ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Database Version', 'personal-project-tracker' ); ?></th>
				<td><?php echo esc_html( (string) get_option( PTP_Database::DB_VERSION_OPTION, 0 ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Mode', 'personal-project-tracker' ); ?></th>
				<td><?php esc_html_e( 'Private Personal Mode', 'personal-project-tracker' ); ?></td>
			</tr>
		</tbody>
	</table>
</div>

<div class="ptp-card">
	<h2><?php esc_html_e( 'Database Tables', 'personal-project-tracker' ); ?></h2>
	<table class="widefat striped ptp-status-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Table', 'personal-project-tracker' ); ?></th>
				<th><?php esc_html_e( 'Status', 'personal-project-tracker' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php
			// One query for every table's existence instead of a SHOW TABLES
			// LIKE per table (17 round trips on every Dashboard load, for a
			// card that rarely changes).
			$ptp_table_placeholders = implode( ',', array_fill( 0, count( $tables ), '%s' ) );
			$ptp_existing_tables    = $wpdb->get_col( $wpdb->prepare( "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ({$ptp_table_placeholders})", $tables ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- schema introspection, not user data.
			$ptp_existing_tables    = array_flip( $ptp_existing_tables );
			?>
			<?php foreach ( $tables as $table ) : ?>
				<?php $exists = isset( $ptp_existing_tables[ $table ] ); ?>
				<tr>
					<td><code><?php echo esc_html( $table ); ?></code></td>
					<td>
						<?php if ( $exists ) : ?>
							<span class="ptp-badge ptp-badge-success"><?php esc_html_e( 'Ready', 'personal-project-tracker' ); ?></span>
						<?php else : ?>
							<span class="ptp-badge ptp-badge-error"><?php esc_html_e( 'Missing', 'personal-project-tracker' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
