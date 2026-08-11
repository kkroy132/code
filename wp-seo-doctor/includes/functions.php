<?php
/**
 * Public procedural API: the plugin accessor and the seodoc_register_*
 * extension points Pro/third-party code use to plug into Free (Step 1 §3).
 *
 * @package SEODoc
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @return \SEODoc\Plugin
 */
function seodoc() {
	return \SEODoc\Plugin::instance();
}

/**
 * Register a Check class against the audit engine.
 *
 * @param string $id    Unique check id, e.g. 'missing-meta-desc'.
 * @param string $class Fully-qualified class name extending SEODoc\Checks\Check.
 */
function seodoc_register_check( $id, $class ) {
	\SEODoc\Module_Registry::add_check( $id, $class );
}

/**
 * Register a Scan_Level_Check: a site-wide or cross-object check (HTTPS,
 * robots.txt, sitemap, duplicate title/meta-description) that runs once
 * per completed scan rather than once per object.
 *
 * @param string $id    Unique check id, e.g. 'site-not-https'.
 * @param string $class Fully-qualified class name extending SEODoc\Checks\Scan_Level_Check.
 */
function seodoc_register_scan_level_check( $id, $class ) {
	\SEODoc\Module_Registry::add_scan_level_check( $id, $class );
}

/**
 * Register an extra stage in the batch scanner pipeline.
 *
 * @param string   $id      Unique stage id.
 * @param callable $handler Receives the current SEODoc\Checks\Scan_Context.
 */
function seodoc_register_scanner_stage( $id, $handler ) {
	\SEODoc\Module_Registry::add_scanner_stage( $id, $handler );
}

/**
 * Register an admin submenu page/tab without editing Core's menu file.
 *
 * @param array $config {
 *     @type string $slug       Submenu slug.
 *     @type string $title      Menu title.
 *     @type callable $render   Screen render callback.
 *     @type string $capability Optional. Defaults to SEODoc\Capabilities::required_capability().
 * }
 */
function seodoc_register_admin_page( array $config ) {
	\SEODoc\Module_Registry::add_admin_page( $config );
}

/**
 * @return bool Whether the Pro add-on plugin is active. Free only ever
 *              uses this to decide whether to show an upsell nudge —
 *              never to gate functionality, since Free has none of Pro's
 *              code loaded to gate.
 */
function seodoc_is_pro_active() {
	return (bool) apply_filters( 'seodoc_is_pro_active', defined( 'SEODOC_PRO_VERSION' ) );
}
