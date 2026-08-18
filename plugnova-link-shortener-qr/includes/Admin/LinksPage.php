<?php
/**
 * Renders the Links management admin page (list, create, edit).
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Admin;

use QuickLinkQRPro\Controllers\LinkController;
use QuickLinkQRPro\Database\LinksRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class LinksPage
 */
final class LinksPage {

	/**
	 * Below this many links remaining, a free-plan account sees the "approaching the limit"
	 * notice on this screen (i.e. at LinkController::FREE_LINK_LIMIT - 5 = 45+ active links)
	 * rather than only finding out once creating a new one is actually blocked.
	 */
	private const NEAR_LIMIT_MARGIN = 5;

	/**
	 * Render the page. The list itself is populated client-side via AJAX (see assets/js/admin.js)
	 * so filtering/sorting/pagination happen without a full page reload.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_qlqr_links' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'plugnova-link-shortener-qr' ) );
		}

		$qlqr_near_link_limit = $this->near_free_link_limit();

		include QLQR_PLUGIN_DIR . 'templates/admin/links.php';
	}

	/**
	 * Whether to show the "approaching the free plan's link limit" notice, and the numbers it
	 * needs — a free-plan account already at FREE_LINK_LIMIT - NEAR_LIMIT_MARGIN active links.
	 * Pro accounts (qlqr_fs()->can_use_premium_code() === true) never see it.
	 *
	 * @return array{active:int, limit:int}|null
	 */
	private function near_free_link_limit(): ?array {
		if ( qlqr_fs()->can_use_premium_code() ) {
			return null;
		}

		$active = ( new LinksRepository() )->active_count();

		if ( $active < LinkController::FREE_LINK_LIMIT - self::NEAR_LIMIT_MARGIN ) {
			return null;
		}

		return array(
			'active' => $active,
			'limit'  => LinkController::FREE_LINK_LIMIT,
		);
	}
}
