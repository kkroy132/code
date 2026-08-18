<?php
/**
 * Public-facing password entry form for a password-protected Smart Bio Link page. Standalone
 * (its own <!DOCTYPE>, no theme header/footer) so it looks consistent with templates/bio-page.php
 * rather than borrowing the active WordPress theme's chrome — see
 * Controllers\BioController::render_password_form()'s docblock for why that differs from
 * templates/password-form.php (the short-link equivalent, which does use get_header()/get_footer()).
 *
 * Expects the following to be set by the including controller (Controllers\BioController):
 *
 * @package QuickLinkQRPro
 * @var \QuickLinkQRPro\Models\BioPage $bio_page
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Only light/dark are meaningfully distinct for a single-card gate screen (no links list to
// theme) — gradient/minimal both read fine as "light" here rather than needing their own case.
$qlqr_is_dark = 'dark' === $bio_page->theme_preset;
$qlqr_colors  = $qlqr_is_dark
	? array(
		'body_bg'     => '#0b0f14',
		'card_bg'     => '#1c1f26',
		'card_border' => '#2e323c',
		'text'        => '#f5f5f7',
		'muted'       => '#a1a1aa',
	)
	: array(
		'body_bg'     => '#f6f7f7',
		'card_bg'     => '#ffffff',
		'card_border' => '#e2e2e2',
		'text'        => '#1d2327',
		'muted'       => '#50575e',
	);
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title><?php echo esc_html( $bio_page->title ); ?></title>
	<?php
	// Captured (not echoed directly) so it can go through wp_add_inline_style() below instead of a
	// literal <style> tag — this page is standalone and never calls wp_head(), so wp_print_styles()
	// is invoked directly at the point the styles belong. Same pattern as templates/bio-page.php.
	ob_start();
	?>
		* { box-sizing: border-box; }
		body {
			margin: 0;
			min-height: 100vh;
			display: flex;
			align-items: center;
			justify-content: center;
			padding: 16px;
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
			background: <?php echo esc_html( $qlqr_colors['body_bg'] ); ?>;
		}
		.qlqr-bio-password-card {
			width: 100%;
			max-width: 380px;
			padding: 32px;
			border-radius: 14px;
			background: <?php echo esc_html( $qlqr_colors['card_bg'] ); ?>;
			border: 1px solid <?php echo esc_html( $qlqr_colors['card_border'] ); ?>;
			text-align: center;
		}
		.qlqr-bio-password-card h1 {
			font-size: 18px;
			margin: 0 0 6px;
			color: <?php echo esc_html( $qlqr_colors['text'] ); ?>;
		}
		.qlqr-bio-password-card p {
			font-size: 13px;
			margin: 0 0 20px;
			color: <?php echo esc_html( $qlqr_colors['muted'] ); ?>;
		}
		.qlqr-bio-password-input {
			width: 100%;
			padding: 13px 16px;
			border-radius: 10px;
			border: 1px solid <?php echo esc_html( $qlqr_colors['card_border'] ); ?>;
			background: <?php echo esc_html( $qlqr_colors['body_bg'] ); ?>;
			color: <?php echo esc_html( $qlqr_colors['text'] ); ?>;
			font-size: 15px;
			margin-bottom: 10px;
		}
		.qlqr-bio-password-submit {
			width: 100%;
			padding: 14px 16px;
			border-radius: 10px;
			border: none;
			background: <?php echo esc_html( $bio_page->theme_color ); ?>;
			color: #fff;
			font-size: 15px;
			font-weight: 700;
			cursor: pointer;
		}
	<?php
	$qlqr_inline_css = ob_get_clean();
	wp_register_style( 'qlqr-bio-password-form-inline', false, array(), QLQR_VERSION );
	wp_enqueue_style( 'qlqr-bio-password-form-inline' );
	wp_add_inline_style( 'qlqr-bio-password-form-inline', $qlqr_inline_css );
	wp_print_styles( 'qlqr-bio-password-form-inline' );
	?>
</head>
<body>
	<div class="qlqr-bio-password-card">
		<h1><?php esc_html_e( 'This page is protected', 'plugnova-link-shortener-qr' ); ?></h1>
		<p><?php esc_html_e( 'Please enter the password to continue.', 'plugnova-link-shortener-qr' ); ?></p>
		<form method="post">
			<?php wp_nonce_field( 'qlqr_bio_password_form', 'qlqr_bio_password_nonce' ); ?>
			<input
				type="password"
				name="qlqr_bio_password"
				class="qlqr-bio-password-input"
				placeholder="<?php esc_attr_e( 'Enter password', 'plugnova-link-shortener-qr' ); ?>"
				required
			/>
			<button type="submit" class="qlqr-bio-password-submit"><?php esc_html_e( 'Continue', 'plugnova-link-shortener-qr' ); ?></button>
		</form>
	</div>
</body>
</html>
