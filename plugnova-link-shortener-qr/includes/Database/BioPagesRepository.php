<?php
/**
 * Data access layer for the wp_qlqr_bio_pages table. All SQL lives here.
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Database;

use QuickLinkQRPro\Models\BioPage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * This file is the data-access layer for the plugin's own custom tables, so two findings are
 * structural here rather than defects, and are declared once at file level instead of being
 * repeated on every query:
 *
 *  - DirectDatabaseQuery.DirectQuery  — custom tables have no WP_Query/get_posts() equivalent;
 *    direct $wpdb access is the only way to read them.
 *  - DirectDatabaseQuery.NoCaching    — these back click/view analytics that must reflect writes
 *    immediately; a stale object-cache read would report wrong numbers.
 *
 * Table names (from Installer::*_table(), i.e. $wpdb->prefix plus a hardcoded suffix — never
 * caller-supplied) and the ORDER BY column are bound through prepare()'s %i identifier
 * placeholder rather than interpolated into the query string, so every query here is genuinely
 * prepared and no PreparedSQL suppression is needed.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Class BioPagesRepository
 */
final class BioPagesRepository implements HasSlugLookup {

	/**
	 * Insert a new bio page and return the created BioPage model.
	 *
	 * @param array<string, mixed> $data Column => value pairs, already validated/sanitized by the caller.
	 * @return BioPage|null
	 */
	public function insert( array $data ): ?BioPage {
		global $wpdb;

		$defaults = array(
			'title'        => '',
			'theme_color'  => '#2271b1',
			'button_style' => 'rounded',
			'status'       => 'active',
			'created_by'   => get_current_user_id(),
			'created_at'   => current_time( 'mysql' ),
			'updated_at'   => current_time( 'mysql' ),
		);

		$row = wp_parse_args( $data, $defaults );

		$inserted = $wpdb->insert( Installer::bio_pages_table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( false === $inserted ) {
			return null;
		}

		return $this->find( (int) $wpdb->insert_id );
	}

	/**
	 * Update an existing bio page by ID.
	 *
	 * @param int                  $id   Bio page ID.
	 * @param array<string, mixed> $data Column => value pairs to update.
	 * @return bool
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		$data['updated_at'] = current_time( 'mysql' );

		$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Installer::bio_pages_table(),
			$data,
			array( 'id' => $id )
		);

		return false !== $result;
	}

	/**
	 * Soft-delete a bio page (moves it to Trash).
	 *
	 * @param int $id Bio page ID.
	 * @return bool
	 */
	public function trash( int $id ): bool {
		return $this->update( $id, array( 'deleted_at' => current_time( 'mysql' ) ) );
	}

	/**
	 * Restore a soft-deleted bio page from Trash.
	 *
	 * @param int $id Bio page ID.
	 * @return bool
	 */
	public function restore( int $id ): bool {
		global $wpdb;

		$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Installer::bio_pages_table(),
			array(
				'deleted_at' => null,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id )
		);

		return false !== $result;
	}

	/**
	 * Permanently delete a bio page and its buttons, social icons, and captured emails.
	 *
	 * @param int $id Bio page ID.
	 * @return bool
	 */
	public function delete_permanently( int $id ): bool {
		global $wpdb;

		// Same as LinksRepository::delete_permanently(): remove the generated QR image along with
		// the row so it cannot outlive the page it belonged to.
		$bio_page = $this->find( $id );
		if ( $bio_page ) {
			\QuickLinkQRPro\Helpers\QrCodeGenerator::delete_generated( $bio_page->qr_image );
		}

		$wpdb->delete( Installer::bio_links_table(), array( 'bio_page_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Installer::bio_socials_table(), array( 'bio_page_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Installer::bio_emails_table(), array( 'bio_page_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Installer::bio_views_table(), array( 'bio_page_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->delete( Installer::bio_pages_table(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return false !== $result;
	}

	/**
	 * Find a single bio page by its numeric ID.
	 *
	 * @param int $id Bio page ID.
	 * @return BioPage|null
	 */
	public function find( int $id ): ?BioPage {
		global $wpdb;

		$table = Installer::bio_pages_table();

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $table, $id ),
			ARRAY_A
		);

		return $row ? BioPage::from_row( $row ) : null;
	}

	/**
	 * Count of non-trashed bio pages — used to enforce the free plan's Bio Page limit (see
	 * Controllers\BioPageController::create()). Matches LinksRepository::active_count()'s
	 * definition of "active" as simply not-deleted, so a manually disabled bio page still
	 * counts against the limit rather than being a way around it.
	 *
	 * @return int
	 */
	public function active_count(): int {
		global $wpdb;
		$table = Installer::bio_pages_table();

		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(id) FROM %i WHERE deleted_at IS NULL', $table )
		);
	}

	/**
	 * Find a single non-trashed bio page by its slug. Used by the public-facing controller.
	 *
	 * @param string $slug Bio page slug.
	 * @return BioPage|null
	 */
	public function find_by_slug( string $slug ): ?BioPage {
		global $wpdb;

		$table = Installer::bio_pages_table();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE slug = %s AND deleted_at IS NULL LIMIT 1',
				$table,
				$slug
			),
			ARRAY_A
		);

		return $row ? BioPage::from_row( $row ) : null;
	}

