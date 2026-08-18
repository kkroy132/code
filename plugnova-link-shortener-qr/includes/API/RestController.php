<?php
/**
 * REST API endpoints under /wp-json/qlqr/v1/.
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\API;

use QuickLinkQRPro\Controllers\BioPageController;
use QuickLinkQRPro\Controllers\LinkController;
use QuickLinkQRPro\Database\BioLinksRepository;
use QuickLinkQRPro\Database\BioPagesRepository;
use QuickLinkQRPro\Database\BioSocialsRepository;
use QuickLinkQRPro\Database\ClicksRepository;
use QuickLinkQRPro\Database\LinksRepository;
use QuickLinkQRPro\Helpers\AnalyticsDays;
use QuickLinkQRPro\Helpers\Options;
use QuickLinkQRPro\Security\Capabilities;
use QuickLinkQRPro\Security\RateLimiter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RestController
 */
final class RestController {

	/**
	 * REST namespace.
	 */
	private const NAMESPACE = 'qlqr/v1';

	/**
	 * Register REST routes.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register all routes, gated by the "Enable REST API" setting.
	 */
	public function register_routes(): void {
		if ( ! Options::get( 'qlqr_rest_api_enabled' ) ) {
			return;
		}

		register_rest_route(
			self::NAMESPACE,
			'/links',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'search_links' ),
					'permission_callback' => array( $this, 'check_permissions' ),
					'args'                => array(
						'search'   => array( 'type' => 'string', 'required' => false ),
						'page'     => array( 'type' => 'integer', 'required' => false, 'default' => 1 ),
						'per_page' => array( 'type' => 'integer', 'required' => false, 'default' => 20 ),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_link' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/links/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_link' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_link' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_link' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/links/(?P<id>\d+)/qr',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'regenerate_qr' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/links/(?P<id>\d+)/analytics',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_analytics' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					// Same 7/30/90 allow-list as the admin analytics modals; Helpers\AnalyticsDays
					// further caps it to 7 for a free-plan account regardless of what's requested here.
					'days' => array( 'type' => 'integer', 'required' => false, 'default' => 30 ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/bio-pages',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'search_bio_pages' ),
					'permission_callback' => array( $this, 'check_permissions' ),
					'args'                => array(
						'search'   => array( 'type' => 'string', 'required' => false ),
						'page'     => array( 'type' => 'integer', 'required' => false, 'default' => 1 ),
						'per_page' => array( 'type' => 'integer', 'required' => false, 'default' => 20 ),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_bio_page' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/bio-pages/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_bio_page' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_bio_page' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_bio_page' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
			)
		);
	}

	/**
	 * Shared permission callback: requires the manage-links capability plus a valid REST nonce,
	 * and applies a per-minute rate limit to protect against brute-force/automation abuse.
	 *
	 * @return bool|WP_Error
	 */
	public function check_permissions(): bool|WP_Error {
		if ( ! Capabilities::current_user_can_manage() ) {
			return new WP_Error(
				'qlqr_forbidden',
				__( 'You do not have permission to manage Plugnova Link Shortener & QR links.', 'plugnova-link-shortener-qr' ),
				array( 'status' => 403 )
			);
		}

		$rate_limiter = new RateLimiter();
		if ( ! $rate_limiter->allow( 'rest_api', (int) Options::get( 'qlqr_rate_limit_per_min' ) ) ) {
			return new WP_Error(
				'qlqr_rate_limited',
				__( 'Too many requests. Please slow down.', 'plugnova-link-shortener-qr' ),
				array( 'status' => 429 )
			);
		}

		return true;
	}

	/**
	 * Convert a create()/clone_link() result blocked by the free plan's link/Bio Page limit into
	 * a 403 WP_Error, or null when $result wasn't blocked by that limit — in which case the
	 * caller falls back to its existing 400 WP_REST_Response for ordinary validation failures.
	 * LinkController::create()/clone_link() and BioPageController::create() only set
	 * 'upgrade_url' for the limit case, so its presence is what distinguishes the two.
	 *
	 * @param array{success:false, error:string, upgrade_url?:string} $result Failed create()/clone_link() result.
	 * @return WP_Error|null
	 */
	private function limit_error( array $result ): ?WP_Error {
		if ( ! isset( $result['upgrade_url'] ) ) {
			return null;
		}

		return new WP_Error(
			'qlqr_limit_reached',
			$result['error'],
			array(
				'status'      => 403,
				'upgrade_url' => $result['upgrade_url'],
			)
		);
	}

	/**
	 * GET /links — paginated search.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function search_links( WP_REST_Request $request ): WP_REST_Response {
		$repository = new LinksRepository();

		$result = $repository->query(
			array(
				'search'   => (string) $request->get_param( 'search' ),
				'paged'    => (int) $request->get_param( 'page' ),
				'per_page' => (int) $request->get_param( 'per_page' ),
			)
		);

		return new WP_REST_Response(
			array(
				'items' => array_map( array( $this, 'link_to_array' ), $result['items'] ),
				'total' => $result['total'],
			)
		);
	}

	/**
	 * POST /links — create a new short link.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function create_link( WP_REST_Request $request ): WP_Error|WP_REST_Response {
		$controller = new LinkController();
		$result     = $controller->create( $request->get_json_params() ?: $request->get_body_params() );

		if ( ! $result['success'] ) {
			return $this->limit_error( $result ) ?? new WP_REST_Response( array( 'error' => $result['error'] ), 400 );
		}

		return new WP_REST_Response( array( 'link' => $this->link_to_array( $result['link'] ) ), 201 );
	}

	/**
	 * GET /links/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_link( WP_REST_Request $request ): WP_REST_Response {
		$repository = new LinksRepository();
		$link       = $repository->find( (int) $request->get_param( 'id' ) );

		if ( ! $link ) {
			return new WP_REST_Response( array( 'error' => __( 'Link not found.', 'plugnova-link-shortener-qr' ) ), 404 );
		}

		return new WP_REST_Response( array( 'link' => $this->link_to_array( $link ) ) );
	}

	/**
	 * PUT/PATCH /links/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function update_link( WP_REST_Request $request ): WP_REST_Response {
		$repository = new LinksRepository();
		$id         = (int) $request->get_param( 'id' );
		$link       = $repository->find( $id );

		if ( ! $link ) {
			return new WP_REST_Response( array( 'error' => __( 'Link not found.', 'plugnova-link-shortener-qr' ) ), 404 );
		}

		$params      = $request->get_json_params() ?: $request->get_body_params();
		$allowed_map = array( 'title', 'destination_url', 'status', 'redirect_type', 'notes', 'tags', 'rotation_method', 'fallback_url' );
		$update      = array();

		// Multi-destination rotation is a Pro feature. This endpoint doesn't go through
		// LinkController::update() (it writes to the repository directly), so it needs the same
		// free-plan gate applied here explicitly: destination_type, destinations, rotation_method
		// and fallback_url are all part of that one feature (the latter two only have meaning
		// when destination_type is "multiple"), so a free-plan account's input for any of them is
		// ignored entirely — never validated, never written — leaving whatever is already stored
		// for this link (e.g. from a prior Pro period) untouched. A Pro account keeps today's
		// behavior exactly.
		$is_pro = qlqr_fs()->can_use_premium_code();

		foreach ( $allowed_map as $field ) {
			if ( ! array_key_exists( $field, $params ) ) {
				continue;
			}

			if ( ! $is_pro && in_array( $field, array( 'rotation_method', 'fallback_url' ), true ) ) {
				continue;
			}

			$update[ $field ] = match ( $field ) {
				'destination_url', 'fallback_url' => esc_url_raw( (string) $params[ $field ] ),
				'rotation_method' => in_array( $params[ $field ], array( 'round_robin', 'random', 'weighted_random' ), true ) ? $params[ $field ] : 'round_robin',
				default => sanitize_text_field( (string) $params[ $field ] ),
			};
		}

		if ( $is_pro && array_key_exists( 'destination_type', $params ) && in_array( $params['destination_type'], array( 'single', 'multiple' ), true ) ) {
			$update['destination_type'] = $params['destination_type'];
		}

		if ( empty( $update ) && ! ( $is_pro && array_key_exists( 'destinations', $params ) ) ) {
			return new WP_REST_Response( array( 'error' => __( 'No valid fields provided.', 'plugnova-link-shortener-qr' ) ), 400 );
		}

		if ( ! empty( $update ) ) {
			$repository->update( $id, $update );
		}

		// Multi-destination rotation list: replace wholesale if provided, same as the admin UI's
		// repeater field. Requires destination_type to end up as "multiple" (either just set above,
		// or already so on the existing link) — otherwise a destinations array with no matching
		// mode would be silently ignored, which could surprise a REST caller, so we validate it.
		// Gated to Pro accounts only, per the note above — a free-plan account's "destinations"
		// input is skipped entirely rather than validated/written.
		if ( $is_pro && array_key_exists( 'destinations', $params ) && is_array( $params['destinations'] ) ) {
			$effective_type = $update['destination_type'] ?? $link->destination_type;

			if ( 'multiple' !== $effective_type ) {
				return new WP_REST_Response(
					array( 'error' => __( 'destinations was provided but destination_type is not "multiple" (either set destination_type: "multiple" in this same request, or on the link already).', 'plugnova-link-shortener-qr' ) ),
					400
				);
			}

			$clean_destinations = array();
			foreach ( $params['destinations'] as $entry ) {
				if ( ! is_array( $entry ) || empty( $entry['destination_url'] ) ) {
					continue;
				}

				$url = esc_url_raw( (string) $entry['destination_url'] );
				if ( '' === $url || ! wp_http_validate_url( $url ) ) {
					continue;
				}

				$clean_destinations[] = array(
					'destination_url' => $url,
					'weight'          => max( 1, min( 1000, (int) ( $entry['weight'] ?? 1 ) ) ),
					'status'          => 'disabled' === ( $entry['status'] ?? 'active' ) ? 'disabled' : 'active',
				);
			}

			if ( empty( $clean_destinations ) ) {
				return new WP_REST_Response( array( 'error' => __( 'destinations must contain at least one valid destination_url.', 'plugnova-link-shortener-qr' ) ), 400 );
			}

			( new \QuickLinkQRPro\Database\DestinationsRepository() )->replace_for_link( $id, $clean_destinations );
		}

		return new WP_REST_Response( array( 'link' => $this->link_to_array( $repository->find( $id ) ) ) );
	}

	/**
	 * DELETE /links/{id} — moves the link to Trash (soft delete).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function delete_link( WP_REST_Request $request ): WP_REST_Response {
		$repository = new LinksRepository();
		$id         = (int) $request->get_param( 'id' );

		if ( ! $repository->find( $id ) ) {
			return new WP_REST_Response( array( 'error' => __( 'Link not found.', 'plugnova-link-shortener-qr' ) ), 404 );
		}

		$repository->trash( $id );

		return new WP_REST_Response( array( 'deleted' => true ) );
	}

	/**
	 * POST /links/{id}/qr — regenerate the QR image.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function regenerate_qr( WP_REST_Request $request ): WP_REST_Response {
		$controller = new LinkController();
		$result     = $controller->regenerate_qr(
			(int) $request->get_param( 'id' ),
			$request->get_json_params() ?: array()
		);

		if ( ! $result['success'] ) {
			return new WP_REST_Response( array( 'error' => $result['error'] ), 404 );
		}

		return new WP_REST_Response( array( 'link' => $this->link_to_array( $result['link'] ) ) );
	}

	/**
	 * GET /links/{id}/analytics.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_analytics( WP_REST_Request $request ): WP_REST_Response {
		$id     = (int) $request->get_param( 'id' );
		$clicks = new ClicksRepository();

		// Allow-list + free-plan cap, same as the admin analytics modals (see
		// AjaxHandler::sanitize_analytics_days()) — a free-plan caller gets at most 7 days
		// regardless of what "days" it requests here.
		$days = AnalyticsDays::sanitize( $request->get_param( 'days' ) );

		return new WP_REST_Response(
			array(
				'clicks_by_day' => $clicks->clicks_by_day( $days, $id ),
				'by_device'     => $clicks->top_by_dimension( 'device', 10, $id, true, $days ),
				'by_browser'    => $clicks->top_by_dimension( 'browser', 10, $id, true, $days ),
				'by_country'    => $clicks->top_by_dimension( 'country', 10, $id, true, $days ),
				'by_referrer'   => $clicks->top_by_dimension( 'referrer', 10, $id, true, $days ),
			)
		);
	}

	/**
	 * GET /bio-pages — paginated search.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function search_bio_pages( WP_REST_Request $request ): WP_REST_Response {
		$repository = new BioPagesRepository();

		$result = $repository->query(
			array(
				'search'   => (string) $request->get_param( 'search' ),
				'paged'    => (int) $request->get_param( 'page' ),
				'per_page' => (int) $request->get_param( 'per_page' ),
			)
		);

		return new WP_REST_Response(
			array(
				'items' => array_map( array( $this, 'bio_page_to_array' ), $result['items'] ),
				'total' => $result['total'],
			)
		);
	}

	/**
	 * POST /bio-pages — create a new Smart Bio Link page.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function create_bio_page( WP_REST_Request $request ): WP_Error|WP_REST_Response {
		$controller = new BioPageController();
		$result     = $controller->create( $request->get_json_params() ?: $request->get_body_params() );

		if ( ! $result['success'] ) {
			return $this->limit_error( $result ) ?? new WP_REST_Response( array( 'error' => $result['error'] ), 400 );
		}

		return new WP_REST_Response( array( 'bio_page' => $this->bio_page_to_array( $result['bio_page'] ) ), 201 );
	}

	/**
	 * GET /bio-pages/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_bio_page( WP_REST_Request $request ): WP_REST_Response {
		$repository = new BioPagesRepository();
		$bio_page   = $repository->find( (int) $request->get_param( 'id' ) );

		if ( ! $bio_page ) {
			return new WP_REST_Response( array( 'error' => __( 'Bio page not found.', 'plugnova-link-shortener-qr' ) ), 404 );
		}

		return new WP_REST_Response( array( 'bio_page' => $this->bio_page_to_array( $bio_page ) ) );
	}

	/**
	 * PUT/PATCH /bio-pages/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function update_bio_page( WP_REST_Request $request ): WP_REST_Response {
		$id         = (int) $request->get_param( 'id' );
		$repository = new BioPagesRepository();

		if ( ! $repository->find( $id ) ) {
			return new WP_REST_Response( array( 'error' => __( 'Bio page not found.', 'plugnova-link-shortener-qr' ) ), 404 );
		}

		$controller = new BioPageController();
		$result     = $controller->update( $id, $request->get_json_params() ?: $request->get_body_params() );

		if ( ! $result['success'] ) {
			return new WP_REST_Response( array( 'error' => $result['error'] ), 400 );
		}

		return new WP_REST_Response( array( 'bio_page' => $this->bio_page_to_array( $result['bio_page'] ) ) );
	}

	/**
	 * DELETE /bio-pages/{id} — moves the bio page to Trash (soft delete).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function delete_bio_page( WP_REST_Request $request ): WP_REST_Response {
		$repository = new BioPagesRepository();
		$id         = (int) $request->get_param( 'id' );

		if ( ! $repository->find( $id ) ) {
			return new WP_REST_Response( array( 'error' => __( 'Bio page not found.', 'plugnova-link-shortener-qr' ) ), 404 );
		}

		$repository->trash( $id );

		return new WP_REST_Response( array( 'deleted' => true ) );
	}

	/**
	 * Serialize a BioPage model (plus its buttons and social icons) to a plain array for JSON
	 * responses — mirrors Admin\AjaxHandler::serialize_bio_page() used by the admin UI.
	 *
	 * @param \QuickLinkQRPro\Models\BioPage $bio_page Bio page model.
	 * @return array<string, mixed>
	 */
	private function bio_page_to_array( \QuickLinkQRPro\Models\BioPage $bio_page ): array {
		return array(
			'id'                    => $bio_page->id,
			'slug'                  => $bio_page->slug,
			'title'                 => $bio_page->title,
			'bio_text'              => $bio_page->bio_text,
			'avatar_url'            => $bio_page->avatar_url,
			'theme_color'           => $bio_page->theme_color,
			'theme_preset'          => $bio_page->theme_preset,
			'button_style'          => $bio_page->button_style,
			'email_capture_enabled' => $bio_page->email_capture_enabled,
			'email_capture_heading' => $bio_page->email_capture_heading,
			'qr_image'              => $bio_page->qr_image,
			'status'                => $bio_page->status,
			'public_url'            => $bio_page->get_public_url(),
			'total_views'           => $bio_page->total_views,
			'qr_views'              => $bio_page->qr_views,
			'created_at'            => $bio_page->created_at,
			'updated_at'            => $bio_page->updated_at,
			'links'                 => array_map(
				static fn( \QuickLinkQRPro\Models\BioLink $l ): array => array(
					'id'        => $l->id,
					'label'     => $l->label,
					'url'       => $l->url,
					'icon'      => $l->icon,
					'image_url' => $l->image_url,
					'status'    => $l->status,
					'clicks'    => $l->clicks,
					'starts_at' => $l->starts_at,
					'ends_at'   => $l->ends_at,
				),
				( new BioLinksRepository() )->find_by_page( $bio_page->id )
			),
			'social_links'          => array_map(
				static fn( \QuickLinkQRPro\Models\BioSocial $s ): array => array(
					'id'       => $s->id,
					'platform' => $s->platform,
					'url'      => $s->url,
					'status'   => $s->status,
				),
				( new BioSocialsRepository() )->find_by_page( $bio_page->id )
			),
		);
	}

	/**
	 * Serialize a Link model to a plain array for JSON responses.
	 *
	 * @param \QuickLinkQRPro\Models\Link $link Link model.
	 * @return array<string, mixed>
	 */
	private function link_to_array( \QuickLinkQRPro\Models\Link $link ): array {
		return array(
			'id'              => $link->id,
			'title'           => $link->title,
			'destination_url' => $link->destination_url,
			'short_slug'      => $link->short_slug,
			'short_url'       => $link->get_short_url(),
			'qr_image'        => $link->qr_image,
			'status'          => $link->status,
			'redirect_type'   => $link->redirect_type,
			'total_clicks'    => $link->total_clicks,
			'is_favorite'     => $link->is_favorite,
			'created_at'      => $link->created_at,
			'updated_at'      => $link->updated_at,
			'destination_type' => $link->destination_type,
			'rotation_method'  => $link->rotation_method,
			'fallback_url'    => $link->fallback_url,
			'destinations'    => $link->is_multi_destination()
				? array_map(
					static fn( \QuickLinkQRPro\Models\Destination $d ): array => array(
						'id'              => $d->id,
						'destination_url' => $d->destination_url,
						'weight'          => $d->weight,
						'status'          => $d->status,
						'clicks'          => $d->clicks,
					),
					( new \QuickLinkQRPro\Database\DestinationsRepository() )->find_by_link( $link->id )
				)
				: array(),
		);
	}
}
