<?php
/**
 * Registers every built-in Free Check and Scan_Level_Check. This is the
 * one place that has to change when a new check is added — individual
 * check classes never register themselves, keeping the list auditable
 * in one file rather than scattered add_action calls.
 *
 * @package SEODoc
 */

namespace SEODoc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Default_Checks {

	public function __construct() {
		add_action( 'seodoc_register_modules', array( $this, 'register' ) );
	}

	public function register() {
		foreach ( $this->get_checks() as $id => $class ) {
			seodoc_register_check( $id, $class );
		}

		foreach ( $this->get_scan_level_checks() as $id => $class ) {
			seodoc_register_scan_level_check( $id, $class );
		}
	}

	/**
	 * @return array<string, string>
	 */
	private function get_checks() {
		return array(
			// On-page.
			'missing-seo-title'       => Checks\On_Page\Missing_Title_Check::class,
			'seo-title-length'        => Checks\On_Page\Title_Length_Check::class,
			'missing-meta-description' => Checks\On_Page\Missing_Meta_Description_Check::class,
			'meta-description-length' => Checks\On_Page\Meta_Description_Length_Check::class,
			'missing-h1'              => Checks\On_Page\Missing_H1_Check::class,
			'multiple-h1'             => Checks\On_Page\Multiple_H1_Check::class,
			'heading-hierarchy-skip'  => Checks\On_Page\Heading_Hierarchy_Check::class,
			'missing-image-alt'       => Checks\On_Page\Missing_Image_Alt_Check::class,
			'thin-content'            => Checks\On_Page\Thin_Content_Check::class,

			// Technical.
			'canonical-issue'         => Checks\Technical\Canonical_Check::class,
			'noindex-page'            => Checks\Technical\Noindex_Check::class,
			'nofollow-page'           => Checks\Technical\Nofollow_Robots_Check::class,
			'missing-schema'          => Checks\Technical\Missing_Schema_Check::class,
			'pagination-missing'      => Checks\Technical\Pagination_Check::class,
			'mixed-content'           => Checks\Technical\Mixed_Content_Check::class,

			// Links (page-level link hygiene — see docs/06-seo-checks.md
			// for why broken-link/orphan/404 detection lives in Step 9/10
			// instead of here).
			'empty-link-text'         => Checks\Links\Empty_Anchor_Text_Check::class,
			'placeholder-link'        => Checks\Links\Placeholder_Link_Check::class,
			'excessive-external-links' => Checks\Links\Excessive_External_Links_Check::class,
			'missing-rel-noopener'    => Checks\Links\Missing_Rel_Noopener_Check::class,
			'nofollow-internal-link'  => Checks\Links\Nofollow_Internal_Link_Check::class,
			'insecure-internal-link'  => Checks\Links\Insecure_Internal_Link_Check::class,
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function get_scan_level_checks() {
		return array(
			'duplicate-seo-title'       => Checks\On_Page\Duplicate_Title_Detector::class,
			'duplicate-meta-description' => Checks\On_Page\Duplicate_Meta_Description_Detector::class,
			'site-not-https'            => Checks\Technical\Https_Site_Check::class,
			'robots-txt-issue'          => Checks\Technical\Robots_Txt_Check::class,
			'sitemap-unavailable'       => Checks\Technical\Sitemap_Availability_Check::class,

			// Reads Link_Graph's edges, built by the internal-link-graph
			// scanner stage (Links_Bootstrap) during the same scan.
			'orphan-page'               => Checks\Links\Orphan_Page_Check::class,
		);
	}
}
