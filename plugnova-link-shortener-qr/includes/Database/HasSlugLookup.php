<?php
/**
 * Contract for any repository whose rows are addressed by a unique, URL-safe slug and that
 * Helpers\SlugGenerator can therefore generate/validate unique slugs against.
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface HasSlugLookup
 */
interface HasSlugLookup {

	/**
	 * Check whether a slug is already in use.
	 *
	 * @param string   $slug       Slug to check.
	 * @param int|null $exclude_id Optionally exclude a row ID (used when editing).
	 * @return bool
	 */
	public function slug_exists( string $slug, ?int $exclude_id = null ): bool;
}
