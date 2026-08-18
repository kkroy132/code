<?php
/**
 * Handles the public-facing short URL redirect: /{prefix}/{slug}.
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Controllers;

use QuickLinkQRPro\Database\ClicksRepository;
use QuickLinkQRPro\Database\DestinationsRepository;
use QuickLinkQRPro\Database\LinksRepository;
use QuickLinkQRPro\Database\TargetingRulesRepository;
use QuickLinkQRPro\Helpers\BotDetector;
use QuickLinkQRPro\Helpers\DestinationRotator;
use QuickLinkQRPro\Helpers\DeviceDetector;
use QuickLinkQRPro\Helpers\GeoLocator;
use QuickLinkQRPro\Helpers\IpHash;
use QuickLinkQRPro\Helpers\Options;
use QuickLinkQRPro\Security\RateLimiter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RedirectController
 */
final class RedirectController {

	/**
	 * Query var used to carry the matched slug through WP's rewrite system.
	 */
	private const QUERY_VAR = 'qlqr_slug';

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
	 * Constructor.
	 */
	public function __construct() {
		$this->links  = new LinksRepository();
		$this->clicks = new ClicksRepository();
	}

	/**
	 * Register rewrite rules and request handling hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'add_rewrite_rule' ) );
		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		add_action( 'template_redirect', array( $this, 'maybe_handle_redirect' ) );

		// Handle the password-protection form submission before any output is sent.
		add_action( 'template_redirect', array( $this, 'maybe_handle_password_submission' ), 5 );
	}

	/**
	 * Add the /{prefix}/{slug} rewrite rule based on the configured URL prefix.
	 */
	public function add_rewrite_rule(): void {
		$prefix = trim( (string) Options::get( 'qlqr_url_prefix' ), '/' );
		$prefix = preg_replace( '/[^a-z0-9\-_]/i', '', $prefix ) ?: 'go';

		add_rewrite_rule(
			'^' . $prefix . '/([a-zA-Z0-9\-_]+)/?$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);
	}

	/**
	 * Register the custom query var used above.
	 *
	 * @param string[] $vars Existing query vars.
	 * @return string[]
	 */
	public function register_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Main entry point: if the current request matches a short link, process it and exit.
	 */
	public function maybe_handle_redirect(): void {
		$slug = get_query_var( self::QUERY_VAR );

		if ( empty( $slug ) ) {
			return;
		}

		$slug = sanitize_text_field( (string) $slug );

		/*
		 * Tell any page-cache plugin in front of WordPress to leave this request alone.
		 * DONOTCACHEPAGE is the de-facto standard constant, honoured by LiteSpeed Cache, WP Rocket,
		 * W3 Total Cache, WP Super Cache and others. A cached short link is served without PHP ever
		 * running, which means the click is never recorded — the visit simply never happens as far as this
		 * plugin is concerned. Defined before any of the work below, so it applies to every branch.
		 */
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// Deliberately unprefixed: this is a third-party constant with a fixed, agreed name that
			// caching plugins look for. A qlqr_-prefixed one would mean nothing to any of them.
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
		}

		$rate_limiter = new RateLimiter();
		if ( ! $rate_limiter->allow( 'redirect', (int) Options::get( 'qlqr_rate_limit_per_min' ) * 5 ) ) {
			status_header( 429 );
			wp_die( esc_html__( 'Too many requests. Please try again shortly.', 'plugnova-link-shortener-qr' ), '', array( 'response' => 429 ) );
		}

		$link = $this->links->find_by_slug( $slug );

		if ( ! $link ) {
			$this->render_not_found();
			return;
		}

		if ( ! $link->is_redirectable() ) {
			$this->render_unavailable( $link );
			return;
		}

		if ( $link->is_password_protected() && ! $this->has_valid_password_session( $link ) ) {
			$this->render_password_form( $link );
			return;
		}

		$resolved = $this->resolve_target_url( $link );

		if ( null === $resolved['url'] ) {
			$this->render_no_active_destination();
			return;
		}

		$this->record_click( $link, $resolved['destination_id'] );
		$this->do_redirect( $link, $resolved['url'] );
	}

