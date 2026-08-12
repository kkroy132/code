<?php
/**
 * Extends Free's Rest_Controller and reuses its seodoc/v1 namespace —
 * deliberately not a separate seodoc-pro/v1 namespace, since this is the
 * same React admin app talking to one API, and Pro requires Free's
 * presence anyway. Backs the Search Console screen: connection status,
 * OAuth start/callback/disconnect, opportunities, content decay.
 *
 * @package SEODocPro
 */

namespace SEODocPro\Rest_Api;

use SEODoc\Rest_Api\Rest_Controller;
use SEODocPro\Gsc\Content_Decay;
use SEODocPro\Gsc\Oauth;
use SEODocPro\Gsc\Opportunity_Finder;
use SEODocPro\Licensing\License_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gsc_Controller extends Rest_Controller {

	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/gsc/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/gsc/connect-url',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_connect_url' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/gsc/callback',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_callback' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/gsc/disconnect',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'disconnect' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/gsc/opportunities',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_opportunities' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/gsc/content-decay',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_content_decay' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);
	}

	public function get_status() {
		return rest_ensure_response(
			array(
				'licensed'  => License_Manager::is_valid_license(),
				'connected' => Oauth::is_connected(),
			)
		);
	}

	public function get_connect_url() {
		if ( ! License_Manager::is_valid_license() ) {
			return new \WP_Error(
				'seodoc_not_licensed',
				__( 'A valid Pro license is required to connect Google Search Console.', 'wp-seo-doctor-pro' ),
				array( 'status' => 402 )
			);
		}

		return rest_ensure_response( array( 'url' => Oauth::get_authorize_url() ) );
	}

	public function handle_callback( \WP_REST_Request $request ) {
		$result = Oauth::complete_connection(
			(string) $request->get_param( 'grant_code' ),
			(string) $request->get_param( 'state' )
		);

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		return rest_ensure_response( array( 'connected' => true ) );
	}

	public function disconnect() {
		Oauth::disconnect();

		return rest_ensure_response( array( 'disconnected' => true ) );
	}

	public function get_opportunities() {
		if ( ! License_Manager::is_valid_license() || ! Oauth::is_connected() ) {
			return rest_ensure_response( array() );
		}

		return rest_ensure_response( Opportunity_Finder::find() );
	}

	public function get_content_decay() {
		if ( ! License_Manager::is_valid_license() || ! Oauth::is_connected() ) {
			return rest_ensure_response( array() );
		}

		return rest_ensure_response( Content_Decay::find() );
	}
}
