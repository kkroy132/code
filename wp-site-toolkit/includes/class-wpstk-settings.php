<?php
/**
 * Plugin settings, backed by the WordPress Settings API.
 *
 * @package WP_Site_Toolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads, validates and registers the plugin options.
 *
 * @since 1.0.0
 */
class WPSTK_Settings {

	/**
	 * Option name holding every setting.
	 */
	const OPTION = 'wpstk_settings';

	/**
	 * Settings group used by the Settings API.
	 */
	const GROUP = 'wpstk_settings_group';

	/**
	 * Runtime cache of the resolved settings.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Registers the settings with WordPress.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Returns the default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'scan_timeout'    => 8,
			'max_urls'        => 150,
			'max_posts'       => 500,
			'page_samples'    => 10,
			'batch_posts'     => 40,
			'batch_urls'      => 5,
			'scan_external'   => 0,
			'monitor_404'     => 1,
			'retention_days'  => 90,
			'max_scans'       => 20,
			'auto_scan'       => 'disabled',
			'delete_data'     => 0,
			'large_image_kb'  => 500,
			'large_image_dim' => 2500,
		);
	}

	/**
	 * Returns every setting, merged over the defaults.
	 *
	 * @return array
	 */
	public static function get_all() {
		if ( null === self::$cache ) {
			$stored = get_option( self::OPTION, array() );
			$stored = is_array( $stored ) ? $stored : array();

			self::$cache = self::sanitize( array_merge( self::defaults(), $stored ), false );
		}

		return self::$cache;
	}

	/**
	 * Returns a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when the key is unknown.
	 *
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$settings = self::get_all();

		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	/**
	 * Clears the runtime cache.
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$cache = null;
	}

	/**
	 * Writes the default settings when none are stored yet.
	 *
	 * @return void
	 */
	public static function install_defaults() {
		$stored = get_option( self::OPTION, null );

		if ( ! is_array( $stored ) ) {
			add_option( self::OPTION, self::defaults(), '', false );

			return;
		}

		update_option( self::OPTION, self::sanitize( array_merge( self::defaults(), $stored ), false ), false );
	}

	/**
	 * Restores the default settings.
	 *
	 * @return void
	 */
	public static function reset() {
		update_option( self::OPTION, self::defaults(), false );
		self::flush_cache();
	}

