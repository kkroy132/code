<?php
/**
 * Public-facing Smart Bio Link landing page — a standalone, mobile-first page listing a set of
 * buttons, rendered without the active theme's header/footer (so it looks the same on every
 * site regardless of theme, the same way templates/password-form.php keeps its own inline styles).
 *
 * Expects the following to be set by the including controller (Controllers\BioController):
 *
 * @package QuickLinkQRPro
 * @var \QuickLinkQRPro\Models\BioPage $bio_page
 * @var \QuickLinkQRPro\Models\BioLink[] $buttons Flat, already visibility-filtered list; not used
 *      directly by this template (the render walks $button_groups below, whose emptiness is the
 *      correct "no links" signal — see group_buttons()'s docblock for why that can differ from
 *      $buttons' emptiness), but kept available for custom child themes that copy this file.
 * @var array<int, array{type:string, item:\QuickLinkQRPro\Models\BioLink, children?:\QuickLinkQRPro\Models\BioLink[]}> $button_groups
 *      Top-level render order: plain links interleaved with group (accordion) headers carrying
 *      their member links — see Controllers\BioController::group_buttons().
 * @var \QuickLinkQRPro\Models\BioSocial[] $socials
 * @var bool $show_email_gate Whether to show the email-capture gate instead of $button_groups.
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$qlqr_radius = match ( $bio_page->button_style ) {
	'square' => '4px',
	'pill'   => '999px',
	default  => '14px',
};

// Visual variables per theme preset. Card background/border/shadow differ enough between presets
// (e.g. "gradient"'s translucent glass cards vs. "minimal"'s flat outlined ones) that a single
// shared set of CSS variables driven by preset is simpler than four near-duplicate stylesheets.
$qlqr_theme = match ( $bio_page->theme_preset ) {
	'dark'     => array(
		'body_bg'       => 'radial-gradient(circle at top, #1f2937 0%, #0b0f14 340px)',
		'text'          => '#f5f5f7',
		'muted'         => '#a1a1aa',
		'card_bg'       => '#1c1f26',
		'card_border'   => '#2e323c',
		'avatar_border' => '#1c1f26',
		'shadow'        => '0 2px 10px rgba(0,0,0,.35)',
	),
	'gradient' => array(
		'body_bg'       => 'linear-gradient(160deg, ' . $bio_page->theme_color . ' 0%, #1d2327 520px)',
		'text'          => '#ffffff',
		'muted'         => 'rgba(255,255,255,.78)',
		'card_bg'       => 'rgba(255,255,255,.14)',
		'card_border'   => 'rgba(255,255,255,.32)',
		'avatar_border' => 'rgba(255,255,255,.6)',
		'shadow'        => '0 2px 10px rgba(0,0,0,.15)',
	),
	'minimal'  => array(
		'body_bg'       => '#ffffff',
		'text'          => '#1d2327',
		'muted'         => '#646970',
		'card_bg'       => '#ffffff',
		'card_border'   => '#d0d0d0',
		'avatar_border' => '#ffffff',
		'shadow'        => 'none',
	),
	default    => array( // 'light'
		'body_bg'       => 'linear-gradient(180deg, ' . $bio_page->theme_color . '1a 0%, #f6f7f7 260px)',
		'text'          => '#1d2327',
		'muted'         => '#50575e',
		'card_bg'       => '#ffffff',
		'card_border'   => '#e2e2e2',
		'avatar_border' => '#ffffff',
		'shadow'        => '0 2px 10px rgba(0,0,0,.12)',
	),
};

// First character of the title for the avatar fallback circle. Extracted with PCRE's Unicode
// mode rather than mbstring, since mbstring isn't guaranteed on every shared host (same
// reasoning as Helpers\QrCodeGenerator::truncate_to_fit_ttf()), and this correctly grabs one
// whole character from multi-byte scripts (e.g. a Bengali title) either way.
preg_match( '/./us', $bio_page->title, $qlqr_initial_match );
$qlqr_avatar_initial = strtoupper( $qlqr_initial_match[0] ?? '?' );

// Social icons split into the inline row (near the top, as before) vs. a fixed-position floating
// stack (Floating Contact Buttons) — the same underlying rows, just two different render spots.
$qlqr_inline_socials   = array_values( array_filter( $socials, static fn( \QuickLinkQRPro\Models\BioSocial $s ): bool => ! $s->is_floating ) );
$qlqr_floating_socials = array_values( array_filter( $socials, static fn( \QuickLinkQRPro\Models\BioSocial $s ): bool => $s->is_floating ) );

$qlqr_show_announcement = ! $show_email_gate && $bio_page->announcement_enabled && $bio_page->announcement_text;
$qlqr_show_countdown    = ! $show_email_gate && $bio_page->has_active_countdown();
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title><?php echo esc_html( $bio_page->title ); ?></title>
	<?php
	// Captured (not echoed directly) so it can go through wp_add_inline_style() below instead of a
	// literal <style> tag — this page is intentionally standalone (see the file docblock) and never
	// calls wp_head(), so wp_print_styles() is invoked directly at the point the styles belong.
	ob_start();
	?>
		* { box-sizing: border-box; }
		body {
			margin: 0;
			min-height: 100vh;
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
			background: <?php echo esc_html( $qlqr_theme['body_bg'] ); ?>;
			display: flex;
			flex-direction: column;
			align-items: center;
			padding: 0 16px 48px;
		}
		.qlqr-bio-announcement {
			width: 100%;
			max-width: 460px;
			margin: 0 auto;
			padding: 10px 36px 10px 14px;
			border-radius: 0 0 10px 10px;
			font-size: 13px;
			font-weight: 600;
			text-align: center;
			position: relative;
			background: <?php echo esc_html( $bio_page->announcement_bg_color ); ?>;
			color: <?php echo esc_html( $bio_page->announcement_text_color ); ?>;
		}
		.qlqr-bio-announcement a {
			color: inherit;
			text-decoration: underline;
		}
		.qlqr-bio-announcement-close {
			position: absolute;
			top: 50%;
			right: 8px;
			transform: translateY(-50%);
			background: none;
			border: none;
			color: inherit;
			opacity: .8;
			font-size: 16px;
			line-height: 1;
			cursor: pointer;
			padding: 4px;
		}
		.qlqr-bio-card {
			width: 100%;
			max-width: 460px;
			text-align: center;
			padding-top: 48px;
		}
		.qlqr-bio-avatar {
			width: 96px;
			height: 96px;
			border-radius: 50%;
			object-fit: cover;
			margin: 0 auto 16px;
			display: block;
			background: #fff;
			border: 3px solid <?php echo esc_html( $qlqr_theme['avatar_border'] ); ?>;
			box-shadow: <?php echo esc_html( $qlqr_theme['shadow'] ); ?>;
		}
		.qlqr-bio-avatar-fallback {
			width: 96px;
			height: 96px;
			border-radius: 50%;
			margin: 0 auto 16px;
			display: flex;
			align-items: center;
			justify-content: center;
			font-size: 34px;
			font-weight: 600;
			color: #fff;
			background: <?php echo esc_html( $bio_page->theme_color ); ?>;
			border: 3px solid <?php echo esc_html( $qlqr_theme['avatar_border'] ); ?>;
			box-shadow: <?php echo esc_html( $qlqr_theme['shadow'] ); ?>;
		}
		.qlqr-bio-title {
			font-size: 22px;
			font-weight: 700;
			margin: 0 0 6px;
			color: <?php echo esc_html( $qlqr_theme['text'] ); ?>;
			word-break: break-word;
		}
		.qlqr-bio-text {
			font-size: 14px;
			color: <?php echo esc_html( $qlqr_theme['muted'] ); ?>;
			margin: 0 0 20px;
			white-space: pre-line;
			word-break: break-word;
			text-align: justify;
		}
		.qlqr-bio-socials {
			display: flex;
			justify-content: center;
			flex-wrap: wrap;
			gap: 10px;
			margin-bottom: 24px;
		}
		.qlqr-bio-social {
			width: 40px;
			height: 40px;
			border-radius: 50%;
			display: flex;
			align-items: center;
			justify-content: center;
			background: <?php echo esc_html( $qlqr_theme['card_bg'] ); ?>;
			border: 1px solid <?php echo esc_html( $qlqr_theme['card_border'] ); ?>;
			color: <?php echo esc_html( $qlqr_theme['text'] ); ?>;
			text-decoration: none;
			font-size: 16px;
			font-weight: 700;
			transition: transform .12s ease;
		}
		.qlqr-bio-social:hover {
			transform: translateY(-2px);
		}
		.qlqr-bio-social svg {
			width: 18px;
			height: 18px;
			display: block;
		}
		.qlqr-bio-countdown {
			margin: 0 0 22px;
		}
		.qlqr-bio-countdown-label {
			font-size: 13px;
			font-weight: 600;
			color: <?php echo esc_html( $qlqr_theme['muted'] ); ?>;
			margin: 0 0 8px;
		}
		.qlqr-bio-countdown-units {
			display: flex;
			justify-content: center;
			gap: 8px;
		}
		.qlqr-bio-countdown-unit {
			min-width: 54px;
			padding: 8px 4px;
			border-radius: 10px;
			background: <?php echo esc_html( $qlqr_theme['card_bg'] ); ?>;
			border: 1px solid <?php echo esc_html( $qlqr_theme['card_border'] ); ?>;
		}
		.qlqr-bio-countdown-value {
			display: block;
			font-size: 20px;
			font-weight: 700;
			color: <?php echo esc_html( $bio_page->theme_color ); ?>;
			font-variant-numeric: tabular-nums;
		}
		.qlqr-bio-countdown-unit-label {
			display: block;
			font-size: 10px;
			text-transform: uppercase;
			letter-spacing: .04em;
			color: <?php echo esc_html( $qlqr_theme['muted'] ); ?>;
			margin-top: 2px;
		}
		.qlqr-bio-links {
			display: flex;
			flex-direction: column;
			gap: 12px;
		}
		.qlqr-bio-link {
			display: block;
			padding: 15px 20px;
			background: <?php echo esc_html( $qlqr_theme['card_bg'] ); ?>;
			border: 1px solid <?php echo esc_html( $qlqr_theme['card_border'] ); ?>;
			border-radius: <?php echo esc_html( $qlqr_radius ); ?>;
			color: <?php echo esc_html( $qlqr_theme['text'] ); ?>;
			font-weight: 600;
			font-size: 15px;
			text-decoration: none;
			transition: transform .12s ease, box-shadow .12s ease;
			word-break: break-word;
		}
		.qlqr-bio-link:hover {
			transform: translateY(-1px);
			box-shadow: <?php echo esc_html( $qlqr_theme['shadow'] ); ?>;
			border-color: <?php echo esc_html( $bio_page->theme_color ); ?>;
		}
		.qlqr-bio-icon {
			margin-right: 8px;
		}
		/* "Product card" style button: a thumbnail image + label, the whole card being one link —
		   used when a button has an image_url configured, instead of the plain text row above. */
		.qlqr-bio-link-card {
			display: flex;
			align-items: center;
			gap: 14px;
			text-align: left;
			padding: 10px;
		}
		.qlqr-bio-link-thumb {
			width: 52px;
			height: 52px;
			flex-shrink: 0;
			object-fit: cover;
			border-radius: calc(<?php echo esc_html( $qlqr_radius ); ?> - 4px);
			background: #fff;
		}
		.qlqr-bio-link-label {
			flex: 1;
			min-width: 0;
		}
		.qlqr-bio-empty {
			color: <?php echo esc_html( $qlqr_theme['muted'] ); ?>;
			font-size: 14px;
			padding: 20px 0;
		}
		.qlqr-bio-gate-heading {
			font-size: 15px;
			font-weight: 600;
			color: <?php echo esc_html( $qlqr_theme['text'] ); ?>;
			margin-bottom: 14px;
		}
		.qlqr-bio-gate-input {
			width: 100%;
			padding: 13px 16px;
			border-radius: <?php echo esc_html( $qlqr_radius ); ?>;
			border: 1px solid <?php echo esc_html( $qlqr_theme['card_border'] ); ?>;
			background: <?php echo esc_html( $qlqr_theme['card_bg'] ); ?>;
			color: <?php echo esc_html( $qlqr_theme['text'] ); ?>;
			font-size: 15px;
			margin-bottom: 10px;
		}
		.qlqr-bio-gate-submit {
			width: 100%;
			padding: 14px 16px;
			border-radius: <?php echo esc_html( $qlqr_radius ); ?>;
			border: none;
			background: <?php echo esc_html( $bio_page->theme_color ); ?>;
			color: #fff;
			font-size: 15px;
			font-weight: 700;
			cursor: pointer;
		}
		/* Accordion (Link Group) — a native <details>/<summary> element, so expand/collapse works
		   even with JavaScript disabled and gets keyboard/screen-reader support for free. */
		.qlqr-bio-group {
			border: 1px solid <?php echo esc_html( $qlqr_theme['card_border'] ); ?>;
			border-radius: <?php echo esc_html( $qlqr_radius ); ?>;
			background: <?php echo esc_html( $qlqr_theme['card_bg'] ); ?>;
			overflow: hidden;
		}
		.qlqr-bio-group-header {
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 10px;
			padding: 15px 20px;
			font-weight: 600;
			font-size: 15px;
			color: <?php echo esc_html( $qlqr_theme['text'] ); ?>;
			cursor: pointer;
			list-style: none;
			user-select: none;
		}
		.qlqr-bio-group-header::-webkit-details-marker {
			display: none;
		}
		.qlqr-bio-group-chevron {
			flex-shrink: 0;
			transition: transform .15s ease;
			color: <?php echo esc_html( $qlqr_theme['muted'] ); ?>;
		}
		.qlqr-bio-group[open] .qlqr-bio-group-chevron {
			transform: rotate(180deg);
		}
		.qlqr-bio-group-children {
			display: flex;
			flex-direction: column;
			gap: 10px;
			padding: 0 12px 12px;
		}
		.qlqr-bio-group-children .qlqr-bio-link {
			border-radius: calc(<?php echo esc_html( $qlqr_radius ); ?> - 2px);
		}
		/* Floating Contact Buttons — a fixed-position stack, independent of page scroll. */
		.qlqr-bio-floating-stack {
			position: fixed;
			right: 18px;
			bottom: 18px;
			display: flex;
			flex-direction: column-reverse;
			gap: 10px;
			z-index: 100;
		}
		.qlqr-bio-floating-btn {
			width: 52px;
			height: 52px;
			border-radius: 50%;
			display: flex;
			align-items: center;
			justify-content: center;
			background: <?php echo esc_html( $bio_page->theme_color ); ?>;
			color: #fff;
			text-decoration: none;
			font-size: 22px;
			font-weight: 700;
			box-shadow: 0 4px 14px rgba(0,0,0,.25);
			transition: transform .12s ease;
		}
		.qlqr-bio-floating-btn:hover {
			transform: scale(1.06);
		}
		.qlqr-bio-floating-btn svg {
			width: 22px;
			height: 22px;
			display: block;
		}
		@media (max-width: 480px) {
			.qlqr-bio-floating-stack { right: 12px; bottom: 12px; }
		}
		.qlqr-bio-pdf-badge {
			display: inline-block;
			margin-left: 6px;
			padding: 1px 6px;
			border-radius: 4px;
			font-size: 10px;
			font-weight: 700;
			letter-spacing: .02em;
			background: <?php echo esc_html( $bio_page->theme_color ); ?>;
			color: #fff;
			vertical-align: middle;
		}
	<?php
	$qlqr_inline_css = ob_get_clean();
	wp_register_style( 'qlqr-bio-page-inline', false, array(), QLQR_VERSION );
	wp_enqueue_style( 'qlqr-bio-page-inline' );
	wp_add_inline_style( 'qlqr-bio-page-inline', $qlqr_inline_css );
	wp_print_styles( 'qlqr-bio-page-inline' );
	?>
