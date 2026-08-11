<?php
/**
 * Instantiates every Check registered via seodoc_register_check() and
 * runs them all against a Scan_Context.
 *
 * @package SEODoc
 */

namespace SEODoc\Checks;

use SEODoc\Module_Registry;
use SEODoc\Issues\Issue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Check_Registry {

	/** @var Check[] */
	private $checks = array();

	public function __construct() {
		// Runs after seodoc_register_modules has fired (see Plugin::boot()),
		// so every seodoc_register_check() call has already landed in
		// Module_Registry by the time this reads it.
		add_action( 'seodoc_loaded', array( $this, 'load_registered_checks' ) );
	}

	public function load_registered_checks() {
		foreach ( Module_Registry::get_checks() as $id => $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}

			$instance = new $class();

			if ( $instance instanceof Check ) {
				$this->checks[ $id ] = $instance;
			}
		}
	}

	/**
	 * @return Check[]
	 */
	public function get_checks() {
		return $this->checks;
	}

	/**
	 * @param Scan_Context $context
	 * @return Issue[]
	 */
	public function run_all( Scan_Context $context ) {
		$issues = array();

		foreach ( $this->checks as $check ) {
			if ( ! $check->applies_to( $context ) ) {
				continue;
			}

			foreach ( $check->run( $context ) as $issue ) {
				$issues[] = $issue;
			}
		}

		return $issues;
	}
}
