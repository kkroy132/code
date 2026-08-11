<?php
/**
 * Thin wrapper turning Orphan_Detector's findings into Issues, so orphan
 * pages show up in the audit/Fix First flow the same way every other
 * problem does. The actual detection logic lives in
 * SEODoc\Links\Orphan_Detector so the Links dashboard screen (Step 9+)
 * can call it directly without going through the issues table.
 *
 * @package SEODoc
 */

namespace SEODoc\Checks\Links;

use SEODoc\Checks\Scan_Level_Check;
use SEODoc\Links\Orphan_Detector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Orphan_Page_Check extends Scan_Level_Check {

	public function get_id() {
		return 'orphan-page';
	}

	public function get_category() {
		return 'links';
	}

	public function run() {
		$issues = array();

		foreach ( Orphan_Detector::find_orphans() as $orphan ) {
			$issues[] = $this->issue(
				self::SEVERITY_HIGH,
				__( 'This page has no internal links pointing to it.', 'wp-seo-doctor' ),
				$orphan['url'],
				'post',
				$orphan['object_id']
			);
		}

		return $issues;
	}
}