	/**
	 * Work out which URL this visitor should be sent to: a matching Geo/Device targeting rule
	 * first (if the link has any), otherwise the link's single destination_url, or — for
	 * multi-destination links — whichever URL the configured rotation method picks among the
	 * currently active destinations (falling back to fallback_url, then to null if nothing
	 * usable is configured at all).
	 *
	 * @param \QuickLinkQRPro\Models\Link $link Matched link.
	 * @return array{url: string|null, destination_id: int|null}
	 */
	private function resolve_target_url( \QuickLinkQRPro\Models\Link $link ): array {
		// Geo/Device targeting is a Pro feature. A free-plan account (including one that
		// configured rules while on Pro and later downgraded) falls straight through to the
		// link's normal/multi-destination resolution below, exactly as if has_targeting_rules
		// were false — the stored rules are left untouched, just not evaluated, so nothing here
		// errors, 404s, or deletes anything. Checked before resolve_targeting_rule_url() rather
		// than inside it so a free-plan visitor never triggers the GeoIP lookup or device
		// detection it would otherwise perform.
		if ( $link->has_targeting_rules && qlqr_fs()->can_use_premium_code() ) {
			$targeted_url = $this->resolve_targeting_rule_url( $link );

			if ( null !== $targeted_url ) {
				return array(
					'url'            => $link->with_utm( $targeted_url ),
					'destination_id' => null,
				);
			}
		}

		// Multi-destination rotation is a Pro feature. A free-plan account (including one that
		// configured multiple destinations while on Pro and later downgraded) falls straight
		// through to the link's normal single-destination resolution below, exactly as if
		// destination_type were "single" — the stored destination rows are left untouched, just
		// not evaluated. get_destination_with_utm() reads the links table's own destination_url
		// column, which create()/update() always keep populated with the first active destination
		// even while destination_type is "multiple" (see LinkController::create()'s note), so this
		// is the same "first active destination" fallback used when nothing is active during
		// rotation below — nothing here errors, 404s, or deletes anything.
		if ( ! $link->is_multi_destination() || ! qlqr_fs()->can_use_premium_code() ) {
			return array(
				'url'            => $link->get_destination_with_utm(),
				'destination_id' => null,
			);
		}

		$destinations_repo = new DestinationsRepository();
		$active             = $destinations_repo->find_by_link( $link->id, true );

		$picked = DestinationRotator::pick( $active, $link->rotation_method, $link->rotation_cursor );

		if ( null === $picked['destination'] ) {
			// No active destination — fall back to the configured fallback URL, if any.
			if ( ! empty( $link->fallback_url ) ) {
				return array(
					'url'            => $link->with_utm( $link->fallback_url ),
					'destination_id' => null,
				);
			}

			return array(
				'url'            => null,
				'destination_id' => null,
			);
		}

		// Persist the round-robin cursor so the *next* visitor continues the sequence rather than
		// restarting it. A no-op write for random/weighted_random (next_cursor === current cursor).
		if ( $picked['next_cursor'] !== $link->rotation_cursor ) {
			$this->links->update( $link->id, array( 'rotation_cursor' => $picked['next_cursor'] ) );
		}

		return array(
			'url'            => $link->with_utm( $picked['destination']->destination_url ),
			'destination_id' => $picked['destination']->id,
		);
	}

	/**
	 * Check the link's Geo/Device targeting rules (in position order) against this visitor's
	 * resolved country and device type, returning the first match's destination URL.
	 * Country/device are only resolved lazily, and at most once each per request, since most
	 * links have no rules at all (guarded by Link::$has_targeting_rules before this is even
	 * called) and most rule sets only use one of the two rule types.
	 *
	 * @param \QuickLinkQRPro\Models\Link $link Matched link.
	 * @return string|null The matching rule's destination URL, or null if nothing matched.
	 */
	private function resolve_targeting_rule_url( \QuickLinkQRPro\Models\Link $link ): ?string {
		$rules = ( new TargetingRulesRepository() )->find_by_link( $link->id, true );

		if ( empty( $rules ) ) {
			return null;
		}

		$country = null;
		$device  = null;

		foreach ( $rules as $rule ) {
			if ( 'country' === $rule->rule_type ) {
				if ( null === $country ) {
					$ip      = IpHash::get_request_ip();
					$country = $ip ? ( GeoLocator::country_for_ip( $ip ) ?? '' ) : '';
				}

				if ( '' !== $country && $rule->match_value === $country ) {
					return $rule->destination_url;
				}
			} elseif ( 'device' === $rule->rule_type ) {
				if ( null === $device ) {
					$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
					$device     = DeviceDetector::device_type( $user_agent );
				}

				if ( $rule->match_value === $device ) {
					return $rule->destination_url;
				}
			}
		}

		return null;
	}

