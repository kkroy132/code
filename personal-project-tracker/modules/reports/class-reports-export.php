<?php
/**
 * Report export helpers.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Reports_Export
 *
 * CSV and JSON are simple, self-contained data transforms and are fully
 * implemented. PDF is intentionally NOT built yet — real PDF generation
 * needs an external library, which conflicts with this plugin's established
 * "avoid excessive external dependencies" convention (see the Calendar
 * module, Phase 5), and a full document/backup pipeline is explicitly out
 * of scope for this phase. to_pdf() exists only as the integration point a
 * future phase will implement against.
 */
class PTP_Reports_Export {

	/**
	 * @param array $data Report data.
	 * @return string JSON-encoded data.
	 */
	public static function to_json( array $data ) {
		return (string) wp_json_encode( $data );
	}

	/**
	 * Convert a list of rows (arrays or objects) into a CSV string.
	 *
	 * @param array    $rows    List of associative arrays or objects.
	 * @param string[] $columns Column keys, in order. Defaults to the first row's keys.
	 * @return string CSV content, empty string when there are no rows.
	 */
	public static function to_csv( array $rows, array $columns = array() ) {
		if ( empty( $rows ) ) {
			return '';
		}

		if ( empty( $columns ) ) {
			$columns = array_keys( (array) $rows[0] );
		}

		$handle = fopen( 'php://temp', 'r+' );
		fputcsv( $handle, $columns, ',', '"', '\\' );

		foreach ( $rows as $row ) {
			$row  = (array) $row;
			$line = array();

			foreach ( $columns as $column ) {
				$value = $row[ $column ] ?? '';
				$line[] = is_array( $value ) ? wp_json_encode( $value ) : $value;
			}

			fputcsv( $handle, $line, ',', '"', '\\' );
		}

		rewind( $handle );
		$csv = stream_get_contents( $handle );
		fclose( $handle );

		return false !== $csv ? $csv : '';
	}

	/**
	 * PDF export integration point — not implemented in this phase.
	 *
	 * @param array $data Report data (unused; kept in the signature so a
	 *                    future implementation is a drop-in, not a breaking change).
	 * @return WP_Error Always, until a future phase implements this.
	 */
	public static function to_pdf( array $data ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return new WP_Error( 'ptp_not_implemented', __( 'PDF export will be available in a future phase.', 'personal-project-tracker' ) );
	}
}
