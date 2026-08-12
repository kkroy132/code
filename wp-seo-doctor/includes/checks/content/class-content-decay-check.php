<?php
/**
 * Turns Content_Decay's findings into Issues, same pattern as
 * Seo_Opportunity_Check.
 *
 * @package SEODoc
 */

namespace SEODoc\Checks\Content;

use SEODoc\Checks\Scan_Level_Check;
use SEODoc\Gsc\Oauth;
use SEODoc\Gsc\Content_Decay;
use SEODoc\Licensing\License_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Content_Decay_Check extends Scan_Level_Check {

	public function get_id() {
		return 'content-decay';
	}

	public function get_category() {
		return 'content';
	}

	public function run() {
		if ( ! License_Manager::is_valid_license() || ! Oauth::is_connected() ) {
			return array();
		}

		$issues = array();

		foreach ( Content_Decay::find() as $decay ) {
			$issues[] = $this->issue(
				self::SEVERITY_MEDIUM,
				sprintf(
					/* translators: 1: percent decline, 2: prior clicks, 3: recent clicks. */
					__( 'Traffic to this page has decreased %1$s%% over the last 90 days (from %2$s to %3$s clicks).', 'wp-seo-doctor' ),
					abs( $decay['change_percent'] ),
					number_format_i18n( $decay['prior_clicks'] ),
					number_format_i18n( $decay['recent_clicks'] )
				),
				$decay['page'],
				'url',
				null,
				$decay
			);
		}

		return $issues;
	}
}
