<?php
/**
 * Isolated Google Sheets table-planning and contract smoke tests.
 * No WordPress or Google API calls are made.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/GoogleSheets/GoogleSheetsTablePlanner.php';

use AdamMembership\GoogleSheets\GoogleSheetsTablePlanner;

function adam_sheets_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$row = static fn ( string $id, string $name = 'Member' ): array => array( 'Inscrição ADAM', '42', $name, '2027', 'Inscrição', 'Efetivo', '25.00', '2026-12-15', 'MB WAY', 'Pago', $id, '' );

$plan = GoogleSheetsTablePlanner::plan( array(), 'registration:test-1' );
adam_sheets_assert( 5 === $plan['target_row'] && ! $plan['requires_insert'], 'An empty table starts at row 5.' );

$rows_with_gap = array( $row( 'registration:1' ), array(), $row( 'registration:3' ) );
$plan = GoogleSheetsTablePlanner::plan( $rows_with_gap, 'registration:2' );
adam_sheets_assert( 6 === $plan['target_row'] && ! $plan['requires_insert'], 'The first empty row between 5 and 24 is selected.' );

$full = array();
for ( $i = 1; $i <= 20; $i++ ) {
	$full[] = $row( 'registration:full-' . $i );
}
$plan = GoogleSheetsTablePlanner::plan( $full, 'registration:new' );
adam_sheets_assert( 25 === $plan['target_row'] && $plan['requires_insert'], 'A full initial table expands at row 25.' );

$after_expansion = $full;
$after_expansion[] = $row( 'registration:25' );
$plan = GoogleSheetsTablePlanner::plan( $after_expansion, 'registration:26' );
adam_sheets_assert( 26 === $plan['target_row'] && $plan['requires_insert'], 'Later entries continue after row 24 with explicit expansion.' );

$plan = GoogleSheetsTablePlanner::plan( array( $row( 'renewal:existing' ) ), 'renewal:existing' );
adam_sheets_assert( 5 === $plan['duplicate_row'], 'An existing identical request ID is detected.' );
adam_sheets_assert( 'renewal:existing' !== (string) $row( 'renewal:other' )[10], 'A renewal for the same member can have a different request ID.' );
adam_sheets_assert( GoogleSheetsTablePlanner::rows_match( $row( 'registration:same' ), $row( 'registration:same' ) ), 'An existing ID with identical data is idempotent.' );
adam_sheets_assert( ! GoogleSheetsTablePlanner::rows_match( $row( 'registration:same' ), $row( 'registration:same', 'Changed' ) ), 'An existing ID with different data requires an update.' );
adam_sheets_assert( GoogleSheetsTablePlanner::rows_match( array( 'Inscrição ADAM', '42', 'Member', 2027, 'Inscrição', 'Efetivo', '€ 25,00', '15/12/2026', 'MB WAY', 'Pago', 'registration:formatted', '' ), $row( 'registration:formatted' ) ), 'Formatted currency and dd/mm/yyyy date values compare equal to their numeric canonical values.' );
adam_sheets_assert( 'A4:L25' === GoogleSheetsTablePlanner::expanded_range( 'A4:L24' ), 'The first table expansion ends at row 25.' );
adam_sheets_assert( 'A4:L26' === GoogleSheetsTablePlanner::expanded_range( GoogleSheetsTablePlanner::expanded_range( 'A4:L24' ) ), 'The second table expansion ends at row 26.' );

$service_source = (string) file_get_contents( __DIR__ . '/../src/GoogleSheets/GoogleSheetsSyncService.php' );
$client_source  = (string) file_get_contents( __DIR__ . '/../src/GoogleSheets/GoogleSheetsClient.php' );
adam_sheets_assert( str_contains( $service_source, 'add_option( $lock_key, $lock_token' ), 'Synchronization uses an atomic WordPress lock.' );
adam_sheets_assert( str_contains( $service_source, "'payment_date'" ) && str_contains( $service_source, "'method'" ), 'Payment date and method are required.' );
adam_sheets_assert( str_contains( $service_source, 'adam_google_sheets_payment_data_missing' ), 'Missing required data stays pending and does not write.' );
adam_sheets_assert( str_contains( $service_source, 'update_table_row' ), 'An existing ID with different data is updated.' );
adam_sheets_assert( str_contains( $service_source, 'finally' ) && str_contains( $service_source, 'delete_option( $lock_key )' ), 'The per-request lock is released after concurrent attempts.' );
adam_sheets_assert( str_contains( $service_source, "'Pago'" ), 'Financial status is Pago.' );
adam_sheets_assert( str_contains( $service_source, '(float) str_replace' ), 'Amount is sent as a numeric value.' );
adam_sheets_assert( str_contains( $client_source, 'updateTable' ) && str_contains( $client_source, 'copyPaste' ) && str_contains( $client_source, "'sheetId' => \$table['sheetId']" ), 'New rows expand the real table and use the resolved Google Sheets grid ID.' );
adam_sheets_assert( str_contains( $client_source, "'pattern' => 'dd/mm/yyyy'" ) || str_contains( $client_source, 'date_serial' ), 'Payment dates remain real numeric Sheets dates.' );

echo "Google Sheets table planner smoke tests passed.\n";
