<?php
/**
 * Settings page: tab switcher + shared shell.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ptp_can_finance = current_user_can( 'ptp_manage_finance' );

$ptp_tabs = array(
	'general'       => __( 'General', 'personal-project-tracker' ),
	'appearance'    => __( 'Appearance', 'personal-project-tracker' ),
	'notifications' => __( 'Notifications', 'personal-project-tracker' ),
	'ai'            => __( 'AI', 'personal-project-tracker' ),
	'backup'        => __( 'Backup', 'personal-project-tracker' ),
	'privacy'       => __( 'Privacy', 'personal-project-tracker' ),
	'advanced'      => __( 'Advanced', 'personal-project-tracker' ),
);

if ( $ptp_can_finance ) {
	// Finance sits right after AI in the tab order the spec lists.
	$ptp_tabs = array_slice( $ptp_tabs, 0, 3, true )
		+ array( 'finance' => __( 'Finance', 'personal-project-tracker' ) )
		+ array_slice( $ptp_tabs, 3, null, true );
}

$ptp_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

if ( ! array_key_exists( $ptp_tab, $ptp_tabs ) ) {
	$ptp_tab = 'general';
}
?>
<div class="ptp-page-header">
	<h1><?php esc_html_e( 'Settings', 'personal-project-tracker' ); ?></h1>
</div>

<h2 class="nav-tab-wrapper">
	<?php foreach ( $ptp_tabs as $ptp_tab_key => $ptp_tab_label ) : ?>
		<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-settings', 'tab' => $ptp_tab_key ), admin_url( 'admin.php' ) ) ); ?>" class="nav-tab <?php echo $ptp_tab === $ptp_tab_key ? 'nav-tab-active' : ''; ?>">
			<?php echo esc_html( $ptp_tab_label ); ?>
		</a>
	<?php endforeach; ?>
</h2>

<div class="ptp-card">
	<?php require PTP_PLUGIN_DIR . 'admin/views/settings/partials/tab-' . $ptp_tab . '.php'; ?>
</div>
