<?php
/**
 * Cache-busting version strings for the plugin's own CSS/JS files.
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AssetVersion
 *
 * wp_enqueue_style()/wp_enqueue_script() append their $ver argument to the asset URL, and browsers
 * (plus any caching plugin or CDN in front of the site) treat that URL as the cache key. Passing
 * QLQR_VERSION alone means every build carrying the same plugin version resolves to the same URL —
 * so a site that already has assets/css/admin.css?ver=1.0.0 cached keeps serving the old file after
 * an update that did not bump the version number, and the admin screen renders new markup against
 * stale styles.
 *
 * Appending the file's own modification time makes the URL change exactly when the file changes,
 * which is both narrower and more reliable than relying on the plugin version being bumped.
 */
final class AssetVersion {

	/**
	 * Version string for one bundled asset: the plugin version, suffixed with the file's mtime.
	 *
	 * @param string $relative_path Path relative to the plugin directory, e.g. "assets/css/admin.css".
	 * @return string
	 */
	public static function for_file( string $relative_path ): string {
		$file = QLQR_PLUGIN_DIR . ltrim( $relative_path, '/' );

		// filemtime() emits a warning and returns false for a missing file; is_readable() first keeps
		// a missing asset from turning into a PHP notice on every admin page load. Falling back to the
		// plain plugin version is correct here — a file we cannot stat is one we cannot fingerprint.
		if ( ! is_readable( $file ) ) {
			return QLQR_VERSION;
		}

		$mtime = filemtime( $file );

		return false === $mtime ? QLQR_VERSION : QLQR_VERSION . '.' . $mtime;
	}

	/**
	 * Human-readable build identifier: the modification time of the most recently changed file in
	 * the plugin.
	 *
	 * Two builds can carry the same version number while containing different code — during
	 * development that is the norm — so the version string alone cannot answer "is the copy running
	 * on this site the one I just uploaded?". That question comes up whenever an update appears not
	 * to have taken effect, and the footer needs something that actually moves between builds.
	 *
	 * The whole plugin is scanned rather than one representative file: keying the stamp off
	 * assets/js/admin.js alone meant a release that changed only PHP left the stamp untouched, so
	 * the stamp reported "same build" for a build that was genuinely different — worse than having
	 * no stamp, because it invites the wrong conclusion. Roughly a hundred filemtime() calls, on
	 * this plugin's own admin screens only, is a fair price for an answer that is actually true.
	 *
	 * @return string Formatted UTC timestamp, or an empty string if nothing could be read.
	 */
	public static function build_stamp(): string {
		$newest = 0;

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( QLQR_PLUGIN_DIR, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $files as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$mtime = $file->getMTime();
			if ( false !== $mtime && $mtime > $newest ) {
				$newest = $mtime;
			}
		}

		return 0 === $newest ? '' : gmdate( 'Y-m-d H:i', $newest );
	}
}
