<?php
/**
 * Application-level orchestration for creating/updating Smart Bio Link pages, mirroring
 * LinkController's shape for the links table.
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Controllers;

use QuickLinkQRPro\Database\BioLinksRepository;
use QuickLinkQRPro\Database\BioPagesRepository;
use QuickLinkQRPro\Database\BioSocialsRepository;
use QuickLinkQRPro\Helpers\QrCodeGenerator;
use QuickLinkQRPro\Helpers\SlugGenerator;
use QuickLinkQRPro\Models\BioPage;
use QuickLinkQRPro\Models\BioSocial;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BioPageController
 */
final class BioPageController {

	/**
	 * Total active (non-trashed) bio page count the free plan is limited to. Pro accounts
	 * (qlqr_fs()->can_use_premium_code() === true) have no limit. Public so Admin\BioLinksPage
	 * can reference the same number for its "at the limit" notice instead of duplicating it.
	 */
	public const FREE_BIO_PAGE_LIMIT = 1;

	/**
	 * Bio pages repository.
	 *
	 * @var BioPagesRepository
	 */
	private BioPagesRepository $repository;

	/**
	 * Slug generator, configured to check uniqueness against bio pages (a separate slug
	 * namespace from short links — both live under their own configurable URL prefix).
	 *
	 * @var SlugGenerator
	 */
	private SlugGenerator $slug_generator;

	/**
	 * QR code generator.
	 *
	 * @var QrCodeGenerator
	 */
	private QrCodeGenerator $qr_generator;

	/**
	 * Bio links (buttons) repository.
	 *
	 * @var BioLinksRepository
	 */
	private BioLinksRepository $links_repository;

