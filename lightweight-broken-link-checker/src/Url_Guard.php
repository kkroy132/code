<?php
/**
 * Outbound request guard (SSRF protection).
 *
 * Every URL the plugin fetches passes through here first. The guard refuses
 * anything that is not a plain public HTTP(S) resource: other schemes,
 * credentials in the URL, uncommon ports, internal host names, and any host
 * that resolves to a loopback, private, link-local, shared or reserved
 * address, in IPv4 or IPv6.
 *
 * @package LWBLC
 */

namespace LWBLC;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Validates URLs before the HTTP client is allowed to touch them.
 */
class Url_Guard {

	/**
	 * Address ranges that must never be contacted, in CIDR form.
	 *
	 * @var string[]
	 */
	private static $blocked_v4 = array(
		'0.0.0.0/8',          // "This" network.
		'10.0.0.0/8',         // Private.
		'100.64.0.0/10',      // Carrier grade NAT.
		'127.0.0.0/8',        // Loopback.
		'169.254.0.0/16',     // Link local, includes cloud metadata.
		'172.16.0.0/12',      // Private.
		'192.0.0.0/24',       // IETF protocol assignments.
		'192.0.2.0/24',       // TEST-NET-1.
		'192.88.99.0/24',     // 6to4 relay anycast.
		'192.168.0.0/16',     // Private.
		'198.18.0.0/15',      // Benchmarking.
		'198.51.100.0/24',    // TEST-NET-2.
		'203.0.113.0/24',     // TEST-NET-3.
		'224.0.0.0/4',        // Multicast.
		'240.0.0.0/4',        // Reserved, includes 255.255.255.255.
	);

	/**
	 * IPv6 ranges that must never be contacted.
	 *
	 * @var string[]
	 */
	private static $blocked_v6 = array(
		'::/128',             // Unspecified.
		'::1/128',            // Loopback.
		'100::/64',           // Discard only.
		'2001:db8::/32',      // Documentation.
		'fc00::/7',           // Unique local.
		'fe80::/10',          // Link local.
		'ff00::/8',           // Multicast.
	);

	/**
	 * Host names that always point back inside the network.
	 *
	 * @var string[]
	 */
	private static $blocked_suffixes = array(
		'.localhost',
		'.local',
		'.localdomain',
		'.internal',
		'.intranet',
		'.intra',
		'.lan',
		'.private',
		'.corp',
		'.home',
		'.home.arpa',
		'.test',
		'.example',
		'.invalid',
	);

	/**
	 * Destination pinned for the request currently in flight.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $pinned = null;

	/**
	 * Registers the transport level hardening.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'http_api_curl', array( __CLASS__, 'harden_curl' ), 10, 3 );
	}

	/**
	 * Ports the checker is allowed to talk to.
	 *
	 * @return int[]
	 */
	public static function allowed_ports() {
		/**
		 * Filters the ports link checking may use.
		 *
		 * Everything else is refused, which keeps the checker away from
		 * databases, caches and admin services listening on the same network.
		 *
		 * @param int[] $ports Allowed port numbers.
		 */
		$ports = apply_filters( 'lwblc_allowed_ports', array( 80, 443 ) );

		return array_map( 'intval', (array) $ports );
	}

	/**
	 * Host names exempt from the private address rules.
	 *
	 * Empty by default. A site that deliberately wants to check links on a
	 * staging box or an intranet name can add it here, accepting that the
	 * protection no longer applies to that host.
	 *
	 * @return string[] Lower case host names.
	 */
	public static function trusted_hosts() {
		/**
		 * Filters host names allowed to resolve to otherwise blocked addresses.
		 *
		 * Only add hosts you control: entries here bypass the loopback, private
		 * and link-local checks for that name.
		 *
		 * @param string[] $hosts Host names.
		 */
		$hosts = apply_filters( 'lwblc_trusted_hosts', array() );

		return array_map( 'strtolower', array_map( 'strval', (array) $hosts ) );
	}

	/**
	 * Whether a host was explicitly exempted from the address rules.
	 *
	 * An entry beginning with a dot matches that domain and everything under
	 * it, so `.staging.example.com` covers the whole subtree.
	 *
	 * @param string $host Normalised host name.
	 * @return bool
	 */
	public static function is_trusted( $host ) {
		foreach ( self::trusted_hosts() as $entry ) {
			if ( '' === $entry ) {
				continue;
			}

			if ( $entry === $host ) {
				return true;
			}

			if ( '.' === $entry[0] && substr( $host, -strlen( $entry ) ) === $entry ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Validates a URL and resolves it to a safe destination.
	 *
	 * @param string $url URL to check.
	 * @return array{url:string,host:string,port:int,ip:string}|WP_Error Destination, or the reason it was refused.
	 */
	public static function validate( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url || strlen( $url ) > 2048 ) {
			return new WP_Error( 'lwblc_invalid_url', __( 'The address is empty or too long.', 'lightweight-broken-link-checker' ) );
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) ) {
			return new WP_Error( 'lwblc_invalid_url', __( 'The address could not be parsed.', 'lightweight-broken-link-checker' ) );
		}

		// The protocol is judged first, so `file:///etc/passwd` is reported for
		// what it is rather than as an address with a missing host.
		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'lwblc_unsupported_scheme', __( 'Only http and https addresses are checked.', 'lightweight-broken-link-checker' ) );
		}

