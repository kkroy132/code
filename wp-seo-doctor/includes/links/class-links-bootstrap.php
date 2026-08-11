<?php
/**
 * Registers the Internal Linking Engine's scanner-stage hook and
 * post-scan suggestion generation. Orphan_Page_Check registration stays
 * in Default_Checks (the one auditable list of every Check/Scan_Level_Check)
 * rather than here — this file is only for things that aren't Checks.
 *
 * @package SEODoc
 */

namespace SEODoc\Links;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Links_Bootstrap {

	public function __construct() {
		add_action( 'seodoc_register_modules', array( $this, 'register_scanner_stage' ) );

		// After Health_Score_Recorder (priority 20): suggestion generation
		// reads the link graph that this scan's own Link_Graph stage just
		// finished writing, so it should run once the scan is otherwise
		// fully settled, not race any other seodoc_scan_completed listener.
		add_action( 'seodoc_scan_completed', array( Suggestion_Engine::class, 'generate' ), 30 );
	}

	public function register_scanner_stage() {
		seodoc_register_scanner_stage( 'internal-link-graph', array( Link_Graph::class, 'record_from_context' ) );
		seodoc_register_scanner_stage( 'external-link-catalog', array( External_Link_Collector::class, 'record_from_context' ) );
	}
}
