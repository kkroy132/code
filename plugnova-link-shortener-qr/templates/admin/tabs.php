<?php
/**
 * Unified admin page view: horizontal tab bar + one panel per section. Panels are all rendered
 * server-side and toggled client-side (assets/js/admin.js), so switching tabs never reloads.
 *
 * Expects: $panels (array<string, array{label:string, renderer:callable}>) from Admin\TabsPage.
 *
 * @package QuickLinkQRPro
 *
 * @var array<string, array{label:string, renderer:callable}> $panels
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap qlqr-wrap">
	<h1><?php esc_html_e( 'Plugnova Link Shortener & QR', 'plugnova-link-shortener-qr' ); ?></h1>
	<?php /* Anchor for WordPress core's notice relocation: admin notices are moved after this marker, keeping them above the tab bar instead of disappearing into a hidden panel. */ ?>
	<hr class="wp-header-end" />

	<nav class="nav-tab-wrapper qlqr-nav-tabs">
		<?php $qlqr_first_tab = true; ?>
		<?php foreach ( $panels as $qlqr_tab => $qlqr_panel ) : ?>
			<a
				href="#<?php echo esc_attr( $qlqr_tab ); ?>"
				class="nav-tab qlqr-nav-tab<?php echo $qlqr_first_tab ? ' nav-tab-active' : ''; ?>"
				data-tab="<?php echo esc_attr( $qlqr_tab ); ?>"
			><?php echo esc_html( $qlqr_panel['label'] ); ?></a>
			<?php $qlqr_first_tab = false; ?>
		<?php endforeach; ?>
	</nav>
</div>

<?php $qlqr_first_tab = true; ?>
<?php foreach ( $panels as $qlqr_tab => $qlqr_panel ) : ?>
	<div
		class="qlqr-tab-panel<?php echo $qlqr_first_tab ? ' qlqr-tab-panel-active' : ''; ?>"
		data-tab-panel="<?php echo esc_attr( $qlqr_tab ); ?>"
		<?php if ( ! empty( $qlqr_panel['lazy_action'] ) ) : ?>
			data-lazy-action="<?php echo esc_attr( $qlqr_panel['lazy_action'] ); ?>"
		<?php endif; ?>
	>
		<?php
		// A panel carrying data-lazy-action ships empty and fetches its own markup the first time
		// its tab is opened (see assets/js/admin.js), so its queries never run on page loads where
		// the user never visits it.
		if ( empty( $qlqr_panel['lazy_action'] ) ) {
			call_user_func( $qlqr_panel['renderer'] );
		}
		?>
	</div>
	<?php $qlqr_first_tab = false; ?>
<?php endforeach; ?>

<?php /* QR code lightbox — shared by the Links and Bio Links panels. Lives at the page level, outside every panel, because a modal nested inside a display:none panel could never become visible when opened from a different tab. */ ?>
<div id="qlqr-qr-lightbox" class="qlqr-modal" style="display:none;" aria-hidden="true">
	<div class="qlqr-modal-inner qlqr-lightbox-inner">
		<button type="button" class="qlqr-modal-close" id="qlqr-lightbox-close" aria-label="<?php esc_attr_e( 'Close', 'plugnova-link-shortener-qr' ); ?>">&times;</button>
		<h2 id="qlqr-lightbox-title"></h2>
		<img id="qlqr-lightbox-image" src="" alt="<?php esc_attr_e( 'QR code', 'plugnova-link-shortener-qr' ); ?>" />
		<p class="submit" style="text-align:center;">
			<a id="qlqr-lightbox-download" href="#" download class="button button-primary"><?php esc_html_e( 'Download', 'plugnova-link-shortener-qr' ); ?></a>
		</p>
	</div>
</div>
