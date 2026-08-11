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

	/** @var Check[]|null Null until first use — see ensure_loaded(). */
	private $checks = null;

	/**
	 * Loads lazily on first actual use rather than eagerly on a fixed
	 * hook. This was originally hooked to seodoc_loaded, fired once and
	 * assumed every registrant had already called seodoc_register_check()
	 * by then — true for Free's own checks, but wrong the moment a
	 * second plugin registers later in the same request. Pro boots at
	 * plugins_loaded priority 20, strictly after Free's priority-10
	 * seodoc_loaded already fired (Step 14), so an eager one-time load
	 * here would have silently dropped every Pro check. Lazy loading
	 * decouples "when checks are registered" from "when they're consumed"
	 * entirely, which is correct regardless of load order — including for
	 * any future third-party integration that registers later still.
	 */
	private function ensure_loaded() {
		if ( null !== $this->checks ) {
			return;
		}

		$this->checks = array();

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
		$this->ensure_loaded();

		return $this->checks;
	}

	/**
	 * @param Scan_Context $context
	 * @return Issue[]
	 */
	public function run_all( Scan_Context $context ) {
		$this->ensure_loaded();

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
