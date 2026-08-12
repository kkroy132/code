<?php
/**
 * Hooks Free's existing extension-point filters to unlock Pro behavior —
 * proof that the seodoc_register_* / filter architecture built across
 * Steps 4-11 actually works end to end, not just scaffolding. Gated on
 * License_Manager::is_valid_license(), not merely "is this plugin
 * active": an installed-but-unlicensed Pro copy must behave exactly like
 * Free, never a silent unlock.
 *
 * @package SEODoc
 */

namespace SEODoc;

use SEODoc\Licensing\License_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Feature_Gates {

	public function __construct() {
		add_filter( 'seodoc_is_pro_active', array( $this, 'is_pro_active' ) );
		add_filter( 'seodoc_suggestion_monthly_quota', array( $this, 'suggestion_quota' ) );
		add_filter( 'seodoc_allowed_redirect_types', array( $this, 'redirect_types' ) );
	}

	public function is_pro_active( $active ) {
		return License_Manager::is_valid_license();
	}

	/**
	 * Free's 25/month cap (SEODoc\Links\Suggestion_Engine) becomes
	 * unlimited — Free's code doesn't change at all, per Step 9's design.
	 */
	public function suggestion_quota( $default ) {
		return License_Manager::is_valid_license() ? PHP_INT_MAX : $default;
	}

	/**
	 * Free ships [301, 302] (SEODoc\Redirects\Redirect_Manager). Licensed
	 * Pro adds 307/308/410 on top, never removing what Free already
	 * allows even if licensing lapses mid-request.
	 */
	public function redirect_types( $types ) {
		if ( ! License_Manager::is_valid_license() ) {
			return $types;
		}

		return array_values( array_unique( array_merge( $types, array( 307, 308, 410 ) ) ) );
	}
}
