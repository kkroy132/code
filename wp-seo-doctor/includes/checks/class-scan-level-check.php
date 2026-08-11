<?php
/**
 * Base class for checks that can't run per-object: site-wide singleton
 * checks (HTTPS, robots.txt, sitemap) and cross-object detectors
 * (duplicate title/meta-description). Runs once per completed scan via
 * Scan_Level_Check_Runner, not once per queue row.
 *
 * @package SEODoc
 */

namespace SEODoc\Checks;

use SEODoc\Issues\Issue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Scan_Level_Check {

	const SEVERITY_CRITICAL = 'critical';
	const SEVERITY_HIGH     = 'high';
	const SEVERITY_MEDIUM   = 'medium';
	const SEVERITY_LOW      = 'low';

	/**
	 * @return string Unique id, e.g. 'site-not-https'.
	 */
	abstract public function get_id();

	/**
	 * @return string 'on-page' | 'technical' | 'links'.
	 */
	abstract public function get_category();

	/**
	 * @return Issue[] Empty array when nothing is wrong.
	 */
	abstract public function run();

	/**
	 * @param string      $severity
	 * @param string      $title
	 * @param string      $url
	 * @param string      $object_type
	 * @param int|null    $object_id
	 * @param array       $details
	 * @return Issue
	 */
	protected function issue( $severity, $title, $url = '', $object_type = 'site', $object_id = null, array $details = array() ) {
		return new Issue(
			array(
				'check_id'    => $this->get_id(),
				'category'    => $this->get_category(),
				'severity'    => $severity,
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'url'         => $url,
				'title'       => $title,
				'details'     => $details,
			)
		);
	}
}
