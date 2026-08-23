<?php
/**
 * Settings > Backup: create/list/download/delete/restore backups, Export
 * (JSON/CSV), and Import (upload -> preview -> confirm).
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ptp_action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

if ( 'import_preview' === $ptp_action ) {
	$ptp_token   = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$ptp_pending = PTP_Import_Service::load_pending( $ptp_token );
	require __DIR__ . '/backup-import-preview.php';
	return;
}

$ptp_backups  = PTP_Backup_Repository::get_all();
$ptp_projects = PTP_Projects_Repository::get_options_for_select();
?>
<h2><?php esc_html_e( 'Backup', 'personal-project-tracker' ); ?></h2>
<p class="description">
	<?php esc_html_e( 'A backup includes Projects, Tasks, Subtasks, Milestones, Calendar, Time, Notes, Links, Finance, Prompts, Templates, Reminders, and Settings. Passwords, tokens, API keys, and secret-shaped text are scrubbed before a backup is ever written to disk. Backup files are stored outside the web root\'s reach and can only be downloaded by a signed-in Settings administrator.', 'personal-project-tracker' ); ?>
</p>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="ptp_create_backup" />
	<?php PTP_Security::nonce_field(); ?>
	<button type="submit" class="button button-primary"><?php esc_html_e( 'Create Backup Now', 'personal-project-tracker' ); ?></button>
</form>

<?php if ( empty( $ptp_backups ) ) : ?>
	<div class="ptp-empty-state">
		<span class="dashicons dashicons-database" aria-hidden="true"></span>
		<p><?php esc_html_e( 'No backups yet.', 'personal-project-tracker' ); ?></p>
	</div>
<?php else : ?>
	<div class="ptp-table-responsive">
		<table class="widefat striped ptp-responsive-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Created', 'personal-project-tracker' ); ?></th>
					<th class="ptp-col-optional"><?php esc_html_e( 'Plugin Version', 'personal-project-tracker' ); ?></th>
					<th class="ptp-col-optional"><?php esc_html_e( 'Size', 'personal-project-tracker' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'personal-project-tracker' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $ptp_backups as $ptp_backup ) : ?>
					<?php
					$ptp_download_url = wp_nonce_url(
						add_query_arg( array( 'action' => 'ptp_download_backup', 'id' => $ptp_backup['id'] ), admin_url( 'admin-post.php' ) ),
						PTP_Security::NONCE_ACTION,
						PTP_Security::NONCE_NAME
					);
					?>
					<tr>
						<td data-label="<?php esc_attr_e( 'Created', 'personal-project-tracker' ); ?>"><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ptp_backup['created_at'] ) ); ?></td>
						<td class="ptp-col-optional" data-label="<?php esc_attr_e( 'Plugin Version', 'personal-project-tracker' ); ?>"><?php echo esc_html( $ptp_backup['plugin_version'] ); ?></td>
						<td class="ptp-col-optional" data-label="<?php esc_attr_e( 'Size', 'personal-project-tracker' ); ?>"><?php echo esc_html( size_format( (int) $ptp_backup['size'] ) ); ?></td>
						<td class="ptp-td-actions">
							<div class="ptp-quick-actions">
								<a class="button button-small" href="<?php echo esc_url( $ptp_download_url ); ?>"><?php esc_html_e( 'Download', 'personal-project-tracker' ); ?></a>

								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ptp-restore-form">
									<input type="hidden" name="action" value="ptp_restore_backup" />
									<input type="hidden" name="id" value="<?php echo esc_attr( $ptp_backup['id'] ); ?>" />
									<?php PTP_Security::nonce_field(); ?>
									<select name="mode" class="ptp-js-restore-mode">
										<option value="merge"><?php esc_html_e( 'Restore (Merge)', 'personal-project-tracker' ); ?></option>
										<option value="replace"><?php esc_html_e( 'Restore (Replace)', 'personal-project-tracker' ); ?></option>
									</select>
									<input type="text" name="confirm_replace" class="ptp-js-restore-confirm" placeholder="<?php esc_attr_e( 'Type REPLACE to confirm', 'personal-project-tracker' ); ?>" style="display:none;" />
									<button type="submit" class="button button-small"><?php esc_html_e( 'Restore', 'personal-project-tracker' ); ?></button>
								</form>

								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ptp-js-delete-backup-form">
									<input type="hidden" name="action" value="ptp_delete_backup" />
									<input type="hidden" name="id" value="<?php echo esc_attr( $ptp_backup['id'] ); ?>" />
									<?php PTP_Security::nonce_field(); ?>
									<button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Delete', 'personal-project-tracker' ); ?></button>
								</form>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<p class="description"><?php esc_html_e( 'Restore (Merge) never overwrites existing data — it only adds records that aren\'t already there. Restore (Replace) permanently deletes all current Projects/Tasks/Milestones/Calendar/Time/Notes/Links/Finance/Prompts/Templates/Reminders and replaces them with this backup\'s contents; it requires typing REPLACE to confirm and cannot be undone.', 'personal-project-tracker' ); ?></p>
<?php endif; ?>

<hr />

<h2><?php esc_html_e( 'Export', 'personal-project-tracker' ); ?></h2>

<h3><?php esc_html_e( 'Export JSON', 'personal-project-tracker' ); ?></h3>
<form method="get" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ptp-filter-bar">
	<input type="hidden" name="action" value="ptp_export_json" />
	<?php echo wp_nonce_field( PTP_Security::NONCE_ACTION, PTP_Security::NONCE_NAME, true, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	<select name="scope" class="ptp-js-export-scope">
		<option value="all"><?php esc_html_e( 'All Data', 'personal-project-tracker' ); ?></option>
		<option value="project"><?php esc_html_e( 'Current Project', 'personal-project-tracker' ); ?></option>
		<option value="date_range"><?php esc_html_e( 'Date Range', 'personal-project-tracker' ); ?></option>
		<option value="module"><?php esc_html_e( 'Module', 'personal-project-tracker' ); ?></option>
	</select>
	<select name="project_id" class="ptp-js-export-project" style="display:none;">
		<option value=""><?php esc_html_e( '— Select a project —', 'personal-project-tracker' ); ?></option>
		<?php foreach ( $ptp_projects as $ptp_pid => $ptp_ptitle ) : ?>
			<option value="<?php echo esc_attr( $ptp_pid ); ?>"><?php echo esc_html( $ptp_ptitle ); ?></option>
		<?php endforeach; ?>
	</select>
	<input type="date" name="date_from" class="ptp-js-export-date" style="display:none;" aria-label="<?php esc_attr_e( 'From date', 'personal-project-tracker' ); ?>" />
	<input type="date" name="date_to" class="ptp-js-export-date" style="display:none;" aria-label="<?php esc_attr_e( 'To date', 'personal-project-tracker' ); ?>" />
	<select name="export_module" class="ptp-js-export-module" style="display:none;">
		<?php foreach ( PTP_Backup_Service::TABLES as $ptp_short => $ptp_label_key ) : ?>
			<option value="<?php echo esc_attr( $ptp_short ); ?>"><?php echo esc_html( $ptp_label_key ); ?></option>
		<?php endforeach; ?>
	</select>
	<button type="submit" class="button"><?php esc_html_e( 'Export JSON', 'personal-project-tracker' ); ?></button>
</form>

<h3><?php esc_html_e( 'Export CSV', 'personal-project-tracker' ); ?></h3>
<form method="get" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ptp-filter-bar">
	<input type="hidden" name="action" value="ptp_export_csv" />
	<?php echo wp_nonce_field( PTP_Security::NONCE_ACTION, PTP_Security::NONCE_NAME, true, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	<select name="module">
		<?php foreach ( array_keys( PTP_Export_Service::MODULE_TABLES ) as $ptp_csv_module ) : ?>
			<option value="<?php echo esc_attr( $ptp_csv_module ); ?>"><?php echo esc_html( ucfirst( $ptp_csv_module ) ); ?></option>
		<?php endforeach; ?>
	</select>
	<select name="scope" class="ptp-js-export-scope">
		<option value="all"><?php esc_html_e( 'All Data', 'personal-project-tracker' ); ?></option>
		<option value="project"><?php esc_html_e( 'Current Project', 'personal-project-tracker' ); ?></option>
		<option value="date_range"><?php esc_html_e( 'Date Range', 'personal-project-tracker' ); ?></option>
	</select>
	<select name="project_id" class="ptp-js-export-project" style="display:none;">
		<option value=""><?php esc_html_e( '— Select a project —', 'personal-project-tracker' ); ?></option>
		<?php foreach ( $ptp_projects as $ptp_pid => $ptp_ptitle ) : ?>
			<option value="<?php echo esc_attr( $ptp_pid ); ?>"><?php echo esc_html( $ptp_ptitle ); ?></option>
		<?php endforeach; ?>
	</select>
	<input type="date" name="date_from" class="ptp-js-export-date" style="display:none;" aria-label="<?php esc_attr_e( 'From date', 'personal-project-tracker' ); ?>" />
	<input type="date" name="date_to" class="ptp-js-export-date" style="display:none;" aria-label="<?php esc_attr_e( 'To date', 'personal-project-tracker' ); ?>" />
	<button type="submit" class="button"><?php esc_html_e( 'Export CSV', 'personal-project-tracker' ); ?></button>
</form>

<hr />

<h2><?php esc_html_e( 'Import', 'personal-project-tracker' ); ?></h2>
<p class="description"><?php esc_html_e( 'Upload a Personal Project Tracker backup or JSON export. You will see a preview — counts, warnings, and any rows that will be skipped — before anything is imported.', 'personal-project-tracker' ); ?></p>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
	<input type="hidden" name="action" value="ptp_import_upload" />
	<?php PTP_Security::nonce_field(); ?>
	<input type="file" name="import_file" accept="application/json,.json" required />
	<button type="submit" class="button button-primary"><?php esc_html_e( 'Upload &amp; Preview', 'personal-project-tracker' ); ?></button>
</form>
