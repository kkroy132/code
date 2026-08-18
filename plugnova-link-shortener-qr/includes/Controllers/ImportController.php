<?php
/**
 * Bulk-creates links from a CSV upload, or migrates them from another link-shortener plugin's
 * own database tables/post type. Every imported row is created through LinkController::create(),
 * so it gets exactly the same slug generation, validation, and QR code generation as a link
 * created by hand — this file only supplies where the input rows come from.
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Controllers;

use QuickLinkQRPro\Database\LinksRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ImportController
 */
final class ImportController {

	/**
	 * Upper bound on rows processed in a single request, so an oversized file/migration source
	 * can't run past typical shared-hosting execution limits. Importing more than this means
	 * running the import again — already-imported URLs are skipped via the dedupe check.
	 */
	private const MAX_ROWS_PER_RUN = 500;

	/**
	 * Links repository.
	 *
	 * @var LinksRepository
	 */
	private LinksRepository $repository;

	/**
	 * Link creation orchestrator, reused so imported rows get identical validation/QR generation.
	 *
	 * @var LinkController
	 */
	private LinkController $link_controller;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->repository      = new LinksRepository();
		$this->link_controller = new LinkController();
	}

	/**
	 * Import links from an uploaded CSV file. Expected columns (header row required, matched
	 * case-insensitively, any order): destination_url (required), title, custom_slug, category.
	 * Unrecognized columns are ignored.
	 *
	 * @param string $tmp_path        Path to the uploaded file (e.g. $_FILES[...]['tmp_name']).
	 * @param bool   $skip_duplicates Skip rows whose destination_url already exists as a link.
	 * @return array{success:bool, imported:int, skipped:int, limit_reached?:bool, upgrade_url?:string, errors:string[]}
	 */
	public function import_csv( string $tmp_path, bool $skip_duplicates ): array {
		if ( ! is_uploaded_file( $tmp_path ) ) {
			return array(
				'success'  => false,
				'imported' => 0,
				'skipped'  => 0,
				'errors'   => array( __( 'Invalid file upload.', 'plugnova-link-shortener-qr' ) ),
			);
		}

		$handle = fopen( $tmp_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return array(
				'success'  => false,
				'imported' => 0,
				'skipped'  => 0,
				'errors'   => array( __( 'Could not read the uploaded file.', 'plugnova-link-shortener-qr' ) ),
			);
		}

		$header = fgetcsv( $handle );
		if ( false === $header ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return array(
				'success'  => false,
				'imported' => 0,
				'skipped'  => 0,
				'errors'   => array( __( 'The CSV file appears to be empty.', 'plugnova-link-shortener-qr' ) ),
			);
		}

		// Map column name (lowercased, trimmed) => index, so the file's column order doesn't matter.
		$columns = array();
		foreach ( $header as $index => $name ) {
			$columns[ strtolower( trim( (string) $name ) ) ] = $index;
		}

		if ( ! isset( $columns['destination_url'] ) && ! isset( $columns['destination url'] ) && ! isset( $columns['url'] ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return array(
				'success'  => false,
				'imported' => 0,
				'skipped'  => 0,
				'errors'   => array( __( 'The CSV must have a "destination_url" (or "URL") column.', 'plugnova-link-shortener-qr' ) ),
			);
		}

		$url_col      = $columns['destination_url'] ?? $columns['destination url'] ?? $columns['url'];
		$title_col    = $columns['title'] ?? null;
		$slug_col     = $columns['custom_slug'] ?? $columns['slug'] ?? null;
		$category_col = $columns['category'] ?? null;

		$rows = array();
		while ( false !== ( $line = fgetcsv( $handle ) ) && count( $rows ) < self::MAX_ROWS_PER_RUN ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			$url = trim( (string) ( $line[ $url_col ] ?? '' ) );
			if ( '' === $url ) {
				continue;
			}

			$rows[] = array(
				'destination_url' => $url,
				'title'           => null !== $title_col ? trim( (string) ( $line[ $title_col ] ?? '' ) ) : '',
				'custom_slug'     => null !== $slug_col ? trim( (string) ( $line[ $slug_col ] ?? '' ) ) : '',
				'category'        => null !== $category_col ? trim( (string) ( $line[ $category_col ] ?? '' ) ) : '',
			);
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $this->import_rows( $rows, $skip_duplicates );
	}

	/**
	 * Migrate links from Pretty Links (wp_prli_links). Best-effort: Pretty Links' schema has
	 * varied slightly across versions, so column reads fall back gracefully rather than erroring
	 * the whole import if one optional column is missing.
	 *
	 * @param bool $skip_duplicates Skip rows whose destination_url already exists as a link.
	 * @return array{success:bool, imported:int, skipped:int, limit_reached?:bool, upgrade_url?:string, errors:string[]}
	 */
	public function import_from_pretty_links( bool $skip_duplicates ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'prli_links';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) { // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders,WordPress.DB.DirectDatabaseQuery
			return array(
				'success'  => false,
				'imported' => 0,
				'skipped'  => 0,
				'errors'   => array( __( 'No Pretty Links data was found on this site (the wp_prli_links table doesn\'t exist).', 'plugnova-link-shortener-qr' ) ),
			);
		}

		$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY id ASC LIMIT %d', $table, self::MAX_ROWS_PER_RUN ),
			ARRAY_A
		);

		$rows = array();
		foreach ( (array) $results as $record ) {
			// Column names observed across Pretty Links releases: "url" holds the destination,
			// "slug" the short slug, "name"/"title" the display title.
			$url = trim( (string) ( $record['url'] ?? $record['target_url'] ?? '' ) );
			if ( '' === $url ) {
				continue;
			}

			$rows[] = array(
				'destination_url' => $url,
				'title'           => trim( (string) ( $record['name'] ?? $record['title'] ?? '' ) ),
				'custom_slug'     => '', // Pretty Links slugs aren't reused: this plugin's slug rules/reserved-word list differ, so a fresh unique slug is generated instead.
				'category'        => __( 'Imported from Pretty Links', 'plugnova-link-shortener-qr' ),
			);
		}

		return $this->import_rows( $rows, $skip_duplicates );
	}

	/**
	 * Migrate links from ThirstyAffiliates (the "thirstylink" custom post type). Best-effort for
	 * the same reason as import_from_pretty_links().
	 *
	 * @param bool $skip_duplicates Skip rows whose destination_url already exists as a link.
	 * @return array{success:bool, imported:int, skipped:int, limit_reached?:bool, upgrade_url?:string, errors:string[]}
	 */
	public function import_from_thirsty_affiliates( bool $skip_duplicates ): array {
		$query = new \WP_Query(
			array(
				'post_type'      => 'thirstylink',
				'post_status'    => 'publish',
				'posts_per_page' => self::MAX_ROWS_PER_RUN,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		if ( ! $query->have_posts() ) {
			return array(
				'success'  => false,
				'imported' => 0,
				'skipped'  => 0,
				'errors'   => array( __( 'No ThirstyAffiliates links were found on this site.', 'plugnova-link-shortener-qr' ) ),
			);
		}

		$rows = array();
		foreach ( $query->posts as $post ) {
			// The target-URL meta key has been "_thirstylink_target_url" since ThirstyAffiliates
			// introduced custom URLs; older/forked versions have used "_thirsty_target_url".
			$url = get_post_meta( $post->ID, '_thirstylink_target_url', true );
			if ( '' === $url ) {
				$url = get_post_meta( $post->ID, '_thirsty_target_url', true );
			}
			$url = trim( (string) $url );

			if ( '' === $url ) {
				continue;
			}

			$rows[] = array(
				'destination_url' => $url,
				'title'           => trim( (string) $post->post_title ),
				'custom_slug'     => trim( (string) $post->post_name ),
				'category'        => __( 'Imported from ThirstyAffiliates', 'plugnova-link-shortener-qr' ),
			);
		}

		return $this->import_rows( $rows, $skip_duplicates );
	}

	/**
	 * Detect which migration sources have data on this site, for the admin UI to only offer
	 * relevant options.
	 *
	 * @return array{pretty_links:int, thirsty_affiliates:int}
	 */
	public function detect_sources(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'prli_links';

		$pretty_links_count = 0;
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ) { // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders,WordPress.DB.DirectDatabaseQuery
			$pretty_links_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(id) FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		$thirsty_count = (int) ( wp_count_posts( 'thirstylink' )->publish ?? 0 );

		return array(
			'pretty_links'        => $pretty_links_count,
			'thirsty_affiliates'  => $thirsty_count,
		);
	}

	/**
	 * Shared import loop: create each row as a link, optionally skipping ones whose destination
	 * URL already exists. Empty/malformed rows are rejected by LinkController::create() itself
	 * (same URL validation as the admin form), and reported per-row rather than aborting the batch.
	 *
	 * @param array<int, array{destination_url:string, title:string, custom_slug:string, category:string}> $rows
	 * @param bool $skip_duplicates Skip rows whose destination_url already exists as a link.
	 * @return array{success:bool, imported:int, skipped:int, limit_reached?:bool, upgrade_url?:string, errors:string[]}
	 */
	private function import_rows( array $rows, bool $skip_duplicates ): array {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- guarded by function_exists() and silenced; raising the ceiling is the point, and hosts that forbid it are unaffected, WordPress.PHP.NoSilencedErrors -- best-effort; some hosts disable this entirely.
		}

		$imported     = 0;
		$skipped      = 0;
		$errors       = array();
		$limit_result = null;

		foreach ( $rows as $row ) {
			if ( $skip_duplicates && $this->repository->url_exists( $row['destination_url'] ) ) {
				++$skipped;
				continue;
			}

			$input = array(
				'destination_url' => $row['destination_url'],
				'title'           => $row['title'],
				'custom_slug'     => $row['custom_slug'],
				'category'        => $row['category'],
			);

			// A custom slug carried over from another plugin/CSV row might collide with an
			// existing link here; fall back to an auto-generated slug rather than failing the
			// whole row over a slug clash the admin likely doesn't care about.
			$result = $this->link_controller->create( $input );
			if ( ! $result['success'] && isset( $result['upgrade_url'] ) ) {
				// The free-plan link limit, not a per-row problem: every remaining row would fail
				// identically, so stop attempting them instead of calling create() (and its
				// active_count() query) once per remaining row. Reported once below, not per-row.
				$limit_result = $result;
				break;
			}
			if ( ! $result['success'] && '' !== $input['custom_slug'] ) {
				$input['custom_slug'] = '';
				$result                = $this->link_controller->create( $input );
			}

			if ( $result['success'] ) {
				++$imported;
			} else {
				$errors[] = sprintf(
					/* translators: 1: destination URL, 2: error message */
					__( '%1$s — %2$s', 'plugnova-link-shortener-qr' ),
					$row['destination_url'],
					$result['error']
				);
			}
		}

		if ( null !== $limit_result ) {
			$errors[] = $limit_result['error'];
		}

		$response = array(
			'success'  => true,
			'imported' => $imported,
			'skipped'  => $skipped,
			// Cap the returned error list so a bad file with thousands of malformed rows doesn't
			// blow up the JSON response; the counts above still reflect the true totals.
			'errors'   => array_slice( $errors, 0, 20 ),
		);

		if ( null !== $limit_result ) {
			$response['limit_reached'] = true;
			$response['upgrade_url']   = $limit_result['upgrade_url'];
		}

		return $response;
	}
}
