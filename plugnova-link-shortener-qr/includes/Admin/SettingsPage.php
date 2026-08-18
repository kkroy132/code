<?php
/**
 * Renders and handles the Settings admin page using the WordPress Settings API.
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SettingsPage
 */
final class SettingsPage {

	/**
	 * Option group name used by register_setting()/settings_fields().
	 */
	private const OPTION_GROUP = 'qlqr_settings_group';

	/**
	 * Constructor: hooks registration happens on admin_init so the Settings API works correctly
	 * regardless of whether this page has been rendered yet in the current request.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'update_option_qlqr_url_prefix', array( $this, 'flush_rewrites_on_prefix_change' ), 10, 0 );
		add_action( 'update_option_qlqr_bio_url_prefix', array( $this, 'flush_rewrites_on_prefix_change' ), 10, 0 );
		add_action( 'update_option_qlqr_broken_link_check_enabled', array( $this, 'reschedule_broken_link_check' ), 10, 2 );
	}

	/**
	 * Schedule or unschedule the daily broken-link-check cron event to match the setting — it must
	 * never run unless the admin has explicitly turned it on, since each run is an outbound request
	 * to every link's own third-party destination server.
	 *
	 * @param mixed $old_value Previous option value (unused; only the new value decides the action).
	 * @param mixed $value     New option value.
	 */
	public function reschedule_broken_link_check( mixed $old_value, mixed $value ): void {
		$hook = \QuickLinkQRPro\Helpers\BrokenLinkChecker::CRON_HOOK;

		if ( $value ) {
			if ( ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', $hook );
			}
		} else {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Re-register both rewrite rules (short links + Smart Bio Link pages) and flush, so links
	 * keep working immediately after either URL prefix is changed (no manual Permalinks resave needed).
	 */
	public function flush_rewrites_on_prefix_change(): void {
		( new \QuickLinkQRPro\Controllers\RedirectController() )->add_rewrite_rule();
		( new \QuickLinkQRPro\Controllers\BioController() )->add_rewrite_rule();
		flush_rewrite_rules();
	}

	/**
	 * Register all settings, sections, and fields.
	 */
	public function register_settings(): void {
		$options = \QuickLinkQRPro\Helpers\Options::class;

		$settings = array(
			'qlqr_url_prefix'                => array( $this, 'sanitize_url_prefix' ),
			'qlqr_bio_url_prefix'             => array( $this, 'sanitize_bio_url_prefix' ),
			'qlqr_default_redirect'           => array( $this, 'sanitize_redirect_type' ),
			'qlqr_slug_length'                => array( $this, 'sanitize_slug_length' ),
			'qlqr_qr_default_size'            => array( $this, 'sanitize_qr_size' ),
			'qlqr_qr_default_fg'              => 'sanitize_hex_color',
			'qlqr_qr_default_bg'              => 'sanitize_hex_color',
			'qlqr_tracking_enabled'           => array( $this, 'sanitize_checkbox' ),
			'qlqr_rest_api_enabled'           => array( $this, 'sanitize_checkbox' ),
			'qlqr_rate_limit_per_min'         => array( $this, 'sanitize_rate_limit' ),
			'qlqr_delete_on_uninstall'        => array( $this, 'sanitize_checkbox' ),
			'qlqr_keyword_autolink_enabled'   => array( $this, 'sanitize_checkbox' ),
			'qlqr_trust_proxy_headers'        => array( $this, 'sanitize_checkbox' ),
			'qlqr_click_retention_months'     => array( $this, 'sanitize_click_retention' ),
			'qlqr_exclude_admin_clicks'       => array( $this, 'sanitize_checkbox' ),
			'qlqr_broken_link_check_enabled'  => array( $this, 'sanitize_checkbox' ),
		);

		$types = array(
			'qlqr_url_prefix'                => 'string',
			'qlqr_bio_url_prefix'             => 'string',
			'qlqr_default_redirect'           => 'integer',
			'qlqr_slug_length'                => 'integer',
			'qlqr_qr_default_size'            => 'integer',
			'qlqr_qr_default_fg'              => 'string',
			'qlqr_qr_default_bg'              => 'string',
			'qlqr_tracking_enabled'           => 'integer',
			'qlqr_rest_api_enabled'           => 'integer',
			'qlqr_rate_limit_per_min'         => 'integer',
			'qlqr_delete_on_uninstall'        => 'integer',
			'qlqr_keyword_autolink_enabled'   => 'integer',
			'qlqr_trust_proxy_headers'        => 'integer',
			'qlqr_click_retention_months'     => 'integer',
			'qlqr_exclude_admin_clicks'       => 'integer',
			'qlqr_broken_link_check_enabled'  => 'integer',
		);

		foreach ( $settings as $option => $sanitize_callback ) {
			register_setting(
				self::OPTION_GROUP,
				$option,
				array(
					'sanitize_callback' => $sanitize_callback,
					'type'              => $types[ $option ],
					'default'           => $options::default_value( $option ),
				)
			);
		}

		register_setting(
			self::OPTION_GROUP,
			'qlqr_allowed_roles',
			array(
				'sanitize_callback' => array( $this, 'sanitize_allowed_roles' ),
				'type'              => 'array',
				'default'           => $options::default_value( 'qlqr_allowed_roles' ),
			)
		);
	}

	/**
	 * Shared sanitizer for every plain on/off toggle registered above. register_setting()'s
	 * sanitize_callback only ever runs for a field that was actually present in the submission
	 * (unchecked checkboxes simply aren't posted), so whatever reaches here is the "on" value —
	 * this just guarantees the stored option is strictly the int 0 or 1 the rest of the codebase
	 * expects, never a stray truthy string.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return int
	 */
	public function sanitize_checkbox( mixed $value ): int {
		return $value ? 1 : 0;
	}

	/**
	 * Allowlist callback for the default redirect type: only 301, 302 or 307 are meaningful HTTP
	 * redirect statuses for this setting, so anything else (including absint()'s "0 for anything
	 * unparseable" behaviour) falls back to the documented default rather than being stored as-is.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return int
	 */
	public function sanitize_redirect_type( mixed $value ): int {
		$value   = absint( $value );
		$allowed = array( 301, 302, 307 );

		return in_array( $value, $allowed, true )
			? $value
			: (int) \QuickLinkQRPro\Helpers\Options::default_value( 'qlqr_default_redirect' );
	}

	/**
	 * Clamp the random slug length to the range Helpers\SlugGenerator itself supports — it
	 * silently re-clamps out-of-range lengths already, but letting 0 or a huge value sit in the
	 * option is still wrong: it misrepresents what will actually happen on the settings screen.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return int
	 */
	public function sanitize_slug_length( mixed $value ): int {
		return max(
			\QuickLinkQRPro\Helpers\SlugGenerator::MIN_LENGTH,
			min( \QuickLinkQRPro\Helpers\SlugGenerator::MAX_LENGTH, absint( $value ) )
		);
	}

	/**
	 * Sanitize the short-link URL prefix to a valid single path segment, rejecting a value that
	 * would collide with the Bio Link prefix (both are matched at the start of the request path,
	 * so identical prefixes would make the two features ambiguous).
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string
	 */
	public function sanitize_url_prefix( mixed $value ): string {
		return $this->sanitize_prefix( $value, 'qlqr_url_prefix', 'qlqr_bio_url_prefix' );
	}

	/**
	 * Sanitize the Bio Link URL prefix to a valid single path segment, rejecting a value that
	 * would collide with the short-link prefix. See sanitize_url_prefix() above.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string
	 */
	public function sanitize_bio_url_prefix( mixed $value ): string {
		return $this->sanitize_prefix( $value, 'qlqr_bio_url_prefix', 'qlqr_url_prefix' );
	}

	/**
	 * Shared implementation behind sanitize_url_prefix() and sanitize_bio_url_prefix().
	 *
	 * @param mixed  $value         Raw submitted value for $option.
	 * @param string $option        Option name being sanitized.
	 * @param string $other_option  The other prefix option, which $value must not collide with.
	 * @return string
	 */
	private function sanitize_prefix( mixed $value, string $option, string $other_option ): string {
		$default = (string) \QuickLinkQRPro\Helpers\Options::default_value( $option );
		$new     = $this->sanitize_path_segment( $value, $default );

		$other_default = (string) \QuickLinkQRPro\Helpers\Options::default_value( $other_option );
		$other         = (string) get_option( $other_option, $other_default );

		if ( $new === $other ) {
			add_settings_error(
				$option,
				$option . '_collision',
				__( 'The link prefix and the bio-page prefix cannot be the same. Keeping the previous value.', 'plugnova-link-shortener-qr' )
			);

			return (string) get_option( $option, $default );
		}

		return $new;
	}

	/**
	 * Sanitize arbitrary input down to a single valid URL path segment (lowercase, alphanumeric
	 * and hyphens only, no slashes) — sanitize_text_field() alone left characters through that
	 * are invalid in a rewrite-rule path segment. Falls back to $default when nothing valid
	 * remains, so a prefix can never end up empty (which would swallow every top-level request).
	 *
	 * @param mixed  $value   Raw submitted value.
	 * @param string $default Fallback when sanitizing leaves nothing.
	 * @return string
	 */
	private function sanitize_path_segment( mixed $value, string $default ): string {
		$segment = sanitize_title( (string) $value );

		return '' !== $segment ? $segment : $default;
	}

	/**
	 * Clamp the per-visitor rate limit to the same 5–1000 range the settings field advertises.
	 *
	 * absint() alone allowed a 0 through (the field's min="5" is only enforced by the browser), and
	 * a limit of 0 made Security\RateLimiter reject every request — which on the front end means
	 * every short link and bio page answering 429.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return int
	 */
	public function sanitize_rate_limit( mixed $value ): int {
		return max( 5, min( 1000, absint( $value ) ) );
	}

	/**
	 * Clamp the default QR size to the same 64–2000px range the settings field advertises.
	 *
	 * Same class of gap as sanitize_rate_limit() above: the field's min/max attributes only bind
	 * the browser, so absint() alone let any value through. This one ends up as the pixel
	 * dimensions handed to GD, where the allocation grows with the square of the size — a mistyped
	 * 20000 asked for roughly 1.5 GB and fataled every subsequent link save. Helpers\QrCodeGenerator
	 * clamps again at render time for callers that bypass this setting (the REST API's "qr_size").
	 *
	 * @param mixed $value Raw submitted value.
	 * @return int
	 */
	public function sanitize_qr_size( mixed $value ): int {
		return max(
			\QuickLinkQRPro\Helpers\QrCodeGenerator::MIN_SIZE,
			min( \QuickLinkQRPro\Helpers\QrCodeGenerator::MAX_SIZE, absint( $value ) )
		);
	}

	/**
	 * Restrict the analytics retention window to the choices the settings field offers.
	 *
	 * Anything unrecognized falls back to "keep everything" rather than to a shorter window: if a
	 * value ever arrives malformed, silently deleting the user's history would be the worse of the
	 * two mistakes by a wide margin.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return int Months to keep, or 0 for forever.
	 */
	public function sanitize_click_retention( mixed $value ): int {
		$months = absint( $value );

		return in_array( $months, array( 0, 3, 6, 12, 24, 36 ), true ) ? $months : 0;
	}

	/**
	 * Sanitize the allowed-roles multi-select into a clean array of valid role slugs.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string[]
	 */
	public function sanitize_allowed_roles( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array( 'administrator', 'editor' );
		}

		$valid_roles = array_keys( wp_roles()->roles );

		return array_values( array_intersect( array_map( 'sanitize_key', $value ), $valid_roles ) );
	}

	/**
	 * Render the page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'plugnova-link-shortener-qr' ) );
		}

		$roles = wp_roles()->roles;

		include QLQR_PLUGIN_DIR . 'templates/admin/settings.php';
	}
}
