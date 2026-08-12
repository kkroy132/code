<?php
/**
 * Extends Free's Scan_Level_Check base class directly — Pro requires
 * Free active, so Free's classes are simply available. Turns
 * Opportunity_Finder's results into Issues so they flow through the
 * same audit/Fix First pipeline as everything else, gated on license
 * validity (not just "Pro is installed") per Step 14's rule.
 *
 * @package SEODoc
 */

namespace SEODoc\Checks\Content;

use SEODoc\Checks\Scan_Level_Check;
use SEODoc\Gsc\Oauth;
use SEODoc\Gsc\Opportunity_Finder;
use SEODoc\Licensing\License_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seo_Opportunity_Check extends Scan_Level_Check {

	public function get_id() {
		return 'seo-opportunity';
	}

	public function get_category() {
		return 'content';
	}

	public function run() {
		if ( ! License_Manager::is_valid_license() || ! Oauth::is_connected() ) {
			return array();
		}

		$issues = array();

		foreach ( Opportunity_Finder::find() as $opportunity ) {
			$issues[] = $this->issue(
				self::SEVERITY_LOW,
				sprintf(
					/* translators: 1: search position, 2: impressions in the last 28 days. */
					__( 'Potential opportunity: this page ranks at position %1$s with %2$s impressions in the last 28 days — close to page one.', 'wp-seo-doctor' ),
					$opportunity['position'],
					number_format_i18n( $opportunity['impressions'] )
				),
				$opportunity['page'],
				'url',
				null,
				$opportunity
			);
		}

		return $issues;
	}
}
