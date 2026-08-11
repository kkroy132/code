<?php
/**
 * Consumes queue rows in small batches via Action Scheduler, one WP-Cron
 * tick's worth of work at a time — never a whole scan in one request.
 *
 * @package SEODoc
 */

namespace SEODoc\Scanner;

use SEODoc\Checks\Check_Registry;
use SEODoc\Checks\Scan_Context;
use SEODoc\Issues\Issue_Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Batch_Processor {

	const HOOK = 'seodoc_process_scan_batch';

	const GROUP = 'seodoc';

	/**
	 * Filterable so a site that self-tests as slow/shared-hosting can be
	 * turned down without a code change.
	 */
	const DEFAULT_BATCH_SIZE = 20;

	public function __construct() {
		add_action( self::HOOK, array( $this, 'process_batch' ) );
	}

	public static function schedule_next( $scan_id ) {
		if ( ! Action_Scheduler_Init::is_available() ) {
			return;
		}

		if ( false === as_next_scheduled_action( self::HOOK, array( $scan_id ), self::GROUP ) ) {
			as_schedule_single_action( time(), self::HOOK, array( $scan_id ), self::GROUP );
		}
	}

	public function process_batch( $scan_id ) {
		$scan = Scan_Controller::get_scan( $scan_id );

		if ( ! $scan || 'running' !== $scan->status ) {
			// Paused or cancelled: stop scheduling more batches. Resuming
			// a paused scan re-schedules from Scan_Controller::resume().
			return;
		}

		$batch_size = (int) apply_filters( 'seodoc_scan_batch_size', self::DEFAULT_BATCH_SIZE );
		$rows       = Queue::claim_batch( $scan_id, $batch_size );

		if ( empty( $rows ) ) {
			Scan_Controller::complete( $scan_id );
			return;
		}

		$registry = seodoc()->get_module( Check_Registry::class );

		foreach ( $rows as $row ) {
			try {
				$context = Scan_Context::for_object( $row->object_type, (int) $row->object_id );
				$issues  = $context && $registry ? $registry->run_all( $context ) : array();

				Issue_Engine::record( $scan_id, $row->object_type, (int) $row->object_id, $issues );

				Queue::mark_done( $row->id );
			} catch ( \Throwable $e ) {
				// One bad row must not take down the whole scan.
				Queue::mark_error( $row->id );
			}

			Scan_Controller::increment_processed( $scan_id );
		}

		self::schedule_next( $scan_id );
	}
}
