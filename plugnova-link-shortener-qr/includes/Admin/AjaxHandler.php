<?php
/**
 * admin-ajax.php handlers backing the Links admin screen (create, update, trash, restore,
 * permanently delete, toggle status/favorite, regenerate QR, and CSV export).
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Admin;

use QuickLinkQRPro\Controllers\BioPageController;
use QuickLinkQRPro\Controllers\ImportController;
use QuickLinkQRPro\Controllers\LinkController;
use QuickLinkQRPro\Database\BioPagesRepository;
use QuickLinkQRPro\Database\ClicksRepository;
use QuickLinkQRPro\Database\KeywordsRepository;
use QuickLinkQRPro\Database\LinksRepository;
use QuickLinkQRPro\Helpers\ActivityLogger;
use QuickLinkQRPro\Security\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AjaxHandler
 */
final class AjaxHandler {

	/**
	 * Register all wp_ajax_* hooks. All actions require a logged-in user (no *_nopriv_* variants),
	 * since link management is always an authenticated, capability-gated action.
	 */
	public function register(): void {
		$actions = array(
			'qlqr_create_link'    => 'create_link',
			'qlqr_get_link'       => 'get_link',
			'qlqr_update_link'    => 'update_link',
			'qlqr_geoip_status'   => 'geoip_status',
			'qlqr_geoip_download' => 'geoip_download',
			'qlqr_geoip_remove'   => 'geoip_remove',
			'qlqr_link_analytics' => 'link_analytics',
			'qlqr_query_links'    => 'query_links',
			'qlqr_list_categories' => 'list_categories',
			'qlqr_trash_link'     => 'trash_link',
			'qlqr_restore_link'   => 'restore_link',
			'qlqr_delete_link'    => 'delete_link_permanently',
			'qlqr_toggle_status'  => 'toggle_status',
			'qlqr_toggle_favorite' => 'toggle_favorite',
			'qlqr_clone_link'     => 'clone_link',
			'qlqr_bulk_action'    => 'bulk_action',
			'qlqr_regenerate_qr'  => 'regenerate_qr',
			'qlqr_export_csv'     => 'export_csv',
			'qlqr_recheck_link'   => 'recheck_link',
			'qlqr_recheck_all_links' => 'recheck_all_links',
			'qlqr_import_csv'         => 'import_csv',
			'qlqr_detect_migration_sources' => 'detect_migration_sources',
			'qlqr_migrate_links'      => 'migrate_links',
			'qlqr_create_bio_page'  => 'create_bio_page',
			'qlqr_get_bio_page'     => 'get_bio_page',
			'qlqr_update_bio_page'  => 'update_bio_page',
			'qlqr_query_bio_pages'  => 'query_bio_pages',
			'qlqr_trash_bio_page'   => 'trash_bio_page',
			'qlqr_restore_bio_page' => 'restore_bio_page',
			'qlqr_delete_bio_page'  => 'delete_bio_page_permanently',
			'qlqr_toggle_bio_status' => 'toggle_bio_status',
			'qlqr_export_bio_emails' => 'export_bio_emails',
			'qlqr_bio_analytics'    => 'bio_analytics',
			'qlqr_export_link_analytics_csv' => 'export_link_analytics_csv',
			'qlqr_export_bio_analytics_csv'  => 'export_bio_analytics_csv',
			'qlqr_query_activity_log' => 'query_activity_log',
			'qlqr_db_check'        => 'db_check',
			'qlqr_db_repair'       => 'db_repair',
			'qlqr_system_health'   => 'system_health',
			'qlqr_analytics_panel' => 'analytics_panel',
		);

		foreach ( $actions as $action => $method ) {
			add_action( 'wp_ajax_' . $action, array( $this, $method ) );
		}
	}

