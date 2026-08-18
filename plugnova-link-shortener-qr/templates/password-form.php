<?php
/**
 * Front-end password entry form for password-protected short links.
 *
 * Rendered two ways depending on the active theme:
 *
 *  - Classic themes keep the original get_header()/get_footer() wrapping, so the gate still
 *    inherits the site's own header, footer and styles.
 *  - Block (full site editing) themes have no header.php/footer.php. Calling get_header() there
 *    falls through to wp-includes/theme-compat/header.php, which emits a deprecation notice and
 *    produces a bare, unstyled document — so those themes get the standalone layout below
 *    instead, which is self-contained and matches templates/bio-password-form.php.
 *
 * Expects: $link (QuickLinkQRPro\Models\Link) to be set by the including controller.
 *
 * @package QuickLinkQRPro
 * @var \QuickLinkQRPro\Models\Link $link
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$qlqr_is_block_theme = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();

if ( ! $qlqr_is_block_theme ) {
	get_header();
}

if ( $qlqr_is_block_theme ) :
	?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title><?php esc_html_e( 'This link is protected', 'plugnova-link-shortener-qr' ); ?></title>
	<?php
	// This branch never calls get_header()/wp_head() (see the docblock above), so the CSS is routed
	// through wp_add_inline_style() + wp_print_styles() rather than a literal <style> tag — same
	// pattern as templates/bio-page.php and templates/bio-password-form.php.
	$qlqr_inline_css = '
		* { box-sizing: border-box; }
		body {
			margin: 0;
			min-height: 100vh;
			display: flex;
			align-items: center;
			justify-content: center;
			padding: 16px;
			background: #f6f7f7;
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
		}
	';
	wp_register_style( 'qlqr-password-form-inline', false, array(), QLQR_VERSION );
	wp_enqueue_style( 'qlqr-password-form-inline' );
	wp_add_inline_style( 'qlqr-password-form-inline', $qlqr_inline_css );
	wp_print_styles( 'qlqr-password-form-inline' );
	?>
</head>
<body>
	<?php
endif;
?>
<div class="qlqr-password-wrap" style="max-width:420px;<?php echo $qlqr_is_block_theme ? '' : 'margin:80px auto;'; ?>width:100%;padding:32px;border:1px solid #e2e2e2;border-radius:8px;background:#fff;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
	<h2 style="margin-top:0;"><?php esc_html_e( 'This link is protected', 'plugnova-link-shortener-qr' ); ?></h2>
	<p><?php esc_html_e( 'Please enter the password to continue to your destination.', 'plugnova-link-shortener-qr' ); ?></p>
	<form method="post">
		<?php wp_nonce_field( 'qlqr_password_form', 'qlqr_password_nonce' ); ?>
		<input
			type="password"
			name="qlqr_password"
			placeholder="<?php esc_attr_e( 'Enter password', 'plugnova-link-shortener-qr' ); ?>"
			style="width:100%;padding:10px;margin-bottom:12px;box-sizing:border-box;"
			required
		/>
		<button type="submit" class="button button-primary" style="width:100%;padding:10px;cursor:pointer;">
			<?php esc_html_e( 'Continue', 'plugnova-link-shortener-qr' ); ?>
		</button>
	</form>
</div>
<?php
if ( $qlqr_is_block_theme ) :
	?>
</body>
</html>
	<?php
else :
	get_footer();
endif;
