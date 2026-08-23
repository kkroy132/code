<?php
/**
 * Settings > Notifications — a thin pointer to the dedicated Notification
 * Preferences page (Phase 11), not a second copy of that form. Global ON/
 * OFF, per-category toggles, and Quiet Hours already live there.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<h2><?php esc_html_e( 'Notifications', 'personal-project-tracker' ); ?></h2>
<p><?php esc_html_e( 'Notification preferences — the global switch, per-category toggles, and Quiet Hours — are managed on their own dedicated page.', 'personal-project-tracker' ); ?></p>
<p>
	<a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-notifications', 'action' => 'preferences' ), admin_url( 'admin.php' ) ) ); ?>">
		<?php esc_html_e( 'Manage Notification Preferences', 'personal-project-tracker' ); ?>
	</a>
</p>
