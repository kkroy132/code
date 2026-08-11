<?php
/**
 * Logs 404 hits as aggregates (one row per URL, incrementing hit_count)
 * rather than one row per request — the "efficient logging" requirement
 * from Step 1 §9/§30 and the schema decision documented in Step 3. This
 * is one of only two frontend hooks in the whole plugin (the other is
 * the redirect matcher in Step 11); no assets, no other frontend cost.
 *
 * @package SEODoc
 */

namespace SEODoc\Monitor_404;

use SEODoc\DB\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Monitor {

	public function __construct() {
		add_action( 'template_redirect', array( $this, 'maybe_log' ) );
	}

	public function maybe_log() {
		if ( ! is_404() ) {
			return;
		}

		$url = self::current_url();
		if ( ! $url ) {
			return;
		}

		self::record_hit( $url );
	}

	private static function current_url() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		$path = wp_unslash( $_SERVER['REQUEST_URI'] );

		return esc_url_raw( home_url( $path ) );
	}

	private static function record_hit( $url ) {
		global $wpdb;
		$table = Schema::table_names( $wpdb )['404'];
		$hash  = md5( $url );
		$now   = current_time( 'mysql' );

		$referrer   = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		$existing_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE url_hash = %s", $hash ) );

		if ( $existing_id ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET hit_count = hit_count + 1, last_seen = %s, last_referrer = %s, last_user_agent = %s WHERE id = %d",
					$now,
					$referrer,
					$user_agent,
					$existing_id
				)
			);
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'url'             => $url,
				'url_hash'        => $hash,
				'hit_count'       => 1,
				'last_referrer'   => $referrer,
				'last_user_agent' => $user_agent,
				'status'          => 'open',
				'first_seen'      => $now,
				'last_seen'       => $now,
			)
		);
	}
}
