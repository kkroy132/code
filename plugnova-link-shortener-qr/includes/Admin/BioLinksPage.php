<?php
/**
 * Renders the Smart Bio Link management admin page (list, create, edit).
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Admin;

use QuickLinkQRPro\Controllers\BioPageController;
use QuickLinkQRPro\Database\BioPagesRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BioLinksPage
 */
final class BioLinksPage {

	/**
	 * Render the page. The list itself is populated client-side via AJAX (see assets/js/admin.js)
	 * so filtering/pagination happen without a full page reload.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_qlqr_links' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'plugnova-link-shortener-qr' ) );
		}

		$qlqr_at_bio_page_limit = $this->at_free_bio_page_limit();

		include QLQR_PLUGIN_DIR . 'templates/admin/bio-links.php';
	}

	/**
	 * Whether to show the "at the free plan's Bio Page limit" notice, and the limit it needs —
	 * a free-plan account already at FREE_BIO_PAGE_LIMIT active bio pages. Pro accounts
	 * (qlqr_fs()->can_use_premium_code() === true) never see it.
	 *
	 * @return array{active:int, limit:int}|null
	 */
	private function at_free_bio_page_limit(): ?array {
		if ( qlqr_fs()->can_use_premium_code() ) {
			return null;
		}

		$active = ( new BioPagesRepository() )->active_count();

		if ( $active < BioPageController::FREE_BIO_PAGE_LIMIT ) {
			return null;
		}

		return array(
			'active' => $active,
			'limit'  => BioPageController::FREE_BIO_PAGE_LIMIT,
		);
	}
}