		if ( empty( $parts['host'] ) ) {
			return new WP_Error( 'lwblc_invalid_url', __( 'The address has no host.', 'lightweight-broken-link-checker' ) );
		}

		// Credentials in a URL are a classic way to confuse a fetcher.
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return new WP_Error( 'lwblc_blocked_host', __( 'Addresses containing credentials are not checked.', 'lightweight-broken-link-checker' ) );
		}

		$port = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );

		if ( ! in_array( $port, self::allowed_ports(), true ) ) {
			return new WP_Error( 'lwblc_blocked_port', __( 'That port is not checked.', 'lightweight-broken-link-checker' ) );
		}

		$host = self::normalize_host( $parts['host'] );

		if ( '' === $host ) {
			return new WP_Error( 'lwblc_invalid_url', __( 'The address has no usable host name.', 'lightweight-broken-link-checker' ) );
		}

		// An explicitly trusted host is taken at face value.
		if ( self::is_trusted( $host ) ) {
			return array(
				'url'  => $url,
				'host' => $host,
				'port' => $port,
				'ip'   => $host,
			);
		}

		// A literal address needs no lookup, just a verdict.
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			if ( self::is_blocked_ip( $host ) ) {
				return new WP_Error( 'lwblc_blocked_ip', __( 'That address is on a private or reserved network.', 'lightweight-broken-link-checker' ) );
			}

			return array(
				'url'  => $url,
				'host' => $host,
				'port' => $port,
				'ip'   => $host,
			);
		}

		if ( self::is_blocked_host( $host ) ) {
			return new WP_Error( 'lwblc_blocked_host', __( 'That host name is local to this network.', 'lightweight-broken-link-checker' ) );
		}

		$addresses = self::resolve( $host );

		if ( empty( $addresses ) ) {
			return new WP_Error( 'lwblc_dns_failure', __( 'The host name could not be resolved.', 'lightweight-broken-link-checker' ) );
		}

		/*
		 * Every answer has to be acceptable, not just the first one: a name
		 * that resolves to both a public and a private address is exactly the
		 * shape of a rebinding attack.
		 */
		foreach ( $addresses as $address ) {
			if ( self::is_blocked_ip( $address ) ) {
				return new WP_Error( 'lwblc_blocked_ip', __( 'That host resolves to a private or reserved address.', 'lightweight-broken-link-checker' ) );
			}
		}

		return array(
			'url'  => $url,
			'host' => $host,
			'port' => $port,
			'ip'   => $addresses[0],
		);
	}

	/**
	 * Lower cases a host and strips IPv6 brackets and a trailing dot.
	 *
	 * @param string $host Host from the URL.
	 * @return string
	 */
	public static function normalize_host( $host ) {
		$host = strtolower( trim( (string) $host ) );
		$host = rtrim( $host, '.' );

		if ( '' !== $host && '[' === $host[0] && ']' === substr( $host, -1 ) ) {
			$host = substr( $host, 1, -1 );
		}

		return $host;
	}

	/**
	 * Whether a host name is one that never leaves the local network.
	 *
	 * @param string $host Normalised host name.
	 * @return bool
	 */
	public static function is_blocked_host( $host ) {
		if ( 'localhost' === $host ) {
			return true;
		}

		// A name with no dot is resolved through local search domains.
		if ( false === strpos( $host, '.' ) ) {
			return true;
		}

		foreach ( self::$blocked_suffixes as $suffix ) {
			if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolves a host name to every address it answers with.
	 *
	 * @param string $host Host name.
	 * @return string[] IPv4 and IPv6 addresses.
	 */
	public static function resolve( $host ) {
		$addresses = array();

		if ( function_exists( 'dns_get_record' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failed lookup is an expected outcome here.
			$records = @dns_get_record( $host, DNS_A + DNS_AAAA );

			if ( is_array( $records ) ) {
				foreach ( $records as $record ) {
					if ( ! empty( $record['ip'] ) ) {
						$addresses[] = $record['ip'];
					}

					if ( ! empty( $record['ipv6'] ) ) {
						$addresses[] = $record['ipv6'];
					}
				}
			}
		}

		if ( empty( $addresses ) && function_exists( 'gethostbynamel' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failed lookup is an expected outcome here.
			$v4 = @gethostbynamel( $host );

			if ( is_array( $v4 ) ) {
				$addresses = $v4;
			}
		}

		return array_values( array_unique( array_filter( $addresses ) ) );
	}

	/**
	 * Whether an IP address belongs to a range the checker must avoid.
	 *
	 * @param string $ip IPv4 or IPv6 address.
	 * @return bool
	 */
	public static function is_blocked_ip( $ip ) {
		$ip = trim( (string) $ip );

		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return true;
		}

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			foreach ( self::$blocked_v4 as $range ) {
				if ( self::in_range( $ip, $range ) ) {
					return true;
				}
			}

			return false;
		}

		// An IPv6 address can carry an IPv4 one; judge the address inside it.
		$embedded = self::embedded_v4( $ip );

		if ( '' !== $embedded ) {
			return self::is_blocked_ip( $embedded );
		}

		foreach ( self::$blocked_v6 as $range ) {
			if ( self::in_range( $ip, $range ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Extracts the IPv4 address wrapped inside a mapped, 6to4 or NAT64 address.
	 *
	 * @param string $ip IPv6 address.
	 * @return string IPv4 address, or an empty string.
	 */
	private static function embedded_v4( $ip ) {
		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Validated by the caller.

		if ( false === $packed || 16 !== strlen( $packed ) ) {
			return '';
		}

		// ::ffff:0:0/96 (IPv4 mapped) and ::/96 (IPv4 compatible).
		if ( "\0\0\0\0\0\0\0\0\0\0\xff\xff" === substr( $packed, 0, 12 )
			|| "\0\0\0\0\0\0\0\0\0\0\0\0" === substr( $packed, 0, 12 ) ) {
			return self::v4_from_bytes( substr( $packed, 12, 4 ) );
		}

		// 2002::/16, 6to4: the IPv4 address follows the prefix.
		if ( "\x20\x02" === substr( $packed, 0, 2 ) ) {
			return self::v4_from_bytes( substr( $packed, 2, 4 ) );
		}

		// 64:ff9b::/96, NAT64.
		if ( "\x00\x64\xff\x9b\0\0\0\0\0\0\0\0" === substr( $packed, 0, 12 ) ) {
			return self::v4_from_bytes( substr( $packed, 12, 4 ) );
		}

		return '';
	}

	/**
	 * Formats four packed bytes as a dotted IPv4 address.
	 *
	 * @param string $bytes Four raw bytes.
	 * @return string
	 */
	private static function v4_from_bytes( $bytes ) {
		if ( 4 !== strlen( $bytes ) ) {
			return '';
		}

		$parts = unpack( 'C4', $bytes );

		return implode( '.', $parts );
	}

	/**
	 * Whether an address falls inside a CIDR range.
	 *
	 * @param string $ip    IP address.
	 * @param string $range CIDR notation.
	 * @return bool
	 */
	public static function in_range( $ip, $range ) {
		$parts = explode( '/', $range, 2 );

		if ( 2 !== count( $parts ) ) {
			return false;
		}

		$subject = @inet_pton( $ip );         // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Validated by the caller.
		$network = @inet_pton( $parts[0] );   // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Hard coded constants.
		$bits    = (int) $parts[1];

		if ( false === $subject || false === $network || strlen( $subject ) !== strlen( $network ) ) {
			return false;
		}

		$whole = intdiv( $bits, 8 );
		$rest  = $bits % 8;

		if ( $whole > 0 && strncmp( $subject, $network, $whole ) !== 0 ) {
			return false;
		}

		if ( 0 === $rest ) {
			return true;
		}

		$mask = chr( 0xff << ( 8 - $rest ) & 0xff );

		return ( $subject[ $whole ] & $mask ) === ( $network[ $whole ] & $mask );
	}

	/**
	 * Remembers the validated destination for the request about to be sent.
	 *
	 * @param array<string,mixed> $destination Result of validate().
	 * @return void
	 */
	public static function pin( array $destination ) {
		self::$pinned = $destination;
	}

	/**
	 * Forgets the pinned destination.
	 *
	 * @return void
	 */
	public static function unpin() {
		self::$pinned = null;
	}

	/**
	 * Locks a cURL handle to the address that was validated.
	 *
	 * Without this the name is resolved a second time by cURL, which leaves a
	 * window for the answer to change between the check and the connection.
	 * Redirects are also disabled at transport level, so a 3xx can never move
	 * the request to an address that was never inspected.
	 *
	 * @param resource|\CurlHandle $handle      cURL handle.
	 * @param array                $parsed_args Request arguments.
	 * @param string               $url         Request URL.
	 * @return void
	 */
	public static function harden_curl( $handle, $parsed_args = array(), $url = '' ) {
		unset( $url ); // Part of the action signature; the pinned destination is what matters.

		if ( null === self::$pinned || empty( $parsed_args['lwblc'] ) ) {
			return;
		}

		if ( defined( 'CURLPROTO_HTTP' ) && defined( 'CURLPROTO_HTTPS' ) ) {
			curl_setopt( $handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
			curl_setopt( $handle, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
		}

		curl_setopt( $handle, CURLOPT_FOLLOWLOCATION, false ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt

		$host = self::$pinned['host'];
		$ip   = self::$pinned['ip'];

		// Only pin when a name was resolved; a literal address needs nothing.
		if ( $host === $ip ) {
			return;
		}

		curl_setopt( // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
			$handle,
			CURLOPT_RESOLVE,
			array( $host . ':' . self::$pinned['port'] . ':' . $ip )
		);
	}
}
