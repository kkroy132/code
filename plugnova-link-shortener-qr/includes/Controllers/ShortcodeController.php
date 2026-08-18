<?php
/**
 * Registers the plugin's public shortcodes: [qlqr], [qlqr_qr], [qlqr_button], [qlqr_stats],
 * [qlqr_bio], [qlqr_bio_qr].
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Controllers;

use QuickLinkQRPro\Database\BioPagesRepository;
use QuickLinkQRPro\Database\ClicksRepository;
use QuickLinkQRPro\Database\LinksRepository;
use QuickLinkQRPro\Models\BioPage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ShortcodeController
 */
final class ShortcodeController {

	/**
	 * Links repository.
	 *
	 * @var LinksRepository
	 */
	private LinksRepository $links;

	/**
	 * Clicks repository.
	 *
	 * @var ClicksRepository
	 */
	private ClicksRepository $clicks;

	/**
	 * Bio pages repository.
	 *
	 * @var BioPagesRepository
	 */
	private BioPagesRepository $bio_pages;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->links     = new LinksRepository();
		$this->clicks    = new ClicksRepository();
		$this->bio_pages = new BioPagesRepository();
	}

	/**
	 * Register all shortcode hooks.
	 */
	public function register(): void {
		add_shortcode( 'qlqr', array( $this, 'render_short_link' ) );
		add_shortcode( 'qlqr_qr', array( $this, 'render_qr_image' ) );
		add_shortcode( 'qlqr_button', array( $this, 'render_button' ) );
		add_shortcode( 'qlqr_stats', array( $this, 'render_stats' ) );
		add_shortcode( 'qlqr_bio', array( $this, 'render_bio_link' ) );
		add_shortcode( 'qlqr_bio_qr', array( $this, 'render_bio_qr_image' ) );
	}

	/**
	 * Look up a bio page by either its numeric "id" attribute or its "slug" attribute (id wins
	 * if both are given), so authors can reference a page however is more convenient for them.
	 *
	 * @param array<string, mixed> $atts Shortcode attributes, already merged via shortcode_atts().
	 * @return BioPage|null
	 */
	private function find_bio_page( array $atts ): ?BioPage {
		if ( ! empty( $atts['id'] ) ) {
			return $this->bio_pages->find( (int) $atts['id'] );
		}

		if ( ! empty( $atts['slug'] ) ) {
			return $this->bio_pages->find_by_slug( sanitize_text_field( (string) $atts['slug'] ) );
		}

		return null;
	}

	/**
	 * [qlqr id="10"] — Prints the plain short URL as a link.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes — WordPress passes an empty
	 *                                          string, not an array, when none are supplied.
	 * @return string
	 */
	public function render_short_link( $atts ): string {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'qlqr' );
		$link = $this->links->find( (int) $atts['id'] );

		if ( ! $link ) {
			return '';
		}

		$target = $link->new_tab ? ' target="_blank" rel="noopener' . ( $link->nofollow ? ' nofollow' : '' ) . ( $link->sponsored ? ' sponsored' : '' ) . '"' : '';

		return sprintf(
			'<a class="qlqr-short-link" href="%1$s"%2$s>%1$s</a>',
			esc_url( $link->get_short_url() ),
			$target // phpcs:ignore WordPress.Security.EscapeOutput -- built entirely from trusted boolean flags above, no user input.
		);
	}

	/**
	 * [qlqr_qr id="10"] — Prints the generated QR code image.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes — WordPress passes an empty
	 *                                          string, not an array, when none are supplied.
	 * @return string
	 */
	public function render_qr_image( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'    => 0,
				'size'  => 200,
				'class' => '',
			),
			$atts,
			'qlqr_qr'
		);

		$link = $this->links->find( (int) $atts['id'] );

		if ( ! $link || empty( $link->qr_image ) ) {
			return '';
		}

		return sprintf(
			'<img class="qlqr-qr-image %1$s" src="%2$s" width="%3$d" height="%3$d" alt="%4$s" loading="lazy" />',
			esc_attr( (string) $atts['class'] ),
			esc_url( $link->qr_image ),
			(int) $atts['size'],
			esc_attr(
				sprintf(
					/* translators: %s: link title or short URL */
					__( 'QR code for %s', 'plugnova-link-shortener-qr' ),
					$link->title ?: $link->get_short_url()
				)
			)
		);
	}

	/**
	 * [qlqr_button id="10" text="Download"] — Prints a styled call-to-action button linking to the short URL.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes — WordPress passes an empty
	 *                                          string, not an array, when none are supplied.
	 * @return string
	 */
	public function render_button( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'   => 0,
				'text' => __( 'Visit Link', 'plugnova-link-shortener-qr' ),
			),
			$atts,
			'qlqr_button'
		);

		$link = $this->links->find( (int) $atts['id'] );
		if ( ! $link ) {
			return '';
		}

		$target = $link->new_tab ? ' target="_blank" rel="noopener' . ( $link->nofollow ? ' nofollow' : '' ) . ( $link->sponsored ? ' sponsored' : '' ) . '"' : '';

		return sprintf(
			'<a class="qlqr-button" href="%1$s"%2$s>%3$s</a>',
			esc_url( $link->get_short_url() ),
			$target, // phpcs:ignore WordPress.Security.EscapeOutput -- built entirely from trusted boolean flags above, no user input.
			esc_html( (string) $atts['text'] )
		);
	}

	/**
	 * [qlqr_stats id="10"] — Prints a minimal public click-count widget for a link.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes — WordPress passes an empty
	 *                                          string, not an array, when none are supplied.
	 * @return string
	 */
	public function render_stats( $atts ): string {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'qlqr_stats' );
		$link = $this->links->find( (int) $atts['id'] );

		if ( ! $link ) {
			return '';
		}

		return sprintf(
			'<span class="qlqr-stats">%s</span>',
			esc_html(
				sprintf(
					/* translators: %s: formatted click count */
					_n( '%s click', '%s clicks', $link->total_clicks, 'plugnova-link-shortener-qr' ),
					number_format_i18n( $link->total_clicks )
				)
			)
		);
	}

	/**
	 * [qlqr_bio id="10"] or [qlqr_bio slug="janedoe" text="View my links"] — Prints a link/button
	 * to a Smart Bio Link page's public URL.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes — WordPress passes an empty
	 *                                          string, not an array, when none are supplied.
	 * @return string
	 */
	public function render_bio_link( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'    => 0,
				'slug'  => '',
				'text'  => '',
				'class' => 'qlqr-bio-shortcode-link',
			),
			$atts,
			'qlqr_bio'
		);

		$bio_page = $this->find_bio_page( $atts );
		if ( ! $bio_page || ! $bio_page->is_viewable() ) {
			return '';
		}

		$text = '' !== $atts['text'] ? (string) $atts['text'] : $bio_page->title;

		return sprintf(
			'<a class="%1$s" href="%2$s" target="_blank" rel="noopener">%3$s</a>',
			esc_attr( (string) $atts['class'] ),
			esc_url( $bio_page->get_public_url() ),
			esc_html( $text )
		);
	}

	/**
	 * [qlqr_bio_qr id="10"] or [qlqr_bio_qr slug="janedoe" size="200"] — Prints a Smart Bio Link
	 * page's QR code image.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes — WordPress passes an empty
	 *                                          string, not an array, when none are supplied.
	 * @return string
	 */
	public function render_bio_qr_image( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'    => 0,
				'slug'  => '',
				'size'  => 200,
				'class' => '',
			),
			$atts,
			'qlqr_bio_qr'
		);

		$bio_page = $this->find_bio_page( $atts );
		if ( ! $bio_page || empty( $bio_page->qr_image ) ) {
			return '';
		}

		return sprintf(
			'<img class="qlqr-bio-qr-image %1$s" src="%2$s" width="%3$d" height="%3$d" alt="%4$s" loading="lazy" />',
			esc_attr( (string) $atts['class'] ),
			esc_url( $bio_page->qr_image ),
			(int) $atts['size'],
			esc_attr(
				sprintf(
					/* translators: %s: bio page title */
					__( 'QR code for %s', 'plugnova-link-shortener-qr' ),
					$bio_page->title
				)
			)
		);
	}
}
