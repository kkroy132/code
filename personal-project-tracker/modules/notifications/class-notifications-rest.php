<?php
/**
 * REST API endpoints for Notifications + Reminders.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Notifications_REST
 *
 * Registers ptp/v1/notifications and ptp/v1/reminders. Every route
 * requires ptp_manage_data — notifications and reminders must be private,
 * per this module's requirements, matching the capability baseline every
 * other non-Finance module uses. A 'finance'-category notification is
 * additionally hidden from (and 404s for) anyone without
 * ptp_manage_finance, the same privacy rule Finance data follows
 * everywhere else in the plugin.
 */
class PTP_Notifications_REST {

	/**
	 * Capability required for every notifications/reminders endpoint.
	 *
	 * @var string
	 */
	const CAPABILITY = 'ptp_manage_data';

	/**
	 * Register all notifications + reminders routes. Hooked on 'ptp_register_rest_routes'.
	 */
	public static function register_routes() {
		$ns = PTP_REST_API::NAMESPACE_NAME;

		register_rest_route(
			$ns,
			'/notifications',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_notifications' ),
				'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
			)
		);

		register_rest_route(
			$ns,
			'/notifications/mark-all-read',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'mark_all_read' ),
				'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
			)
		);

		register_rest_route(
			$ns,
			'/notifications/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete_notification' ),
					'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/notifications/(?P<id>\d+)/read',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'mark_read' ),
				'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
			)
		);

		register_rest_route(
			$ns,
			'/notifications/(?P<id>\d+)/unread',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'mark_unread' ),
				'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
			)
		);

		register_rest_route(
			$ns,
			'/notifications/(?P<id>\d+)/snooze',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'snooze_notification' ),
				'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
			)
		);

		register_rest_route(
			$ns,
			'/reminders',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_reminders' ),
					'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_reminder' ),
					'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/reminders/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_reminder' ),
					'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update_reminder' ),
					'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete_reminder' ),
					'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/reminders/(?P<id>\d+)/snooze',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'snooze_reminder' ),
				'permission_callback' => PTP_Security::rest_permission( self::CAPABILITY ),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_notifications( WP_REST_Request $request ) {
		$can_finance = current_user_can( 'ptp_manage_finance' );

		$result = PTP_Notifications_Repository::get_list(
			array(
				'status'          => $request->get_param( 'status' ),
				'category'        => $request->get_param( 'category' ),
				'include_finance' => $can_finance,
				'paged'           => $request->get_param( 'page' ),
				'per_page'        => $request->get_param( 'per_page' ),
			)
		);

		return new WP_REST_Response(
			array(
				'items'       => array_map( array( __CLASS__, 'prepare_notification' ), $result['items'] ),
				'total'       => $result['total'],
				'total_pages' => $result['total_pages'],
				'page'        => $result['page'],
				'per_page'    => $result['per_page'],
				'unread'      => PTP_Notifications_Repository::get_unread_count( $can_finance ),
			),
			200
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function mark_read( WP_REST_Request $request ) {
		$notification = self::visible_or_404( (int) $request['id'] );

		if ( is_wp_error( $notification ) ) {
			return $notification;
		}

		PTP_Notifications_Repository::mark_read( $notification->id );

		return new WP_REST_Response( self::prepare_notification( PTP_Notifications_Repository::get( $notification->id ) ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function mark_unread( WP_REST_Request $request ) {
		$notification = self::visible_or_404( (int) $request['id'] );

		if ( is_wp_error( $notification ) ) {
			return $notification;
		}

		PTP_Notifications_Repository::mark_unread( $notification->id );

		return new WP_REST_Response( self::prepare_notification( PTP_Notifications_Repository::get( $notification->id ) ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function mark_all_read( WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$count = PTP_Notifications_Repository::mark_all_read( current_user_can( 'ptp_manage_finance' ) );

		return new WP_REST_Response( array( 'marked' => $count ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function snooze_notification( WP_REST_Request $request ) {
		$notification = self::visible_or_404( (int) $request['id'] );

		if ( is_wp_error( $notification ) ) {
			return $notification;
		}

		$until = PTP_Notifications_Service::resolve_snooze_until(
			(string) $request->get_param( 'preset' ),
			(int) $request->get_param( 'minutes' )
		);

		PTP_Notifications_Repository::snooze( $notification->id, $until );

		return new WP_REST_Response( self::prepare_notification( PTP_Notifications_Repository::get( $notification->id ) ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_notification( WP_REST_Request $request ) {
		$notification = self::visible_or_404( (int) $request['id'] );

		if ( is_wp_error( $notification ) ) {
			return $notification;
		}

		$result = PTP_Notifications_Repository::delete( $notification->id );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * Load a notification and 404 if it does not exist OR the current user
	 * cannot view its category (finance privacy — never reveal existence).
	 *
	 * @param int $id Notification ID.
	 * @return object|WP_Error
	 */
	private static function visible_or_404( $id ) {
		$notification = PTP_Notifications_Repository::get( $id );

		if ( ! $notification || ( 'finance' === $notification->category && ! current_user_can( 'ptp_manage_finance' ) ) ) {
			return self::to_rest_error( new WP_Error( 'ptp_not_found', __( 'Notification not found.', 'personal-project-tracker' ) ) );
		}

		return $notification;
	}

	/**
	 * @param object|null $notification Notification row.
	 * @return array|null
	 */
	private static function prepare_notification( $notification ) {
		if ( ! $notification ) {
			return null;
		}

		return array(
			'id'            => (int) $notification->id,
			'type'          => $notification->type,
			'title'         => $notification->title,
			'message'       => $notification->message,
			'category'      => $notification->category,
			'related_type'  => $notification->related_type,
			'related_id'    => $notification->related_id ? (int) $notification->related_id : null,
			'is_read'       => (bool) $notification->is_read,
			'snoozed_until' => $notification->snoozed_until,
			'deep_link'     => PTP_Notifications_Service::get_deep_link( $notification->related_type, $notification->related_id ),
			'created_at'    => $notification->created_at,
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_reminders( WP_REST_Request $request ) {
		$result = PTP_Reminders_Repository::get_list(
			array(
				'search'       => $request->get_param( 'search' ),
				'related_type' => $request->get_param( 'related_type' ),
				'status'       => $request->get_param( 'status' ),
				'paged'        => $request->get_param( 'page' ),
				'per_page'     => $request->get_param( 'per_page' ),
			)
		);

		return new WP_REST_Response(
			array(
				'items'       => array_map( array( __CLASS__, 'prepare_reminder' ), $result['items'] ),
				'total'       => $result['total'],
				'total_pages' => $result['total_pages'],
				'page'        => $result['page'],
				'per_page'    => $result['per_page'],
			),
			200
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_reminder( WP_REST_Request $request ) {
		$reminder = PTP_Reminders_Repository::get( (int) $request['id'] );

		if ( ! $reminder ) {
			return self::to_rest_error( new WP_Error( 'ptp_not_found', __( 'Reminder not found.', 'personal-project-tracker' ) ) );
		}

		return new WP_REST_Response( self::prepare_reminder( $reminder ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_reminder( WP_REST_Request $request ) {
		$result = PTP_Reminders_Repository::create( $request->get_params() );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( self::prepare_reminder( PTP_Reminders_Repository::get( $result ) ), 201 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_reminder( WP_REST_Request $request ) {
		$id     = (int) $request['id'];
		$result = PTP_Reminders_Repository::update( $id, $request->get_params() );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( self::prepare_reminder( PTP_Reminders_Repository::get( $id ) ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_reminder( WP_REST_Request $request ) {
		$result = PTP_Reminders_Repository::delete( (int) $request['id'] );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function snooze_reminder( WP_REST_Request $request ) {
		$id       = (int) $request['id'];
		$reminder = PTP_Reminders_Repository::get( $id );

		if ( ! $reminder ) {
			return self::to_rest_error( new WP_Error( 'ptp_not_found', __( 'Reminder not found.', 'personal-project-tracker' ) ) );
		}

		$until = PTP_Notifications_Service::resolve_snooze_until(
			(string) $request->get_param( 'preset' ),
			(int) $request->get_param( 'minutes' ),
			$reminder->remind_at
		);

		$result = PTP_Reminders_Repository::snooze( $id, $until );

		if ( is_wp_error( $result ) ) {
			return self::to_rest_error( $result );
		}

		return new WP_REST_Response( self::prepare_reminder( PTP_Reminders_Repository::get( $id ) ), 200 );
	}

	/**
	 * @param object|null $reminder Reminder row.
	 * @return array|null
	 */
	private static function prepare_reminder( $reminder ) {
		if ( ! $reminder ) {
			return null;
		}

		return array(
			'id'                  => (int) $reminder->id,
			'title'               => $reminder->title,
			'related_type'        => $reminder->related_type,
			'related_id'          => $reminder->related_id ? (int) $reminder->related_id : null,
			'remind_at'           => $reminder->remind_at,
			'recurrence'          => $reminder->recurrence,
			'recurrence_interval' => $reminder->recurrence_interval ? (int) $reminder->recurrence_interval : null,
			'status'              => $reminder->status,
			'snoozed_until'       => $reminder->snoozed_until,
			'deep_link'           => PTP_Notifications_Service::get_deep_link( $reminder->related_type, $reminder->related_id ),
			'created_at'          => $reminder->created_at,
			'updated_at'          => $reminder->updated_at,
		);
	}

	/**
	 * @param WP_Error $error Error to annotate.
	 * @return WP_Error
	 */
	private static function to_rest_error( WP_Error $error ) {
		$status_map = array( 'ptp_not_found' => 404 );
		$status     = $status_map[ $error->get_error_code() ] ?? 400;
		$error->add_data( array( 'status' => $status ) );

		return $error;
	}
}
