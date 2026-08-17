<?php
/**
 * Basic Security Health Check module.
 *
 * @package WP_Site_Toolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Non-invasive configuration checks.
 *
 * This is a Basic Security Health Check, not a complete security audit. It only
 * reads configuration that WordPress already knows about and inspects the
 * response headers of this site's own front page. It never probes for
 * vulnerabilities, never touches third-party systems and never tries to exploit
 * anything.
 *
 * @since 1.0.0
 */
class WPSTK_Module_Security extends WPSTK_Module {

	/**
	 * {@inheritDoc}
	 */
	public function get_id() {
		return 'security';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label() {
		return __( 'Security', 'wp-site-toolkit' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Basic Security Health Check: HTTPS, versions, debug output, response headers, XML-RPC, file editing and account configuration.', 'wp-site-toolkit' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_icon() {
		return 'dashicons-shield';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_tasks( $settings ) {
		return array(
			array(
				'id'    => 'config',
				'label' => __( 'Reading security configuration', 'wp-site-toolkit' ),
			),
			array(
				'id'    => 'headers',
				'label' => __( 'Inspecting response headers', 'wp-site-toolkit' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function run_task( $task_id, $offset, $state, $settings ) {
		if ( 'config' === $task_id ) {
			$state['config'] = $this->collect_config();

			return $this->task_result( $state, null, 1, 1 );
		}

		if ( 'headers' === $task_id ) {
			$state['headers'] = $this->collect_headers( $settings );

			return $this->task_result( $state, null, 1, 1 );
		}

		return $this->task_result( $state );
	}

	/**
	 * Reads the local configuration relevant to the check.
	 *
	 * @return array
	 */
	private function collect_config() {
		$updates       = get_site_transient( 'update_core' );
		$core_update   = false;
		$core_latest   = '';
		$plugin_update = get_site_transient( 'update_plugins' );
		$theme_update  = get_site_transient( 'update_themes' );

		if ( is_object( $updates ) && ! empty( $updates->updates ) && is_array( $updates->updates ) ) {
			foreach ( $updates->updates as $update ) {
				if ( isset( $update->response ) && 'upgrade' === $update->response ) {
					$core_update = true;
					$core_latest = isset( $update->current ) ? (string) $update->current : '';

					break;
				}
			}
		}

		$admin_user = get_user_by( 'login', 'admin' );

		$administrators = get_users(
			array(
				'role'   => 'administrator',
				'fields' => 'ID',
				'number' => 100,
			)
		);

		return array(
			'https'            => 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ),
			'site_url_https'   => 'https' === wp_parse_url( site_url(), PHP_URL_SCHEME ),
			'force_ssl_admin'  => force_ssl_admin(),
			'wp_version'       => get_bloginfo( 'version' ),
			'core_update'      => $core_update,
			'core_latest'      => $core_latest,
			'php_version'      => PHP_VERSION,
			'debug'            => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'debug_display'    => defined( 'WP_DEBUG_DISPLAY' ) ? (bool) WP_DEBUG_DISPLAY : null,
			'debug_log'        => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
			'script_debug'     => defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG,
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Reads the existing WordPress core filter's current value; this does not declare a new hook.
			'xmlrpc_enabled'   => (bool) apply_filters( 'xmlrpc_enabled', true ),
			'disallow_edit'    => defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT,
			'disallow_mods'    => defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS,
			'users_can_reg'    => (int) get_option( 'users_can_register' ),
			'default_role'     => (string) get_option( 'default_role' ),
			'admin_user'       => $admin_user instanceof WP_User,
			'admin_count'      => count( (array) $administrators ),
			'plugin_updates'   => ( is_object( $plugin_update ) && ! empty( $plugin_update->response ) ) ? count( (array) $plugin_update->response ) : 0,
			'theme_updates'    => ( is_object( $theme_update ) && ! empty( $theme_update->response ) ) ? count( (array) $theme_update->response ) : 0,
			'auto_update_core' => $this->core_auto_update_state(),
		);
	}

	/**
	 * Describes how core automatic updates are configured.
	 *
	 * @return string One of `all`, `minor`, `disabled` or `unknown`.
	 */
	private function core_auto_update_state() {
		if ( defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED ) {
			return 'disabled';
		}

		if ( defined( 'WP_AUTO_UPDATE_CORE' ) ) {
			if ( false === WP_AUTO_UPDATE_CORE ) {
				return 'disabled';
			}

			if ( true === WP_AUTO_UPDATE_CORE ) {
				return 'all';
			}

			if ( 'minor' === WP_AUTO_UPDATE_CORE ) {
				return 'minor';
			}

			if ( 'beta' === WP_AUTO_UPDATE_CORE || 'rc' === WP_AUTO_UPDATE_CORE ) {
				return 'all';
			}
		}

		$option = get_site_option( 'auto_update_core_major' );

		if ( 'enabled' === $option ) {
			return 'all';
		}

		return 'minor';
	}

	/**
	 * Reads the response headers of this site's front page.
	 *
	 * @param array $settings Settings.
	 *
	 * @return array
	 */
	private function collect_headers( $settings ) {
		$response = WPSTK_HTTP::fetch( home_url( '/' ), (int) $settings['scan_timeout'] );

		$out = array(
			'available' => (bool) $response['success'],
			'status'    => (int) $response['status'],
			'message'   => (string) $response['message'],
			'present'   => array(),
			'missing'   => array(),
			'exposes'   => array(),
		);

		if ( ! $response['success'] ) {
			return $out;
		}

		$headers = $response['headers'];

		$wanted = array(
			'x-content-type-options'   => __( 'X-Content-Type-Options', 'wp-site-toolkit' ),
			'referrer-policy'          => __( 'Referrer-Policy', 'wp-site-toolkit' ),
			'x-frame-options'          => __( 'X-Frame-Options', 'wp-site-toolkit' ),
			'content-security-policy'  => __( 'Content-Security-Policy', 'wp-site-toolkit' ),
			'permissions-policy'       => __( 'Permissions-Policy', 'wp-site-toolkit' ),
		);

		if ( 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ) ) {
			$wanted['strict-transport-security'] = __( 'Strict-Transport-Security', 'wp-site-toolkit' );
		}

		foreach ( $wanted as $key => $name ) {
			if ( isset( $headers[ $key ] ) ) {
				$out['present'][ $key ] = $name;
			} else {
				$out['missing'][ $key ] = $name;
			}
		}

		// Clickjacking protection can come from either header.
		if ( isset( $out['missing']['x-frame-options'] ) && isset( $headers['content-security-policy'] ) ) {
			$csp = is_array( $headers['content-security-policy'] ) ? implode( ' ', $headers['content-security-policy'] ) : (string) $headers['content-security-policy'];

			if ( false !== stripos( $csp, 'frame-ancestors' ) ) {
				unset( $out['missing']['x-frame-options'] );
				$out['present']['x-frame-options'] = $wanted['x-frame-options'];
			}
		}

		foreach ( array( 'x-powered-by', 'server' ) as $key ) {
			if ( ! isset( $headers[ $key ] ) ) {
				continue;
			}

			$value = is_array( $headers[ $key ] ) ? implode( ' ', $headers[ $key ] ) : (string) $headers[ $key ];

			if ( preg_match( '~\d+\.\d+~', $value ) ) {
				$out['exposes'][ $key ] = $value;
			}
		}

		return $out;
	}

	/**
	 * {@inheritDoc}
	 */
	public function finalize( $state, $settings, $all_state ) {
		$config  = $this->state_get( $state, 'config', array() );
		$headers = $this->state_get( $state, 'headers', array() );

		if ( empty( $config ) ) {
			return array();
		}

		$checks = array();

		$checks[] = $this->https_check( $config );
		$checks[] = $this->core_check( $config );
		$checks[] = $this->php_check( $config );
		$checks[] = $this->updates_check( $config );
		$checks[] = $this->debug_check( $config );
		$checks[] = $this->headers_check( $headers );
		$checks[] = $this->xmlrpc_check( $config );
		$checks[] = $this->file_edit_check( $config );
		$checks[] = $this->accounts_check( $config );

		return array_filter( $checks );
	}

	/**
	 * HTTPS check.
	 *
	 * @param array $config Config state.
	 *
	 * @return array
	 */
	private function https_check( $config ) {
		$label = __( 'HTTPS', 'wp-site-toolkit' );

		if ( empty( $config['https'] ) || empty( $config['site_url_https'] ) ) {
			return $this->check(
				array(
					'id'      => 'https',
					'status'  => 'critical',
					'label'   => $label,
					'weight'  => 2.0,
					'summary' => __( 'This site is not served over HTTPS.', 'wp-site-toolkit' ),
					'why'     => __( 'Without HTTPS, anything sent between your visitors and the server — including login details — travels in plain text, and browsers mark the site as not secure.', 'wp-site-toolkit' ),
					'action'  => __( 'Most hosts offer a free certificate. Once it is installed, update the WordPress Address and Site Address in Settings → General.', 'wp-site-toolkit' ),
					'items'   => array(
						WPSTK_Check::item(
							array(
								'label'      => __( 'General settings', 'wp-site-toolkit' ),
								'detail'     => sprintf(
									/* translators: 1: home URL, 2: site URL. */
									__( 'Home: %1$s, WordPress: %2$s', 'wp-site-toolkit' ),
									home_url(),
									site_url()
								),
								'link'       => admin_url( 'options-general.php' ),
								'link_label' => __( 'Open settings', 'wp-site-toolkit' ),
							)
						),
					),
				)
			);
		}

		if ( empty( $config['force_ssl_admin'] ) ) {
			return $this->check(
				array(
					'id'      => 'https',
					'status'  => 'recommendation',
					'label'   => $label,
					'weight'  => 2.0,
					'summary' => __( 'The site uses HTTPS, but the admin area is not forced onto it.', 'wp-site-toolkit' ),
					'why'     => __( 'Forcing HTTPS in the admin area makes sure login details are never sent unencrypted, even if someone types the http address.', 'wp-site-toolkit' ),
					'action'  => __( 'Add define( \'FORCE_SSL_ADMIN\', true ); to wp-config.php.', 'wp-site-toolkit' ),
				)
			);
		}

		return $this->passed(
			'https',
			$label,
			__( 'The site and the admin area are both served over HTTPS.', 'wp-site-toolkit' ),
			__( 'HTTPS keeps traffic between your visitors and the server private.', 'wp-site-toolkit' )
		);
	}

	/**
	 * WordPress version check.
	 *
	 * @param array $config Config state.
	 *
	 * @return array
	 */
	private function core_check( $config ) {
		$label = __( 'WordPress version', 'wp-site-toolkit' );

		if ( empty( $config['core_update'] ) ) {
			return $this->passed(
				'wp_version',
				$label,
				sprintf(
					/* translators: %s: WordPress version. */
					__( 'Running WordPress %s, which is up to date as far as this site knows.', 'wp-site-toolkit' ),
					$config['wp_version']
				),
				__( 'Security fixes reach your site through WordPress updates.', 'wp-site-toolkit' )
			);
		}

		return $this->check(
			array(
				'id'      => 'wp_version',
				'status'  => 'warning',
				'label'   => $label,
				'weight'  => 2.0,
				'summary' => sprintf(
					/* translators: 1: installed version, 2: available version. */
					__( 'WordPress %1$s is installed and %2$s is available.', 'wp-site-toolkit' ),
					$config['wp_version'],
					'' !== $config['core_latest'] ? $config['core_latest'] : __( 'a newer version', 'wp-site-toolkit' )
				),
				'why'     => __( 'Updates carry security fixes. Details of the fixed issues are public once a release is out, so out-of-date sites are the easiest targets.', 'wp-site-toolkit' ),
				'action'  => __( 'Back up the site and run the update from Dashboard → Updates.', 'wp-site-toolkit' ),
				'items'   => array(
					WPSTK_Check::item(
						array(
							'label'      => __( 'WordPress updates', 'wp-site-toolkit' ),
							'link'       => admin_url( 'update-core.php' ),
							'link_label' => __( 'Open updates', 'wp-site-toolkit' ),
						)
					),
				),
			)
		);
	}

	/**
	 * PHP version check.
	 *
	 * @param array $config Config state.
	 *
	 * @return array
	 */
	private function php_check( $config ) {
		$label   = __( 'PHP version', 'wp-site-toolkit' );
		$version = (string) $config['php_version'];
		$now     = time();

		// Published end-of-life dates for each PHP branch.
		$eol = array(
			'7.4' => '2022-11-28',
			'8.0' => '2023-11-26',
			'8.1' => '2025-12-31',
			'8.2' => '2026-12-31',
			'8.3' => '2027-12-31',
			'8.4' => '2028-12-31',
		);

		$branch     = implode( '.', array_slice( explode( '.', $version ), 0, 2 ) );
		$branch_eol = isset( $eol[ $branch ] ) ? strtotime( $eol[ $branch ] ) : null;

		if ( null === $branch_eol ) {
			// A branch newer than this plugin knows about.
			if ( version_compare( $version, '8.4', '>=' ) ) {
				return $this->passed(
					'php_version',
					$label,
					sprintf(
						/* translators: %s: PHP version. */
						__( 'Running PHP %s, which is a currently supported release.', 'wp-site-toolkit' ),
						$version
					),
					__( 'Supported PHP branches still receive security fixes.', 'wp-site-toolkit' )
				);
			}

			$branch_eol = 0;
		}

		if ( $branch_eol > 0 && $branch_eol > $now ) {
			return $this->passed(
				'php_version',
				$label,
				sprintf(
					/* translators: 1: PHP version, 2: end of support date. */
					__( 'Running PHP %1$s, which receives security fixes until %2$s.', 'wp-site-toolkit' ),
					$version,
					date_i18n( get_option( 'date_format' ), $branch_eol )
				),
				__( 'Supported PHP branches still receive security fixes.', 'wp-site-toolkit' )
			);
		}

		return $this->check(
			array(
				'id'      => 'php_version',
				'status'  => version_compare( $version, '7.4', '<' ) ? 'critical' : 'warning',
				'label'   => $label,
				'weight'  => 1.5,
				'summary' => sprintf(
					/* translators: %s: PHP version. */
					__( 'Running PHP %s, which no longer receives security fixes from the PHP project.', 'wp-site-toolkit' ),
					$version
				),
				'why'     => __( 'Once a PHP branch reaches end of life, security issues found in it are not fixed. Newer versions are also noticeably faster.', 'wp-site-toolkit' ),
				'action'  => __( 'Ask your host to move the site to a supported PHP version. Test on a staging copy first if you can.', 'wp-site-toolkit' ),
				'items'   => array(
					WPSTK_Check::item(
						array(
							'label'      => __( 'Site Health', 'wp-site-toolkit' ),
							'detail'     => __( 'WordPress lists plugin compatibility information here', 'wp-site-toolkit' ),
							'link'       => admin_url( 'site-health.php' ),
							'link_label' => __( 'Open Site Health', 'wp-site-toolkit' ),
						)
					),
				),
			)
		);
	}

	/**
	 * Plugin and theme update check.
	 *
	 * @param array $config Config state.
	 *
	 * @return array
	 */
	private function updates_check( $config ) {
		$label   = __( 'Plugin and theme updates', 'wp-site-toolkit' );
		$plugins = (int) $config['plugin_updates'];
		$themes  = (int) $config['theme_updates'];
		$total   = $plugins + $themes;

		if ( 0 === $total ) {
			return $this->passed(
				'component_updates',
				$label,
				__( 'No plugin or theme updates are waiting.', 'wp-site-toolkit' ),
				__( 'Out-of-date plugins are the most common way WordPress sites are compromised.', 'wp-site-toolkit' )
			);
		}

		return $this->check(
			array(
				'id'      => 'component_updates',
				'status'  => $total > 5 ? 'warning' : 'recommendation',
				'label'   => $label,
				'weight'  => 1.5,
				'summary' => sprintf(
					/* translators: 1: number of plugin updates, 2: number of theme updates. */
					__( '%1$d plugin updates and %2$d theme updates are waiting.', 'wp-site-toolkit' ),
					$plugins,
					$themes
				),
				'why'     => __( 'Out-of-date plugins are the most common way WordPress sites are compromised. Update details are public, which makes old versions easy to target.', 'wp-site-toolkit' ),
				'action'  => __( 'Back up the site and install the updates. Enabling automatic updates for trusted plugins keeps this from piling up.', 'wp-site-toolkit' ),
				'items'   => array(
					WPSTK_Check::item(
						array(
							'label'      => __( 'Updates screen', 'wp-site-toolkit' ),
							'link'       => admin_url( 'update-core.php' ),
							'link_label' => __( 'Open updates', 'wp-site-toolkit' ),
						)
					),
				),
			)
		);
	}

	/**
	 * Debug mode check.
	 *
	 * @param array $config Config state.
	 *
	 * @return array
	 */
	private function debug_check( $config ) {
		$label = __( 'Debug mode', 'wp-site-toolkit' );

		if ( empty( $config['debug'] ) ) {
			return $this->passed(
				'debug_mode',
				$label,
				__( 'WP_DEBUG is off.', 'wp-site-toolkit' ),
				__( 'Debug output can reveal file paths and database details to visitors.', 'wp-site-toolkit' )
			);
		}

		$displaying = ( null === $config['debug_display'] ) || ! empty( $config['debug_display'] );

		return $this->check(
			array(
				'id'      => 'debug_mode',
				'status'  => $displaying ? 'critical' : 'warning',
				'label'   => $label,
				'weight'  => 1.5,
				'summary' => $displaying
					? __( 'WP_DEBUG is on and errors may be printed into your pages.', 'wp-site-toolkit' )
					: __( 'WP_DEBUG is on, but display of errors is turned off.', 'wp-site-toolkit' ),
				'why'     => __( 'Debug output can expose absolute file paths, database table names and plugin internals to anyone who triggers a warning.', 'wp-site-toolkit' ),
				'action'  => $displaying
					? __( 'Set WP_DEBUG to false in wp-config.php on a live site. If you need the log, keep WP_DEBUG_LOG on and set WP_DEBUG_DISPLAY to false.', 'wp-site-toolkit' )
					: __( 'Turn WP_DEBUG off in wp-config.php once you have finished troubleshooting.', 'wp-site-toolkit' ),
			)
		);
	}

	/**
	 * Response header check.
	 *
	 * @param array $headers Header state.
	 *
	 * @return array
	 */
	private function headers_check( $headers ) {
		$label = __( 'Security response headers', 'wp-site-toolkit' );

		if ( empty( $headers ) || empty( $headers['available'] ) ) {
			return $this->skipped( 'security_headers', $label, __( 'The front page could not be loaded, so its response headers were not read.', 'wp-site-toolkit' ) );
		}

		$missing = (array) $headers['missing'];
		$exposes = (array) $headers['exposes'];
		$items   = array();

		foreach ( $missing as $name ) {
			$items[] = WPSTK_Check::item(
				array(
					'label'  => $name,
					'detail' => __( 'Not sent', 'wp-site-toolkit' ),
				)
			);
		}

		foreach ( $exposes as $key => $value ) {
			$items[] = WPSTK_Check::item(
				array(
					'label'  => $key,
					'detail' => sprintf(
						/* translators: %s: header value. */
						__( 'Reveals software version: %s', 'wp-site-toolkit' ),
						WPSTK_Content::shorten( $value, 60 )
					),
				)
			);
		}

		if ( empty( $missing ) && empty( $exposes ) ) {
			return $this->passed(
				'security_headers',
				$label,
				__( 'All of the response headers this check looks for are present.', 'wp-site-toolkit' ),
				__( 'These headers tell the browser to be stricter about how your pages may be used.', 'wp-site-toolkit' )
			);
		}

		return $this->check(
			array(
				'id'          => 'security_headers',
				'status'      => count( $missing ) > 3 ? 'warning' : 'recommendation',
				'label'       => $label,
				'summary'     => sprintf(
					/* translators: 1: number of missing headers, 2: number of headers checked. */
					__( '%1$d of %2$d recommended response headers are missing.', 'wp-site-toolkit' ),
					count( $missing ),
					count( $missing ) + count( (array) $headers['present'] )
				),
				'why'         => __( 'These headers tell the browser to be stricter: not to guess content types, not to allow your pages inside frames on other sites, and not to leak the full referring URL.', 'wp-site-toolkit' ),
				'action'      => __( 'Response headers are added by your web server or hosting panel. Ask your host, or add them in your server configuration. Content-Security-Policy in particular needs testing before you rely on it.', 'wp-site-toolkit' ),
				'note'        => __( 'Missing headers are not a vulnerability on their own. They are defence in depth.', 'wp-site-toolkit' ),
				'items'       => $items,
				'items_total' => count( $items ),
			)
		);
	}

	/**
	 * XML-RPC check.
	 *
	 * @param array $config Config state.
	 *
	 * @return array
	 */
	private function xmlrpc_check( $config ) {
		$label = __( 'XML-RPC', 'wp-site-toolkit' );

		if ( empty( $config['xmlrpc_enabled'] ) ) {
			return $this->passed(
				'xmlrpc',
				$label,
				__( 'XML-RPC is disabled on this site.', 'wp-site-toolkit' ),
				__( 'XML-RPC is an older remote interface. Sites that do not use it are usually better off with it disabled.', 'wp-site-toolkit' )
			);
		}

		return $this->check(
			array(
				'id'      => 'xmlrpc',
				'status'  => 'recommendation',
				'label'   => $label,
				'weight'  => 0.8,
				'summary' => __( 'XML-RPC is enabled, which is the WordPress default.', 'wp-site-toolkit' ),
				'why'     => __( 'XML-RPC is an older remote interface used by the WordPress mobile apps, Jetpack and some publishing tools. It is also a common target for automated login attempts.', 'wp-site-toolkit' ),
				'action'  => __( 'If nothing on your site uses XML-RPC, ask your host to block /xmlrpc.php or disable it with the xmlrpc_enabled filter. Leave it on if you use the mobile app or Jetpack.', 'wp-site-toolkit' ),
				'note'    => __( 'Enabled is not the same as unsafe. This is listed so you can make a deliberate choice.', 'wp-site-toolkit' ),
			)
		);
	}

	/**
	 * File editing check.
	 *
	 * @param array $config Config state.
	 *
	 * @return array
	 */
	private function file_edit_check( $config ) {
		$label = __( 'Theme and plugin file editing', 'wp-site-toolkit' );

		if ( ! empty( $config['disallow_edit'] ) || ! empty( $config['disallow_mods'] ) ) {
			return $this->passed(
				'file_editing',
				$label,
				__( 'The built-in file editors are disabled.', 'wp-site-toolkit' ),
				__( 'With the editors disabled, an attacker who gets into an administrator account cannot rewrite your PHP files from the browser.', 'wp-site-toolkit' )
			);
		}

		return $this->check(
			array(
				'id'      => 'file_editing',
				'status'  => 'recommendation',
				'label'   => $label,
				'summary' => __( 'The built-in theme and plugin file editors are available.', 'wp-site-toolkit' ),
				'why'     => __( 'Anyone who gains access to an administrator account can use these editors to run arbitrary PHP on your server.', 'wp-site-toolkit' ),
				'action'  => __( 'Add define( \'DISALLOW_FILE_EDIT\', true ); to wp-config.php. You can still edit files over SFTP or through your host.', 'wp-site-toolkit' ),
			)
		);
	}

	/**
	 * Account configuration check.
	 *
	 * @param array $config Config state.
	 *
	 * @return array
	 */
	private function accounts_check( $config ) {
		$label    = __( 'Account configuration', 'wp-site-toolkit' );
		$problems = array();
		$status   = 'passed';

		if ( ! empty( $config['users_can_reg'] ) && 'administrator' === $config['default_role'] ) {
			$status     = 'critical';
			$problems[] = WPSTK_Check::item(
				array(
					'label'      => __( 'Open registration creates administrators', 'wp-site-toolkit' ),
					'detail'     => __( 'Anyone who registers becomes an administrator', 'wp-site-toolkit' ),
					'link'       => admin_url( 'options-general.php' ),
					'link_label' => __( 'Open settings', 'wp-site-toolkit' ),
				)
			);
		} elseif ( ! empty( $config['users_can_reg'] ) && in_array( $config['default_role'], array( 'editor', 'author' ), true ) ) {
			$status     = 'warning';
			$problems[] = WPSTK_Check::item(
				array(
					'label'      => __( 'Open registration grants publishing rights', 'wp-site-toolkit' ),
					/* translators: %s: role name. */
					'detail'     => sprintf( __( 'New accounts are created as %s', 'wp-site-toolkit' ), $config['default_role'] ),
					'link'       => admin_url( 'options-general.php' ),
					'link_label' => __( 'Open settings', 'wp-site-toolkit' ),
				)
			);
		}

		if ( ! empty( $config['admin_user'] ) ) {
			$status     = 'critical' === $status ? 'critical' : ( 'warning' === $status ? 'warning' : 'recommendation' );
			$problems[] = WPSTK_Check::item(
				array(
					'label'      => __( 'An account named "admin" exists', 'wp-site-toolkit' ),
					'detail'     => __( 'Automated login attempts try this username first', 'wp-site-toolkit' ),
					'link'       => admin_url( 'users.php' ),
					'link_label' => __( 'View users', 'wp-site-toolkit' ),
				)
			);
		}

		if ( (int) $config['admin_count'] > 5 ) {
			$status     = 'passed' === $status ? 'recommendation' : $status;
			$problems[] = WPSTK_Check::item(
				array(
					'label'      => __( 'Several administrator accounts', 'wp-site-toolkit' ),
					'detail'     => sprintf(
						/* translators: %d: number of accounts. */
						_n( '%d administrator account', '%d administrator accounts', (int) $config['admin_count'], 'wp-site-toolkit' ),
						(int) $config['admin_count']
					),
					'link'       => admin_url( 'users.php?role=administrator' ),
					'link_label' => __( 'View users', 'wp-site-toolkit' ),
				)
			);
		}

		if ( 'passed' === $status ) {
			return $this->passed(
				'accounts',
				$label,
				__( 'Registration settings and administrator accounts look sensible.', 'wp-site-toolkit' ),
				__( 'Most break-ins start with an account, not with a code vulnerability.', 'wp-site-toolkit' )
			);
		}

		return $this->check(
			array(
				'id'          => 'accounts',
				'status'      => $status,
				'label'       => $label,
				'weight'      => 1.2,
				'summary'     => sprintf(
					/* translators: %d: number of findings. */
					_n( '%d account setting is worth reviewing.', '%d account settings are worth reviewing.', count( $problems ), 'wp-site-toolkit' ),
					count( $problems )
				),
				'why'         => __( 'Most break-ins start with an account rather than a code vulnerability. Predictable usernames and generous default roles make that easier.', 'wp-site-toolkit' ),
				'action'      => __( 'Give each person the lowest role that lets them do their job, avoid the username "admin", and keep the default role for new registrations at subscriber.', 'wp-site-toolkit' ),
				'items'       => $problems,
				'items_total' => count( $problems ),
			)
		);
	}
}