	/**
	 * Social icon row repository.
	 *
	 * @var BioSocialsRepository
	 */
	private BioSocialsRepository $socials_repository;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->repository       = new BioPagesRepository();
		$this->slug_generator    = new SlugGenerator( $this->repository );
		$this->qr_generator      = new QrCodeGenerator();
		$this->links_repository  = new BioLinksRepository();
		$this->socials_repository = new BioSocialsRepository();
	}

	/**
	 * Blocks a new bio page from being created once a free-plan account is already at
	 * FREE_BIO_PAGE_LIMIT active bio pages. Pro accounts always pass. Checked by create() —
	 * the only place that adds a row to the bio pages table — so every entry point (Add New,
	 * Import Template's eventual save, and the REST API) is covered from one place.
	 *
	 * @return array{success:false, error:string, upgrade_url:string}|null Null when creation may proceed.
	 */
	private function free_plan_limit_error(): ?array {
		if ( qlqr_fs()->can_use_premium_code() ) {
			return null;
		}

		if ( $this->repository->active_count() < self::FREE_BIO_PAGE_LIMIT ) {
			return null;
		}

		return array(
			'success'     => false,
			'error'       => sprintf(
				/* translators: %d: the free plan's active Bio Page limit */
				_n(
					'The free plan includes %d Bio Page. Upgrade to Pro for unlimited Bio Pages.',
					'The free plan includes %d Bio Pages. Upgrade to Pro for unlimited Bio Pages.',
					self::FREE_BIO_PAGE_LIMIT,
					'plugnova-link-shortener-qr'
				),
				self::FREE_BIO_PAGE_LIMIT
			),
			'upgrade_url' => qlqr_fs()->get_upgrade_url(),
		);
	}

	/**
	 * Create a new bio page from validated input, generating a slug and QR code as needed.
	 *
	 * @param array<string, mixed> $input {
	 *     @type string $title        Required display title.
	 *     @type string $custom_slug  Optional custom slug; a random one is generated if empty.
	 *     @type string $bio_text     Optional short bio shown under the title.
	 *     @type string $avatar_url   Optional avatar image URL.
	 *     @type string $theme_color  Hex color.
	 *     @type string $theme_preset light|dark|gradient|minimal.
	 *     @type string $button_style rounded|square|pill.
	 *     @type bool   $email_capture_enabled Whether visitors must submit an email before the links unlock.
	 *     @type string $email_capture_heading Custom heading for the email-capture gate, or empty for a default.
	 *     @type string $status       active|disabled.
	 *     @type array<int, array{label:string, url:string, icon?:string, status?:string, starts_at?:string, ends_at?:string}> $links
	 *           The page's buttons, in display order.
	 *     @type array<int, array{platform:string, url:string, status?:string}> $social_links
	 *           The page's social-icon row, in display order.
	 * }
	 * @return array{success:bool, bio_page?:BioPage, error?:string, upgrade_url?:string, qr_warning?:string|null}
	 */
	public function create( array $input ): array {
		$limit_error = $this->free_plan_limit_error();
		if ( null !== $limit_error ) {
			return $limit_error;
		}

		$title = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
		if ( '' === $title ) {
			return array(
				'success' => false,
				'error'   => __( 'Please provide a title for the bio page.', 'plugnova-link-shortener-qr' ),
			);
		}

		$custom_slug = trim( (string) ( $input['custom_slug'] ?? '' ) );

		if ( '' !== $custom_slug ) {
			$slug       = $this->slug_generator->sanitize_custom_slug( $custom_slug );
			$validation = $this->slug_generator->validate( $slug );

			if ( true !== $validation ) {
				return array(
					'success' => false,
					'error'   => $validation,
				);
			}

			if ( $this->repository->slug_exists( $slug ) ) {
				return array(
					'success' => false,
					'error'   => __( 'This custom slug is already taken.', 'plugnova-link-shortener-qr' ),
				);
			}
		} else {
			$slug = $this->slug_generator->generate_unique( (int) get_option( 'qlqr_slug_length', 6 ) );
		}

		$links   = $this->sanitize_links( is_array( $input['links'] ?? null ) ? $input['links'] : array() );
		$socials = $this->sanitize_socials( is_array( $input['social_links'] ?? null ) ? $input['social_links'] : array() );

		$password_hash = null;
		if ( ! empty( $input['password'] ) ) {
			$password_hash = wp_hash_password( (string) $input['password'] );
		}

		// Read the raw value first, then allow-list it. Testing `$input['status'] ?? 'active'` but
		// then returning `$input['status']` would warn and yield null whenever the key is absent —
		// the normal case for REST API callers, which don't have to send every field.
		$raw_status = (string) ( $input['status'] ?? 'active' );
		$status     = in_array( $raw_status, array( 'active', 'disabled' ), true ) ? $raw_status : 'active';

		$row = array(
			'slug'                     => $slug,
			'title'                    => $title,
			'bio_text'                 => $this->sanitize_optional_bio_text( $input['bio_text'] ?? null ),
			'avatar_url'               => $this->sanitize_optional_url( $input['avatar_url'] ?? null ),
			'theme_color'              => $this->sanitize_hex_color( (string) ( $input['theme_color'] ?? '#2271b1' ) ),
			'theme_preset'             => $this->sanitize_theme_preset( (string) ( $input['theme_preset'] ?? 'light' ) ),
			'button_style'             => $this->sanitize_button_style( (string) ( $input['button_style'] ?? 'rounded' ) ),
			'email_capture_enabled'    => ! empty( $input['email_capture_enabled'] ) ? 1 : 0,
			'email_capture_heading'    => $this->sanitize_optional_heading( $input['email_capture_heading'] ?? null ),
			'status'                   => $status,
			'password'                 => $password_hash,
			'countdown_enabled'        => ! empty( $input['countdown_enabled'] ) ? 1 : 0,
			'countdown_label'          => $this->sanitize_optional_heading( $input['countdown_label'] ?? null ),
			'countdown_target_at'      => $this->sanitize_optional_datetime( $input['countdown_target_at'] ?? null ),
			'announcement_enabled'     => ! empty( $input['announcement_enabled'] ) ? 1 : 0,
			'announcement_text'        => $this->sanitize_optional_announcement_text( $input['announcement_text'] ?? null ),
			'announcement_bg_color'    => $this->sanitize_hex_color_with_default( (string) ( $input['announcement_bg_color'] ?? '#2271b1' ), '#2271b1' ),
			'announcement_text_color'  => $this->sanitize_hex_color_with_default( (string) ( $input['announcement_text_color'] ?? '#ffffff' ), '#ffffff' ),
			'announcement_url'         => $this->sanitize_optional_url( $input['announcement_url'] ?? null ),
			'starts_at'                => $this->sanitize_optional_datetime( $input['starts_at'] ?? null ),
			'ends_at'                  => $this->sanitize_optional_datetime( $input['ends_at'] ?? null ),
		);

		$bio_page = $this->repository->insert( $row );

		if ( ! $bio_page ) {
			return array(
				'success' => false,
				'error'   => __( 'Could not save the bio page. Please try again.', 'plugnova-link-shortener-qr' ),
			);
		}

		if ( ! empty( $links ) ) {
			$this->links_repository->replace_for_page( $bio_page->id, $links );
		}

		if ( ! empty( $socials ) ) {
			$this->socials_repository->replace_for_page( $bio_page->id, $socials );
		}

		$qr_warning = $this->generate_and_attach_qr( $bio_page );

		return array(
			'success'    => true,
			'bio_page'   => $this->repository->find( $bio_page->id ) ?? $bio_page,
			'qr_warning' => $qr_warning,
		);
	}

	/**
	 * Update an existing bio page's details (everything except the slug, which is kept
	 * immutable after creation so already-shared/printed links and QR codes never break).
	 *
	 * @param int                  $id    Bio page ID.
	 * @param array<string, mixed> $input Same shape as create(), minus custom_slug (ignored if present).
	 * @return array{success:bool, bio_page?:BioPage, error?:string, qr_warning?:string|null}
	 */
	public function update( int $id, array $input ): array {
		$bio_page = $this->repository->find( $id );
		if ( ! $bio_page ) {
			return array(
				'success' => false,
				'error'   => __( 'Bio page not found.', 'plugnova-link-shortener-qr' ),
			);
		}

		$title = array_key_exists( 'title', $input ) ? sanitize_text_field( (string) $input['title'] ) : $bio_page->title;
		if ( '' === $title ) {
			return array(
				'success' => false,
				'error'   => __( 'Please provide a title for the bio page.', 'plugnova-link-shortener-qr' ),
			);
		}

		$links = array_key_exists( 'links', $input )
			? $this->sanitize_links( is_array( $input['links'] ) ? $input['links'] : array() )
			: array();

		$socials = array_key_exists( 'social_links', $input )
			? $this->sanitize_socials( is_array( $input['social_links'] ) ? $input['social_links'] : array() )
			: array();

		// Only replace the stored password hash if a new one was actually typed; an empty field
		// means "keep the current password" — mirrors LinkController::update()'s exact same
		// password/clear_password handling for short links.
		$password_update = array();
		if ( array_key_exists( 'password', $input ) && '' !== trim( (string) $input['password'] ) ) {
			$password_update['password'] = wp_hash_password( (string) $input['password'] );
		} elseif ( ! empty( $input['clear_password'] ) ) {
			$password_update['password'] = null;
		}

		// See create()'s note above: read first, then allow-list, so a payload that omits the field
		// keeps the page's existing status instead of warning and overwriting it with null.
		$raw_status = (string) ( $input['status'] ?? $bio_page->status );
		$status     = in_array( $raw_status, array( 'active', 'disabled' ), true ) ? $raw_status : $bio_page->status;

		$row = array_merge(
			array(
				'title'                    => $title,
				'bio_text'                 => array_key_exists( 'bio_text', $input ) ? $this->sanitize_optional_bio_text( $input['bio_text'] ) : $bio_page->bio_text,
				'avatar_url'               => array_key_exists( 'avatar_url', $input ) ? $this->sanitize_optional_url( $input['avatar_url'] ) : $bio_page->avatar_url,
				'theme_color'              => $this->sanitize_hex_color( (string) ( $input['theme_color'] ?? $bio_page->theme_color ) ),
				'theme_preset'             => $this->sanitize_theme_preset( (string) ( $input['theme_preset'] ?? $bio_page->theme_preset ) ),
				'button_style'             => $this->sanitize_button_style( (string) ( $input['button_style'] ?? $bio_page->button_style ) ),
				'email_capture_enabled'    => array_key_exists( 'email_capture_enabled', $input ) ? ( ! empty( $input['email_capture_enabled'] ) ? 1 : 0 ) : (int) $bio_page->email_capture_enabled,
				'email_capture_heading'    => array_key_exists( 'email_capture_heading', $input ) ? $this->sanitize_optional_heading( $input['email_capture_heading'] ) : $bio_page->email_capture_heading,
				'status'                   => $status,
				'countdown_enabled'        => array_key_exists( 'countdown_enabled', $input ) ? ( ! empty( $input['countdown_enabled'] ) ? 1 : 0 ) : (int) $bio_page->countdown_enabled,
				'countdown_label'          => array_key_exists( 'countdown_label', $input ) ? $this->sanitize_optional_heading( $input['countdown_label'] ) : $bio_page->countdown_label,
				'countdown_target_at'      => array_key_exists( 'countdown_target_at', $input ) ? $this->sanitize_optional_datetime( $input['countdown_target_at'] ) : $bio_page->countdown_target_at,
				'announcement_enabled'     => array_key_exists( 'announcement_enabled', $input ) ? ( ! empty( $input['announcement_enabled'] ) ? 1 : 0 ) : (int) $bio_page->announcement_enabled,
				'announcement_text'        => array_key_exists( 'announcement_text', $input ) ? $this->sanitize_optional_announcement_text( $input['announcement_text'] ) : $bio_page->announcement_text,
				'announcement_bg_color'    => $this->sanitize_hex_color_with_default( (string) ( $input['announcement_bg_color'] ?? $bio_page->announcement_bg_color ), '#2271b1' ),
				'announcement_text_color'  => $this->sanitize_hex_color_with_default( (string) ( $input['announcement_text_color'] ?? $bio_page->announcement_text_color ), '#ffffff' ),
				'announcement_url'         => array_key_exists( 'announcement_url', $input ) ? $this->sanitize_optional_url( $input['announcement_url'] ) : $bio_page->announcement_url,
				'starts_at'                => array_key_exists( 'starts_at', $input ) ? $this->sanitize_optional_datetime( $input['starts_at'] ) : $bio_page->starts_at,
				'ends_at'                  => array_key_exists( 'ends_at', $input ) ? $this->sanitize_optional_datetime( $input['ends_at'] ) : $bio_page->ends_at,
			),
			$password_update
		);

		// Unlike create() above (which already checked insert()'s result), this never checked
		// whether the write actually succeeded — it just assumed it had and reported success
		// regardless. That let a real failure (e.g. $wpdb->update() rejecting a value MySQL
		// wouldn't accept) go completely unreported: the modal would close as if it worked, and
		// reopening Edit would show the old, unchanged data with no error ever shown anywhere.
		if ( ! $this->repository->update( $id, $row ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Could not save changes. Please try again.', 'plugnova-link-shortener-qr' ),
			);
		}

		if ( array_key_exists( 'links', $input ) ) {
			$this->links_repository->replace_for_page( $id, $links );
		}

		if ( array_key_exists( 'social_links', $input ) ) {
			$this->socials_repository->replace_for_page( $id, $socials );
		}

		$refreshed = $this->repository->find( $id ) ?? $bio_page;

		/*
		 * The QR image encodes get_public_url_for_qr(), which is built from the page's slug — and
		 * the slug can never change after creation (see the "locked" note next to the Slug field in
		 * the edit form). theme_color is the only saved field that actually changes what the QR
		 * looks like (it's the foreground color; size/background come from a global option this
		 * method doesn't touch). Regenerating on every single save — editing bio text, adding a
		 * link, toggling a countdown — was pure wasted GD work and a real, measurable delay on each
		 * save with nothing to show for it, so it only runs when theme_color actually changed, or
		 * there is no QR image yet (e.g. a prior generation attempt failed).
		 */
		$qr_warning = null;
		if ( null === $bio_page->qr_image || $row['theme_color'] !== $bio_page->theme_color ) {
			$qr_warning = $this->generate_and_attach_qr( $refreshed );
		}

		return array(
			'success'    => true,
			'bio_page'   => $this->repository->find( $id ),
			'qr_warning' => $qr_warning,
		);
	}

	/**
	 * Generate a QR image for a bio page and persist the resulting path on the row.
	 *
	 * @param BioPage $bio_page Bio page to generate a QR for.
	 * @return string|null A human-readable failure reason, or null on success.
	 */
	private function generate_and_attach_qr( BioPage $bio_page ): ?string {
		$result = $this->qr_generator->generate_to_uploads(
			$bio_page->get_public_url_for_qr(),
			array(
				'size'      => (int) get_option( 'qlqr_qr_default_size', 300 ),
				'fg_color'  => $bio_page->theme_color,
				'bg_color'  => '#ffffff',
			),
			$bio_page->slug
		);

		if ( $result ) {
			$this->repository->update( $bio_page->id, array( 'qr_image' => $result['url'] ) );

			// Same reasoning as LinkController::generate_and_attach_qr(): drop the image this one
			// supersedes so regenerating cannot accumulate orphaned files in uploads.
			if ( $result['url'] !== $bio_page->qr_image ) {
				QrCodeGenerator::delete_generated( $bio_page->qr_image );
			}

			return null;
		}

		return $this->qr_generator->get_last_error() ?? __( 'QR code could not be generated for an unknown reason.', 'plugnova-link-shortener-qr' );
	}

	/**
	 * Validate a hex color string, falling back to the plugin's default accent color if invalid.
	 *
	 * @param string $color Raw color value.
	 * @return string
	 */
	private function sanitize_hex_color( string $color ): string {
		$sanitized = sanitize_hex_color( $color );
		return $sanitized ?: '#2271b1';
	}

	/**
	 * Validate a hex color string, falling back to a caller-supplied default if invalid (used for
	 * the announcement bar's background/text colors, whose sensible defaults differ from the
	 * page's own theme_color default).
	 *
	 * @param string $color   Raw color value.
	 * @param string $default Fallback hex color if $color is invalid.
	 * @return string
	 */
	private function sanitize_hex_color_with_default( string $color, string $default ): string {
		$sanitized = sanitize_hex_color( $color );
		return $sanitized ?: $default;
	}


	/**
	 * Sanitize the optional announcement bar text, returning null for empty input.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	private function sanitize_optional_announcement_text( mixed $value ): ?string {
		if ( empty( $value ) ) {
			return null;
		}

		return mb_substr( sanitize_text_field( (string) $value ), 0, 255 );
	}

	/**
	 * Sanitize a group's client-generated stable identifier (or a link's reference to one) down
	 * to a short, storage-safe token. Both a group header's own group_key and a link's
	 * parent_group_key run through this, so a mismatched/mangled reference simply fails to match
	 * anything (renders as an ungrouped link) rather than causing an error.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	private function sanitize_group_key( mixed $value ): ?string {
		if ( empty( $value ) ) {
			return null;
		}

		$key = preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $value );

		return $key ? substr( $key, 0, 40 ) : null;
	}

	/**
	 * Sanitize a comma-separated list of 2-letter ISO country codes into a clean, deduplicated,
	 * uppercase comma-separated string (or null for "no restriction" / empty input).
	 *
	 * @param mixed $value Raw value — either an array of codes or a comma-separated string.
	 * @return string|null
	 */
	private function sanitize_countries_list( mixed $value ): ?string {
		if ( empty( $value ) ) {
			return null;
		}

		$raw = is_array( $value ) ? $value : explode( ',', (string) $value );

		$codes = array();
		foreach ( $raw as $code ) {
			$code = strtoupper( trim( (string) $code ) );
			if ( 2 === strlen( $code ) && ctype_alpha( $code ) ) {
				$codes[ $code ] = true;
			}
		}

		return $codes ? implode( ',', array_keys( $codes ) ) : null;
	}

	/**
	 * Sanitize a comma-separated list of device types into a clean, allow-listed comma-separated
	 * string (or null for "no restriction" / empty input).
	 *
	 * @param mixed $value Raw value — either an array of device types or a comma-separated string.
	 * @return string|null
	 */
	private function sanitize_devices_list( mixed $value ): ?string {
		if ( empty( $value ) ) {
			return null;
		}

		$raw     = is_array( $value ) ? $value : explode( ',', (string) $value );
		$allowed = array( 'mobile', 'desktop', 'tablet' );

		$devices = array();
		foreach ( $raw as $device ) {
			$device = strtolower( trim( (string) $device ) );
			if ( in_array( $device, $allowed, true ) ) {
				$devices[ $device ] = true;
			}
		}

		return $devices ? implode( ',', array_keys( $devices ) ) : null;
	}

	/**
	 * Restrict button_style to the supported allow-list.
	 *
	 * @param string $style Raw style value.
	 * @return string
	 */
	private function sanitize_button_style( string $style ): string {
		return in_array( $style, array( 'rounded', 'square', 'pill' ), true ) ? $style : 'rounded';
	}

	/**
	 * Restrict theme_preset to the supported allow-list.
	 *
	 * @param string $preset Raw preset value.
	 * @return string
	 */
	private function sanitize_theme_preset( string $preset ): string {
		return in_array( $preset, array( 'light', 'dark', 'gradient', 'minimal' ), true ) ? $preset : 'light';
	}

	/**
	 * Sanitize the optional email-capture gate heading, returning null for empty input (the
	 * public template falls back to a sensible default when null).
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	private function sanitize_optional_heading( mixed $value ): ?string {
		if ( empty( $value ) ) {
			return null;
		}

		return mb_substr( sanitize_text_field( (string) $value ), 0, 255 );
	}

	/**
	 * Sanitize the optional bio/description text, returning null for empty input.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	private function sanitize_optional_bio_text( mixed $value ): ?string {
		if ( empty( $value ) ) {
			return null;
		}

		// mb_substr(), not substr(): substr() cuts by byte offset, and a multi-byte UTF-8
		// character (e.g. Bengali, which is 3 bytes each) straddling the 500-byte mark gets
		// sliced in half, leaving invalid UTF-8 that MySQL then rejects outright — silently,
		// since nothing here checked $wpdb->update()'s result (now fixed in update()/create()
		// below). WordPress ships a compat shim for mb_substr() on hosts without the mbstring
		// extension, so this is safe everywhere.
		return mb_substr( sanitize_textarea_field( (string) $value ), 0, 500 );
	}

	/**
	 * Validate an optional URL, returning null if empty or malformed rather than erroring the
	 * whole save (the avatar is optional, so a bad value is treated as "not set").
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	private function sanitize_optional_url( mixed $value ): ?string {
		if ( empty( $value ) ) {
			return null;
		}

		$url = esc_url_raw( trim( (string) $value ) );

		return ( '' !== $url && wp_http_validate_url( $url ) ) ? $url : null;
	}

	/**
	 * Validate and sanitize a raw "links" input array into a clean list ready for
	 * BioLinksRepository::replace_for_page(). Entries missing a label or a valid URL are
	 * silently dropped rather than causing the whole save to fail.
	 *
	 * @param array<int, mixed> $raw Raw links input (typically decoded from JSON/POST).
	 * @return array<int, array{label:string, url:string, icon:string|null, image_url:string|null, status:string}>
	 */
	private function sanitize_links( array $raw ): array {
		$clean = array();

		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$is_group = 'group' === ( $entry['item_type'] ?? 'link' );
			$label    = mb_substr( sanitize_text_field( (string) ( $entry['label'] ?? '' ) ), 0, 190 );

			// A group header has no URL of its own — it's purely an accordion container — so the
			// URL-required check below only applies to normal link rows.
			$url = $is_group ? '' : esc_url_raw( trim( (string) ( $entry['url'] ?? '' ) ) );

			if ( '' === $label ) {
				continue;
			}

			if ( ! $is_group && ( '' === $url || ! wp_http_validate_url( $url ) ) ) {
				continue;
			}

			$icon = trim( (string) ( $entry['icon'] ?? '' ) );

			$clean[] = array(
				'label'     => $label,
				'url'       => $url,
				'icon'      => '' !== $icon ? mb_substr( $icon, 0, 10 ) : null,
				// A "product card" thumbnail is optional and independent of the icon — a button
				// can have neither, just an icon, or just an image (the public template prefers
				// image_url over icon when both are set, since a photo says more than an emoji).
				'image_url' => $is_group ? null : $this->sanitize_optional_url( $entry['image_url'] ?? null ),
				'status'    => 'disabled' === ( $entry['status'] ?? 'active' ) ? 'disabled' : 'active',
				'starts_at' => $is_group ? null : $this->sanitize_optional_datetime( $entry['starts_at'] ?? null ),
				'ends_at'   => $is_group ? null : $this->sanitize_optional_datetime( $entry['ends_at'] ?? null ),
				'item_type'         => $is_group ? 'group' : 'link',
				'group_key'         => $is_group ? $this->sanitize_group_key( $entry['group_key'] ?? null ) : null,
				'parent_group_key'  => $is_group ? null : $this->sanitize_group_key( $entry['parent_group_key'] ?? null ),
				'visible_countries' => $is_group ? null : $this->sanitize_countries_list( $entry['visible_countries'] ?? null ),
				'visible_devices'   => $is_group ? null : $this->sanitize_devices_list( $entry['visible_devices'] ?? null ),
			);
		}

		return $clean;
	}

	/**
	 * Validate an optional datetime string (from a <input type="datetime-local">), returning
	 * null for empty/unparseable input rather than erroring the whole save.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null MySQL datetime string, or null.
	 */
	private function sanitize_optional_datetime( mixed $value ): ?string {
		if ( empty( $value ) ) {
			return null;
		}

		$timestamp = strtotime( (string) $value );

		return $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : null;
	}

	/**
	 * Validate and sanitize a raw "social_links" input array into a clean list ready for
	 * BioSocialsRepository::replace_for_page(). Entries missing a valid URL are silently dropped.
	 *
	 * @param array<int, mixed> $raw Raw social links input (typically decoded from JSON/POST).
	 * @return array<int, array{platform:string, url:string, status:string}>
	 */
	private function sanitize_socials( array $raw ): array {
		$clean = array();

		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$url = esc_url_raw( trim( (string) ( $entry['url'] ?? '' ) ) );
			if ( '' === $url ) {
				continue;
			}

			// mailto:/tel: links don't pass wp_http_validate_url() (it requires http/https), but
			// they're valid and common for the "email"/website contact icons, so allow them
			// alongside normal http(s) validation instead of rejecting them outright.
			if ( ! wp_http_validate_url( $url ) && ! preg_match( '/^(mailto|tel):/i', $url ) ) {
				continue;
			}

			$platform = (string) ( $entry['platform'] ?? 'website' );
			if ( ! array_key_exists( $platform, BioSocial::PLATFORMS ) ) {
				$platform = 'website';
			}

			$clean[] = array(
				'platform'    => $platform,
				'url'         => $url,
				'status'      => 'disabled' === ( $entry['status'] ?? 'active' ) ? 'disabled' : 'active',
				'is_floating' => ! empty( $entry['is_floating'] ) ? 1 : 0,
			);
		}

		return $clean;
	}
}