	/**
	 * Handle POST submission of the password-protection form.
	 */
	public function maybe_handle_password_submission(): void {
		$slug = get_query_var( self::QUERY_VAR );
		if ( empty( $slug ) || 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			return;
		}

		if ( ! isset( $_POST['qlqr_password_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['qlqr_password_nonce'] ) ), 'qlqr_password_form' ) ) {
			return;
		}

		$link = $this->links->find_by_slug( sanitize_text_field( (string) $slug ) );
		if ( ! $link || ! $link->is_password_protected() ) {
			return;
		}

		// Deliberately unslashed but NOT sanitized: this is a password being compared against a
		// hash, so stripping or altering characters would corrupt a legitimate secret. It is never
		// echoed, stored or concatenated into SQL.
		$submitted = isset( $_POST['qlqr_password'] ) ? (string) wp_unslash( $_POST['qlqr_password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( wp_check_password( $submitted, (string) $link->password ) ) {
			$this->mark_password_valid( $link );
		}
	}

	/**
	 * Record analytics for the click and increment the link's cached counter.
	 *
	 * @param \QuickLinkQRPro\Models\Link $link           Matched link.
	 * @param int|null                    $destination_id For multi-destination links, the specific
	 *                                                     destination that was picked this visit (see
	 *                                                     resolve_target_url()); null for single-destination links.
	 */
	private function record_click( \QuickLinkQRPro\Models\Link $link, ?int $destination_id = null ): void {
		if ( ! Options::get( 'qlqr_tracking_enabled' ) ) {
			return;
		}

		/*
		 * Optionally skip the people who manage the links. Checking a link works means clicking it,
		 * and on a site whose real traffic is still small those checks are a visible share of the
		 * numbers — they show up as clicks from your own country, your own browser, referred by your
		 * own site, all sharing one IP so they also collapse the unique-click count.
		 *
		 * Off by default. Analytics that quietly decline to count some real visits are worse than
		 * analytics that count a few of your own: the first kind makes every number suspect, and the
		 * owner has no way to tell it is happening.
		 *
		 * Only the recording is skipped — the redirect itself proceeds exactly as it would for any
		 * visitor, so this can never make a link behave differently for the person testing it.
		 */
		if ( Options::get( 'qlqr_exclude_admin_clicks' ) && current_user_can( \QuickLinkQRPro\Security\Capabilities::MANAGE_LINKS ) ) {
			return;
		}

		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$referrer   = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		$ip         = IpHash::get_request_ip();
		$ip_hash    = IpHash::hash( $ip );
		$is_bot     = BotDetector::is_bot( $user_agent );

		// Lifetime-unique: has this ip_hash ever generated a non-bot click on this link before?
		// Computed before inserting the current row so the current click can correctly be the
		// "first" unique one.
		$is_unique = ! $is_bot && ! $this->clicks->has_prior_click( $link->id, $ip_hash );

		$this->clicks->insert(
			array(
				'link_id'          => $link->id,
				'country'          => $ip ? GeoLocator::country_for_ip( $ip ) : null,
				'device'           => DeviceDetector::device_type( $user_agent ),
				'browser'          => DeviceDetector::browser( $user_agent ),
				'operating_system' => DeviceDetector::operating_system( $user_agent ),
				'referrer'         => $referrer ? wp_parse_url( $referrer, PHP_URL_HOST ) : '',
				'utm_source'       => isset( $_GET['utm_source'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_source'] ) ) : null, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public short-link visit, not a form submission; these are read-only analytics dimensions
				'utm_medium'       => isset( $_GET['utm_medium'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_medium'] ) ) : null, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public short-link visit, not a form submission; these are read-only analytics dimensions
				'utm_campaign'     => isset( $_GET['utm_campaign'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_campaign'] ) ) : null, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public short-link visit, not a form submission; these are read-only analytics dimensions
				// The QR image itself encodes "?qlqr_src=qr" (see Models\Link::get_short_url_for_qr()),
				// so its presence here means this visit came from a scanned QR code rather than a
				// directly clicked/typed short link.
				'source'           => isset( $_GET['qlqr_src'] ) && 'qr' === $_GET['qlqr_src'] ? 'qr' : 'link', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public short-link visit, not a form submission; these are read-only analytics dimensions
				'language'         => isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ), 0, 20 ) : null,
				'ip_hash'          => $ip_hash,
				'user_agent'       => substr( $user_agent, 0, 255 ),
				'is_bot'           => $is_bot ? 1 : 0,
				'is_unique'        => $is_unique ? 1 : 0,
				'clicked_at'       => current_time( 'mysql' ),
			)
		);

		// Bot/crawler visits (e.g. WhatsApp or Slack generating a link preview) are still logged
		// above for transparency, but intentionally excluded from the link's headline click count
		// so it reflects real visitors.
		if ( ! $is_bot ) {
			$this->links->increment_click_count( $link->id );

			if ( null !== $destination_id ) {
				( new DestinationsRepository() )->increment_click( $destination_id );
			}
		}
	}

	/**
	 * Perform the actual HTTP redirect to the resolved destination URL.
	 *
	 * @param \QuickLinkQRPro\Models\Link $link        Matched link (used for its configured redirect type).
	 * @param string                      $destination Already-resolved target URL (single destination_url,
	 *                                                  or the rotation-picked URL for multi-destination links).
	 */
	private function do_redirect( \QuickLinkQRPro\Models\Link $link, string $destination ): void {
		$status = in_array( $link->redirect_type, array( 301, 302, 307 ), true ) ? $link->redirect_type : 302;

		/*
		 * Send no-cache directives before redirecting. Without them a redirect is cacheable — a 301
		 * indefinitely so, by the visitor's browser and by any proxy, CDN or page-cache plugin in
		 * between. A cached redirect never reaches this site again, so record_click() above never
		 * runs for it: the link keeps working while its click count silently stops moving after the
		 * first visit from each browser. Every one of the failure paths below already called this;
		 * the success path — the only one whose traffic is actually being counted — did not.
		 *
		 * This costs the redirect nothing perceptible (it is a header, not a round trip) and is what
		 * makes per-click analytics trustworthy.
		 */
		nocache_headers();

		// Intentionally wp_redirect(), not wp_safe_redirect(): this plugin's entire purpose is
		// redirecting to arbitrary external destinations (affiliate links, campaign URLs, etc.),
		// which wp_safe_redirect() blocks by design (it only allows same-host redirects and
		// silently falls back to admin_url() otherwise). The destination was already validated as
		// a well-formed URL at creation/update time via wp_http_validate_url(), and is re-escaped
		// here with esc_url_raw() immediately before use.
		wp_redirect( esc_url_raw( $destination ), $status ); // phpcs:ignore WordPress.Security.SafeRedirect
		exit;
	}

	/**
	 * Render a 404-style "link not found" page.
	 */
	private function render_not_found(): void {
		status_header( 404 );
		nocache_headers();
		wp_die(
			esc_html__( 'This short link does not exist.', 'plugnova-link-shortener-qr' ),
			esc_html__( 'Link Not Found', 'plugnova-link-shortener-qr' ),
			array( 'response' => 404 )
		);
	}

	/**
	 * Render a page explaining why a link is currently unavailable (disabled/expired/limit reached).
	 *
	 * @param \QuickLinkQRPro\Models\Link $link Matched link.
	 */
	private function render_unavailable( \QuickLinkQRPro\Models\Link $link ): void {
		status_header( 410 );
		nocache_headers();

		$message = __( 'This link is no longer available.', 'plugnova-link-shortener-qr' );
		if ( $link->is_expired() ) {
			$message = __( 'This link has expired.', 'plugnova-link-shortener-qr' );
		} elseif ( $link->has_reached_click_limit() ) {
			$message = __( 'This link has reached its maximum number of clicks.', 'plugnova-link-shortener-qr' );
		} elseif ( 'disabled' === $link->status ) {
			$message = __( 'This link has been disabled by its owner.', 'plugnova-link-shortener-qr' );
		}

		wp_die( esc_html( $message ), esc_html__( 'Link Unavailable', 'plugnova-link-shortener-qr' ), array( 'response' => 410 ) );
	}

	/**
	 * Render an error page for multi-destination links where no destination is currently active
	 * and no fallback URL is configured. Distinct from render_unavailable() because the *link*
	 * itself is fine (active, not expired) — it's specifically the destination list that's empty.
	 */
	private function render_no_active_destination(): void {
		status_header( 503 );
		nocache_headers();

		wp_die(
			esc_html__( 'This link has no active destination configured right now. Please try again later.', 'plugnova-link-shortener-qr' ),
			esc_html__( 'Link Unavailable', 'plugnova-link-shortener-qr' ),
			array( 'response' => 503 )
		);
	}

	/**
	 * Render a minimal password entry form for protected links.
	 *
	 * @param \QuickLinkQRPro\Models\Link $link Matched link.
	 */
	private function render_password_form( \QuickLinkQRPro\Models\Link $link ): void {
		nocache_headers();

		$template = QLQR_PLUGIN_DIR . 'templates/password-form.php';
		if ( is_readable( $template ) ) {
			include $template;
		}
		exit;
	}

	/**
	 * Whether the current visitor has already unlocked a password-protected link. Backed by a
	 * signed cookie bound to the stored password hash, so changing the link's password invalidates
	 * old unlock cookies.
	 *
	 * @param \QuickLinkQRPro\Models\Link $link Link.
	 * @return bool
	 */
	private function has_valid_password_session( \QuickLinkQRPro\Models\Link $link ): bool {
		return \QuickLinkQRPro\Helpers\GateCookie::is_passed( 'link_pw_' . $link->id, (string) $link->password );
	}

	/**
	 * Record that the visitor has entered the correct password for a link (sends a signed cookie).
	 *
	 * @param \QuickLinkQRPro\Models\Link $link Link.
	 */
	private function mark_password_valid( \QuickLinkQRPro\Models\Link $link ): void {
		\QuickLinkQRPro\Helpers\GateCookie::mark_passed( 'link_pw_' . $link->id, (string) $link->password );
	}
}
