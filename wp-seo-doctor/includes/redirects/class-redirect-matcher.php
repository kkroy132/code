<?php
/**
 * The second (and last) frontend hook in the plugin, alongside Step 10's
 * 404 Monitor — see docs/10 for why there's no separate top-level
 * "frontend hooks" file. Hooked at priority 1 (well before Monitor's
 * default 10) so a matched redirect exits before 404 logging ever runs
 * for that request.
 *
 * @package SEODoc
 */

namespace SEODoc\Redirects;

use SEODoc\DB\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Redirect_Matcher {

	public function __construct() {
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 1 );
	}

	public function maybe_redirect() {
		if ( is_admin() ) {
			return;
		}

		$path = self::current_path();
		if ( '' === $path ) {
			return;
		}

		$redirect = self::find_active_redirect( $path );
		if ( ! $redirect ) {
			return;
		}

		self::record_hit( $redirect->id );

		wp_redirect( esc_url_raw( $redirect->destination_url ), (int) $redirect->redirect_type ); // phpcs:ignore WordPress.Security.SafeRedirect -- destination is admin-configured and validated at creation (Redirect_Manager::validate_destination()), not user-supplied at request time.
		exit;
	}

	private static function current_path() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		$path = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );

		return $path ? untrailingslashit( $path ) : '';
	}

	private static function find_active_redirect( $path ) {
		global $wpdb;
		$table = Schema::table_names( $wpdb )['redirects'];

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE source_hash = %s AND status = 'active' AND is_regex = 0 LIMIT 1",
				md5( $path )
			)
		);
	}

	private static function record_hit( $id ) {
		global $wpdb;
		$table = Schema::table_names( $wpdb )['redirects'];

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET hit_count = hit_count + 1, last_hit_at = %s WHERE id = %d",
				current_time( 'mysql' ),
				$id
			)
		);
	}
}
