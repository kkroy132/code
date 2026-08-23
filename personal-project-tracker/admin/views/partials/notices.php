<?php
/**
 * Shared admin notice banners.
 *
 * Renders a success/error banner from the `ptp_success` / `ptp_error`
 * query args used by simple redirect-based flows. Field-level validation
 * errors (e.g. the project form) are rendered inline by their own view
 * instead, using the flash-transient pattern.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ptp_success_code = isset( $_GET['ptp_success'] ) ? sanitize_key( wp_unslash( $_GET['ptp_success'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_error_message = isset( $_GET['ptp_error'] ) ? sanitize_text_field( wp_unslash( $_GET['ptp_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$ptp_success_messages = array(
	'created' => __( 'Project created successfully.', 'personal-project-tracker' ),
	'updated' => __( 'Project updated successfully.', 'personal-project-tracker' ),
);
?>
<?php if ( $ptp_success_code && isset( $ptp_success_messages[ $ptp_success_code ] ) ) : ?>
	<div class="notice notice-success is-dismissible" role="status"><p><?php echo esc_html( $ptp_success_messages[ $ptp_success_code ] ); ?></p></div>
<?php endif; ?>
<?php if ( $ptp_error_message ) : ?>
	<div class="notice notice-error is-dismissible" role="alert"><p><?php echo esc_html( $ptp_error_message ); ?></p></div>
<?php endif; ?>
