<?php
/**
 * The audit engine.
 *
 * @package WP_Site_Toolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Runs audits as a resumable queue of small batches.
 *
 * A scan is a list of steps built from the registered modules. Each call to
 * `step()` processes one batch and stores the result, so the work can be
 * driven from the browser, from WP-Cron or from WP-CLI without ever holding a
 * request open for a long time.
 *
 * @since 1.0.0
 */
class WPSTK_Audit {

	/**
	 * Option holding the state of the running scan.
	 */
	const STATE_OPTION = 'wpstk_scan_state';

	/**
	 * Number of seconds after which an untouched scan is considered abandoned.
	 */
	const STALE_AFTER = 900;

	/**
	 * Registered modules.
	 *
	 * @var WPSTK_Module[]|null
	 */
	private $modules = null;

	/**
	 * Returns the registered modules, keyed by module ID.
	 *
	 * @return WPSTK_Module[]
	 */
	public function get_modules() {
		if ( null !== $this->modules ) {
			return $this->modules;
		}

		$modules = array(
			'seo'         => new WPSTK_Module_SEO(),
			'links'       => new WPSTK_Module_Links(),
			'images'      => new WPSTK_Module_Images(),
			'performance' => new WPSTK_Module_Performance(),
			'security'    => new WPSTK_Module_Security(),
			'technical'   => new WPSTK_Module_Technical(),
		);

		/**
		 * Filters the audit modules.
		 *
		 * Entries that are not WPSTK_Module instances are discarded.
		 *
		 * @since 1.0.0
		 *
		 * @param WPSTK_Module[] $modules Modules keyed by ID.
		 */
		$filtered = apply_filters( 'wpstk_modules', $modules );

		$valid = array();

		foreach ( (array) $filtered as $id => $module ) {
			if ( $module instanceof WPSTK_Module ) {
				$valid[ (string) $id ] = $module;
			}
		}

		$this->modules = ! empty( $valid ) ? $valid : $modules;

		return $this->modules;
	}

	/**
	 * Returns a single module.
	 *
	 * @param string $id Module ID.
	 *
	 * @return WPSTK_Module|null
	 */
	public function get_module( $id ) {
		$modules = $this->get_modules();

		return isset( $modules[ $id ] ) ? $modules[ $id ] : null;
	}

	/**
	 * Returns the relative weight of each module in the overall score.
	 *
	 * @return array
	 */
	public function get_module_weights() {
		$weights = array(
			'seo'         => 20,
			'links'       => 20,
			'images'      => 15,
			'performance' => 15,
			'security'    => 20,
			'technical'   => 10,
		);

		/**
		 * Filters the weight each module carries in the overall health score.
		 *
		 * @since 1.0.0
		 *
		 * @param array $weights Weights keyed by module ID.
		 */
		return apply_filters( 'wpstk_module_weights', $weights );
	}

	/**
	 * Returns the stored scan state.
	 *
	 * @return array|null
	 */
	public function get_state() {
		$state = get_option( self::STATE_OPTION, null );

		return is_array( $state ) && isset( $state['scan_id'] ) ? $state : null;
	}

	/**
	 * Whether a scan is currently in progress.
	 *
	 * @return bool
	 */
	public function is_running() {
		$state = $this->get_state();

		return $state && 'running' === $state['status'] && ! $this->is_stale( $state );
	}

	/**
	 * Whether the stored state has not been touched for a while.
	 *
	 * @param array|null $state Optional state to inspect.
	 *
	 * @return bool
	 */
	public function is_stale( $state = null ) {
		$state = null === $state ? $this->get_state() : $state;

		if ( ! $state || ! isset( $state['updated'] ) ) {
			return true;
		}

		return ( time() - (int) $state['updated'] ) > self::STALE_AFTER;
	}

	/**
	 * Starts a new scan.
	 *
	 * @param string $trigger How the scan was started.
	 *
	 * @return array|WP_Error Progress payload or an error.
	 */
	public function start( $trigger = 'manual' ) {
		if ( ! WPSTK_Database::tables_exist() ) {
			WPSTK_Database::install();
		}

		$existing = $this->get_state();

		if ( $existing && 'running' === $existing['status'] ) {
			if ( ! $this->is_stale( $existing ) ) {
				return new WP_Error(
					'wpstk_scan_running',
					__( 'An audit is already running. Wait for it to finish or cancel it first.', 'site-toolkit' )
				);
			}

			// The previous scan was abandoned; close it out and start fresh.
			WPSTK_Scan_Store::set_status( $existing['scan_id'], 'failed' );
			delete_option( self::STATE_OPTION );
		}

		$settings = WPSTK_Settings::get_all();
		$queue    = array();

		foreach ( $this->get_modules() as $module_id => $module ) {
			foreach ( $module->get_tasks( $settings ) as $task ) {
				if ( empty( $task['id'] ) ) {
					continue;
				}

				$queue[] = array(
					'module'    => $module_id,
					'task'      => (string) $task['id'],
					'label'     => isset( $task['label'] ) ? (string) $task['label'] : (string) $task['id'],
					'offset'    => 0,
					'processed' => 0,
					'total'     => 0,
					'done'      => false,
				);
			}
		}

		if ( empty( $queue ) ) {
			return new WP_Error( 'wpstk_no_tasks', __( 'No audit tasks are available.', 'site-toolkit' ) );
		}

		$scan_id = WPSTK_Scan_Store::create( $trigger );

		if ( $scan_id <= 0 ) {
			return new WP_Error(
				'wpstk_scan_not_created',
				__( 'The audit could not be started because the scan record could not be saved.', 'site-toolkit' )
			);
		}

		$state = array(
			'scan_id' => $scan_id,
			'status'  => 'running',
			'trigger' => $trigger,
			'queue'   => $queue,
			'index'   => 0,
			'started' => time(),
			'updated' => time(),
			'data'    => array(),
			'errors'  => array(),
		);

		$this->save_state( $state );

		return $this->progress( $state );
	}

