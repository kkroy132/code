<?php
/**
 * Placeholder view for modules not yet implemented.
 *
 * @package Personal_Project_Tracker
 *
 * @var string $title Page title, passed in from PTP_Admin_Pages::render_placeholder().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<h1><?php echo esc_html( $title ); ?></h1>

<div class="ptp-card ptp-placeholder">
	<span class="dashicons dashicons-hammer" aria-hidden="true"></span>
	<p>
		<?php
		printf(
			/* translators: %s: module name, e.g. "Projects". */
			esc_html__( 'The %s module is being built in a later development phase and will appear here once complete.', 'personal-project-tracker' ),
			esc_html( $title )
		);
		?>
	</p>
</div>
