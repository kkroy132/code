<?php
/**
 * Link audit module.
 *
 * @package WP_Site_Toolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Finds broken links, redirect chains, thin internal linking and orphaned
 * content, and summarises the 404 log.
 *
 * @since 1.0.0
 */
class WPSTK_Module_Links extends WPSTK_Module {

	/**
	 * Per-request cache mapping URLs to post IDs.
	 *
	 * @var array
	 */
	private $post_id_cache = array();

	/**
	 * Resolves a URL to a post ID, caching the answer for the current request.
	 *
	 * @param string $url Absolute URL.
	 *
	 * @return int
	 */
	private function resolve_post_id( $url ) {
		if ( ! isset( $this->post_id_cache[ $url ] ) ) {
			if ( count( $this->post_id_cache ) > 2000 ) {
				$this->post_id_cache = array();
			}

			$this->post_id_cache[ $url ] = (int) url_to_postid( $url );
		}

		return $this->post_id_cache[ $url ];
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_id() {
		return 'links';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label() {
		return __( 'Links', 'site-toolkit' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Broken links, redirects, internal linking, orphaned content and recorded 404 requests.', 'site-toolkit' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_icon() {
		return 'dashicons-admin-links';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_tasks( $settings ) {
		$tasks = array(
			array(
				'id'    => 'collect',
				'label' => __( 'Collecting links from your content', 'site-toolkit' ),
			),
			array(
				'id'    => 'internal',
				'label' => __( 'Checking internal links', 'site-toolkit' ),
			),
		);

		if ( ! empty( $settings['scan_external'] ) ) {
			$tasks[] = array(
				'id'    => 'external',
				'label' => __( 'Checking external links', 'site-toolkit' ),
			);
		}

		return $tasks;
	}

	/**
	 * {@inheritDoc}
	 */
	public function run_task( $task_id, $offset, $state, $settings ) {
		switch ( $task_id ) {
			case 'collect':
				return $this->task_collect( $offset, $state, $settings );
			case 'internal':
				return $this->task_check( 'internal', $offset, $state, $settings );
			case 'external':
				return $this->task_check( 'external', $offset, $state, $settings );
		}

		return $this->task_result( $state );
	}

	/**
	 * Extracts links from published content.
	 *
	 * @param int   $offset   Offset.
	 * @param array $state    Module state.
	 * @param array $settings Settings.
	 *
	 * @return array
	 */
	private function task_collect( $offset, $state, $settings ) {
		if ( ! isset( $state['collect'] ) ) {
			$state['collect'] = array(
				'posts_scanned'  => 0,
				'internal'       => array(),
				'external'       => array(),
				'internal_total' => 0,
				'external_total' => 0,
				'inbound'        => array(),
				'post_titles'    => array(),
				'post_links_out' => array(),
				'covered_all'    => true,
				'available'      => 0,
			);
		}

		$max   = (int) $settings['max_posts'];
		$batch = min( (int) $settings['batch_posts'], max( 1, $max - $offset ) );

		$available                     = WPSTK_Content::count_posts();
		$state['collect']['available'] = $available;
		$total                         = min( $available, $max );

		if ( $offset >= $total || $batch <= 0 ) {
			$state['collect']['covered_all'] = $available <= $max;

			return $this->task_result( $state, null, 0, $total );
		}

		$posts = WPSTK_Content::get_post_batch( $offset, $batch );

		if ( empty( $posts ) ) {
			$state['collect']['covered_all'] = $available <= $max;

			return $this->task_result( $state, null, 0, $total );
		}

		$max_urls = (int) $settings['max_urls'];
		$home     = home_url( '/' );

		foreach ( $posts as $post ) {
			$post_id = (int) $post['ID'];
			++$state['collect']['posts_scanned'];

			$state['collect']['post_titles'][ $post_id ] = WPSTK_Content::shorten( (string) $post['post_title'], 80 );

			if ( ! isset( $state['collect']['inbound'][ $post_id ] ) ) {
				$state['collect']['inbound'][ $post_id ] = 0;
			}

			$outbound = 0;
			$hrefs    = WPSTK_HTML::get_links( (string) $post['post_content'] );

			foreach ( $hrefs as $href ) {
				if ( WPSTK_Content::is_skippable_href( $href ) ) {
					continue;
				}

				$url = WPSTK_HTTP::resolve_url( $href, $home );

				if ( ! WPSTK_HTTP::is_requestable( $url ) ) {
					continue;
				}

				$url = $this->normalise_url( $url );

				if ( WPSTK_Content::is_internal_url( $url ) ) {
					++$state['collect']['internal_total'];
					++$outbound;

					$target = $this->resolve_post_id( $url );

					if ( $target > 0 && $target !== $post_id ) {
						if ( ! isset( $state['collect']['inbound'][ $target ] ) ) {
							$state['collect']['inbound'][ $target ] = 0;
						}

						++$state['collect']['inbound'][ $target ];
					}

					if ( count( $state['collect']['internal'] ) < $max_urls && ! isset( $state['collect']['internal'][ $url ] ) ) {
						$state['collect']['internal'][ $url ] = array( $post_id, WPSTK_Content::shorten( (string) $post['post_title'], 60 ) );
					}
				} else {
					++$state['collect']['external_total'];

					if ( count( $state['collect']['external'] ) < $max_urls && ! isset( $state['collect']['external'][ $url ] ) ) {
						$state['collect']['external'][ $url ] = array( $post_id, WPSTK_Content::shorten( (string) $post['post_title'], 60 ) );
					}
				}
			}

			$state['collect']['post_links_out'][ $post_id ] = $outbound;
		}

		$processed = count( $posts );
		$next      = $offset + $processed;

		if ( $next >= $total ) {
			$state['collect']['covered_all'] = $available <= $max;

			return $this->task_result( $state, null, $processed, $total );
		}

		return $this->task_result( $state, $next, $processed, $total );
	}

	/**
	 * Requests a batch of collected URLs.
	 *
	 * @param string $kind     Either `internal` or `external`.
	 * @param int    $offset   Offset.
	 * @param array  $state    Module state.
	 * @param array  $settings Settings.
	 *
	 * @return array
	 */
	private function task_check( $kind, $offset, $state, $settings ) {
		$collected = isset( $state['collect'][ $kind ] ) ? $state['collect'][ $kind ] : array();
		$urls      = array_keys( $collected );
		$total     = count( $urls );

		if ( ! isset( $state['results'][ $kind ] ) ) {
			$state['results'][ $kind ] = array(
				'ok'        => 0,
				'redirects' => array(),
				'broken'    => array(),
				'checked'   => 0,
			);
		}

		if ( 0 === $total || $offset >= $total ) {
			return $this->task_result( $state, null, 0, $total );
		}

		$batch   = array_slice( $urls, $offset, max( 1, (int) $settings['batch_urls'] ) );
		$timeout = (int) $settings['scan_timeout'];

		foreach ( $batch as $url ) {
			$result = WPSTK_HTTP::check_url( $url, $timeout );
			++$state['results'][ $kind ]['checked'];

			$source = isset( $collected[ $url ] ) ? $collected[ $url ] : array( 0, '' );

			if ( 'ok' === $result['type'] ) {
				++$state['results'][ $kind ]['ok'];

				if ( count( $result['chain'] ) > 1 ) {
					$state['results'][ $kind ]['redirects'] = $this->push_sample(
						$state['results'][ $kind ]['redirects'],
						array(
							'url'    => $url,
							'final'  => $result['final_url'],
							'hops'   => count( $result['chain'] ) - 1,
							'post'   => (int) $source[0],
							'title'  => (string) $source[1],
							'status' => (int) $result['status'],
						)
					);
				}

				continue;
			}

			$state['results'][ $kind ]['broken'] = $this->push_sample(
				$state['results'][ $kind ]['broken'],
				array(
					'url'     => $url,
					'type'    => $result['type'],
					'status'  => (int) $result['status'],
					'message' => WPSTK_Content::shorten( $result['message'], 120 ),
					'post'    => (int) $source[0],
					'title'   => (string) $source[1],
				),
				50
			);
		}

		$processed = count( $batch );
		$next      = $offset + $processed;

		if ( $next >= $total ) {
			return $this->task_result( $state, null, $processed, $total );
		}

		return $this->task_result( $state, $next, $processed, $total );
	}

	/**
	 * Strips the fragment from a URL so the same page is not checked twice.
	 *
	 * @param string $url Absolute URL.
	 *
	 * @return string
	 */
	private function normalise_url( $url ) {
		$position = strpos( $url, '#' );

		if ( false !== $position ) {
			$url = substr( $url, 0, $position );
		}

		return $url;
	}

	/**
	 * {@inheritDoc}
	 */
	public function finalize( $state, $settings, $all_state ) {
		$collect = $this->state_get( $state, 'collect', array() );
		$results = $this->state_get( $state, 'results', array() );

		$checks = array();

		$checks[] = $this->broken_check( 'internal', $results, $collect );
		$checks[] = $this->external_check( $results, $collect, $settings );
		$checks[] = $this->redirect_check( $results );
		$checks   = array_merge( $checks, $this->internal_linking_checks( $collect ) );
		$checks[] = $this->not_found_check( $settings );
		$checks[] = $this->redirect_manager_check();

		return array_filter( $checks );
	}

	/**
	 * Summarises the configured redirects.
	 *
	 * Always reports as passed: this is a reference figure about a feature the
	 * site owner controls directly, not something the audit judges.
	 *
	 * @return array
	 */
	private function redirect_manager_check() {
		if ( ! class_exists( 'WPSTK_Redirects' ) ) {
			return array();
		}

		$label = __( 'Redirect manager', 'site-toolkit' );
		$total = WPSTK_Redirects::count_all();

		if ( 0 === $total ) {
			return $this->check(
				array(
					'id'      => 'redirect_manager',
					'status'  => 'passed',
					'label'   => $label,
					'weight'  => 0.3,
					'summary' => __( 'No redirects have been created yet.', 'site-toolkit' ),
					'why'     => __( 'When a page moves or is renamed, a redirect keeps old links and search rankings working instead of leading to a 404.', 'site-toolkit' ),
					'action'  => __( 'Redirects can be created from the 404 monitor or from the Redirects tab in this section.', 'site-toolkit' ),
					'items'   => array(
						WPSTK_Check::item(
							array(
								'label'      => __( 'Redirects', 'site-toolkit' ),
								'link'       => admin_url( 'admin.php?page=site-toolkit-links&tab=redirects' ),
								'link_label' => __( 'Open', 'site-toolkit' ),
							)
						),
					),
				)
			);
		}

		$active = WPSTK_Redirects::count_enabled();
		$hits   = WPSTK_Redirects::total_hits();

		return $this->check(
			array(
				'id'      => 'redirect_manager',
				'status'  => 'passed',
				'label'   => $label,
				'weight'  => 0.3,
				'summary' => sprintf(
					/* translators: 1: number of active redirects, 2: total redirects configured, 3: total times used. */
					__( '%1$d of %2$d redirects are active and have been used %3$s times in total.', 'site-toolkit' ),
					$active,
					$total,
					number_format_i18n( $hits )
				),
				'why'     => __( 'Redirects send visitors and search engines from an old address to the right page instead of a dead end.', 'site-toolkit' ),
				'items'   => array(
					WPSTK_Check::item(
						array(
							'label'      => __( 'Manage redirects', 'site-toolkit' ),
							'link'       => admin_url( 'admin.php?page=site-toolkit-links&tab=redirects' ),
							'link_label' => __( 'Open', 'site-toolkit' ),
						)
					),
				),
			)
		);
	}

	/**
	 * Builds the broken internal link check.
	 *
	 * @param string $kind    Link kind.
	 * @param array  $results Result state.
	 * @param array  $collect Collected state.
	 *
	 * @return array
	 */
	private function broken_check( $kind, $results, $collect ) {
		$label = __( 'Broken internal links', 'site-toolkit' );

		if ( empty( $results[ $kind ]['checked'] ) ) {
			return $this->skipped( 'broken_internal', $label, __( 'No internal links were found in the content that was scanned.', 'site-toolkit' ) );
		}

		$data    = $results[ $kind ];
		$broken  = $data['broken'];
		$checked = (int) $data['checked'];
		$items   = array();
		$buckets = array();

		foreach ( $broken as $row ) {
			$buckets[ $row['type'] ] = isset( $buckets[ $row['type'] ] ) ? $buckets[ $row['type'] ] + 1 : 1;

			$items[] = WPSTK_Check::item(
				array(
					'label'      => WPSTK_Content::shorten( $row['url'], 90 ),
					'detail'     => sprintf(
						/* translators: 1: outcome, 2: linking page title. */
						__( '%1$s — linked from "%2$s"', 'site-toolkit' ),
						$row['message'],
						'' !== $row['title'] ? $row['title'] : __( '(no title)', 'site-toolkit' )
					),
					'url'        => $row['url'],
					'link'       => $row['post'] > 0 ? (string) get_edit_post_link( $row['post'], 'raw' ) : '',
					'link_label' => $row['post'] > 0 ? __( 'Edit source page', 'site-toolkit' ) : '',
				)
			);
		}

		$count = count( $broken );

		if ( 0 === $count ) {
			return $this->check(
				array(
					'id'      => 'broken_internal',
					'status'  => 'passed',
					'label'   => $label,
					'weight'  => 2.0,
					'summary' => sprintf(
						/* translators: %d: number of links checked. */
						_n( '%d internal link responded normally.', 'All %d internal links responded normally.', $checked, 'site-toolkit' ),
						$checked
					),
					'why'     => __( 'Internal links that lead nowhere waste visitors\' time and stop search engines from reaching your pages.', 'site-toolkit' ),
				)
			);
		}

		$serious = isset( $buckets['not_found'] ) || isset( $buckets['server'] );

		return $this->check(
			array(
				'id'          => 'broken_internal',
				'status'      => $serious ? 'critical' : 'warning',
				'label'       => $label,
				'weight'      => 2.0,
				'summary'     => sprintf(
					/* translators: 1: number of broken links, 2: number of links checked, 3: breakdown by outcome. */
					__( '%1$d of %2$d internal links did not respond normally (%3$s).', 'site-toolkit' ),
					$count,
					$checked,
					$this->describe_buckets( $buckets )
				),
				'why'         => __( 'Internal links that lead nowhere waste visitors\' time and stop search engines from reaching your pages.', 'site-toolkit' ),
				'action'      => __( 'Open each source page and either fix the address or remove the link. Timeouts are sometimes temporary — re-run the audit to confirm.', 'site-toolkit' ),
				'items'       => $items,
				'items_total' => $count,
			)
		);
	}

	/**
	 * Builds the external link check.
	 *
	 * @param array $results  Result state.
	 * @param array $collect  Collected state.
	 * @param array $settings Settings.
	 *
	 * @return array
	 */
	private function external_check( $results, $collect, $settings ) {
		$label = __( 'External links', 'site-toolkit' );
		$found = isset( $collect['external_total'] ) ? (int) $collect['external_total'] : 0;

		if ( empty( $settings['scan_external'] ) ) {
			return $this->skipped(
				'broken_external',
				$label,
				sprintf(
					/* translators: %d: number of external links found. */
					_n(
						'%d external link was found. External link checking is turned off, so no third-party site was contacted.',
						'%d external links were found. External link checking is turned off, so no third-party site was contacted.',
						$found,
						'site-toolkit'
					),
					$found
				)
			);
		}

		if ( empty( $results['external']['checked'] ) ) {
			return $this->skipped( 'broken_external', $label, __( 'No external links were found in the content that was scanned.', 'site-toolkit' ) );
		}

		$data    = $results['external'];
		$broken  = $data['broken'];
		$checked = (int) $data['checked'];
		$count   = count( $broken );
		$buckets = array();
		$items   = array();

		foreach ( $broken as $row ) {
			$buckets[ $row['type'] ] = isset( $buckets[ $row['type'] ] ) ? $buckets[ $row['type'] ] + 1 : 1;

			$items[] = WPSTK_Check::item(
				array(
					'label'      => WPSTK_Content::shorten( $row['url'], 90 ),
					'detail'     => sprintf(
						/* translators: 1: outcome, 2: linking page title. */
						__( '%1$s — linked from "%2$s"', 'site-toolkit' ),
						$row['message'],
						'' !== $row['title'] ? $row['title'] : __( '(no title)', 'site-toolkit' )
					),
					'url'        => $row['url'],
					'link'       => $row['post'] > 0 ? (string) get_edit_post_link( $row['post'], 'raw' ) : '',
					'link_label' => $row['post'] > 0 ? __( 'Edit source page', 'site-toolkit' ) : '',
				)
			);
		}

		if ( 0 === $count ) {
			return $this->check(
				array(
					'id'      => 'broken_external',
					'status'  => 'passed',
					'label'   => $label,
					'summary' => sprintf(
						/* translators: %d: number of links checked. */
						_n( '%d external link responded normally.', 'All %d external links responded normally.', $checked, 'site-toolkit' ),
						$checked
					),
					'why'     => __( 'Links to sites that have disappeared make your content look neglected.', 'site-toolkit' ),
				)
			);
		}

		return $this->check(
			array(
				'id'          => 'broken_external',
				'status'      => 'warning',
				'label'       => $label,
				'summary'     => sprintf(
					/* translators: 1: number of failing links, 2: number checked, 3: breakdown. */
					__( '%1$d of %2$d external links did not respond normally (%3$s).', 'site-toolkit' ),
					$count,
					$checked,
					$this->describe_buckets( $buckets )
				),
				'why'         => __( 'Links to sites that have disappeared make your content look neglected.', 'site-toolkit' ),
				'action'      => __( 'Check the listed addresses in a browser. Some sites block automated requests, so treat "access denied" results as a hint rather than proof.', 'site-toolkit' ),
				'note'        => __( 'External sites sometimes rate limit or block automated requests. Verify before removing a link.', 'site-toolkit' ),
				'items'       => $items,
				'items_total' => $count,
			)
		);
	}

	/**
	 * Builds the redirect check.
	 *
	 * @param array $results Result state.
	 *
	 * @return array
	 */
	private function redirect_check( $results ) {
		$label     = __( 'Redirects and redirect chains', 'site-toolkit' );
		$redirects = array();

		foreach ( array( 'internal', 'external' ) as $kind ) {
			if ( ! empty( $results[ $kind ]['redirects'] ) ) {
				$redirects = array_merge( $redirects, $results[ $kind ]['redirects'] );
			}
		}

		$checked = 0;

		foreach ( array( 'internal', 'external' ) as $kind ) {
			$checked += isset( $results[ $kind ]['checked'] ) ? (int) $results[ $kind ]['checked'] : 0;
		}

		if ( 0 === $checked ) {
			return $this->skipped( 'redirects', $label, __( 'No links were available to check.', 'site-toolkit' ) );
		}

		if ( empty( $redirects ) ) {
			return $this->passed(
				'redirects',
				$label,
				__( 'No redirected links were found in your content.', 'site-toolkit' ),
				__( 'Linking straight to the final address saves a round trip for every visitor.', 'site-toolkit' )
			);
		}

		$chains = 0;
		$items  = array();

		foreach ( $redirects as $row ) {
			if ( $row['hops'] > 1 ) {
				++$chains;
			}

			$items[] = WPSTK_Check::item(
				array(
					'label'      => WPSTK_Content::shorten( $row['url'], 80 ),
					'detail'     => sprintf(
						/* translators: 1: number of redirect hops, 2: final URL. */
						_n( '%1$d redirect to %2$s', '%1$d redirects to %2$s', (int) $row['hops'], 'site-toolkit' ),
						(int) $row['hops'],
						WPSTK_Content::shorten( $row['final'], 70 )
					),
					'url'        => $row['final'],
					'link'       => $row['post'] > 0 ? (string) get_edit_post_link( $row['post'], 'raw' ) : '',
					'link_label' => $row['post'] > 0 ? __( 'Edit source page', 'site-toolkit' ) : '',
				)
			);
		}

		return $this->check(
			array(
				'id'          => 'redirects',
				'status'      => $chains > 0 ? 'warning' : 'recommendation',
				'label'       => $label,
				'summary'     => $chains > 0
					? sprintf(
						/* translators: 1: number of redirected links, 2: number that redirect more than once. */
						__( '%1$d links redirect before reaching their destination, and %2$d of those go through more than one hop.', 'site-toolkit' ),
						count( $redirects ),
						$chains
					)
					: sprintf(
						/* translators: %d: number of redirected links. */
						_n( '%d link redirects once before reaching its destination.', '%d links redirect once before reaching their destination.', count( $redirects ), 'site-toolkit' ),
						count( $redirects )
					),
				'why'         => __( 'Each redirect adds a round trip. Chains of two or more are slower still and can lose referral information.', 'site-toolkit' ),
				'action'      => __( 'Update the links to point straight at the final address.', 'site-toolkit' ),
				'items'       => $items,
				'items_total' => count( $redirects ),
			)
		);
	}

	/**
	 * Builds the internal linking and orphaned content checks.
	 *
	 * @param array $collect Collected state.
	 *
	 * @return array[]
	 */
	private function internal_linking_checks( $collect ) {
		$label_density = __( 'Internal linking', 'site-toolkit' );
		$label_orphan  = __( 'Orphaned content', 'site-toolkit' );

		if ( empty( $collect['posts_scanned'] ) ) {
			return array(
				$this->skipped( 'internal_linking', $label_density, __( 'No published content was available to analyse.', 'site-toolkit' ) ),
				$this->skipped( 'orphaned_content', $label_orphan, __( 'No published content was available to analyse.', 'site-toolkit' ) ),
			);
		}

		$scanned = (int) $collect['posts_scanned'];
		$note    = '';

		if ( empty( $collect['covered_all'] ) ) {
			$note = sprintf(
				/* translators: 1: entries analysed, 2: total published entries. */
				__( 'Based on the first %1$d of %2$d published entries, so a page linked from content outside that range may appear here incorrectly.', 'site-toolkit' ),
				$scanned,
				(int) $collect['available']
			);
		}

		$few_links = array();

		foreach ( (array) $collect['post_links_out'] as $post_id => $count ) {
			if ( $count < 1 ) {
				$few_links[] = WPSTK_Check::post_item(
					$post_id,
					isset( $collect['post_titles'][ $post_id ] ) ? $collect['post_titles'][ $post_id ] : '',
					__( 'No links to other pages on this site', 'site-toolkit' )
				);
			}

			if ( count( $few_links ) >= WPSTK_Check::MAX_ITEMS ) {
				break;
			}
		}

		$few_total = 0;

		foreach ( (array) $collect['post_links_out'] as $count ) {
			if ( $count < 1 ) {
				++$few_total;
			}
		}

		$orphans      = array();
		$orphan_total = 0;

		foreach ( (array) $collect['inbound'] as $post_id => $count ) {
			if ( $count > 0 ) {
				continue;
			}

			++$orphan_total;

			if ( count( $orphans ) < WPSTK_Check::MAX_ITEMS ) {
				$orphans[] = WPSTK_Check::post_item(
					$post_id,
					isset( $collect['post_titles'][ $post_id ] ) ? $collect['post_titles'][ $post_id ] : '',
					__( 'No links found from other scanned pages', 'site-toolkit' )
				);
			}
		}

		$checks = array();

		$checks[] = $this->check(
			array(
				'id'          => 'internal_linking',
				'status'      => $few_total > 0 ? 'recommendation' : 'passed',
				'label'       => $label_density,
				'summary'     => $few_total > 0
					? sprintf(
						/* translators: 1: entries without internal links, 2: entries analysed, 3: total internal links found. */
						__( '%1$d of %2$d entries link to nothing else on this site. %3$d internal links were found in total.', 'site-toolkit' ),
						$few_total,
						$scanned,
						(int) $collect['internal_total']
					)
					: sprintf(
						/* translators: 1: entries analysed, 2: total internal links found. */
						__( 'All %1$d entries link to at least one other page. %2$d internal links were found in total.', 'site-toolkit' ),
						$scanned,
						(int) $collect['internal_total']
					),
				'why'         => __( 'Internal links help readers find related content and help search engines understand how your pages relate to each other.', 'site-toolkit' ),
				'action'      => __( 'Add a few relevant links from these entries to other pages on your site.', 'site-toolkit' ),
				'note'        => $note,
				'items'       => $few_links,
				'items_total' => $few_total,
			)
		);

		$checks[] = $this->check(
			array(
				'id'          => 'orphaned_content',
				'status'      => $orphan_total > 0 ? 'recommendation' : 'passed',
				'label'       => $label_orphan,
				'summary'     => $orphan_total > 0
					? sprintf(
						/* translators: 1: number of entries, 2: entries analysed. */
						__( '%1$d of %2$d entries are not linked from any other scanned entry.', 'site-toolkit' ),
						$orphan_total,
						$scanned
					)
					: __( 'Every analysed entry is linked from at least one other entry.', 'site-toolkit' ),
				'why'         => __( 'Content that nothing links to is harder for both visitors and search engines to discover.', 'site-toolkit' ),
				'action'      => __( 'Link to these entries from related content, a menu or a listing page.', 'site-toolkit' ),
				'note'        => trim( $note . ' ' . __( 'Links from menus, widgets and archive pages are not counted, so some entries listed here may still be reachable.', 'site-toolkit' ) ),
				'items'       => $orphans,
				'items_total' => $orphan_total,
			)
		);

		return $checks;
	}

	/**
	 * Summarises the 404 log.
	 *
	 * @param array $settings Settings.
	 *
	 * @return array
	 */
	private function not_found_check( $settings ) {
		$label = __( '404 requests', 'site-toolkit' );

		if ( empty( $settings['monitor_404'] ) ) {
			return $this->skipped( 'not_found_log', $label, __( '404 monitoring is turned off in the settings.', 'site-toolkit' ) );
		}

		$recent = WPSTK_Not_Found_Monitor::get_recent( WPSTK_Check::MAX_ITEMS, 0, 30 );
		$total  = WPSTK_Not_Found_Monitor::count_recent( 30 );

		if ( 0 === $total ) {
			return $this->passed(
				'not_found_log',
				$label,
				__( 'No 404 requests have been recorded in the last 30 days.', 'site-toolkit' ),
				__( 'Repeated 404s usually mean a page moved without a redirect, or something links to an address that never existed.', 'site-toolkit' )
			);
		}

		$items = array();

		foreach ( $recent as $row ) {
			$items[] = WPSTK_Check::item(
				array(
					'label'  => WPSTK_Content::shorten( $row['url'], 90 ),
					'detail' => sprintf(
						/* translators: 1: number of hits, 2: date. */
						_n( '%1$d request, last seen %2$s', '%1$d requests, last seen %2$s', (int) $row['hits'], 'site-toolkit' ),
						(int) $row['hits'],
						mysql2date( get_option( 'date_format' ), $row['last_seen'] )
					),
					'url'    => home_url( $row['url'] ),
				)
			);
		}

		return $this->check(
			array(
				'id'          => 'not_found_log',
				'status'      => $total > 20 ? 'warning' : 'recommendation',
				'label'       => $label,
				'summary'     => sprintf(
					/* translators: %d: number of distinct addresses. */
					_n( '%d distinct address returned a 404 in the last 30 days.', '%d distinct addresses returned a 404 in the last 30 days.', $total, 'site-toolkit' ),
					$total
				),
				'why'         => __( 'Repeated 404s usually mean a page moved without a redirect, or something links to an address that never existed.', 'site-toolkit' ),
				'action'      => __( 'Review the list under Links → 404 monitor. Redirect the addresses that used to work and ignore the obvious bot noise.', 'site-toolkit' ),
				'items'       => $items,
				'items_total' => $total,
			)
		);
	}

	/**
	 * Renders a readable breakdown of failure types.
	 *
	 * @param array $buckets Counts keyed by failure type.
	 *
	 * @return string
	 */
	private function describe_buckets( $buckets ) {
		$parts = array();

		foreach ( $buckets as $type => $count ) {
			$count = (int) $count;

			switch ( $type ) {
				case 'not_found':
					/* translators: %d: number of links. */
					$parts[] = sprintf( _n( '%d not found', '%d not found', $count, 'site-toolkit' ), $count );
					break;
				case 'forbidden':
					/* translators: %d: number of links. */
					$parts[] = sprintf( _n( '%d access denied', '%d access denied', $count, 'site-toolkit' ), $count );
					break;
				case 'client':
					/* translators: %d: number of links. */
					$parts[] = sprintf( _n( '%d client error', '%d client errors', $count, 'site-toolkit' ), $count );
					break;
				case 'server':
					/* translators: %d: number of links. */
					$parts[] = sprintf( _n( '%d server error', '%d server errors', $count, 'site-toolkit' ), $count );
					break;
				case 'timeout':
					/* translators: %d: number of links. */
					$parts[] = sprintf( _n( '%d timed out', '%d timed out', $count, 'site-toolkit' ), $count );
					break;
				case 'redirect':
					/* translators: %d: number of links. */
					$parts[] = sprintf( _n( '%d redirect problem', '%d redirect problems', $count, 'site-toolkit' ), $count );
					break;
				default:
					/* translators: %d: number of links. */
					$parts[] = sprintf( _n( '%d unreachable', '%d unreachable', $count, 'site-toolkit' ), $count );
					break;
			}
		}

		return implode( ', ', $parts );
	}
}
