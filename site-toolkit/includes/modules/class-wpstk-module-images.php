<?php
/**
 * Image audit module.
 *
 * @package WP_Site_Toolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Checks alt text, file sizes, dimensions and media that may no longer be used.
 *
 * @since 1.0.0
 */
class WPSTK_Module_Images extends WPSTK_Module {

	/**
	 * {@inheritDoc}
	 */
	public function get_id() {
		return 'images';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label() {
		return __( 'Images', 'site-toolkit' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Alt text, oversized files, unusual dimensions, total media usage and potentially unused attachments.', 'site-toolkit' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_icon() {
		return 'dashicons-format-image';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_tasks( $settings ) {
		return array(
			array(
				'id'    => 'content',
				'label' => __( 'Checking images used in your content', 'site-toolkit' ),
			),
			array(
				'id'    => 'media',
				'label' => __( 'Measuring the media library', 'site-toolkit' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function run_task( $task_id, $offset, $state, $settings ) {
		switch ( $task_id ) {
			case 'content':
				return $this->task_content( $offset, $state, $settings );
			case 'media':
				return $this->task_media( $offset, $state, $settings );
		}

		return $this->task_result( $state );
	}

	/**
	 * Scans post content for images without alt text and records which
	 * attachments are referenced.
	 *
	 * @param int   $offset   Offset.
	 * @param array $state    Module state.
	 * @param array $settings Settings.
	 *
	 * @return array
	 */
	private function task_content( $offset, $state, $settings ) {
		if ( ! isset( $state['content'] ) ) {
			$state['content'] = array(
				'posts_scanned'  => 0,
				'images_found'   => 0,
				'missing_alt'    => 0,
				'decorative'     => 0,
				'samples'        => array(),
				'referenced_ids' => array(),
				'referenced_files' => array(),
				'covered_all'    => true,
				'available'      => 0,
			);
		}

		$max   = (int) $settings['max_posts'];
		$batch = min( (int) $settings['batch_posts'], max( 1, $max - $offset ) );

		$available                     = WPSTK_Content::count_posts();
		$state['content']['available'] = $available;
		$total                         = min( $available, $max );

		if ( $offset >= $total || $batch <= 0 ) {
			$state['content']['covered_all'] = $available <= $max;

			return $this->task_result( $state, null, 0, $total );
		}

		$posts = WPSTK_Content::get_post_batch( $offset, $batch );

		if ( empty( $posts ) ) {
			$state['content']['covered_all'] = $available <= $max;

			return $this->task_result( $state, null, 0, $total );
		}

		$ids  = wp_list_pluck( $posts, 'ID' );
		$meta = WPSTK_Content::get_seo_meta( $ids );

		foreach ( $posts as $post ) {
			$post_id = (int) $post['ID'];
			$content = (string) $post['post_content'];

			++$state['content']['posts_scanned'];

			// Featured images count as used media.
			if ( ! empty( $meta[ $post_id ]['_thumbnail_id'] ) ) {
				$state['content']['referenced_ids'][ (int) $meta[ $post_id ]['_thumbnail_id'] ] = 1;
			}

			$missing_here = 0;

			foreach ( WPSTK_HTML::get_tags( $content, 'img' ) as $image ) {
				++$state['content']['images_found'];

				if ( ! empty( $image['class'] ) && preg_match( '~wp-image-(\d+)~', $image['class'], $matches ) ) {
					$state['content']['referenced_ids'][ (int) $matches[1] ] = 1;
				}

				if ( ! empty( $image['src'] ) ) {
					$file = wp_basename( wp_parse_url( $image['src'], PHP_URL_PATH ) );

					if ( '' !== $file && count( $state['content']['referenced_files'] ) < 5000 ) {
						$state['content']['referenced_files'][ $this->base_filename( $file ) ] = 1;
					}
				}

				if ( ! isset( $image['alt'] ) ) {
					++$state['content']['missing_alt'];
					++$missing_here;
				} elseif ( '' === trim( $image['alt'] ) ) {
					++$state['content']['decorative'];
				}
			}

			// Collect attachment IDs used by blocks that do not emit an img tag class.
			if ( false !== strpos( $content, 'wp:image' ) || false !== strpos( $content, '"id":' ) ) {
				$block_ids = array();

				if ( preg_match_all( '~"id"\s*:\s*(\d+)~', $content, $block_ids ) ) {
					foreach ( $block_ids[1] as $block_id ) {
						$state['content']['referenced_ids'][ (int) $block_id ] = 1;
					}
				}
			}

			if ( $missing_here > 0 ) {
				$state['content']['samples'] = $this->push_sample(
					$state['content']['samples'],
					array(
						$post_id,
						WPSTK_Content::shorten( (string) $post['post_title'], 80 ),
						$missing_here,
					)
				);
			}
		}

		$processed = count( $posts );
		$next      = $offset + $processed;

		if ( $next >= $total ) {
			$state['content']['covered_all'] = $available <= $max;

			return $this->task_result( $state, null, $processed, $total );
		}

		return $this->task_result( $state, $next, $processed, $total );
	}

	/**
	 * Measures the media library in batches.
	 *
	 * @param int   $offset   Offset.
	 * @param array $state    Module state.
	 * @param array $settings Settings.
	 *
	 * @return array
	 */
	private function task_media( $offset, $state, $settings ) {
		if ( ! isset( $state['media'] ) ) {
			$state['media'] = array(
				'count'        => 0,
				'bytes'        => 0,
				'measured'     => 0,
				'large_files'  => array(),
				'large_count'  => 0,
				'large_dims'   => array(),
				'dims_count'   => 0,
				'no_alt'       => array(),
				'no_alt_count' => 0,
				'largest'      => array(),
				'unattached'   => array(),
				'unused_count' => 0,
				'ids'          => array(),
			);
		}

		$total = WPSTK_Content::count_image_attachments();

		if ( 0 === $total || $offset >= $total ) {
			return $this->task_result( $state, null, 0, $total );
		}

		$batch  = max( 10, (int) $settings['batch_posts'] );
		$images = WPSTK_Content::get_image_batch( $offset, $batch );

		if ( empty( $images ) ) {
			return $this->task_result( $state, null, 0, $total );
		}

		$size_limit = (int) $settings['large_image_kb'] * 1024;
		$dim_limit  = (int) $settings['large_image_dim'];

		foreach ( $images as $image ) {
			$attachment_id = (int) $image['ID'];
			++$state['media']['count'];

			$metadata = wp_get_attachment_metadata( $attachment_id );
			$metadata = is_array( $metadata ) ? $metadata : array();

			$bytes  = $this->attachment_bytes( $attachment_id, $metadata );
			$width  = isset( $metadata['width'] ) ? (int) $metadata['width'] : 0;
			$height = isset( $metadata['height'] ) ? (int) $metadata['height'] : 0;
			$title  = '' !== trim( (string) $image['post_title'] ) ? $image['post_title'] : __( '(untitled)', 'site-toolkit' );

			if ( $bytes > 0 ) {
				$state['media']['bytes'] += $bytes;
				++$state['media']['measured'];

				if ( $bytes >= $size_limit ) {
					++$state['media']['large_count'];
					$state['media']['large_files'] = $this->push_sample(
						$state['media']['large_files'],
						array( $attachment_id, $title, $bytes, $width, $height )
					);
				}

				$state['media']['largest'] = $this->track_largest( $state['media']['largest'], array( $attachment_id, $title, $bytes ) );
			}

			if ( $width >= $dim_limit || $height >= $dim_limit ) {
				++$state['media']['dims_count'];
				$state['media']['large_dims'] = $this->push_sample(
					$state['media']['large_dims'],
					array( $attachment_id, $title, $width, $height )
				);
			}

			$alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

			if ( '' === trim( (string) $alt ) ) {
				++$state['media']['no_alt_count'];
				$state['media']['no_alt'] = $this->push_sample(
					$state['media']['no_alt'],
					array( $attachment_id, $title )
				);
			}

			// Remember candidates for the unused-media check, bounded so a very
			// large library cannot blow up the stored scan state.
			if ( count( $state['media']['ids'] ) < 3000 ) {
				$state['media']['ids'][] = array(
					$attachment_id,
					(int) $image['post_parent'],
					$this->base_filename( wp_basename( (string) get_post_meta( $attachment_id, '_wp_attached_file', true ) ) ),
					$title,
					$bytes,
				);
			} else {
				$state['media']['ids_truncated'] = true;
			}
		}

		$processed = count( $images );
		$next      = $offset + $processed;

		if ( $next >= $total ) {
			return $this->task_result( $state, null, $processed, $total );
		}

		return $this->task_result( $state, $next, $processed, $total );
	}

	/**
	 * Returns the size of an attachment in bytes.
	 *
	 * Uses the size recorded in the attachment metadata when available so the
	 * scan does not touch the filesystem more than it has to.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $metadata      Attachment metadata.
	 *
	 * @return int
	 */
	private function attachment_bytes( $attachment_id, $metadata ) {
		if ( isset( $metadata['filesize'] ) && (int) $metadata['filesize'] > 0 ) {
			return (int) $metadata['filesize'];
		}

		$path = get_attached_file( $attachment_id );

		if ( ! $path || ! @is_readable( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing files must not raise warnings during a scan.
			return 0;
		}

		$size = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Same as above.

		return $size ? (int) $size : 0;
	}

	/**
	 * Keeps a running top ten of the largest files.
	 *
	 * @param array $largest Current list.
	 * @param array $entry   Candidate entry.
	 *
	 * @return array
	 */
	private function track_largest( $largest, $entry ) {
		$largest[] = $entry;

		usort(
			$largest,
			static function ( $a, $b ) {
				if ( $a[2] === $b[2] ) {
					return 0;
				}

				return $a[2] > $b[2] ? -1 : 1;
			}
		);

		return array_slice( $largest, 0, 10 );
	}

	/**
	 * Strips the WordPress size suffix from a file name so `photo-300x200.jpg`
	 * matches the original `photo.jpg`.
	 *
	 * @param string $filename File name.
	 *
	 * @return string
	 */
	private function base_filename( $filename ) {
		$filename = strtolower( (string) $filename );

		return (string) preg_replace( '~-\d+x\d+(\.[a-z0-9]+)$~', '$1', $filename );
	}

	/**
	 * {@inheritDoc}
	 */
	public function finalize( $state, $settings, $all_state ) {
		$content = $this->state_get( $state, 'content', array() );
		$media   = $this->state_get( $state, 'media', array() );

		$checks = array();

		$checks[] = $this->alt_text_check( $content, $media );
		$checks[] = $this->large_files_check( $media, $settings );
		$checks[] = $this->dimensions_check( $media, $settings );
		$checks[] = $this->library_check( $media );
		$checks[] = $this->unused_check( $content, $media );

		return array_filter( $checks );
	}

	/**
	 * Builds the alt text check.
	 *
	 * @param array $content Content state.
	 * @param array $media   Media state.
	 *
	 * @return array
	 */
	private function alt_text_check( $content, $media ) {
		$label = __( 'Image alt text', 'site-toolkit' );

		if ( empty( $content['images_found'] ) && empty( $media['count'] ) ) {
			return $this->skipped( 'alt_text', $label, __( 'No images were found in your content or media library.', 'site-toolkit' ) );
		}

		$missing = (int) $this->state_get( $content, 'missing_alt', 0 );
		$found   = (int) $this->state_get( $content, 'images_found', 0 );
		$items   = array();

		foreach ( (array) $this->state_get( $content, 'samples', array() ) as $row ) {
			$items[] = WPSTK_Check::post_item(
				$row[0],
				$row[1],
				sprintf(
					/* translators: %d: number of images. */
					_n( '%d image without an alt attribute', '%d images without an alt attribute', (int) $row[2], 'site-toolkit' ),
					(int) $row[2]
				)
			);
		}

		$library_missing = (int) $this->state_get( $media, 'no_alt_count', 0 );

		if ( 0 === $missing ) {
			return $this->check(
				array(
					'id'      => 'alt_text',
					'status'  => $library_missing > 0 ? 'recommendation' : 'passed',
					'label'   => $label,
					'weight'  => 2.0,
					'summary' => $library_missing > 0
						? sprintf(
							/* translators: 1: images in content, 2: library images without alt text. */
							__( 'All %1$d images in your content have an alt attribute, but %2$d media library items have no alt text saved.', 'site-toolkit' ),
							$found,
							$library_missing
						)
						: sprintf(
							/* translators: %d: number of images. */
							_n( 'The %d image in your content has an alt attribute.', 'All %d images in your content have an alt attribute.', $found, 'site-toolkit' ),
							$found
						),
					'why'     => __( 'Alt text describes an image to people using a screen reader and to search engines. Purely decorative images should keep an empty alt attribute.', 'site-toolkit' ),
					'action'  => $library_missing > 0 ? __( 'Add alt text in the media library so it is filled in automatically the next time you insert those images.', 'site-toolkit' ) : '',
					'items'   => $library_missing > 0 ? array(
						WPSTK_Check::item(
							array(
								'label'      => __( 'Media library', 'site-toolkit' ),
								'detail'     => sprintf(
									/* translators: %d: number of images. */
									_n( '%d image without alt text', '%d images without alt text', $library_missing, 'site-toolkit' ),
									$library_missing
								),
								'link'       => admin_url( 'upload.php' ),
								'link_label' => __( 'Open media library', 'site-toolkit' ),
							)
						),
					) : array(),
				)
			);
		}

		return $this->check(
			array(
				'id'          => 'alt_text',
				'status'      => $missing > ( $found / 4 ) ? 'warning' : 'recommendation',
				'label'       => $label,
				'weight'      => 2.0,
				'summary'     => sprintf(
					/* translators: 1: images without alt, 2: images found, 3: decorative images. */
					__( '%1$d of %2$d images in your content have no alt attribute at all. A further %3$d use an empty alt, which is correct for decorative images.', 'site-toolkit' ),
					$missing,
					$found,
					(int) $this->state_get( $content, 'decorative', 0 )
				),
				'why'         => __( 'Alt text describes an image to people using a screen reader and to search engines. Without it, the image is simply skipped.', 'site-toolkit' ),
				'action'      => __( 'Open the listed entries and describe each image in a short sentence. Leave the alt empty only when the image is decorative.', 'site-toolkit' ),
				'items'       => $items,
				'items_total' => count( $items ),
			)
		);
	}

	/**
	 * Builds the large file check.
	 *
	 * @param array $media    Media state.
	 * @param array $settings Settings.
	 *
	 * @return array
	 */
	private function large_files_check( $media, $settings ) {
		$label = __( 'Large image files', 'site-toolkit' );

		if ( empty( $media['count'] ) ) {
			return $this->skipped( 'large_images', $label, __( 'The media library contains no images.', 'site-toolkit' ) );
		}

		$threshold = (int) $settings['large_image_kb'] * 1024;
		$count     = (int) $media['large_count'];

		if ( 0 === $count ) {
			return $this->passed(
				'large_images',
				$label,
				sprintf(
					/* translators: %s: file size. */
					__( 'No image is larger than %s.', 'site-toolkit' ),
					size_format( $threshold )
				),
				__( 'Large images are the most common cause of slow pages on WordPress sites.', 'site-toolkit' )
			);
		}

		$items = array();

		foreach ( (array) $media['large_files'] as $row ) {
			$items[] = WPSTK_Check::item(
				array(
					'label'      => (string) $row[1],
					'detail'     => $row[3] > 0
						? sprintf(
							/* translators: 1: file size, 2: width, 3: height. */
							__( '%1$s — %2$d × %3$d pixels', 'site-toolkit' ),
							size_format( (int) $row[2] ),
							(int) $row[3],
							(int) $row[4]
						)
						: size_format( (int) $row[2] ),
					'url'        => (string) wp_get_attachment_url( (int) $row[0] ),
					'link'       => admin_url( 'post.php?post=' . (int) $row[0] . '&action=edit' ),
					'link_label' => __( 'View image', 'site-toolkit' ),
				)
			);
		}

		return $this->check(
			array(
				'id'          => 'large_images',
				'status'      => $count > ( (int) $media['count'] / 4 ) ? 'warning' : 'recommendation',
				'label'       => $label,
				'weight'      => 1.5,
				'summary'     => sprintf(
					/* translators: 1: number of images, 2: total images, 3: size threshold. */
					__( '%1$d of %2$d images are larger than %3$s.', 'site-toolkit' ),
					$count,
					(int) $media['count'],
					size_format( $threshold )
				),
				'why'         => __( 'Large images are the most common cause of slow pages. They cost your visitors time and data, especially on mobile connections.', 'site-toolkit' ),
				'action'      => __( 'Re-save these images at a smaller size or compress them, then replace them in the media library. Site Toolkit never modifies or deletes your files.', 'site-toolkit' ),
				'items'       => $items,
				'items_total' => $count,
			)
		);
	}

	/**
	 * Builds the dimension check.
	 *
	 * @param array $media    Media state.
	 * @param array $settings Settings.
	 *
	 * @return array
	 */
	private function dimensions_check( $media, $settings ) {
		$label = __( 'Image dimensions', 'site-toolkit' );

		if ( empty( $media['count'] ) ) {
			return $this->skipped( 'image_dimensions', $label, __( 'The media library contains no images.', 'site-toolkit' ) );
		}

		$limit = (int) $settings['large_image_dim'];
		$count = (int) $media['dims_count'];

		if ( 0 === $count ) {
			return $this->passed(
				'image_dimensions',
				$label,
				sprintf(
					/* translators: %d: number of pixels. */
					__( 'No image is wider or taller than %d pixels.', 'site-toolkit' ),
					$limit
				),
				__( 'Images far larger than they are displayed waste bandwidth on every page view.', 'site-toolkit' )
			);
		}

		$items = array();

		foreach ( (array) $media['large_dims'] as $row ) {
			$items[] = WPSTK_Check::item(
				array(
					'label'      => (string) $row[1],
					'detail'     => sprintf(
						/* translators: 1: width, 2: height. */
						__( '%1$d × %2$d pixels', 'site-toolkit' ),
						(int) $row[2],
						(int) $row[3]
					),
					'url'        => (string) wp_get_attachment_url( (int) $row[0] ),
					'link'       => admin_url( 'post.php?post=' . (int) $row[0] . '&action=edit' ),
					'link_label' => __( 'View image', 'site-toolkit' ),
				)
			);
		}

		return $this->check(
			array(
				'id'          => 'image_dimensions',
				'status'      => 'recommendation',
				'label'       => $label,
				'summary'     => sprintf(
					/* translators: 1: number of images, 2: pixel limit. */
					_n( '%1$d image is wider or taller than %2$d pixels.', '%1$d images are wider or taller than %2$d pixels.', $count, 'site-toolkit' ),
					$count,
					$limit
				),
				'why'         => __( 'Very large originals are rarely displayed at full size. WordPress generates smaller versions, but the original is still stored and sometimes still served.', 'site-toolkit' ),
				'action'      => __( 'Resize the originals before uploading them next time. Existing files can stay as they are if the generated sizes are what your theme uses.', 'site-toolkit' ),
				'items'       => $items,
				'items_total' => $count,
			)
		);
	}

	/**
	 * Builds the media library size report.
	 *
	 * @param array $media Media state.
	 *
	 * @return array
	 */
	private function library_check( $media ) {
		$label = __( 'Media library size', 'site-toolkit' );

		if ( empty( $media['count'] ) ) {
			return $this->skipped( 'media_report', $label, __( 'The media library contains no images.', 'site-toolkit' ) );
		}

		$items = array();

		foreach ( (array) $media['largest'] as $row ) {
			$items[] = WPSTK_Check::item(
				array(
					'label'      => (string) $row[1],
					'detail'     => size_format( (int) $row[2] ),
					'url'        => (string) wp_get_attachment_url( (int) $row[0] ),
					'link'       => admin_url( 'post.php?post=' . (int) $row[0] . '&action=edit' ),
					'link_label' => __( 'View image', 'site-toolkit' ),
				)
			);
		}

		return $this->check(
			array(
				'id'          => 'media_report',
				'status'      => 'passed',
				'label'       => $label,
				'weight'      => 0.5,
				'summary'     => sprintf(
					/* translators: 1: number of images, 2: total size, 3: number measured. */
					__( '%1$d images use %2$s in total (measured for %3$d files).', 'site-toolkit' ),
					(int) $media['count'],
					size_format( (int) $media['bytes'] ),
					(int) $media['measured']
				),
				'why'         => __( 'This is a reference figure, not a problem. It counts original files only, not the smaller sizes WordPress generates.', 'site-toolkit' ),
				'items'       => $items,
				'items_total' => count( $items ),
			)
		);
	}

	/**
	 * Builds the potentially unused media check.
	 *
	 * @param array $content Content state.
	 * @param array $media   Media state.
	 *
	 * @return array
	 */
	private function unused_check( $content, $media ) {
		$label = __( 'Potentially unused media', 'site-toolkit' );

		if ( empty( $media['ids'] ) ) {
			return $this->skipped( 'unused_media', $label, __( 'The media library contains no images.', 'site-toolkit' ) );
		}

		if ( ! empty( $media['ids_truncated'] ) ) {
			return $this->skipped(
				'unused_media',
				$label,
				__( 'Skipped because this media library is larger than the 3,000 files this check inspects in a single scan.', 'site-toolkit' )
			);
		}

		if ( empty( $content['covered_all'] ) ) {
			return $this->skipped(
				'unused_media',
				$label,
				sprintf(
					/* translators: 1: entries analysed, 2: total published entries. */
					__( 'Skipped because only %1$d of %2$d published entries were analysed. Media usage can only be judged reliably when every entry is scanned — raise the "Maximum posts per scan" setting to enable this check.', 'site-toolkit' ),
					(int) $this->state_get( $content, 'posts_scanned', 0 ),
					(int) $this->state_get( $content, 'available', 0 )
				)
			);
		}

		$referenced_ids   = (array) $this->state_get( $content, 'referenced_ids', array() );
		$referenced_files = (array) $this->state_get( $content, 'referenced_files', array() );
		$site_icon        = (int) get_option( 'site_icon' );
		$custom_logo      = (int) get_theme_mod( 'custom_logo' );

		$candidates = array();

		foreach ( (array) $media['ids'] as $row ) {
			$attachment_id = (int) $row[0];
			$parent        = (int) $row[1];
			$filename      = (string) $row[2];

			if ( isset( $referenced_ids[ $attachment_id ] ) ) {
				continue;
			}

			if ( '' !== $filename && isset( $referenced_files[ $filename ] ) ) {
				continue;
			}

			if ( $attachment_id === $site_icon || $attachment_id === $custom_logo ) {
				continue;
			}

			if ( $parent > 0 && 'publish' === get_post_status( $parent ) ) {
				continue;
			}

			$candidates[] = $row;
		}

		$count = count( $candidates );

		if ( 0 === $count ) {
			return $this->passed(
				'unused_media',
				$label,
				__( 'Every image in the library is referenced by your content, a featured image, the site icon or the site logo.', 'site-toolkit' ),
				__( 'Unused files still take up storage and make the library harder to search.', 'site-toolkit' )
			);
		}

		$items = array();
		$bytes = 0;

		foreach ( $candidates as $row ) {
			$bytes += (int) $row[4];

			if ( count( $items ) >= WPSTK_Check::MAX_ITEMS ) {
				continue;
			}

			$items[] = WPSTK_Check::item(
				array(
					'label'      => (string) $row[3],
					'detail'     => (int) $row[4] > 0 ? size_format( (int) $row[4] ) : '',
					'url'        => (string) wp_get_attachment_url( (int) $row[0] ),
					'link'       => admin_url( 'post.php?post=' . (int) $row[0] . '&action=edit' ),
					'link_label' => __( 'View image', 'site-toolkit' ),
				)
			);
		}

		return $this->check(
			array(
				'id'          => 'unused_media',
				'status'      => 'recommendation',
				'label'       => $label,
				'weight'      => 0.5,
				'summary'     => sprintf(
					/* translators: 1: number of images, 2: total size. */
					_n(
						'%1$d image appears to be unused, taking up about %2$s.',
						'%1$d images appear to be unused, taking up about %2$s.',
						$count,
						'site-toolkit'
					),
					$count,
					size_format( $bytes )
				),
				'why'         => __( 'Unused files take up storage and make the media library harder to search.', 'site-toolkit' ),
				'action'      => __( 'Review each file before doing anything with it. Site Toolkit never deletes media.', 'site-toolkit' ),
				'note'        => __( 'Potentially unused only. Images used by page builders, custom fields, theme options, widgets or CSS backgrounds cannot be detected here, so treat this list as a starting point rather than a verdict.', 'site-toolkit' ),
				'items'       => $items,
				'items_total' => $count,
			)
		);
	}
}