	/**
	 * Registers the option, sections and fields.
	 *
	 * @return void
	 */
	public static function register() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_input' ),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			)
		);

		add_settings_section(
			'wpstk_section_scanning',
			__( 'Scanning', 'wp-site-toolkit' ),
			array( __CLASS__, 'render_scanning_intro' ),
			'wpstk-settings'
		);

		add_settings_section(
			'wpstk_section_monitoring',
			__( 'Monitoring and history', 'wp-site-toolkit' ),
			array( __CLASS__, 'render_monitoring_intro' ),
			'wpstk-settings'
		);

		add_settings_section(
			'wpstk_section_data',
			__( 'Data', 'wp-site-toolkit' ),
			array( __CLASS__, 'render_data_intro' ),
			'wpstk-settings'
		);

		$fields = array(
			array(
				'id'      => 'scan_timeout',
				'title'   => __( 'Request timeout', 'wp-site-toolkit' ),
				'section' => 'wpstk_section_scanning',
				'type'    => 'number',
				'min'     => 2,
				'max'     => 30,
				'suffix'  => __( 'seconds', 'wp-site-toolkit' ),
				'help'    => __( 'How long the scanner waits for a single URL before giving up.', 'wp-site-toolkit' ),
			),
			array(
				'id'      => 'max_urls',
				'title'   => __( 'Maximum URLs per scan', 'wp-site-toolkit' ),
				'section' => 'wpstk_section_scanning',
				'type'    => 'number',
				'min'     => 10,
				'max'     => 2000,
				'help'    => __( 'Upper limit for link checking. Lower this on shared hosting.', 'wp-site-toolkit' ),
			),
			array(
				'id'      => 'max_posts',
				'title'   => __( 'Maximum posts per scan', 'wp-site-toolkit' ),
				'section' => 'wpstk_section_scanning',
				'type'    => 'number',
				'min'     => 10,
				'max'     => 5000,
				'help'    => __( 'How many published posts, pages and other public entries are analysed.', 'wp-site-toolkit' ),
			),
			array(
				'id'      => 'page_samples',
				'title'   => __( 'Rendered pages sampled', 'wp-site-toolkit' ),
				'section' => 'wpstk_section_scanning',
				'type'    => 'number',
				'min'     => 1,
				'max'     => 50,
				'help'    => __( 'Number of pages loaded from your own site to inspect canonical tags, headings and social tags.', 'wp-site-toolkit' ),
			),
			array(
				'id'      => 'batch_posts',
				'title'   => __( 'Posts per batch', 'wp-site-toolkit' ),
				'section' => 'wpstk_section_scanning',
				'type'    => 'number',
				'min'     => 5,
				'max'     => 200,
				'help'    => __( 'Smaller batches are gentler on the server but make scans take longer.', 'wp-site-toolkit' ),
			),
			array(
				'id'      => 'batch_urls',
				'title'   => __( 'URLs per batch', 'wp-site-toolkit' ),
				'section' => 'wpstk_section_scanning',
				'type'    => 'number',
				'min'     => 1,
				'max'     => 25,
				'help'    => __( 'How many links are requested in a single scan step.', 'wp-site-toolkit' ),
			),
			array(
				'id'      => 'scan_external',
				'title'   => __( 'Check external links', 'wp-site-toolkit' ),
				'section' => 'wpstk_section_scanning',
				'type'    => 'checkbox',
				'label'   => __( 'Send requests to third-party domains linked from your content', 'wp-site-toolkit' ),
				'help'    => __( 'Off by default. When enabled, the scanner contacts the external sites you link to in order to detect dead links. External links are always counted, even when this is off.', 'wp-site-toolkit' ),
			),
			array(
				'id'      => 'large_image_kb',
				'title'   => __( 'Large image threshold', 'wp-site-toolkit' ),
				'section' => 'wpstk_section_scanning',
				'type'    => 'number',
				'min'     => 50,
				'max'     => 20000,
				'suffix'  => __( 'KB', 'wp-site-toolkit' ),
				'help'    => __( 'Image files above this size are reported.', 'wp-site-toolkit' ),
			),
			array(
				'id'      => 'large_image_dim',
				'title'   => __( 'Large dimension threshold', 'wp-site-toolkit' ),
				'section' => 'wpstk_section_scanning',
				'type'    => 'number',
				'min'     => 500,
				'max'     => 20000,
				'suffix'  => __( 'pixels', 'wp-site-toolkit' ),
				'help'    => __( 'Images wider or taller than this are reported.', 'wp-site-toolkit' ),
			),
			array(
				'id'      => 'monitor_404',
				'title'   => __( '404 monitoring', 'wp-site-toolkit' ),
				'section' => 'wpstk_section_monitoring',
				'type'    => 'checkbox',
				'label'   => __( 'Record requests that end in a 404 page', 'wp-site-toolkit' ),
				'help'    => __( 'Stores the requested path, the referring URL and a hit counter. No IP addresses, user agents or other visitor details are stored.', 'wp-site-toolkit' ),
			),
			array(
				'id'      => 'auto_scan',
				'title'   => __( 'Automatic audits', 'wp-site-toolkit' ),
				'section' => 'wpstk_section_monitoring',
				'type'    => 'select',
				'options' => array(
					'disabled' => __( 'Disabled (run audits manually)', 'wp-site-toolkit' ),
					'weekly'   => __( 'Once a week', 'wp-site-toolkit' ),
					'monthly'  => __( 'Once a month', 'wp-site-toolkit' ),
				),
				'help'    => __( 'Disabled by default. Automatic audits run through WP-Cron in small batches.', 'wp-site-toolkit' ),
			),
			array(
				'id'      => 'retention_days',
				'title'   => __( 'Keep scan history for', 'wp-site-toolkit' ),
				'section' => 'wpstk_section_monitoring',
				'type'    => 'number',
				'min'     => 1,
				'max'     => 3650,
				'suffix'  => __( 'days', 'wp-site-toolkit' ),
				'help'    => __( 'Scans older than this are deleted automatically once a day.', 'wp-site-toolkit' ),
			),
			array(
				'id'      => 'max_scans',
				'title'   => __( 'Maximum stored scans', 'wp-site-toolkit' ),
				'section' => 'wpstk_section_monitoring',
				'type'    => 'number',
				'min'     => 2,
				'max'     => 200,
				'help'    => __( 'Only the most recent scans are kept, whichever limit is reached first.', 'wp-site-toolkit' ),
			),
			array(
				'id'      => 'delete_data',
				'title'   => __( 'Uninstall behaviour', 'wp-site-toolkit' ),
				'section' => 'wpstk_section_data',
				'type'    => 'checkbox',
				'label'   => __( 'Remove all WP Site Toolkit data when uninstalling', 'wp-site-toolkit' ),
				'help'    => __( 'Off by default. When enabled, deleting the plugin also removes its tables, options and scheduled events. Your posts, pages and media are never touched.', 'wp-site-toolkit' ),
			),
		);

		foreach ( $fields as $field ) {
			add_settings_field(
				'wpstk_field_' . $field['id'],
				$field['title'],
				array( __CLASS__, 'render_field' ),
				'wpstk-settings',
				$field['section'],
				array_merge( $field, array( 'label_for' => 'wpstk-field-' . $field['id'] ) )
			);
		}
	}

	/**
	 * Renders the scanning section description.
	 *
	 * @return void
	 */
	public static function render_scanning_intro() {
		echo '<p class="description">' . esc_html__( 'These limits keep audits predictable on shared hosting. Increase them if your server has room to spare.', 'wp-site-toolkit' ) . '</p>';
	}

	/**
	 * Renders the monitoring section description.
	 *
	 * @return void
	 */
	public static function render_monitoring_intro() {
		echo '<p class="description">' . esc_html__( 'Everything below is processed and stored on this site only.', 'wp-site-toolkit' ) . '</p>';
	}

	/**
	 * Renders the data section description.
	 *
	 * @return void
	 */
	public static function render_data_intro() {
		echo '<p class="description">' . esc_html__( 'Control what happens to stored audit data.', 'wp-site-toolkit' ) . '</p>';
	}

	/**
	 * Renders a single settings field.
	 *
	 * @param array $args Field definition.
	 *
	 * @return void
	 */
	public static function render_field( $args ) {
		$settings = self::get_all();
		$id       = isset( $args['id'] ) ? $args['id'] : '';
		$value    = isset( $settings[ $id ] ) ? $settings[ $id ] : '';
		$name     = self::OPTION . '[' . $id . ']';
		$field_id = 'wpstk-field-' . $id;
		$type     = isset( $args['type'] ) ? $args['type'] : 'text';

		if ( 'checkbox' === $type ) {
			printf(
				'<label for="%1$s"><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s /> %4$s</label>',
				esc_attr( $field_id ),
				esc_attr( $name ),
				checked( 1, (int) $value, false ),
				esc_html( isset( $args['label'] ) ? $args['label'] : '' )
			);
		} elseif ( 'select' === $type ) {
			$options = isset( $args['options'] ) && is_array( $args['options'] ) ? $args['options'] : array();

			echo '<select id="' . esc_attr( $field_id ) . '" name="' . esc_attr( $name ) . '">';

			foreach ( $options as $option_value => $option_label ) {
				printf(
					'<option value="%1$s" %2$s>%3$s</option>',
					esc_attr( $option_value ),
					selected( (string) $value, (string) $option_value, false ),
					esc_html( $option_label )
				);
			}

			echo '</select>';
		} else {
			printf(
				'<input type="number" id="%1$s" name="%2$s" value="%3$s" min="%4$s" max="%5$s" step="1" class="small-text" />',
				esc_attr( $field_id ),
				esc_attr( $name ),
				esc_attr( $value ),
				esc_attr( isset( $args['min'] ) ? $args['min'] : 0 ),
				esc_attr( isset( $args['max'] ) ? $args['max'] : 100000 )
			);

			if ( ! empty( $args['suffix'] ) ) {
				echo ' <span class="wpstk-field-suffix">' . esc_html( $args['suffix'] ) . '</span>';
			}
		}

		if ( ! empty( $args['help'] ) ) {
			echo '<p class="description">' . esc_html( $args['help'] ) . '</p>';
		}
	}

	/**
	 * Sanitisation callback used by the Settings API.
	 *
	 * @param mixed $input Raw submitted value.
	 *
	 * @return array
	 */
	public static function sanitize_input( $input ) {
		$input = is_array( $input ) ? $input : array();

		// Unchecked checkboxes are not submitted, so start from the defaults rather than the stored values.
		$clean = self::sanitize( array_merge( self::defaults(), $input ), true );

		self::$cache = $clean;

		return $clean;
	}

	/**
	 * Validates every setting and clamps numbers into their supported range.
	 *
	 * @param array $values     Values to clean.
	 * @param bool  $from_input Whether the values came from a form submission.
	 *
	 * @return array
	 */
	public static function sanitize( $values, $from_input = false ) {
		$defaults = self::defaults();
		$values   = is_array( $values ) ? $values : array();
		$clean    = array();

		$ranges = array(
			'scan_timeout'    => array( 2, 30 ),
			'max_urls'        => array( 10, 2000 ),
			'max_posts'       => array( 10, 5000 ),
			'page_samples'    => array( 1, 50 ),
			'batch_posts'     => array( 5, 200 ),
			'batch_urls'      => array( 1, 25 ),
			'retention_days'  => array( 1, 3650 ),
			'max_scans'       => array( 2, 200 ),
			'large_image_kb'  => array( 50, 20000 ),
			'large_image_dim' => array( 500, 20000 ),
		);

		foreach ( $ranges as $key => $range ) {
			$value         = isset( $values[ $key ] ) ? (int) $values[ $key ] : $defaults[ $key ];
			$clean[ $key ] = max( $range[0], min( $range[1], $value ) );
		}

		foreach ( array( 'scan_external', 'monitor_404', 'delete_data' ) as $key ) {
			$clean[ $key ] = empty( $values[ $key ] ) ? 0 : 1;
		}

		$auto_scan          = isset( $values['auto_scan'] ) ? sanitize_key( $values['auto_scan'] ) : $defaults['auto_scan'];
		$clean['auto_scan'] = in_array( $auto_scan, array( 'disabled', 'weekly', 'monthly' ), true ) ? $auto_scan : 'disabled';

		if ( $from_input ) {
			// Reschedule the automatic audit as soon as the preference changes.
			add_action( 'shutdown', array( 'WPSTK_Cron', 'schedule_events' ) );
		}

		return $clean;
	}
}
