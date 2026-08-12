<?php
/**
 * The one auditable place Pro plugs into Free's extension points —
 * mirrors Free's own Default_Checks pattern (Step 6): every
 * seodoc_register_*() call and every filter Pro adds to Free's registries
 * lives here, not scattered across individual feature classes.
 *
 * @package SEODocPro
 */

namespace SEODocPro;

use SEODocPro\Checks\Content_Decay_Check;
use SEODocPro\Checks\Seo_Opportunity_Check;
use SEODocPro\Rest_Api\Gsc_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pro_Registrar {

	public function __construct() {
		add_filter( 'seodoc_rest_controllers', array( $this, 'add_rest_controllers' ) );
		add_action( 'seodoc_pro_loaded', array( $this, 'register_checks' ) );
	}

	/**
	 * @param string[] $controllers
	 * @return string[]
	 */
	public function add_rest_controllers( array $controllers ) {
		$controllers[] = Gsc_Controller::class;

		return $controllers;
	}

	/**
	 * Scan_Level_Check registration is safe at seodoc_pro_loaded (unlike
	 * Free's per-object Check_Registry, fixed for this exact ordering
	 * issue earlier in Step 12 — Scan_Level_Check_Runner already reads
	 * Module_Registry fresh on every seodoc_scan_completed, long after
	 * both plugins have booted, so there's no snapshot-timing hazard here).
	 */
	public function register_checks() {
		seodoc_register_scan_level_check( 'seo-opportunity', Seo_Opportunity_Check::class );
		seodoc_register_scan_level_check( 'content-decay', Content_Decay_Check::class );
	}
}
