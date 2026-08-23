<?php
/**
 * Shared admin page header/wrapper open tag.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ptp_appearance_mode = class_exists( 'PTP_Settings' ) ? PTP_Settings::get( 'appearance_mode', 'system' ) : 'system';

if ( ! in_array( $ptp_appearance_mode, array( 'light', 'dark', 'system' ), true ) ) {
	$ptp_appearance_mode = 'system';
}
?>
<div class="wrap ptp-wrap" data-ptp-theme="<?php echo esc_attr( $ptp_appearance_mode ); ?>">
	<?php require PTP_PLUGIN_DIR . 'admin/views/partials/notices.php'; ?>
