<?php
/**
 * PSR-4 autoloader for the LWBLC namespace.
 *
 * Maps LWBLC\Sub\Thing to src/Sub/Thing.php.
 *
 * @package LWBLC
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	function ( $class_name ) {
		$prefix = 'LWBLC\\';
		$length = strlen( $prefix );

		if ( 0 !== strncmp( $prefix, $class_name, $length ) ) {
			return;
		}

		$relative = substr( $class_name, $length );

		// Only plain class paths are allowed, no traversal.
		if ( ! preg_match( '/^[A-Za-z0-9_\\\\]+$/', $relative ) ) {
			return;
		}

		$file = LWBLC_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);
