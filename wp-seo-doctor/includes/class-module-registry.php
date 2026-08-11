<?php
/**
 * In-memory registry backing the seodoc_register_* extension points.
 *
 * @package SEODoc
 */

namespace SEODoc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Module_Registry {

	private static $checks = array();

	private static $scanner_stages = array();

	private static $admin_pages = array();

	public static function add_check( $id, $class ) {
		self::$checks[ $id ] = $class;
	}

	/**
	 * @return array<string, string> check id => class name.
	 */
	public static function get_checks() {
		return self::$checks;
	}

	public static function add_scanner_stage( $id, $handler ) {
		self::$scanner_stages[ $id ] = $handler;
	}

	/**
	 * @return array<string, callable> stage id => handler.
	 */
	public static function get_scanner_stages() {
		return self::$scanner_stages;
	}

	public static function add_admin_page( array $config ) {
		self::$admin_pages[] = $config;
	}

	/**
	 * @return array<int, array> registered admin page configs.
	 */
	public static function get_admin_pages() {
		return self::$admin_pages;
	}
}
