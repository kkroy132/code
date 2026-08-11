<?php
/**
 * Base REST controller: shared namespace constant and the capability
 * gate every route's permission_callback uses. Nonce validation for
 * cookie-authenticated requests is handled by WP core automatically when
 * the client sends X-WP-Nonce — nothing extra needed here for that part.
 *
 * @package SEODoc
 */

namespace SEODoc\Rest_Api;

use SEODoc\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Rest_Controller {

	const REST_NAMESPACE = 'seodoc/v1';

	abstract public function register_routes();

	public function permission_check() {
		return Capabilities::current_user_can_manage();
	}
}
