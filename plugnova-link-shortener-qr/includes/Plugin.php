<?php
/**
 * Core plugin orchestrator.
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro;

use QuickLinkQRPro\Admin\AdminMenu;
use QuickLinkQRPro\API\RestController;
use QuickLinkQRPro\Controllers\BioController;
use QuickLinkQRPro\Controllers\RedirectController;
use QuickLinkQRPro\Controllers\ShortcodeController;
use QuickLinkQRPro\Frontend\Assets as FrontendAssets;
use QuickLinkQRPro\Frontend\KeywordLinker;
use QuickLinkQRPro\Helpers\ActivityLogger;
use QuickLinkQRPro\Helpers\BrokenLinkChecker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Plugin
 *
 * Singleton responsible for booting every subsystem of the plugin.
 * Kept intentionally thin: it only wires collaborators together.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Whether run() has already executed.
	 *
	 * @var bool
	 */
	private bool $has_run = false;

	/**
	 * Private constructor to enforce singleton usage.
	 */
	private function __construct() {}

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Boot every subsystem. Safe to call once per request.
	 */
	public function run(): void {
		if ( $this->has_run ) {
			return;
		}
		$this->has_run = true;

		$this->maybe_upgrade_database();
		$this->maybe_migrate_default_redirect();

		// Front-end URL redirect handling (/go/{slug}).
		$redirect_controller = new RedirectController();
		$redirect_controller->register();

		// Front-end Smart Bio Link pages (/bio/{slug} + /bio/{slug}/click/{id}).
		$bio_controller = new BioController();
		$bio_controller->register();

		$this->maybe_flush_rewrite_rules( $redirect_controller, $bio_controller );

		// Broken-link checking (daily wp-cron, plus on-demand from the Links admin screen).
		BrokenLinkChecker::register();
		$this->maybe_schedule_broken_link_check();

		// Activity Log audit trail (daily pruning piggybacks on the same cron event above).
		ActivityLogger::register();

		// Shortcodes: [qlqr], [qlqr_qr], [qlqr_button], [qlqr_stats].
		( new ShortcodeController() )->register();

		// Front-end assets (only enqueued when shortcodes/blocks are present).
		( new FrontendAssets() )->register();

		// Keyword auto-linking (wraps configured keywords in post content with a link).
		( new KeywordLinker() )->register();

		// REST API endpoints under /wp-json/qlqr/v1/.
		( new RestController() )->register();

		if ( is_admin() ) {
			( new AdminMenu() )->register();
		}
	}

	/**
	 * Run the DB installer again if the stored DB version is stale.
	 * Keeps upgrades safe across plugin updates without needing a separate upgrade routine file per version.
	 */
	private function maybe_upgrade_database(): void {
		$installed_version = get_option( 'qlqr_db_version', '' );

		if ( $installed_version !== QLQR_DB_VERSION ) {
			Database\Installer::install();
		}
	}

	/**
	 * One-time move of the stored "Default Redirect Type" setting from 301 to 302.
	 *
	 * Helpers\Options::set_defaults() writes its defaults with add_option(), which by design leaves
	 * an existing value alone — so changing the default in that class only ever reaches a fresh
	 * install. Sites set up before the default changed keep 301 stored, and 301 is the one value
	 * that makes click analytics wrong: the browser caches the redirect, so every click after the
	 * first from that browser never reaches the site and is never counted.
	 *
	 * Guarded by its own flag so it runs exactly once. Anyone who later chooses 301 deliberately in
	 * Settings keeps that choice — this must not turn into a setting that silently resets itself.
	 * Only the plugin-wide default moves; links that already exist keep whatever they were given,
	 * and the Links screen's "Set Redirect Type…" bulk action is there to change them in one go.
	 */
	private function maybe_migrate_default_redirect(): void {
		if ( get_option( 'qlqr_default_redirect_migrated' ) ) {
			return;
		}

		if ( 301 === (int) get_option( 'qlqr_default_redirect', 302 ) ) {
			update_option( 'qlqr_default_redirect', 302 );
		}

		update_option( 'qlqr_default_redirect_migrated', 1, true );
	}

	/**
	 * Self-healing safety net: if the rewrite rules were never flushed with our rule present
	 * (e.g. the activation hook ran before WordPress had fully set up rewrites on some hosts,
	 * or the site was migrated/cloned without re-running activation), flush once here and
	 * remember we've done it for this plugin version. Cheap to check, runs at most once per
	 * version bump.
	 *
	 * @param RedirectController $redirect_controller Controller whose short-link rewrite rule must be present.
	 * @param BioController       $bio_controller      Controller whose bio-page rewrite rules must be present.
	 */
	private function maybe_flush_rewrite_rules( RedirectController $redirect_controller, BioController $bio_controller ): void {
		$flushed_version = get_option( 'qlqr_rewrite_flushed_version', '' );

		if ( $flushed_version === QLQR_VERSION ) {
			return;
		}

		add_action(
			'init',
			static function () use ( $redirect_controller, $bio_controller ): void {
				$redirect_controller->add_rewrite_rule();
				$bio_controller->add_rewrite_rule();
				flush_rewrite_rules();
				update_option( 'qlqr_rewrite_flushed_version', QLQR_VERSION, true );
			},
			999
		);
	}

	/**
	 * Self-healing safety net for the broken-link check cron event, mirroring
	 * maybe_flush_rewrite_rules() above: activation normally schedules it, but sites migrated/
	 * cloned without re-running activation (or upgraded from a version that predates this
	 * feature) would otherwise never get it scheduled at all.
	 *
	 * Only re-schedules when the admin has actually opted in via the "Automatically check for
	 * broken links" setting (off by default) — this runs on every request (via run() on
	 * plugins_loaded), so without this guard it would silently re-schedule the daily check even on
	 * a site where the admin turned the setting off, undoing that choice on the very next page load.
	 */
	private function maybe_schedule_broken_link_check(): void {
		if ( ! get_option( 'qlqr_broken_link_check_enabled', 0 ) ) {
			return;
		}

		if ( ! wp_next_scheduled( BrokenLinkChecker::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', BrokenLinkChecker::CRON_HOOK );
		}
	}

	/**
	 * Prevent cloning of the singleton.
	 */
	private function __clone() {}

	/**
	 * Prevent unserializing of the singleton.
	 */
	public function __wakeup() {
		throw new \RuntimeException( 'Cannot unserialize a singleton.' );
	}
}