	/**
	 * Verify the shared admin nonce and required capability. Dies with a JSON error on failure.
	 */
	private function guard(): void {
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'plugnova-link-shortener-qr' ) ), 403 );
		}

		check_ajax_referer( 'qlqr_admin_nonce', 'nonce' );
	}

	/**
	 * Create a new link.
	 */
	public function create_link(): void {
		$this->guard();

		$input = wp_unslash( $_POST['data'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification -- verified in guard() via check_ajax_referer.
		if ( ! is_array( $input ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request payload.', 'plugnova-link-shortener-qr' ) ) );
		}

		$controller = new LinkController();
		$result     = $controller->create( $input );

		if ( ! $result['success'] ) {
			wp_send_json_error(
				array(
					'message'     => $result['error'],
					'upgrade_url' => $result['upgrade_url'] ?? null,
				)
			);
		}

		ActivityLogger::log( 'link', $result['link']->id, 'created', sprintf( 'Link "%s" was created.', $result['link']->title ?: $result['link']->short_slug ) );

		wp_send_json_success(
			array(
				'link'       => $this->serialize( $result['link'] ),
				'qr_warning' => $result['qr_warning'] ?? null,
			)
		);
	}

	/**
	 * Fetch a single link's full details, for populating the Edit modal.
	 */
	public function get_link(): void {
		$this->guard();

		$id         = intval( wp_unslash( $_POST['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class
		$repository = new LinksRepository();
		$link       = $repository->find( $id );

		if ( ! $link ) {
			wp_send_json_error( array( 'message' => __( 'Link not found.', 'plugnova-link-shortener-qr' ) ) );
		}

		$data = $this->serialize( $link );

		// Keyword rules are attached here rather than inside serialize(): serialize() also runs once
		// per row in query_links(), where an extra keywords query per link would be a needless N+1.
		// Only the Edit modal needs them, and the modal is populated from exactly this endpoint.
		$data['keywords'] = array_map(
			static fn( \QuickLinkQRPro\Models\Keyword $keyword ): array => array(
				'keyword'          => $keyword->keyword,
				'case_sensitive'   => $keyword->case_sensitive,
				'max_replacements' => $keyword->max_replacements,
				'status'           => $keyword->status,
			),
			( new KeywordsRepository() )->find_by_link( $link->id )
		);

		wp_send_json_success( array( 'link' => $data ) );
	}

	/**
	 * Update an existing link's details.
	 */
	public function update_link(): void {
		$this->guard();

		$id    = intval( wp_unslash( $_POST['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class
		$input = wp_unslash( $_POST['data'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification -- verified in guard() via check_ajax_referer.

		if ( ! is_array( $input ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request payload.', 'plugnova-link-shortener-qr' ) ) );
		}

		$controller = new LinkController();
		$result     = $controller->update( $id, $input );

		if ( ! $result['success'] ) {
			wp_send_json_error( array( 'message' => $result['error'] ) );
		}

		ActivityLogger::log( 'link', $result['link']->id, 'updated', sprintf( 'Link "%s" was updated.', $result['link']->title ?: $result['link']->short_slug ) );

		wp_send_json_success(
			array(
				'link'       => $this->serialize( $result['link'] ),
				'qr_warning' => $result['qr_warning'] ?? null,
			)
		);
	}

	/**
	 * Query links for the admin list table (search/filter/sort/paginate).
	 */
	public function query_links(): void {
		$this->guard();

		$repository = new LinksRepository();
		$result     = $repository->query(
			array(
				'search'        => sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class
				'status'        => sanitize_key( wp_unslash( $_POST['status'] ?? 'all' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class
				'category'      => sanitize_text_field( wp_unslash( $_POST['category'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class
				'link_status'   => sanitize_key( wp_unslash( $_POST['link_status'] ?? 'all' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class
				'date_from'     => $this->sanitize_date_arg( wp_unslash( $_POST['date_from'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_date_arg() requires an exact Y-m-d match and returns an empty string otherwise
				'date_to'       => $this->sanitize_date_arg( wp_unslash( $_POST['date_to'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_date_arg() requires an exact Y-m-d match and returns an empty string otherwise
				'favorite_only' => ! empty( $_POST['favorite_only'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class
				'color_label'   => sanitize_hex_color( wp_unslash( $_POST['color_label'] ?? '' ) ) ?: '', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class
				'tag'           => sanitize_text_field( wp_unslash( $_POST['tag'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class
				'orderby'       => sanitize_key( wp_unslash( $_POST['orderby'] ?? 'created_at' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class
				'order'         => sanitize_key( wp_unslash( $_POST['order'] ?? 'desc' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class
				'paged'         => intval( wp_unslash( $_POST['paged'] ?? 1 ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class
				'per_page'      => intval( wp_unslash( $_POST['per_page'] ?? 20 ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class
			)
		);

		$link_ids     = array_map( static fn( \QuickLinkQRPro\Models\Link $l ): int => $l->id, $result['items'] );
		$clicks       = new ClicksRepository();
		// qr_scan_count_multi() defaults to all-time (0); \QuickLinkQRPro\Helpers\AnalyticsDays::cap()
		// caps that to the free plan's 7-day window and leaves a Pro account's all-time total as-is.
		$qr_scans     = $clicks->qr_scan_count_multi( $link_ids, \QuickLinkQRPro\Helpers\AnalyticsDays::cap( 0 ) );
		$trends       = $clicks->clicks_by_day_multi( $link_ids, 7 );

		$items = array_map(
			function ( \QuickLinkQRPro\Models\Link $link ) use ( $qr_scans, $trends ): array {
				$row                  = $this->serialize( $link );
				$row['qr_scan_count'] = $qr_scans[ $link->id ] ?? 0;
				$row['trend']         = $trends[ $link->id ] ?? array();
				return $row;
			},
			$result['items']
		);

		wp_send_json_success(
			array(
				'items' => $items,
				'total' => $result['total'],
			)
		);
	}

	/**
	 * Validate a 'Y-m-d' date filter value, returning '' for anything malformed rather than
	 * passing a value through to SQL that would silently match nothing.
	 *
	 * @param mixed $raw Raw POST value.
	 * @return string
	 */
	private function sanitize_date_arg( mixed $raw ): string { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_date_arg() requires an exact Y-m-d match and returns an empty string otherwise
		$value = sanitize_text_field( wp_unslash( (string) $raw ) );

		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	}

	/**
	 * List distinct categories currently in use, for the filter dropdown and the create/edit
	 * modal's category autocomplete.
	 */
	public function list_categories(): void {
		$this->guard();

		$repository = new LinksRepository();
		wp_send_json_success( array( 'categories' => $repository->distinct_categories() ) );
	}

	/**
	 * Move a link to Trash.
	 */
	public function trash_link(): void {
		$this->guard();
		$id = intval( wp_unslash( $_POST['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class

		$repository = new LinksRepository();
		$link       = $repository->find( $id );
		$trashed    = $repository->trash( $id );

		if ( $trashed && $link ) {
			ActivityLogger::log( 'link', $id, 'trashed', sprintf( 'Link "%s" was moved to Trash.', $link->title ?: $link->short_slug ) );
		}

		wp_send_json_success( array( 'trashed' => $trashed ) );
	}

	/**
	 * Restore a link from Trash.
	 */
	public function restore_link(): void {
		$this->guard();
		$id = intval( wp_unslash( $_POST['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class

		$repository = new LinksRepository();
		$link       = $repository->find( $id );
		$restored   = $repository->restore( $id );

		if ( $restored && $link ) {
			ActivityLogger::log( 'link', $id, 'restored', sprintf( 'Link "%s" was restored from Trash.', $link->title ?: $link->short_slug ) );
		}

		wp_send_json_success( array( 'restored' => $restored ) );
	}

	/**
	 * Permanently delete a link (must already be in Trash).
	 */
	public function delete_link_permanently(): void {
		$this->guard();
		$id = intval( wp_unslash( $_POST['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class

		$repository = new LinksRepository();
		$link       = $repository->find( $id );

		if ( ! $link || null === $link->deleted_at ) {
			wp_send_json_error( array( 'message' => __( 'Only trashed links can be permanently deleted.', 'plugnova-link-shortener-qr' ) ) );
		}

		$deleted = $repository->delete_permanently( $id );

		if ( $deleted ) {
			ActivityLogger::log( 'link', $id, 'deleted', sprintf( 'Link "%s" was permanently deleted.', $link->title ?: $link->short_slug ) );
		}

		wp_send_json_success( array( 'deleted' => $deleted ) );
	}

	/**
	 * Toggle a link's status between active and disabled.
	 */
	public function toggle_status(): void {
		$this->guard();
		$id = intval( wp_unslash( $_POST['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class

		$repository = new LinksRepository();
		$link       = $repository->find( $id );

		if ( ! $link ) {
			wp_send_json_error( array( 'message' => __( 'Link not found.', 'plugnova-link-shortener-qr' ) ) );
		}

		$new_status = 'active' === $link->status ? 'disabled' : 'active';
		$repository->update( $id, array( 'status' => $new_status ) );

		ActivityLogger::log( 'link', $id, 'status_changed', sprintf( 'Link "%s" was set to %s.', $link->title ?: $link->short_slug, $new_status ) );

		wp_send_json_success( array( 'status' => $new_status ) );
	}

	/**
	 * Toggle a link's favorite flag.
	 */
	public function toggle_favorite(): void {
		$this->guard();
		$id = intval( wp_unslash( $_POST['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class

		$repository = new LinksRepository();
		$link       = $repository->find( $id );

		if ( ! $link ) {
			wp_send_json_error( array( 'message' => __( 'Link not found.', 'plugnova-link-shortener-qr' ) ) );
		}

		$repository->update( $id, array( 'is_favorite' => $link->is_favorite ? 0 : 1 ) );

		wp_send_json_success( array( 'is_favorite' => ! $link->is_favorite ) );
	}

	/**
	 * Duplicate a link (same destination/QR style/password/schedule/targeting rules, fresh slug).
	 */
	public function clone_link(): void {
		$this->guard();
		$id = intval( wp_unslash( $_POST['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class

		$controller = new LinkController();
		$result     = $controller->clone_link( $id );

		if ( ! $result['success'] ) {
			wp_send_json_error(
				array(
					'message'     => $result['error'],
					'upgrade_url' => $result['upgrade_url'] ?? null,
				)
			);
		}

		ActivityLogger::log( 'link', $result['link']->id, 'cloned', sprintf( 'Link "%s" was cloned.', $result['link']->title ?: $result['link']->short_slug ) );

		wp_send_json_success(
			array(
				'link'       => $this->serialize( $result['link'] ),
				'qr_warning' => $result['qr_warning'] ?? null,
			)
		);
	}

	/**
	 * Apply a bulk action (trash/restore/delete/enable/disable) to a set of links at once, for
	 * the Links table's checkbox-select + bulk-action-dropdown UI.
	 */
	public function bulk_action(): void {
		$this->guard();

		$action = sanitize_key( wp_unslash( $_POST['bulk_action'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class
		$ids    = array_map( 'intval', (array) ( $_POST['ids'] ?? array() ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class
		$ids    = array_values( array_filter( $ids, static fn( int $id ): bool => $id > 0 ) );
		$value  = sanitize_text_field( wp_unslash( $_POST['bulk_value'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class

		$known_actions = array( 'trash', 'restore', 'delete', 'enable', 'disable', 'regenerate_qr', 'set_category', 'add_tag', 'set_redirect_type' );

		if ( ! in_array( $action, $known_actions, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown bulk action.', 'plugnova-link-shortener-qr' ) ) );
		}

		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No links selected.', 'plugnova-link-shortener-qr' ) ) );
		}

		if ( in_array( $action, array( 'set_category', 'add_tag' ), true ) && '' === trim( $value ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a value for this bulk action.', 'plugnova-link-shortener-qr' ) ) );
		}

		if ( 'set_redirect_type' === $action && ! in_array( (int) $value, array( 301, 302, 307 ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Please choose 301, 302 or 307.', 'plugnova-link-shortener-qr' ) ) );
		}

		$repository = new LinksRepository();
		$link_controller = new LinkController();
		$affected   = 0;

		foreach ( $ids as $id ) {
			switch ( $action ) {
				case 'trash':
					$affected += $repository->trash( $id ) ? 1 : 0;
					break;
				case 'restore':
					$affected += $repository->restore( $id ) ? 1 : 0;
					break;
				case 'delete':
					$link = $repository->find( $id );
					if ( $link && null !== $link->deleted_at ) {
						$affected += $repository->delete_permanently( $id ) ? 1 : 0;
					}
					break;
				case 'enable':
					$affected += $repository->update( $id, array( 'status' => 'active' ) ) ? 1 : 0;
					break;
				case 'disable':
					$affected += $repository->update( $id, array( 'status' => 'disabled' ) ) ? 1 : 0;
					break;
				case 'regenerate_qr':
					$affected += $link_controller->regenerate_qr( $id, array() )['success'] ? 1 : 0;
					break;
				case 'set_category':
					$affected += $repository->update( $id, array( 'category' => mb_substr( $value, 0, 100 ) ) ) ? 1 : 0;
					break;
				case 'set_redirect_type':
					$affected += $repository->update( $id, array( 'redirect_type' => (int) $value ) ) ? 1 : 0;
					break;
				case 'add_tag':
					$link = $repository->find( $id );
					if ( $link ) {
						$existing = array_filter( array_map( 'trim', explode( ',', (string) $link->tags ) ) );
						if ( ! in_array( $value, $existing, true ) ) {
							$existing[] = $value;
						}
						$affected += $repository->update( $id, array( 'tags' => mb_substr( implode( ', ', $existing ), 0, 255 ) ) ) ? 1 : 0;
					}
					break;
			}
		}

		if ( $affected > 0 ) {
			ActivityLogger::log( 'bulk', null, 'bulk_' . $action, sprintf( 'Bulk "%s" applied to %d link(s).', $action, $affected ) );
		}

		wp_send_json_success( array( 'affected' => $affected ) );
	}

	/**
	 * Regenerate a link's QR code image, optionally with new style/color options.
	 */
	public function regenerate_qr(): void {
		$this->guard();
		$id      = intval( wp_unslash( $_POST['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class
		$options = wp_unslash( $_POST['options'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification -- verified in guard().

		$controller = new LinkController();
		$result     = $controller->regenerate_qr( $id, is_array( $options ) ? $options : array() );

		if ( ! $result['success'] ) {
			wp_send_json_error( array( 'message' => $result['error'] ) );
		}

		ActivityLogger::log( 'link', $result['link']->id, 'qr_regenerated', sprintf( 'QR code was regenerated for link "%s".', $result['link']->title ?: $result['link']->short_slug ) );

		wp_send_json_success(
			array(
				'link'       => $this->serialize( $result['link'] ),
				'qr_warning' => $result['qr_warning'] ?? null,
			)
		);
	}

	/**
	 * Re-check a single link's destination_url and persist the result immediately, so the admin
	 * gets a fresh status without waiting for the daily cron.
	 */
	public function recheck_link(): void {
		$this->guard();

		$id         = intval( wp_unslash( $_POST['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class
		$repository = new LinksRepository();
		$link       = $repository->find( $id );

		if ( ! $link ) {
			wp_send_json_error( array( 'message' => __( 'Link not found.', 'plugnova-link-shortener-qr' ) ) );
		}

		if ( $link->is_multi_destination() ) {
			wp_send_json_error( array( 'message' => __( 'Multi-destination links are not checked individually — each destination would need its own check.', 'plugnova-link-shortener-qr' ) ) );
		}

		$result = \QuickLinkQRPro\Helpers\BrokenLinkChecker::check_link( $link );
		$repository->record_check_result( $id, $result );

		wp_send_json_success( array( 'link' => $this->serialize( $repository->find( $id ) ) ) );
	}

	/**
	 * Re-check every eligible link. Can take a little while on sites with many links (each check
	 * is a real HTTP request with a 10s timeout), so the JS side shows a spinner rather than
	 * expecting an instant reply — same pattern as the GeoIP database download.
	 */
	public function recheck_all_links(): void {
		$this->guard();

		$result = \QuickLinkQRPro\Helpers\BrokenLinkChecker::check_all();
		wp_send_json_success( $result );
	}

	/**
	 * Bulk-import links from an uploaded CSV file.
	 */
	public function import_csv(): void {
		$this->guard();

		if ( empty( $_FILES['file']['tmp_name'] ) || UPLOAD_ERR_OK !== ( $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared against the UPLOAD_ERR_* integer constants set by PHP itself
			wp_send_json_error( array( 'message' => __( 'Please choose a CSV file to upload.', 'plugnova-link-shortener-qr' ) ) );
		}

		$skip_duplicates = ! empty( $_POST['skip_duplicates'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class

		$controller = new ImportController();
		$result     = $controller->import_csv( sanitize_text_field( wp_unslash( $_FILES['file']['tmp_name'] ) ), $skip_duplicates ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class

		wp_send_json_success( $result );
	}

	/**
	 * Report which migration sources (other plugins' data) are present on this site, so the
	 * admin UI only offers relevant options.
	 */
	public function detect_migration_sources(): void {
		$this->guard();

		$controller = new ImportController();
		wp_send_json_success( $controller->detect_sources() );
	}

	/**
	 * Migrate links from another plugin (Pretty Links or ThirstyAffiliates) into this plugin.
	 */
	public function migrate_links(): void {
		$this->guard();

		$source           = sanitize_key( wp_unslash( $_POST['source'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class
		$skip_duplicates  = ! empty( $_POST['skip_duplicates'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class
		$controller       = new ImportController();

		$result = match ( $source ) {
			'pretty_links'       => $controller->import_from_pretty_links( $skip_duplicates ),
			'thirsty_affiliates' => $controller->import_from_thirsty_affiliates( $skip_duplicates ),
			default              => array(
				'success'  => false,
				'imported' => 0,
				'skipped'  => 0,
				'errors'   => array( __( 'Unknown migration source.', 'plugnova-link-shortener-qr' ) ),
			),
		};

		wp_send_json_success( $result );
	}

	/**
	 * Query the activity log audit trail for the Activity Log admin table (filter/search/paginate).
	 */
	public function query_activity_log(): void {
		$this->guard();

		$repository = new \QuickLinkQRPro\Database\ActivityLogRepository();
		$result     = $repository->query(
			array(
				'object_type' => sanitize_key( wp_unslash( $_POST['object_type'] ?? 'all' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class
				'search'      => sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class
				'paged'       => intval( wp_unslash( $_POST['paged'] ?? 1 ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class
				'per_page'    => intval( wp_unslash( $_POST['per_page'] ?? 20 ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class
			)
		);

		wp_send_json_success(
			array(
				'items' => array_map(
					static fn( \QuickLinkQRPro\Models\ActivityLogEntry $entry ): array => array(
						'id'          => $entry->id,
						'object_type' => $entry->object_type,
						'object_id'   => $entry->object_id,
						'action'      => $entry->action,
						'description' => $entry->description,
						'user_name'   => $entry->user_name,
						'created_at'  => $entry->created_at,
					),
					$result['items']
				),
				'total' => $result['total'],
			)
		);
	}

	/**
	 * Render the site-wide Analytics panel and return its markup.
	 *
	 * The panel is no longer rendered with the rest of the tabbed page (see Admin\TabsPage): it is
	 * the only one whose render() issues queries, and it is never the tab on screen when the page
	 * first loads, so rendering it eagerly cost seven queries on every admin request. This runs the
	 * exact same AnalyticsPage::render() into an output buffer instead, so the panel's markup and
	 * its template are unchanged — only the moment it runs has moved.
	 */
	public function analytics_panel(): void {
		$this->guard();

		ob_start();
		( new AnalyticsPage() )->render();
		$html = (string) ob_get_clean();

		wp_send_json_success( array( 'html' => $html ) );
	}

	/**
	 * Report whether the GeoIP database is currently installed, for the Settings page.
	 */
	public function geoip_status(): void {
		$this->guard();
		wp_send_json_success( \QuickLinkQRPro\Helpers\GeoIpInstaller::status() );
	}

	/**
	 * Download and build the GeoIP database. This can take a little while (a few MB download
	 * plus parsing ~140k rows), so the JS side shows a spinner rather than expecting an instant reply.
	 */
	public function geoip_download(): void {
		$this->guard();

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'plugnova-link-shortener-qr' ) ) );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- guarded by function_exists() and silenced; raising the ceiling is the point, and hosts that forbid it are unaffected, WordPress.PHP.NoSilencedErrors -- best-effort; some hosts disable this function entirely, which is fine to ignore.
		}

		$result = \QuickLinkQRPro\Helpers\GeoIpInstaller::download_and_build();

		if ( ! $result['success'] ) {
			wp_send_json_error( array( 'message' => $result['message'] ) );
		}

		wp_send_json_success(
			array(
				'message' => $result['message'],
				'status'  => \QuickLinkQRPro\Helpers\GeoIpInstaller::status(),
			)
		);
	}

	/**
	 * Remove the installed GeoIP database.
	 */
	public function geoip_remove(): void {
		$this->guard();

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'plugnova-link-shortener-qr' ) ) );
		}

		\QuickLinkQRPro\Helpers\GeoIpInstaller::remove();
		wp_send_json_success( array( 'status' => \QuickLinkQRPro\Helpers\GeoIpInstaller::status() ) );
	}

	/**
	 * Run the Database Repair Tool's read-only diagnostic checks (missing tables, stale schema
	 * version, orphaned child rows), for the Settings page's "Database Tools" panel.
	 */
	public function db_check(): void {
		$this->guard();

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'plugnova-link-shortener-qr' ) ) );
		}

		wp_send_json_success( array( 'results' => \QuickLinkQRPro\Helpers\DatabaseRepair::check() ) );
	}

	/**
	 * Fix everything db_check() flagged: re-sync the schema, remove orphaned rows, optimize tables.
	 */
	public function db_repair(): void {
		$this->guard();

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'plugnova-link-shortener-qr' ) ) );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 60 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- guarded by function_exists() and silenced; raising the ceiling is the point, and hosts that forbid it are unaffected, WordPress.PHP.NoSilencedErrors -- best-effort; some hosts disable this entirely.
		}

		$summary = \QuickLinkQRPro\Helpers\DatabaseRepair::repair();

		ActivityLogger::log( 'system', null, 'db_repair', sprintf( 'Database repair ran: %d orphaned row(s) removed, %d table(s) optimized.', $summary['orphans_removed'], $summary['tables_optimized'] ) );

		wp_send_json_success(
			array(
				'summary' => $summary,
				'results' => \QuickLinkQRPro\Helpers\DatabaseRepair::check(),
			)
		);
	}

	/**
	 * Run the System Health Checker's environment/configuration checks, for the Settings page's
	 * "System Health" panel.
	 */
	public function system_health(): void {
		$this->guard();

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'plugnova-link-shortener-qr' ) ) );
		}

		wp_send_json_success( array( 'results' => \QuickLinkQRPro\Helpers\SystemHealth::run_checks() ) );
	}

	/**
	 * Validate the analytics date-range picker's value down to an allow-list, defaulting to 30
	 * days for anything missing/invalid, then apply the free-plan 7-day cap — shared by
	 * link_analytics(), export_link_analytics_csv(), bio_analytics(), and export_bio_analytics_csv().
	 *
	 * @param mixed $raw Raw POST value.
	 * @return int One of 7, 30, 90 for a Pro account; always 7 for a free account.
	 */
	private function sanitize_analytics_days( mixed $raw ): int { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- \QuickLinkQRPro\Helpers\AnalyticsDays::sanitize() restricts the value to 7, 30 or 90
		return \QuickLinkQRPro\Helpers\AnalyticsDays::sanitize( $raw );
	}

	/**
	 * Re-bucket a raw (large-limit) referrer breakdown into human-readable categories (Google,
	 * Facebook, etc. — see Helpers\ReferrerCategorizer), plus two rows for traffic that carries no
	 * referrer at all: "QR scan" and "Direct". Sorted back to descending order and capped to
	 * $limit. Shared by link_analytics() and bio_analytics().
	 *
	 * QR scans are separated because a camera app sends no Referer header, so every scan used to
	 * land in "Direct" next to typed URLs and bookmarks — which made the row unable to answer the
	 * one question a printed code raises: how much traffic is it actually bringing? The three kinds
	 * of row are mutually exclusive and together account for every non-bot event.
	 *
	 * @param array<int, array<string, mixed>> $raw_rows     Raw {label: hostname, ...} rows.
	 * @param int                                $direct_count Non-bot events with no referrer that did not come from a QR code.
	 * @param int                                $qr_count     Non-bot events with no referrer that came from a scanned QR code.
	 * @param string                             $count_key    'clicks' or 'views'.
	 * @param int                                $limit        Max categories to return.
	 * @return array<int, array<string, mixed>>
	 */
	private function categorize_referrers( array $raw_rows, int $direct_count, int $qr_count, string $count_key, int $limit ): array {
		$grouped = \QuickLinkQRPro\Helpers\ReferrerCategorizer::group( $raw_rows, $count_key, $limit + 2 );

		if ( $qr_count > 0 ) {
			$grouped[] = array(
				'label'    => __( '📱 QR scan', 'plugnova-link-shortener-qr' ),
				$count_key => $qr_count,
			);
		}

		if ( $direct_count > 0 ) {
			$grouped[] = array(
				'label'    => __( 'Direct', 'plugnova-link-shortener-qr' ),
				$count_key => $direct_count,
			);
		}

		usort( $grouped, static fn( array $a, array $b ): int => $b[ $count_key ] <=> $a[ $count_key ] );

		return array_slice( $grouped, 0, $limit );
	}

	/**
	 * Per-link analytics breakdown for the "Clicks" popup on the Links page — clicks over time,
	 * plus device/browser/country/referrer/campaign breakdowns, scoped to a single link and to
	 * the requested date range (defaults to the last 30 days).
	 */
	public function link_analytics(): void {
		$this->guard();

		$id           = intval( wp_unslash( $_POST['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class
		$days         = $this->sanitize_analytics_days( wp_unslash( $_POST['days'] ?? 30 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_analytics_days() restricts the value to 7, 30 or 90
		$include_bots = ! empty( $_POST['include_bots'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class
		$exclude_bots = ! $include_bots;

		$repository = new LinksRepository();
		$link       = $repository->find( $id );

		if ( ! $link ) {
			wp_send_json_error( array( 'message' => __( 'Link not found.', 'plugnova-link-shortener-qr' ) ) );
		}

		$clicks = new ClicksRepository();

		$destinations_data = array();
		if ( $link->is_multi_destination() ) {
			$destinations       = ( new \QuickLinkQRPro\Database\DestinationsRepository() )->find_by_link( $id );
			$total_dest_clicks  = array_sum( array_map( static fn( \QuickLinkQRPro\Models\Destination $d ): int => $d->clicks, $destinations ) );

			$destinations_data = array_map(
				static function ( \QuickLinkQRPro\Models\Destination $d ) use ( $total_dest_clicks ): array {
					return array(
						'destination_url' => $d->destination_url,
						'clicks'          => $d->clicks,
						'percentage'      => $total_dest_clicks > 0 ? round( ( $d->clicks / $total_dest_clicks ) * 100, 1 ) : 0,
						'status'          => $d->status,
						'weight'          => $d->weight,
					);
				},
				$destinations
			);
		}

		$clicks_by_day = $clicks->clicks_by_day( $days, $id, $exclude_bots );

		wp_send_json_success(
			array(
				'days'                  => $days,
				'include_bots'          => $include_bots,
				'title'                 => $link->title ?: $link->short_slug,
				'short_url'             => $link->get_short_url(),
				// The period sum of the chart above, rather than the link's lifetime total_clicks
				// counter (shown elsewhere, e.g. the Links table), so every number in this modal
				// consistently describes the currently selected date range.
				'total_clicks'          => array_sum( array_column( $clicks_by_day, 'clicks' ) ),
				'unique_clicks'         => $clicks->unique_click_count( $id, $days ),
				'qr_scans'              => $clicks->qr_scan_count( $id, $days ),
				'bot_clicks'            => $clicks->bot_click_count( $id, $days ),
				'clicks_by_day'         => $clicks_by_day,
				'by_device'             => $clicks->top_by_dimension( 'device', 5, $id, $exclude_bots, $days ),
				'by_browser'            => $clicks->top_by_dimension( 'browser', 5, $id, $exclude_bots, $days ),
				'by_os'                 => $clicks->top_by_dimension( 'operating_system', 5, $id, $exclude_bots, $days ),
				'by_country'            => $clicks->top_by_dimension( 'country', 5, $id, $exclude_bots, $days ),
				'by_referrer'           => $this->categorize_referrers(
					$clicks->top_by_dimension( 'referrer', 200, $id, $exclude_bots, $days ),
					$clicks->direct_referrer_count( $id, $days ),
					$clicks->qr_referrer_count( $id, $days ),
					'clicks',
					6
				),
				'by_utm_campaign'       => $clicks->top_by_dimension( 'utm_campaign', 5, $id, $exclude_bots, $days ),
				'is_multi_destination' => $link->is_multi_destination(),
				'rotation_method'   => $link->rotation_method,
				'destinations'      => $destinations_data,
			)
		);
	}

	/**
	 * Write one labeled section of a multi-section analytics CSV: a title row, a header row, one
	 * row per {label, <count_key>} entry, then a blank separator row. Shared by
	 * export_link_analytics_csv() and export_bio_analytics_csv() for every dimension breakdown
	 * (Devices, Browsers, Countries, Referrers, UTM Campaigns) so a single CSV file can hold
	 * several differently-shaped tables, the same way the on-screen modal shows several tables.
	 *
	 * @param resource $output       Open file handle from fopen('php://output', 'w').
	 * @param string   $title        Section title (its own row, first column only).
	 * @param string   $label_header Header for the label column (e.g. "Device", "Country").
	 * @param array<int, array<string, mixed>> $rows Rows of {label, <count_key>}.
	 * @param string   $count_key    'clicks' or 'views'.
	 */
	private function write_csv_dimension_section( $output, string $title, string $label_header, array $rows, string $count_key ): void {
		fputcsv( $output, array( $title ) );
		fputcsv( $output, array( $label_header, ucfirst( $count_key ) ) );

		foreach ( $rows as $row ) {
			fputcsv( $output, array( $row['label'] ?? '', $row[ $count_key ] ?? 0 ) );
		}

		fputcsv( $output, array() );
	}

	/**
	 * Stream a CSV export of a single link's analytics — summary, clicks-by-day, and every
	 * breakdown table shown in the "Clicks" popup — scoped to the same date range / bot-traffic
	 * setting currently selected there (see assets/js/admin.js' Export CSV button, which reads
	 * both from the modal's own controls before building this request's query string).
	 */
	public function export_link_analytics_csv(): void {
		$this->guard();

		$id           = intval( wp_unslash( $_GET['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification -- verified in guard() via check_ajax_referer.
		$days         = $this->sanitize_analytics_days( wp_unslash( $_GET['days'] ?? 30 ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification -- verified in guard() via check_ajax_referer.
		$include_bots = ! empty( $_GET['include_bots'] ); // phpcs:ignore WordPress.Security.NonceVerification -- verified in guard() via check_ajax_referer.
		$exclude_bots = ! $include_bots;

		$link = ( new LinksRepository() )->find( $id );
		if ( ! $link ) {
			wp_die( esc_html__( 'Link not found.', 'plugnova-link-shortener-qr' ) );
		}

		$clicks        = new ClicksRepository();
		$clicks_by_day = $clicks->clicks_by_day( $days, $id, $exclude_bots );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . sanitize_file_name( $link->short_slug ) . '-analytics-' . gmdate( 'Y-m-d' ) . '.csv' );

		$output = fopen( 'php://output', 'w' );

		fputcsv( $output, array( 'Link', $link->title ?: $link->short_slug ) );
		fputcsv( $output, array( 'Short URL', $link->get_short_url() ) );
		fputcsv( $output, array( 'Date Range', 'Last ' . $days . ' days' ) );
		fputcsv( $output, array( 'Include Bot Traffic', $include_bots ? 'Yes' : 'No' ) );
		fputcsv( $output, array() );

		fputcsv( $output, array( 'Summary' ) );
		fputcsv( $output, array( 'Metric', 'Value' ) );
		fputcsv( $output, array( 'Clicks', array_sum( array_column( $clicks_by_day, 'clicks' ) ) ) );
		fputcsv( $output, array( 'Unique Clicks', $clicks->unique_click_count( $id, $days ) ) );
		fputcsv( $output, array( 'QR Scans', $clicks->qr_scan_count( $id, $days ) ) );
		fputcsv( $output, array( 'Bot Traffic', $clicks->bot_click_count( $id, $days ) ) );
		fputcsv( $output, array() );

		fputcsv( $output, array( 'Clicks by Day' ) );
		fputcsv( $output, array( 'Date', 'Clicks' ) );
		foreach ( $clicks_by_day as $row ) {
			fputcsv( $output, array( $row['date'], $row['clicks'] ) );
		}
		fputcsv( $output, array() );

		$this->write_csv_dimension_section( $output, 'Devices', 'Device', $clicks->top_by_dimension( 'device', 20, $id, $exclude_bots, $days ), 'clicks' );
		$this->write_csv_dimension_section( $output, 'Browsers', 'Browser', $clicks->top_by_dimension( 'browser', 20, $id, $exclude_bots, $days ), 'clicks' );
		$this->write_csv_dimension_section( $output, 'Operating Systems', 'Operating System', $clicks->top_by_dimension( 'operating_system', 20, $id, $exclude_bots, $days ), 'clicks' );
		$this->write_csv_dimension_section( $output, 'Countries', 'Country', $clicks->top_by_dimension( 'country', 50, $id, $exclude_bots, $days ), 'clicks' );
		$this->write_csv_dimension_section(
			$output,
			'Referrers',
			'Source',
			$this->categorize_referrers(
				$clicks->top_by_dimension( 'referrer', 200, $id, $exclude_bots, $days ),
				$clicks->direct_referrer_count( $id, $days ),
				$clicks->qr_referrer_count( $id, $days ),
				'clicks',
				20
			),
			'clicks'
		);
		$this->write_csv_dimension_section( $output, 'UTM Campaigns', 'Campaign', $clicks->top_by_dimension( 'utm_campaign', 20, $id, $exclude_bots, $days ), 'clicks' );

		// No fclose(): this is the php://output stream (not a real file, so WP_Filesystem has no
		// equivalent for it), and PHP flushes and closes it during shutdown from the exit() below.
		exit;
	}

	/**
	 * Stream a CSV export of all non-trashed links and exit.
	 */
	public function export_csv(): void {
		$this->guard();

		$ids_param = sanitize_text_field( wp_unslash( $_GET['ids'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification -- verified in guard() via check_ajax_referer.
		$ids       = array_filter( array_map( 'absint', explode( ',', $ids_param ) ) );

		$repository = new LinksRepository();
		$result     = $repository->query( array( 'per_page' => 100000, 'status' => 'all', 'ids' => $ids ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=plugnova-link-shortener-qr-export-' . gmdate( 'Y-m-d' ) . '.csv' );

		$output = fopen( 'php://output', 'w' );
		fputcsv( $output, array( 'ID', 'Title', 'Destination URL', 'Short URL', 'Category', 'Status', 'Clicks', 'Created At' ) );

		foreach ( $result['items'] as $link ) {
			fputcsv(
				$output,
				array(
					$link->id,
					$link->title,
					$link->destination_url,
					$link->get_short_url(),
					$link->category,
					$link->status,
					$link->total_clicks,
					$link->created_at,
				)
			);
		}

		// No fclose(): this is the php://output stream (not a real file, so WP_Filesystem has no
		// equivalent for it), and PHP flushes and closes it during shutdown from the exit() below.
		exit;
	}

	/**
	 * Create a new Smart Bio Link page.
	 */
	public function create_bio_page(): void {
		$this->guard();

		$input = wp_unslash( $_POST['data'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification -- verified in guard() via check_ajax_referer.
		if ( ! is_array( $input ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request payload.', 'plugnova-link-shortener-qr' ) ) );
		}

		$controller = new BioPageController();
		$result     = $controller->create( $input );

		if ( ! $result['success'] ) {
			wp_send_json_error(
				array(
					'message'     => $result['error'],
					'upgrade_url' => $result['upgrade_url'] ?? null,
				)
			);
		}

		ActivityLogger::log( 'bio_page', $result['bio_page']->id, 'created', sprintf( 'Bio page "%s" was created.', $result['bio_page']->title ?: $result['bio_page']->slug ) );

		wp_send_json_success(
			array(
				'bio_page'   => $this->serialize_bio_page( $result['bio_page'] ),
				'qr_warning' => $result['qr_warning'] ?? null,
			)
		);
	}

	/**
	 * Fetch a single bio page's full details, for populating the Edit modal.
	 */
	public function get_bio_page(): void {
		$this->guard();

		$id         = intval( wp_unslash( $_POST['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class
		$repository = new BioPagesRepository();
		$bio_page   = $repository->find( $id );

		if ( ! $bio_page ) {
			wp_send_json_error( array( 'message' => __( 'Bio page not found.', 'plugnova-link-shortener-qr' ) ) );
		}

		wp_send_json_success( array( 'bio_page' => $this->serialize_bio_page( $bio_page ) ) );
	}

	/**
	 * Update an existing bio page's details.
	 */
	public function update_bio_page(): void {
		$this->guard();

		$id    = intval( wp_unslash( $_POST['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class
		$input = wp_unslash( $_POST['data'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification -- verified in guard() via check_ajax_referer.

		if ( ! is_array( $input ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request payload.', 'plugnova-link-shortener-qr' ) ) );
		}

		$controller = new BioPageController();
		$result     = $controller->update( $id, $input );

		if ( ! $result['success'] ) {
			wp_send_json_error( array( 'message' => $result['error'] ) );
		}

		ActivityLogger::log( 'bio_page', $result['bio_page']->id, 'updated', sprintf( 'Bio page "%s" was updated.', $result['bio_page']->title ?: $result['bio_page']->slug ) );

		wp_send_json_success(
			array(
				'bio_page'   => $this->serialize_bio_page( $result['bio_page'] ),
				'qr_warning' => $result['qr_warning'] ?? null,
			)
		);
	}

	/**
	 * Query bio pages for the admin list table (search/filter/paginate).
	 */
	public function query_bio_pages(): void {
		$this->guard();

		$repository = new BioPagesRepository();
		$result     = $repository->query(
			array(
				'search'   => sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class
				'status'   => sanitize_key( wp_unslash( $_POST['status'] ?? 'all' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class
				'paged'    => intval( wp_unslash( $_POST['paged'] ?? 1 ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class
				'per_page' => intval( wp_unslash( $_POST['per_page'] ?? 20 ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class
			)
		);

		wp_send_json_success(
			array(
				'items' => array_map( array( $this, 'serialize_bio_page' ), $result['items'] ),
				'total' => $result['total'],
			)
		);
	}

	/**
	 * Move a bio page to Trash.
	 */
	public function trash_bio_page(): void {
		$this->guard();
		$id = intval( wp_unslash( $_POST['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class

		$repository = new BioPagesRepository();
		$bio_page   = $repository->find( $id );
		$trashed    = $repository->trash( $id );

		if ( $trashed && $bio_page ) {
			ActivityLogger::log( 'bio_page', $id, 'trashed', sprintf( 'Bio page "%s" was moved to Trash.', $bio_page->title ?: $bio_page->slug ) );
		}

		wp_send_json_success( array( 'trashed' => $trashed ) );
	}

	/**
	 * Restore a bio page from Trash.
	 */
	public function restore_bio_page(): void {
		$this->guard();
		$id = intval( wp_unslash( $_POST['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class

		$repository = new BioPagesRepository();
		$bio_page   = $repository->find( $id );
		$restored   = $repository->restore( $id );

		if ( $restored && $bio_page ) {
			ActivityLogger::log( 'bio_page', $id, 'restored', sprintf( 'Bio page "%s" was restored from Trash.', $bio_page->title ?: $bio_page->slug ) );
		}

		wp_send_json_success( array( 'restored' => $restored ) );
	}

	/**
	 * Permanently delete a bio page (must already be in Trash).
	 */
	public function delete_bio_page_permanently(): void {
		$this->guard();
		$id = intval( wp_unslash( $_POST['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class

		$repository = new BioPagesRepository();
		$bio_page   = $repository->find( $id );

		if ( ! $bio_page || null === $bio_page->deleted_at ) {
			wp_send_json_error( array( 'message' => __( 'Only trashed bio pages can be permanently deleted.', 'plugnova-link-shortener-qr' ) ) );
		}

		$deleted = $repository->delete_permanently( $id );

		if ( $deleted ) {
			ActivityLogger::log( 'bio_page', $id, 'deleted', sprintf( 'Bio page "%s" was permanently deleted.', $bio_page->title ?: $bio_page->slug ) );
		}

		wp_send_json_success( array( 'deleted' => $deleted ) );
	}

	/**
	 * Toggle a bio page's status between active and disabled.
	 */
	public function toggle_bio_status(): void {
		$this->guard();
		$id = intval( wp_unslash( $_POST['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class

		$repository = new BioPagesRepository();
		$bio_page   = $repository->find( $id );

		if ( ! $bio_page ) {
			wp_send_json_error( array( 'message' => __( 'Bio page not found.', 'plugnova-link-shortener-qr' ) ) );
		}

		$new_status = 'active' === $bio_page->status ? 'disabled' : 'active';
		$repository->update( $id, array( 'status' => $new_status ) );

		ActivityLogger::log( 'bio_page', $id, 'status_changed', sprintf( 'Bio page "%s" was set to %s.', $bio_page->title ?: $bio_page->slug, $new_status ) );

		wp_send_json_success( array( 'status' => $new_status ) );
	}

	/**
	 * Stream a CSV export of emails captured via a bio page's email-capture gate, and exit.
	 */
	public function export_bio_emails(): void {
		$this->guard();

		$id       = intval( wp_unslash( $_GET['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification -- verified in guard() via check_ajax_referer.
		$bio_page = ( new BioPagesRepository() )->find( $id );

		if ( ! $bio_page ) {
			wp_die( esc_html__( 'Bio page not found.', 'plugnova-link-shortener-qr' ) );
		}

		$emails = ( new \QuickLinkQRPro\Database\BioEmailsRepository() )->find_by_page( $id );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . sanitize_file_name( $bio_page->slug ) . '-emails-' . gmdate( 'Y-m-d' ) . '.csv' );

		$output = fopen( 'php://output', 'w' );
		fputcsv( $output, array( 'Email', 'Captured At' ) );

		foreach ( $emails as $row ) {
			fputcsv( $output, array( $row['email'], $row['created_at'] ) );
		}

		// No fclose(): this is the php://output stream (not a real file, so WP_Filesystem has no
		// equivalent for it), and PHP flushes and closes it during shutdown from the exit() below.
		exit;
	}

	/**
	 * Full analytics breakdown for a bio page — views over time, country/device breakdowns,
	 * QR vs. direct traffic, email captures, and a per-button click ranking. Mirrors
	 * link_analytics() above for regular links.
	 */
	public function bio_analytics(): void {
		$this->guard();

		$id           = intval( wp_unslash( $_POST['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class
		$days         = $this->sanitize_analytics_days( wp_unslash( $_POST['days'] ?? 30 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_analytics_days() restricts the value to 7, 30 or 90
		$include_bots = ! empty( $_POST['include_bots'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by guard(), the first statement of every handler in this class
		$exclude_bots = ! $include_bots;

		$bio_page = ( new BioPagesRepository() )->find( $id );

		if ( ! $bio_page ) {
			wp_send_json_error( array( 'message' => __( 'Bio page not found.', 'plugnova-link-shortener-qr' ) ) );
		}

		$views = new \QuickLinkQRPro\Database\BioViewsRepository();

		// Group (accordion) headers carry no clicks of their own — they're excluded from the
		// leaderboard rather than padding the bottom of it with permanent zeros.
		$buttons     = array_filter(
			( new \QuickLinkQRPro\Database\BioLinksRepository() )->find_by_page( $bio_page->id ),
			static fn( \QuickLinkQRPro\Models\BioLink $button ): bool => ! $button->is_group_header()
		);
		$button_data = array_map(
			static fn( \QuickLinkQRPro\Models\BioLink $button ): array => array(
				'label'  => $button->label,
				'clicks' => $button->clicks,
			),
			$buttons
		);
		usort( $button_data, static fn( array $a, array $b ): int => $b['clicks'] <=> $a['clicks'] );

		$views_by_day = $views->views_by_day( $bio_page->id, $days, $exclude_bots );

		wp_send_json_success(
			array(
				'days'            => $days,
				'include_bots'    => $include_bots,
				'title'           => $bio_page->title ?: $bio_page->slug,
				'public_url'      => $bio_page->get_public_url(),
				// Period sums (matching the chart), not lifetime totals — same reasoning as
				// link_analytics()'s 'total_clicks'.
				'total_views'     => array_sum( array_column( $views_by_day, 'views' ) ),
				'unique_views'    => $views->unique_view_count( $bio_page->id, $days ),
				'qr_views'        => $views->count( $bio_page->id, 'qr', $days ),
				'direct_views'    => $views->count( $bio_page->id, 'link', $days ),
				'bot_views'       => $views->bot_view_count( $bio_page->id, $days ),
				'email_count'     => ( new \QuickLinkQRPro\Database\BioEmailsRepository() )->count_for_page( $bio_page->id ),
				'views_by_day'    => $views_by_day,
				'by_country'      => $views->top_by_dimension( $bio_page->id, 'country', 5, $exclude_bots, $days ),
				'by_device'       => $views->top_by_dimension( $bio_page->id, 'device', 5, $exclude_bots, $days ),
				'by_browser'      => $views->top_by_dimension( $bio_page->id, 'browser', 5, $exclude_bots, $days ),
				'by_os'           => $views->top_by_dimension( $bio_page->id, 'operating_system', 5, $exclude_bots, $days ),
				'by_referrer'     => $this->categorize_referrers(
					$views->top_by_dimension( $bio_page->id, 'referrer', 200, $exclude_bots, $days ),
					$views->direct_referrer_count( $bio_page->id, $days ),
					$views->qr_referrer_count( $bio_page->id, $days ),
					'views',
					6
				),
				'by_utm_campaign' => $views->top_by_dimension( $bio_page->id, 'utm_campaign', 5, $exclude_bots, $days ),
				'buttons'         => $button_data,
			)
		);
	}

	/**
	 * Stream a CSV export of a single bio page's analytics — summary, views-by-day, top buttons,
	 * and every breakdown table shown in its analytics modal — scoped to the same date range /
	 * bot-traffic setting currently selected there. Mirrors export_link_analytics_csv().
	 */
	public function export_bio_analytics_csv(): void {
		$this->guard();

		$id           = intval( wp_unslash( $_GET['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification -- verified in guard() via check_ajax_referer.
		$days         = $this->sanitize_analytics_days( wp_unslash( $_GET['days'] ?? 30 ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification -- verified in guard() via check_ajax_referer.
		$include_bots = ! empty( $_GET['include_bots'] ); // phpcs:ignore WordPress.Security.NonceVerification -- verified in guard() via check_ajax_referer.
		$exclude_bots = ! $include_bots;

		$bio_page = ( new BioPagesRepository() )->find( $id );
		if ( ! $bio_page ) {
			wp_die( esc_html__( 'Bio page not found.', 'plugnova-link-shortener-qr' ) );
		}

		$views        = new \QuickLinkQRPro\Database\BioViewsRepository();
		$views_by_day = $views->views_by_day( $bio_page->id, $days, $exclude_bots );

		$buttons     = array_filter(
			( new \QuickLinkQRPro\Database\BioLinksRepository() )->find_by_page( $bio_page->id ),
			static fn( \QuickLinkQRPro\Models\BioLink $button ): bool => ! $button->is_group_header()
		);
		$button_data = array_map(
			static fn( \QuickLinkQRPro\Models\BioLink $button ): array => array(
				'label'  => $button->label,
				'clicks' => $button->clicks,
			),
			$buttons
		);
		usort( $button_data, static fn( array $a, array $b ): int => $b['clicks'] <=> $a['clicks'] );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . sanitize_file_name( $bio_page->slug ) . '-analytics-' . gmdate( 'Y-m-d' ) . '.csv' );

		$output = fopen( 'php://output', 'w' );

		fputcsv( $output, array( 'Bio Page', $bio_page->title ?: $bio_page->slug ) );
		fputcsv( $output, array( 'Public URL', $bio_page->get_public_url() ) );
		fputcsv( $output, array( 'Date Range', 'Last ' . $days . ' days' ) );
		fputcsv( $output, array( 'Include Bot Traffic', $include_bots ? 'Yes' : 'No' ) );
		fputcsv( $output, array() );

		fputcsv( $output, array( 'Summary' ) );
		fputcsv( $output, array( 'Metric', 'Value' ) );
		fputcsv( $output, array( 'Views', array_sum( array_column( $views_by_day, 'views' ) ) ) );
		fputcsv( $output, array( 'Unique Views', $views->unique_view_count( $bio_page->id, $days ) ) );
		fputcsv( $output, array( 'QR Views', $views->count( $bio_page->id, 'qr', $days ) ) );
		fputcsv( $output, array( 'Direct Views', $views->count( $bio_page->id, 'link', $days ) ) );
		fputcsv( $output, array( 'Bot Traffic', $views->bot_view_count( $bio_page->id, $days ) ) );
		fputcsv( $output, array( 'Emails Captured', ( new \QuickLinkQRPro\Database\BioEmailsRepository() )->count_for_page( $bio_page->id ) ) );
		fputcsv( $output, array() );

		fputcsv( $output, array( 'Views by Day' ) );
		fputcsv( $output, array( 'Date', 'Views' ) );
		foreach ( $views_by_day as $row ) {
			fputcsv( $output, array( $row['date'], $row['views'] ) );
		}
		fputcsv( $output, array() );

		$this->write_csv_dimension_section( $output, 'Top Buttons by Clicks', 'Button', $button_data, 'clicks' );
		$this->write_csv_dimension_section( $output, 'Countries', 'Country', $views->top_by_dimension( $bio_page->id, 'country', 50, $exclude_bots, $days ), 'views' );
		$this->write_csv_dimension_section( $output, 'Devices', 'Device', $views->top_by_dimension( $bio_page->id, 'device', 20, $exclude_bots, $days ), 'views' );
		$this->write_csv_dimension_section( $output, 'Browsers', 'Browser', $views->top_by_dimension( $bio_page->id, 'browser', 20, $exclude_bots, $days ), 'views' );
		$this->write_csv_dimension_section( $output, 'Operating Systems', 'Operating System', $views->top_by_dimension( $bio_page->id, 'operating_system', 20, $exclude_bots, $days ), 'views' );
		$this->write_csv_dimension_section(
			$output,
			'Referrers',
			'Source',
			$this->categorize_referrers(
				$views->top_by_dimension( $bio_page->id, 'referrer', 200, $exclude_bots, $days ),
				$views->direct_referrer_count( $bio_page->id, $days ),
				$views->qr_referrer_count( $bio_page->id, $days ),
				'views',
				20
			),
			'views'
		);
		$this->write_csv_dimension_section( $output, 'UTM Campaigns', 'Campaign', $views->top_by_dimension( $bio_page->id, 'utm_campaign', 20, $exclude_bots, $days ), 'views' );

		// No fclose(): this is the php://output stream (not a real file, so WP_Filesystem has no
		// equivalent for it), and PHP flushes and closes it during shutdown from the exit() below.
		exit;
	}

	/**
	 * Serialize a BioPage model (plus its buttons) for JSON responses to the admin UI.
	 *
	 * @param \QuickLinkQRPro\Models\BioPage $bio_page Bio page model.
	 * @return array<string, mixed>
	 */
	private function serialize_bio_page( \QuickLinkQRPro\Models\BioPage $bio_page ): array {
		return array(
			'id'                       => $bio_page->id,
			'slug'                     => $bio_page->slug,
			'title'                    => $bio_page->title,
			'bio_text'                 => $bio_page->bio_text,
			'avatar_url'               => $bio_page->avatar_url,
			'theme_color'              => $bio_page->theme_color,
			'theme_preset'             => $bio_page->theme_preset,
			'button_style'             => $bio_page->button_style,
			'email_capture_enabled'    => $bio_page->email_capture_enabled,
			'email_capture_heading'    => $bio_page->email_capture_heading,
			'qr_image'                 => $bio_page->qr_image,
			'status'                   => $bio_page->status,
			// Edit-modal-only field: has_password is a boolean flag, never the hash itself —
			// mirrors serialize()'s treatment of Link::password/has_password.
			'has_password'             => $bio_page->is_password_protected(),
			'countdown_enabled'        => $bio_page->countdown_enabled,
			'countdown_label'          => $bio_page->countdown_label,
			'countdown_target_at'      => $bio_page->countdown_target_at ? mysql2date( 'Y-m-d\TH:i', $bio_page->countdown_target_at, false ) : '',
			'announcement_enabled'     => $bio_page->announcement_enabled,
			'announcement_text'        => $bio_page->announcement_text,
			'announcement_bg_color'    => $bio_page->announcement_bg_color,
			'announcement_text_color'  => $bio_page->announcement_text_color,
			'announcement_url'         => $bio_page->announcement_url,
			'starts_at'                => $bio_page->starts_at ? mysql2date( 'Y-m-d\TH:i', $bio_page->starts_at, false ) : '',
			'ends_at'                  => $bio_page->ends_at ? mysql2date( 'Y-m-d\TH:i', $bio_page->ends_at, false ) : '',
			'public_url'               => $bio_page->get_public_url(),
			'total_views'              => $bio_page->total_views,
			'qr_views'                 => $bio_page->qr_views,
			'email_count'              => ( new \QuickLinkQRPro\Database\BioEmailsRepository() )->count_for_page( $bio_page->id ),
			'is_trashed'               => null !== $bio_page->deleted_at,
			'created_at'               => $bio_page->created_at,
			'updated_at'               => $bio_page->updated_at,
			'links'                    => array_map(
				static fn( \QuickLinkQRPro\Models\BioLink $l ): array => array(
					'id'                => $l->id,
					'label'             => $l->label,
					'url'               => $l->url,
					'icon'              => $l->icon,
					'image_url'         => $l->image_url,
					'status'            => $l->status,
					'clicks'            => $l->clicks,
					'starts_at'         => $l->starts_at ? mysql2date( 'Y-m-d\TH:i', $l->starts_at, false ) : '',
					'ends_at'           => $l->ends_at ? mysql2date( 'Y-m-d\TH:i', $l->ends_at, false ) : '',
					'item_type'         => $l->item_type,
					'group_key'         => $l->group_key,
					'parent_group_key'  => $l->parent_group_key,
					'visible_countries' => $l->visible_countries,
					'visible_devices'   => $l->visible_devices,
				),
				( new \QuickLinkQRPro\Database\BioLinksRepository() )->find_by_page( $bio_page->id )
			),
			'social_links'             => array_map(
				static fn( \QuickLinkQRPro\Models\BioSocial $s ): array => array(
					'id'          => $s->id,
					'platform'    => $s->platform,
					'url'         => $s->url,
					'status'      => $s->status,
					'is_floating' => $s->is_floating,
				),
				( new \QuickLinkQRPro\Database\BioSocialsRepository() )->find_by_page( $bio_page->id )
			),
		);
	}

	/**
	 * Serialize a Link model for JSON responses to the admin UI.
	 *
	 * @param \QuickLinkQRPro\Models\Link $link Link model.
	 * @return array<string, mixed>
	 */
	private function serialize( \QuickLinkQRPro\Models\Link $link ): array {
		return array(
			'id'              => $link->id,
			'title'           => $link->title,
			'destination_url' => $link->destination_url,
			'short_slug'      => $link->short_slug,
			'short_url'       => $link->get_short_url(),
			'qr_image'        => $link->qr_image,
			'qr_style'        => $link->qr_style,
			'qr_fg_color'     => $link->qr_fg_color,
			'qr_bg_color'     => $link->qr_bg_color,
			'qr_logo_id'      => $link->qr_logo_id,
			'qr_logo_url'     => $link->qr_logo_id ? wp_get_attachment_image_url( $link->qr_logo_id, 'thumbnail' ) : '',
			'qr_caption_text' => $link->qr_caption_text,
			'status'          => $link->status,
			'redirect_type'   => $link->redirect_type,
			'total_clicks'    => $link->total_clicks,
			'is_favorite'     => $link->is_favorite,
			'is_trashed'      => null !== $link->deleted_at,
			'category'        => $link->category,
			'link_status'     => $link->link_status,
			'http_status'     => $link->http_status,
			'response_time_ms' => $link->response_time_ms,
			'meta_title'      => $link->meta_title,
			'has_redirect_loop' => $link->has_redirect_loop,
			'color_label'     => $link->color_label,
			'score'           => \QuickLinkQRPro\Helpers\LinkScoreCalculator::calculate( $link ),
			'last_checked_at' => $link->last_checked_at,
			'created_at'      => $link->created_at,
			'updated_at'      => $link->updated_at,
			// Edit-modal-only fields below. has_password is a boolean flag, never the hash itself.
			'has_password'    => $link->is_password_protected(),
			'expires_at'      => $link->expires_at ? mysql2date( 'Y-m-d\TH:i', $link->expires_at, false ) : '',
			'click_limit'     => $link->click_limit,
			'utm_source'      => $link->utm_source,
			'utm_medium'      => $link->utm_medium,
			'utm_campaign'    => $link->utm_campaign,
			'tags'            => $link->tags,
			'notes'           => $link->notes,
			'nofollow'        => $link->nofollow,
			'sponsored'       => $link->sponsored,
			'new_tab'         => $link->new_tab,
			'destination_type' => $link->destination_type,
			'rotation_method'  => $link->rotation_method,
			'fallback_url'    => $link->fallback_url,
			'destinations'    => $link->is_multi_destination()
				? array_map(
					static fn( \QuickLinkQRPro\Models\Destination $d ): array => array(
						'id'              => $d->id,
						'destination_url' => $d->destination_url,
						'weight'          => $d->weight,
						'status'          => $d->status,
						'clicks'          => $d->clicks,
					),
					( new \QuickLinkQRPro\Database\DestinationsRepository() )->find_by_link( $link->id )
				)
				: array(),
			'targeting_rules' => $link->has_targeting_rules
				? array_map(
					static fn( \QuickLinkQRPro\Models\TargetingRule $r ): array => array(
						'id'              => $r->id,
						'rule_type'       => $r->rule_type,
						'match_value'     => $r->match_value,
						'destination_url' => $r->destination_url,
						'status'          => $r->status,
					),
					( new \QuickLinkQRPro\Database\TargetingRulesRepository() )->find_by_link( $link->id )
				)
				: array(),
		);
	}
}
