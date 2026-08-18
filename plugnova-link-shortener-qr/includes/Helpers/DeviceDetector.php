<?php
/**
 * Lightweight user-agent parsing for device/browser/OS analytics.
 * Intentionally dependency-free (no bundled UA database) to keep the plugin lightweight;
 * covers the common cases used for dashboard charts.
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class DeviceDetector
 */
final class DeviceDetector {

	/**
	 * Parse the click's device type from a user agent string.
	 *
	 * @param string $user_agent Raw User-Agent header.
	 * @return string One of: mobile, tablet, desktop.
	 */
	public static function device_type( string $user_agent ): string {
		if ( preg_match( '/iPad|Android(?!.*Mobile)|Tablet/i', $user_agent ) ) {
			return 'tablet';
		}

		if ( preg_match( '/Mobi|iPhone|iPod|Android.*Mobile|BlackBerry|Windows Phone/i', $user_agent ) ) {
			return 'mobile';
		}

		return 'desktop';
	}

	/**
	 * Parse the browser family from a user agent string.
	 *
	 * @param string $user_agent Raw User-Agent header.
	 * @return string
	 */
	public static function browser( string $user_agent ): string {
		$patterns = array(
			'Edg/'      => 'Edge',
			'OPR/'      => 'Opera',
			'Opera/'    => 'Opera',
			'Chrome/'   => 'Chrome',
			'CriOS/'    => 'Chrome',
			'Firefox/'  => 'Firefox',
			'FxiOS/'    => 'Firefox',
			'Version/.*Safari' => 'Safari',
			'MSIE '     => 'Internet Explorer',
			'Trident/'  => 'Internet Explorer',
		);

		// "~" delimiter, not "/": most of the patterns above end in a literal slash (e.g. "Chrome/"),
		// which with a "/" delimiter would close the pattern early and leave the rest to be parsed as
		// modifiers ("/Chrome//i" -> Unknown modifier '/'), making every one of those checks fail.
		foreach ( $patterns as $pattern => $name ) {
			if ( preg_match( '~' . $pattern . '~i', $user_agent ) ) {
				return $name;
			}
		}

		return 'Other';
	}

	/**
	 * Parse the operating system family from a user agent string.
	 *
	 * @param string $user_agent Raw User-Agent header.
	 * @return string
	 */
	public static function operating_system( string $user_agent ): string {
		// Order matters — first match wins, so the more specific pattern has to come first:
		// an iOS user agent reads "(iPhone; CPU iPhone OS 17_0 like Mac OS X)", so checking
		// "Mac OS X" before "iPhone" would report every iPhone/iPad visit as macOS. Likewise
		// Android user agents also contain "Linux".
		$patterns = array(
			'Windows NT 10'    => 'Windows 10/11',
			'Windows NT'       => 'Windows',
			'iPhone|iPad|iPod' => 'iOS',
			'Android'          => 'Android',
			'CrOS'             => 'ChromeOS',
			'Mac OS X'         => 'macOS',
			'Linux'            => 'Linux',
		);

		// Same "~" delimiter as browser() above, so a future pattern containing a slash can't
		// silently break this loop the same way.
		foreach ( $patterns as $pattern => $name ) {
			if ( preg_match( '~' . $pattern . '~i', $user_agent ) ) {
				return $name;
			}
		}

		return 'Other';
	}
}
