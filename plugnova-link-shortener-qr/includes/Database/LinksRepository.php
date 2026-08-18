<?php
/**
 * Data access layer for the wp_qlqr_links table. All SQL lives here.
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Database;

use QuickLinkQRPro\Models\Link;

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
 * caller-supplied) and the ORDER BY column in query() are bound through prepare()'s %i
 * identifier placeholder rather than interpolated into the query string, so every query here is
 * genuinely prepared and no PreparedSQL suppression is needed — except query()'s WHERE clause and
 * the "ids" IN (...) list, whose combined placeholder count is inherently dynamic (any subset of
 * up to nine independent optional filters, plus one %d per caller-supplied ID) and so cannot be
 * statically counted by PHPCS; every value there is still bound through prepare() and no
 * identifier or caller value is ever concatenated into the SQL text itself, so a narrowly-scoped
 * PreparedSQLPlaceholders ignore stays on those two calls, explaining exactly why.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Class LinksRepository
 */
final class LinksRepository implements HasSlugLookup {

	/**
	 * Insert a new link and return the created Link model.
	 *
	 * @param array<string, mixed> $data Column => value pairs, already validated/sanitized by the caller.
	 * @return Link|null
	 */
	public function insert( array $data ): ?Link {
		global $wpdb;

		$defaults = array(
			'title'           => '',
			'destination_url' => '',
			'short_slug'      => '',
			'qr_style'        => 'square',
			'qr_fg_color'     => '#000000',
			'qr_bg_color'     => '#ffffff',
			'status'          => 'active',
			'redirect_type'   => 301,
			'new_tab'         => 1,
			'created_by'      => get_current_user_id(),
			'created_at'      => current_time( 'mysql' ),
			'updated_at'      => current_time( 'mysql' ),
		);

		$row = wp_parse_args( $data, $defaults );

		$inserted = $wpdb->insert( Installer::links_table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( false === $inserted ) {
			return null;
		}

		return $this->find( (int) $wpdb->insert_id );
	}

	/**
	 * Update an existing link by ID.
	 *
	 * @param int                   $id   Link ID.
	 * @param array<string, mixed>  $data Column => value pairs to update.
	 * @return bool
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		$data['updated_at'] = current_time( 'mysql' );

		$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Installer::links_table(),
			$data,
			array( 'id' => $id )
		);

		return false !== $result;
	}

	/**
	 * Soft-delete a link (moves it to Trash).
	 *
	 * @param int $id Link ID.
	 * @return bool
	 */
	public function trash( int $id ): bool {
		return $this->update( $id, array( 'deleted_at' => current_time( 'mysql' ) ) );
	}

	/**
	 * Restore a soft-deleted link from Trash.
	 *
	 * @param int $id Link ID.
	 * @return bool
	 */
	public function restore( int $id ): bool {
		global $wpdb;

		$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Installer::links_table(),
			array(
				'deleted_at' => null,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id )
		);

		return false !== $result;
	}

