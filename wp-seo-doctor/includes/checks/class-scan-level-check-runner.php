<?php
/**
 * Runs every registered Scan_Level_Check once a scan finishes.
 *
 * @package SEODoc
 */

namespace SEODoc\Checks;

use SEODoc\Module_Registry;
use SEODoc\Issues\Issue_Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Scan_Level_Check_Runner {

	public function __construct() {
		add_action( 'seodoc_scan_completed', array( $this, 'run' ) );
	}

	public function run( $scan_id ) {
		foreach ( Module_Registry::get_scan_level_checks() as $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}

			$instance = new $class();
			if ( ! $instance instanceof Scan_Level_Check ) {
				continue;
			}

			Issue_Engine::record_for_check( $scan_id, $instance->get_id(), $instance->run() );
		}
	}
}