	/**
	 * Check whether a slug is already in use (across all bio pages, including trashed).
	 *
	 * @param string   $slug       Slug to check.
	 * @param int|null $exclude_id Optionally exclude a bio page ID (used when editing).
	 * @return bool
	 */
	public function slug_exists( string $slug, ?int $exclude_id = null ): bool {
		global $wpdb;

		$table = Installer::bio_pages_table();

		if ( $exclude_id ) {
			$count = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(id) FROM %i WHERE slug = %s AND id != %d',
					$table,
					$slug,
					$exclude_id
				)
			);
		} else {
			$count = $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(id) FROM %i WHERE slug = %s', $table, $slug )
			);
		}

		return (int) $count > 0;
	}

	/**
	 * Paginated, filterable list of bio pages for the admin list table.
	 *
	 * @param array<string, mixed> $args {
	 *     @type string $search    Free-text search on title/slug.
	 *     @type string $status    active|disabled|trash|all.
	 *     @type string $orderby   Column to order by.
	 *     @type string $order     ASC|DESC.
	 *     @type int    $per_page  Items per page.
	 *     @type int    $paged     Current page (1-indexed).
	 * }
	 * @return array{items: BioPage[], total: int}
	 */
	public function query( array $args = array() ): array {
		global $wpdb;

		$table = Installer::bio_pages_table();

		$defaults = array(
			'search'   => '',
			'status'   => 'all',
			'orderby'  => 'created_at',
			'order'    => 'DESC',
			'per_page' => 20,
			'paged'    => 1,
		);
		$args = wp_parse_args( $args, $defaults );

		$allowed_orderby = array( 'id', 'title', 'created_at', 'updated_at', 'total_views', 'slug' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
		$order           = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';

		$per_page = max( 1, (int) $args['per_page'] );
		$offset   = max( 0, ( (int) $args['paged'] - 1 ) * $per_page );

		$is_trash   = 'trash' === $args['status'];
		$has_status = ! $is_trash && in_array( $args['status'], array( 'active', 'disabled' ), true );
		$has_search = '' !== $args['search'];
		$like       = $has_search ? '%' . $wpdb->esc_like( $args['search'] ) . '%' : '';

		$order_asc = 'ASC' === $order;

		// Each status/search/order-direction combination gets its own complete literal query,
		// written directly at the prepare() call site (never assembled into a variable, and never
		// chosen via a ternary between two literals — PHPCS's PreparedSQL sniff can't statically
		// resolve either), so every placeholder is verifiable. $orderby is bound via %i (validated
		// against the allow-list above); $order can only ever be 'ASC' or 'DESC' as restricted
		// above, so its two possible query texts are both written out rather than interpolated.
		if ( $is_trash && $has_search ) {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(id) FROM %i WHERE deleted_at IS NOT NULL AND (title LIKE %s OR slug LIKE %s)', $table, $like, $like )
			);
			if ( $order_asc ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare( 'SELECT * FROM %i WHERE deleted_at IS NOT NULL AND (title LIKE %s OR slug LIKE %s) ORDER BY %i ASC LIMIT %d OFFSET %d', $table, $like, $like, $orderby, $per_page, $offset ),
					ARRAY_A
				);
			} else {
				$rows = $wpdb->get_results(
					$wpdb->prepare( 'SELECT * FROM %i WHERE deleted_at IS NOT NULL AND (title LIKE %s OR slug LIKE %s) ORDER BY %i DESC LIMIT %d OFFSET %d', $table, $like, $like, $orderby, $per_page, $offset ),
					ARRAY_A
				);
			}
		} elseif ( $is_trash ) {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(id) FROM %i WHERE deleted_at IS NOT NULL', $table )
			);
			if ( $order_asc ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare( 'SELECT * FROM %i WHERE deleted_at IS NOT NULL ORDER BY %i ASC LIMIT %d OFFSET %d', $table, $orderby, $per_page, $offset ),
					ARRAY_A
				);
			} else {
				$rows = $wpdb->get_results(
					$wpdb->prepare( 'SELECT * FROM %i WHERE deleted_at IS NOT NULL ORDER BY %i DESC LIMIT %d OFFSET %d', $table, $orderby, $per_page, $offset ),
					ARRAY_A
				);
			}
		} elseif ( $has_status && $has_search ) {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(id) FROM %i WHERE deleted_at IS NULL AND status = %s AND (title LIKE %s OR slug LIKE %s)', $table, $args['status'], $like, $like )
			);
			if ( $order_asc ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare( 'SELECT * FROM %i WHERE deleted_at IS NULL AND status = %s AND (title LIKE %s OR slug LIKE %s) ORDER BY %i ASC LIMIT %d OFFSET %d', $table, $args['status'], $like, $like, $orderby, $per_page, $offset ),
					ARRAY_A
				);
			} else {
				$rows = $wpdb->get_results(
					$wpdb->prepare( 'SELECT * FROM %i WHERE deleted_at IS NULL AND status = %s AND (title LIKE %s OR slug LIKE %s) ORDER BY %i DESC LIMIT %d OFFSET %d', $table, $args['status'], $like, $like, $orderby, $per_page, $offset ),
					ARRAY_A
				);
			}
		} elseif ( $has_status ) {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(id) FROM %i WHERE deleted_at IS NULL AND status = %s', $table, $args['status'] )
			);
			if ( $order_asc ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare( 'SELECT * FROM %i WHERE deleted_at IS NULL AND status = %s ORDER BY %i ASC LIMIT %d OFFSET %d', $table, $args['status'], $orderby, $per_page, $offset ),
					ARRAY_A
				);
			} else {
				$rows = $wpdb->get_results(
					$wpdb->prepare( 'SELECT * FROM %i WHERE deleted_at IS NULL AND status = %s ORDER BY %i DESC LIMIT %d OFFSET %d', $table, $args['status'], $orderby, $per_page, $offset ),
					ARRAY_A
				);
			}
		} elseif ( $has_search ) {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(id) FROM %i WHERE deleted_at IS NULL AND (title LIKE %s OR slug LIKE %s)', $table, $like, $like )
			);
			if ( $order_asc ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare( 'SELECT * FROM %i WHERE deleted_at IS NULL AND (title LIKE %s OR slug LIKE %s) ORDER BY %i ASC LIMIT %d OFFSET %d', $table, $like, $like, $orderby, $per_page, $offset ),
					ARRAY_A
				);
			} else {
				$rows = $wpdb->get_results(
					$wpdb->prepare( 'SELECT * FROM %i WHERE deleted_at IS NULL AND (title LIKE %s OR slug LIKE %s) ORDER BY %i DESC LIMIT %d OFFSET %d', $table, $like, $like, $orderby, $per_page, $offset ),
					ARRAY_A
				);
			}
		} else {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(id) FROM %i WHERE deleted_at IS NULL', $table )
			);
			if ( $order_asc ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare( 'SELECT * FROM %i WHERE deleted_at IS NULL ORDER BY %i ASC LIMIT %d OFFSET %d', $table, $orderby, $per_page, $offset ),
					ARRAY_A
				);
			} else {
				$rows = $wpdb->get_results(
					$wpdb->prepare( 'SELECT * FROM %i WHERE deleted_at IS NULL ORDER BY %i DESC LIMIT %d OFFSET %d', $table, $orderby, $per_page, $offset ),
					ARRAY_A
				);
			}
		}

		return array(
			'items' => array_map( array( BioPage::class, 'from_row' ), $rows ?: array() ),
			'total' => $total,
		);
	}

	/**
	 * Increment the cached total_views counter on a bio page, and its qr_views counter too if
	 * this particular view arrived via a scanned QR code. Fired on each public page view.
	 *
	 * @param int  $id     Bio page ID.
	 * @param bool $via_qr Whether this view came from the page's QR code (?qlqr_src=qr).
	 */
	public function increment_view_count( int $id, bool $via_qr = false ): void {
		global $wpdb;

		$table = Installer::bio_pages_table();

		// Two literal statements instead of a ternary into a variable, so prepare() receives a
		// string literal and its placeholder can be statically verified.
		if ( $via_qr ) {
			$wpdb->query(
				$wpdb->prepare( 'UPDATE %i SET total_views = total_views + 1, qr_views = qr_views + 1 WHERE id = %d', $table, $id )
			);
		} else {
			$wpdb->query(
				$wpdb->prepare( 'UPDATE %i SET total_views = total_views + 1 WHERE id = %d', $table, $id )
			);
		}
	}
}
