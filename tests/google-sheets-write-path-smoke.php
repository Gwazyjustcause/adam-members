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
adam_write_path_assert( str_contains( $block, "'appendCells'" ) && str_contains( $block, "':batchUpdate'" ), 'AppendCells batchUpdate payload remains present.' );
adam_write_path_assert( str_contains( $block, "'sheetId' => \$table['sheetId']" ), 'AppendCells uses the resolved sheet ID.' );
adam_write_path_assert( str_contains( $client, "metadata_quotas_resolved" ) && str_contains( $block, "append_cells_request_prepared" ), 'Technical sheet and table IDs are logged at both boundaries.' );
adam_write_path_assert( ! str_contains( $block, "'sheetId' => 0" ) && ! str_contains( $block, "'sheetId' => 0," ), 'AppendCells does not assume sheet ID zero.' );
adam_write_path_assert( ! str_contains( $block, "'tableId' => \$table['tableId']" ), 'AppendCells does not confuse table ID with sheet ID.' );
adam_write_path_assert( str_contains( $block, "self::WRITE_SCOPE,\n\t\t\tarray(),\n\t\t\t\$request_id,\n\t\t\t'append'" ), 'request_json receives an array query before request ID and stage.' );
adam_write_path_assert( ! str_contains( $block, "self::WRITE_SCOPE,\n\t\t\t\$request_id,\n\t\t\t'append'" ), 'The request ID is not passed in the query argument position.' );

echo "Google Sheets write-path smoke tests passed.\n";
