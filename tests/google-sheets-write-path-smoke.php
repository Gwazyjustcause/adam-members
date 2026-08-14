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
$start = strpos( $client, 'public function append_table_row' );
$end   = strpos( $client, '/** Perform an authenticated Sheets values request', $start );
$block = false !== $start && false !== $end ? substr( $client, $start, $end - $start ) : '';

adam_write_path_assert( '' !== $block, 'Append path exists.' );
adam_write_path_assert( str_contains( $block, "table_metadata( \$request_id, 'table_metadata_before_write' )" ), 'Metadata is read before writing.' );
adam_write_path_assert( str_contains( $block, "'appendCells'" ) && str_contains( $block, "':batchUpdate'" ), 'AppendCells batchUpdate payload remains present.' );
adam_write_path_assert( str_contains( $block, "self::WRITE_SCOPE,\n\t\t\tarray(),\n\t\t\t\$request_id,\n\t\t\t'append'" ), 'request_json receives an array query before request ID and stage.' );
adam_write_path_assert( ! str_contains( $block, "self::WRITE_SCOPE,\n\t\t\t\$request_id,\n\t\t\t'append'" ), 'The request ID is not passed in the query argument position.' );

echo "Google Sheets write-path smoke tests passed.\n";
