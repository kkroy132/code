<?php
/**
 * Backs the Redirects screen: list, create, update, delete. All the
 * actual validation (destination safety, allowed types, self-loop
 * prevention) lives in Redirect_Manager — this controller only maps
 * HTTP verbs to it and turns WP_Error into a 400 response.
 *
 * @package SEODoc
 */

namespace SEODoc\Rest_Api;

use SEODoc\Redirects\Redirect_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Redirects_Controller extends Rest_Controller {

	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/redirects',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/redirects/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
			)
		);
	}

	public function get_items( \WP_REST_Request $request ) {
		$page = max( 1, (int) $request->get_param( 'page' ) );

		return rest_ensure_response( Redirect_Manager::get_list( $page ) );
	}

	public function create_item( \WP_REST_Request $request ) {
		$redirect_type = (int) $request->get_param( 'redirect_type' );

		$result = Redirect_Manager::create(
			(string) $request->get_param( 'source' ),
			(string) $request->get_param( 'destination' ),
			$redirect_type ? $redirect_type : 301,
			array( 'group_name' => $request->get_param( 'group_name' ) )
		);

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		return rest_ensure_response( array( 'id' => $result ) );
	}

	public function update_item( \WP_REST_Request $request ) {
		$fields = array();

		foreach ( array( 'destination', 'redirect_type', 'status' ) as $key ) {
			$value = $request->get_param( $key );
			if ( null !== $value ) {
				$fields[ $key ] = $value;
			}
		}

		$result = Redirect_Manager::update( (int) $request['id'], $fields );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		return rest_ensure_response( array( 'updated' => true ) );
	}

	public function delete_item( \WP_REST_Request $request ) {
		Redirect_Manager::delete( (int) $request['id'] );

		return rest_ensure_response( array( 'deleted' => true ) );
	}
}
