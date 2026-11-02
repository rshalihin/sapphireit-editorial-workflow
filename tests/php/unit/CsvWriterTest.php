<?php
/**
 * Tests for the spreadsheet-safe CSV writer.
 *
 * @package Sit_Cwm\Tests
 * @since   1.2.0
 */

namespace Sit_Cwm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sit_Cwm\Audit\CsvWriter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Formula neutralisation and row quoting (F8).
 *
 * @since 1.2.0
 *
 * @covers \Sit_Cwm\Audit\CsvWriter
 */
final class CsvWriterTest extends TestCase {

	/**
	 * A cell starting with a formula trigger gets a leading quote.
	 *
	 * @dataProvider data_dangerous
	 *
	 * @param string|int $in  Cell.
	 * @param string|int $out Expected cell.
	 * @return void
	 */
	public function test_formula_cells_are_neutralised( $in, $out ) {
		$this->assertSame( $out, CsvWriter::neutralise( $in ) );
	}

	/**
	 * Cells and their neutralised form.
	 *
	 * @return array
	 */
	public function data_dangerous() {
		return array(
			array( '=HYPERLINK("http://x","y")', '\'=HYPERLINK("http://x","y")' ),
			array( '+1', '\'+1' ),
			array( '-2', '\'-2' ),
			array( '@SUM(A1)', '\'@SUM(A1)' ),
			array( "\tx", "'\tx" ),
			array( "\rx", "'\rx" ),
			array( 'Plain', 'Plain' ),
			array( '', '' ),
			array( 42, 42 ), // Ints pass through untouched.
		);
	}

	/**
	 * A row is quoted the CSV way and every cell neutralised.
	 *
	 * @return void
	 */
	public function test_write_row_quotes_and_neutralises() {
		$h = fopen( 'php://memory', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- In-memory stream.
		CsvWriter::write_row( $h, array( 1, 'a,"b"', '=x' ) );
		rewind( $h );
		$this->assertSame( "1,\"a,\"\"b\"\"\",'=x\n", stream_get_contents( $h ) );
	}
}
