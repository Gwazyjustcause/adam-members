<?php
/** Pure regression coverage for one canonical ID per financial movement. */

declare(strict_types=1);

require_once __DIR__ . '/../src/GoogleSheets/GoogleSheetsTablePlanner.php';

use AdamMembership\GoogleSheets\GoogleSheetsTablePlanner;

function adam_movement_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$row = static fn ( string $member, string $year, string $movement, string $id, string $date = '2026-08-14', string $amount = '12.00', string $method = 'Transferência bancária' ): array => array( 'Inscrição ADAM', $member, 'Member ' . $member, $year, $movement, 'Efetivo', $amount, $date, $method, 'Pago', $id, '' );
$decide = static function ( array $rows, array $candidate, string $id ): string {
	$plan = GoogleSheetsTablePlanner::plan( $rows, $id );
	if ( 0 === $plan['duplicate_row'] ) {
		return 'append';
	}
	return GoogleSheetsTablePlanner::rows_match( $plan['duplicate_values'], $candidate ) ? 'noop' : 'update';
};

$registration = $row( 'ADAM-0007', '2026', 'Inscrição', 'registration:reg-1' );
$rows = array();
adam_movement_assert( 'append' === $decide( $rows, $registration, 'registration:reg-1' ), 'New registration appends.' );
$rows[] = $registration;
adam_movement_assert( 'noop' === $decide( $rows, $registration, 'registration:reg-1' ), 'Registration retry is idempotent.' );
adam_movement_assert( 'update' === $decide( $rows, $row( 'ADAM-0007', '2026', 'Inscrição', 'registration:reg-1', '2026-08-15' ), 'registration:reg-1' ), 'Registration date edits update.' );
adam_movement_assert( 'update' === $decide( $rows, $row( 'ADAM-0007', '2026', 'Inscrição', 'registration:reg-1', '2026-08-14', '15.00' ), 'registration:reg-1' ), 'Registration amount edits update.' );
adam_movement_assert( 'update' === $decide( $rows, $row( 'ADAM-0007', '2026', 'Inscrição', 'registration:reg-1', '2026-08-14', '12.00', 'MB WAY' ), 'registration:reg-1' ), 'Registration method edits update.' );

$renewal_one = $row( 'ADAM-0007', '2027', 'Renovação', 'renewal:ren-1' );
adam_movement_assert( 'append' === $decide( $rows, $renewal_one, 'renewal:ren-1' ), 'First renewal appends.' );
$rows[] = $renewal_one;
adam_movement_assert( 'noop' === $decide( $rows, $renewal_one, 'renewal:ren-1' ), 'Renewal retry is idempotent.' );
adam_movement_assert( 'update' === $decide( $rows, $row( 'ADAM-0007', '2027', 'Renovação', 'renewal:ren-1', '2026-12-20', '13.00', 'Cartão' ), 'renewal:ren-1' ), 'Renewal edits update only that ID.' );

$renewal_two = $row( 'ADAM-0007', '2028', 'Renovação', 'renewal:ren-2' );
adam_movement_assert( 'append' === $decide( $rows, $renewal_two, 'renewal:ren-2' ), 'Second renewal appends.' );
$rows[] = $renewal_two;
adam_movement_assert( 3 === count( $rows ), 'One member can have three transactions.' );

$other_member = $row( 'ADAM-0008', '2026', 'Inscrição', 'registration:reg-2' );
adam_movement_assert( 'append' === $decide( $rows, $other_member, 'registration:reg-2' ), 'Another member has an independent ID.' );
adam_movement_assert( 'append' === $decide( $rows, $row( 'ADAM-0007', '2029', 'Renovação', 'renewal:ren-3' ), 'renewal:ren-3' ), 'A new canonical ID never updates an older transaction.' );
$edited_registration = $row( 'ADAM-0007', '2026', 'Inscrição', 'registration:reg-1', '2026-08-15', '15.00', 'MB WAY' );
adam_movement_assert( 'registration:reg-1' === $edited_registration[10], 'Edited data preserves the canonical ID.' );
$deleted_row_state = array( $registration, $renewal_two );
adam_movement_assert( 'append' === $decide( $deleted_row_state, $renewal_one, 'renewal:ren-1' ), 'Deleted rows are resolved from current state.' );
adam_movement_assert( 'noop' === $decide( $deleted_row_state, $renewal_two, 'renewal:ren-2' ), 'Remaining rows are not overwritten.' );

$service = (string) file_get_contents( __DIR__ . '/../src/GoogleSheets/GoogleSheetsSyncService.php' );
$client  = (string) file_get_contents( __DIR__ . '/../src/GoogleSheets/GoogleSheetsClient.php' );
adam_movement_assert( str_contains( $service, 'update_table_row' ), 'Service updates changed existing IDs.' );
adam_movement_assert( str_contains( $client, "read_values( 'A5:L', \$request_id )" ), 'Update re-reads current rows by canonical ID.' );
adam_movement_assert( str_contains( $client, "'updateCells'" ) && str_contains( $client, "'sheetId' => \$table['sheetId']" ), 'Update targets the resolved row and sheet.' );
adam_movement_assert( str_contains( $client, 'adam_google_sheets_id_mismatch' ), 'Update rejects a row whose canonical ID does not match the requested movement.' );

echo "Google Sheets movement lifecycle smoke tests passed.\n";
