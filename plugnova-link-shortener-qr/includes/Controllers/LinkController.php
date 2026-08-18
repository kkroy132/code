<?php
/**
 * Application-level orchestration for creating/updating links, shared by the Admin UI and REST API.
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Controllers;

use QuickLinkQRPro\Database\DestinationsRepository;
use QuickLinkQRPro\Database\KeywordsRepository;
use QuickLinkQRPro\Database\LinksRepository;
use QuickLinkQRPro\Database\TargetingRulesRepository;
use QuickLinkQRPro\Helpers\QrCodeGenerator;
use QuickLinkQRPro\Helpers\SlugGenerator;
use QuickLinkQRPro\Models\Link;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class LinkController
 */
final class LinkController {

	/**
	 * Total active (non-trashed) link count the free plan is limited to. Pro accounts
	 * (qlqr_fs()->can_use_premium_code() === true) have no limit. Public so Admin\LinksPage can
	 * reference the same number for its "approaching the limit" notice instead of duplicating it.
	 */
	public const FREE_LINK_LIMIT = 50;

	/**
	 * Links repository.
	 *
	 * @var LinksRepository
	 */
	private LinksRepository $repository;

	/**
	 * Slug generator.
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
	 * Destinations repository, for multi-destination rotating links.
	 *
	 * @var DestinationsRepository
	 */
	private DestinationsRepository $destinations_repository;

	/**
	 * Targeting rules repository, for per-link Geo/Device redirect rules.
	 *
	 * @var TargetingRulesRepository
	 */
	private TargetingRulesRepository $targeting_rules_repository;

