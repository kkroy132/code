<?php
/**
 * Performance audit module.
 *
 * @package WP_Site_Toolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Local performance checks: database size, revisions, autoloaded options,
 * media weight and a basic response time measurement.
 *
 * These are local checks. They do not replace a real page speed or Core Web
 * Vitals measurement taken from a visitor's browser.
 *
 * @since 1.0.0
 */
class WPSTK_Module_Performance extends WPSTK_Module {

	/**
	 * Autoloaded option size thresholds, in bytes.
	 */
	const AUTOLOAD_WARN     = 800000;
	const AUTOLOAD_CRITICAL = 2000000;

	/**
	 * {@inheritDoc}
	 */
	public function get_id() {
		return 'performance';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label() {
		return __( 'Performance', 'site-toolkit' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Database size, revisions, autoloaded options, media weight and a basic response time reading. Basic local check only.', 'site-toolkit' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_icon() {
		return 'dashicons-performance';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_tasks( $settings ) {
		return array(
			array(
				'id'    => 'database',
				'label' => __( 'Measuring the database', 'site-toolkit' ),
			),
			array(
				'id'    => 'response',
				'label' => __( 'Measuring server response time', 'site-toolkit' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function run_task( $task_id, $offset, $state, $settings ) {
		if ( 'database' === $task_id ) {
			$state['database'] = $this->collect_database();

			return $this->task_result( $state, null, 1, 1 );
		}

		if ( 'response' === $task_id ) {
			$state['response'] = $this->measure_response( $settings );

			return $this->task_result( $state, null, 1, 1 );
		}

		return $this->task_result( $state );
	}

	/**
	 * Collects database statistics.
	 *
	 * @return array
	 */
	private function collect_database() {
		global $wpdb;

		$data = array(
			'tables'         => array(),
			'total_bytes'    => 0,
			'overhead_bytes' => 0,
			'revisions'      => 0,
			'autodrafts'     => 0,
			'trashed'        => 0,
			'spam_comments'  => 0,
			'transients'     => 0,
			'autoload_bytes' => 0,
			'autoload_count' => 0,
			'autoload_top'   => array(),
			'counts'         => array(),
			'object_cache'   => wp_using_ext_object_cache(),
			'revision_limit' => defined( 'WP_POST_REVISIONS' ) ? WP_POST_REVISIONS : null,
		);

		$like = $wpdb->esc_like( $wpdb->prefix ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema statistics are not cacheable.
		$tables = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $like ), ARRAY_A );

		foreach ( (array) $tables as $table ) {
			$bytes    = (int) $table['Data_length'] + (int) $table['Index_length'];
			$overhead = isset( $table['Data_free'] ) ? (int) $table['Data_free'] : 0;

			$data['total_bytes']    += $bytes;
			$data['overhead_bytes'] += $overhead;

			$data['tables'][] = array(
				'name'  => (string) $table['Name'],
				'bytes' => $bytes,
				'rows'  => isset( $table['Rows'] ) ? (int) $table['Rows'] : 0,
			);
		}

		usort(
			$data['tables'],
			static function ( $a, $b ) {
				if ( $a['bytes'] === $b['bytes'] ) {
					return 0;
				}

				return $a['bytes'] > $b['bytes'] ? -1 : 1;
			}
		);

		$data['tables'] = array_slice( $data['tables'], 0, 8 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate count.
		$data['revisions'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate count.
		$data['autodrafts'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate count.
		$data['trashed'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate count.
		$data['spam_comments'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'" );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate count.
		$data['transients'] = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_' ) . '%' )
		);

		// WordPress 6.6 introduced additional autoload values alongside 'yes'.
		$autoload_values = array( 'yes', 'on', 'auto', 'auto-on' );
		$placeholders    = implode( ',', array_fill( 0, count( $autoload_values ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders only.
		$autoload_sql = "SELECT COUNT(*) AS total, SUM(LENGTH(option_value)) AS bytes FROM {$wpdb->options} WHERE autoload IN ({$placeholders})";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
		$autoload = $wpdb->get_row( $wpdb->prepare( $autoload_sql, $autoload_values ), ARRAY_A );

		if ( is_array( $autoload ) ) {
			$data['autoload_count'] = (int) $autoload['total'];
			$data['autoload_bytes'] = (int) $autoload['bytes'];
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders only.
		$top_sql = "SELECT option_name, LENGTH(option_value) AS bytes FROM {$wpdb->options}
			WHERE autoload IN ({$placeholders}) ORDER BY bytes DESC LIMIT 8";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
		$top = $wpdb->get_results( $wpdb->prepare( $top_sql, $autoload_values ), ARRAY_A );

		foreach ( (array) $top as $option ) {
			$data['autoload_top'][] = array(
				'name'  => (string) $option['option_name'],
				'bytes' => (int) $option['bytes'],
			);
		}

		foreach ( WPSTK_Content::get_post_types() as $type ) {
			$counts = wp_count_posts( $type );

			if ( $counts && isset( $counts->publish ) ) {
				$data['counts'][ $type ] = (int) $counts->publish;
			}
		}

		return $data;
	}

	/**
	 * Times a request to the site's front page.
	 *
	 * @param array $settings Settings.
	 *
	 * @return array
	 */
	private function measure_response( $settings ) {
		$timeout  = (int) $settings['scan_timeout'];
		$samples  = array();
		$statuses = array();

		for ( $i = 0; $i < 2; $i++ ) {
			$response = WPSTK_HTTP::fetch( home_url( '/' ), $timeout );

			if ( $response['success'] ) {
				$samples[]  = (float) $response['duration'];
				$statuses[] = (int) $response['status'];
			}
		}

		return array(
			'samples'  => $samples,
			'average'  => ! empty( $samples ) ? round( array_sum( $samples ) / count( $samples ), 3 ) : null,
			'statuses' => $statuses,
			'bytes'    => 0,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function finalize( $state, $settings, $all_state ) {
		$database = $this->state_get( $state, 'database', array() );
		$response = $this->state_get( $state, 'response', array() );

		$checks = array();

		$checks[] = $this->content_check( $database );
		$checks[] = $this->database_check( $database );
		$checks[] = $this->revisions_check( $database );
		$checks[] = $this->autoload_check( $database );
		$checks[] = $this->cleanup_check( $database );
		$checks[] = $this->media_weight_check( $all_state );
		$checks[] = $this->response_check( $response );
		$checks[] = $this->object_cache_check( $database );

		return array_filter( $checks );
	}

	/**
	 * Reports how much content the site holds.
	 *
	 * @param array $database Database state.
	 *
	 * @return array|null
	 */
	private function content_check( $database ) {
		if ( empty( $database ) ) {
			return null;
		}

		$items = array();
		$total = 0;

		foreach ( (array) $database['counts'] as $type => $count ) {
			$object = get_post_type_object( $type );
			$total += (int) $count;

			$items[] = WPSTK_Check::item(
				array(
					'label'      => $object ? $object->labels->name : $type,
					'detail'     => sprintf(
						/* translators: %d: number of published entries. */
						_n( '%d published entry', '%d published entries', (int) $count, 'site-toolkit' ),
						(int) $count
					),
					'link'       => admin_url( 'edit.php?post_type=' . rawurlencode( $type ) ),
					'link_label' => __( 'View', 'site-toolkit' ),
				)
			);
		}

		return $this->check(
			array(
				'id'      => 'content_volume',
				'status'  => 'passed',
				'label'   => __( 'Published content', 'site-toolkit' ),
				'weight'  => 0.5,
				'summary' => sprintf(
					/* translators: %d: number of published entries. */
					_n( '%d published entry across all public post types.', '%d published entries across all public post types.', $total, 'site-toolkit' ),
					$total
				),
				'why'     => __( 'A reference figure that helps put the other numbers on this page in context.', 'site-toolkit' ),
				'items'   => $items,
			)
		);
	}

	/**
	 * Reports database size.
	 *
	 * @param array $database Database state.
	 *
	 * @return array|null
	 */
	private function database_check( $database ) {
		if ( empty( $database ) ) {
			return null;
		}

		$items = array();

		foreach ( (array) $database['tables'] as $table ) {
			$items[] = WPSTK_Check::item(
				array(
					'label'  => $table['name'],
					'detail' => sprintf(
						/* translators: 1: table size, 2: number of rows. */
						__( '%1$s, about %2$s rows', 'site-toolkit' ),
						size_format( $table['bytes'] ),
						number_format_i18n( $table['rows'] )
					),
				)
			);
		}

		$overhead = (int) $database['overhead_bytes'];
		$status   = $overhead > ( 50 * MB_IN_BYTES ) ? 'recommendation' : 'passed';

		return $this->check(
			array(
				'id'      => 'database_size',
				'status'  => $status,
				'label'   => __( 'Database size', 'site-toolkit' ),
				'weight'  => 0.8,
				'summary' => sprintf(
					/* translators: 1: total database size, 2: reclaimable size. */
					__( 'The WordPress tables use %1$s, of which about %2$s is reclaimable space.', 'site-toolkit' ),
					size_format( (int) $database['total_bytes'] ),
					size_format( $overhead )
				),
				'why'     => __( 'A large database is not a problem on its own, but reclaimable space suggests tables that would benefit from being optimised.', 'site-toolkit' ),
				'action'  => 'passed' === $status ? '' : __( 'Ask your host to optimise the tables, or use your hosting control panel\'s database tools. Site Toolkit does not modify your tables.', 'site-toolkit' ),
				'note'    => __( 'The largest tables are listed below.', 'site-toolkit' ),
				'items'   => $items,
			)
		);
	}

	/**
	 * Reports post revisions.
	 *
	 * @param array $database Database state.
	 *
	 * @return array|null
	 */
	private function revisions_check( $database ) {
		if ( empty( $database ) ) {
			return null;
		}

		$revisions = (int) $database['revisions'];
		$limit     = $database['revision_limit'];

		if ( $revisions < 500 ) {
			return $this->passed(
				'revisions',
				__( 'Post revisions', 'site-toolkit' ),
				sprintf(
					/* translators: %s: number of revisions. */
					__( '%s revisions are stored, which is a normal amount.', 'site-toolkit' ),
					number_format_i18n( $revisions )
				),
				__( 'Revisions let you undo edits. They only become a concern when there are tens of thousands of them.', 'site-toolkit' )
			);
		}

		$note = '';

		if ( null === $limit ) {
			$note = __( 'WP_POST_REVISIONS is not set, so WordPress keeps every revision. Setting it in wp-config.php limits how many are kept from now on.', 'site-toolkit' );
		} elseif ( false === $limit ) {
			$note = __( 'Revisions are disabled by WP_POST_REVISIONS, so this total will not grow.', 'site-toolkit' );
		}

		return $this->check(
			array(
				'id'      => 'revisions',
				'status'  => $revisions > 5000 ? 'warning' : 'recommendation',
				'label'   => __( 'Post revisions', 'site-toolkit' ),
				'summary' => sprintf(
					/* translators: %s: number of revisions. */
					__( '%s post revisions are stored.', 'site-toolkit' ),
					number_format_i18n( $revisions )
				),
				'why'     => __( 'Every revision is a row in the posts table. Large numbers make backups bigger and some queries slower.', 'site-toolkit' ),
				'action'  => __( 'Limit future revisions with define( \'WP_POST_REVISIONS\', 5 ); in wp-config.php. Removing existing revisions is a job for a dedicated cleanup tool — Site Toolkit does not delete content.', 'site-toolkit' ),
				'note'    => $note,
			)
		);
	}

	/**
	 * Reports autoloaded option weight.
	 *
	 * @param array $database Database state.
	 *
	 * @return array|null
	 */
	private function autoload_check( $database ) {
		if ( empty( $database ) ) {
			return null;
		}

		$bytes = (int) $database['autoload_bytes'];
		$count = (int) $database['autoload_count'];

		$items = array();

		foreach ( (array) $database['autoload_top'] as $option ) {
			$items[] = WPSTK_Check::item(
				array(
					'label'  => $option['name'],
					'detail' => size_format( $option['bytes'] ),
				)
			);
		}

		if ( $bytes < self::AUTOLOAD_WARN ) {
			return $this->check(
				array(
					'id'      => 'autoloaded_options',
					'status'  => 'passed',
					'label'   => __( 'Autoloaded options', 'site-toolkit' ),
					'weight'  => 1.5,
					'summary' => sprintf(
						/* translators: 1: total size, 2: number of options. */
						__( '%1$s of options load on every request, across %2$s entries.', 'site-toolkit' ),
						size_format( $bytes ),
						number_format_i18n( $count )
					),
					'why'     => __( 'Autoloaded options are read from the database on every single page load, so keeping them small helps every request.', 'site-toolkit' ),
					'items'   => $items,
				)
			);
		}

		return $this->check(
			array(
				'id'      => 'autoloaded_options',
				'status'  => $bytes >= self::AUTOLOAD_CRITICAL ? 'warning' : 'recommendation',
				'label'   => __( 'Autoloaded options', 'site-toolkit' ),
				'weight'  => 1.5,
				'summary' => sprintf(
					/* translators: 1: total size, 2: number of options. */
					__( '%1$s of options load on every request, across %2$s entries.', 'site-toolkit' ),
					size_format( $bytes ),
					number_format_i18n( $count )
				),
				'why'     => __( 'Autoloaded options are read from the database on every single page load. Once this grows past roughly 800 KB it starts adding measurable time to every request.', 'site-toolkit' ),
				'action'  => __( 'Look at the largest entries below. They usually belong to a plugin — often one that was removed without cleaning up. Check with the plugin author before deleting anything.', 'site-toolkit' ),
				'note'    => __( 'The largest autoloaded options are listed below.', 'site-toolkit' ),
				'items'   => $items,
			)
		);
	}

	/**
	 * Reports leftover rows worth cleaning up.
	 *
	 * @param array $database Database state.
	 *
	 * @return array|null
	 */
	private function cleanup_check( $database ) {
		if ( empty( $database ) ) {
			return null;
		}

		$items = array();
		$total = 0;

		$rows = array(
			array( __( 'Trashed posts and pages', 'site-toolkit' ), (int) $database['trashed'], admin_url( 'edit.php?post_status=trash&post_type=post' ) ),
			array( __( 'Auto-draft entries', 'site-toolkit' ), (int) $database['autodrafts'], '' ),
			array( __( 'Spam comments', 'site-toolkit' ), (int) $database['spam_comments'], admin_url( 'edit-comments.php?comment_status=spam' ) ),
			array( __( 'Stored transients', 'site-toolkit' ), (int) $database['transients'], '' ),
		);

		foreach ( $rows as $row ) {
			if ( $row[1] <= 0 ) {
				continue;
			}

			$total += $row[1];

			$items[] = WPSTK_Check::item(
				array(
					'label'      => $row[0],
					'detail'     => number_format_i18n( $row[1] ),
					'link'       => $row[2],
					'link_label' => '' !== $row[2] ? __( 'View', 'site-toolkit' ) : '',
				)
			);
		}

		if ( $total < 200 ) {
			return $this->passed(
				'database_clutter',
				__( 'Database clutter', 'site-toolkit' ),
				__( 'Trash, auto-drafts, spam and transients are all at reasonable levels.', 'site-toolkit' ),
				__( 'These rows accumulate quietly and are safe to clear once you no longer need them.', 'site-toolkit' )
			);
		}

		return $this->check(
			array(
				'id'      => 'database_clutter',
				'status'  => 'recommendation',
				'label'   => __( 'Database clutter', 'site-toolkit' ),
				'weight'  => 0.8,
				'summary' => sprintf(
					/* translators: %s: number of rows. */
					__( '%s rows of trash, auto-drafts, spam comments and transients are stored.', 'site-toolkit' ),
					number_format_i18n( $total )
				),
				'why'     => __( 'None of this is dangerous, but it makes the database bigger than it needs to be and slows down backups.', 'site-toolkit' ),
				'action'  => __( 'Empty the trash and the spam queue when you are sure you no longer need them. Expired transients are cleared by WordPress automatically.', 'site-toolkit' ),
				'items'   => $items,
			)
		);
	}

	/**
	 * Reports the total weight of the media library.
	 *
	 * @param array $all_state State of every module.
	 *
	 * @return array|null
	 */
	private function media_weight_check( $all_state ) {
		$media = isset( $all_state['images']['media'] ) ? $all_state['images']['media'] : array();

		if ( empty( $media['count'] ) ) {
			return $this->skipped(
				'media_weight',
				__( 'Media weight', 'site-toolkit' ),
				__( 'No images were measured in this scan.', 'site-toolkit' )
			);
		}

		$bytes   = (int) $media['bytes'];
		$count   = (int) $media['count'];
		$average = $count > 0 ? (int) round( $bytes / max( 1, (int) $media['measured'] ) ) : 0;

		$status = $average > ( 400 * KB_IN_BYTES ) ? 'recommendation' : 'passed';

		return $this->check(
			array(
				'id'      => 'media_weight',
				'status'  => $status,
				'label'   => __( 'Media weight', 'site-toolkit' ),
				'summary' => sprintf(
					/* translators: 1: number of images, 2: total size, 3: average size. */
					__( '%1$s original image files use %2$s, an average of %3$s each.', 'site-toolkit' ),
					number_format_i18n( $count ),
					size_format( $bytes ),
					size_format( $average )
				),
				'why'     => __( 'The average size of your originals is a good indicator of how heavy your pages will feel to visitors.', 'site-toolkit' ),
				'action'  => 'passed' === $status ? '' : __( 'Compress images before uploading them. See the Images section for the specific files involved.', 'site-toolkit' ),
				'note'    => __( 'Original files only. The smaller sizes WordPress generates are not counted.', 'site-toolkit' ),
			)
		);
	}

	/**
	 * Reports how quickly the front page responded.
	 *
	 * @param array $response Response state.
	 *
	 * @return array|null
	 */
	private function response_check( $response ) {
		$label = __( 'Server response time', 'site-toolkit' );
		$note  = __( 'Basic local check only. This is a request from your server to itself, so it excludes network latency, DNS and browser rendering. It is not a substitute for a page speed or Core Web Vitals test.', 'site-toolkit' );

		if ( empty( $response ) || null === $response['average'] ) {
			return $this->skipped( 'response_time', $label, __( 'The front page could not be loaded from the server, so no timing was recorded.', 'site-toolkit' ) );
		}

		$average = (float) $response['average'];

		if ( $average < 0.8 ) {
			$status = 'passed';
		} elseif ( $average < 2.0 ) {
			$status = 'recommendation';
		} else {
			$status = 'warning';
		}

		return $this->check(
			array(
				'id'      => 'response_time',
				'status'  => $status,
				'label'   => $label,
				'summary' => sprintf(
					/* translators: %s: time in seconds. */
					__( 'The front page took %s seconds to respond on average.', 'site-toolkit' ),
					number_format_i18n( $average, 2 )
				),
				'why'     => __( 'A slow first byte delays everything that follows, however well optimised the rest of the page is.', 'site-toolkit' ),
				'action'  => 'passed' === $status ? '' : __( 'Consider a caching plugin, a persistent object cache, or a faster hosting plan. Deactivating plugins one at a time is the usual way to find a slow one.', 'site-toolkit' ),
				'note'    => $note,
			)
		);
	}

	/**
	 * Reports whether a persistent object cache is in use.
	 *
	 * @param array $database Database state.
	 *
	 * @return array|null
	 */
	private function object_cache_check( $database ) {
		if ( empty( $database ) ) {
			return null;
		}

		if ( ! empty( $database['object_cache'] ) ) {
			return $this->passed(
				'object_cache',
				__( 'Persistent object cache', 'site-toolkit' ),
				__( 'A persistent object cache is active.', 'site-toolkit' ),
				__( 'A persistent object cache saves repeating the same database queries on every request.', 'site-toolkit' )
			);
		}

		return $this->check(
			array(
				'id'      => 'object_cache',
				'status'  => 'recommendation',
				'label'   => __( 'Persistent object cache', 'site-toolkit' ),
				'weight'  => 0.5,
				'summary' => __( 'No persistent object cache was detected.', 'site-toolkit' ),
				'why'     => __( 'Without one, WordPress repeats the same database queries on every request. Most sites work fine without it; busy sites benefit noticeably.', 'site-toolkit' ),
				'action'  => __( 'Ask your host whether Redis or Memcached is available. This is optional, not a fault.', 'site-toolkit' ),
			)
		);
	}
}
