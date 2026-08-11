<?php
/**
 * Free-tier redirect CRUD: exact-path 301/302 redirects only. Regex
 * matching, 307/308/410, redirect groups, and bulk import/export are Pro
 * (Step 1 §18 feature matrix — "Redirect Manager: Limited" for Free);
 * this class exposes the seams Pro extends rather than gating on
 * "is Pro active" anywhere in here (Step 1 §3).
 *
 * @package SEODoc
 */

namespace SEODoc\Redirects;

use SEODoc\DB\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Redirect_Manager {

	/**
	 * Free ships 301/302. Pro hooks this filter to add 307/308/410
	 * without any change here.
	 *
	 * @return int[]
	 */
	public static function allowed_redirect_types() {
		return apply_filters( 'seodoc_allowed_redirect_types', array( 301, 302 ) );
	}

	/**
	 * @param string $source
	 * @param string $destination
	 * @param int    $redirect_type
	 * @param array  $args { @type string $group_name Optional. }
	 * @return int|\WP_Error New redirect id.
	 */
	public static function create( $source, $destination, $redirect_type = 301, array $args = array() ) {
		$source_path = self::normalize_source( $source );
		if ( is_wp_error( $source_path ) ) {
			return $source_path;
		}

		$destination_check = self::validate_destination( $destination );
		if ( is_wp_error( $destination_check ) ) {
			return $destination_check;
		}

		if ( ! in_array( (int) $redirect_type, self::allowed_redirect_types(), true ) ) {
			return new \WP_Error( 'seodoc_invalid_redirect_type', __( 'This redirect type is not available.', 'wp-seo-doctor' ) );
		}

		$destination_url = esc_url_raw( trim( $destination ) );

		if ( self::is_self_loop( $source_path, $destination_url ) ) {
			return new \WP_Error( 'seodoc_redirect_loop', __( 'A redirect cannot point to itself.', 'wp-seo-doctor' ) );
		}

		if ( self::find_id_by_path( $source_path ) ) {
			return new \WP_Error( 'seodoc_redirect_exists', __( 'A redirect for this path already exists.', 'wp-seo-doctor' ) );
		}

		global $wpdb;
		$table = Schema::table_names( $wpdb )['redirects'];

		$wpdb->insert(
			$table,
			array(
				'source_path'     => $source_path,
				'source_hash'     => md5( $source_path ),
				'destination_url' => $destination_url,
				'redirect_type'   => (int) $redirect_type,
				'is_regex'        => 0,
				'group_name'      => isset( $args['group_name'] ) ? sanitize_text_field( $args['group_name'] ) : null,
				'status'          => 'active',
				'created_by'      => get_current_user_id() ? get_current_user_id() : null,
				'created_at'      => current_time( 'mysql' ),
				'updated_at'      => current_time( 'mysql' ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * @param int   $id
	 * @param array $fields Any of: destination, redirect_type, status.
	 * @return true|\WP_Error
	 */
	public static function update( $id, array $fields ) {
		global $wpdb;
		$table = Schema::table_names( $wpdb )['redirects'];

		$data = array( 'updated_at' => current_time( 'mysql' ) );

		if ( isset( $fields['destination'] ) ) {
			$check = self::validate_destination( $fields['destination'] );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
			$data['destination_url'] = esc_url_raw( trim( $fields['destination'] ) );
		}

		if ( isset( $fields['redirect_type'] ) ) {
			if ( ! in_array( (int) $fields['redirect_type'], self::allowed_redirect_types(), true ) ) {
				return new \WP_Error( 'seodoc_invalid_redirect_type', __( 'This redirect type is not available.', 'wp-seo-doctor' ) );
			}
			$data['redirect_type'] = (int) $fields['redirect_type'];
		}

		if ( isset( $fields['status'] ) && in_array( $fields['status'], array( 'active', 'inactive' ), true ) ) {
			$data['status'] = $fields['status'];
		}

		$wpdb->update( $table, $data, array( 'id' => (int) $id ) );

		return true;
	}

	public static function delete( $id ) {
		global $wpdb;
		$table = Schema::table_names( $wpdb )['redirects'];
		$wpdb->delete( $table, array( 'id' => (int) $id ) );

		return true;
	}

	/**
	 * @return object[]
	 */
	public static function get_list( $page = 1, $per_page = 20 ) {
		global $wpdb;
		$table  = Schema::table_names( $wpdb )['redirects'];
		$offset = ( max( 1, $page ) - 1 ) * $per_page;

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d", $per_page, $offset )
		);
	}

	private static function find_id_by_path( $source_path ) {
		global $wpdb;
		$table = Schema::table_names( $wpdb )['redirects'];

		return $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE source_hash = %s", md5( $source_path ) ) );
	}

	/**
	 * Same-site self-loop check only — a relative/same-host destination
	 * that resolves to the same path as the source. A destination on a
	 * different host sharing the same path (a legitimate cross-domain
	 * redirect) must never be flagged here.
	 */
	private static function is_self_loop( $source_path, $destination_url ) {
		$destination_host = wp_parse_url( $destination_url, PHP_URL_HOST );
		$site_host        = wp_parse_url( home_url(), PHP_URL_HOST );
		$is_same_site      = ! $destination_host || strtolower( $destination_host ) === strtolower( $site_host );

		return $is_same_site && self::normalize_source( $destination_url ) === $source_path;
	}

	/**
	 * @return string|\WP_Error A site-relative, untrailingslashed path.
	 */
	private static function normalize_source( $source ) {
		$path = wp_parse_url( trim( (string) $source ), PHP_URL_PATH );
		if ( ! $path ) {
			return new \WP_Error( 'seodoc_invalid_source', __( 'Enter a valid path to redirect from.', 'wp-seo-doctor' ) );
		}

		return untrailingslashit( $path );
	}

	/**
	 * @return true|\WP_Error
	 */
	private static function validate_destination( $destination ) {
		$destination = trim( (string) $destination );
		if ( '' === $destination ) {
			return new \WP_Error( 'seodoc_invalid_destination', __( 'Enter a destination URL.', 'wp-seo-doctor' ) );
		}

		if ( 0 === strpos( $destination, '/' ) ) {
			return true; // Site-relative path.
		}

		$scheme = wp_parse_url( $destination, PHP_URL_SCHEME );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new \WP_Error(
				'seodoc_invalid_destination',
				__( 'Redirect destinations must be a site-relative path or a full http(s) URL.', 'wp-seo-doctor' )
			);
		}

		return true;
	}
}
