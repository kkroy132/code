<?php
/**
 * REST API endpoints for the Calendar.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Calendar_REST
 *
 * Registers ptp/v1/calendar. GET /calendar is a read-only aggregation
 * endpoint (custom events + live project/task/milestone deadlines) —
 * writing is only ever possible for custom events, under /calendar/events,
 * since editing a task/project/milestone deadline belongs to that
 * module's own (already-secured) REST endpoints.
 */
class PTP_Calendar_REST {

	/**
	 * Route base, relative to the ptp/v1 namespace.
	 *
	 * @var string
	 */
	const BASE = 'calendar';

	/**
	 * Capability required for every calendar endpoint, matching the
	 * capability the Calendar admin menu item already uses.
	 *
	 * @var string
	 */
	const CAPABILITY = 'ptp_manage_data';

	/**
	 * Register all calendar routes. Hooked on 'ptp_register_rest_routes'.
	 */
	public static function register_routes() {
		$ns = PTP_REST_API::NAMESPACE_NAME;

		register_rest_route(
			$ns,
			'/' . self::BASE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_items' ),
				'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
				'args'                => array(
					'start'      => array(
						'type'     => 'string',
						'required' => true,
					),
					'end'        => array(
						'type'     => 'string',
						'required' => true,
					),
					'filter'     => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'default'           => 'all',
					),
					'project_id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'default'           => 0,
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/' . self::BASE . '/events',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_events' ),
					'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
					'args'                => array(
						'start'      => array(
							'type'     => 'string',
							'required' => true,
						),
						'end'        => array(
							'type'     => 'string',
							'required' => true,
						),
						'project_id' => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'default'           => 0,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_event' ),
					'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
					'args'                => self::item_args(),
				),
			)
		);

		register_rest_route(
			$ns,
			'/' . self::BASE . '/events/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_event' ),
					'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update_event' ),
					'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
					'args'                => self::item_args(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete_event' ),
					'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
				),
			)
		);
	}

	/**
	 * Arg schema for creating/updating a custom event. Types here are
	 * shape validation only; PTP_Calendar_Repository::prepare_fields()
	 * owns the actual business rules.
	 *
	 * @return array
	 */
	private static function item_args() {
		return array(
			'title'            => array(
				'type'     => 'string',
				'required' => true,
			),
			'description'      => array( 'type' => 'string' ),
			'all_day'          => array( 'type' => 'boolean' ),
			'start_date'       => array(
				'type'     => 'string',
				'required' => true,
			),
			'start_time'       => array( 'type' => 'string' ),
			'end_date'         => array( 'type' => 'string' ),
			'end_time'         => array( 'type' => 'string' ),
			'project_id'       => array( 'type' => array( 'integer', 'null' ) ),
			'task_id'          => array( 'type' => array( 'integer', 'null' ) ),
			'milestone_id'     => array( 'type' => array( 'integer', 'null' ) ),
			'location'         => array( 'type' => 'string' ),
			'color'            => array( 'type' => 'string' ),
			'reminder_minutes' => array( 'type' => array( 'integer', 'string', 'null' ) ),
		);
	}

	/**
	 * GET /calendar — the unified aggregation endpoint.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_items( WP_REST_Request $request ) {
		$start = self::sanitize_range_date( $request->get_param( 'start' ) );
		$end   = self::sanitize_range_date( $request->get_param( 'end' ) );

		if ( ! $start || ! $end ) {
			return self::to_rest_error( new WP_Error( 'ptp_invalid_range', __( 'start and end must be valid dates (Y-m-d).', 'personal-project-tracker' ) ) );
		}

		$items = PTP_Calendar_Repository::get_unified_items(
			array(
				'start'      => $start,
				'end'        => $end,
				'filter'     => $request->get_param( 'filter' ),
				'project_id' => $request->get_param( 'project_id' ),
			)
		);

		return new WP_REST_Response( array( 'items' => $items ), 200 );
	}

	/**
	 * GET /calendar/events — custom events only, for the event management screens.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_events( WP_REST_Request $request ) {
		$start = self::sanitize_range_date( $request->get_param( 'start' ) );
		$end   = self::sanitize_range_date( $request->get_param( 'end' ) );

		if ( ! $start || ! $end ) {
			return self::to_rest_error( new WP_Error( 'ptp_invalid_range', __( 'start and end must be valid dates (Y-m-d).', 'personal-project-tracker' ) ) );
		}

		$events = PTP_Calendar_Repository::get_events_in_range( $start, $end, (int) $request->get_param( 'project_id' ) );

		return new WP_REST_Response(
			array( 'items' => array_map( array( __CLASS__, 'prepare_item' ), $events ) ),
			200
		);
	}

	/**
	 * GET /calendar/events/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_event( WP_REST_Request $request ) {
		$event = PTP_Calendar_Repository::get( (int) $request['id'] );

		if ( ! $event ) {
			return self::to_rest_error( new WP_Error( 'ptp_not_found', __( 'Event not found.', 'personal-project-tracker' ) ) );
		}

		return new WP_REST_Response( self::prepare_item( $event ), 200 );
	}

	/**
	 * POST /calendar/events
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_event( WP_REST_Request $request ) {
		$result = PTP_Calendar_Repository::create( $request->get_params() );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( self::prepare_item( PTP_Calendar_Repository::get( $result ) ), 201 );
	}

	/**
	 * PUT/PATCH/POST /calendar/events/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_event( WP_REST_Request $request ) {
		$id     = (int) $request['id'];
		$result = PTP_Calendar_Repository::update( $id, $request->get_params() );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( self::prepare_item( PTP_Calendar_Repository::get( $id ) ), 200 );
	}

	/**
	 * DELETE /calendar/events/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_event( WP_REST_Request $request ) {
		$result = PTP_Calendar_Repository::delete( (int) $request['id'] );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * @param mixed $value Raw date param.
	 * @return string|null 'Y-m-d' or null if invalid.
	 */
	private static function sanitize_range_date( $value ) {
		$value   = trim( (string) $value );
		$parsed  = DateTime::createFromFormat( 'Y-m-d', $value );

		return ( $parsed && $parsed->format( 'Y-m-d' ) === $value ) ? $value : null;
	}

	/**
	 * @param WP_Error $error Error to annotate.
	 * @return WP_Error
	 */
	private static function to_rest_error( WP_Error $error ) {
		$status = 'ptp_not_found' === $error->get_error_code() ? 404 : 400;
		$error->add_data( array( 'status' => $status ) );

		return $error;
	}

	/**
	 * @param object|null $event Custom event row.
	 * @return array|null
	 */
	private static function prepare_item( $event ) {
		if ( ! $event ) {
			return null;
		}

		return array(
			'id'               => (int) $event->id,
			'title'            => $event->title,
			'description'      => $event->description,
			'event_type'       => $event->event_type,
			'start_datetime'   => $event->start_datetime,
			'end_datetime'     => $event->end_datetime,
			'all_day'          => (bool) $event->all_day,
			'location'         => $event->location,
			'color'            => $event->color,
			'reminder_minutes' => null !== $event->reminder_minutes ? (int) $event->reminder_minutes : null,
			'project_id'       => null !== $event->project_id ? (int) $event->project_id : null,
			'task_id'          => null !== $event->task_id ? (int) $event->task_id : null,
			'milestone_id'     => null !== $event->milestone_id ? (int) $event->milestone_id : null,
			'created_at'       => $event->created_at,
			'updated_at'       => $event->updated_at,
		);
	}
}