	/**
	 * Processes the next batch.
	 *
	 * @return array|WP_Error Progress payload or an error.
	 */
	public function step() {
		$state = $this->get_state();

		if ( ! $state || 'running' !== $state['status'] ) {
			return new WP_Error( 'wpstk_no_scan', __( 'No audit is currently running.', 'site-toolkit' ) );
		}

		$index = (int) $state['index'];

		if ( ! isset( $state['queue'][ $index ] ) ) {
			return $this->finish( $state );
		}

		$item     = $state['queue'][ $index ];
		$module   = $this->get_module( $item['module'] );
		$settings = WPSTK_Settings::get_all();

		if ( ! $module ) {
			$state['queue'][ $index ]['done'] = true;
			++$state['index'];
			$state['updated'] = time();
			$this->save_state( $state );

			return $this->progress( $state );
		}

		$module_state = isset( $state['data'][ $item['module'] ] ) ? $state['data'][ $item['module'] ] : array();

		try {
			$result = $module->run_task( $item['task'], (int) $item['offset'], $module_state, $settings );
		} catch ( Throwable $e ) {
			// A failing task must never break the whole audit.
			$state['errors'][] = array(
				'module'  => $item['module'],
				'task'    => $item['task'],
				'message' => $e->getMessage(),
			);

			$result = array(
				'state'       => $module_state,
				'next_offset' => null,
				'processed'   => 0,
				'total'       => (int) $item['total'],
			);
		}

		$state['data'][ $item['module'] ] = isset( $result['state'] ) && is_array( $result['state'] ) ? $result['state'] : $module_state;

		$state['queue'][ $index ]['processed'] = (int) $item['processed'] + ( isset( $result['processed'] ) ? (int) $result['processed'] : 0 );
		$state['queue'][ $index ]['total']     = isset( $result['total'] ) ? (int) $result['total'] : (int) $item['total'];

		$next_offset = isset( $result['next_offset'] ) ? $result['next_offset'] : null;

		if ( null === $next_offset ) {
			$state['queue'][ $index ]['done'] = true;
			++$state['index'];
		} else {
			$state['queue'][ $index ]['offset'] = (int) $next_offset;
		}

		$state['updated'] = time();
		$this->save_state( $state );

		if ( ! isset( $state['queue'][ (int) $state['index'] ] ) ) {
			return $this->finish( $state );
		}

		return $this->progress( $state );
	}

	/**
	 * Finalises every module and stores the results.
	 *
	 * @param array $state Scan state.
	 *
	 * @return array Progress payload.
	 */
	private function finish( $state ) {
		$settings = WPSTK_Settings::get_all();
		$checks   = array();
		$scores   = array();
		$counts   = array();

		foreach ( $this->get_modules() as $module_id => $module ) {
			$module_state = isset( $state['data'][ $module_id ] ) ? $state['data'][ $module_id ] : array();

			try {
				$module_checks = $module->finalize( $module_state, $settings, $state['data'] );
			} catch ( Throwable $e ) {
				$state['errors'][] = array(
					'module'  => $module_id,
					'task'    => 'finalize',
					'message' => $e->getMessage(),
				);

				$module_checks = array();
			}

			$module_checks = is_array( $module_checks ) ? $module_checks : array();
			$normalised    = array();

			foreach ( $module_checks as $check ) {
				$check           = is_array( $check ) ? $check : array();
				$check['module'] = $module_id;
				$normalised[]    = WPSTK_Check::make( $check );
			}

			$scores[ $module_id ] = WPSTK_Check::score( $normalised );
			$counts[ $module_id ] = WPSTK_Check::count_by_status( $normalised );
			$checks               = array_merge( $checks, $normalised );
		}

		$scores['overall'] = $this->overall_score( $scores );

		$summary = array(
			'counts'    => $counts,
			'errors'    => array_slice( (array) $state['errors'], 0, 20 ),
			'duration'  => max( 0, time() - (int) $state['started'] ),
			'trigger'   => (string) $state['trigger'],
			'site_url'  => home_url( '/' ),
			'wp'        => get_bloginfo( 'version' ),
			'php'       => PHP_VERSION,
			'processed' => $this->processed_totals( $state ),
		);

		WPSTK_Scan_Store::complete( $state['scan_id'], $checks, $scores, $summary );

		delete_option( self::STATE_OPTION );

		WPSTK_Scan_Store::prune();

		return array(
			'running'     => false,
			'done'        => true,
			'scan_id'     => (int) $state['scan_id'],
			'percent'     => 100.0,
			'step'        => count( $state['queue'] ),
			'total_steps' => count( $state['queue'] ),
			'label'       => __( 'Audit complete', 'site-toolkit' ),
			'module'      => '',
		);
	}

