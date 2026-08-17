<?php
/**
 * Base class for audit modules.
 *
 * @package WP_Site_Toolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Contract every audit module implements.
 *
 * A module contributes tasks to the scan queue. Each task processes one batch
 * at a time and accumulates raw findings into the module state. Once every task
 * is finished, `finalize()` turns that state into check results.
 *
 * @since 1.0.0
 */
abstract class WPSTK_Module {

	/**
	 * Returns the module identifier, for example `seo`.
	 *
	 * @return string
	 */
	abstract public function get_id();

	/**
	 * Returns the translated module name.
	 *
	 * @return string
	 */
	abstract public function get_label();

	/**
	 * Returns a one line description of what the module looks at.
	 *
	 * @return string
	 */
	abstract public function get_description();

	/**
	 * Returns the dashicon used for the module.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'dashicons-yes-alt';
	}

	/**
	 * Returns the tasks that make up this module's part of a scan.
	 *
	 * @param array $settings Plugin settings.
	 *
	 * @return array[] List of arrays with `id` and `label` keys.
	 */
	abstract public function get_tasks( $settings );

	/**
	 * Processes one batch of a task.
	 *
	 * @param string $task_id  Task identifier.
	 * @param int    $offset   Current offset within the task.
	 * @param array  $state    Module state accumulated so far.
	 * @param array  $settings Plugin settings.
	 *
	 * @return array {
	 *     @type array    $state       Updated module state.
	 *     @type int|null $next_offset Offset for the next batch, or null when finished.
	 *     @type int      $processed   Items handled in this batch.
	 *     @type int      $total       Total items the task will handle.
	 * }
	 */
	abstract public function run_task( $task_id, $offset, $state, $settings );

	/**
	 * Turns the accumulated state into check results.
	 *
	 * @param array $state     Module state.
	 * @param array $settings  Plugin settings.
	 * @param array $all_state State of every module, keyed by module ID.
	 *
	 * @return array[] Check definitions understood by WPSTK_Check::make().
	 */
	abstract public function finalize( $state, $settings, $all_state );

	/**
	 * Helper that builds the return value of run_task().
	 *
	 * @param array    $state       Updated state.
	 * @param int|null $next_offset Next offset or null.
	 * @param int      $processed   Items processed in this batch.
	 * @param int      $total       Total items.
	 *
	 * @return array
	 */
	protected function task_result( $state, $next_offset = null, $processed = 0, $total = 0 ) {
		return array(
			'state'       => $state,
			'next_offset' => $next_offset,
			'processed'   => (int) $processed,
			'total'       => (int) $total,
		);
	}

	/**
	 * Returns a value from the module state with a fallback.
	 *
	 * @param array  $state   Module state.
	 * @param string $key     Key.
	 * @param mixed  $default Fallback.
	 *
	 * @return mixed
	 */
	protected function state_get( $state, $key, $default = null ) {
		return isset( $state[ $key ] ) ? $state[ $key ] : $default;
	}

	/**
	 * Appends an item to a bounded list inside the module state.
	 *
	 * Keeping sample lists small is what stops a scan on a large site from
	 * exhausting memory or bloating the options table.
	 *
	 * @param array  $list  Existing list.
	 * @param array  $item  Item to append.
	 * @param int    $limit Maximum number of stored items.
	 *
	 * @return array
	 */
	protected function push_sample( $list, $item, $limit = 25 ) {
		$list = is_array( $list ) ? $list : array();

		if ( count( $list ) < $limit ) {
			$list[] = $item;
		}

		return $list;
	}

	/**
	 * Builds a check result for this module.
	 *
	 * @param array $args Check definition.
	 *
	 * @return array
	 */
	protected function check( $args ) {
		$args['module'] = $this->get_id();

		return $args;
	}

	/**
	 * Builds a "passed" check.
	 *
	 * @param string $id      Check ID.
	 * @param string $label   Check name.
	 * @param string $summary What was found.
	 * @param string $why     Why it matters.
	 *
	 * @return array
	 */
	protected function passed( $id, $label, $summary, $why = '' ) {
		return $this->check(
			array(
				'id'      => $id,
				'status'  => 'passed',
				'label'   => $label,
				'summary' => $summary,
				'why'     => $why,
			)
		);
	}

	/**
	 * Builds a "skipped" check.
	 *
	 * @param string $id     Check ID.
	 * @param string $label  Check name.
	 * @param string $reason Why the check did not run.
	 *
	 * @return array
	 */
	protected function skipped( $id, $label, $reason ) {
		return $this->check(
			array(
				'id'      => $id,
				'status'  => 'skipped',
				'label'   => $label,
				'summary' => $reason,
			)
		);
	}
}
