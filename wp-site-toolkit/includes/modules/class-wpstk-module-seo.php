<?php
/**
 * SEO audit module.
 *
 * @package WP_Site_Toolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Checks titles, descriptions, headings, canonical tags, social tags,
 * indexability, the sitemap and robots.txt.
 *
 * @since 1.0.0
 */
class WPSTK_Module_SEO extends WPSTK_Module {

	/**
	 * Recommended title length bounds, in characters.
	 */
	const TITLE_MIN = 30;
	const TITLE_MAX = 60;

	/**
	 * Recommended meta description length bounds, in characters.
	 */
	const DESC_MIN = 70;
	const DESC_MAX = 160;

	/**
	 * {@inheritDoc}
	 */
	public function get_id() {
		return 'seo';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label() {
		return __( 'SEO', 'wp-site-toolkit' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Titles, meta descriptions, headings, canonical tags, social tags, indexability, sitemap and robots.txt.', 'wp-site-toolkit' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_icon() {
		return 'dashicons-search';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_tasks( $settings ) {
		return array(
			array(
				'id'    => 'content',
				'label' => __( 'Checking titles and meta descriptions', 'wp-site-toolkit' ),
			),
			array(
				'id'    => 'pages',
				'label' => __( 'Inspecting rendered pages', 'wp-site-toolkit' ),
			),
			array(
				'id'    => 'site',
				'label' => __( 'Checking sitemap, robots.txt and indexability', 'wp-site-toolkit' ),
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
			case 'pages':
				return $this->task_pages( $offset, $state, $settings );
			case 'site':
				return $this->task_site( $state, $settings );
		}

		return $this->task_result( $state );
	}

	/**
	 * Analyses stored titles and descriptions in batches.
	 *
	 * @param int   $offset   Offset.
	 * @param array $state    Module state.
	 * @param array $settings Settings.
	 *
	 * @return array
	 */
	private function task_content( $offset, $state, $settings ) {
		$max   = (int) $settings['max_posts'];
		$batch = min( (int) $settings['batch_posts'], max( 1, $max - $offset ) );

		if ( ! isset( $state['content'] ) ) {
			$state['content'] = array(
				'total'          => 0,
				'title_missing'  => array(),
				'title_short'    => array(),
				'title_long'     => array(),
				'title_dupes'    => array(),
				'desc_missing'   => array(),
				'desc_short'     => array(),
				'desc_long'      => array(),
				'counts'         => array(
					'title_missing' => 0,
					'title_short'   => 0,
					'title_long'    => 0,
					'title_dupes'   => 0,
					'desc_missing'  => 0,
					'desc_short'    => 0,
					'desc_long'     => 0,
				),
				'title_hashes'   => array(),
				'covered_all'    => true,
				'available'      => 0,
			);
		}

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
			$post_id   = (int) $post['ID'];
			$post_meta = isset( $meta[ $post_id ] ) ? $meta[ $post_id ] : array();

			++$state['content']['total'];

			$title        = WPSTK_Content::resolve_title( $post, $post_meta );
			$title_value  = trim( $title['value'] );
			$title_length = WPSTK_Content::length( $title_value );

			if ( '' === $title_value ) {
				++$state['content']['counts']['title_missing'];
				$state['content']['title_missing'] = $this->push_sample(
					$state['content']['title_missing'],
					array( $post_id, $post['post_title'], '' )
				);
			} else {
				$hash = md5( strtolower( $title_value ) );

				if ( isset( $state['content']['title_hashes'][ $hash ] ) ) {
					++$state['content']['counts']['title_dupes'];
					$state['content']['title_dupes'] = $this->push_sample(
						$state['content']['title_dupes'],
						array( $post_id, $post['post_title'], WPSTK_Content::shorten( $title_value, 70 ) )
					);
				} elseif ( count( $state['content']['title_hashes'] ) < 5000 ) {
					$state['content']['title_hashes'][ $hash ] = 1;
				}

				if ( $title_length < self::TITLE_MIN ) {
					++$state['content']['counts']['title_short'];
					$state['content']['title_short'] = $this->push_sample(
						$state['content']['title_short'],
						array(
							$post_id,
							$post['post_title'],
							/* translators: %d: number of characters. */
							sprintf( _n( '%d character', '%d characters', $title_length, 'wp-site-toolkit' ), $title_length ),
						)
					);
				} elseif ( $title_length > self::TITLE_MAX ) {
					++$state['content']['counts']['title_long'];
					$state['content']['title_long'] = $this->push_sample(
						$state['content']['title_long'],
						array(
							$post_id,
							$post['post_title'],
							/* translators: %d: number of characters. */
							sprintf( _n( '%d character', '%d characters', $title_length, 'wp-site-toolkit' ), $title_length ),
						)
					);
				}
			}

			$description  = WPSTK_Content::resolve_description( $post, $post_meta );
			$desc_value   = trim( $description['value'] );
			$desc_length  = WPSTK_Content::length( $desc_value );

			if ( 'none' === $description['source'] || '' === $desc_value ) {
				++$state['content']['counts']['desc_missing'];
				$state['content']['desc_missing'] = $this->push_sample(
					$state['content']['desc_missing'],
					array( $post_id, $post['post_title'], '' )
				);
			} elseif ( $desc_length < self::DESC_MIN ) {
				++$state['content']['counts']['desc_short'];
				$state['content']['desc_short'] = $this->push_sample(
					$state['content']['desc_short'],
					array(
						$post_id,
						$post['post_title'],
						/* translators: %d: number of characters. */
						sprintf( _n( '%d character', '%d characters', $desc_length, 'wp-site-toolkit' ), $desc_length ),
					)
				);
			} elseif ( $desc_length > self::DESC_MAX ) {
				++$state['content']['counts']['desc_long'];
				$state['content']['desc_long'] = $this->push_sample(
					$state['content']['desc_long'],
					array(
						$post_id,
						$post['post_title'],
						/* translators: %d: number of characters. */
						sprintf( _n( '%d character', '%d characters', $desc_length, 'wp-site-toolkit' ), $desc_length ),
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
	 * Loads a sample of rendered pages from this site and inspects the markup.
	 *
	 * @param int   $offset   Offset.
	 * @param array $state    Module state.
	 * @param array $settings Settings.
	 *
	 * @return array
	 */
	private function task_pages( $offset, $state, $settings ) {
		if ( ! isset( $state['pages'] ) ) {
			$state['pages'] = array(
				'urls'          => $this->sample_urls( (int) $settings['page_samples'] ),
				'fetched'       => 0,
				'failed'        => 0,
				'no_canonical'  => array(),
				'bad_canonical' => array(),
				'no_h1'         => array(),
				'many_h1'       => array(),
				'hierarchy'     => array(),
				'no_og'         => array(),
				'noindex'       => array(),
				'no_title'      => array(),
				'no_desc'       => array(),
				'durations'     => array(),
				'first_error'   => '',
			);
		}

		$urls  = $state['pages']['urls'];
		$total = count( $urls );

		if ( $offset >= $total ) {
			return $this->task_result( $state, null, 0, $total );
		}

		$batch     = array_slice( $urls, $offset, 2 );
		$timeout   = (int) $settings['scan_timeout'];
		$processed = 0;

		foreach ( $batch as $entry ) {
			++$processed;

			$response = WPSTK_HTTP::fetch( $entry['url'], $timeout );

			if ( ! $response['success'] || $response['status'] >= 400 || '' === $response['body'] ) {
				++$state['pages']['failed'];

				if ( '' === $state['pages']['first_error'] ) {
					$state['pages']['first_error'] = '' !== $response['message']
						? $response['message']
						/* translators: %d: HTTP status code. */
						: sprintf( __( 'HTTP %d', 'wp-site-toolkit' ), (int) $response['status'] );
				}

				continue;
			}

			++$state['pages']['fetched'];
			$state['pages']['durations'][] = (float) $response['duration'];

			$this->inspect_page( $entry, $response['body'], $state );
		}

		$next = $offset + $processed;

		if ( $next >= $total ) {
			return $this->task_result( $state, null, $processed, $total );
		}

		return $this->task_result( $state, $next, $processed, $total );
	}

	/**
	 * Inspects the markup of one rendered page.
	 *
	 * @param array  $entry Sample entry with url, label and post ID.
	 * @param string $html  Page markup.
	 * @param array  $state Module state, modified in place.
	 *
	 * @return void
	 */
	private function inspect_page( $entry, $html, &$state ) {
		$label   = $entry['label'];
		$url     = $entry['url'];
		$post_id = (int) $entry['post_id'];

		$canonicals = WPSTK_HTML::get_canonicals( $html );

		if ( empty( $canonicals ) ) {
			$state['pages']['no_canonical'] = $this->push_sample(
				$state['pages']['no_canonical'],
				array( $post_id, $label, $url, '' )
			);
		} elseif ( count( array_unique( $canonicals ) ) > 1 ) {
			$state['pages']['bad_canonical'] = $this->push_sample(
				$state['pages']['bad_canonical'],
				array( $post_id, $label, $url, __( 'More than one canonical tag', 'wp-site-toolkit' ) )
			);
		} else {
			$canonical = $canonicals[0];

			if ( ! WPSTK_HTTP::is_requestable( $canonical ) ) {
				$state['pages']['bad_canonical'] = $this->push_sample(
					$state['pages']['bad_canonical'],
					array( $post_id, $label, $url, __( 'Canonical URL is not an absolute http(s) address', 'wp-site-toolkit' ) )
				);
			} elseif ( ! WPSTK_Content::is_internal_url( $canonical ) ) {
				$state['pages']['bad_canonical'] = $this->push_sample(
					$state['pages']['bad_canonical'],
					array(
						$post_id,
						$label,
						$url,
						/* translators: %s: canonical URL. */
						sprintf( __( 'Canonical points to another domain: %s', 'wp-site-toolkit' ), WPSTK_Content::shorten( $canonical, 70 ) ),
					)
				);
			}
		}

		$headings = WPSTK_HTML::get_headings( WPSTK_HTML::get_body( $html ) );
		$h1_count = 0;
		$previous = 0;
		$jumped   = false;

		foreach ( $headings as $heading ) {
			if ( 1 === $heading['level'] ) {
				++$h1_count;
			}

			if ( $previous > 0 && $heading['level'] > $previous + 1 ) {
				$jumped = true;
			}

			$previous = $heading['level'];
		}

		if ( 0 === $h1_count ) {
			$state['pages']['no_h1'] = $this->push_sample( $state['pages']['no_h1'], array( $post_id, $label, $url, '' ) );
		} elseif ( $h1_count > 1 ) {
			$state['pages']['many_h1'] = $this->push_sample(
				$state['pages']['many_h1'],
				array(
					$post_id,
					$label,
					$url,
					/* translators: %d: number of H1 headings. */
					sprintf( _n( '%d H1 heading', '%d H1 headings', $h1_count, 'wp-site-toolkit' ), $h1_count ),
				)
			);
		}

		if ( $jumped ) {
			$state['pages']['hierarchy'] = $this->push_sample(
				$state['pages']['hierarchy'],
				array( $post_id, $label, $url, __( 'A heading level is skipped', 'wp-site-toolkit' ) )
			);
		}

		$og_title = WPSTK_HTML::get_meta( $html, 'og:title', 'property' );
		$og_desc  = WPSTK_HTML::get_meta( $html, 'og:description', 'property' );
		$og_image = WPSTK_HTML::get_meta( $html, 'og:image', 'property' );

		$missing_og = array();

		if ( null === $og_title ) {
			$missing_og[] = 'og:title';
		}

		if ( null === $og_desc ) {
			$missing_og[] = 'og:description';
		}

		if ( null === $og_image ) {
			$missing_og[] = 'og:image';
		}

		if ( ! empty( $missing_og ) ) {
			$state['pages']['no_og'] = $this->push_sample(
				$state['pages']['no_og'],
				array(
					$post_id,
					$label,
					$url,
					/* translators: %s: comma separated list of tag names. */
					sprintf( __( 'Missing: %s', 'wp-site-toolkit' ), implode( ', ', $missing_og ) ),
				)
			);
		}

		$robots = WPSTK_HTML::get_meta( $html, 'robots' );

		if ( is_string( $robots ) && false !== stripos( $robots, 'noindex' ) ) {
			$state['pages']['noindex'] = $this->push_sample(
				$state['pages']['noindex'],
				array( $post_id, $label, $url, WPSTK_Content::shorten( $robots, 60 ) )
			);
		}

		if ( '' === WPSTK_HTML::get_title( $html ) ) {
			$state['pages']['no_title'] = $this->push_sample( $state['pages']['no_title'], array( $post_id, $label, $url, '' ) );
		}

		$meta_description = WPSTK_HTML::get_meta( $html, 'description' );

		if ( null === $meta_description || '' === trim( (string) $meta_description ) ) {
			$state['pages']['no_desc'] = $this->push_sample( $state['pages']['no_desc'], array( $post_id, $label, $url, '' ) );
		}
	}

	/**
	 * Builds the list of URLs sampled from this site.
	 *
	 * @param int $limit Maximum number of URLs.
	 *
	 * @return array[]
	 */
	private function sample_urls( $limit ) {
		$limit = max( 1, (int) $limit );

		$urls = array(
			array(
				'url'     => home_url( '/' ),
				'label'   => __( 'Home page', 'wp-site-toolkit' ),
				'post_id' => 0,
			),
		);

		$posts = get_posts(
			array(
				'post_type'           => WPSTK_Content::get_post_types(),
				'post_status'         => 'publish',
				'numberposts'         => $limit * 2,
				'orderby'             => 'modified',
				'order'               => 'DESC',
				'suppress_filters'    => true,
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
			)
		);

		$front_page = (int) get_option( 'page_on_front' );

		foreach ( $posts as $post ) {
			if ( count( $urls ) >= $limit ) {
				break;
			}

			if ( $front_page && (int) $post->ID === $front_page ) {
				continue;
			}

			$permalink = get_permalink( $post );

			if ( ! $permalink ) {
				continue;
			}

			$urls[] = array(
				'url'     => $permalink,
				'label'   => '' !== $post->post_title ? $post->post_title : __( '(no title)', 'wp-site-toolkit' ),
				'post_id' => (int) $post->ID,
			);
		}

		return array_slice( $urls, 0, $limit );
	}

	/**
	 * Checks the sitemap, robots.txt and the site-wide indexing preference.
	 *
	 * @param array $state    Module state.
	 * @param array $settings Settings.
	 *
	 * @return array
	 */
	private function task_site( $state, $settings ) {
		$timeout = (int) $settings['scan_timeout'];

		$state['site'] = array(
			'blog_public' => (int) get_option( 'blog_public' ),
			'sitemap'     => $this->probe_sitemap( $timeout ),
			'robots'      => $this->probe_robots( $timeout ),
		);

		return $this->task_result( $state, null, 1, 1 );
	}

	/**
	 * Looks for a reachable XML sitemap.
	 *
	 * @param int $timeout Timeout in seconds.
	 *
	 * @return array
	 */
	private function probe_sitemap( $timeout ) {
		$candidates = array( home_url( '/wp-sitemap.xml' ), home_url( '/sitemap_index.xml' ), home_url( '/sitemap.xml' ) );

		foreach ( $candidates as $candidate ) {
			$result = WPSTK_HTTP::check_url( $candidate, $timeout );

			if ( 'ok' === $result['type'] ) {
				return array(
					'found'  => true,
					'url'    => $result['final_url'],
					'status' => $result['status'],
					'source' => $candidate,
				);
			}
		}

		return array(
			'found'  => false,
			'url'    => $candidates[0],
			'status' => 0,
			'source' => '',
		);
	}

	/**
	 * Fetches robots.txt and reports what it contains.
	 *
	 * @param int $timeout Timeout in seconds.
	 *
	 * @return array
	 */
	private function probe_robots( $timeout ) {
		$url      = home_url( '/robots.txt' );
		$response = WPSTK_HTTP::fetch( $url, $timeout );

		$out = array(
			'found'        => false,
			'url'          => $url,
			'status'       => (int) $response['status'],
			'blocks_all'   => false,
			'has_sitemap'  => false,
			'virtual'      => ! $this->physical_robots_exists(),
		);

		if ( ! $response['success'] || 200 !== (int) $response['status'] ) {
			return $out;
		}

		$body         = (string) $response['body'];
		$out['found'] = true;

		if ( preg_match( '~^\s*disallow:\s*/\s*$~im', $body ) ) {
			$out['blocks_all'] = true;
		}

		if ( preg_match( '~^\s*sitemap:\s*\S+~im', $body ) ) {
			$out['has_sitemap'] = true;
		}

		return $out;
	}

	/**
	 * Whether a physical robots.txt file sits in the site root.
	 *
	 * @return bool
	 */
	private function physical_robots_exists() {
		return file_exists( ABSPATH . 'robots.txt' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function finalize( $state, $settings, $all_state ) {
		$checks  = array();
		$content = $this->state_get( $state, 'content', array() );
		$pages   = $this->state_get( $state, 'pages', array() );
		$site    = $this->state_get( $state, 'site', array() );

		$checks = array_merge( $checks, $this->finalize_content( $content, $settings ) );
		$checks = array_merge( $checks, $this->finalize_pages( $pages ) );
		$checks = array_merge( $checks, $this->finalize_site( $site ) );

		return $checks;
	}

	/**
	 * Builds the checks derived from stored titles and descriptions.
	 *
	 * @param array $content  Content state.
	 * @param array $settings Settings.
	 *
	 * @return array[]
	 */
	private function finalize_content( $content, $settings ) {
		if ( empty( $content ) || empty( $content['total'] ) ) {
			return array(
				$this->skipped( 'meta_title', __( 'Meta titles', 'wp-site-toolkit' ), __( 'No published content was found to analyse.', 'wp-site-toolkit' ) ),
				$this->skipped( 'meta_description', __( 'Meta descriptions', 'wp-site-toolkit' ), __( 'No published content was found to analyse.', 'wp-site-toolkit' ) ),
			);
		}

		$counts = $content['counts'];
		$total  = (int) $content['total'];
		$note   = '';

		if ( empty( $content['covered_all'] ) ) {
			$note = sprintf(
				/* translators: 1: number of entries analysed, 2: total number of published entries. */
				__( 'Analysed the first %1$d of %2$d published entries. Raise the "Maximum posts per scan" setting to cover more.', 'wp-site-toolkit' ),
				$total,
				(int) $content['available']
			);
		}

		$checks = array();

		// Titles.
		$title_items = array();

		foreach ( $content['title_missing'] as $row ) {
			$title_items[] = WPSTK_Check::post_item( $row[0], $row[1], __( 'No title', 'wp-site-toolkit' ) );
		}

		foreach ( $content['title_long'] as $row ) {
			$title_items[] = WPSTK_Check::post_item( $row[0], $row[1], $row[2] );
		}

		foreach ( $content['title_short'] as $row ) {
			$title_items[] = WPSTK_Check::post_item( $row[0], $row[1], $row[2] );
		}

		$title_problems = $counts['title_missing'] + $counts['title_long'] + $counts['title_short'];

		if ( $counts['title_missing'] > 0 ) {
			$status = 'critical';
		} elseif ( $counts['title_long'] > 0 ) {
			$status = 'warning';
		} elseif ( $counts['title_short'] > 0 ) {
			$status = 'recommendation';
		} else {
			$status = 'passed';
		}

		$checks[] = $this->check(
			array(
				'id'          => 'meta_title',
				'status'      => $status,
				'label'       => __( 'Meta titles', 'wp-site-toolkit' ),
				'weight'      => 1.5,
				'summary'     => 'passed' === $status
					? sprintf(
						/* translators: %d: number of entries. */
						_n( '%d entry has a usable title.', 'All %d entries have a usable title.', $total, 'wp-site-toolkit' ),
						$total
					)
					: sprintf(
						/* translators: 1: number of entries with title problems, 2: number of entries checked, 3: missing count, 4: too long count, 5: too short count. */
						__( '%1$d of %2$d entries have a title worth revisiting: %3$d empty, %4$d over 60 characters, %5$d under 30 characters.', 'wp-site-toolkit' ),
						$title_problems,
						$total,
						$counts['title_missing'],
						$counts['title_long'],
						$counts['title_short']
					),
				'why'         => __( 'The title is usually the first thing people read in search results and browser tabs. Empty titles give search engines nothing to work with, and very long ones are shortened before they are shown.', 'wp-site-toolkit' ),
				'action'      => __( 'Give every entry a descriptive title of roughly 30 to 60 characters. If you use an SEO plugin, set the SEO title there; otherwise the post title is used.', 'wp-site-toolkit' ),
				'note'        => $note,
				'items'       => $title_items,
				'items_total' => $title_problems,
			)
		);

		// Duplicate titles.
		if ( $counts['title_dupes'] > 0 ) {
			$dupe_items = array();

			foreach ( $content['title_dupes'] as $row ) {
				$dupe_items[] = WPSTK_Check::post_item( $row[0], $row[1], $row[2] );
			}

			$checks[] = $this->check(
				array(
					'id'          => 'duplicate_titles',
					'status'      => 'warning',
					'label'       => __( 'Duplicate titles', 'wp-site-toolkit' ),
					'summary'     => sprintf(
						/* translators: %d: number of entries. */
						_n( '%d entry shares its title with another entry.', '%d entries share a title with another entry.', $counts['title_dupes'], 'wp-site-toolkit' ),
						$counts['title_dupes']
					),
					'why'         => __( 'When several pages use the same title, search engines and visitors cannot tell them apart in a list of results.', 'wp-site-toolkit' ),
					'action'      => __( 'Give each page a title that describes what makes it different.', 'wp-site-toolkit' ),
					'note'        => $note,
					'items'       => $dupe_items,
					'items_total' => $counts['title_dupes'],
				)
			);
		} else {
			$checks[] = $this->passed(
				'duplicate_titles',
				__( 'Duplicate titles', 'wp-site-toolkit' ),
				__( 'Every title analysed is unique.', 'wp-site-toolkit' ),
				__( 'Unique titles help search engines and visitors tell your pages apart.', 'wp-site-toolkit' )
			);
		}

		// Descriptions.
		$desc_items = array();

		foreach ( $content['desc_missing'] as $row ) {
			$desc_items[] = WPSTK_Check::post_item( $row[0], $row[1], __( 'No description or excerpt', 'wp-site-toolkit' ) );
		}

		foreach ( $content['desc_long'] as $row ) {
			$desc_items[] = WPSTK_Check::post_item( $row[0], $row[1], $row[2] );
		}

		foreach ( $content['desc_short'] as $row ) {
			$desc_items[] = WPSTK_Check::post_item( $row[0], $row[1], $row[2] );
		}

		$desc_problems = $counts['desc_missing'] + $counts['desc_long'] + $counts['desc_short'];

		if ( $counts['desc_missing'] > ( $total / 2 ) ) {
			$status = 'warning';
		} elseif ( $desc_problems > 0 ) {
			$status = 'recommendation';
		} else {
			$status = 'passed';
		}

		$checks[] = $this->check(
			array(
				'id'          => 'meta_description',
				'status'      => $status,
				'label'       => __( 'Meta descriptions', 'wp-site-toolkit' ),
				'weight'      => 1.5,
				'summary'     => 'passed' === $status
					? __( 'Every entry analysed has a description of a reasonable length.', 'wp-site-toolkit' )
					: sprintf(
						/* translators: 1: entries with description problems, 2: entries checked, 3: missing count, 4: too short count, 5: too long count. */
						__( '%1$d of %2$d entries could use a better description: %3$d with none, %4$d under 70 characters, %5$d over 160 characters.', 'wp-site-toolkit' ),
						$desc_problems,
						$total,
						$counts['desc_missing'],
						$counts['desc_short'],
						$counts['desc_long']
					),
				'why'         => __( 'Search engines often show the meta description underneath the title. Without one, they pick an arbitrary sentence from the page.', 'wp-site-toolkit' ),
				'action'      => __( 'Write a description of roughly 70 to 160 characters for your most important pages. An excerpt is used when no SEO description is set.', 'wp-site-toolkit' ),
				'note'        => $note,
				'items'       => $desc_items,
				'items_total' => $desc_problems,
			)
		);

		return $checks;
	}

	/**
	 * Builds the checks derived from the rendered page sample.
	 *
	 * @param array $pages Pages state.
	 *
	 * @return array[]
	 */
	private function finalize_pages( $pages ) {
		$labels = array(
			'canonical' => __( 'Canonical URLs', 'wp-site-toolkit' ),
			'h1'        => __( 'H1 headings', 'wp-site-toolkit' ),
			'hierarchy' => __( 'Heading structure', 'wp-site-toolkit' ),
			'og'        => __( 'Open Graph tags', 'wp-site-toolkit' ),
			'noindex'   => __( 'Page level indexability', 'wp-site-toolkit' ),
			'head'      => __( 'Rendered title and description tags', 'wp-site-toolkit' ),
		);

		if ( empty( $pages ) || empty( $pages['fetched'] ) ) {
			$reason = __( 'The plugin could not load any pages from this site, so the rendered markup was not inspected.', 'wp-site-toolkit' );

			if ( ! empty( $pages['first_error'] ) ) {
				$reason .= ' ' . sprintf(
					/* translators: %s: error message. */
					__( 'Last error: %s', 'wp-site-toolkit' ),
					$pages['first_error']
				);
			}

			$checks = array();

			foreach ( $labels as $key => $label ) {
				$checks[] = $this->skipped( 'page_' . $key, $label, $reason );
			}

			return $checks;
		}

		$fetched = (int) $pages['fetched'];
		$note    = sprintf(
			/* translators: %d: number of pages loaded. */
			_n( 'Based on %d page loaded from this site.', 'Based on %d pages loaded from this site.', $fetched, 'wp-site-toolkit' ),
			$fetched
		);

		$checks = array();

		$checks[] = $this->page_check(
			'page_canonical',
			$labels['canonical'],
			array_merge( $pages['no_canonical'], $pages['bad_canonical'] ),
			count( $pages['no_canonical'] ) > 0 ? 'warning' : ( count( $pages['bad_canonical'] ) > 0 ? 'warning' : 'passed' ),
			__( 'A canonical URL tells search engines which address is the official one for a page.', 'wp-site-toolkit' ),
			__( 'WordPress adds canonical tags automatically on most sites. If they are missing, check your theme header and any SEO plugin settings.', 'wp-site-toolkit' ),
			__( 'Every sampled page has a single, valid canonical URL.', 'wp-site-toolkit' ),
			$note,
			1.2
		);

		$h1_items  = array_merge( $pages['no_h1'], $pages['many_h1'] );
		$h1_status = ! empty( $pages['no_h1'] ) ? 'warning' : ( ! empty( $pages['many_h1'] ) ? 'recommendation' : 'passed' );

		$checks[] = $this->page_check(
			'page_h1',
			$labels['h1'],
			$h1_items,
			$h1_status,
			__( 'One clear H1 describes what a page is about, for both readers and assistive technology.', 'wp-site-toolkit' ),
			__( 'Make sure each page renders exactly one H1, usually the page title supplied by your theme.', 'wp-site-toolkit' ),
			__( 'Every sampled page renders exactly one H1.', 'wp-site-toolkit' ),
			$note,
			1.2
		);

		$checks[] = $this->page_check(
			'page_hierarchy',
			$labels['hierarchy'],
			$pages['hierarchy'],
			! empty( $pages['hierarchy'] ) ? 'recommendation' : 'passed',
			__( 'Headings work best as an outline: H2 under H1, H3 under H2. Skipping a level makes the structure harder to follow with a screen reader.', 'wp-site-toolkit' ),
			__( 'Reorder the headings on the listed pages so no level is skipped.', 'wp-site-toolkit' ),
			__( 'No skipped heading levels were found on the sampled pages.', 'wp-site-toolkit' ),
			$note
		);

		$checks[] = $this->page_check(
			'page_open_graph',
			$labels['og'],
			$pages['no_og'],
			! empty( $pages['no_og'] ) ? 'recommendation' : 'passed',
			__( 'Open Graph tags control the title, description and image used when a page is shared on social networks and chat apps.', 'wp-site-toolkit' ),
			__( 'Add Open Graph tags through your theme or an SEO plugin. At minimum set og:title, og:description and og:image.', 'wp-site-toolkit' ),
			__( 'The sampled pages all provide og:title, og:description and og:image.', 'wp-site-toolkit' ),
			$note
		);

		$checks[] = $this->page_check(
			'page_noindex',
			$labels['noindex'],
			$pages['noindex'],
			! empty( $pages['noindex'] ) ? 'warning' : 'passed',
			__( 'A noindex directive asks search engines to leave the page out of their results. That is sometimes intentional, but it is worth confirming.', 'wp-site-toolkit' ),
			__( 'Open each listed page and remove the noindex setting if the page should be findable.', 'wp-site-toolkit' ),
			__( 'None of the sampled pages asks search engines to skip it.', 'wp-site-toolkit' ),
			$note,
			1.3
		);

		$head_items  = array_merge( $pages['no_title'], $pages['no_desc'] );
		$head_status = ! empty( $pages['no_title'] ) ? 'critical' : ( ! empty( $pages['no_desc'] ) ? 'recommendation' : 'passed' );

		$checks[] = $this->page_check(
			'page_head_tags',
			$labels['head'],
			$head_items,
			$head_status,
			__( 'This confirms what actually reaches the browser, which can differ from what is stored in the database.', 'wp-site-toolkit' ),
			__( 'If a title tag is missing entirely, check that your theme calls wp_head() and that no plugin is removing it.', 'wp-site-toolkit' ),
			__( 'Every sampled page outputs a title tag and a meta description.', 'wp-site-toolkit' ),
			$note
		);

		return $checks;
	}

	/**
	 * Helper that turns a list of page findings into a check.
	 *
	 * @param string $id         Check ID.
	 * @param string $label      Check name.
	 * @param array  $rows       Findings.
	 * @param string $status     Status.
	 * @param string $why        Why it matters.
	 * @param string $action     What to do.
	 * @param string $passed_msg Message shown when nothing was found.
	 * @param string $note       Caveat.
	 * @param float  $weight     Score weight.
	 *
	 * @return array
	 */
	private function page_check( $id, $label, $rows, $status, $why, $action, $passed_msg, $note, $weight = 1.0 ) {
		$items = array();

		foreach ( (array) $rows as $row ) {
			$post_id = (int) $row[0];

			$items[] = WPSTK_Check::item(
				array(
					'label'      => (string) $row[1],
					'detail'     => isset( $row[3] ) ? (string) $row[3] : '',
					'url'        => (string) $row[2],
					'link'       => $post_id > 0 ? (string) get_edit_post_link( $post_id, 'raw' ) : '',
					'link_label' => $post_id > 0 ? __( 'Edit', 'wp-site-toolkit' ) : '',
				)
			);
		}

		$count = count( $items );

		return $this->check(
			array(
				'id'          => $id,
				'status'      => $status,
				'label'       => $label,
				'weight'      => $weight,
				'summary'     => 'passed' === $status
					? $passed_msg
					: sprintf(
						/* translators: %d: number of pages. */
						_n( '%d sampled page needs attention.', '%d sampled pages need attention.', $count, 'wp-site-toolkit' ),
						$count
					),
				'why'         => $why,
				'action'      => $action,
				'note'        => $note,
				'items'       => $items,
				'items_total' => $count,
			)
		);
	}

	/**
	 * Builds the site level checks.
	 *
	 * @param array $site Site state.
	 *
	 * @return array[]
	 */
	private function finalize_site( $site ) {
		if ( empty( $site ) ) {
			return array();
		}

		$checks = array();

		// Site wide indexability.
		if ( 0 === (int) $site['blog_public'] ) {
			$checks[] = $this->check(
				array(
					'id'      => 'search_visibility',
					'status'  => 'critical',
					'label'   => __( 'Search engine visibility', 'wp-site-toolkit' ),
					'weight'  => 2.0,
					'summary' => __( 'This site asks search engines not to index it.', 'wp-site-toolkit' ),
					'why'     => __( 'While this setting is on, WordPress asks search engines to stay away. That is right for a staging site and wrong for a live one.', 'wp-site-toolkit' ),
					'action'  => __( 'If this site is live, turn off "Discourage search engines from indexing this site" in Settings → Reading.', 'wp-site-toolkit' ),
					'items'   => array(
						WPSTK_Check::item(
							array(
								'label'      => __( 'Reading settings', 'wp-site-toolkit' ),
								'detail'     => __( 'Discourage search engines is enabled', 'wp-site-toolkit' ),
								'link'       => admin_url( 'options-reading.php' ),
								'link_label' => __( 'Open settings', 'wp-site-toolkit' ),
							)
						),
					),
				)
			);
		} else {
			$checks[] = $this->passed(
				'search_visibility',
				__( 'Search engine visibility', 'wp-site-toolkit' ),
				__( 'Search engines are allowed to index this site.', 'wp-site-toolkit' ),
				__( 'A live site should let search engines in.', 'wp-site-toolkit' )
			);
		}

		// Sitemap.
		$sitemap = $site['sitemap'];

		if ( ! empty( $sitemap['found'] ) ) {
			$checks[] = $this->check(
				array(
					'id'      => 'sitemap',
					'status'  => 'passed',
					'label'   => __( 'XML sitemap', 'wp-site-toolkit' ),
					'weight'  => 1.2,
					'summary' => __( 'A sitemap is reachable.', 'wp-site-toolkit' ),
					'why'     => __( 'A sitemap gives search engines a complete list of the pages you want them to find.', 'wp-site-toolkit' ),
					'items'   => array(
						WPSTK_Check::item(
							array(
								'label'      => $sitemap['url'],
								'detail'     => sprintf(
									/* translators: %d: HTTP status code. */
									__( 'HTTP %d', 'wp-site-toolkit' ),
									(int) $sitemap['status']
								),
								'url'        => $sitemap['url'],
								'link'       => $sitemap['url'],
								'link_label' => __( 'View sitemap', 'wp-site-toolkit' ),
							)
						),
					),
				)
			);
		} else {
			$checks[] = $this->check(
				array(
					'id'      => 'sitemap',
					'status'  => 'warning',
					'label'   => __( 'XML sitemap', 'wp-site-toolkit' ),
					'weight'  => 1.2,
					'summary' => __( 'No sitemap was found at the usual addresses.', 'wp-site-toolkit' ),
					'why'     => __( 'Without a sitemap, search engines have to discover your pages by following links, which is slower and less complete.', 'wp-site-toolkit' ),
					'action'  => __( 'WordPress publishes /wp-sitemap.xml by default. If it is missing, check whether a plugin or theme disabled it, or enable the sitemap feature in your SEO plugin.', 'wp-site-toolkit' ),
					'note'    => __( 'Addresses checked: /wp-sitemap.xml, /sitemap_index.xml, /sitemap.xml', 'wp-site-toolkit' ),
				)
			);
		}

		// Robots.txt.
		$robots = $site['robots'];

		if ( empty( $robots['found'] ) ) {
			$checks[] = $this->check(
				array(
					'id'      => 'robots_txt',
					'status'  => 'warning',
					'label'   => __( 'robots.txt', 'wp-site-toolkit' ),
					'summary' => sprintf(
						/* translators: %d: HTTP status code. */
						__( 'robots.txt could not be read (HTTP %d).', 'wp-site-toolkit' ),
						(int) $robots['status']
					),
					'why'     => __( 'Crawlers request robots.txt before anything else. A server error there can slow down or block crawling.', 'wp-site-toolkit' ),
					'action'  => __( 'Open /robots.txt in a browser. WordPress generates one automatically unless a real file or a redirect gets in the way.', 'wp-site-toolkit' ),
				)
			);
		} elseif ( ! empty( $robots['blocks_all'] ) ) {
			$checks[] = $this->check(
				array(
					'id'      => 'robots_txt',
					'status'  => 'critical',
					'label'   => __( 'robots.txt', 'wp-site-toolkit' ),
					'weight'  => 1.5,
					'summary' => __( 'robots.txt contains a rule that blocks the whole site.', 'wp-site-toolkit' ),
					'why'     => __( 'A "Disallow: /" rule asks well behaved crawlers to skip every page on the site.', 'wp-site-toolkit' ),
					'action'  => __( 'Remove the site-wide Disallow rule unless this site is meant to stay out of search results.', 'wp-site-toolkit' ),
					'items'   => array(
						WPSTK_Check::item(
							array(
								'label'      => $robots['url'],
								'url'        => $robots['url'],
								'link'       => $robots['url'],
								'link_label' => __( 'View robots.txt', 'wp-site-toolkit' ),
							)
						),
					),
				)
			);
		} else {
			$checks[] = $this->check(
				array(
					'id'      => 'robots_txt',
					'status'  => 'passed',
					'label'   => __( 'robots.txt', 'wp-site-toolkit' ),
					'summary' => empty( $robots['virtual'] )
						? __( 'A robots.txt file is served from the site root.', 'wp-site-toolkit' )
						: __( 'WordPress is serving a generated robots.txt.', 'wp-site-toolkit' ),
					'why'     => __( 'Crawlers read robots.txt before requesting anything else.', 'wp-site-toolkit' ),
					'items'   => array(
						WPSTK_Check::item(
							array(
								'label'      => $robots['url'],
								'detail'     => ! empty( $robots['has_sitemap'] )
									? __( 'Includes a Sitemap directive', 'wp-site-toolkit' )
									: __( 'No Sitemap directive', 'wp-site-toolkit' ),
								'url'        => $robots['url'],
								'link'       => $robots['url'],
								'link_label' => __( 'View robots.txt', 'wp-site-toolkit' ),
							)
						),
					),
				)
			);
		}

		return $checks;
	}
}
