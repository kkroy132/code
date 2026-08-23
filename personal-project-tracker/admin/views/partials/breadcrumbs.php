<?php
/**
 * Shared breadcrumb trail. Any view that wants one sets $ptp_breadcrumbs
 * (a list of ['label' => string, 'url' => string|null], the last entry's
 * url normally omitted/null since it represents the current page) and
 * requires this file — matching the same "set a local var, require a
 * partial" convention admin/views/partials/notices.php already uses.
 *
 * @package Personal_Project_Tracker
 *
 * @var array<int, array{label: string, url?: string}>|null $ptp_breadcrumbs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $ptp_breadcrumbs ) || ! is_array( $ptp_breadcrumbs ) ) {
	return;
}
?>
<nav class="ptp-breadcrumbs" aria-label="<?php esc_attr_e( 'Breadcrumb', 'personal-project-tracker' ); ?>">
	<?php foreach ( $ptp_breadcrumbs as $ptp_crumb_index => $ptp_crumb ) : ?>
		<?php if ( $ptp_crumb_index > 0 ) : ?>
			<span class="ptp-breadcrumb-sep" aria-hidden="true">/</span>
		<?php endif; ?>
		<?php if ( ! empty( $ptp_crumb['url'] ) ) : ?>
			<a href="<?php echo esc_url( $ptp_crumb['url'] ); ?>"><?php echo esc_html( $ptp_crumb['label'] ); ?></a>
		<?php else : ?>
			<span class="ptp-breadcrumb-current" aria-current="page"><?php echo esc_html( $ptp_crumb['label'] ); ?></span>
		<?php endif; ?>
	<?php endforeach; ?>
</nav>
