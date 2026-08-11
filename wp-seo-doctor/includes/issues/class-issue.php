<?php
/**
 * Issue value object returned by every Check::run(). Pulled forward from
 * Step 7 because the Check contract (Step 5) depends on it; Step 7 builds
 * the Issue_Engine's scoring/ranking on top of the storage shape defined
 * here, not this class itself.
 *
 * @package SEODoc
 */

namespace SEODoc\Issues;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Issue {

	public $check_id;
	public $category;
	public $severity;
	public $object_type;
	public $object_id;
	public $url;
	public $title;
	public $details;

	/**
	 * @param array $args {
	 *     @type string $check_id
	 *     @type string $category
	 *     @type string $severity
	 *     @type string $object_type
	 *     @type int|null $object_id
	 *     @type string $url
	 *     @type string $title
	 *     @type array $details
	 * }
	 */
	public function __construct( array $args ) {
		$this->check_id    = $args['check_id'];
		$this->category    = $args['category'];
		$this->severity    = $args['severity'];
		$this->object_type = isset( $args['object_type'] ) ? $args['object_type'] : 'post';
		$this->object_id   = isset( $args['object_id'] ) ? $args['object_id'] : null;
		$this->url         = isset( $args['url'] ) ? $args['url'] : '';
		$this->title       = $args['title'];
		$this->details     = isset( $args['details'] ) ? $args['details'] : array();
	}
}
