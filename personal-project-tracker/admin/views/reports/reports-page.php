<?php
/**
 * Reports page: report-type switcher, shared filter bar, CSV export.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ptp_can_view_finance = current_user_can( 'ptp_manage_finance' );

$ptp_report_types = array(
	'projects'     => __( 'Project Report', 'personal-project-tracker' ),
	'tasks'        => __( 'Task Report', 'personal-project-tracker' ),
	'milestones'   => __( 'Milestone Report', 'personal-project-tracker' ),
	'time'         => __( 'Time Report', 'personal-project-tracker' ),
	'productivity' => __( 'Productivity Report', 'personal-project-tracker' ),
	'activity'     => __( 'Activity Report', 'personal-project-tracker' ),
);

if ( $ptp_can_view_finance ) {
	$ptp_report_types['finance'] = __( 'Finance Report', 'personal-project-tracker' );
}

$ptp_report = isset( $_GET['report'] ) ? sanitize_key( wp_unslash( $_GET['report'] ) ) : 'projects'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

if ( ! array_key_exists( $ptp_report, $ptp_report_types ) ) {
	$ptp_report = 'projects';
}

$ptp_project_id = isset( $_GET['project_id'] ) ? absint( $_GET['project_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_status      = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_priority    = isset( $_GET['priority'] ) ? sanitize_key( wp_unslash( $_GET['priority'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_category    = isset( $_GET['category'] ) ? sanitize_text_field( wp_unslash( $_GET['category'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_date_from   = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ptp_date_to     = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$ptp_filter_args = array(
	'project_id' => $ptp_project_id,
	'status'     => $ptp_status,
	'priority'   => $ptp_priority,
	'category'   => $ptp_category,
	'date_from'  => $ptp_date_from,
	'date_to'    => $ptp_date_to,
);

$ptp_projects = PTP_Projects_Repository::get_options_for_select();

$ptp_export_url = add_query_arg(
	array_merge(
		array(
			'action'          => 'ptp_export_report_csv',
			'report'          => $ptp_report,
			PTP_Security::NONCE_NAME => PTP_Security::create_nonce(),
		),
		array_filter( $ptp_filter_args )
	),
	admin_url( 'admin-post.php' )
);
?>
<div class="ptp-page-header">
	<h1><?php esc_html_e( 'Reports', 'personal-project-tracker' ); ?></h1>
	<div class="ptp-quick-actions">
		<a class="button" href="<?php echo esc_url( $ptp_export_url ); ?>"><?php esc_html_e( 'Export CSV', 'personal-project-tracker' ); ?></a>
		<?php if ( current_user_can( 'ptp_manage_data' ) ) : ?>
			<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-ai-prompts', 'action' => 'new', 'context_type' => 'report', 'project_id' => $ptp_project_id, 'include_reports' => 1, 'include_finance' => $ptp_can_view_finance ? 1 : 0 ), admin_url( 'admin.php' ) ) ); ?>">
				<?php esc_html_e( 'Generate AI Analysis Prompt', 'personal-project-tracker' ); ?>
			</a>
		<?php endif; ?>
	</div>
</div>

<div class="ptp-card">
	<form method="get" class="ptp-filter-bar">
		<input type="hidden" name="page" value="ptp-reports" />

		<select name="report" id="ptp-report-type">
			<?php foreach ( $ptp_report_types as $ptp_key => $ptp_label ) : ?>
				<option value="<?php echo esc_attr( $ptp_key ); ?>" <?php selected( $ptp_report, $ptp_key ); ?>><?php echo esc_html( $ptp_label ); ?></option>
			<?php endforeach; ?>
		</select>

		<select name="project_id">
			<option value=""><?php esc_html_e( 'All projects', 'personal-project-tracker' ); ?></option>
			<?php foreach ( $ptp_projects as $ptp_pid => $ptp_ptitle ) : ?>
				<option value="<?php echo esc_attr( $ptp_pid ); ?>" <?php selected( $ptp_project_id, $ptp_pid ); ?>><?php echo esc_html( $ptp_ptitle ); ?></option>
			<?php endforeach; ?>
		</select>

		<input type="date" name="date_from" value="<?php echo esc_attr( $ptp_date_from ); ?>" aria-label="<?php esc_attr_e( 'From date', 'personal-project-tracker' ); ?>" />
		<input type="date" name="date_to" value="<?php echo esc_attr( $ptp_date_to ); ?>" aria-label="<?php esc_attr_e( 'To date', 'personal-project-tracker' ); ?>" />

		<select name="priority">
			<option value=""><?php esc_html_e( 'All priorities', 'personal-project-tracker' ); ?></option>
			<?php foreach ( PTP_Tasks_Repository::get_priorities() as $ptp_pkey => $ptp_plabel ) : ?>
				<option value="<?php echo esc_attr( $ptp_pkey ); ?>" <?php selected( $ptp_priority, $ptp_pkey ); ?>><?php echo esc_html( $ptp_plabel ); ?></option>
			<?php endforeach; ?>
		</select>

		<input type="text" name="category" value="<?php echo esc_attr( $ptp_category ); ?>" placeholder="<?php esc_attr_e( 'Category', 'personal-project-tracker' ); ?>" />

		<button type="submit" class="button"><?php esc_html_e( 'Filter', 'personal-project-tracker' ); ?></button>

		<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ptp-reports', 'report' => $ptp_report ), admin_url( 'admin.php' ) ) ); ?>">
			<?php esc_html_e( 'Reset', 'personal-project-tracker' ); ?>
		</a>
	</form>
	<p class="description"><?php esc_html_e( 'Not every filter applies to every report — Priority only affects Task/Milestone reports, Category only affects the Finance report.', 'personal-project-tracker' ); ?></p>
</div>

<?php
if ( 'finance' === $ptp_report && ! $ptp_can_view_finance ) {
	$ptp_report = 'projects';
}

require PTP_PLUGIN_DIR . 'admin/views/reports/partials/' . $ptp_report . '-report.php';
