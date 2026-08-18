<?php
/**
 * Handles the public-facing Smart Bio Link page: /{bio_prefix}/{slug} (renders the landing page)
 * and /{bio_prefix}/{slug}/click/{id} (records a button click, then redirects).
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Controllers;

use QuickLinkQRPro\Database\BioEmailsRepository;
use QuickLinkQRPro\Database\BioLinksRepository;
use QuickLinkQRPro\Database\BioPagesRepository;
use QuickLinkQRPro\Database\BioSocialsRepository;
use QuickLinkQRPro\Database\BioViewsRepository;
use QuickLinkQRPro\Helpers\BotDetector;
use QuickLinkQRPro\Helpers\DeviceDetector;
use QuickLinkQRPro\Helpers\GeoLocator;
use QuickLinkQRPro\Helpers\IpHash;
use QuickLinkQRPro\Helpers\Options;
use QuickLinkQRPro\Models\BioPage;
use QuickLinkQRPro\Security\RateLimiter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BioController
 */
final class BioController {

	/**
	 * Query var carrying the matched bio page slug.
	 */
	private const QUERY_VAR_SLUG = 'qlqr_bio_slug';

	/**
	 * Query var carrying the button ID for a click-through request. Only present on the
	 * /{bio_prefix}/{slug}/click/{id} route.
	 */
	private const QUERY_VAR_CLICK_ID = 'qlqr_bio_click_id';

	/**
	 * Bio pages repository.
	 *
	 * @var BioPagesRepository
	 */
	private BioPagesRepository $pages;

	/**
	 * Bio links (buttons) repository.
	 *
	 * @var BioLinksRepository
	 */
	private BioLinksRepository $links;

	/**
	 * Social icon row repository.
	 *
	 * @var BioSocialsRepository
	 */
	private BioSocialsRepository $socials;

	/**
	 * Captured-email repository, for the optional email-capture gate.
	 *
	 * @var BioEmailsRepository
	 */
	private BioEmailsRepository $emails;

