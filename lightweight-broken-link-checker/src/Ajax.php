<?php
/**
 * AJAX endpoints for the admin screen.
 *
 * @package LWBLC
 */

namespace LWBLC;

defined( 'ABSPATH' ) || exit;

/**
 * Handles admin-ajax requests.
 */
class Ajax {

	/**
	 * Nonce action shared by every endpoint.
	 */
	const NONCE_ACTION = 'lwblc_ajax';

	/**
	 * Registers the endpoints.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_lwblc_start_scan', array( __CLASS__, 'start_scan' ) );
		add_action( 'wp_ajax_lwblc_cancel_scan', array( __CLASS__, 'cancel_scan' ) );
		add_action( 'wp_ajax_lwblc_scan_progress', array( __CLASS__, 'scan_progress' ) );
		add_action( 'wp_ajax_lwblc_recheck_link', array( __CLASS__, 'recheck_link' ) );
	}

	/**
	 * Rejects the request unless the nonce and capability check out.
	 *
	 * @return void
	 */
	private static function guard() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( Plugin::capability() ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You are not allowed to do this.', 'lwblc' ) ),
				403
			);
		}
	}

	/**
	 * Starts a scan.
	 *
	 * @return void
	 */
	public static function start_scan() {
		self::guard();

		$result = Scanner::start();

		if ( empty( $result['success'] ) ) {
			wp_send_json_error(
				array(
					'message'  => $result['message'],
					'progress' => self::progress_payload(),
				)
			);
		}

		wp_send_json_success(
			array(
				'message'  => $result['message'],
				'progress' => self::progress_payload(),
			)
		);
	}

	/**
	 * Cancels a running scan.
	 *
	 * @return void
	 */
	public static function cancel_scan() {
		self::guard();

		Scanner::cancel();

		wp_send_json_success(
			array(
				'message'  => __( 'Scan cancelled.', 'lwblc' ),
				'progress' => self::progress_payload(),
			)
		);
	}

	/**
	 * Rechecks one link immediately.
	 *
	 * @return void
	 */
	public static function recheck_link() {
		self::guard();

		$link_id = isset( $_POST['link_id'] ) ? absint( wp_unslash( $_POST['link_id'] ) ) : 0;

		if ( $link_id < 1 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid link.', 'lwblc' ) ) );
		}

		$result = Checker::recheck( $link_id );

		if ( null === $result ) {
			wp_send_json_error( array( 'message' => __( 'That link no longer exists.', 'lwblc' ) ) );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Link rechecked.', 'lwblc' ),
				'result'  => $result,
			)
		);
	}

	/**
	 * Returns scan progress for the admin progress bar.
	 *
	 * @return void
	 */
	public static function scan_progress() {
		self::guard();

		wp_send_json_success( self::progress_payload() );
	}

	/**
	 * Builds the progress payload.
	 *
	 * @return array<string,mixed>
	 */
	public static function progress_payload() {
		$state   = Scanner::get_state();
		$scanned = (int) $state['scanned'];
		$total   = (int) $state['total'];
		$running = ! empty( $state['running'] );

		if ( $total > 0 ) {
			$percent = (int) min( 100, round( ( $scanned / $total ) * 100 ) );
		} else {
			$percent = $running ? 0 : 100;
		}

		$counts = Database::status_counts();

		return array(
			'running'     => $running,
			'scanned'     => $scanned,
			'total'       => $total,
			'percent'     => $percent,
			'links_found' => (int) $state['links_found'],
			'counts'      => $counts,
			'total_links' => array_sum( $counts ),
			'next_check'  => Checker::next_run_label(),
			'text'        => $running
				/* translators: 1: number of posts scanned, 2: total posts. */
				? sprintf( __( 'Scanning %1$s of %2$s posts…', 'lwblc' ), number_format_i18n( $scanned ), number_format_i18n( $total ) )
				/* translators: %s: number of posts scanned. */
				: sprintf( __( 'Last scan covered %s posts.', 'lwblc' ), number_format_i18n( $scanned ) ),
		);
	}

}
