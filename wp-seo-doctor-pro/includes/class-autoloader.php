<?php
/**
 * Same convention as Free's autoloader (wp-seo-doctor/includes/class-autoloader.php):
 * SEODocPro\Sub\Class_Name → includes/sub/class-class-name.php. Kept as
 * its own copy rather than a shared dependency — Free and Pro are
 * separate plugins that can be active independently of each other's
 * internals (Step 1 §1), so neither should require() a file living
 * inside the other's folder.
 *
 * @package SEODocPro
 */

namespace SEODocPro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Autoloader {

	public static function register() {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	public static function autoload( $class ) {
		$prefix = __NAMESPACE__ . '\\';

		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative   = substr( $class, strlen( $prefix ) );
		$parts      = explode( '\\', $relative );
		$class_name = array_pop( $parts );

		$path = SEODOC_PRO_PLUGIN_DIR . 'includes/';
		foreach ( $parts as $part ) {
			$path .= self::normalize( $part ) . '/';
		}
		$path .= 'class-' . self::normalize( $class_name ) . '.php';

		if ( file_exists( $path ) ) {
			require $path;
		}
	}

	private static function normalize( $segment ) {
		return strtolower( str_replace( '_', '-', $segment ) );
	}
}
