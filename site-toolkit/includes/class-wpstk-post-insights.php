<?php
/**
 * Per-post SEO signals shown in the post list and editor.
 *
 * @package WP_Site_Toolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Surfaces a handful of the SEO module's checks on the screens editors
 * actually work in, so a finding does not wait for the next full audit to be
 * noticed.
 *
 * Every signal here is computed from data already loaded for the current
 * post: no HTTP requests, no extra queries beyond the SEO meta lookup the
 * SEO module already uses. It intentionally covers only title length, meta
 * description length and image alt text — the three checks cheap enough to
 * run on every row of a post list without adding noticeable overhead.
 *
 * @since 1.1.0
 */
class WPSTK_Post_Insights {

	/**
	 * Column identifier.
	 */
	const COLUMN_ID = 'wpstk_insights';

	/**
	 * Registers the hooks once the current user's capability can be checked.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Adds the post list column, editor meta box and stylesheet.
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! WPSTK_Security::can( 'view' ) ) {
			return;
		}

		foreach ( WPSTK_Content::get_post_types() as $post_type ) {
			add_filter( "manage_{$post_type}_posts_columns", array( __CLASS__, 'add_column' ) );
			add_action( "manage_{$post_type}_posts_custom_column", array( __CLASS__, 'render_column' ), 10, 2 );
		}

		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Loads the small stylesheet only on the screens that use it.
	 *
	 * @param string $hook_suffix Current screen hook.
	 *
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'edit.php', 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || empty( $screen->post_type ) || ! in_array( $screen->post_type, WPSTK_Content::get_post_types(), true ) ) {
			return;
		}

		wp_enqueue_style(
			'wpstk-post-insights',
			WPSTK_URL . 'admin/css/wpstk-post-insights.css',
			array(),
			WPSTK_VERSION
		);
	}

	/**
	 * Inserts the column before the date column.
	 *
	 * @param array $columns Existing columns.
	 *
	 * @return array
	 */
	public static function add_column( $columns ) {
		$date = null;

		if ( isset( $columns['date'] ) ) {
			$date = $columns['date'];
			unset( $columns['date'] );
		}

		$columns[ self::COLUMN_ID ] = __( 'Site Toolkit', 'site-toolkit' );

		if ( null !== $date ) {
			$columns['date'] = $date;
		}

		return $columns;
	}

	/**
	 * Renders the column content for one row.
	 *
	 * @param string $column  Column being rendered.
	 * @param int    $post_id Post ID.
	 *
	 * @return void
	 */
	public static function render_column( $column, $post_id ) {
		if ( self::COLUMN_ID !== $column ) {
			return;
		}

		self::render_badges( self::collect_signals( (int) $post_id ) );
	}

