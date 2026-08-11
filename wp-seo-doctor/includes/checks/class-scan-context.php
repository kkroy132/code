<?php
/**
 * Wraps the object being scanned (a WP_Post for Free's MVP scope) and
 * lazily fetches the extras — live rendered HTML, parsed DOM — that only
 * some Checks need, so a Check that only reads post_content never
 * triggers an HTTP fetch, and multiple Checks that do need HTML share one
 * fetch per object instead of one each.
 *
 * @package SEODoc
 */

namespace SEODoc\Checks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Scan_Context {

	private $object_type;
	private $object_id;
	private $post;

	private $html;
	private $dom;

	private function __construct( $object_type, $object_id, \WP_Post $post = null ) {
		$this->object_type = $object_type;
		$this->object_id   = $object_id;
		$this->post        = $post;
	}

	/**
	 * @param string $object_type Currently only 'post' is handled; Pro
	 *               registers additional factories for term/archive/
	 *               attachment contexts via the seodoc_scan_context filter
	 *               (Step 12) rather than this switch growing per add-on.
	 * @param int    $object_id
	 * @return self|null Null when the object no longer exists (e.g. a
	 *               queue row for a post deleted mid-scan).
	 */
	public static function for_object( $object_type, $object_id ) {
		$context = null;

		if ( 'post' === $object_type ) {
			$post = get_post( $object_id );
			if ( $post ) {
				$context = new self( $object_type, $object_id, $post );
			}
		}

		return apply_filters( 'seodoc_scan_context', $context, $object_type, $object_id );
	}

	public function get_object_type() {
		return $this->object_type;
	}

	public function get_object_id() {
		return $this->object_id;
	}

	public function get_post() {
		return $this->post;
	}

	public function get_title() {
		return $this->post ? get_the_title( $this->post ) : '';
	}

	public function get_content() {
		return $this->post ? $this->post->post_content : '';
	}

	public function get_excerpt() {
		return $this->post ? $this->post->post_excerpt : '';
	}

	public function get_permalink() {
		return $this->post ? get_permalink( $this->post ) : '';
	}

	/**
	 * @param string $key
	 * @param bool   $single
	 * @return mixed
	 */
	public function get_meta( $key, $single = true ) {
		if ( ! $this->post ) {
			return $single ? '' : array();
		}
		return get_post_meta( $this->post->ID, $key, $single );
	}

	/**
	 * @return string Empty string on fetch failure or no URL.
	 */
	public function fetch_html() {
		if ( null !== $this->html ) {
			return $this->html;
		}

		$url = $this->get_permalink();
		if ( ! $url ) {
			$this->html = '';
			return $this->html;
		}

		$response = \SEODoc\Http_Client::get( $url );

		$this->html = is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_body( $response );

		return $this->html;
	}

	/**
	 * @return \DOMDocument|null Null when there's no HTML to parse.
	 */
	public function get_dom() {
		if ( null !== $this->dom ) {
			return $this->dom;
		}

		$html = $this->fetch_html();
		if ( '' === $html ) {
			return null;
		}

		$dom      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML( $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$this->dom = $dom;
		return $this->dom;
	}
}
