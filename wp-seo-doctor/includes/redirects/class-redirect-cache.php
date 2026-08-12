<?php
/**
 * Caches Redirect_Matcher's per-request lookup via WP's object cache.
 * Found during the Step 16 performance audit: the matcher runs on every
 * single front-end request (Step 11), and until now did a DB query every
 * time regardless of whether any redirect existed for that path — the
 * overwhelming majority of requests, since redirects only ever cover a
 * small minority of paths.
 *
 * Honest about impact: WordPress' default object cache is per-request
 * only, so on the median install (no Redis/Memcached) this provides zero
 * cross-request benefit — the DB query still happens every time, exactly
 * as before. It's the sites that matter most for this cost (higher-
 * traffic installs, which are also the ones most likely to already run a
 * persistent object cache) that actually get the win. Either way this
 * is strictly additive: no behavior changes on sites without persistent
 * caching, and it's the standard, correct WordPress pattern for this
 * shape of problem (Step 1 §30's "use caching where appropriate").
 *
 * @package SEODoc
 */

namespace SEODoc\Redirects;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Redirect_Cache {

	const GROUP = 'seodoc_redirects';

	const VERSION_OPTION = 'seodoc_redirects_cache_version';

	/**
	 * @param string $hash
	 * @return object|null|false Cached redirect row, null for a cached
	 *                            "no redirect for this path", or false
	 *                            for a cache miss (caller must query).
	 */
	public static function get( $hash ) {
		$found = false;
		$value = wp_cache_get( self::key( $hash ), self::GROUP, false, $found );

		return $found ? $value : false;
	}

	/**
	 * @param string      $hash
	 * @param object|null $value Null caches a "no redirect" result.
	 */
	public static function set( $hash, $value ) {
		wp_cache_set( self::key( $hash ), $value, self::GROUP, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Invalidates every cached lookup at once by changing the key prefix
	 * every entry is stored under — cheaper and simpler than tracking
	 * and deleting every individual cached path. Called by
	 * Redirect_Manager on create/update/delete.
	 */
	public static function flush() {
		$version = (int) get_option( self::VERSION_OPTION, 1 );
		update_option( self::VERSION_OPTION, $version + 1 );
	}

	private static function key( $hash ) {
		// Autoloaded (default): this option is read on every front-end
		// request via find_active_redirect(), so it needs to ride along
		// in WP's single alloptions cache read rather than costing its
		// own separate lookup.
		$version = (int) get_option( self::VERSION_OPTION, 1 );

		return "v{$version}_{$hash}";
	}
}
