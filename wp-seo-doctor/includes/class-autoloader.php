<?php
/**
 * Minimal PSR-4-style autoloader, no Composer dependency at runtime.
 *
 * @package SEODoc
 */

namespace SEODoc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps SEODoc\Sub\Namespace\Class_Name to
 * includes/sub/namespace/class-class-name.php (WordPress file-naming
 * convention), so class-schema.php and its namespace stay in sync by
 * construction rather than by a maintained list.
 */
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

		$path = SEODOC_PLUGIN_DIR . 'includes/';
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
