<?php
/**
 * Dashboard view — Phase 1 foundation status.
 *
 * Feature widgets (project/task counts, revenue, activity, quick actions,
 * etc.) are added on top of this scaffold as their owning modules are
 * implemented in later phases.
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
<p class="description">
	<?php esc_html_e( 'Foundation installed. Feature modules (Projects, Tasks, Finance, Reports, and the rest) are added phase by phase.', 'personal-project-tracker' ); ?>
</p>

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
			<?php foreach ( $tables as $table ) : ?>
				<?php
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema introspection, not user data.
				$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
				?>
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
