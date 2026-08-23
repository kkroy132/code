<?php
/**
 * Import preview/confirm step.
 *
 * @package Personal_Project_Tracker
 *
 * @var string             $ptp_token   The pending-import token.
 * @var array|WP_Error     $ptp_pending Loaded pending import (or an error if expired/invalid).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<h2><?php esc_html_e( 'Import Preview', 'personal-project-tracker' ); ?></h2>

<?php if ( is_wp_error( $ptp_pending ) ) : ?>
	<div class="notice notice-error"><p><?php echo esc_html( $ptp_pending->get_error_message() ); ?></p></div>
	<p><a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-settings', 'tab' => 'backup' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( '&laquo; Back to Backup', 'personal-project-tracker' ); ?></a></p>
	<?php return; ?>
<?php endif; ?>

<?php $ptp_summary = $ptp_pending['summary'] ?? null; ?>

<?php if ( ! $ptp_summary ) : ?>
	<div class="notice notice-error"><p><?php esc_html_e( 'This file could no longer be validated. Please upload it again.', 'personal-project-tracker' ); ?></p></div>
<?php else : ?>
	<table class="widefat striped ptp-status-table">
		<tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Source Plugin Version', 'personal-project-tracker' ); ?></th>
				<td><?php echo esc_html( $ptp_summary['plugin_version'] ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Source Schema Version', 'personal-project-tracker' ); ?></th>
				<td><?php echo esc_html( $ptp_summary['db_version'] ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Created', 'personal-project-tracker' ); ?></th>
				<td><?php echo esc_html( $ptp_summary['created_at'] ); ?></td>
			</tr>
		</tbody>
	</table>

	<h3><?php esc_html_e( 'Row Counts', 'personal-project-tracker' ); ?></h3>
	<ul class="ptp-simple-list">
		<?php foreach ( $ptp_summary['counts'] as $ptp_key => $ptp_count ) : ?>
			<li>
				<?php echo esc_html( ucfirst( $ptp_key ) ); ?>: <strong><?php echo esc_html( $ptp_count ); ?></strong>
				<?php if ( ! empty( $ptp_summary['invalid'][ $ptp_key ] ) ) : ?>
					<span class="ptp-text-danger">
						<?php
						printf(
							/* translators: %d: number of invalid rows. */
							esc_html__( '(%d will be skipped)', 'personal-project-tracker' ),
							(int) $ptp_summary['invalid'][ $ptp_key ]
						);
						?>
					</span>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>

	<?php if ( ! empty( $ptp_summary['warnings'] ) ) : ?>
		<div class="notice notice-warning">
			<ul class="ptp-error-list">
				<?php foreach ( $ptp_summary['warnings'] as $ptp_warning ) : ?>
					<li><?php echo esc_html( $ptp_warning ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="ptp_import_confirm" />
		<input type="hidden" name="token" value="<?php echo esc_attr( $ptp_token ); ?>" />
		<?php PTP_Security::nonce_field(); ?>

		<div class="ptp-form-field">
			<label for="ptp-duplicate-strategy"><?php esc_html_e( 'Duplicates', 'personal-project-tracker' ); ?></label>
			<select id="ptp-duplicate-strategy" name="duplicate_strategy">
				<option value="skip_existing"><?php esc_html_e( 'Skip Existing', 'personal-project-tracker' ); ?></option>
				<option value="update_existing"><?php esc_html_e( 'Update Existing', 'personal-project-tracker' ); ?></option>
				<option value="create_new"><?php esc_html_e( 'Create New', 'personal-project-tracker' ); ?></option>
			</select>
			<p class="description"><?php esc_html_e( 'A record is considered a duplicate when its title (and project, where relevant) matches one that already exists. Time entries and Finance entries have no reliable duplicate signature and are always imported as new rows.', 'personal-project-tracker' ); ?></p>
		</div>

		<p class="ptp-form-actions">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Confirm Import', 'personal-project-tracker' ); ?></button>
			<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-settings', 'tab' => 'backup' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Cancel', 'personal-project-tracker' ); ?></a>
		</p>
	</form>
<?php endif; ?>