	/**
	 * Calculates the weighted overall score.
	 *
	 * @param array $scores Module scores.
	 *
	 * @return int|null
	 */
	private function overall_score( $scores ) {
		$weights = $this->get_module_weights();
		$total   = 0;
		$earned  = 0;

		foreach ( $scores as $module_id => $score ) {
			if ( 'overall' === $module_id || null === $score ) {
				continue;
			}

			$weight = isset( $weights[ $module_id ] ) ? (float) $weights[ $module_id ] : 10.0;

			$total  += $weight;
			$earned += $weight * (int) $score;
		}

		if ( $total <= 0 ) {
			return null;
		}

		return (int) max( 0, min( 100, round( $earned / $total ) ) );
	}

	/**
	 * Sums how much content each module looked at.
	 *
	 * @param array $state Scan state.
	 *
	 * @return array
	 */
	private function processed_totals( $state ) {
		$totals = array();

		foreach ( (array) $state['queue'] as $item ) {
			$key            = $item['module'] . '.' . $item['task'];
			$totals[ $key ] = (int) $item['processed'];
		}

		return $totals;
	}

	/**
	 * Cancels the running scan.
	 *
	 * @return bool Whether a scan was cancelled.
	 */
	public function cancel() {
		$state = $this->get_state();

		if ( ! $state ) {
			return false;
		}

		WPSTK_Scan_Store::set_status( $state['scan_id'], 'cancelled' );
		delete_option( self::STATE_OPTION );

		return true;
	}

	/**
	 * Runs steps until the scan finishes or the time budget is used up.
	 *
	 * @param int $budget_seconds Maximum wall clock time to spend.
	 *
	 * @return array|WP_Error Last progress payload.
	 */
	public function run_until( $budget_seconds = 20 ) {
		$deadline = time() + max( 5, (int) $budget_seconds );
		$progress = null;

		do {
			$progress = $this->step();

			if ( is_wp_error( $progress ) ) {
				return $progress;
			}

			if ( ! empty( $progress['done'] ) ) {
				return $progress;
			}
		} while ( time() < $deadline );

		return $progress;
	}

	/**
	 * Builds the progress payload for the current state.
	 *
	 * @param array $state Scan state.
	 *
	 * @return array
	 */
	public function progress( $state ) {
		$queue       = (array) $state['queue'];
		$total_steps = count( $queue );
		$index       = min( (int) $state['index'], $total_steps );
		$completed   = 0;

		foreach ( $queue as $position => $item ) {
			if ( ! empty( $item['done'] ) ) {
				++$completed;

				continue;
			}

			if ( $position === $index && (int) $item['total'] > 0 ) {
				$completed += min( 1, (int) $item['processed'] / max( 1, (int) $item['total'] ) );
			}
		}

		$current = isset( $queue[ $index ] ) ? $queue[ $index ] : end( $queue );
		$current = is_array( $current ) ? $current : array(
			'module' => '',
			'label'  => __( 'Finishing up', 'site-toolkit' ),
		);
		$module  = $this->get_module( $current['module'] );

		return array(
			'running'     => true,
			'done'        => false,
			'scan_id'     => (int) $state['scan_id'],
			'percent'     => $total_steps > 0 ? round( ( $completed / $total_steps ) * 100, 1 ) : 0.0,
			'step'        => $index + 1,
			'total_steps' => $total_steps,
			'label'       => (string) $current['label'],
			'module'      => $module ? $module->get_label() : '',
		);
	}

	/**
	 * Returns the progress payload for the running scan, if any.
	 *
	 * @return array
	 */
	public function status() {
		$state = $this->get_state();

		if ( ! $state || 'running' !== $state['status'] ) {
			return array(
				'running' => false,
				'done'    => false,
				'percent' => 0.0,
			);
		}

		return $this->progress( $state );
	}

	/**
	 * Persists the scan state.
	 *
	 * @param array $state Scan state.
	 *
	 * @return void
	 */
	private function save_state( $state ) {
		update_option( self::STATE_OPTION, $state, false );
	}
}