	/**
	 * Keywords repository, for the link's auto-linking keyword rules.
	 *
	 * @var KeywordsRepository
	 */
	private KeywordsRepository $keywords_repository;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->repository            = new LinksRepository();
		$this->slug_generator        = new SlugGenerator( $this->repository );
		$this->qr_generator          = new QrCodeGenerator();
		$this->destinations_repository = new DestinationsRepository();
		$this->targeting_rules_repository = new TargetingRulesRepository();
		$this->keywords_repository        = new KeywordsRepository();
	}

	/**
	 * Blocks a new link from being created once a free-plan account is already at
	 * FREE_LINK_LIMIT active links. Pro accounts always pass. Checked by both create() and
	 * clone_link() — the only two places that add a row to the links table — so every entry
	 * point (Quick Add, the Add New/Edit modal, CSV import, the Pretty Links/ThirstyAffiliates
	 * migrations, the REST API, and Clone) is covered from one place.
	 *
	 * @return array{success:false, error:string, upgrade_url:string}|null Null when creation may proceed.
	 */
	private function free_plan_limit_error(): ?array {
		if ( qlqr_fs()->can_use_premium_code() ) {
			return null;
		}

		if ( $this->repository->active_count() < self::FREE_LINK_LIMIT ) {
			return null;
		}

		return array(
			'success'     => false,
			'error'       => sprintf(
				/* translators: %d: the free plan's active link limit */
				__( "You've reached the %d-link limit on the free plan. Upgrade to Pro for unlimited links.", 'plugnova-link-shortener-qr' ),
				self::FREE_LINK_LIMIT
			),
			'upgrade_url' => qlqr_fs()->get_upgrade_url(),
		);
	}

	/**
	 * Create a new link from validated input, generating a slug and QR code as needed.
	 *
	 * @param array<string, mixed> $input {
	 *     @type string $title           Optional display title.
	 *     @type string $destination_url Required destination URL.
	 *     @type string $custom_slug     Optional custom slug; a random one is generated if empty.
	 *     @type int    $redirect_type   301|302|307.
	 *     @type string $qr_style        square|rounded|circle.
	 *     @type string $qr_fg_color     Hex color.
	 *     @type string $qr_bg_color     Hex color.
	 *     @type bool   $qr_transparent  Transparent background flag.
	 *     @type int    $qr_size         QR image size in px.
	 *     @type int|null $qr_logo_id    Attachment ID for a brand logo band above the QR code.
	 *     @type string|null $qr_caption_text Optional caption text baked into the QR image (e.g. "Check Price on Amazon").
	 *     @type string $status          active|disabled.
	 *     @type string|null $password   Plain-text password to hash and store, or null/empty for none.
	 *     @type string|null $expires_at MySQL datetime string, or null.
	 *     @type int|null $click_limit   Click limit, or null.
	 *     @type string|null $utm_source
	 *     @type string|null $utm_medium
	 *     @type string|null $utm_campaign
	 *     @type string|null $tags
	 *     @type string|null $category      Single free-text category/group name, or null.
	 *     @type string|null $color_label   Hex color for the row's visual label, or null.
	 *     @type string|null $notes
	 *     @type bool $nofollow
	 *     @type bool $sponsored
	 *     @type bool $new_tab
	 *     @type string $destination_type single|multiple. Default "single".
	 *     @type array<int, array{destination_url:string, weight?:int, status?:string}> $destinations
	 *           Required (at least one valid entry) when destination_type is "multiple".
	 *     @type string $rotation_method round_robin|random|weighted_random. Only used when destination_type is "multiple".
	 *     @type string|null $fallback_url Used when destination_type is "multiple" and no destination is active.
	 *     @type array<int, array{rule_type:string, match_value:string, destination_url:string, status?:string}> $targeting_rules
	 *           Optional Geo/Device redirect rules, evaluated in array order (first match wins) before
	 *           the normal single/multi-destination resolution. rule_type is "country" (match_value: a
	 *           2-letter ISO country code) or "device" (match_value: mobile|desktop|tablet).
	 *     @type array<int, array{keyword:string, case_sensitive?:bool, max_replacements?:int, status?:string}> $keywords
	 *           Optional auto-linking keyword rules: occurrences of these phrases in post/page content
	 *           are turned into links pointing at this link.
	 * }
	 * @return array{success:bool, link?:Link, error?:string, upgrade_url?:string}
	 */
	public function create( array $input ): array {
		$limit_error = $this->free_plan_limit_error();
		if ( null !== $limit_error ) {
			return $limit_error;
		}

		// Every one of these reads the raw value into a variable *first*, then allow-lists it.
		// Testing `$input['x'] ?? default` but then returning `$input['x']` would emit an
		// "Undefined array key" warning and yield null/'' whenever the key is absent — which is the
		// normal case for REST API callers and CSV/plugin imports, neither of which sends every field.
		// Multi-destination rotation is a Pro feature: a new link can never start as "multiple" on
		// the free plan, regardless of what the caller (admin UI, REST API, import) sends — same
		// gating shape as targeting_rules below.
		$raw_destination_type = (string) ( $input['destination_type'] ?? 'single' );
		$destination_type     = ( qlqr_fs()->can_use_premium_code() && in_array( $raw_destination_type, array( 'single', 'multiple' ), true ) )
			? $raw_destination_type
			: 'single';

		$destinations = array();

		if ( 'multiple' === $destination_type ) {
			$destinations = $this->sanitize_destinations( is_array( $input['destinations'] ?? null ) ? $input['destinations'] : array() );

			if ( empty( $destinations ) ) {
				return array(
					'success' => false,
					'error'   => __( 'Please add at least one valid destination URL.', 'plugnova-link-shortener-qr' ),
				);
			}

			// The links table's own destination_url column stays populated (not nullable, and used
			// by CSV export / REST responses / the admin list's "Destination" column) — the first
			// active destination is the most sensible single value to show there. Actual redirect
			// traffic always goes through the destinations table + rotation logic instead, never
			// through this column, once destination_type is "multiple".
			$destination_url = $destinations[0]['destination_url'];
		} else {
			$destination_url = esc_url_raw( trim( (string) ( $input['destination_url'] ?? '' ) ) );
			if ( '' === $destination_url || ! wp_http_validate_url( $destination_url ) ) {
				return array(
					'success' => false,
					'error'   => __( 'Please provide a valid destination URL.', 'plugnova-link-shortener-qr' ),
				);
			}
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

		$password_hash = null;
		if ( ! empty( $input['password'] ) ) {
			$password_hash = wp_hash_password( (string) $input['password'] );
		}

		// Geo/Device targeting is a Pro feature: a new link can never start with rules on the free
		// plan, regardless of what the caller (admin UI, REST API, import) sends.
		$targeting_rules = qlqr_fs()->can_use_premium_code()
			? $this->sanitize_targeting_rules( is_array( $input['targeting_rules'] ?? null ) ? $input['targeting_rules'] : array() )
			: array();
		$keywords        = $this->sanitize_keywords( is_array( $input['keywords'] ?? null ) ? $input['keywords'] : array() );

		$raw_status        = (string) ( $input['status'] ?? 'active' );
		$status            = in_array( $raw_status, array( 'active', 'disabled' ), true ) ? $raw_status : 'active';
		// 302, not 301: a 301 is cached by the browser forever, so the second and every later click
		// from that browser never reaches the site and is never counted. See do_redirect().
		$raw_redirect_type = (int) ( $input['redirect_type'] ?? get_option( 'qlqr_default_redirect', 302 ) );
		$redirect_type     = in_array( $raw_redirect_type, array( 301, 302, 307 ), true ) ? $raw_redirect_type : 302;
		$raw_qr_format     = (string) ( $input['qr_format'] ?? 'png' );
		$qr_format         = in_array( $raw_qr_format, array( 'png', 'svg' ), true ) ? $raw_qr_format : 'png';

		$row = array(
			'title'           => sanitize_text_field( (string) ( $input['title'] ?? '' ) ),
			'destination_url' => $destination_url,
			'short_slug'      => $slug,
			'qr_style'        => $this->sanitize_qr_style( (string) ( $input['qr_style'] ?? 'square' ) ),
			'qr_fg_color'     => $this->sanitize_hex_color( (string) ( $input['qr_fg_color'] ?? '#000000' ) ),
			'qr_bg_color'     => $this->sanitize_hex_color( (string) ( $input['qr_bg_color'] ?? '#ffffff' ) ),
			'qr_logo_id'      => ! empty( $input['qr_logo_id'] ) ? (int) $input['qr_logo_id'] : null,
			'qr_caption_text' => $this->sanitize_optional_text( $input['qr_caption_text'] ?? null ),
			'destination_type' => $destination_type,
			'rotation_method'  => $this->sanitize_rotation_method( (string) ( $input['rotation_method'] ?? 'round_robin' ) ),
			'fallback_url'    => 'multiple' === $destination_type ? $this->sanitize_optional_url( $input['fallback_url'] ?? null ) : null,
			'status'          => $status,
			'password'        => $password_hash,
			'expires_at'      => ! empty( $input['expires_at'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( (string) $input['expires_at'] ) ) : null,
			'click_limit'     => ! empty( $input['click_limit'] ) ? max( 1, (int) $input['click_limit'] ) : null,
			'redirect_type'   => $redirect_type,
			'utm_source'      => $this->sanitize_optional_text( $input['utm_source'] ?? null ),
			'utm_medium'      => $this->sanitize_optional_text( $input['utm_medium'] ?? null ),
			'utm_campaign'    => $this->sanitize_optional_text( $input['utm_campaign'] ?? null ),
			'tags'            => $this->sanitize_optional_text( $input['tags'] ?? null ),
			'category'        => $this->sanitize_optional_category( $input['category'] ?? null ),
			'notes'           => ! empty( $input['notes'] ) ? sanitize_textarea_field( (string) $input['notes'] ) : null,
			'color_label'     => $this->sanitize_optional_color_label( $input['color_label'] ?? null ),
			'nofollow'        => ! empty( $input['nofollow'] ) ? 1 : 0,
			'sponsored'       => ! empty( $input['sponsored'] ) ? 1 : 0,
			'new_tab'         => array_key_exists( 'new_tab', $input ) ? ( $input['new_tab'] ? 1 : 0 ) : 1,
			'has_targeting_rules' => empty( $targeting_rules ) ? 0 : 1,
		);

		$link = $this->repository->insert( $row );

		if ( ! $link ) {
			return array(
				'success' => false,
				'error'   => __( 'Could not save the link. Please try again.', 'plugnova-link-shortener-qr' ),
			);
		}

		if ( 'multiple' === $destination_type ) {
			$this->destinations_repository->replace_for_link( $link->id, $destinations );
		}

		if ( ! empty( $targeting_rules ) ) {
			$this->targeting_rules_repository->replace_for_link( $link->id, $targeting_rules );
		}

		if ( ! empty( $keywords ) ) {
			$this->keywords_repository->replace_for_link( $link->id, $keywords );
		}

		$qr_warning = $this->generate_and_attach_qr(
			$link,
			array(
				'size'               => ! empty( $input['qr_size'] ) ? (int) $input['qr_size'] : (int) get_option( 'qlqr_qr_default_size', 300 ),
				'style'              => $row['qr_style'],
				'fg_color'           => $row['qr_fg_color'],
				'bg_color'           => $row['qr_bg_color'],
				'transparent'        => ! empty( $input['qr_transparent'] ),
				'format'             => $qr_format,
				'logo_attachment_id' => $row['qr_logo_id'],
				'caption_text'       => $row['qr_caption_text'],
			)
		);

		$refreshed = $this->repository->find( $link->id );

		return array(
			'success'    => true,
			'link'       => $refreshed ?? $link,
			'qr_warning' => $qr_warning,
		);
	}

	/**
	 * Update an existing link's full details (everything except the short slug, which is kept
	 * immutable after creation so already-shared/printed links and QR codes never break).
	 *
	 * @param int                   $id    Link ID.
	 * @param array<string, mixed>  $input Same shape as create(), minus custom_slug (ignored if present).
	 * @return array{success:bool, link?:Link, error?:string, qr_warning?:string|null}
	 */
	public function update( int $id, array $input ): array {
		$link = $this->repository->find( $id );
		if ( ! $link ) {
			return array(
				'success' => false,
				'error'   => __( 'Link not found.', 'plugnova-link-shortener-qr' ),
			);
		}

		// See create()'s note above: read first, then allow-list, so a payload that omits the field
		// falls back to the link's existing value instead of warning and overwriting it with ''.
		// Multi-destination rotation is a Pro feature: a free-plan account can't switch a link to
		// "multiple" or edit its destinations — any destination_type/destinations the caller sent
		// is ignored entirely here (never even sanitized), leaving whatever is already stored for
		// this link untouched below, exactly like targeting_rules. A link that already has
		// destination_type "multiple" (e.g. set while the account was Pro) keeps that value on a
		// free-plan save of unrelated fields — only the free-plan *input* is ignored, not the
		// link's existing stored value.
		$raw_destination_type = (string) ( $input['destination_type'] ?? $link->destination_type );
		$destination_type     = ( qlqr_fs()->can_use_premium_code() && in_array( $raw_destination_type, array( 'single', 'multiple' ), true ) )
			? $raw_destination_type
			: $link->destination_type;

		$destinations = array();

		if ( 'multiple' === $destination_type && qlqr_fs()->can_use_premium_code() ) {
			$destinations = $this->sanitize_destinations( is_array( $input['destinations'] ?? null ) ? $input['destinations'] : array() );

			if ( empty( $destinations ) ) {
				return array(
					'success' => false,
					'error'   => __( 'Please add at least one valid destination URL.', 'plugnova-link-shortener-qr' ),
				);
			}

			$destination_url = $destinations[0]['destination_url'];
		} elseif ( 'multiple' === $destination_type ) {
			// Free-plan account whose link is already "multiple" from a prior Pro period:
			// destination_type is forced above to stay whatever it already was, but the
			// "destinations" input is never parsed or required here — the existing destination_url
			// and stored destination rows are left exactly as they are (see below, where
			// replace_for_link()/delete_for_link() are both skipped for a free-plan account).
			$destination_url = $link->destination_url;
		} else {
			$destination_url = esc_url_raw( trim( (string) ( $input['destination_url'] ?? '' ) ) );
			if ( '' === $destination_url || ! wp_http_validate_url( $destination_url ) ) {
				return array(
					'success' => false,
					'error'   => __( 'Please provide a valid destination URL.', 'plugnova-link-shortener-qr' ),
				);
			}
		}

		// Only replace the stored password hash if a new one was actually typed; an empty field
		// means "keep the current password" rather than "remove password protection" — clearing
		// protection entirely is a separate, explicit action to avoid accidental removal.
		$password_update = array();
		if ( array_key_exists( 'password', $input ) && '' !== trim( (string) $input['password'] ) ) {
			$password_update['password'] = wp_hash_password( (string) $input['password'] );
		} elseif ( ! empty( $input['clear_password'] ) ) {
			$password_update['password'] = null;
		}

		// Geo/Device targeting is a Pro feature: a free-plan account can't add, edit, or remove
		// rules, so any "targeting_rules" the caller sent is ignored entirely here (never even
		// sanitized) — exactly as if the key weren't present — which leaves whatever rules are
		// already stored for this link untouched below. A Pro account keeps today's behavior.
		$has_targeting_rules_input = qlqr_fs()->can_use_premium_code() && array_key_exists( 'targeting_rules', $input );
		$targeting_rules           = $has_targeting_rules_input
			? $this->sanitize_targeting_rules( is_array( $input['targeting_rules'] ) ? $input['targeting_rules'] : array() )
			: array();

		// Same "was the field submitted at all?" test as targeting rules: REST/CSV callers that never
		// send a keywords key must keep the link's existing keyword rules, while the admin modal
		// always sends the key (empty when the user removed every row) so clearing them works.
		$has_keywords_input = array_key_exists( 'keywords', $input );
		$keywords           = $has_keywords_input
			? $this->sanitize_keywords( is_array( $input['keywords'] ) ? $input['keywords'] : array() )
			: array();

		$qr_style    = $this->sanitize_qr_style( (string) ( $input['qr_style'] ?? $link->qr_style ) );
		$qr_fg_color = $this->sanitize_hex_color( (string) ( $input['qr_fg_color'] ?? $link->qr_fg_color ) );
		$qr_bg_color = $this->sanitize_hex_color( (string) ( $input['qr_bg_color'] ?? $link->qr_bg_color ) );
		$qr_caption  = array_key_exists( 'qr_caption_text', $input ) ? $this->sanitize_optional_text( $input['qr_caption_text'] ) : $link->qr_caption_text;
		// A missing key means "leave the logo as-is" (the edit modal didn't touch it); a present
		// key with an empty value means "remove the logo" — mirrors the password/clear_password
		// pattern above, since 0/'' can't otherwise be told apart from "field not submitted".
		$qr_logo_id  = array_key_exists( 'qr_logo_id', $input ) ? ( ! empty( $input['qr_logo_id'] ) ? (int) $input['qr_logo_id'] : null ) : $link->qr_logo_id;

		$raw_status        = (string) ( $input['status'] ?? $link->status );
		$status            = in_array( $raw_status, array( 'active', 'disabled' ), true ) ? $raw_status : $link->status;
		$raw_redirect_type = (int) ( $input['redirect_type'] ?? $link->redirect_type );
		$redirect_type     = in_array( $raw_redirect_type, array( 301, 302, 307 ), true ) ? $raw_redirect_type : $link->redirect_type;
		$raw_qr_format     = (string) ( $input['qr_format'] ?? 'png' );
		$qr_format         = in_array( $raw_qr_format, array( 'png', 'svg' ), true ) ? $raw_qr_format : 'png';

		$row = array_merge(
			array(
				'title'           => sanitize_text_field( (string) ( $input['title'] ?? $link->title ) ),
				'destination_url' => $destination_url,
				'status'          => $status,
				'expires_at'      => ! empty( $input['expires_at'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( (string) $input['expires_at'] ) ) : null,
				'click_limit'     => ! empty( $input['click_limit'] ) ? max( 1, (int) $input['click_limit'] ) : null,
				'redirect_type'   => $redirect_type,
				'utm_source'      => array_key_exists( 'utm_source', $input ) ? $this->sanitize_optional_text( $input['utm_source'] ) : $link->utm_source,
				'utm_medium'      => array_key_exists( 'utm_medium', $input ) ? $this->sanitize_optional_text( $input['utm_medium'] ) : $link->utm_medium,
				'utm_campaign'    => array_key_exists( 'utm_campaign', $input ) ? $this->sanitize_optional_text( $input['utm_campaign'] ) : $link->utm_campaign,
				'tags'            => array_key_exists( 'tags', $input ) ? $this->sanitize_optional_text( $input['tags'] ) : $link->tags,
				'category'        => array_key_exists( 'category', $input ) ? $this->sanitize_optional_category( $input['category'] ) : $link->category,
				'notes'           => array_key_exists( 'notes', $input ) ? ( ! empty( $input['notes'] ) ? sanitize_textarea_field( (string) $input['notes'] ) : null ) : $link->notes,
				'color_label'     => array_key_exists( 'color_label', $input ) ? $this->sanitize_optional_color_label( $input['color_label'] ) : $link->color_label,
				'nofollow'        => ! empty( $input['nofollow'] ) ? 1 : 0,
				'sponsored'       => ! empty( $input['sponsored'] ) ? 1 : 0,
				'new_tab'         => array_key_exists( 'new_tab', $input ) ? ( $input['new_tab'] ? 1 : 0 ) : (int) $link->new_tab,
				'qr_style'        => $qr_style,
				'qr_fg_color'     => $qr_fg_color,
				'qr_bg_color'     => $qr_bg_color,
				'qr_logo_id'      => $qr_logo_id,
				'qr_caption_text' => $qr_caption,
				'destination_type' => $destination_type,
				// rotation_method/fallback_url are part of the same Pro-gated multi-destination
				// feature as destination_type/destinations above: a free-plan account's input for
				// either is ignored (kept at the link's existing stored value) so it can't quietly
				// rewrite Pro-only rotation config that would take effect again on a future upgrade.
				'rotation_method'  => qlqr_fs()->can_use_premium_code()
					? $this->sanitize_rotation_method( (string) ( $input['rotation_method'] ?? $link->rotation_method ) )
					: $link->rotation_method,
				'fallback_url'    => 'multiple' === $destination_type
					? ( ( qlqr_fs()->can_use_premium_code() && array_key_exists( 'fallback_url', $input ) ) ? $this->sanitize_optional_url( $input['fallback_url'] ) : $link->fallback_url )
					: null,
				'has_targeting_rules' => $has_targeting_rules_input ? ( empty( $targeting_rules ) ? 0 : 1 ) : (int) $link->has_targeting_rules,
			),
			$password_update
		);

		// Unlike create() above (which already checks insert()'s result), this never checked
		// whether the write actually succeeded. See BioPageController::update() for the exact
		// failure this hides: a real save error would go completely unreported and the modal
		// would close as if it worked.
		if ( ! $this->repository->update( $id, $row ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Could not save changes. Please try again.', 'plugnova-link-shortener-qr' ),
			);
		}

		// Both branches below are Pro-only: a free-plan account never writes to the destinations
		// table at all, whether that would mean replacing rows or cleaning up now-unused ones —
		// its stored destinations (if any, from a prior Pro period) are left exactly as they are.
		if ( 'multiple' === $destination_type && qlqr_fs()->can_use_premium_code() ) {
			$this->destinations_repository->replace_for_link( $id, $destinations );
		} elseif ( $link->is_multi_destination() && qlqr_fs()->can_use_premium_code() ) {
			// Switched from multiple back to single: clean up the now-unused destination rows
			// rather than leaving them orphaned (they're harmless either way, since redirect
			// logic only reads them when destination_type is "multiple", but tidy is better).
			$this->destinations_repository->delete_for_link( $id );
		}

		if ( $has_targeting_rules_input ) {
			$this->targeting_rules_repository->replace_for_link( $id, $targeting_rules );
		}

		if ( $has_keywords_input ) {
			$this->keywords_repository->replace_for_link( $id, $keywords );
		}

		$qr_warning = $this->generate_and_attach_qr(
			$link,
			array(
				'size'               => (int) get_option( 'qlqr_qr_default_size', 300 ),
				'style'              => $qr_style,
				'fg_color'           => $qr_fg_color,
				'bg_color'           => $qr_bg_color,
				'transparent'        => ! empty( $input['qr_transparent'] ),
				'format'             => $qr_format,
				'logo_attachment_id' => $qr_logo_id,
				'caption_text'       => $qr_caption,
			)
		);

		return array(
			'success'    => true,
			'link'       => $this->repository->find( $id ),
			'qr_warning' => $qr_warning,
		);
	}

	/**
	 * Duplicate an existing link: same destination/QR style/password/schedule/UTM/targeting
	 * rules/multi-destinations, but a fresh unique slug (so it needs its own QR image), zero
	 * clicks, and a "(Copy)" suffix on the title so it's obvious in the list which is which.
	 *
	 * @param int $id Link ID to clone.
	 * @return array{success:bool, link?:Link, error?:string, upgrade_url?:string, qr_warning?:string|null}
	 */
	public function clone_link( int $id ): array {
		$original = $this->repository->find( $id );
		if ( ! $original ) {
			return array(
				'success' => false,
				'error'   => __( 'Link not found.', 'plugnova-link-shortener-qr' ),
			);
		}

		$limit_error = $this->free_plan_limit_error();
		if ( null !== $limit_error ) {
			return $limit_error;
		}

		$slug = $this->slug_generator->generate_unique( (int) get_option( 'qlqr_slug_length', 6 ) );

		$row = array(
			'title'               => $original->title ? $original->title . ' ' . __( '(Copy)', 'plugnova-link-shortener-qr' ) : '',
			'destination_url'     => $original->destination_url,
			'short_slug'          => $slug,
			'qr_style'            => $original->qr_style,
			'qr_fg_color'         => $original->qr_fg_color,
			'qr_bg_color'         => $original->qr_bg_color,
			'qr_logo_id'          => $original->qr_logo_id,
			'qr_caption_text'     => $original->qr_caption_text,
			'destination_type'    => $original->destination_type,
			'rotation_method'     => $original->rotation_method,
			'fallback_url'        => $original->fallback_url,
			'status'              => $original->status,
			'password'            => $original->password,
			'expires_at'          => $original->expires_at,
			'click_limit'         => $original->click_limit,
			'redirect_type'       => $original->redirect_type,
			'utm_source'          => $original->utm_source,
			'utm_medium'          => $original->utm_medium,
			'utm_campaign'        => $original->utm_campaign,
			'tags'                => $original->tags,
			'category'            => $original->category,
			'notes'               => $original->notes,
			'color_label'         => $original->color_label,
			'nofollow'            => $original->nofollow ? 1 : 0,
			'sponsored'           => $original->sponsored ? 1 : 0,
			'new_tab'             => $original->new_tab ? 1 : 0,
			'has_targeting_rules' => $original->has_targeting_rules ? 1 : 0,
		);

		$clone = $this->repository->insert( $row );

		if ( ! $clone ) {
			return array(
				'success' => false,
				'error'   => __( 'Could not clone the link. Please try again.', 'plugnova-link-shortener-qr' ),
			);
		}

		if ( $original->is_multi_destination() ) {
			$destinations = $this->destinations_repository->find_by_link( $original->id );
			$this->destinations_repository->replace_for_link(
				$clone->id,
				array_map(
					static fn( \QuickLinkQRPro\Models\Destination $d ): array => array(
						'destination_url' => $d->destination_url,
						'weight'          => $d->weight,
						'status'          => $d->status,
					),
					$destinations
				)
			);
		}

		if ( $original->has_targeting_rules ) {
			$rules = $this->targeting_rules_repository->find_by_link( $original->id );
			$this->targeting_rules_repository->replace_for_link(
				$clone->id,
				array_map(
					static fn( \QuickLinkQRPro\Models\TargetingRule $r ): array => array(
						'rule_type'       => $r->rule_type,
						'match_value'     => $r->match_value,
						'destination_url' => $r->destination_url,
						'status'          => $r->status,
					),
					$rules
				)
			);
		}

		$qr_warning = $this->generate_and_attach_qr(
			$clone,
			array(
				'size'               => (int) get_option( 'qlqr_qr_default_size', 300 ),
				'style'              => $clone->qr_style,
				'fg_color'           => $clone->qr_fg_color,
				'bg_color'           => $clone->qr_bg_color,
				'transparent'        => false,
				'format'             => 'png',
				'logo_attachment_id' => $clone->qr_logo_id,
				'caption_text'       => $clone->qr_caption_text,
			)
		);

		return array(
			'success'    => true,
			'link'       => $this->repository->find( $clone->id ) ?? $clone,
			'qr_warning' => $qr_warning,
		);
	}

	/**
	 * Regenerate the QR code for an existing link (e.g. after changing style/colors).
	 *
	 * @param int                   $id      Link ID.
	 * @param array<string, mixed>  $options QR rendering options (see generate_and_attach_qr()).
	 * @return array{success:bool, link?:Link, error?:string}
	 */
	public function regenerate_qr( int $id, array $options = array() ): array {
		$link = $this->repository->find( $id );
		if ( ! $link ) {
			return array(
				'success' => false,
				'error'   => __( 'Link not found.', 'plugnova-link-shortener-qr' ),
			);
		}

		$merged = wp_parse_args(
			$options,
			array(
				'size'               => (int) get_option( 'qlqr_qr_default_size', 300 ),
				'style'              => $link->qr_style,
				'fg_color'           => $link->qr_fg_color,
				'bg_color'           => $link->qr_bg_color,
				'transparent'        => false,
				'format'             => 'png',
				'logo_attachment_id' => $link->qr_logo_id,
				'caption_text'       => $link->qr_caption_text,
			)
		);

		$caption_text = array_key_exists( 'caption_text', $options ) ? $this->sanitize_optional_text( $options['caption_text'] ) : $link->qr_caption_text;
		$merged['caption_text'] = $caption_text;

		$this->repository->update(
			$id,
			array(
				'qr_style'        => $this->sanitize_qr_style( (string) $merged['style'] ),
				'qr_fg_color'     => $this->sanitize_hex_color( (string) $merged['fg_color'] ),
				'qr_bg_color'     => $this->sanitize_hex_color( (string) $merged['bg_color'] ),
				'qr_caption_text' => $caption_text,
			)
		);

		$qr_warning = $this->generate_and_attach_qr( $link, $merged );

		return array(
			'success'    => true,
			'link'       => $this->repository->find( $id ),
			'qr_warning' => $qr_warning,
		);
	}

	/**
	 * Generate a QR image for a link and persist the resulting path on the row.
	 *
	 * @param Link                 $link    Link to generate a QR for.
	 * @param array<string, mixed> $options QrCodeGenerator options.
	 * @return string|null A human-readable failure reason, or null on success.
	 */
	private function generate_and_attach_qr( Link $link, array $options ): ?string {
		$result = $this->qr_generator->generate_to_uploads( $link->get_short_url_for_qr(), $options, $link->short_slug );

		if ( $result ) {
			$this->repository->update( $link->id, array( 'qr_image' => $result['url'] ) );

			// Remove the image this one replaces. Filenames embed a hash of the render options, so
			// without this every colour/style change would leave another orphan in uploads forever.
			if ( $result['url'] !== $link->qr_image ) {
				QrCodeGenerator::delete_generated( $link->qr_image );
			}

			return null;
		}

		return $this->qr_generator->get_last_error() ?? __( 'QR code could not be generated for an unknown reason.', 'plugnova-link-shortener-qr' );
	}

	/**
	 * Restrict qr_style to the supported allow-list.
	 *
	 * @param string $style Raw style value.
	 * @return string
	 */
	private function sanitize_qr_style( string $style ): string {
		return in_array( $style, array( 'square', 'rounded', 'circle' ), true ) ? $style : 'square';
	}

	/**
	 * Validate a hex color string, falling back to black if invalid.
	 *
	 * @param string $color Raw color value.
	 * @return string
	 */
	private function sanitize_hex_color( string $color ): string {
		$sanitized = sanitize_hex_color( $color );
		return $sanitized ?: '#000000';
	}

	/**
	 * Validate an optional hex color used as a visual row label, returning null for empty/invalid
	 * input rather than erroring the whole save (the label is purely decorative).
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	private function sanitize_optional_color_label( mixed $value ): ?string {
		if ( empty( $value ) ) {
			return null;
		}

		return sanitize_hex_color( (string) $value ) ?: null;
	}

	/**
	 * Sanitize an optional free-text field, returning null for empty input.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	private function sanitize_optional_text( mixed $value ): ?string {
		if ( empty( $value ) ) {
			return null;
		}

		// mb_substr(), not substr(): see BioPageController::sanitize_optional_bio_text() for why
		// a byte-based cutoff corrupts multi-byte text (e.g. Bengali) and can make the whole
		// $wpdb->update() fail silently.
		return mb_substr( sanitize_text_field( (string) $value ), 0, 255 );
	}

	/**
	 * Sanitize the optional category field, returning null for empty input. Capped at 100 chars
	 * to match the links.category column width.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	private function sanitize_optional_category( mixed $value ): ?string {
		if ( empty( $value ) ) {
			return null;
		}

		return mb_substr( sanitize_text_field( (string) $value ), 0, 100 );
	}

	/**
	 * Validate and sanitize a raw "destinations" input array into a clean list ready for
	 * DestinationsRepository::replace_for_link(). Entries with an empty or malformed URL are
	 * silently dropped rather than causing the whole save to fail — a partially-filled repeater
	 * row (e.g. the user added a blank row and didn't remove it) shouldn't block saving the
	 * valid entries.
	 *
	 * @param array<int, mixed> $raw Raw destinations input (typically decoded from JSON/POST).
	 * @return array<int, array{destination_url:string, weight:int, status:string}>
	 */
	private function sanitize_destinations( array $raw ): array {
		$clean = array();

		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$url = esc_url_raw( trim( (string) ( $entry['destination_url'] ?? '' ) ) );
			if ( '' === $url || ! wp_http_validate_url( $url ) ) {
				continue;
			}

			$clean[] = array(
				'destination_url' => $url,
				'weight'          => max( 1, min( 1000, (int) ( $entry['weight'] ?? 1 ) ) ),
				'status'          => 'disabled' === ( $entry['status'] ?? 'active' ) ? 'disabled' : 'active',
			);
		}

		return $clean;
	}

	/**
	 * Validate and sanitize a raw "targeting_rules" input array into a clean list ready for
	 * TargetingRulesRepository::replace_for_link(). Entries with an invalid rule_type/match_value/
	 * destination_url are silently dropped rather than failing the whole save — same rationale as
	 * sanitize_destinations() above.
	 *
	 * @param array<int, mixed> $raw Raw targeting rules input (typically decoded from JSON/POST).
	 * @return array<int, array{rule_type:string, match_value:string, destination_url:string, status:string}>
	 */
	private function sanitize_targeting_rules( array $raw ): array {
		$clean = array();

		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$rule_type = 'device' === ( $entry['rule_type'] ?? 'country' ) ? 'device' : 'country';

			$url = esc_url_raw( trim( (string) ( $entry['destination_url'] ?? '' ) ) );
			if ( '' === $url || ! wp_http_validate_url( $url ) ) {
				continue;
			}

			if ( 'device' === $rule_type ) {
				$match_value = strtolower( trim( (string) ( $entry['match_value'] ?? '' ) ) );
				if ( ! in_array( $match_value, array( 'mobile', 'desktop', 'tablet' ), true ) ) {
					continue;
				}
			} else {
				$match_value = strtoupper( trim( (string) ( $entry['match_value'] ?? '' ) ) );
				if ( 2 !== strlen( $match_value ) || ! ctype_alpha( $match_value ) ) {
					continue;
				}
			}

			$clean[] = array(
				'rule_type'       => $rule_type,
				'match_value'     => $match_value,
				'destination_url' => $url,
				'status'          => 'disabled' === ( $entry['status'] ?? 'active' ) ? 'disabled' : 'active',
			);
		}

		return $clean;
	}

	/**
	 * Validate and sanitize a raw "keywords" input array into a clean list ready for
	 * KeywordsRepository::replace_for_link(). Entries without keyword text are silently dropped, and
	 * a keyword repeated within the same submission is kept only once (case-insensitively) so the
	 * same phrase can't be registered twice for one link.
	 *
	 * @param array<int, mixed> $raw Raw keywords input (typically decoded from JSON/POST).
	 * @return array<int, array{keyword:string, case_sensitive:int, max_replacements:int, status:string}>
	 */
	private function sanitize_keywords( array $raw ): array {
		$clean = array();
		$seen  = array();

		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			// 190 characters is the keyword column's width; mb_substr keeps a multi-byte phrase from
			// being cut mid-character.
			$keyword = trim( sanitize_text_field( (string) ( $entry['keyword'] ?? '' ) ) );
			$keyword = mb_substr( $keyword, 0, 190 );

			if ( '' === $keyword ) {
				continue;
			}

			// strtolower(), not mb_strtolower(): WordPress ships a compat shim for mb_substr() but not
			// for mb_strtolower(), so calling it would be a fatal error on a host without mbstring.
			// The cost is only that "CAFÉ" and "café" would both be kept — an ASCII-only fold is
			// enough to stop the duplicate that actually happens in practice.
			$fingerprint = strtolower( $keyword );
			if ( isset( $seen[ $fingerprint ] ) ) {
				continue;
			}
			$seen[ $fingerprint ] = true;

			$clean[] = array(
				'keyword'          => $keyword,
				'case_sensitive'   => empty( $entry['case_sensitive'] ) ? 0 : 1,
				'max_replacements' => max( 1, min( 50, (int) ( $entry['max_replacements'] ?? 1 ) ) ),
				'status'           => 'disabled' === ( $entry['status'] ?? 'active' ) ? 'disabled' : 'active',
			);
		}

		return $clean;
	}

	/**
	 * Restrict rotation_method to the supported allow-list.
	 *
	 * @param string $method Raw rotation method value.
	 * @return string
	 */
	private function sanitize_rotation_method( string $method ): string {
		return in_array( $method, array( 'round_robin', 'random', 'weighted_random' ), true ) ? $method : 'round_robin';
	}

	/**
	 * Validate an optional fallback URL, returning null if empty or malformed rather than erroring
	 * the whole save — a fallback URL is optional, so a bad value is treated as "not set".
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
}