</head>
<body>
	<?php if ( $qlqr_show_announcement ) : ?>
		<div class="qlqr-bio-announcement" id="qlqr-bio-announcement" data-key="qlqr_bio_announcement_dismissed_<?php echo esc_attr( (string) $bio_page->id ); ?>">
			<?php if ( $bio_page->announcement_url ) : ?>
				<a href="<?php echo esc_url( $bio_page->announcement_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $bio_page->announcement_text ); ?></a>
			<?php else : ?>
				<?php echo esc_html( $bio_page->announcement_text ); ?>
			<?php endif; ?>
			<button type="button" class="qlqr-bio-announcement-close" id="qlqr-bio-announcement-close" aria-label="<?php esc_attr_e( 'Dismiss', 'plugnova-link-shortener-qr' ); ?>">&times;</button>
		</div>
	<?php endif; ?>

	<div class="qlqr-bio-card">
		<?php if ( $bio_page->avatar_url ) : ?>
			<img class="qlqr-bio-avatar" src="<?php echo esc_url( $bio_page->avatar_url ); ?>" alt="<?php echo esc_attr( $bio_page->title ); ?>" />
		<?php else : ?>
			<div class="qlqr-bio-avatar-fallback"><?php echo esc_html( $qlqr_avatar_initial ); ?></div>
		<?php endif; ?>

		<h1 class="qlqr-bio-title"><?php echo esc_html( $bio_page->title ); ?></h1>

		<?php if ( $bio_page->bio_text ) : ?>
			<p class="qlqr-bio-text"><?php echo esc_html( $bio_page->bio_text ); ?></p>
		<?php endif; ?>

		<?php if ( ! empty( $qlqr_inline_socials ) ) : ?>
			<div class="qlqr-bio-socials">
				<?php foreach ( $qlqr_inline_socials as $qlqr_social ) : ?>
					<a class="qlqr-bio-social" href="<?php echo esc_url( $qlqr_social->url ); ?>" target="_blank" rel="noopener" title="<?php echo esc_attr( ucfirst( $qlqr_social->platform ) ); ?>">
						<?php echo wp_kses( $qlqr_social->icon_svg(), \QuickLinkQRPro\Models\BioSocial::icon_svg_allowed_html() ); ?>
					</a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php if ( $show_email_gate ) : ?>
			<div class="qlqr-bio-gate">
				<p class="qlqr-bio-gate-heading"><?php echo esc_html( $bio_page->email_capture_heading ?: __( 'Enter your email to see the links', 'plugnova-link-shortener-qr' ) ); ?></p>
				<form method="post">
					<?php wp_nonce_field( 'qlqr_bio_email_capture', 'qlqr_bio_email_nonce' ); ?>
					<input type="email" name="qlqr_bio_email" class="qlqr-bio-gate-input" placeholder="<?php esc_attr_e( 'you@example.com', 'plugnova-link-shortener-qr' ); ?>" required />
					<button type="submit" class="qlqr-bio-gate-submit"><?php esc_html_e( 'Continue', 'plugnova-link-shortener-qr' ); ?></button>
				</form>
			</div>
		<?php else : ?>
			<?php if ( $qlqr_show_countdown ) : ?>
				<?php
				/*
				 * get_gmt_from_date(), not strtotime(): countdown_target_at is stored as a site-local
				 * datetime, and WordPress fixes PHP's timezone to UTC — so strtotime() would read that
				 * local wall time as if it were UTC and hand the browser an epoch shifted by the site's
				 * UTC offset (a +06:00 site would count down six hours late). get_gmt_from_date()
				 * interprets the string in the site timezone and converts it to a true UTC timestamp,
				 * which is the same clock the JS Date.now() below reads.
				 */
				$qlqr_countdown_ms = (int) get_gmt_from_date( $bio_page->countdown_target_at, 'U' ) * 1000;
				?>
				<div class="qlqr-bio-countdown" id="qlqr-bio-countdown" data-target="<?php echo esc_attr( (string) $qlqr_countdown_ms ); ?>">
					<?php if ( $bio_page->countdown_label ) : ?>
						<p class="qlqr-bio-countdown-label"><?php echo esc_html( $bio_page->countdown_label ); ?></p>
					<?php endif; ?>
					<div class="qlqr-bio-countdown-units">
						<div class="qlqr-bio-countdown-unit"><span class="qlqr-bio-countdown-value" data-unit="d">00</span><span class="qlqr-bio-countdown-unit-label"><?php esc_html_e( 'Days', 'plugnova-link-shortener-qr' ); ?></span></div>
						<div class="qlqr-bio-countdown-unit"><span class="qlqr-bio-countdown-value" data-unit="h">00</span><span class="qlqr-bio-countdown-unit-label"><?php esc_html_e( 'Hrs', 'plugnova-link-shortener-qr' ); ?></span></div>
						<div class="qlqr-bio-countdown-unit"><span class="qlqr-bio-countdown-value" data-unit="m">00</span><span class="qlqr-bio-countdown-unit-label"><?php esc_html_e( 'Min', 'plugnova-link-shortener-qr' ); ?></span></div>
						<div class="qlqr-bio-countdown-unit"><span class="qlqr-bio-countdown-value" data-unit="s">00</span><span class="qlqr-bio-countdown-unit-label"><?php esc_html_e( 'Sec', 'plugnova-link-shortener-qr' ); ?></span></div>
					</div>
				</div>
			<?php endif; ?>

			<div class="qlqr-bio-links">
				<?php if ( empty( $button_groups ) ) : ?>
					<p class="qlqr-bio-empty"><?php esc_html_e( 'No links have been added yet.', 'plugnova-link-shortener-qr' ); ?></p>
				<?php else : ?>
					<?php
					/**
					 * Render a single button's inner markup (used both for top-level buttons and for
					 * buttons nested inside a group), so the two call sites never drift apart.
					 *
					 * @param \QuickLinkQRPro\Models\BioLink $button   Button to render.
					 * @param \QuickLinkQRPro\Models\BioPage $bio_page Owning bio page (for the click URL).
					 */
					if ( ! function_exists( 'qlqr_render_bio_button' ) ) :
					function qlqr_render_bio_button( \QuickLinkQRPro\Models\BioLink $button, \QuickLinkQRPro\Models\BioPage $bio_page ): void {
						$href = esc_url( $bio_page->get_public_url() . '/click/' . $button->id );

						// A button whose destination is a .pdf file gets a "download" hint on the
						// anchor (honored by most browsers when the final response doesn't itself
						// force inline display) plus a small badge, so visitors know what they're
						// about to get before clicking through the /click/{id} redirect.
						$is_pdf = '.pdf' === strtolower( (string) substr( (string) wp_parse_url( $button->url, PHP_URL_PATH ), -4 ) );

						if ( $button->image_url ) :
							?>
							<a class="qlqr-bio-link qlqr-bio-link-card" href="<?php echo $href; // phpcs:ignore WordPress.Security.EscapeOutput -- already esc_url()'d above. ?>" target="_blank" rel="noopener"<?php echo $is_pdf ? ' download' : ''; ?>>
								<img class="qlqr-bio-link-thumb" src="<?php echo esc_url( $button->image_url ); ?>" alt="" />
								<span class="qlqr-bio-link-label"><?php echo esc_html( $button->label ); ?><?php if ( $is_pdf ) : ?><span class="qlqr-bio-pdf-badge">PDF</span><?php endif; ?></span>
							</a>
							<?php
						else :
							?>
							<a class="qlqr-bio-link" href="<?php echo $href; // phpcs:ignore WordPress.Security.EscapeOutput -- already esc_url()'d above. ?>" target="_blank" rel="noopener"<?php echo $is_pdf ? ' download' : ''; ?>>
								<?php if ( $button->icon ) : ?><span class="qlqr-bio-icon"><?php echo esc_html( $button->icon ); ?></span><?php endif; ?>
								<?php echo esc_html( $button->label ); ?><?php if ( $is_pdf ) : ?><span class="qlqr-bio-pdf-badge">PDF</span><?php endif; ?>
							</a>
							<?php
						endif;
					}
					endif;
					?>
					<?php foreach ( $button_groups as $qlqr_entry ) : ?>
						<?php if ( 'group' === $qlqr_entry['type'] ) : ?>
							<details class="qlqr-bio-group">
								<summary class="qlqr-bio-group-header">
									<span><?php if ( $qlqr_entry['item']->icon ) : ?><span class="qlqr-bio-icon"><?php echo esc_html( $qlqr_entry['item']->icon ); ?></span><?php endif; ?><?php echo esc_html( $qlqr_entry['item']->label ); ?></span>
									<span class="qlqr-bio-group-chevron">▾</span>
								</summary>
								<div class="qlqr-bio-group-children">
									<?php foreach ( $qlqr_entry['children'] as $qlqr_child ) : ?>
										<?php qlqr_render_bio_button( $qlqr_child, $bio_page ); ?>
									<?php endforeach; ?>
								</div>
							</details>
						<?php else : ?>
							<?php qlqr_render_bio_button( $qlqr_entry['item'], $bio_page ); ?>
						<?php endif; ?>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>

	<?php if ( ! empty( $qlqr_floating_socials ) ) : ?>
		<div class="qlqr-bio-floating-stack">
			<?php foreach ( $qlqr_floating_socials as $qlqr_social ) : ?>
				<a class="qlqr-bio-floating-btn" href="<?php echo esc_url( $qlqr_social->url ); ?>" target="_blank" rel="noopener" title="<?php echo esc_attr( ucfirst( $qlqr_social->platform ) ); ?>">
					<?php echo wp_kses( $qlqr_social->icon_svg(), \QuickLinkQRPro\Models\BioSocial::icon_svg_allowed_html() ); ?>
				</a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php if ( $qlqr_show_announcement || $qlqr_show_countdown ) : ?>
		<?php
		// This page is intentionally standalone (see the file docblock) and never calls wp_footer(),
		// so wp_print_scripts() is invoked directly at the point the script belongs, the same way
		// the <head> styles above go through wp_print_styles() instead of a literal <style> tag.
		wp_enqueue_script( 'qlqr-bio-page', QLQR_PLUGIN_URL . 'assets/js/bio-page.js', array(), QLQR_VERSION, true );
		wp_print_scripts( 'qlqr-bio-page' );
		?>
	<?php endif; ?>
</body>
</html>
