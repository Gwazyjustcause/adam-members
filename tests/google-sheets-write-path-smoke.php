<?php
/** Static contract check for the metadata -> batchUpdate write path. */

declare(strict_types=1);

function adam_write_path_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$client = (string) file_get_contents( __DIR__ . '/../src/GoogleSheets/GoogleSheetsClient.php' );
$metadata_fixture = array(
	'sheets' => array(
		array( 'properties' => array( 'title' => 'Other', 'sheetId' => 0 ) ),
		array(
			'properties' => array( 'title' => 'Quotas', 'sheetId' => 987654321 ),
			'tables' => array( array( 'name' => 'QuotasTable', 'tableId' => 'quotas-table-real-id', 'range' => array( 'startRowIndex' => 3, 'endRowIndex' => 24 ) ) ),
		),
	),
);
$start = strpos( $client, 'public function append_table_row' );
$end   = strpos( $client, '/** Perform an authenticated Sheets values request', $start );
$block = false !== $start && false !== $end ? substr( $client, $start, $end - $start ) : '';

adam_write_path_assert( '' !== $block, 'Append path exists.' );
adam_write_path_assert( 987654321 === (int) $metadata_fixture['sheets'][1]['properties']['sheetId'], 'Fixture uses a non-zero Quotas sheet ID.' );
adam_write_path_assert( 'quotas-table-real-id' !== (string) $metadata_fixture['sheets'][1]['properties']['sheetId'], 'Fixture keeps table ID distinct from sheet ID.' );
adam_write_path_assert( str_contains( $block, "table_metadata( \$request_id, 'table_metadata_before_write' )" ), 'Metadata is read before writing.' );
adam_write_path_assert( str_contains( $block, "'insertDimension'" ) && str_contains( $block, "'updateTable'" ) && str_contains( $block, "'copyPaste'" ), 'New rows are inserted, formatted from the table row, and the real table range is expanded.' );
adam_write_path_assert( str_contains( $block, "'sheetId' => \$table['sheetId']" ), 'Table writes use the resolved sheet ID.' );
adam_write_path_assert( str_contains( $client, 'public function update_table_row' ) && str_contains( $client, "'updateCells'" ), 'Existing rows use the updateCells path.' );
adam_write_path_assert( str_contains( $client, "'update_metadata'" ) && str_contains( $client, "'update_confirmation'" ), 'Updates resolve and confirm current metadata.' );
adam_write_path_assert( ! str_contains( $client, 'metadata_quotas_resolved' ) && ! str_contains( $client, 'append_cells_request_prepared' ), 'Temporary sheet ID tracing was removed.' );
adam_write_path_assert( ! str_contains( $block, "'sheetId' => 0" ) && ! str_contains( $block, "'sheetId' => 0," ), 'AppendCells does not assume sheet ID zero.' );
adam_write_path_assert( str_contains( $block, "'tableId' => \$table['tableId']" ) && str_contains( $block, "'sheetId' => \$table['sheetId']" ), 'Table expansion uses table ID only for updateTable and sheet ID for grid writes.' );
adam_write_path_assert( str_contains( $block, "self::WRITE_SCOPE,\n\t\t\tarray(),\n\t\t\t\$request_id,\n\t\t\t\$expands_table ? 'append_and_expand_table' : 'write_table_gap'" ), 'request_json receives an array query before request ID and stage.' );
adam_write_path_assert( str_contains( $client, 'date_serial' ) && str_contains( $client, "7 === \$index" ), 'Payment dates are sent as numeric Sheets date serials.' );

adam_write_path_assert( str_contains( $client, "'type' => 'CURRENCY'" ) && str_contains( $client, "'pattern' => '€ #,##0.00'" ) && str_contains( $client, "'pattern' => 'dd/mm/yyyy'" ), 'Amount and payment date receive numeric column formats.' );

echo "Google Sheets write-path smoke tests passed.\n";
