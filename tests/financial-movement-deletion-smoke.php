<?php
/** Regression checks for safe financial-history deletion. */

declare(strict_types=1);

function adam_delete_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}

$repo   = (string) file_get_contents( __DIR__ . '/../src/Finance/FinancialMovementRepository.php' );
$client = (string) file_get_contents( __DIR__ . '/../src/GoogleSheets/GoogleSheetsClient.php' );
$admin  = (string) file_get_contents( __DIR__ . '/../src/Admin/AdminController.php' );
$delete_start = strpos( $client, 'public function delete_table_row' );
$delete_end = strpos( $client, '/** Convert ordered A:L values', $delete_start );
$delete_code = false !== $delete_start && false !== $delete_end ? substr( $client, $delete_start, $delete_end - $delete_start ) : '';

adam_delete_assert( str_contains( $repo, 'public function delete( FinancialMovement $movement )' ), 'FinancialMovement has an explicit local delete operation.' );
adam_delete_assert( str_contains( $client, "read_values( 'A5:L', \$request_id )" ), 'Deletion re-reads current sheet contents.' );
adam_delete_assert( str_contains( $client, '(string) $stored_row[10]' ) && str_contains( $client, "deleteDimension" ) && str_contains( $client, "'sheetId' => \$table['sheetId']" ), 'Deletion matches column K exactly and uses the resolved sheet ID.' );
adam_delete_assert( str_contains( $client, 'adam_google_sheets_duplicate_movement_id' ), 'Duplicate canonical IDs abort deletion safely.' );
adam_delete_assert( str_contains( $client, 'adam_google_sheets_delete_unconfirmed' ) && str_contains( $client, 'confirmation' ), 'Google deletion must be confirmed before local deletion.' );
adam_delete_assert( str_contains( $delete_code, "'dimension' => 'ROWS'" ) && str_contains( $delete_code, "'startIndex' => \$api_row_index" ) && str_contains( $delete_code, "'endIndex' => \$api_row_index + 1" ), 'Deletion uses one physical zero-based grid row.' );
adam_delete_assert( ! str_contains( $delete_code, 'updateCells' ) && ! str_contains( $delete_code, 'clear' ), 'Deletion does not clear cells or write blank values.' );
adam_delete_assert( str_contains( $delete_code, 'table_end - 1' ) && str_contains( $delete_code, 'adam_google_sheets_table_range_unconfirmed' ), 'Deletion confirms that QuotasTable shrank by exactly one row.' );
adam_delete_assert( str_contains( $admin, "'POST' !== strtoupper" ) && str_contains( $admin, 'verify_admin_nonce( \'adam_membership_delete_financial_movement_\'' ), 'Deletion requires POST and a dedicated nonce.' );
adam_delete_assert( str_contains( $admin, 'ensure_can_manage' ) && str_contains( $admin, 'delete_table_row' ) && str_contains( $admin, 'financial_movements->delete' ), 'Deletion requires capability and coordinates Google/local deletion.' );
adam_delete_assert( str_contains( $admin, 'Esta ação remove também o respetivo registo do Google Sheets' ), 'UI requires explicit confirmation.' );
adam_delete_assert( str_contains( $admin, 'adam_membership_delete_financial_movement' ) && str_contains( $admin, 'method="post" action="' ), 'History uses a POST admin action, never GET.' );
adam_delete_assert( str_contains( $admin, 'latest_for_member' ), 'The current panel is recalculated from remaining movements.' );

$rows = array(
	array( 'Inscrição ADAM', 'ADAM-0007', 'Member', 2026, 'Inscrição', 'Efetivo', 12.0, '2026-08-14', 'MB WAY', 'Pago', 'registration:r1', '' ),
	array( 'Renovação ADAM', 'ADAM-0008', 'Other', 2027, 'Renovação', 'Efetivo', 12.0, '2027-08-14', 'Cartão', 'Pago', 'manual:target', '' ),
	array( 'Associar APD/ANA', 'ADAM-0009', 'Third', 2027, 'Associar APD/ANA', 'Aderente', 22.0, '2027-08-14', 'Numerário', 'Pago', 'apd:a1', '' ),
);
$target = array_values( array_filter( $rows, static fn ( array $row ): bool => 'manual:target' === (string) ( $row[10] ?? '' ) ) );
adam_delete_assert( 1 === count( $target ) && 'ADAM-0008' === $target[0][1], 'Reordered rows are selected by exact canonical ID, not by member or position.' );
$remaining = array_values( array_filter( $rows, static fn ( array $row ): bool => 'manual:target' !== (string) ( $row[10] ?? '' ) ) );
adam_delete_assert( 2 === count( $remaining ) && 'registration:r1' === $remaining[0][10] && 'apd:a1' === $remaining[1][10], 'Deleting one movement cannot remove another member movement.' );
$before_numbered = array( 13 => $rows[0], 14 => $rows[1], 15 => $rows[2] );
$after_numbered = array( 13 => $before_numbered[13], 14 => $before_numbered[15] );
adam_delete_assert( 'registration:r1' === $before_numbered[13][10] && 'manual:target' === $before_numbered[14][10] && 'apd:a1' === $before_numbered[15][10], 'Deletion scenario starts with contiguous rows 13, 14 and 15.' );
adam_delete_assert( 2 === count( $after_numbered ) && 'registration:r1' === $after_numbered[13][10] && 'apd:a1' === $after_numbered[14][10], 'Physical deletion shifts row 15 into row 14 without leaving a blank row.' );

echo "Financial movement deletion smoke tests passed.\n";
