<?php
/**
 * Registers every REST controller on rest_api_init. Pro/third-party
 * controllers add themselves via the seodoc_rest_controllers filter
 * rather than this file needing to know about them.
 *
 * @package SEODoc
 */

namespace SEODoc\Rest_Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Rest_Api {

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register' ) );
	}

	public function register() {
		$controllers = apply_filters(
			'seodoc_rest_controllers',
			array(
				Overview_Controller::class,
				Issues_Controller::class,
				Suggestions_Controller::class,
				Monitor_404_Controller::class,
				Redirects_Controller::class,
				Notices_Controller::class,
			)
		);

		foreach ( $controllers as $class ) {
			if ( class_exists( $class ) ) {
				( new $class() )->register_routes();
			}
		}
	}
}
