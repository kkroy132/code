<?php
/**
 * Simple transient-based rate limiting for public-facing endpoints (redirects, REST API).
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Security;

use QuickLinkQRPro\Helpers\IpHash;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RateLimiter
 */
final class RateLimiter {

	/**
	 * Check whether the current visitor has exceeded the allowed number of requests per minute
	 * for a given action bucket, and record this request against the count.
	 *
	 * @param string $bucket           Logical action name, e.g. 'redirect' or 'rest_create_link'.
	 * @param int    $max_per_minute   Maximum allowed requests per rolling 60-second window.
	 * @return bool True if the request is within the limit, false if it should be blocked.
	 */
	public function allow( string $bucket, int $max_per_minute ): bool {
		if ( $max_per_minute < 1 ) {
			// A limit of zero would block every single request — including every short-link redirect
			// on the site. That is never what an administrator means, so treat a missing/invalid
			// limit as "not configured" and fail open, matching the unknown-IP case below.
			return true;
		}

		$ip = IpHash::get_request_ip();
		if ( '' === $ip ) {
			// If we can't identify the requester, fail open rather than blocking legitimate traffic
			// behind an exotic proxy setup; other security layers (nonces, capability checks) still apply.
			return true;
		}

		// The counter is bucketed by wall-clock minute, and the minute number is part of the key.
		// Writing the count back with set_transient() also resets its expiry, so a single fixed key
		// would never expire under sustained traffic: a visitor who hit the limit once would stay
		// blocked until they went a full 60 seconds in complete silence. Keying by minute means each
		// minute starts from zero no matter how continuous the traffic is.
		$window  = (int) floor( time() / MINUTE_IN_SECONDS );
		$key     = 'qlqr_rl_' . $bucket . '_' . $window . '_' . md5( $ip );
		$current = (int) get_transient( $key );

		if ( $current >= $max_per_minute ) {
			return false;
		}

		// Two minutes of TTL so the row outlives its own window and is then cleaned up on its own.
		set_transient( $key, $current + 1, 2 * MINUTE_IN_SECONDS );

		return true;
	}
}