	/**
	 * Page-view event repository, for the analytics dashboard (distinct from the buttons'
	 * per-click counters).
	 *
	 * @var BioViewsRepository
	 */
	private BioViewsRepository $views;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->pages   = new BioPagesRepository();
		$this->links   = new BioLinksRepository();
		$this->socials = new BioSocialsRepository();
		$this->emails  = new BioEmailsRepository();
		$this->views   = new BioViewsRepository();
	}

	/**
	 * Register rewrite rules and request handling hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'add_rewrite_rule' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );

		// Handle the email-capture gate's and password-protection form's submissions before any
		// output is sent — mirrors RedirectController::maybe_handle_password_submission() for the
		// same reason: setting the "unlocked" session flag here lets the very same request fall
		// through to maybe_handle_request() below and immediately render the unlocked page.
		add_action( 'template_redirect', array( $this, 'maybe_handle_email_capture_submission' ), 5 );
		add_action( 'template_redirect', array( $this, 'maybe_handle_password_submission' ), 5 );
		add_action( 'template_redirect', array( $this, 'maybe_handle_request' ) );
	}

	/**
	 * Add the /{bio_prefix}/{slug} and /{bio_prefix}/{slug}/click/{id} rewrite rules based on the
	 * configured bio URL prefix.
	 */
	public function add_rewrite_rule(): void {
		$prefix = trim( (string) Options::get( 'qlqr_bio_url_prefix' ), '/' );
		$prefix = preg_replace( '/[^a-z0-9\-_]/i', '', $prefix ) ?: 'bio';

		// Registered before the plain page-view rule so a click URL never has a chance to be
		// mistaken for a slug containing literal "/click/123" text (it can't be, since slugs are
		// validated to exclude "/", but matching the more specific pattern first is still the
		// clearer intent).
		add_rewrite_rule(
			'^' . $prefix . '/([a-zA-Z0-9\-_]+)/click/([0-9]+)/?$',
			'index.php?' . self::QUERY_VAR_SLUG . '=$matches[1]&' . self::QUERY_VAR_CLICK_ID . '=$matches[2]',
			'top'
		);

		add_rewrite_rule(
			'^' . $prefix . '/([a-zA-Z0-9\-_]+)/?$',
			'index.php?' . self::QUERY_VAR_SLUG . '=$matches[1]',
			'top'
		);
	}

	/**
	 * Register the custom query vars used above.
	 *
	 * @param string[] $vars Existing query vars.
	 * @return string[]
	 */
	public function register_query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR_SLUG;
		$vars[] = self::QUERY_VAR_CLICK_ID;
		return $vars;
	}

	/**
	 * Main entry point: if the current request matches a bio page (or one of its button click
	 * links), process it and exit.
	 */
	public function maybe_handle_request(): void {
		$slug = get_query_var( self::QUERY_VAR_SLUG );

		if ( empty( $slug ) ) {
			return;
		}

		$slug = sanitize_text_field( (string) $slug );

		/*
		 * Tell any page-cache plugin in front of WordPress to leave this request alone.
		 * DONOTCACHEPAGE is the de-facto standard constant, honoured by LiteSpeed Cache, WP Rocket,
		 * W3 Total Cache, WP Super Cache and others. A cached bio page is served without PHP ever
		 * running, which means the view is never recorded — the visit simply never happens as far as this
		 * plugin is concerned. Defined before any of the work below, so it applies to every branch.
		 */
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// Deliberately unprefixed: this is a third-party constant with a fixed, agreed name that
			// caching plugins look for. A qlqr_-prefixed one would mean nothing to any of them.
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
		}

		$rate_limiter = new RateLimiter();
		if ( ! $rate_limiter->allow( 'bio', (int) Options::get( 'qlqr_rate_limit_per_min' ) * 5 ) ) {
			status_header( 429 );
			wp_die( esc_html__( 'Too many requests. Please try again shortly.', 'plugnova-link-shortener-qr' ), '', array( 'response' => 429 ) );
		}

		$bio_page = $this->pages->find_by_slug( $slug );

		if ( ! $bio_page || ! $bio_page->is_viewable() ) {
			$this->render_not_found();
			return;
		}

		$schedule_status = $bio_page->campaign_schedule_status();
		if ( 'active' !== $schedule_status ) {
			$this->render_schedule_gate( $schedule_status );
			return;
		}

		// Gated before the click-through branch too, not just the page render — otherwise a
		// password-protected page's protection could be bypassed by hitting a button's
		// /click/{id} URL directly, skipping the landing page entirely.
		if ( $bio_page->is_password_protected() && ! $this->has_valid_password_session( $bio_page ) ) {
			$this->render_password_form( $bio_page );
			return;
		}

		$click_id = get_query_var( self::QUERY_VAR_CLICK_ID );

		if ( '' !== $click_id ) {
			$this->handle_click( $bio_page, (int) $click_id );
			return;
		}

		$this->render_page( $bio_page );
	}

	/**
	 * Record a button click and redirect the visitor to its target URL.
	 *
	 * @param BioPage $bio_page Matched bio page.
	 * @param int     $button_id Button row ID (must belong to $bio_page).
	 */
	private function handle_click( BioPage $bio_page, int $button_id ): void {
		$button = $this->links->find( $button_id, $bio_page->id );

		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$ip         = IpHash::get_request_ip();
		$country    = $ip ? GeoLocator::country_for_ip( $ip ) : null;
		$device     = DeviceDetector::device_type( $user_agent );

		if ( ! $button || $button->is_group_header() || ! $button->is_currently_visible( $country, $device ) ) {
			$this->render_not_found();
			return;
		}

		if ( Options::get( 'qlqr_tracking_enabled' ) ) {
			$this->links->increment_click( $button->id );
		}

		// Intentionally wp_redirect(), not wp_safe_redirect(): same rationale as
		// RedirectController::do_redirect() — arbitrary external destinations are this plugin's
		// whole purpose, and the URL was already validated at save time via wp_http_validate_url().
		wp_redirect( esc_url_raw( $button->url ), 302 ); // phpcs:ignore WordPress.Security.SafeRedirect
		exit;
	}

	/**
	 * Render the public bio page.
	 *
	 * @param BioPage $bio_page Matched, viewable bio page.
	 */
	private function render_page( BioPage $bio_page ): void {
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$ip         = IpHash::get_request_ip();
		$country    = $ip ? GeoLocator::country_for_ip( $ip ) : null;
		$device     = DeviceDetector::device_type( $user_agent );

		if ( Options::get( 'qlqr_tracking_enabled' ) ) {
			$via_qr = isset( $_GET['qlqr_src'] ) && 'qr' === $_GET['qlqr_src']; // phpcs:ignore WordPress.Security.NonceVerification -- read-only marker, never persisted or echoed.
			$this->pages->increment_view_count( $bio_page->id, $via_qr );
			$this->record_view( $bio_page->id, $via_qr, $user_agent, $ip, $country, $device );
		}

		nocache_headers();

		$show_email_gate = $bio_page->email_capture_enabled && ! $this->has_captured_email( $bio_page->id );

		$buttons = array_values(
			array_filter(
				$this->links->find_by_page( $bio_page->id, true ),
				// A group header has no schedule/country/device restriction of its own (see
				// BioPageController::sanitize_links()), so this check is uniform across both row
				// types — a header is filtered out only via find_by_page()'s active-only clause.
				static fn( \QuickLinkQRPro\Models\BioLink $button ): bool => $button->is_currently_visible( $country, $device )
			)
		);
		$button_groups = $this->group_buttons( $buttons );
		$socials       = $this->socials->find_by_page( $bio_page->id, true );

		$template = QLQR_PLUGIN_DIR . 'templates/bio-page.php';

		if ( is_readable( $template ) ) {
			include $template;
		}

		exit;
	}

	/**
	 * Arrange a flat, already-visibility-filtered list of buttons into the nested structure
	 * templates/bio-page.php renders: top-level items in their original order, each either a
	 * plain link or a group header carrying its member links (matched by group_key/
	 * parent_group_key rather than a database ID, since both are saved together in one batch —
	 * see BioLinksRepository::replace_for_page()). A link whose parent_group_key doesn't match
	 * any header in the (visible) list — e.g. its group got disabled/removed — falls back to
	 * rendering top-level rather than silently disappearing.
	 *
	 * @param \QuickLinkQRPro\Models\BioLink[] $buttons Flat, ordered, already-visible buttons.
	 * @return array<int, array{type:string, item:\QuickLinkQRPro\Models\BioLink, children?:\QuickLinkQRPro\Models\BioLink[]}>
	 */
	private function group_buttons( array $buttons ): array {
		$header_keys = array();
		foreach ( $buttons as $button ) {
			if ( $button->is_group_header() && $button->group_key ) {
				$header_keys[ $button->group_key ] = true;
			}
		}

		$children_by_group = array();
		foreach ( $buttons as $button ) {
			if ( ! $button->is_group_header() && $button->parent_group_key && isset( $header_keys[ $button->parent_group_key ] ) ) {
				$children_by_group[ $button->parent_group_key ][] = $button;
			}
		}

		$grouped = array();
		foreach ( $buttons as $button ) {
			if ( $button->is_group_header() ) {
				if ( $button->group_key && ! empty( $children_by_group[ $button->group_key ] ) ) {
					$grouped[] = array(
						'type'     => 'group',
						'item'     => $button,
						'children' => $children_by_group[ $button->group_key ],
					);
				}
				// An empty group (no visible children) is skipped entirely — an accordion with
				// nothing inside it would just be a dead click for visitors.
				continue;
			}

			if ( $button->parent_group_key && isset( $header_keys[ $button->parent_group_key ] ) ) {
				continue; // Already rendered as a child of its group above.
			}

			$grouped[] = array(
				'type' => 'link',
				'item' => $button,
			);
		}

		return $grouped;
	}

	/**
	 * Record a page-view event for the analytics dashboard. Separate from
	 * BioPagesRepository::increment_view_count()'s cached counters, which stay cheap headline
	 * numbers — this table backs the "Views over time" chart and country/device breakdowns,
	 * mirroring Controllers\RedirectController::record_click() for regular links.
	 *
	 * @param int         $bio_page_id Bio page ID.
	 * @param bool        $via_qr      Whether this view came from a scanned QR code.
	 * @param string      $user_agent  Raw User-Agent header, already computed by render_page() (also
	 *                                  needed there for button visibility filtering, so it isn't
	 *                                  recomputed here).
	 * @param string      $ip          Visitor IP, already resolved by render_page().
	 * @param string|null $country     Visitor country, already resolved by render_page().
	 * @param string      $device      Visitor device type, already resolved by render_page().
	 */
	private function record_view( int $bio_page_id, bool $via_qr, string $user_agent, string $ip, ?string $country, string $device ): void {
		$is_bot   = BotDetector::is_bot( $user_agent );
		$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';

		$this->views->insert(
			array(
				'bio_page_id'      => $bio_page_id,
				'country'          => $country,
				'device'           => $device,
				// Same breakdown dimensions Controllers\RedirectController::record_click() captures
				// for short links, so a bio page's analytics modal can offer the same Browser/OS/
				// Referrer/Campaign tables instead of the thinner country+device set it started with.
				'browser'          => DeviceDetector::browser( $user_agent ),
				'operating_system' => DeviceDetector::operating_system( $user_agent ),
				'referrer'         => $referrer ? wp_parse_url( $referrer, PHP_URL_HOST ) : '',
				'utm_source'       => isset( $_GET['utm_source'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_source'] ) ) : null, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public page view, not a form submission; these are read-only analytics dimensions
				'utm_medium'       => isset( $_GET['utm_medium'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_medium'] ) ) : null, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public page view, not a form submission; these are read-only analytics dimensions
				'utm_campaign'     => isset( $_GET['utm_campaign'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_campaign'] ) ) : null, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public page view, not a form submission; these are read-only analytics dimensions
				'source'           => $via_qr ? 'qr' : 'link',
				'ip_hash'          => IpHash::hash( $ip ),
				'is_bot'           => $is_bot ? 1 : 0,
				'viewed_at'        => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Handle POST submission of the email-capture gate form.
	 */
	public function maybe_handle_email_capture_submission(): void {
		$slug = get_query_var( self::QUERY_VAR_SLUG );
		if ( empty( $slug ) || 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			return;
		}

		if ( ! isset( $_POST['qlqr_bio_email_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['qlqr_bio_email_nonce'] ) ), 'qlqr_bio_email_capture' ) ) {
			return;
		}

		$bio_page = $this->pages->find_by_slug( sanitize_text_field( (string) $slug ) );
		if ( ! $bio_page || ! $bio_page->email_capture_enabled ) {
			return;
		}

		$email = isset( $_POST['qlqr_bio_email'] ) ? sanitize_email( wp_unslash( $_POST['qlqr_bio_email'] ) ) : '';
		if ( '' === $email || ! is_email( $email ) ) {
			return;
		}

		$this->emails->insert( $bio_page->id, $email );
		$this->mark_email_captured( $bio_page->id );
	}

	/**
	 * Handle POST submission of the password-protection form. Mirrors
	 * RedirectController::maybe_handle_password_submission() exactly, scoped to a bio page instead
	 * of a short link.
	 */
	public function maybe_handle_password_submission(): void {
		$slug = get_query_var( self::QUERY_VAR_SLUG );
		if ( empty( $slug ) || 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			return;
		}

		if ( ! isset( $_POST['qlqr_bio_password_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['qlqr_bio_password_nonce'] ) ), 'qlqr_bio_password_form' ) ) {
			return;
		}

		$bio_page = $this->pages->find_by_slug( sanitize_text_field( (string) $slug ) );
		if ( ! $bio_page || ! $bio_page->is_password_protected() ) {
			return;
		}

		// Deliberately unslashed but NOT sanitized: this is a password being compared against a
		// hash, so stripping or altering characters would corrupt a legitimate secret. It is never
		// echoed, stored or concatenated into SQL.
		$submitted = isset( $_POST['qlqr_bio_password'] ) ? (string) wp_unslash( $_POST['qlqr_bio_password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( wp_check_password( $submitted, (string) $bio_page->password ) ) {
			$this->mark_password_valid( $bio_page );
		}
	}

	/**
	 * Whether the current visitor has already entered the correct password for a bio page. Uses a
	 * separate cookie from has_captured_email() so a page can require both a password AND an email
	 * (the password gate is checked first — see maybe_handle_request()). The cookie is bound to the
	 * stored password hash, so changing the page's password invalidates old unlock cookies.
	 *
	 * @param BioPage $bio_page Bio page.
	 * @return bool
	 */
	private function has_valid_password_session( BioPage $bio_page ): bool {
		return \QuickLinkQRPro\Helpers\GateCookie::is_passed( 'bio_pw_' . $bio_page->id, (string) $bio_page->password );
	}

	/**
	 * Record that the visitor has entered the correct password for a bio page (sends a signed cookie).
	 *
	 * @param BioPage $bio_page Bio page.
	 */
	private function mark_password_valid( BioPage $bio_page ): void {
		\QuickLinkQRPro\Helpers\GateCookie::mark_passed( 'bio_pw_' . $bio_page->id, (string) $bio_page->password );
	}

	/**
	 * Render a minimal password entry form for a protected bio page, styled to match the page's
	 * own theme (unlike templates/password-form.php, which borrows the site theme's header/footer
	 * — a bio page is otherwise always a fully standalone document, so its password gate stays
	 * standalone too).
	 *
	 * @param BioPage $bio_page Matched, password-protected bio page.
	 */
	private function render_password_form( BioPage $bio_page ): void {
		nocache_headers();

		$template = QLQR_PLUGIN_DIR . 'templates/bio-password-form.php';
		if ( is_readable( $template ) ) {
			include $template;
		}
		exit;
	}

	/**
	 * Whether the current visitor has already unlocked a given bio page's email-capture gate.
	 *
	 * @param int $bio_page_id Bio page ID.
	 * @return bool
	 */
	private function has_captured_email( int $bio_page_id ): bool {
		return \QuickLinkQRPro\Helpers\GateCookie::is_passed( 'bio_email_' . $bio_page_id );
	}

	/**
	 * Record that the visitor has submitted an email for a given bio page's gate (sends a cookie).
	 *
	 * @param int $bio_page_id Bio page ID.
	 */
	private function mark_email_captured( int $bio_page_id ): void {
		\QuickLinkQRPro\Helpers\GateCookie::mark_passed( 'bio_email_' . $bio_page_id );
	}

	/**
	 * Render a 404-style "page not found" page.
	 */
	private function render_not_found(): void {
		status_header( 404 );
		nocache_headers();
		wp_die(
			esc_html__( 'This bio page does not exist.', 'plugnova-link-shortener-qr' ),
			esc_html__( 'Page Not Found', 'plugnova-link-shortener-qr' ),
			array( 'response' => 404 )
		);
	}

	/**
	 * Render a friendly "not available yet" / "campaign ended" page for a page whose optional
	 * Campaign Scheduling window ($bio_page->starts_at / ->ends_at) doesn't currently include now.
	 * A 503 (rather than 404) is used since the page genuinely exists — it's just not the right
	 * time to see it — matching the convention search engines/monitoring tools expect for
	 * temporarily-unavailable content.
	 *
	 * @param string $status 'not_started' or 'ended', from BioPage::campaign_schedule_status().
	 */
	private function render_schedule_gate( string $status ): void {
		status_header( 503 );
		nocache_headers();

		$message = 'not_started' === $status
			? __( 'This page isn\'t available yet — check back soon.', 'plugnova-link-shortener-qr' )
			: __( 'This page\'s campaign has ended.', 'plugnova-link-shortener-qr' );

		wp_die(
			esc_html( $message ),
			esc_html__( 'Not Available', 'plugnova-link-shortener-qr' ),
			array( 'response' => 503 )
		);
	}
}
