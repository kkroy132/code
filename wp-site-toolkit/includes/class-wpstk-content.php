<?php
/**
 * Content queries shared by several audit modules.
 *
 * @package WP_Site_Toolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Batched access to published content plus a few URL helpers.
 *
 * @since 1.0.0
 */
class WPSTK_Content {

	/**
	 * Runtime cache of the audited post types.
	 *
	 * @var string[]|null
	 */
	private static $post_types = null;

	/**
	 * Returns the public post types that are audited.
	 *
	 * @return string[]
	 */
	public static function get_post_types() {
		if ( null !== self::$post_types ) {
			return self::$post_types;
		}

		$types = get_post_types(
			array(
				'public' => true,
			),
			'names'
		);

		unset( $types['attachment'] );

		/**
		 * Filters the post types included in content audits.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $types Post type names.
		 */
		$types = apply_filters( 'wpstk_audited_post_types', array_values( $types ) );

		$types = array_values( array_filter( array_map( 'strval', (array) $types ) ) );

		if ( empty( $types ) ) {
			$types = array( 'post', 'page' );
		}

		self::$post_types = $types;

		return self::$post_types;
	}

	/**
	 * Counts the published entries that will be audited.
	 *
	 * @return int
	 */
	public static function count_posts() {
		global $wpdb;

		$types        = self::get_post_types();
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders only; values are bound below.
		$sql = "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$placeholders})";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $types ) );
	}

	/**
	 * Returns a batch of published entries ordered by ID.
	 *
	 * @param int $offset Zero based offset.
	 * @param int $limit  Batch size.
	 *
	 * @return array[] Rows with ID, post_title, post_content, post_excerpt, post_type and post_name.
	 */
	public static function get_post_batch( $offset, $limit ) {
		global $wpdb;

		$types        = self::get_post_types();
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$args         = array_merge( $types, array( (int) $limit, (int) $offset ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders only; values are bound below.
		$sql = "SELECT ID, post_title, post_content, post_excerpt, post_type, post_name, post_date
			FROM {$wpdb->posts}
			WHERE post_status = 'publish' AND post_type IN ({$placeholders})
			ORDER BY ID ASC
			LIMIT %d OFFSET %d";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Counts image attachments.
	 *
	 * @return int
	 */
	public static function count_image_attachments() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate over the posts table.
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'"
		);
	}

	/**
	 * Returns a batch of image attachments ordered by ID.
	 *
	 * @param int $offset Zero based offset.
	 * @param int $limit  Batch size.
	 *
	 * @return array[]
	 */
	public static function get_image_batch( $offset, $limit ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared with bound values.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_parent, post_mime_type
				FROM {$wpdb->posts}
				WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%%'
				ORDER BY ID ASC
				LIMIT %d OFFSET %d",
				(int) $limit,
				(int) $offset
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Returns the meta keys used by popular SEO plugins for the browser title.
	 *
	 * @return string[]
	 */
	public static function title_meta_keys() {
		return array( '_yoast_wpseo_title', 'rank_math_title', '_seopress_titles_title', '_aioseo_title' );
	}

	/**
	 * Returns the meta keys used by popular SEO plugins for the meta description.
	 *
	 * @return string[]
	 */
	public static function description_meta_keys() {
		return array( '_yoast_wpseo_metadesc', 'rank_math_description', '_seopress_titles_desc', '_aioseo_description' );
	}

	/**
	 * Loads SEO related meta for a set of post IDs in one query.
	 *
	 * @param int[] $post_ids Post IDs.
	 *
	 * @return array Map of post ID to meta key/value pairs.
	 */
	public static function get_seo_meta( $post_ids ) {
		global $wpdb;

		$post_ids = array_values( array_unique( array_map( 'intval', (array) $post_ids ) ) );
		$out      = array();

		if ( empty( $post_ids ) ) {
			return $out;
		}

		$keys = array_merge( self::title_meta_keys(), self::description_meta_keys(), array( '_thumbnail_id' ) );

		$id_placeholders  = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
		$key_placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders only; values are bound below.
		$sql = "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
			WHERE post_id IN ({$id_placeholders}) AND meta_key IN ({$key_placeholders})";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $post_ids, $keys ) ), ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return $out;
		}

		foreach ( $rows as $row ) {
			$out[ (int) $row['post_id'] ][ $row['meta_key'] ] = (string) $row['meta_value'];
		}

		return $out;
	}

	/**
	 * Picks the effective browser title for a post.
	 *
	 * @param array $post Post row.
	 * @param array $meta Meta values for the post.
	 *
	 * @return array {
	 *     @type string $value  Resolved title.
	 *     @type string $source `seo_plugin` or `post_title`.
	 * }
	 */
	public static function resolve_title( $post, $meta ) {
		foreach ( self::title_meta_keys() as $key ) {
			if ( ! empty( $meta[ $key ] ) ) {
				return array(
					'value'  => self::expand_placeholders( $meta[ $key ], $post ),
					'source' => 'seo_plugin',
				);
			}
		}

		return array(
			'value'  => isset( $post['post_title'] ) ? (string) $post['post_title'] : '',
			'source' => 'post_title',
		);
	}

	/**
	 * Picks the effective meta description for a post.
	 *
	 * @param array $post Post row.
	 * @param array $meta Meta values for the post.
	 *
	 * @return array {
	 *     @type string $value  Resolved description.
	 *     @type string $source `seo_plugin`, `excerpt` or `none`.
	 * }
	 */
	public static function resolve_description( $post, $meta ) {
		foreach ( self::description_meta_keys() as $key ) {
			if ( ! empty( $meta[ $key ] ) ) {
				return array(
					'value'  => self::expand_placeholders( $meta[ $key ], $post ),
					'source' => 'seo_plugin',
				);
			}
		}

		$excerpt = isset( $post['post_excerpt'] ) ? trim( (string) $post['post_excerpt'] ) : '';

		if ( '' !== $excerpt ) {
			return array(
				'value'  => WPSTK_HTML::clean_text( $excerpt ),
				'source' => 'excerpt',
			);
		}

		return array(
			'value'  => '',
			'source' => 'none',
		);
	}

	/**
	 * Replaces the most common SEO plugin placeholders so lengths are realistic.
	 *
	 * Unknown placeholders are removed rather than guessed at.
	 *
	 * @param string $text Template string.
	 * @param array  $post Post row.
	 *
	 * @return string
	 */
	public static function expand_placeholders( $text, $post ) {
		$title = isset( $post['post_title'] ) ? (string) $post['post_title'] : '';

		$replacements = array(
			'%%title%%'     => $title,
			'%%sitename%%'  => get_bloginfo( 'name' ),
			'%%sitedesc%%'  => get_bloginfo( 'description' ),
			'%%page%%'      => '',
			'%%sep%%'       => '-',
			'%title%'       => $title,
			'%sitename%'    => get_bloginfo( 'name' ),
			'%sitedesc%'    => get_bloginfo( 'description' ),
			'%sep%'         => '-',
			'%%excerpt%%'   => isset( $post['post_excerpt'] ) ? (string) $post['post_excerpt'] : '',
			'%%currentyear%%' => gmdate( 'Y' ),
		);

		$text = strtr( (string) $text, $replacements );
		$text = preg_replace( '#%%?[a-z0-9_-]+%%?#i', '', $text );

		return WPSTK_HTML::clean_text( (string) $text );
	}

	/**
	 * Returns the site home URL without a trailing slash.
	 *
	 * @return string
	 */
	public static function home_url() {
		return untrailingslashit( home_url() );
	}

	/**
	 * Returns the site host name.
	 *
	 * @return string
	 */
	public static function home_host() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		return $host ? strtolower( $host ) : '';
	}

	/**
	 * Whether a URL points at this site.
	 *
	 * @param string $url Absolute URL.
	 *
	 * @return bool
	 */
	public static function is_internal_url( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! $host ) {
			return false;
		}

		$host      = strtolower( $host );
		$home_host = self::home_host();

		return $host === $home_host || 'www.' . $home_host === $host || 'www.' . $host === $home_host;
	}

	/**
	 * Whether a raw href should be skipped by the link scanner.
	 *
	 * @param string $href Raw href value.
	 *
	 * @return bool
	 */
	public static function is_skippable_href( $href ) {
		$href = trim( (string) $href );

		if ( '' === $href || '#' === $href[0] ) {
			return true;
		}

		return (bool) preg_match( '~^(mailto:|tel:|sms:|javascript:|data:|ftp:|ftps:|callto:|skype:|whatsapp:)~i', $href );
	}

	/**
	 * Truncates a string for display without cutting mid-entity.
	 *
	 * @param string $text   Text to shorten.
	 * @param int    $length Maximum length.
	 *
	 * @return string
	 */
	public static function shorten( $text, $length = 90 ) {
		$text = (string) $text;

		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > $length ) {
			return mb_substr( $text, 0, $length - 1 ) . '…';
		}

		if ( strlen( $text ) > $length ) {
			return substr( $text, 0, $length - 1 ) . '…';
		}

		return $text;
	}

	/**
	 * Returns the character length of a string.
	 *
	 * @param string $text Text.
	 *
	 * @return int
	 */
	public static function length( $text ) {
		$text = (string) $text;

		return function_exists( 'mb_strlen' ) ? (int) mb_strlen( $text ) : strlen( $text );
	}
}
