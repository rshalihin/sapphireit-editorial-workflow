<?php
/**
 * Spreadsheet-safe CSV rows.
 *
 * @package Sit_Cwm
 * @since   1.2.0
 */

namespace Sit_Cwm\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes CSV rows whose cells cannot run as spreadsheet formulas (F8).
 *
 * Activity cells hold user-typed text (post titles, comments), so a cell such
 * as `=HYPERLINK(...)` would otherwise execute when an auditor opens the file.
 *
 * @since 1.2.0
 * @internal
 */
final class CsvWriter {

	/**
	 * First characters a spreadsheet reads as the start of a formula.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	const FORMULA_TRIGGERS = array( '=', '+', '-', '@', "\t", "\r" );

	/**
	 * A cell with a leading `'` when it starts with a formula trigger.
	 *
	 * @since 1.2.0
	 *
	 * @param string|int $cell Cell value. Integers are returned unchanged.
	 * @return string|int
	 */
	public static function neutralise( $cell ) {
		if ( ! is_string( $cell ) || '' === $cell ) {
			return $cell;
		}

		return in_array( $cell[0], self::FORMULA_TRIGGERS, true ) ? "'" . $cell : $cell;
	}

	/**
	 * Writes one neutralised row.
	 *
	 * @since 1.2.0
	 *
	 * @param resource $handle Writable stream.
	 * @param array    $cells  Cells, in column order.
	 * @return void
	 */
	public static function write_row( $handle, array $cells ): void {
		fputcsv( $handle, array_map( array( self::class, 'neutralise' ), $cells ), ',', '"', '\\' );
	}
}
