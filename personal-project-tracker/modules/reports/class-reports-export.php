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
 * implemented. PDF is intentionally not supported — real PDF generation
 * needs an external library, which conflicts with this plugin's
 * zero-external-dependency design. to_pdf() exists only as the integration
 * point a future version could implement against without a breaking change.
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
				$value    = $row[ $column ] ?? '';
				$value    = is_array( $value ) ? wp_json_encode( $value ) : $value;
				$line[] = self::escape_csv_formula( $value );
			}

			fputcsv( $handle, $line, ',', '"', '\\' );
		}

		rewind( $handle );
		$csv = stream_get_contents( $handle );
		fclose( $handle );

		return false !== $csv ? $csv : '';
	}

	/**
	 * Neutralize CSV/formula injection: a cell beginning with =, +, -, @, or
	 * a tab/CR is treated as a formula by Excel/LibreOffice/Google Sheets
	 * when the exported file is opened, which can execute arbitrary
	 * formulas (e.g. =HYPERLINK(...) exfiltrating data, or legacy DDE) —
	 * this data can originate from any free-text field a user typed (a
	 * task/project title, a note, a description), so it must be neutralized
	 * here, the one place both Reports and the Backup module's CSV export
	 * (PTP_Export_Service, which reuses this same method) write a cell.
	 * Prefixing with a single quote is the standard OWASP-recommended
	 * mitigation: spreadsheet apps then render the value as literal text.
	 *
	 * @param mixed $value Raw cell value.
	 * @return mixed Original value, or a formula-neutralized string.
	 */
	private static function escape_csv_formula( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}

		if ( in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}

		return $value;
	}

	/**
	 * PDF export integration point — not supported.
	 *
	 * @param array $data Report data (unused; kept in the signature so a
	 *                    future implementation is a drop-in, not a breaking change).
	 * @return WP_Error Always.
	 */
	public static function to_pdf( array $data ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return new WP_Error( 'ptp_not_implemented', __( 'PDF export is not supported. Use CSV or JSON export instead.', 'personal-project-tracker' ) );
	}
}
