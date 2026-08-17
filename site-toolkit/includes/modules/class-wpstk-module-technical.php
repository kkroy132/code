<?php
/**
 * Technical audit module.
 *
 * @package WP_Site_Toolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Environment facts plus the checks that depend on them.
 *
 * @since 1.0.0
 */
class WPSTK_Module_Technical extends WPSTK_Module {

	/**
	 * {@inheritDoc}
	 */
	public function get_id() {
		return 'technical';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label() {
		return __( 'Technical', 'site-toolkit' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Versions, URLs, permalinks, WP-Cron, the REST API, PHP limits, sitemap and robots.txt availability.', 'site-toolkit' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_icon() {
		return 'dashicons-admin-tools';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_tasks( $settings ) {
		return array(
			array(
				'id'    => 'environment',
				'label' => __( 'Reading the server environment', 'site-toolkit' ),
			),
			array(
				'id'    => 'endpoints',
				'label' => __( 'Checking the REST API and site files', 'site-toolkit' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function run_task( $task_id, $offset, $state, $settings ) {
		if ( 'environment' === $task_id ) {
			$state['environment'] = $this->collect_environment();

			return $this->task_result( $state, null, 1, 1 );
		}

		if ( 'endpoints' === $task_id ) {
			$state['endpoints'] = $this->collect_endpoints( $settings );

			return $this->task_result( $state, null, 1, 1 );
		}

		return $this->task_result( $state );
	}

	/**
	 * Reads the environment facts WordPress already knows.
	 *
	 * @return array
	 */
	private function collect_environment() {
		global $wpdb;

		$database_version = '';

		if ( method_exists( $wpdb, 'db_server_info' ) ) {
			$database_version = (string) $wpdb->db_server_info();
		}

		if ( '' === $database_version ) {
			$database_version = (string) $wpdb->db_version();
		}

		$cron_overdue = 0;
		$crons        = _get_cron_array();

		if ( is_array( $crons ) ) {
			$threshold = time() - HOUR_IN_SECONDS;

			foreach ( $crons as $timestamp => $hooks ) {
				if ( (int) $timestamp < $threshold ) {
					$cron_overdue += is_array( $hooks ) ? count( $hooks ) : 1;
				}
			}
		}

		return array(
			'wp_version'      => get_bloginfo( 'version' ),
			'php_version'     => PHP_VERSION,
			'db_version'      => $database_version,
			'db_type'         => false !== stripos( $database_version, 'mariadb' ) ? 'MariaDB' : 'MySQL',
			'site_url'        => site_url(),
			'home_url'        => home_url(),
			'https'           => 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ),
			'permalink'       => (string) get_option( 'permalink_structure' ),
			'cron_disabled'   => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'cron_overdue'    => $cron_overdue,
			'alternate_cron'  => defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON,
			'debug'           => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'memory_limit'    => $this->bytes_from_ini( defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : ini_get( 'memory_limit' ) ),
			'memory_raw'      => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : (string) ini_get( 'memory_limit' ),
			'max_memory'      => $this->bytes_from_ini( defined( 'WP_MAX_MEMORY_LIMIT' ) ? WP_MAX_MEMORY_LIMIT : ini_get( 'memory_limit' ) ),
			'php_memory'      => $this->bytes_from_ini( ini_get( 'memory_limit' ) ),
			'upload_limit'    => (int) wp_max_upload_size(),
			'max_execution'   => (int) ini_get( 'max_execution_time' ),
			'post_max_size'   => $this->bytes_from_ini( ini_get( 'post_max_size' ) ),
			'multisite'       => is_multisite(),
			'timezone'        => (string) wp_timezone_string(),
			'language'        => (string) get_locale(),
			'theme'           => $this->active_theme(),
			'active_plugins'  => count( (array) get_option( 'active_plugins', array() ) ),
			'curl_available'  => function_exists( 'curl_version' ),
			'server_software' => $this->server_software(),
		);
	}

	/**
	 * Returns the active theme name and version.
	 *
	 * @return string
	 */
	private function active_theme() {
		$theme = wp_get_theme();

		if ( ! $theme->exists() ) {
			return __( 'Unknown', 'site-toolkit' );
		}

		return trim( $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) );
	}

	/**
	 * Returns the web server software string, when the host exposes it.
	 *
	 * @return string
	 */
	private function server_software() {
		if ( empty( $_SERVER['SERVER_SOFTWARE'] ) ) {
			return __( 'Unknown', 'site-toolkit' );
		}

		return sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) );
	}

	/**
	 * Converts a PHP shorthand size such as `256M` into bytes.
	 *
	 * @param string $value Shorthand value.
	 *
	 * @return int
	 */
	private function bytes_from_ini( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value || '-1' === $value ) {
			return 0;
		}

		if ( function_exists( 'wp_convert_hr_to_bytes' ) ) {
			return (int) wp_convert_hr_to_bytes( $value );
		}

		return (int) $value;
	}

	/**
	 * Probes the REST API, sitemap and robots.txt.
	 *
	 * @param array $settings Settings.
	 *
	 * @return array
	 */
	private function collect_endpoints( $settings ) {
		$timeout = (int) $settings['scan_timeout'];

		$rest = WPSTK_HTTP::fetch( rest_url(), $timeout );

		return array(
			'rest_status'  => (int) $rest['status'],
			'rest_ok'      => $rest['success'] && $rest['status'] >= 200 && $rest['status'] < 300,
			'rest_message' => (string) $rest['message'],
			'rest_url'     => rest_url(),
			'sitemap'      => WPSTK_HTTP::check_url( home_url( '/wp-sitemap.xml' ), $timeout ),
			'robots'       => WPSTK_HTTP::check_url( home_url( '/robots.txt' ), $timeout ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function finalize( $state, $settings, $all_state ) {
		$environment = $this->state_get( $state, 'environment', array() );
		$endpoints   = $this->state_get( $state, 'endpoints', array() );

		if ( empty( $environment ) ) {
			return array();
		}

		$checks = array();

		$checks[] = $this->overview_check( $environment, $endpoints );
		$checks[] = $this->permalink_check( $environment );
		$checks[] = $this->cron_check( $environment );
		$checks[] = $this->rest_check( $endpoints );
		$checks[] = $this->memory_check( $environment );
		$checks[] = $this->uploads_check( $environment );

		return array_filter( $checks );
	}

	/**
	 * Builds the environment overview.
	 *
	 * @param array $environment Environment state.
	 * @param array $endpoints   Endpoint state.
	 *
	 * @return array
	 */
	private function overview_check( $environment, $endpoints ) {
		$rows = array(
			array( __( 'WordPress version', 'site-toolkit' ), $environment['wp_version'] ),
			array( __( 'PHP version', 'site-toolkit' ), $environment['php_version'] ),
			array(
				/* translators: %s: database server name, for example MySQL. */
				sprintf( __( '%s version', 'site-toolkit' ), $environment['db_type'] ),
				$environment['db_version'],
			),
			array( __( 'Web server', 'site-toolkit' ), $environment['server_software'] ),
			array( __( 'WordPress address', 'site-toolkit' ), $environment['site_url'] ),
			array( __( 'Site address', 'site-toolkit' ), $environment['home_url'] ),
			array(
				__( 'HTTPS', 'site-toolkit' ),
				$environment['https'] ? __( 'Enabled', 'site-toolkit' ) : __( 'Not enabled', 'site-toolkit' ),
			),
			array(
				__( 'Permalink structure', 'site-toolkit' ),
				'' !== $environment['permalink'] ? $environment['permalink'] : __( 'Plain', 'site-toolkit' ),
			),
			array(
				__( 'WP-Cron', 'site-toolkit' ),
				$environment['cron_disabled'] ? __( 'Disabled by DISABLE_WP_CRON', 'site-toolkit' ) : __( 'Enabled', 'site-toolkit' ),
			),
			array(
				__( 'REST API', 'site-toolkit' ),
				! empty( $endpoints['rest_ok'] ) ? __( 'Reachable', 'site-toolkit' ) : __( 'Not reachable', 'site-toolkit' ),
			),
			array(
				__( 'Debug mode', 'site-toolkit' ),
				$environment['debug'] ? __( 'On', 'site-toolkit' ) : __( 'Off', 'site-toolkit' ),
			),
			array( __( 'WordPress memory limit', 'site-toolkit' ), $environment['memory_raw'] ),
			array(
				__( 'PHP memory limit', 'site-toolkit' ),
				$environment['php_memory'] > 0 ? size_format( $environment['php_memory'] ) : __( 'Unlimited', 'site-toolkit' ),
			),
			array( __( 'Maximum upload size', 'site-toolkit' ), size_format( $environment['upload_limit'] ) ),
			array(
				__( 'Maximum execution time', 'site-toolkit' ),
				$environment['max_execution'] > 0
					? sprintf(
						/* translators: %d: number of seconds. */
						_n( '%d second', '%d seconds', $environment['max_execution'], 'site-toolkit' ),
						$environment['max_execution']
					)
					: __( 'No limit', 'site-toolkit' ),
			),
			array(
				__( 'XML sitemap', 'site-toolkit' ),
				$this->endpoint_label( isset( $endpoints['sitemap'] ) ? $endpoints['sitemap'] : null ),
			),
			array(
				__( 'robots.txt', 'site-toolkit' ),
				$this->endpoint_label( isset( $endpoints['robots'] ) ? $endpoints['robots'] : null ),
			),
			array( __( 'Active theme', 'site-toolkit' ), $environment['theme'] ),
			array( __( 'Active plugins', 'site-toolkit' ), number_format_i18n( $environment['active_plugins'] ) ),
			array( __( 'Multisite', 'site-toolkit' ), $environment['multisite'] ? __( 'Yes', 'site-toolkit' ) : __( 'No', 'site-toolkit' ) ),
			array( __( 'Site language', 'site-toolkit' ), $environment['language'] ),
			array( __( 'Timezone', 'site-toolkit' ), $environment['timezone'] ),
		);

		$items = array();

		foreach ( $rows as $row ) {
			$items[] = WPSTK_Check::item(
				array(
					'label'  => (string) $row[0],
					'detail' => (string) $row[1],
				)
			);
		}

		return $this->check(
			array(
				'id'          => 'environment',
				'status'      => 'passed',
				'label'       => __( 'Environment overview', 'site-toolkit' ),
				'weight'      => 0.3,
				'summary'     => __( 'A reference list of what this site is running on.', 'site-toolkit' ),
				'why'         => __( 'Useful when reporting a problem to a host, a plugin author or a support forum.', 'site-toolkit' ),
				'items'       => $items,
				'items_total' => count( $items ),
			)
		);
	}

	/**
	 * Describes the outcome of an endpoint probe.
	 *
	 * @param array|null $result Probe result.
	 *
	 * @return string
	 */
	private function endpoint_label( $result ) {
		if ( ! is_array( $result ) ) {
			return __( 'Not checked', 'site-toolkit' );
		}

		if ( 'ok' === $result['type'] ) {
			return __( 'Available', 'site-toolkit' );
		}

		return $result['message'];
	}

	/**
	 * Permalink structure check.
	 *
	 * @param array $environment Environment state.
	 *
	 * @return array
	 */
	private function permalink_check( $environment ) {
		$label = __( 'Permalink structure', 'site-toolkit' );

		if ( '' !== $environment['permalink'] ) {
			return $this->passed(
				'permalinks',
				$label,
				sprintf(
					/* translators: %s: permalink structure. */
					__( 'Pretty permalinks are in use (%s).', 'site-toolkit' ),
					$environment['permalink']
				),
				__( 'Readable addresses are easier to share and to understand at a glance.', 'site-toolkit' )
			);
		}

		return $this->check(
			array(
				'id'      => 'permalinks',
				'status'  => 'warning',
				'label'   => $label,
				'weight'  => 1.2,
				'summary' => __( 'This site uses plain permalinks such as /?p=123.', 'site-toolkit' ),
				'why'     => __( 'Plain permalinks say nothing about the page. Readable addresses are easier to share, easier to understand and slightly better understood by search engines.', 'site-toolkit' ),
				'action'  => __( 'Choose a structure such as "Post name" in Settings → Permalinks. WordPress keeps the old addresses working.', 'site-toolkit' ),
				'items'   => array(
					WPSTK_Check::item(
						array(
							'label'      => __( 'Permalink settings', 'site-toolkit' ),
							'link'       => admin_url( 'options-permalink.php' ),
							'link_label' => __( 'Open settings', 'site-toolkit' ),
						)
					),
				),
			)
		);
	}

	/**
	 * WP-Cron check.
	 *
	 * @param array $environment Environment state.
	 *
	 * @return array
	 */
	private function cron_check( $environment ) {
		$label   = __( 'WP-Cron', 'site-toolkit' );
		$overdue = (int) $environment['cron_overdue'];

		if ( ! empty( $environment['cron_disabled'] ) ) {
			return $this->check(
				array(
					'id'      => 'wp_cron',
					'status'  => $overdue > 0 ? 'warning' : 'recommendation',
					'label'   => $label,
					'summary' => $overdue > 0
						? sprintf(
							/* translators: %d: number of overdue events. */
							_n(
								'WP-Cron is disabled and %d scheduled event is more than an hour overdue.',
								'WP-Cron is disabled and %d scheduled events are more than an hour overdue.',
								$overdue,
								'site-toolkit'
							),
							$overdue
						)
						: __( 'WP-Cron is disabled by DISABLE_WP_CRON.', 'site-toolkit' ),
					'why'     => __( 'Scheduled tasks handle publishing scheduled posts, checking for updates and cleaning up. Disabling WP-Cron is fine only when a real cron job calls wp-cron.php instead.', 'site-toolkit' ),
					'action'  => __( 'Confirm that a server cron job runs wp-cron.php regularly. If not, remove DISABLE_WP_CRON from wp-config.php.', 'site-toolkit' ),
				)
			);
		}

		if ( $overdue > 3 ) {
			return $this->check(
				array(
					'id'      => 'wp_cron',
					'status'  => 'warning',
					'label'   => $label,
					'summary' => sprintf(
						/* translators: %d: number of overdue events. */
						_n( '%d scheduled event is more than an hour overdue.', '%d scheduled events are more than an hour overdue.', $overdue, 'site-toolkit' ),
						$overdue
					),
					'why'     => __( 'WP-Cron runs when someone visits the site. On a quiet site, or when something blocks loopback requests, scheduled tasks fall behind.', 'site-toolkit' ),
					'action'  => __( 'Ask your host to set up a real cron job that calls wp-cron.php every few minutes, then define DISABLE_WP_CRON as true.', 'site-toolkit' ),
				)
			);
		}

		return $this->passed(
			'wp_cron',
			$label,
			__( 'Scheduled events are running on time.', 'site-toolkit' ),
			__( 'Scheduled tasks handle publishing scheduled posts, checking for updates and cleaning up.', 'site-toolkit' )
		);
	}

	/**
	 * REST API check.
	 *
	 * @param array $endpoints Endpoint state.
	 *
	 * @return array
	 */
	private function rest_check( $endpoints ) {
		$label = __( 'REST API', 'site-toolkit' );

		if ( empty( $endpoints ) ) {
			return $this->skipped( 'rest_api', $label, __( 'The REST API was not checked.', 'site-toolkit' ) );
		}

		if ( ! empty( $endpoints['rest_ok'] ) ) {
			return $this->passed(
				'rest_api',
				$label,
				__( 'The REST API responded normally.', 'site-toolkit' ),
				__( 'The block editor and many plugins rely on the REST API.', 'site-toolkit' )
			);
		}

		$detail = '' !== $endpoints['rest_message']
			? $endpoints['rest_message']
			: sprintf(
				/* translators: %d: HTTP status code. */
				__( 'HTTP %d', 'site-toolkit' ),
				(int) $endpoints['rest_status']
			);

		return $this->check(
			array(
				'id'      => 'rest_api',
				'status'  => 'critical',
				'label'   => $label,
				'weight'  => 1.5,
				'summary' => sprintf(
					/* translators: %s: error detail. */
					__( 'The REST API did not respond normally (%s).', 'site-toolkit' ),
					$detail
				),
				'why'     => __( 'The block editor, Site Health and many plugins stop working when the REST API is unreachable.', 'site-toolkit' ),
				'action'  => __( 'Check for a security plugin or server rule blocking /wp-json/, and confirm that your permalink settings have been saved at least once.', 'site-toolkit' ),
				'items'   => array(
					WPSTK_Check::item(
						array(
							'label'      => $endpoints['rest_url'],
							'url'        => $endpoints['rest_url'],
							'link'       => admin_url( 'site-health.php' ),
							'link_label' => __( 'Open Site Health', 'site-toolkit' ),
						)
					),
				),
			)
		);
	}

	/**
	 * Memory limit check.
	 *
	 * @param array $environment Environment state.
	 *
	 * @return array
	 */
	private function memory_check( $environment ) {
		$label = __( 'Memory limit', 'site-toolkit' );
		$bytes = (int) $environment['memory_limit'];

		if ( $bytes >= ( 128 * MB_IN_BYTES ) ) {
			return $this->passed(
				'memory_limit',
				$label,
				sprintf(
					/* translators: %s: memory limit. */
					__( 'WordPress may use up to %s.', 'site-toolkit' ),
					size_format( $bytes )
				),
				__( '128 MB is enough for most sites. Image-heavy sites and page builders benefit from more.', 'site-toolkit' )
			);
		}

		return $this->check(
			array(
				'id'      => 'memory_limit',
				'status'  => $bytes < ( 64 * MB_IN_BYTES ) ? 'warning' : 'recommendation',
				'label'   => $label,
				'summary' => sprintf(
					/* translators: %s: memory limit. */
					__( 'The WordPress memory limit is %s.', 'site-toolkit' ),
					size_format( $bytes )
				),
				'why'     => __( 'Running out of memory shows up as a blank page or a partially rendered admin screen, usually while editing or uploading.', 'site-toolkit' ),
				'action'  => __( 'Add define( \'WP_MEMORY_LIMIT\', \'256M\' ); to wp-config.php. Your host also has to allow that much.', 'site-toolkit' ),
			)
		);
	}

	/**
	 * Upload limit check.
	 *
	 * @param array $environment Environment state.
	 *
	 * @return array
	 */
	private function uploads_check( $environment ) {
		$label = __( 'Upload limit', 'site-toolkit' );
		$bytes = (int) $environment['upload_limit'];

		if ( $bytes >= ( 8 * MB_IN_BYTES ) ) {
			return $this->passed(
				'upload_limit',
				$label,
				sprintf(
					/* translators: %s: upload limit. */
					__( 'Files up to %s can be uploaded.', 'site-toolkit' ),
					size_format( $bytes )
				),
				__( 'The upload limit decides how large an image or video you can add through the media library.', 'site-toolkit' )
			);
		}

		return $this->check(
			array(
				'id'      => 'upload_limit',
				'status'  => 'recommendation',
				'label'   => $label,
				'weight'  => 0.5,
				'summary' => sprintf(
					/* translators: %s: upload limit. */
					__( 'The maximum upload size is %s.', 'site-toolkit' ),
					size_format( $bytes )
				),
				'why'     => __( 'A low limit makes uploading ordinary photos awkward, and the failure message is not always clear.', 'site-toolkit' ),
				'action'  => __( 'Ask your host to raise upload_max_filesize and post_max_size in the PHP configuration.', 'site-toolkit' ),
			)
		);
	}
}
