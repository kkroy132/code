<?php
/**
 * Add/Edit Revenue form.
 *
 * @package Personal_Project_Tracker
 *
 * @var object|null $revenue Existing revenue row when editing, null when creating or not found.
 * @var bool        $is_edit Whether this request is an "edit" (vs. "new").
 * @var array|null  $flash   Flashed validation errors + previously submitted values, if any.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ptp_not_found = ! empty( $is_edit ) && ! $revenue;
$ptp_projects  = PTP_Projects_Repository::get_options_for_select();

$ptp_default_project_id = isset( $_GET['project_id'] ) ? absint( $_GET['project_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$ptp_values = array(
	'project_id'   => $revenue->project_id ?? $ptp_default_project_id,
	'amount'       => $revenue->amount ?? '',
	'currency'     => $revenue->currency ?? PTP_Settings::get( 'default_currency', 'USD' ),
	'category'     => $revenue->category ?? '',
	'description'  => $revenue->description ?? '',
	'revenue_date' => $revenue->revenue_date ?? current_time( 'Y-m-d' ),
);

if ( ! empty( $flash['data'] ) ) {
	$ptp_values = wp_parse_args( $flash['data'], $ptp_values );
}
?>
<h1><?php echo ! empty( $is_edit ) ? esc_html__( 'Edit Revenue', 'personal-project-tracker' ) : esc_html__( 'Add Revenue', 'personal-project-tracker' ); ?></h1>

<?php if ( ! empty( $flash['errors'] ) ) : ?>
	<div class="notice notice-error" role="alert">
		<ul class="ptp-error-list">
			<?php foreach ( $flash['errors'] as $ptp_error_message ) : ?>
				<li><?php echo esc_html( $ptp_error_message ); ?></li>
			<?php endforeach; ?>
		</ul>
	</div>
<?php endif; ?>

<?php if ( $ptp_not_found ) : ?>
	<div class="notice notice-error"><p><?php esc_html_e( 'That revenue entry could not be found.', 'personal-project-tracker' ); ?></p></div>
<?php else : ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ptp-card ptp-revenue-form">
		<input type="hidden" name="action" value="ptp_save_revenue" />
		<?php if ( ! empty( $is_edit ) && $revenue ) : ?>
			<input type="hidden" name="id" value="<?php echo esc_attr( $revenue->id ); ?>" />
		<?php endif; ?>
		<?php PTP_Security::nonce_field(); ?>

		<div class="ptp-form-grid">
			<div class="ptp-form-field">
				<label for="ptp-amount"><?php esc_html_e( 'Amount', 'personal-project-tracker' ); ?> <span class="ptp-required" aria-hidden="true">*</span></label>
				<input type="number" id="ptp-amount" name="amount" min="0" step="0.01" required value="<?php echo esc_attr( $ptp_values['amount'] ); ?>" />
			</div>

			<div class="ptp-form-field">
				<label for="ptp-currency"><?php esc_html_e( 'Currency', 'personal-project-tracker' ); ?></label>
				<input type="text" id="ptp-currency" name="currency" maxlength="10" placeholder="USD" value="<?php echo esc_attr( $ptp_values['currency'] ); ?>" />
			</div>

			<div class="ptp-form-field">
				<label for="ptp-date"><?php esc_html_e( 'Date', 'personal-project-tracker' ); ?> <span class="ptp-required" aria-hidden="true">*</span></label>
				<input type="date" id="ptp-date" name="revenue_date" required value="<?php echo esc_attr( $ptp_values['revenue_date'] ); ?>" />
			</div>

			<div class="ptp-form-field">
				<label for="ptp-category"><?php esc_html_e( 'Category', 'personal-project-tracker' ); ?></label>
				<input type="text" id="ptp-category" name="category" maxlength="100" value="<?php echo esc_attr( $ptp_values['category'] ); ?>" />
			</div>

			<div class="ptp-form-field">
				<label for="ptp-project"><?php esc_html_e( 'Project', 'personal-project-tracker' ); ?></label>
				<select id="ptp-project" name="project_id">
					<option value=""><?php esc_html_e( '— None —', 'personal-project-tracker' ); ?></option>
					<?php foreach ( $ptp_projects as $ptp_pid => $ptp_ptitle ) : ?>
						<option value="<?php echo esc_attr( $ptp_pid ); ?>" <?php selected( (int) $ptp_values['project_id'], $ptp_pid ); ?>>
							<?php echo esc_html( $ptp_ptitle ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="ptp-form-field ptp-form-field-full">
				<label for="ptp-description"><?php esc_html_e( 'Description', 'personal-project-tracker' ); ?></label>
				<textarea id="ptp-description" name="description" rows="3" maxlength="500"><?php echo esc_textarea( $ptp_values['description'] ); ?></textarea>
			</div>
		</div>

		<p class="ptp-form-actions">
			<button type="submit" class="button button-primary">
				<?php echo ! empty( $is_edit ) ? esc_html__( 'Update Revenue', 'personal-project-tracker' ) : esc_html__( 'Save Revenue', 'personal-project-tracker' ); ?>
			</button>
			<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-finance' ), admin_url( 'admin.php' ) ) ); ?>">
				<?php esc_html_e( 'Cancel', 'personal-project-tracker' ); ?>
			</a>
		</p>
	</form>

<?php endif; ?>