	/**
	 * Registers the editor meta box for every audited post type.
	 *
	 * @return void
	 */
	public static function add_meta_box() {
		foreach ( WPSTK_Content::get_post_types() as $post_type ) {
			add_meta_box(
				'wpstk-post-insights',
				__( 'Site Toolkit', 'site-toolkit' ),
				array( __CLASS__, 'render_meta_box' ),
				$post_type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Renders the editor meta box.
	 *
	 * @param WP_Post $post Current post.
	 *
	 * @return void
	 */
	public static function render_meta_box( $post ) {
		$signals = self::collect_signals( (int) $post->ID );

		echo '<div class="wpstk-insights">';

		self::render_rows( $signals );

		if ( WPSTK_Security::can( 'run' ) ) {
			echo '<p class="wpstk-insights__footer"><a href="' . esc_url( WPSTK_Admin::page_url( 'site-toolkit-audit' ) ) . '">' . esc_html__( 'Run a full site audit for complete results', 'site-toolkit' ) . '</a></p>';
		}

		echo '</div>';
	}

	/**
	 * Computes the title, description and image alt-text signals for a post.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return array Signals keyed by title, description, images. Each value has
	 *               `status` (one of the standard check statuses) and `text`.
	 */
	public static function collect_signals( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return array();
		}

		$meta      = WPSTK_Content::get_seo_meta( array( $post_id ) );
		$post_meta = isset( $meta[ $post_id ] ) ? $meta[ $post_id ] : array();
		$post_row  = array(
			'ID'           => $post->ID,
			'post_title'   => $post->post_title,
			'post_excerpt' => $post->post_excerpt,
		);

		$signals = array();

		$signals['title']       = self::title_signal( $post_row, $post_meta );
		$signals['description'] = self::description_signal( $post_row, $post_meta );
		$signals['images']      = self::image_signal( $post );

		return $signals;
	}

	/**
	 * Builds the title signal.
	 *
	 * @param array $post_row  Minimal post row.
	 * @param array $post_meta SEO meta for the post.
	 *
	 * @return array
	 */
	private static function title_signal( $post_row, $post_meta ) {
		$title  = WPSTK_Content::resolve_title( $post_row, $post_meta );
		$value  = trim( $title['value'] );
		$length = WPSTK_Content::length( $value );

		if ( '' === $value ) {
			return array(
				'status' => 'critical',
				'text'   => __( 'Missing title', 'site-toolkit' ),
			);
		}

		if ( $length < WPSTK_Module_SEO::TITLE_MIN ) {
			return array(
				'status' => 'recommendation',
				/* translators: %d: number of characters. */
				'text'   => sprintf( __( 'Title is short (%d characters)', 'site-toolkit' ), $length ),
			);
		}

		if ( $length > WPSTK_Module_SEO::TITLE_MAX ) {
			return array(
				'status' => 'warning',
				/* translators: %d: number of characters. */
				'text'   => sprintf( __( 'Title is long (%d characters)', 'site-toolkit' ), $length ),
			);
		}

		return array(
			'status' => 'passed',
			/* translators: %d: number of characters. */
			'text'   => sprintf( __( 'Title length is good (%d characters)', 'site-toolkit' ), $length ),
		);
	}

	/**
	 * Builds the meta description signal.
	 *
	 * @param array $post_row  Minimal post row.
	 * @param array $post_meta SEO meta for the post.
	 *
	 * @return array
	 */
	private static function description_signal( $post_row, $post_meta ) {
		$description = WPSTK_Content::resolve_description( $post_row, $post_meta );
		$value       = trim( $description['value'] );
		$length      = WPSTK_Content::length( $value );

		if ( 'none' === $description['source'] || '' === $value ) {
			return array(
				'status' => 'warning',
				'text'   => __( 'No meta description', 'site-toolkit' ),
			);
		}

		if ( $length < WPSTK_Module_SEO::DESC_MIN ) {
			return array(
				'status' => 'recommendation',
				/* translators: %d: number of characters. */
				'text'   => sprintf( __( 'Description is short (%d characters)', 'site-toolkit' ), $length ),
			);
		}

		if ( $length > WPSTK_Module_SEO::DESC_MAX ) {
			return array(
				'status' => 'recommendation',
				/* translators: %d: number of characters. */
				'text'   => sprintf( __( 'Description is long (%d characters)', 'site-toolkit' ), $length ),
			);
		}

		return array(
			'status' => 'passed',
			/* translators: %d: number of characters. */
			'text'   => sprintf( __( 'Description length is good (%d characters)', 'site-toolkit' ), $length ),
		);
	}

	/**
	 * Builds the image alt-text signal, covering content images and the
	 * featured image.
	 *
	 * @param WP_Post $post Post object.
	 *
	 * @return array
	 */
	private static function image_signal( $post ) {
		$found_images = 0;
		$missing_alt  = 0;

		foreach ( WPSTK_HTML::get_tags( (string) $post->post_content, 'img' ) as $image ) {
			++$found_images;

			if ( ! isset( $image['alt'] ) ) {
				++$missing_alt;
			}
		}

		$thumbnail_id = get_post_thumbnail_id( $post->ID );

		if ( $thumbnail_id ) {
			++$found_images;
			$alt = get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true );

			if ( '' === trim( (string) $alt ) ) {
				++$missing_alt;
			}
		}

		if ( 0 === $found_images ) {
			return array(
				'status' => 'passed',
				'text'   => __( 'No images to check', 'site-toolkit' ),
			);
		}

		if ( $missing_alt > 0 ) {
			return array(
				'status' => 'warning',
				/* translators: %d: number of images. */
				'text'   => sprintf( _n( '%d image missing alt text', '%d images missing alt text', $missing_alt, 'site-toolkit' ), $missing_alt ),
			);
		}

		return array(
			'status' => 'passed',
			/* translators: %d: number of images. */
			'text'   => sprintf( _n( '%d image has alt text', '%d images have alt text', $found_images, 'site-toolkit' ), $found_images ),
		);
	}

	/**
	 * Renders the compact badge row used in the post list column.
	 *
	 * @param array $signals Signals from collect_signals().
	 *
	 * @return void
	 */
	private static function render_badges( $signals ) {
		if ( empty( $signals ) ) {
			echo '&#8211;';

			return;
		}

		$labels = array(
			'title'       => __( 'Title', 'site-toolkit' ),
			'description' => __( 'Description', 'site-toolkit' ),
			'images'      => __( 'Images', 'site-toolkit' ),
		);

		echo '<ul class="wpstk-insights-list">';

		foreach ( $signals as $key => $signal ) {
			printf(
				'<li class="wpstk-insights-item wpstk-insights-item--%1$s" title="%2$s"><span class="wpstk-insights-dot"></span>%3$s</li>',
				esc_attr( $signal['status'] ),
				esc_attr( $signal['text'] ),
				esc_html( isset( $labels[ $key ] ) ? $labels[ $key ] : $key )
			);
		}

		echo '</ul>';
	}

	/**
	 * Renders the full detail rows used in the editor meta box.
	 *
	 * @param array $signals Signals from collect_signals().
	 *
	 * @return void
	 */
	private static function render_rows( $signals ) {
		if ( empty( $signals ) ) {
			echo '<p>' . esc_html__( 'Nothing to check yet.', 'site-toolkit' ) . '</p>';

			return;
		}

		$labels = array(
			'title'       => __( 'Title', 'site-toolkit' ),
			'description' => __( 'Meta description', 'site-toolkit' ),
			'images'      => __( 'Image alt text', 'site-toolkit' ),
		);

		echo '<ul class="wpstk-insights-rows">';

		foreach ( $signals as $key => $signal ) {
			echo '<li class="wpstk-insights-row wpstk-insights-row--' . esc_attr( $signal['status'] ) . '">';
			echo '<strong>' . esc_html( isset( $labels[ $key ] ) ? $labels[ $key ] : $key ) . ':</strong> ';
			echo esc_html( $signal['text'] );
			echo '</li>';
		}

		echo '</ul>';
	}
}
