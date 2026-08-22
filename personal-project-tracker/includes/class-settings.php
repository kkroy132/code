<?php
/**
 * Plugin settings storage.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Settings
 *
 * Thin wrapper around a single autoloaded wp_options entry holding all
 * plugin settings as an associative array. The full Settings admin page
 * (General/Appearance/Notifications/Finance/AI/Backup/Import-Export/
 * Privacy/Advanced tabs) is built on top of this in a later phase; this
 * class only owns the storage contract and defaults so activation and
 * other modules have a single source of truth to read/write.
 */
class PTP_Settings {

	/**
	 * wp_options key.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'ptp_settings';

	/**
	 * Default settings values.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_defaults() {
		return array(
			'default_currency'            => 'USD',
			'date_format'                 => get_option( 'date_format', 'Y-m-d' ),
			'time_format'                 => get_option( 'time_format', 'H:i' ),
			'personal_mode'                => true,
			'notifications_enabled'        => true,
			'reminders_enabled'            => true,
			'smart_alerts_enabled'         => true,
			'overdue_task_alerts'          => true,
			'upcoming_deadline_days'       => 3,
			'budget_warning_threshold'     => 80,
			'delete_data_on_uninstall'     => false,
			'ai_prompt_default_tool'       => 'generic',
		);
	}

	/**
	 * Get all settings, merged with defaults for any keys not yet stored.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_all() {
		$stored = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return wp_parse_args( $stored, self::get_defaults() );
	}

	/**
	 * Get a single setting value.
	 *
	 * @param string $key           Setting key.
	 * @param mixed  $fallback      Fallback if not set.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		$all = self::get_all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Update a single setting value.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value New value.
	 */
	public static function update( $key, $value ) {
		$all         = self::get_all();
		$all[ $key ] = $value;

		update_option( self::OPTION_NAME, $all );
	}

	/**
	 * Replace multiple settings at once.
	 *
	 * @param array<string, mixed> $values Key/value pairs to merge in.
	 */
	public static function update_many( array $values ) {
		$all = array_merge( self::get_all(), $values );

		update_option( self::OPTION_NAME, $all );
	}

	/**
	 * Seed defaults on activation if no settings exist yet.
	 *
	 * Never overwrites existing settings, so reactivating the plugin does
	 * not reset a user's configuration.
	 */
	public static function seed_defaults() {
		if ( false === get_option( self::OPTION_NAME, false ) ) {
			add_option( self::OPTION_NAME, self::get_defaults() );
		}
	}
}