	/**
	 * Permanently delete a link and its click records.
	 *
	 * @param int $id Link ID.
	 * @return bool
	 */
	public function delete_permanently( int $id ): bool {
		global $wpdb;

		// Take the generated QR image with the row, otherwise deleting a link leaves its PNG/SVG
		// behind in uploads with nothing left pointing at it.
		$link = $this->find( $id );
		if ( $link ) {
			\QuickLinkQRPro\Helpers\QrCodeGenerator::delete_generated( $link->qr_image );
		}

		$wpdb->delete( Installer::clicks_table(), array( 'link_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Installer::destinations_table(), array( 'link_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Installer::targeting_rules_table(), array( 'link_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Installer::keywords_table(), array( 'link_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->delete( Installer::links_table(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return false !== $result;
	}

	/**
	 * Find a single link by its numeric ID.
	 *
	 * @param int $id Link ID.
	 * @return Link|null
	 */
	public function find( int $id ): ?Link {
		global $wpdb;

		$table = Installer::links_table();

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $table, $id ),
			ARRAY_A
		);

		return $row ? Link::from_row( $row ) : null;
	}

	/**
	 * Find a single active, non-expired link by its short slug. Used by the redirect controller.
	 *
	 * @param string $slug Short slug.
	 * @return Link|null
	 */
	public function find_by_slug( string $slug ): ?Link {
		global $wpdb;

		$table = Installer::links_table();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE short_slug = %s AND deleted_at IS NULL LIMIT 1',
				$table,
				$slug
			),
			ARRAY_A
		);

		return $row ? Link::from_row( $row ) : null;
	}

	/**
	 * Check whether a slug is already in use (across all links, including trashed).
	 *
	 * @param string   $slug       Slug to check.
	 * @param int|null $exclude_id Optionally exclude a link ID (used when editing).
	 * @return bool
	 */
	public function slug_exists( string $slug, ?int $exclude_id = null ): bool {
		global $wpdb;

		$table = Installer::links_table();

		if ( $exclude_id ) {
			$count = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(id) FROM %i WHERE short_slug = %s AND id != %d',
					$table,
					$slug,
					$exclude_id
				)
			);
		} else {
			$count = $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(id) FROM %i WHERE short_slug = %s', $table, $slug )
			);
		}

		return (int) $count > 0;
	}

	/**
	 * Whether a non-trashed link with this exact destination_url already exists. Used by
	 * Controllers\ImportController to optionally skip duplicates during bulk import/migration.
	 *
	 * @param string $url Destination URL to check (exact match).
	 * @return bool
	 */
	public function url_exists( string $url ): bool {
		global $wpdb;
		$table = Installer::links_table();

		$count = $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(id) FROM %i WHERE destination_url = %s AND deleted_at IS NULL', $table, $url )
		);

		return (int) $count > 0;
	}

	/**
	 * Paginated, filterable list of links for the admin list table.
	 *
	 * @param array<string, mixed> $args {
	 *     @type string $search       Free-text search on title/destination/slug.
	 *     @type string $status       active|disabled|expired|trash|all.
	 *     @type string $category     Exact category match, or '' for all categories.
	 *     @type string $link_status  broken|unknown|ok|all — filters on the last broken-link check result.
	 *     @type string $date_from    'Y-m-d' — only links created on/after this date, or '' for no lower bound.
	 *     @type string $date_to      'Y-m-d' — only links created on/before this date, or '' for no upper bound.
	 *     @type bool   $favorite_only Only favorited links.
	 *     @type string $color_label  Exact color_label hex match, or '' for any.
	 *     @type string $tag          Substring match against the tags column, or '' for any.
	 *     @type int[]  $ids          Restrict to these specific link IDs, or empty for no restriction.
	 *     @type string $orderby      Column to order by.
	 *     @type string $order        ASC|DESC.
	 *     @type int    $per_page     Items per page.
	 *     @type int    $paged        Current page (1-indexed).
	 * }
	 * @return array{items: Link[], total: int}
	 */
	public function query( array $args = array() ): array {
		global $wpdb;

		$table = Installer::links_table();

		$defaults = array(
			'search'        => '',
			'status'        => 'all',
			'category'      => '',
			'link_status'   => 'all',
			'date_from'     => '',
			'date_to'       => '',
			'favorite_only' => false,
			'color_label'   => '',
			'tag'           => '',
			'ids'           => array(),
			'orderby'       => 'created_at',
			'order'         => 'DESC',
			'per_page'      => 20,
			'paged'         => 1,
		);
		$args = wp_parse_args( $args, $defaults );

		$where  = array();
		$params = array();

		if ( 'trash' === $args['status'] ) {
			$where[] = 'deleted_at IS NOT NULL';
		} else {
			$where[] = 'deleted_at IS NULL';
			if ( in_array( $args['status'], array( 'active', 'disabled' ), true ) ) {
				$where[]  = 'status = %s';
				$params[] = $args['status'];
			}
		}

		if ( '' !== $args['category'] ) {
			$where[]  = 'category = %s';
			$params[] = $args['category'];
		}

		if ( in_array( $args['link_status'], array( 'broken', 'unknown', 'ok' ), true ) ) {
			$where[]  = 'link_status = %s';
			$params[] = $args['link_status'];
		}

		if ( '' !== $args['date_from'] ) {
			$where[]  = 'created_at >= %s';
			$params[] = $args['date_from'] . ' 00:00:00';
		}

		if ( '' !== $args['date_to'] ) {
			$where[]  = 'created_at <= %s';
			$params[] = $args['date_to'] . ' 23:59:59';
		}

		if ( ! empty( $args['favorite_only'] ) ) {
			$where[] = 'is_favorite = 1';
		}

		if ( '' !== $args['color_label'] ) {
			$where[]  = 'color_label = %s';
			$params[] = $args['color_label'];
		}

		if ( '' !== $args['tag'] ) {
			$where[]  = 'tags LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $args['tag'] ) . '%';
		}

		$ids = array_values( array_filter( array_map( 'intval', (array) $args['ids'] ), static fn( int $id ): bool => $id > 0 ) );
		if ( ! empty( $ids ) ) {
			// A generated run of "%d" markers — one per ID — not data; every actual ID is bound
			// through those placeholders by prepare() below, same as ClicksRepository's *_multi() lookups.
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$where[]      = "id IN ({$placeholders})";
			$params       = array_merge( $params, $ids );
		}

		if ( '' !== $args['search'] ) {
			$where[]  = '(title LIKE %s OR destination_url LIKE %s OR short_slug LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$allowed_orderby = array( 'id', 'title', 'created_at', 'updated_at', 'total_clicks', 'short_slug' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
		$order           = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';

		$where_sql = implode( ' AND ', $where );
		$per_page  = max( 1, (int) $args['per_page'] );
		$offset    = max( 0, ( (int) $args['paged'] - 1 ) * $per_page );

		/*
		 * $where_sql is assembled from the fixed clause fragments above — each one a hardcoded
		 * string with %s/%d placeholders, never an identifier or caller value — chosen from a set of
		 * up to nine independent optional filters plus a variable-length "ids" IN (...) list. That
		 * combinatorial range of shapes is exactly what makes writing this out as one literal per
		 * combination (as the smaller, fixed-shape repositories in this directory do) infeasible
		 * here, so PHPCS cannot statically count the placeholders. $table and $orderby are still
		 * bound identifiers via %i (never interpolated), $order is restricted to the 'ASC'/'DESC'
		 * allow-list above, and every value in $params is bound by prepare() below — nothing but
		 * these hardcoded fragments and %i/%s/%d placeholders ever reaches the SQL text.
		 */
		$count_sql = "SELECT COUNT(id) FROM %i WHERE {$where_sql}";
		$total     = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, array_merge( array( $table ), $params ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$list_sql    = "SELECT * FROM %i WHERE {$where_sql} ORDER BY %i {$order} LIMIT %d OFFSET %d";
		$list_params = array_merge( array( $table ), $params, array( $orderby ), array( $per_page, $offset ) );
		$rows        = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return array(
			'items' => array_map( array( Link::class, 'from_row' ), $rows ?: array() ),
			'total' => $total,
		);
	}

	/**
	 * Increment the cached total_clicks counter on a link. Fired asynchronously after each redirect.
	 *
	 * @param int $id Link ID.
	 */
	public function increment_click_count( int $id ): void {
		global $wpdb;

		$table = Installer::links_table();
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET total_clicks = total_clicks + 1 WHERE id = %d', $table, $id ) );
	}

	/**
	 * Get aggregate dashboard counters in a single pass.
	 *
	 * @return array{total_links:int, total_clicks:int, today_clicks:int, qr_generated:int}
	 */
	public function dashboard_counters(): array {
		global $wpdb;

		$links_table  = Installer::links_table();
		$clicks_table = Installer::clicks_table();

		$total_links   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(id) FROM %i WHERE deleted_at IS NULL', $links_table ) );
		$total_clicks  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(id) FROM %i WHERE is_bot = 0', $clicks_table ) );
		$unique_clicks = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(id) FROM %i WHERE is_bot = 0 AND is_unique = 1', $clicks_table ) );
		$bot_clicks    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(id) FROM %i WHERE is_bot = 1', $clicks_table ) );
		$today_clicks  = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(id) FROM %i WHERE DATE(clicked_at) = %s AND is_bot = 0', $clicks_table, current_time( 'Y-m-d' ) )
		);
		$qr_generated = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(id) FROM %i WHERE qr_image IS NOT NULL AND deleted_at IS NULL', $links_table ) );
		$qr_scans     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(id) FROM %i WHERE is_bot = 0 AND source = 'qr'", $clicks_table ) );
		$broken_links = $this->broken_count();

		return array(
			'total_links'   => $total_links,
			'total_clicks'  => $total_clicks,
			'unique_clicks' => $unique_clicks,
			'bot_clicks'    => $bot_clicks,
			'today_clicks'  => $today_clicks,
			'qr_generated'  => $qr_generated,
			'qr_scans'      => $qr_scans,
			'broken_links'  => $broken_links,
		);
	}

	/**
	 * Top performing links ordered by total click count.
	 *
	 * @param int $limit Number of links to return.
	 * @return Link[]
	 */
	public function top_links( int $limit = 5 ): array {
		global $wpdb;
		$table = Installer::links_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE deleted_at IS NULL ORDER BY total_clicks DESC LIMIT %d', $table, $limit ),
			ARRAY_A
		);

		return array_map( array( Link::class, 'from_row' ), $rows ?: array() );
	}

	/**
	 * Distinct, non-empty category names currently in use, alphabetically sorted. Powers the
	 * category filter dropdown and the create/edit modal's autocomplete list.
	 *
	 * @return string[]
	 */
	public function distinct_categories(): array {
		global $wpdb;
		$table = Installer::links_table();

		$rows = $wpdb->get_col(
			$wpdb->prepare( "SELECT DISTINCT category FROM %i WHERE category IS NOT NULL AND category != '' AND deleted_at IS NULL ORDER BY category ASC", $table )
		);

		return $rows ?: array();
	}

	/**
	 * All active, non-trashed, single-destination links — the set Helpers\BrokenLinkChecker
	 * iterates over. Multi-destination links are excluded: their redirect traffic never reads
	 * destination_url once destination_type is "multiple" (see LinkController::create()), so
	 * checking it would just report false positives against a URL nothing actually uses.
	 *
	 * @return Link[]
	 */
	public function find_all_checkable(): array {
		global $wpdb;
		$table = Installer::links_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM %i WHERE deleted_at IS NULL AND destination_type = 'single'", $table ),
			ARRAY_A
		);

		return array_map( array( Link::class, 'from_row' ), $rows ?: array() );
	}

	/**
	 * Persist the result of a broken-link check for one link.
	 *
	 * @param int                  $id     Link ID.
	 * @param array<string, mixed> $result {
	 *     Shape returned by Helpers\BrokenLinkChecker::check_link().
	 *
	 *     @type string    $status            ok|broken.
	 *     @type int|null  $http_status       Observed HTTP status code, or null if the request itself failed.
	 *     @type int|null  $response_time_ms  Observed response time in milliseconds, or null if unavailable.
	 *     @type string|null $meta_title      Destination page's &lt;title&gt; tag, or null if unavailable.
	 *     @type bool      $has_redirect_loop Whether the check hit a redirect-limit error.
	 * }
	 */
	public function record_check_result( int $id, array $result ): void {
		$this->update(
			$id,
			array(
				'link_status'       => 'broken' === ( $result['status'] ?? 'broken' ) ? 'broken' : 'ok',
				'http_status'       => $result['http_status'] ?? null,
				'response_time_ms'  => $result['response_time_ms'] ?? null,
				'meta_title'        => $result['meta_title'] ?? null,
				'has_redirect_loop' => ! empty( $result['has_redirect_loop'] ) ? 1 : 0,
				'last_checked_at'   => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Count of non-trashed links — used to enforce the free plan's link limit (see
	 * Controllers\LinkController::create()/clone_link()). Matches dashboard_counters()'s
	 * total_links definition of "active" as simply not-deleted, so a manually disabled link
	 * still counts against the limit rather than being a way around it.
	 *
	 * @return int
	 */
	public function active_count(): int {
		global $wpdb;
		$table = Installer::links_table();

		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(id) FROM %i WHERE deleted_at IS NULL', $table )
		);
	}

	/**
	 * Count of currently broken, active, non-trashed links — used for the Dashboard notice.
	 *
	 * @return int
	 */
	public function broken_count(): int {
		global $wpdb;
		$table = Installer::links_table();

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(id) FROM %i WHERE link_status = 'broken' AND deleted_at IS NULL", $table )
		);
	}
}
